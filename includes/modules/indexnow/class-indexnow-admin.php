<?php
/**
 * Row actions on the posts lists: send a page to IndexNow, inspect it in Google (zs-080, zs-082).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Indexnow;

use ZinnDigital\ZinnSEO\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two links under each published post's title in the posts lists. No script or stylesheet: plain
 * links (the IndexNow one goes through admin-post.php with a nonce and comes back with a notice).
 */
final class Indexnow_Admin {

	/** The admin-post action. */
	public const ACTION = 'zseo_indexnow_one';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 20, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_actions' ), 20, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * The links.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ): array {
		$actions = (array) $actions;
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || ! Capabilities::can_edit_post( (int) $post->ID ) ) {
			return $actions;
		}
		$url   = (string) get_permalink( $post );
		$links = Indexnow::inspect_links( $url );
		/* translators: %s: the post's title. */
		$label                   = sprintf( __( 'Inspect “%s” in Google Search Console (opens in a new tab)', 'zinn-seo' ), get_the_title( $post ) );
		$actions['zseo_inspect'] = '<a href="' . esc_url( $links['google'] ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Inspect in Google', 'zinn-seo' ) . '</a>';
		if ( \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ) && '' === Indexnow::refusal() && Indexnow::wanted( $post ) ) {
			$send                  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . (int) $post->ID ), self::ACTION . (int) $post->ID );
			$actions['zseo_index'] = '<a href="' . esc_url( $send ) . '">' . esc_html__( 'Send to IndexNow', 'zinn-seo' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Queue one post and go back.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( self::ACTION . $id );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! Capabilities::can_edit_post( $id ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'zinn-seo' ), '', array( 'response' => 403 ) );
		}
		$queued = \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ) && '' === Indexnow::refusal() && Indexnow::wanted( $post ) ? Indexnow::queue( array( (string) get_permalink( $post ) ) ) : 0;
		$back   = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'zseo_indexnow', $queued > 0 ? 'sent' : 'refused', $back ? $back : admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * The notice after a row action.
	 *
	 * @return void
	 */
	public static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows a notice only.
		$state = isset( $_GET['zseo_indexnow'] ) ? sanitize_key( wp_unslash( (string) $_GET['zseo_indexnow'] ) ) : '';
		if ( 'sent' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sent to IndexNow. Bing, Yandex and the other IndexNow engines will look at the page shortly.', 'zinn-seo' ) . '</p></div>';
		} elseif ( 'refused' === $state ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Not sent: the page is not public or indexable, or this site does not send to search engines (staging copy, or search engines discouraged).', 'zinn-seo' ) . '</p></div>';
		}
	}
}
