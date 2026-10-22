<?php
/**
 * An honest "last modified" (zs-047).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress moves a post's `post_modified` on EVERY save, including saves that change nothing a
 * reader sees (a custom field, the menu order, an SEO field, a page builder's own data). A sitemap
 * that reports those dates tells search engines to recrawl unchanged pages, and they learn to
 * distrust its dates. So when a save leaves the content as it was, the time the content last
 * changed is kept in `_zseo_lastmod` (one row, only on posts saved that way), and a later save
 * that does change the content removes it again.
 */
final class Lastmod {

	/** The fields a reader sees. */
	public const CONTENT_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_password', 'post_parent' );

	/**
	 * Did a save change what a reader sees? Pure, for the tests.
	 *
	 * @param object $before Post before (any object with the post fields).
	 * @param object $after  Post after.
	 * @return bool
	 */
	public static function content_changed( $before, $after ): bool {
		foreach ( self::CONTENT_FIELDS as $field ) {
			if ( (string) ( $before->$field ?? '' ) !== (string) ( $after->$field ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `post_updated`: keep or clear the content's own time.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after.
	 * @param \WP_Post $before  Post before.
	 * @return void
	 */
	public static function on_update( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'revision' === $after->post_type ) {
			return;
		}
		$key = '_zseo_lastmod';
		if ( self::content_changed( $before, $after ) ) {
			delete_post_meta( (int) $post_id, $key );
			return;
		}
		if ( '' === (string) get_post_meta( (int) $post_id, $key, true ) && '0000-00-00 00:00:00' !== (string) $before->post_modified_gmt ) {
			update_post_meta( (int) $post_id, $key, (string) $before->post_modified_gmt );
		}
	}
}
