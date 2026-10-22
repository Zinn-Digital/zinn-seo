<?php
/**
 * The AI jobs Zinn® SEO can do: one definition each, used by the screens, REST, WP-CLI and bulk.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A task is DATA plus a few pure callables (see `register()`), so one runner does the asking,
 * the batching, the failure classes and the "suggestion, never a silent change" rule for all of
 * them. Free tasks are registered by the free module; Pro tasks by the Pro module (their code
 * ships only in the Pro package).
 *
 * ⛔⛔ NO TASK WRITES ANYTHING THE READER SEES BY ITSELF (zs-200). `apply` runs only when a person
 * accepts the suggestion (or accepts a reviewed batch); a task with no `apply` is applied by the
 * screen that asked (the editor inserts a block into the unsaved post, which the person saves).
 */
final class Tasks {

	/**
	 * Registered tasks.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $tasks = array();

	/**
	 * Register a task.
	 *
	 * Definition keys:
	 * - `label`       callable(): string — what the screens call it;
	 * - `tier`        `free` | `pro` — the edition that may run it once;
	 * - `bulk`        bool — the Pro bulk job may run it over the whole site;
	 * - `subject`     `post` | `attachment` | `none`;
	 * - `pack`        int — items in one request (many small items share one call: batched);
	 * - `task`        the AI core's task (`write` by default);
	 * - `per_item`    int — output tokens one item needs;
	 * - `input`       callable( int $id, array $opts ): array|\WP_Error — the facts for one item;
	 * - `images`      callable( int $id ): array — `{mime, data}` parts, sent only to a provider
	 *                 that reads images (never on credits);
	 * - `instruction` callable( array $opts, bool $sees_images ): string — the system prompt;
	 * - `fields`      array — name => JSON Schema of one answer field;
	 * - `clean`       callable( array $answer, int $id, array $opts ): array — sanitised fields;
	 * - `apply`       callable( int $id, array $fields, array $opts ): true|\WP_Error — or null;
	 * - `store`       bool — keep the answer as the subject's suggestion (default true for posts);
	 * - `bulk_next`   callable( int $after, int $limit, array $scope ): array<int> — the next ids
	 *                 the bulk job would do, in id order (keyset);
	 * - `bulk_count`  callable( array $scope ): int — how many it would do (the same predicate:
	 *                 the dry run's count IS the applied count).
	 *
	 * @param string               $id  Task id.
	 * @param array<string, mixed> $def Definition.
	 * @return void
	 */
	public static function register( string $id, array $def ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,30}$/', $id ) || ! isset( $def['instruction'], $def['fields'] ) ) {
			return;
		}
		self::$tasks[ $id ] = $def + array(
			'label'    => static fn() => $id,
			'tier'     => 'free',
			'bulk'     => false,
			'subject'  => 'post',
			'pack'     => 1,
			'task'     => 'write',
			'per_item' => 400,
			'input'    => null,
			'images'   => null,
			'clean'    => null,
			'apply'    => null,
			'store'    => true,
		);
	}

	/**
	 * One task, or null.
	 *
	 * @param string $id Task id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::$tasks[ $id ] ?? null;
	}

	/**
	 * Every task.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::$tasks;
	}

	/**
	 * Forget every task (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$tasks = array();
	}

	/**
	 * What the screens need to know about each task.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe(): array {
		$out = array();
		foreach ( self::$tasks as $id => $def ) {
			$out[] = array(
				'id'      => $id,
				'label'   => (string) call_user_func( $def['label'] ),
				'tier'    => (string) $def['tier'],
				'bulk'    => (bool) $def['bulk'],
				'subject' => (string) $def['subject'],
				'images'  => null !== $def['images'],
				'applies' => null !== $def['apply'],
			);
		}

		return $out;
	}

	/**
	 * The JSON Schema of an answer: `{ items: [ { id, …fields } ] }`.
	 *
	 * ⭐ The same shape for one item or twenty: a pack of items is answered in one call, and the
	 * answer names each item's id, so the runner never relies on the order the model kept.
	 *
	 * @param array<string, array<string, mixed>> $fields Field schemas.
	 * @return array<string, mixed>
	 */
	public static function schema( array $fields ): array {
		$properties = array( 'id' => array( 'type' => 'string' ) ) + $fields;

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => $properties,
						'required'             => array_keys( $properties ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'items' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * A post's text for a prompt: title, rendered words (no markup), cut to a length.
	 *
	 * @param \WP_Post $post  The post.
	 * @param int      $chars Longest text kept.
	 * @return string
	 */
	public static function text_of( \WP_Post $post, int $chars = 6000 ): string {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		return self::cut( $text, $chars );
	}

	/**
	 * Cut text to a length on a word boundary.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Longest.
	 * @return string
	 */
	public static function cut( string $text, int $chars ): string {
		$text = trim( $text );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $chars ) {
			return $text;
		}
		$cut   = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $chars ) : substr( $text, 0, $chars );
		$space = strrpos( $cut, ' ' );

		return rtrim( false !== $space && $space > $chars * 0.8 ? substr( $cut, 0, $space ) : $cut ) . '…';
	}

	/**
	 * The language a post is written in, for the instruction ("write in German").
	 *
	 * @param int $post_id Post.
	 * @return string A BCP-47 code, e.g. `de` or `en-GB`.
	 */
	public static function language_of( int $post_id ): string {
		if ( $post_id > 0 && function_exists( 'tranzly_get_post_language' ) ) {
			$lang = (string) tranzly_get_post_language( $post_id );
			if ( '' !== $lang ) {
				return $lang;
			}
		}

		return str_replace( '_', '-', (string) get_locale() );
	}

	/**
	 * A plain, trimmed line of text from an answer, at most `$max` characters.
	 *
	 * @param mixed $value Answer value.
	 * @param int   $max   Longest.
	 * @return string
	 */
	public static function line( $value, int $max ): string {
		$text = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return self::cut( $text, $max );
	}

	/**
	 * Lines of text from an answer list.
	 *
	 * @param mixed $value Answer value.
	 * @param int   $max   Longest line.
	 * @param int   $count Most lines kept (an answer's list, never a site's pages).
	 * @return array<int, string>
	 */
	public static function lines( $value, int $max, int $count ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			$line = self::line( $item, $max );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return array_slice( array_values( array_unique( $out ) ), 0, $count );
	}
}
