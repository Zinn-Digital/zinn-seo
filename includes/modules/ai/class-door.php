<?php
/**
 * The door the shared AI core uses to reach Zinn® credits through this site's connection.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

use ZinnDigital\ZinnSEO\Admin\Admin;
use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offered to the AI core at boot (`credits` and `choose` in bootstrap.php), so EVERY plugin that
 * carries the core — Tranzly, Page Builder Sandwich, Zinn® Chat — can pay with the same credits
 * once this site is connected, and none of them holds the token.
 *
 * ⛔ The core is told WHAT failed, in its own classes, with the sentence already written here in
 * the reader's language: `refused` (no credit, the site's limit, an account on hold — with the
 * top-up link when credit is what is missing), `provider_outage` (only when the API names the AI
 * vendor, with its status page), `unknown` (ours: names nobody). CLAUDE.md §2.57.
 */
final class Door {

	/** The API route (`pluginAiComplete`). */
	public const PATH = '/v1/plugin-ai/complete';

	/** Funding choices: `auto` (own key when there is one, else credits), `byo`, `credits`. */
	public const FUNDING = array( 'auto', 'byo', 'credits' );

	/**
	 * Is the connection module here (it is a module, and can be switched off)?
	 *
	 * @return bool
	 */
	private static function has_connection(): bool {
		if ( ! class_exists( Connection::class ) ) {
			$dir = dirname( __DIR__ ) . '/connect';
			if ( is_readable( $dir . '/class-connection.php' ) && \ZinnDigital\ZinnSEO\Modules::enabled( 'connect' ) ) {
				require_once $dir . '/class-client.php';
				require_once $dir . '/class-connection.php';
			}
		}

		return class_exists( Connection::class );
	}

	/**
	 * The state the core shows (no secret in it).
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$connect = admin_url( 'admin.php?page=' . ( class_exists( Admin::class ) ? Admin::SLUG : 'zinn-seo' ) . '&view=credits' );
		if ( ! self::has_connection() || ! Connection::connected() ) {
			return array(
				'connected'   => false,
				'can_spend'   => false,
				'connect_url' => $connect,
				'host'        => 'zinn-seo',
			);
		}
		$row     = Connection::row();
		$kept    = get_option( 'zseo_credits', array() );
		$view    = is_array( $kept ) && is_array( $kept['view'] ?? null ) ? $kept['view'] : array();
		$balance = isset( $view['balance_micros'] ) ? (int) $view['balance_micros'] : null;

		return array(
			'connected'      => true,
			'can_spend'      => Connection::can_spend(),
			'account'        => (string) ( $row['org_name'] ?? '' ),
			'balance_micros' => $balance,
			'buy_url'        => Client::https( $view['buy_url'] ?? '' ),
			'connect_url'    => $connect,
			'host'           => 'zinn-seo',
		);
	}

	/**
	 * The funding the site owner chose for AI in this plugin.
	 *
	 * @return string auto|byo|credits.
	 */
	public static function funding(): string {
		$value = (string) Settings::get( 'ai_funding', 'auto' );

		return in_array( $value, self::FUNDING, true ) ? $value : 'auto';
	}

	/**
	 * The host's choice for a request with no provider named (`Core::boot` `choose`): Zinn® credits
	 * when the owner chose them — or chose "automatic" and has no key of their own that can do the
	 * task — and null (the shared default) otherwise.
	 *
	 * @param string $task Task id.
	 * @param bool   $own_key Whether the site has a working key of its own for this task (tests pass it).
	 * @return array{provider: string, model: string}|null
	 */
	public static function choose( string $task, ?bool $own_key = null ): ?array {
		$funding = self::funding();
		if ( 'byo' === $funding ) {
			return null;
		}
		$credits = array(
			'provider' => 'zinn',
			'model'    => 'auto',
		);
		if ( 'credits' === $funding ) {
			return $credits;
		}
		if ( null === $own_key ) {
			$own_key = self::own_key( $task );
		}
		if ( $own_key ) {
			return null;
		}

		return self::has_connection() && Connection::can_spend() ? $credits : null;
	}

	/**
	 * Has the site a working key of its own for a task (the shared default is a configured
	 * provider other than Zinn® credits)?
	 *
	 * @param string $task Task id.
	 * @return bool
	 */
	public static function own_key( string $task ): bool {
		$store = '\ZinnDigital\ZinnSEO\AiCore\Store';
		if ( ! class_exists( $store ) ) {
			return false;
		}
		$choice = $store::default_for( $task );
		if ( '' !== $choice['provider'] && 'zinn' !== $choice['provider'] && $store::configured( $choice['provider'] ) ) {
			return true;
		}
		// No default saved yet, but a key exists: the core then uses it.
		foreach ( array_keys( (array) ( $store::get()['providers'] ?? array() ) ) as $provider ) {
			if ( 'zinn' !== $provider && $store::configured( (string) $provider ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send a batch through the connection (the core's `complete`).
	 *
	 * @param array<int, array<string, mixed>> $requests Requests in the API's shape.
	 * @param string                           $purpose  What for (the account's usage log).
	 * @return array<string, mixed> See `AiCore\Zinn`.
	 */
	public static function complete( array $requests, string $purpose ): array {
		if ( ! self::has_connection() || ! Connection::connected() ) {
			return array(
				'ok'      => false,
				'failure' => array(
					'kind'    => 'refused',
					'message' => __( 'To pay with Zinn® credits, connect this site to a Zinn® account first.', 'zinn-seo' ),
					'link'    => self::state()['connect_url'],
					'detail'  => 'zinn:not_connected',
				),
			);
		}
		$body = array( 'requests' => array() );
		foreach ( $requests as $request ) {
			$row = array(
				'id'          => (string) $request['id'],
				'messages'    => array_values( (array) $request['messages'] ),
				'max_tokens'  => (int) $request['max_tokens'],
				'temperature' => (string) $request['temperature'],
			);
			if ( '' !== (string) ( $request['system'] ?? '' ) ) {
				$row['system'] = (string) $request['system'];
			}
			if ( is_array( $request['schema'] ?? null ) ) {
				$row['schema']      = $request['schema'];
				$row['schema_name'] = (string) $request['schema_name'];
			}
			$body['requests'][] = $row;
		}
		$purpose = sanitize_key( $purpose );
		if ( '' !== $purpose ) {
			$body['purpose'] = substr( $purpose, 0, 64 );
		}
		$answer = Connection::call( 'POST', self::PATH, $body );
		if ( ! $answer['ok'] ) {
			return array(
				'ok'      => false,
				'failure' => self::failure( (array) ( $answer['error'] ?? array() ) ),
			);
		}
		$out = array(
			'ok'             => true,
			'balance_micros' => (int) ( $answer['body']['balance_micros'] ?? 0 ),
			'charged_micros' => (int) ( $answer['body']['charged_micros'] ?? 0 ),
			'results'        => array(),
		);
		foreach ( (array) ( $answer['body']['results'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}
			if ( ! empty( $row['ok'] ) ) {
				$out['results'][] = $row;
				continue;
			}
			$classified       = Client::classify( 503, array( 'failure' => is_array( $row['failure'] ?? null ) ? $row['failure'] : array() ) );
			$out['results'][] = array(
				'id'      => (string) $row['id'],
				'ok'      => false,
				'failure' => self::failure( (array) $classified['error'] ),
			);
		}
		// The kept balance is wrong now: the next screen asks again.
		if ( class_exists( '\ZinnDigital\ZinnSEO\Credits\Credits' ) ) {
			\ZinnDigital\ZinnSEO\Credits\Credits::forget();
		}
		/**
		 * Fires after AI was paid with Zinn® credits.
		 *
		 * @param int    $charged Micro-dollars charged.
		 * @param int    $balance The balance after.
		 * @param string $purpose What for.
		 */
		do_action( 'zinn_seo_ai_spent', $out['charged_micros'], $out['balance_micros'], $purpose );

		return $out;
	}

	/**
	 * The connection's classified error in the core's terms.
	 *
	 * @param array<string, mixed> $error `Client` error: class, code, message and the rest.
	 * @return array{kind: string, message: string, link: string, detail: string}
	 */
	public static function failure( array $error ): array {
		$class   = (string) ( $error['class'] ?? 'ours' );
		$code    = (string) ( $error['code'] ?? '' );
		$message = (string) ( $error['message'] ?? '' );
		$state   = self::state();
		switch ( $class ) {
			case 'reconnect':
				return self::row( 'refused', $message, $state['connect_url'], 'reconnect:' . $code );
			case 'refused':
				// Credit is the one refusal a person fixes by buying: the link is the top-up. The
				// site's limit and an account on hold are fixed in the Zinn® account (the same page).
				$link = in_array( $code, array( 'no_credit', 'site_limit', 'account_hold' ), true ) ? (string) ( $state['buy_url'] ?? '' ) : '';
				if ( '' === $link && in_array( $code, array( 'no_credit', 'site_limit', 'account_hold' ), true ) ) {
					// Never read yet on this site: ask for the account once, now — the link that
					// opens the right account is what the refusal is for (found by the harness).
					$link = self::buy_url();
				}
				if ( '' === $link && in_array( $code, array( 'no_credit', 'site_limit', 'account_hold' ), true ) ) {
					$link = $state['connect_url'];
				}
				return self::row( 'refused', $message, $link, $code );
			case 'outage':
				return self::row( 'provider_outage', $message, (string) ( $error['status_url'] ?? '' ), $code . ':' . (string) ( $error['vendor'] ?? '' ) );
			case 'own_key':
				return self::row( 'credential_billing', $message, (string) ( $error['fix_url'] ?? '' ), $code );
			case 'busy':
				return self::row( 'rate_limited', $message, '', $code );
			case 'unreachable':
				return self::row( 'unreachable', $message, '', $code );
		}

		return self::row( 'unknown', '' !== $message && 'ours' === $class ? $message : __( 'Something went wrong on our side. Nothing was charged. Please try again in a few minutes.', 'zinn-seo' ), '', $class . ':' . $code );
	}

	/**
	 * The account's credit page (it names the organisation), read from Zinn® when it is not kept.
	 *
	 * @return string An https address, or ''.
	 */
	private static function buy_url(): string {
		$credits = '\ZinnDigital\ZinnSEO\Credits\Credits';
		if ( ! class_exists( $credits ) ) {
			$file = dirname( __DIR__ ) . '/credits/class-credits.php';
			if ( ! is_readable( $file ) || ! \ZinnDigital\ZinnSEO\Modules::enabled( 'credits' ) ) {
				return '';
			}
			require_once $file;
		}
		$account = $credits::account();

		return is_array( $account ) ? Client::https( $account['buy_url'] ?? '' ) : '';
	}

	/**
	 * One failure row.
	 *
	 * @param string $kind    Class.
	 * @param string $message Sentence.
	 * @param string $link    Remedy.
	 * @param string $code    Code, carried as `detail` with a `zinn:` mark.
	 * @return array{kind: string, message: string, link: string, detail: string}
	 */
	private static function row( string $kind, string $message, string $link, string $code ): array {
		return array(
			'kind'    => $kind,
			'message' => $message,
			'link'    => $link,
			'detail'  => 'zinn:' . $code,
		);
	}
}
