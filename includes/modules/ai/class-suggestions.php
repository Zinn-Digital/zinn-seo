<?php
/**
 * AI suggestions waiting for a person: kept on the item, accepted or thrown away (zs-200).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One private meta row per item and task, `_zseo_ai_<task>`: what the AI wrote, when, for whom,
 * and how it was paid. Nothing reads it on a visitor's page. The review queue pages through it
 * by post id (keyset, the meta_key index), so a 10,000-post bulk run is reviewed 50 at a time
 * without a slow query.
 *
 * ⛔ Accepting is the ONLY way a suggestion reaches the page, and it goes through the task's
 * `apply` — the same writes a person makes, recorded in the SEO history as made by `ai` so a
 * change can be traced and undone (zs-012).
 */
final class Suggestions {

	/** Meta key prefix. */
	public const PREFIX = '_zseo_ai_';

	/**
	 * The meta key of a task.
	 *
	 * @param string $task Task id.
	 * @return string
	 */
	public static function key( string $task ): string {
		return self::PREFIX . $task;
	}

	/**
	 * Keep a suggestion (replacing the one before for that task).
	 *
	 * @param int                  $id       Item.
	 * @param string               $task     Task id.
	 * @param array<string, mixed> $fields   What the AI wrote.
	 * @param string               $provider Who answered (`zinn` for credits).
	 * @param bool                 $credits  Paid with Zinn® credits.
	 * @return void
	 */
	public static function put( int $id, string $task, array $fields, string $provider, bool $credits ): void {
		if ( $id <= 0 ) {
			return;
		}
		update_post_meta(
			$id,
			self::key( $task ),
			wp_slash(
				array(
					'fields'   => $fields,
					'at'       => time(),
					'by'       => get_current_user_id(),
					'provider' => $provider,
					'credits'  => $credits,
				)
			)
		);
	}

	/**
	 * The suggestion for an item and task.
	 *
	 * @param int    $id   Item.
	 * @param string $task Task id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id, string $task ): ?array {
		$row = get_post_meta( $id, self::key( $task ), true );

		return is_array( $row ) && is_array( $row['fields'] ?? null ) ? $row : null;
	}

	/**
	 * Every suggestion an item has, by task.
	 *
	 * @param int $id Item.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_item( int $id ): array {
		$out = array();
		foreach ( array_keys( Tasks::all() ) as $task ) {
			$row = self::get( $id, $task );
			if ( null !== $row ) {
				$out[ $task ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Throw a suggestion away.
	 *
	 * @param int    $id       Item.
	 * @param string $task     Task id.
	 * @param bool   $accepted It was used (nothing to clean up).
	 * @return void
	 */
	public static function drop( int $id, string $task, bool $accepted = false ): void {
		$row = self::get( $id, $task );
		delete_post_meta( $id, self::key( $task ) );
		if ( null !== $row && ! $accepted ) {
			/**
			 * Fires after a suggestion was thrown away unused (a drawn image is deleted then).
			 *
			 * @param int                  $id   Item.
			 * @param string               $task Task id.
			 * @param array<string, mixed> $row  The suggestion.
			 */
			do_action( 'zinn_seo_ai_rejected', $id, $task, $row );
		}
	}

	/**
	 * Accept a suggestion: apply it (as the person may have edited it) and forget it.
	 *
	 * @param int                       $id     Item.
	 * @param string                    $task   Task id.
	 * @param array<string, mixed>|null $edited The fields as the person left them (null: as written).
	 * @return array<string, mixed>|\WP_Error What was applied.
	 */
	public static function accept( int $id, string $task, ?array $edited = null ) {
		$def = Tasks::get( $task );
		if ( null === $def || null === $def['apply'] ) {
			return new \WP_Error( 'zinn_seo_ai_task', __( 'This suggestion is used on the screen that asked for it.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$row = self::get( $id, $task );
		if ( null === $row && null === $edited ) {
			return new \WP_Error( 'zinn_seo_ai_none', __( 'There is no AI suggestion for this item any more.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$fields = self::merge( null === $row ? array() : (array) $row['fields'], $edited );
		$done   = call_user_func( $def['apply'], $id, $fields, array() );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		self::drop( $id, $task, true );

		/**
		 * Fires after a person accepted an AI suggestion.
		 *
		 * @param int                  $id     Item.
		 * @param string               $task   Task id.
		 * @param array<string, mixed> $fields What was applied.
		 */
		do_action( 'zinn_seo_ai_accepted', $id, $task, $fields );

		return array(
			'id'     => $id,
			'task'   => $task,
			'fields' => $fields,
		);
	}

	/**
	 * What is applied: the suggestion as kept (already cleaned when the AI answered — running the
	 * answer's cleaner again would throw away fields whose shape it changed, found by
	 * zinn_seo_p10.sh), with the person's edits on top. An edit can change a text field only:
	 * a string, sanitised; anything else they send is ignored, so an edit cannot smuggle a
	 * different image, target or language into the apply.
	 *
	 * @param array<string, mixed>      $kept   The kept suggestion's fields.
	 * @param array<string, mixed>|null $edited The person's fields, or null.
	 * @return array<string, mixed>
	 */
	public static function merge( array $kept, ?array $edited ): array {
		foreach ( (array) $edited as $name => $value ) {
			if ( ! is_string( $name ) || ! is_string( $value ) ) {
				continue;
			}
			if ( array_key_exists( $name, $kept ) && ! is_string( $kept[ $name ] ) ) {
				continue; // Not a text field.
			}
			$kept[ $name ] = false !== strpos( $value, "\n" ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}

		return $kept;
	}

	/**
	 * The review queue for a task: items after an id, in id order.
	 *
	 * @param string $task  Task id.
	 * @param int    $after Last id seen.
	 * @param int    $limit Page size.
	 * @return array{items: array<int, array<string, mixed>>, next: int, total: int}
	 */
	public static function queue( string $task, int $after = 0, int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$key   = self::key( $task );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a keyset page over the meta_key index; the screen asks once per page.
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d", $key, $after, $limit ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $ids );
		update_meta_cache( 'post', $ids );
		$items = array();
		foreach ( $ids as $id ) {
			$row  = self::get( $id, $task );
			$post = get_post( $id );
			if ( null === $row || ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $id,
				'title'    => 'attachment' === $post->post_type ? (string) $post->post_title : get_the_title( $post ),
				'type'     => $post->post_type,
				'edit'     => (string) get_edit_post_link( $id, 'raw' ),
				'thumb'    => 'attachment' === $post->post_type ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '',
				'current'  => self::current( $id, $task ),
				'fields'   => (array) $row['fields'],
				'at'       => (int) $row['at'],
				'credits'  => ! empty( $row['credits'] ),
				'provider' => (string) $row['provider'],
			);
		}

		return array(
			'items' => $items,
			'next'  => array() === $ids ? 0 : (int) end( $ids ),
			'total' => $total,
		);
	}

	/**
	 * What the item has now, for the side-by-side review (the task's `current`).
	 *
	 * @param int    $id   Item.
	 * @param string $task Task id.
	 * @return array<string, mixed>
	 */
	public static function current( int $id, string $task ): array {
		$def = Tasks::get( $task );
		if ( null === $def || ! isset( $def['current'] ) || ! is_callable( $def['current'] ) ) {
			return array();
		}

		return (array) call_user_func( $def['current'], $id );
	}

	/**
	 * Remove every suggestion (uninstall).
	 *
	 * @return void
	 */
	public static function purge(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall.
	}
}
