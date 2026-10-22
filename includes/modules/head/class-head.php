<?php
/**
 * The page head: title, description, robots, canonical, verification tags and the one JSON-LD
 * graph (zs-020, zs-021, zs-027, zs-415).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Head;

use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Setup;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ THE BUDGET (architecture §1.4, measured by wp/tests/wp-integration/zinn_seo.sh on every
 * template type): no script but the one JSON-LD graph, no stylesheet, no HTML comment, no
 * generator tag, nothing that names this plugin, and the head grows by at most 2.5 KB.
 *
 * WordPress already prints a `<title>` (themes with title-tag support) and a robots meta tag; we
 * change their CONTENT through WordPress's own filters rather than printing a second copy. The
 * canonical link WordPress prints on single posts is replaced by ours, which also covers
 * archives and page 2+. Everything else this plugin prints goes out in one block at `wp_head`
 * priority 1, so it sits at the top of the head, before theme and plugin assets.
 */
final class Head {

	/** Webmaster verification: setting => meta name (zs-027). */
	public const VERIFY = array(
		'verify_google'    => 'google-site-verification',
		'verify_bing'      => 'msvalidate.01',
		'verify_yandex'    => 'yandex-verification',
		'verify_baidu'     => 'baidu-site-verification',
		'verify_pinterest' => 'p:domain_verify',
		'verify_naver'     => 'naver-site-verification',
	);

	/**
	 * The module's settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		$out = array(
			'run_with_others'  => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
			// rel="prev" / rel="next" between the pages of a list and of a post split into pages
			// (Yoast SEO, All in One SEO, SEOPress, The SEO Framework and Rank Math all print them;
			// Bing still reads them). Found missing by the importer certification, queue Q254.
			'adjacent_links'   => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'appearance',
			),
			// …on pages kept out of search too (The SEO Framework prints them there).
			'adjacent_noindex' => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
			// Clicking an empty title or description field puts the suggested text in it to
			// change (zs-757). Off for sites that had the plugin before; a first install turns it
			// on (Plugin::activate()).
			'placeholder_edit' => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
		);
		foreach ( array_keys( self::VERIFY ) as $key ) {
			$out[ $key ] = array(
				'type'    => 'string',
				'default' => '',
				'max'     => 200,
				'pattern' => '/^[A-Za-z0-9_\-:.=+\/]*$/',
				'group'   => 'verification',
			);
		}

		return $out;
	}

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		if ( ! self::should_print() ) {
			return;
		}
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 15 );
		add_filter( 'wp_title', array( self::class, 'legacy_title' ), 15, 3 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );
		add_action( 'wp', array( self::class, 'take_over_canonical' ), 99 );
		add_action( 'wp_head', array( self::class, 'print_head' ), 1 );
		add_action( 'wp_head', array( self::class, 'one_title' ), 0 );
		add_action( 'template_redirect', array( self::class, 'author_redirect' ), 1 );
	}

	/**
	 * An author archive the site moved elsewhere: a 301 to the address set on the profile
	 * (zs-756). Only someone who manages SEO can set it (Editor::save_user()).
	 *
	 * @return void
	 */
	public static function author_redirect(): void {
		if ( ! is_author() ) {
			return;
		}
		$user = get_queried_object();
		$to   = $user instanceof \WP_User ? self::author_target( (int) $user->ID ) : '';
		if ( '' === $to ) {
			return;
		}
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the site's own SEO manager chose this address, which may be another site; "WordPress" names nothing of this plugin (no-trace mode).
		wp_redirect( $to, 301, 'WordPress' );
		exit;
	}

	/**
	 * Where an author's archive redirects to ('' for nowhere, or for the archive itself).
	 *
	 * @param int $user_id Author.
	 * @return string
	 */
	public static function author_target( int $user_id ): string {
		$to = esc_url_raw( trim( (string) get_user_meta( $user_id, Meta::key( 'redirect' ), true ) ), array( 'http', 'https' ) );
		if ( '' === $to || untrailingslashit( $to ) === untrailingslashit( (string) get_author_posts_url( $user_id ) ) ) {
			return '';
		}

		return $to;
	}

	/**
	 * Does this site want our tags? Not when another SEO plugin is active and printing its own,
	 * unless the owner chose to run both (two descriptions or two canonicals confuse search
	 * engines; the importers and the switch-off live in their own module).
	 *
	 * @return bool
	 */
	public static function should_print(): bool {
		if ( (bool) Settings::get( 'run_with_others', false ) ) {
			return true;
		}

		return array() === self::other_seo_plugins();
	}

	/**
	 * Other SEO plugins active on this site (main file => name), from the list the setup knows.
	 *
	 * @return array<string, string>
	 */
	public static function other_seo_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( Setup::known_seo_plugins() as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$out[ $file ] = $name;
			}
		}

		return $out;
	}

	/**
	 * One `<title>` on pages a plugin draws with `get_header()` in a block theme.
	 *
	 * A block theme has no header.php, so WordPress falls back to its own old header file — which
	 * prints a `<title>` itself and then runs `wp_head`, where the title is printed again. Marketplace
	 * store pages, some forum and course pages end up with two. The one from `wp_head` is dropped
	 * there; both said the same.
	 *
	 * @return void
	 */
	public static function one_title(): void {
		if ( did_action( 'get_header' ) && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && false !== strpos( (string) locate_template( array( 'header.php' ) ), '/theme-compat/' ) ) {
			remove_action( 'wp_head', '_wp_render_title_tag', 1 );
			remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
		}
	}

	/**
	 * The document title (themes with title-tag support).
	 *
	 * @param string $title What another filter returned.
	 * @return string
	 */
	public static function document_title( $title ): string {
		// A feed's channel title is WordPress's (site name, or "Comments for …"): feeds read the
		// document title through get_wp_title_rss(), and ours there doubled the site name
		// ("Site – Site", measured on 0.13.2, X1A survey 2026-10-12).
		if ( is_feed() ) {
			return (string) $title;
		}
		$ours = Resolver::title( Context::current() );

		return '' !== $ours ? esc_html( $ours ) : (string) $title;
	}

	/**
	 * Old themes that still call wp_title() themselves.
	 *
	 * @param string $title       Title.
	 * @param string $sep         Separator.
	 * @param string $seplocation Side.
	 * @return string
	 */
	public static function legacy_title( $title, $sep = '', $seplocation = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's signature.
		if ( is_feed() ) {
			return (string) $title;
		}
		$ours = Resolver::title( Context::current() );

		return '' !== $ours ? esc_html( $ours ) : (string) $title;
	}

	/**
	 * Robots directives, through WordPress's own robots tag.
	 *
	 * ⛔ A site set to "discourage search engines" keeps WordPress's noindex: nothing here can
	 * turn indexing back on.
	 *
	 * @param array<string, bool|string> $robots Directives so far.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		$c      = Context::current();
		$ours   = Resolver::robots( $c );
		// WordPress marks every search results page noindex itself. A site that chose to have
		// them indexed (Search appearance, or a plugin it moved from) gets what it chose — the
		// setting used to be shown and not honoured (found by the importer's check of the
		// rendered pages, 2026-10-10). Never on a site that discourages search engines.
		if ( 'search' === $c->kind && ! isset( $ours['noindex'] ) && '0' !== (string) get_option( 'blog_public', '1' ) ) {
			unset( $robots['noindex'] );
		}
		foreach ( $ours as $directive => $value ) {
			$robots[ $directive ] = $value;
		}
		if ( ! empty( $robots['noindex'] ) ) {
			// Preview limits mean nothing on a page that asks not to be indexed.
			unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
		}
		if ( isset( $robots['nosnippet'] ) ) {
			unset( $robots['max-snippet'] );
		}

		return $robots;
	}

	/**
	 * Replace WordPress's canonical link with ours (which also covers archives and page 2+).
	 *
	 * @return void
	 */
	public static function take_over_canonical(): void {
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Print the head block.
	 *
	 * @return void
	 */
	public static function print_head(): void {
		$c     = Context::current();
		$lines = array();
		$desc  = Resolver::description( $c );
		if ( '' !== $desc ) {
			$lines[] = '<meta name="description" content="' . esc_attr( $desc ) . '">';
		}
		$canonical = Resolver::canonical( $c );
		if ( '' !== $canonical ) {
			$lines[] = '<link rel="canonical" href="' . esc_url( $canonical ) . '">';
		}
		foreach ( self::adjacent() as $rel => $href ) {
			$lines[] = '<link rel="' . esc_attr( $rel ) . '" href="' . esc_url( $href ) . '">';
		}
		if ( $c->front || 'home' === $c->kind ) {
			foreach ( self::VERIFY as $key => $name ) {
				$code = (string) Settings::get( $key, '' );
				if ( '' !== $code ) {
					$lines[] = '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $code ) . '">';
				}
			}
		}

		/**
		 * Filters the tags the head block prints (one complete tag per entry). The social module
		 * adds Open Graph and X here.
		 *
		 * @param array<int, string> $lines Tags.
		 * @param Context            $c     What the page is about.
		 */
		$lines = (array) apply_filters( 'zinn_seo_head_tags', $lines, $c );
		$graph = Graph::render( $c );
		if ( '' !== $graph ) {
			$lines[] = $graph;
		}
		if ( array() !== $lines ) {
			echo implode( "\n", array_map( 'strval', $lines ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every tag was escaped as it was built (esc_attr/esc_url above, the social module's own, and Graph::render()).
		}
	}

	/**
	 * The previous and next page of the list or the split post being viewed: rel => address.
	 *
	 * @return array<string, string>
	 */
	public static function adjacent(): array {
		if ( ! (bool) Settings::get( 'adjacent_links', true ) || is_404() || is_feed() ) {
			return array();
		}
		// A page kept out of search leads crawlers nowhere (as Yoast SEO and Rank Math do).
		if ( ! (bool) Settings::get( 'adjacent_noindex', false ) && isset( Resolver::robots( Context::current() )['noindex'] ) ) {
			return array();
		}
		/**
		 * Filters whether the previous/next links are left out on this page. The name is Rank
		 * Math's own filter, so site code written for it keeps working after a move.
		 *
		 * @param bool $disable Leave them out.
		 */
		if ( (bool) apply_filters( 'rank_math/frontend/disable_adjacent_rel_links', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- another plugin's filter, kept for the code written for it.
			return array();
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post || false === strpos( (string) $post->post_content, '<!--nextpage-->' ) ) {
				return array();
			}
			$pages = substr_count( (string) $post->post_content, '<!--nextpage-->' ) + 1;
			$page  = max( 1, (int) get_query_var( 'page' ) );

			return self::adjacent_pair(
				$page,
				$pages,
				static function ( int $n ) use ( $post ): string {
					$link = _wp_link_page( $n );
					return 1 === preg_match( '/href=["\']([^"\']+)["\']/', $link, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : (string) get_permalink( $post );
				}
			);
		}
		// Not on search results: endless, and kept out of search (no plugin prints them there).
		if ( is_home() || is_archive() ) {
			global $wp_query;
			$max = $wp_query instanceof \WP_Query ? (int) $wp_query->max_num_pages : 0;

			return self::adjacent_pair( max( 1, (int) get_query_var( 'paged' ) ), $max, static fn( int $n ): string => (string) get_pagenum_link( $n, false ) );
		}

		return array();
	}

	/**
	 * The rel => address of the pages before and after page $page of $pages. Pure, for the tests.
	 *
	 * @param int      $page  This page (1-based).
	 * @param int      $pages How many there are.
	 * @param callable $link  `function ( int $n ): string` — the address of page $n.
	 * @return array<string, string>
	 */
	public static function adjacent_pair( int $page, int $pages, callable $link ): array {
		$out = array();
		if ( $pages < 2 || $page > $pages ) {
			return $out;
		}
		if ( $page > 1 ) {
			$out['prev'] = (string) $link( $page - 1 );
		}
		if ( $page < $pages ) {
			$out['next'] = (string) $link( $page + 1 );
		}

		return array_filter( $out, static fn( string $u ): bool => '' !== $u );
	}

	/**
	 * Read the content of a pasted verification tag: `<meta name="…" content="CODE">` → CODE.
	 *
	 * @param string $value What was pasted.
	 * @return string
	 */
	public static function verification_code( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $value, $m ) ) {
			$value = $m[1];
		}

		return trim( $value );
	}
}
