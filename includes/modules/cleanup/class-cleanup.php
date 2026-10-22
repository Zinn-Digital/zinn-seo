<?php
/**
 * Crawl clean-up (zs-701), address and title clean-up (zs-714) and link previews (zs-727).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cleanup;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every switch is off on a new site, and the screen shows, for each, the exact line or address it
 * removes from THIS site (read from the home page), so nobody switches something off blind.
 *
 * ⛔ A clean-up never runs where it could break something a person relies on: never for a
 * logged-in user's preview, the customiser, wp-admin, REST, AJAX, cron or WP-CLI, and an address
 * redirect is only ever a GET or HEAD request.
 */
final class Cleanup {

	/**
	 * The recommended set: safe on every site we have measured (the screen's one-click button and
	 * the setup step switch these on).
	 *
	 * @var array<int, string>
	 */
	public const RECOMMENDED = array(
		'clean_shortlink',
		'clean_rsd',
		'clean_wlw',
		'clean_generator',
		'clean_feed_search',
		'clean_search_spam',
		'url_replytocom',
		'oembed_title',
		'oembed_image',
	);

	/**
	 * Query parameters kept by the parameter clean-up even though WordPress does not register them:
	 * campaign and click ids (analytics needs them), and the ones popular plugins read directly.
	 *
	 * @var array<int, string>
	 */
	public const KEEP_PARAMS = array(
		'utm_*',
		'gclid',
		'gbraid',
		'wbraid',
		'dclid',
		'fbclid',
		'msclkid',
		'ttclid',
		'twclid',
		'li_fat_id',
		'mc_cid',
		'mc_eid',
		'_ga',
		'_gl',
		'srsltid',
		'ref',
		'add-to-cart',
		'quantity',
		'variation_id',
		'wc-api',
		'wc-ajax',
		'removed_item',
		'undo_item',
		'orderby',
		'min_price',
		'max_price',
		'filter_*',
		'query_type_*',
		'rating_filter',
		'lang',
		'amp',
		'nocache',
		'ver',
	);

	/**
	 * Front-end hooks, each only while its switch is on.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		$on = static fn( string $key ): bool => (bool) Settings::get( $key, false );
		if ( $on( 'clean_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( $on( 'clean_rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( $on( 'clean_wlw' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( $on( 'clean_rest_links' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		if ( $on( 'clean_oembed_links' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		}
		if ( $on( 'clean_generator' ) ) {
			self::remove_generators();
		}
		self::feed_links( $on );
		if ( self::feeds_to_remove( $on ) ) {
			add_action( 'template_redirect', array( self::class, 'redirect_feed' ), 1 );
		}
		if ( $on( 'clean_params' ) ) {
			add_action( 'template_redirect', array( self::class, 'strip_params' ), 0 );
		}
		if ( $on( 'clean_search_spam' ) ) {
			add_action( 'template_redirect', array( self::class, 'refuse_spam_search' ), 0 );
		}
		if ( $on( 'url_replytocom' ) ) {
			add_filter( 'comment_reply_link', array( self::class, 'reply_link' ) );
			add_action( 'template_redirect', array( self::class, 'redirect_replytocom' ), 0 );
		}
		if ( '' !== (string) Settings::get( 'url_attachment', '' ) ) {
			add_action( 'template_redirect', array( self::class, 'redirect_attachment' ), 1 );
		}
		if ( array() !== (array) Settings::get( 'archives_off', array() ) ) {
			add_action( 'template_redirect', array( self::class, 'redirect_archive' ), 1 );
		}
		if ( $on( 'url_host' ) || $on( 'url_slash' ) ) {
			add_action( 'template_redirect', array( self::class, 'enforce_address' ), 2 );
		}
		if ( array() !== (array) Settings::get( 'title_case', array() ) ) {
			add_filter( 'pre_get_document_title', array( self::class, 'case_title' ), 20 );
		}
		Bases::boot_front();
	}

	/**
	 * Hooks every request type needs (the oEmbed answer is a REST response; robots.txt lines).
	 *
	 * @return void
	 */
	public static function boot_any(): void {
		if ( (bool) Settings::get( 'clean_search_robots', false ) ) {
			add_filter( 'zinn_seo_robots_star', array( self::class, 'robots_lines' ) );
		}
		if ( (bool) Settings::get( 'oembed_title', false ) || '' !== (string) Settings::get( 'oembed_author', '' ) || (bool) Settings::get( 'oembed_image', false ) ) {
			add_filter( 'oembed_response_data', array( self::class, 'oembed' ), 20, 2 );
		}
		Bases::boot_any();
	}

	// ── zs-701 ──────────────────────────────────────────────────────────────────────────────

	/**
	 * Remove the generator tags WordPress and the most common plugins print (they name software
	 * and versions, which tells attackers what to try and search engines nothing).
	 *
	 * @return void
	 */
	public static function remove_generators(): void {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		// WooCommerce and Easy Digital Downloads add their own.
		remove_action( 'get_the_generator_html', 'wc_generator_tag', 10 );
		remove_action( 'get_the_generator_xhtml', 'wc_generator_tag', 10 );
		remove_action( 'wp_head', 'edd_version_in_header' );
		// Slider Revolution prints one through its own filter.
		add_filter( 'revslider_meta_generator', '__return_empty_string' );
	}

	/**
	 * Which feed links leave the head (WordPress's own switches for each kind of feed link).
	 *
	 * @param callable $on Reads a switch.
	 * @return void
	 */
	private static function feed_links( callable $on ): void {
		if ( $on( 'clean_feed_comments' ) ) {
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
			add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
		}
		if ( $on( 'clean_feed_author' ) ) {
			add_filter( 'feed_links_extra_show_author_feed', '__return_false' );
		}
		if ( $on( 'clean_feed_search' ) ) {
			add_filter( 'feed_links_extra_show_search_feed', '__return_false' );
		}
		if ( $on( 'clean_feed_terms' ) ) {
			add_filter( 'feed_links_extra_show_category_feed', '__return_false' );
			add_filter( 'feed_links_extra_show_tag_feed', '__return_false' );
			add_filter( 'feed_links_extra_show_tax_feed', '__return_false' );
		}
		if ( $on( 'clean_feed_types' ) ) {
			add_filter( 'feed_links_extra_show_post_type_archive_feed', '__return_false' );
		}
	}

	/**
	 * Is any feed switched off?
	 *
	 * @param callable $on Reads a switch.
	 * @return bool
	 */
	private static function feeds_to_remove( callable $on ): bool {
		foreach ( array( 'clean_feed_comments', 'clean_feed_author', 'clean_feed_search', 'clean_feed_terms', 'clean_feed_types' ) as $key ) {
			if ( $on( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A switched-off feed answers with a permanent redirect to the page it belongs to, so links
	 * and crawlers that knew it are not left on an error.
	 *
	 * @return void
	 */
	public static function redirect_feed(): void {
		if ( ! is_feed() || ! self::visitor_get() ) {
			return;
		}
		$to = self::feed_target(
			array(
				'comments' => (bool) Settings::get( 'clean_feed_comments', false ),
				'author'   => (bool) Settings::get( 'clean_feed_author', false ),
				'search'   => (bool) Settings::get( 'clean_feed_search', false ),
				'terms'    => (bool) Settings::get( 'clean_feed_terms', false ),
				'types'    => (bool) Settings::get( 'clean_feed_types', false ),
			)
		);
		if ( '' !== $to ) {
			wp_safe_redirect( $to, 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * Where the current feed should go, or '' when it stays.
	 *
	 * @param array<string, bool> $off Which kinds are switched off.
	 * @return string
	 */
	public static function feed_target( array $off ): string {
		if ( ! empty( $off['comments'] ) && is_comment_feed() ) {
			return is_singular() ? (string) get_permalink( (int) get_queried_object_id() ) : home_url( '/' );
		}
		if ( ! empty( $off['search'] ) && is_search() ) {
			return (string) get_search_link( (string) get_search_query( false ) );
		}
		if ( ! empty( $off['author'] ) && is_author() ) {
			return (string) get_author_posts_url( (int) get_queried_object_id() );
		}
		if ( ! empty( $off['terms'] ) && ( is_category() || is_tag() || is_tax() ) ) {
			$link = get_term_link( (int) get_queried_object_id() );
			return is_wp_error( $link ) ? home_url( '/' ) : (string) $link;
		}
		if ( ! empty( $off['types'] ) && is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$link = get_post_type_archive_link( is_array( $type ) ? (string) reset( $type ) : (string) $type );
			return false === $link ? home_url( '/' ) : (string) $link;
		}

		return '';
	}

	/**
	 * Is this a plain visitor's GET (or HEAD) request, where a redirect is safe?
	 *
	 * @return bool
	 */
	public static function visitor_get(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_preview() || is_customize_preview() || is_robots() || is_user_logged_in() ) {
			return false;
		}

		return true;
	}

	/**
	 * Is a query parameter one to keep?
	 *
	 * @param string             $name    Parameter.
	 * @param array<int, string> $allowed Names; a trailing `*` matches a prefix.
	 * @return bool
	 */
	public static function param_allowed( string $name, array $allowed ): bool {
		foreach ( $allowed as $rule ) {
			$rule = strtolower( trim( $rule ) );
			if ( '' === $rule ) {
				continue;
			}
			if ( '*' === substr( $rule, -1 ) ? 0 === strpos( strtolower( $name ), substr( $rule, 0, -1 ) ) : strtolower( $name ) === $rule ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The parameters kept on this site: WordPress's public query variables, the built-in list and
	 * the site's own additions (one per line or comma).
	 *
	 * @return array<int, string>
	 */
	public static function allowed_params(): array {
		global $wp;
		$public = ( $wp instanceof \WP && is_array( $wp->public_query_vars ) ) ? $wp->public_query_vars : array();
		$own    = preg_split( '/[\s,]+/', (string) Settings::get( 'clean_params_allow', '' ) );

		return array_values( array_unique( array_merge( array_map( 'strval', $public ), self::KEEP_PARAMS, is_array( $own ) ? array_filter( $own ) : array() ) ) );
	}

	/**
	 * The address with unknown parameters removed, or '' when nothing is removed.
	 *
	 * @param string             $url     The requested address.
	 * @param array<int, string> $allowed Kept names.
	 * @return string
	 */
	public static function without_unknown_params( string $url, array $allowed ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return '';
		}
		$kept    = array();
		$removed = false;
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = rawurldecode( (string) strtok( $pair, '=' ) );
			$name = (string) preg_replace( '/\[.*$/', '', $name );
			if ( self::param_allowed( $name, $allowed ) ) {
				$kept[] = $pair;
			} else {
				$removed = true;
			}
		}
		if ( ! $removed ) {
			return '';
		}
		$base = strtok( $url, '?' );

		return (string) $base . ( array() !== $kept ? '?' . implode( '&', $kept ) : '' );
	}

	/**
	 * Redirect an address carrying unknown parameters to the same address without them.
	 *
	 * @return void
	 */
	public static function strip_params(): void {
		if ( empty( $_GET ) || ! self::visitor_get() || is_feed() || is_404() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the parameter NAMES are read, to redirect.
			return;
		}
		$url = self::requested_url();
		$to  = self::without_unknown_params( $url, self::allowed_params() );
		if ( '' !== $to && $to !== $url ) {
			wp_safe_redirect( $to, 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * The address this request asked for.
	 *
	 * @return string
	 */
	public static function requested_url(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$home = wp_parse_url( home_url( '/' ) );

		return ( is_ssl() ? 'https' : 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $uri;
	}

	/**
	 * Is a site search one only spam bots make (a link, an emoji, control characters, or longer than
	 * the site allows)?
	 *
	 * @param string $query The search words.
	 * @param int    $max   Longest search allowed, in characters.
	 * @return bool
	 */
	public static function spam_search( string $query, int $max ): bool {
		if ( '' === $query ) {
			return false;
		}
		if ( mb_strlen( $query ) > $max ) {
			return true;
		}
		if ( 1 === preg_match( '#(https?:|www\.|\.(com|net|org|ru|cn|xyz|top|info|biz|io)\b|@\w+\.\w|<|>)#iu', $query ) ) {
			return true;
		}

		return 1 === preg_match( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200B}-\x{200F}\x{0000}-\x{0008}]/u', $query );
	}

	/**
	 * Answer a spam search with "not found" (noindex), so it never becomes a thin page.
	 *
	 * @return void
	 */
	public static function refuse_spam_search(): void {
		if ( ! is_search() || is_admin() ) {
			return;
		}
		if ( self::spam_search( (string) get_search_query( false ), (int) Settings::get( 'clean_search_max', 100 ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	/**
	 * The robots.txt lines that keep crawlers out of site-search results.
	 *
	 * @param array<int, string> $lines Lines for every crawler.
	 * @return array<int, string>
	 */
	public static function robots_lines( $lines ): array {
		$lines   = (array) $lines;
		$path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path    = '' === $path ? '/' : trailingslashit( $path );
		$lines[] = 'Disallow: ' . $path . '?s=';
		$lines[] = 'Disallow: ' . $path . 'page/*/?s=';
		$lines[] = 'Disallow: ' . $path . 'search/';

		return $lines;
	}

	// ── zs-714 ──────────────────────────────────────────────────────────────────────────────

	/**
	 * A comment's "Reply" link without `?replytocom=` (every comment otherwise makes a copy of the
	 * page with its own address); WordPress's reply script still opens the form in place.
	 *
	 * @param string $link The link's HTML.
	 * @return string
	 */
	public static function reply_link( $link ): string {
		return (string) preg_replace_callback(
			'/href=(["\'])([^"\']*)\1/',
			static fn( array $m ): string => 'href=' . $m[1] . self::without_replytocom( $m[2] ) . $m[1],
			(string) $link
		);
	}

	/**
	 * An address without its `replytocom` parameter (`&amp;` or `&` separated), fragment kept.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function without_replytocom( string $url ): string {
		$hash = '';
		$at   = strpos( $url, '#' );
		if ( false !== $at ) {
			$hash = substr( $url, $at );
			$url  = substr( $url, 0, $at );
		}
		$q = strpos( $url, '?' );
		if ( false === $q ) {
			return $url . $hash;
		}
		$amp   = false !== strpos( $url, '&amp;' ) ? '&amp;' : '&';
		$parts = array_filter(
			explode( $amp, substr( $url, $q + 1 ) ),
			static fn( string $p ): bool => '' !== $p && 0 !== strpos( $p, 'replytocom=' )
		);

		return substr( $url, 0, $q ) . ( array() !== $parts ? '?' . implode( $amp, $parts ) : '' ) . $hash;
	}

	/**
	 * An address carrying `?replytocom=N` goes to the page, at that comment.
	 *
	 * @return void
	 */
	public static function redirect_replytocom(): void {
		$reply = isset( $_GET['replytocom'] ) ? absint( $_GET['replytocom'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only redirect.
		if ( $reply < 1 || ! is_singular() || ! self::visitor_get() ) {
			return;
		}
		$url = remove_query_arg( 'replytocom', self::requested_url() );
		wp_safe_redirect( $url . '#comment-' . $reply, 301, 'WordPress' );
		exit;
	}

	/**
	 * Attachment pages go to the page they belong to, or to the file itself.
	 *
	 * @return void
	 */
	public static function redirect_attachment(): void {
		if ( ! is_attachment() || ! self::visitor_get() ) {
			return;
		}
		$to = self::attachment_target( (int) get_queried_object_id(), (string) Settings::get( 'url_attachment', '' ) );
		if ( '' !== $to ) {
			wp_safe_redirect( $to, 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * An archive the site switched off goes to the home page.
	 *
	 * @return void
	 */
	public static function redirect_archive(): void {
		if ( ! self::visitor_get() ) {
			return;
		}
		$users = is_author() ? count(
			get_users(
				array(
					'number' => 2,
					'fields' => 'ID',
				)
			)
		) : 0;
		if ( self::archive_off( (array) Settings::get( 'archives_off', array() ), is_author() ? 'author' : ( is_date() ? 'date' : '' ), $users, (bool) have_posts() ) ) {
			wp_safe_redirect( home_url( '/' ), 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * Is this archive switched off? Pure, for the tests.
	 *
	 * @param array<int, string> $off   The setting.
	 * @param string             $kind  `author`, `date` or ''.
	 * @param int                $users Users on the site (up to 2), for `author_single`.
	 * @param bool               $posts Whether the archive lists any post.
	 * @return bool
	 */
	public static function archive_off( array $off, string $kind, int $users, bool $posts ): bool {
		if ( '' === $kind ) {
			return false;
		}
		if ( in_array( $kind, $off, true ) ) {
			return true;
		}

		return 'author' === $kind && in_array( 'author_single', $off, true ) && ( $users < 2 || ! $posts );
	}

	/**
	 * Where an attachment page goes.
	 *
	 * @param int    $id   Attachment.
	 * @param string $mode parent or file.
	 * @return string
	 */
	public static function attachment_target( int $id, string $mode ): string {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( 'parent' === $mode && $post->post_parent > 0 ) {
			$parent = get_post( (int) $post->post_parent );
			if ( $parent instanceof \WP_Post && 'publish' === $parent->post_status && '' === (string) $parent->post_password ) {
				return (string) get_permalink( $parent );
			}
		}
		if ( in_array( $mode, array( 'parent', 'file' ), true ) ) {
			$file = wp_get_attachment_url( $id );
			return is_string( $file ) ? $file : '';
		}

		return '';
	}

	/**
	 * One host and one trailing-slash style for every address (when WordPress's own canonical
	 * redirect has been switched off by a theme or plugin, duplicates otherwise get indexed).
	 *
	 * @return void
	 */
	public static function enforce_address(): void {
		if ( ! self::visitor_get() || is_404() || is_feed() ) {
			return;
		}
		$to = self::enforced(
			self::requested_url(),
			home_url( '/' ),
			(bool) Settings::get( 'url_host', false ),
			(bool) Settings::get( 'url_slash', false ) ? ( '/' === substr( (string) get_option( 'permalink_structure', '' ), -1 ) ? 'add' : 'remove' ) : ''
		);
		if ( '' !== $to ) {
			wp_safe_redirect( $to, 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * The address with the site's host and slash style, or '' when it already has them.
	 *
	 * @param string $url   Requested address.
	 * @param string $home  The home address.
	 * @param bool   $host  Enforce the host (www or not).
	 * @param string $slash add, remove or '' (leave).
	 * @return string
	 */
	public static function enforced( string $url, string $home, bool $host, string $slash ): string {
		$u = wp_parse_url( $url );
		$h = wp_parse_url( $home );
		if ( ! is_array( $u ) || ! is_array( $h ) || empty( $u['host'] ) || empty( $h['host'] ) ) {
			return '';
		}
		$out_host = $host ? (string) $h['host'] : (string) $u['host'];
		$path     = (string) ( $u['path'] ?? '/' );
		$base     = (string) ( $h['path'] ?? '/' );
		$is_file  = 1 === preg_match( '#\.[a-z0-9]{1,5}$#i', $path );
		if ( '' !== $slash && ! $is_file && '/' !== $path && trailingslashit( $base ) !== trailingslashit( $path ) ) {
			$path = 'add' === $slash ? trailingslashit( $path ) : untrailingslashit( $path );
		}
		$same_host = strtolower( $out_host ) === strtolower( (string) $u['host'] );
		if ( $same_host && (string) ( $u['path'] ?? '/' ) === $path ) {
			return '';
		}

		return ( $u['scheme'] ?? 'https' ) . '://' . $out_host . ( isset( $u['port'] ) ? ':' . $u['port'] : '' ) . $path . ( isset( $u['query'] ) ? '?' . $u['query'] : '' );
	}

	/**
	 * Words left in lower case inside a title, per language (the first and last word are always
	 * capitalised).
	 *
	 * @param string $lang Two-letter language.
	 * @return array<int, string>
	 */
	public static function small_words( string $lang ): array {
		$words = array(
			'en' => array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'onto', 'or', 'so', 'the', 'to', 'up', 'via', 'vs', 'with', 'yet' ),
			'fr' => array( 'à', 'au', 'aux', 'de', 'des', 'du', 'en', 'et', 'la', 'le', 'les', 'ou', 'par', 'pour', 'sur', 'un', 'une' ),
			'es' => array( 'a', 'al', 'con', 'de', 'del', 'el', 'en', 'la', 'las', 'los', 'o', 'para', 'por', 'un', 'una', 'y' ),
			'it' => array( 'a', 'al', 'con', 'da', 'del', 'della', 'di', 'e', 'il', 'in', 'la', 'le', 'per', 'su', 'un', 'una' ),
			'pt' => array( 'a', 'ao', 'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'no', 'o', 'os', 'para', 'por', 'um', 'uma' ),
			'nl' => array( 'de', 'een', 'en', 'het', 'in', 'met', 'of', 'op', 'te', 'van', 'voor' ),
			'de' => array( 'am', 'an', 'auf', 'aus', 'bei', 'das', 'dem', 'den', 'der', 'die', 'ein', 'eine', 'für', 'im', 'in', 'mit', 'oder', 'und', 'von', 'zu', 'zum', 'zur' ),
		);

		return $words[ $lang ] ?? array();
	}

	/**
	 * Capitalise a title: `title` (Title Case, small words kept small) or `first` (the first letter
	 * only). A word that already holds a capital after its first letter (iPhone, SEO, McDonald) or
	 * a digit is left exactly as written.
	 *
	 * @param string $title Title.
	 * @param string $style title, first or ''.
	 * @param string $lang  Two-letter language.
	 * @return string
	 */
	public static function capitalise( string $title, string $style, string $lang ): string {
		if ( '' === $style || '' === trim( $title ) ) {
			return $title;
		}
		$ucfirst = static fn( string $w ): string => mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_substr( $w, 1 );
		if ( 'first' === $style ) {
			return $ucfirst( $title );
		}
		$parts = preg_split( '/(\s+)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $title;
		}
		$words = array_keys( array_filter( $parts, static fn( $p ) => '' !== trim( (string) $p ) ) );
		$first = (int) reset( $words );
		$last  = (int) end( $words );
		$small = self::small_words( $lang );
		foreach ( $parts as $i => $word ) {
			if ( '' === trim( (string) $word ) || 1 === preg_match( '/\d|.\p{Lu}/u', (string) $word ) ) {
				continue;
			}
			$plain = mb_strtolower( trim( (string) $word, '.,:;!?"\'()' ) );
			if ( $i !== $first && $i !== $last && in_array( $plain, $small, true ) ) {
				$parts[ $i ] = mb_strtolower( (string) $word );
				continue;
			}
			$parts[ $i ] = $ucfirst( (string) $word );
		}

		return implode( '', $parts );
	}

	/**
	 * The language of the page being shown: its Tranzly language, else the site's.
	 *
	 * @return string Two letters.
	 */
	public static function page_language(): string {
		$lang = function_exists( 'tranzly_current_language' ) ? (string) tranzly_current_language() : '';
		if ( '' === $lang ) {
			$lang = (string) get_locale();
		}

		return strtolower( substr( str_replace( '-', '_', $lang ), 0, 2 ) );
	}

	/**
	 * The page title, capitalised the way the site chose for its language.
	 *
	 * @param string $title Title so far.
	 * @return string
	 */
	public static function case_title( $title ): string {
		$title = (string) $title;
		$lang  = self::page_language();
		$all   = (array) Settings::get( 'title_case', array() );
		$style = (string) ( $all[ $lang ]['style'] ?? ( $all['all']['style'] ?? '' ) );
		if ( '' === $title || '' === $style ) {
			return $title;
		}
		$plain = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );

		return esc_html( self::capitalise( $plain, $style, $lang ) );
	}

	// ── zs-727 ──────────────────────────────────────────────────────────────────────────────

	/**
	 * The link preview other apps read (Discord, Slack, Mastodon, LinkedIn): the page's SEO title,
	 * its sharing image, and the author shown as the site — or not at all — instead of a login name.
	 *
	 * @param array<string, mixed> $data The oEmbed answer.
	 * @param \WP_Post|mixed       $post The post.
	 * @return array<string, mixed>
	 */
	public static function oembed( $data, $post ): array {
		$data = (array) $data;
		if ( ! $post instanceof \WP_Post ) {
			return $data;
		}
		if ( (bool) Settings::get( 'oembed_title', false ) ) {
			$title = self::seo_title( $post );
			if ( '' !== $title ) {
				$data['title'] = $title;
			}
		}
		$author = (string) Settings::get( 'oembed_author', '' );
		if ( 'hide' === $author ) {
			unset( $data['author_name'], $data['author_url'] );
		} elseif ( 'site' === $author ) {
			$data['author_name'] = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$data['author_url']  = home_url( '/' );
		}
		if ( (bool) Settings::get( 'oembed_image', false ) && empty( $data['thumbnail_url'] ) ) {
			$image = self::share_image( $post );
			if ( array() !== $image ) {
				$data['thumbnail_url']    = $image[0];
				$data['thumbnail_width']  = $image[1];
				$data['thumbnail_height'] = $image[2];
			}
		}

		return $data;
	}

	/**
	 * A post's SEO title, as its page shows it.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function seo_title( \WP_Post $post ): string {
		if ( ! class_exists( '\ZinnDigital\ZinnSEO\Templates\Resolver' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Templates\Context' ) ) {
			return '';
		}
		$title = \ZinnDigital\ZinnSEO\Templates\Resolver::title( \ZinnDigital\ZinnSEO\Templates\Context::for_post( $post ) );

		return html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The image a post is shared with: its own sharing image, else its featured image.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{0: string, 1: int, 2: int}|array{}
	 */
	public static function share_image( \WP_Post $post ): array {
		$id = (int) get_post_meta( $post->ID, '_zseo_og_image', true );
		if ( $id < 1 ) {
			$id = (int) get_post_thumbnail_id( $post );
		}
		if ( $id < 1 ) {
			return array();
		}
		$src = wp_get_attachment_image_src( $id, 'large' );

		return is_array( $src ) ? array( (string) $src[0], (int) $src[1], (int) $src[2] ) : array();
	}

	// ── The screen ──────────────────────────────────────────────────────────────────────────

	/**
	 * What each zs-701 switch removes, read from this site's home page (the line itself), so the
	 * screen can show it. Lines already gone are reported as gone.
	 *
	 * @param string               $html    The home page.
	 * @param array<string, mixed> $headers Its response headers (lower-case names).
	 * @return array<string, array<int, string>>
	 */
	public static function found_lines( string $html, array $headers ): array {
		$patterns = array(
			'clean_shortlink'     => '#<link[^>]+rel=["\']shortlink["\'][^>]*>#i',
			'clean_rsd'           => '#<link[^>]+rel=["\']EditURI["\'][^>]*>#i',
			'clean_wlw'           => '#<link[^>]+wlwmanifest[^>]*>#i',
			'clean_rest_links'    => '#<link[^>]+rel=["\']https://api\.w\.org/["\'][^>]*>|<link[^>]+rel=["\']alternate["\'][^>]+application/json["\'][^>]*>#i',
			'clean_oembed_links'  => '#<link[^>]+application/(json|xml)\+oembed[^>]*>#i',
			'clean_generator'     => '#<meta[^>]+name=["\']generator["\'][^>]*>#i',
			'clean_feed_comments' => '#<link[^>]+rel=["\']alternate["\'][^>]+(comments/feed|Comments Feed)[^>]*>#i',
		);
		$out      = array();
		foreach ( $patterns as $key => $re ) {
			$out[ $key ] = preg_match_all( $re, $html, $m ) ? array_values( array_unique( $m[0] ) ) : array();
		}
		foreach ( array(
			'link' => array(
				'clean_shortlink'  => 'shortlink',
				'clean_rest_links' => 'api.w.org',
			),
		) as $name => $map ) {
			foreach ( (array) ( $headers[ $name ] ?? array() ) as $value ) {
				foreach ( $map as $key => $needle ) {
					if ( false !== strpos( (string) $value, $needle ) ) {
						$out[ $key ][] = 'Link: ' . $value;
					}
				}
			}
		}

		return $out;
	}
}
