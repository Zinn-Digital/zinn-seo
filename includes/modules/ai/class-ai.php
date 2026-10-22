<?php
/**
 * Runs an AI task over one or many items: asks, batches, classifies, keeps the suggestion.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

use ZinnDigital\ZinnSEO\AiCore\Client;
use ZinnDigital\ZinnSEO\AiCore\Failure;
use ZinnDigital\ZinnSEO\AiCore\Result;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ BATCHED (owner, §2.19 and the P10 brief): items are packed `pack` to a request — one call
 * answers ten posts' titles — and on Zinn® credits up to twenty such requests travel in ONE call
 * to the Zinn® API. With the site owner's own key each request is one call to their provider.
 * A pack that fails is reported per item, with the class of the failure, and nothing in it is
 * kept; the others still are.
 *
 * ⛔ The answer is a SUGGESTION (zs-200): kept on the item (`Suggestions`) or handed back to
 * the screen that asked. Nothing here changes a title, a page or a redirect.
 */
final class Ai {

	/**
	 * Replaces `Client::generate_many()` in unit tests: `fn( array $items, array $options ): array`.
	 *
	 * @var callable|null
	 */
	public static $generate = null;

	/**
	 * Can the edition run this task?
	 *
	 * @param array<string, mixed> $def Task.
	 * @return bool
	 */
	public static function allowed( array $def ): bool {
		return 'pro' !== $def['tier'] || Plugin::is_pro();
	}

	/**
	 * Is AI set up at all (a key of the owner's own, or credits the site may spend)?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return class_exists( Client::class ) && Client::ready();
	}

	/**
	 * Will this task be paid with Zinn® credits?
	 *
	 * @param array<string, mixed> $def Task.
	 * @return bool
	 */
	public static function on_credits( array $def ): bool {
		return class_exists( Client::class ) && Client::on_credits( (string) $def['task'] );
	}

	/**
	 * Run a task.
	 *
	 * @param string               $task Task id.
	 * @param array<int, int>      $ids  Items (post or attachment ids; `[0]` for a task with no subject).
	 * @param array<string, mixed> $opts Task options (the screen's choices).
	 * @return array{items: array<int, array<string, mixed>>, cost_micros: int, provider: string, credits: bool}|\WP_Error
	 */
	public static function run( string $task, array $ids, array $opts = array() ) {
		$def = Tasks::get( $task );
		if ( null === $def ) {
			/* translators: %s: an AI task's id. */
			return new \WP_Error( 'zinn_seo_ai_task', sprintf( __( 'There is no AI task called %s.', 'zinn-seo' ), $task ), array( 'status' => 404 ) );
		}
		if ( ! self::allowed( $def ) ) {
			return new \WP_Error( 'zinn_seo_ai_pro', __( 'This AI feature is part of Zinn® SEO Pro.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		$credits = self::on_credits( $def );
		$sees    = ! $credits && null !== $def['images'];
		$out     = array();
		$ready   = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $id ) {
			$input = null === $def['input'] ? array() : call_user_func( $def['input'], $id, $opts );
			if ( is_wp_error( $input ) ) {
				$out[ $id ] = array(
					'id'      => $id,
					'ok'      => false,
					'failure' => self::refusal( $input->get_error_message() ),
				);
				continue;
			}
			$ready[ $id ] = (array) $input;
		}
		$pack     = $sees ? 1 : max( 1, (int) $def['pack'] );
		$requests = array();
		foreach ( array_chunk( $ready, $pack, true ) as $n => $chunk ) {
			$user = array();
			foreach ( $chunk as $id => $input ) {
				$user[] = array( 'id' => (string) $id ) + $input;
			}
			$content = "Items (JSON):\n" . (string) wp_json_encode( $user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( $sees ) {
				$parts = array(
					array(
						'type' => 'text',
						'text' => $content,
					),
				);
				foreach ( (array) call_user_func( $def['images'], (int) array_key_first( $chunk ) ) as $image ) {
					if ( is_array( $image ) && isset( $image['mime'], $image['data'] ) ) {
						$parts[] = array(
							'type' => 'image',
							'mime' => (string) $image['mime'],
							'data' => (string) $image['data'],
						);
					}
				}
				$content = $parts;
			}
			$requests[] = array(
				'id'       => 'p' . $n,
				'ids'      => array_keys( $chunk ),
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => self::instruction( $def, $opts, $sees ),
					),
					array(
						'role'    => 'user',
						'content' => $content,
					),
				),
			);
		}

		$cost     = 0;
		$provider = '';
		if ( array() !== $requests ) {
			$options = array(
				'task'        => (string) $def['task'],
				'purpose'     => 'zinn-seo-' . $task,
				'schema'      => Tasks::schema( (array) $def['fields'] ),
				'schema_name' => 'items',
				'max_tokens'  => min( 4096, max( 256, $pack * (int) $def['per_item'] ) ),
				'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.4,
			);
			// A feature with a model of its own (the writer, spelling and tone): the person's
			// choice for this run, else the feature's saved one, on the owner's own key only —
			// on Zinn® credits Zinn® chooses the model for each job.
			if ( ! $credits && '' !== (string) ( $def['feature'] ?? '' ) ) {
				$options += Model_Picker::route( (string) $def['feature'], (string) ( $opts['ai_model'] ?? '' ) );
				// A writing task asks for a filled-in JSON answer, not a long deliberation: on a
				// model that thinks, its thoughts are billed and counted against `max_tokens`,
				// and a thinking Gemini model ran out mid-answer (live, 2026-10-13: an ideas run
				// on gemini-3.5-flash, 1,478 of 1,500 tokens, unreadable). Providers that do not
				// think ignore this.
				$options['thinking'] = (string) ( $def['thinking'] ?? 'low' );
			}
			$results = null !== self::$generate ? call_user_func( self::$generate, $requests, $options ) : Client::generate_many( $requests, $options );
			foreach ( $requests as $index => $request ) {
				$result = $results[ $index ] ?? null;
				if ( ! $result instanceof Result ) {
					$result = Result::failed( new Failure( Failure::UNKNOWN, '', __( 'The AI request failed for a reason we could not identify. Try again.', 'zinn-seo' ) ) );
				}
				$provider = '' !== $result->provider ? $result->provider : $provider;
				$cost    += $result->cost_micro_usd;
				foreach ( self::answers( $def, $request['ids'], $result, $opts ) as $id => $row ) {
					$out[ $id ] = $row;
				}
			}
		}

		ksort( $out );
		$items = array_values( $out );
		/**
		 * After a task ran: what it cost and what it answered (a feature keeps its own measured
		 * price from it, CLAUDE.md §2.45).
		 *
		 * @param string                           $task    Task id.
		 * @param array<int, array<string, mixed>> $items   Items.
		 * @param int                              $cost    Micros charged.
		 * @param bool                             $credits Paid with Zinn® credits.
		 */
		do_action( 'zinn_seo_ai_ran', $task, $items, $cost, $credits );
		if ( $def['store'] && 'none' !== $def['subject'] ) {
			foreach ( $items as $row ) {
				if ( ! empty( $row['ok'] ) ) {
					Suggestions::put( (int) $row['id'], $task, (array) $row['fields'], $provider, $credits );
				}
			}
		}

		return array(
			'items'       => $items,
			'cost_micros' => $cost,
			'provider'    => $provider,
			'credits'     => $credits,
		);
	}

	/**
	 * The system prompt: the task's own, plus the rules every task keeps.
	 *
	 * @param array<string, mixed> $def  Task.
	 * @param array<string, mixed> $opts Options.
	 * @param bool                 $sees The model receives the image.
	 * @return string
	 */
	public static function instruction( array $def, array $opts, bool $sees ): string {
		return trim( (string) call_user_func( $def['instruction'], $opts, $sees ) ) . "\n\n"
			. 'Rules: answer every item, keeping each item\'s "id" exactly as given. Write in the language the item names in "language" (else the language of its text). '
			. 'Use only facts found in the item; never invent prices, numbers, names or claims. Plain text: no markdown, no quotation marks around values, no emoji.';
	}

	/**
	 * One result's answers, per item.
	 *
	 * @param array<string, mixed> $def    Task.
	 * @param array<int, int>      $ids    The items the request carried.
	 * @param Result               $result What came back.
	 * @param array<string, mixed> $opts   Options.
	 * @return array<int, array<string, mixed>>
	 */
	private static function answers( array $def, array $ids, Result $result, array $opts ): array {
		$out = array();
		if ( ! $result->ok() ) {
			$failure = self::failure( $result->failure );
			foreach ( $ids as $id ) {
				$out[ $id ] = array(
					'id'      => $id,
					'ok'      => false,
					'failure' => $failure,
				);
			}
			return $out;
		}
		$by_id = array();
		foreach ( (array) ( $result->data['items'] ?? array() ) as $answer ) {
			if ( is_array( $answer ) && isset( $answer['id'] ) ) {
				$by_id[ (string) $answer['id'] ] = $answer;
			}
		}
		$missing = new Failure( Failure::UNKNOWN, '', __( 'The AI did not answer for this item. Try it again on its own.', 'zinn-seo' ) );
		foreach ( $ids as $id ) {
			$answer = $by_id[ (string) $id ] ?? null;
			if ( null === $answer ) {
				$out[ $id ] = array(
					'id'      => $id,
					'ok'      => false,
					'failure' => self::failure( $missing ),
				);
				continue;
			}
			unset( $answer['id'] );
			$fields     = null !== $def['clean'] ? (array) call_user_func( $def['clean'], $answer, $id, $opts ) : $answer;
			$out[ $id ] = array(
				'id'     => $id,
				'ok'     => true,
				'fields' => $fields,
			);
		}

		return $out;
	}

	/**
	 * A failure as the screens show it (zs-199): the class decides the advice and the button.
	 *
	 * @param Failure|null $failure The core's failure.
	 * @return array<string, string>
	 */
	public static function failure( ?Failure $failure ): array {
		if ( null === $failure ) {
			$failure = new Failure( Failure::UNKNOWN, '', __( 'The AI request failed for a reason we could not identify. Try again.', 'zinn-seo' ) );
		}
		$row           = $failure->to_array();
		$row['action'] = self::action( $failure );
		unset( $row['detail'] );

		return $row;
	}

	/**
	 * The one button a failure offers.
	 *
	 * @param Failure $failure Failure.
	 * @return string `top_up` (add Zinn® credit), `account` (the Zinn® account: a spending limit or
	 *                a hold), `status` (the provider's status page),
	 *                `fix_key` (the provider's billing or keys page), `settings` (our AI settings),
	 *                `connect` (connect to Zinn®), `retry` or `none`.
	 */
	public static function action( Failure $failure ): string {
		$detail = (string) $failure->detail;
		switch ( $failure->kind ) {
			case Failure::OUTAGE:
				return '' !== $failure->link ? 'status' : 'retry';
			case Failure::BILLING:
			case Failure::INVALID_KEY:
			case Failure::MODEL:
				return '' !== $failure->link ? 'fix_key' : 'settings';
			case Failure::RATE_LIMITED:
			case Failure::UNREACHABLE:
				return 'retry';
			case Failure::REFUSED:
				if ( 0 === strpos( $detail, 'zinn:no_credit' ) ) {
					return 'top_up';
				}
				if ( 0 === strpos( $detail, 'zinn:site_limit' ) || 0 === strpos( $detail, 'zinn:account_hold' ) ) {
					return 'account';
				}
				if ( 0 === strpos( $detail, 'zinn:reconnect' ) || 0 === strpos( $detail, 'zinn:not_connected' ) ) {
					return 'connect';
				}
				return '' !== $failure->link ? 'settings' : 'none';
		}

		return 'retry';
	}

	/**
	 * A refusal of ours (an item that cannot be asked about), as a failure row.
	 *
	 * @param string $message Sentence.
	 * @return array<string, string>
	 */
	public static function refusal( string $message ): array {
		return self::failure( Failure::refused( $message ) );
	}

	/**
	 * The buttons of a failure that stops every item, not just one (setup, money, a key).
	 */
	public const BLOCKING = array( 'top_up', 'account', 'connect', 'fix_key', 'settings' );

	/**
	 * A run's outcome as a REST error when EVERY item failed the same way (the screens then show
	 * one notice of the right class instead of a list of identical lines).
	 *
	 * @param array<string, mixed> $run A `run()` answer.
	 * @return \WP_Error|null
	 */
	public static function as_error( array $run ): ?\WP_Error {
		$items = (array) $run['items'];
		if ( array() === $items ) {
			return null;
		}
		$first   = null;
		$blocker = null;
		foreach ( $items as $row ) {
			if ( ! empty( $row['ok'] ) ) {
				return null;
			}
			$failure = (array) $row['failure'];
			$first   = $first ?? $failure;
			if ( null === $blocker && in_array( (string) ( $failure['action'] ?? '' ), self::BLOCKING, true ) ) {
				$blocker = $failure;
			}
		}
		// ZH5-2: a failure only a person can fix for EVERY item (AI not set up, no credit, a key
		// to fix) is the one to show, ahead of the first item's own problem ("too little text"):
		// that one hides why nothing at all could run.
		$first  = $blocker ?? $first;
		$status = array(
			'refused'            => 402,
			'provider_outage'    => 503,
			'unreachable'        => 502,
			'credential_billing' => 424,
			'credential_invalid' => 424,
			'model_unavailable'  => 424,
			'rate_limited'       => 429,
		);

		return new \WP_Error(
			'zinn_seo_ai_failed',
			(string) ( $first['message'] ?? '' ),
			array(
				'status'  => $status[ (string) ( $first['kind'] ?? '' ) ] ?? 502,
				'failure' => $first,
			)
		);
	}
}
