<?php
/**
 * Smart robots defaults (zs-713).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SmartRobots;

use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pages no site wants in search results, kept out by default — each rule shown on the screen
 * with how many pages it covers, and each one a switch:
 *
 * - a password-protected post (search engines can only index its "enter the password" form);
 * - a category, tag or other archive with no posts in it (an empty, thin page);
 * - comment pages (`/post/comment-page-2/`), copies of the post with older comments;
 * - feeds, through an `X-Robots-Tag: noindex, follow` header (their links are still followed);
 * - page numbers that do not exist on a post (`/post/7/` on a post with one page), which
 *   WordPress answers with a copy of the post: they are sent to the post with a 301.
 *
 * ⭐ A page whose own SEO field says "index" keeps it: a person's explicit choice beats a default.
 */
final class Smart_Robots {

	/**
	 * Rule id => setting.
	 *
	 * @var array<string, string>
	 */
	public const RULES = array(
		'password'      => 'robots_def_password',
		'empty_terms'   => 'robots_def_empty_terms',
		'comment_pages' => 'robots_def_comment_pages',
		'feeds'         => 'robots_def_feeds',
		'paging'        => 'robots_def_paging',
	);

	/**
	 * Is a rule switched on?
	 *
	 * @param string $rule Rule id.
	 * @return bool
	 */
	public static function on( string $rule ): bool {
		return isset( self::RULES[ $rule ] ) && (bool) Settings::get( self::RULES[ $rule ], true );
	}

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		add_filter( 'wp_robots', array( self::class, 'robots' ), 30 );
		if ( self::on( 'feeds' ) ) {
			add_action( 'template_redirect', array( self::class, 'feed_header' ), 1 );
		}
		if ( self::on( 'paging' ) ) {
			add_action( 'template_redirect', array( self::class, 'redirect_missing_page' ), 1 );
		}
	}

	/**
	 * Which rule (if any) keeps the current page out of search results.
	 *
	 * @return string Rule id or ''.
	 */
	public static function rule_for_request(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( self::on( 'password' ) && $post instanceof \WP_Post && '' !== (string) $post->post_password ) {
				return 'password';
			}
			// Only an address that names a comment page: WordPress also sets `cpage` on the post's
			// own address when it shows the newest comments page first.
			if ( self::on( 'comment_pages' ) && self::requested_comment_page() ) {
				return 'comment_pages';
			}
			return '';
		}
		if ( self::on( 'empty_terms' ) && ( is_category() || is_tag() || is_tax() ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && 0 === (int) $term->count ) {
				return 'empty_terms';
			}
		}

		return '';
	}

	/**
	 * Did the request's address name a comment page (`/comment-page-2/`, `?cpage=2`)?
	 *
	 * @return bool
	 */
	public static function requested_comment_page(): bool {
		global $wp;
		$asked = $wp instanceof \WP && isset( $wp->query_vars['cpage'] ) ? (int) $wp->query_vars['cpage'] : 0;

		return $asked > 0;
	}

	/**
	 * Did the page's own SEO field ask for "index"?
	 *
	 * @return bool
	 */
	public static function own_index(): bool {
		$object = get_queried_object();
		$own    = array();
		if ( $object instanceof \WP_Post ) {
			$own = (array) Meta::get_post( (int) $object->ID, 'robots' );
		} elseif ( $object instanceof \WP_Term ) {
			$own = (array) Meta::get_term( (int) $object->term_id, 'robots' );
		}

		return in_array( 'index', $own, true );
	}

	/**
	 * Add noindex (keeping follow) where a rule applies.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		if ( '' === self::rule_for_request() || self::own_index() ) {
			return $robots;
		}

		return self::noindex( $robots );
	}

	/**
	 * Directives with noindex (and follow), without the preview limits that mean nothing then.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function noindex( array $robots ): array {
		unset( $robots['index'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
		$robots['noindex'] = true;
		if ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}

		return $robots;
	}

	/**
	 * Feeds: noindex for search engines, links still followed.
	 *
	 * @return void
	 */
	public static function feed_header(): void {
		if ( is_feed() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, follow', false );
		}
	}

	/**
	 * How many pages a post really has (its `<!--nextpage-->` breaks + 1).
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	public static function page_count( string $content ): int {
		return 1 + (int) preg_match_all( '/<!--\s*nextpage\s*-->/', $content );
	}

	/**
	 * A page number the post does not have goes to the post itself.
	 *
	 * @return void
	 */
	public static function redirect_missing_page(): void {
		if ( ! is_singular() || is_preview() ) {
			return;
		}
		$page = (int) get_query_var( 'page' );
		$post = get_queried_object();
		if ( $page < 2 || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $page > self::page_count( (string) $post->post_content ) ) {
			wp_safe_redirect( (string) get_permalink( $post ), 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * How many pages each rule covers on this site (for the screen).
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$types    = get_post_types( array( 'public' => true ) );
		$in       = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$password = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for the settings screen.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password <> '' AND post_type IN ($in)", array_values( $types ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		);
		$taxes    = get_taxonomies( array( 'public' => true ) );
		$tin      = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		$empty    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for the settings screen.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE count = 0 AND taxonomy IN ($tin)", array_values( $taxes ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $tin is a list of %s placeholders.
		);

		return array(
			'password'      => $password,
			'empty_terms'   => $empty,
			'comment_pages' => '1' === (string) get_option( 'page_comments', '0' ) ? (int) wp_count_comments()->approved : 0,
			'feeds'         => -1,
			'paging'        => -1,
		);
	}
}
