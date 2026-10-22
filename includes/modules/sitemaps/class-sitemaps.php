<?php
/**
 * The XML sitemaps: settings, the addresses they answer on, and what keeps them current
 * (zs-040 … zs-051).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

use ZinnDigital\ZinnSEO\Head\Head;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Notrace;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ A sitemap request is answered at `plugins_loaded` from a file already on disk: no query for
 * posts, no theme, no template. Every other page view pays one string comparison of its address.
 *
 * Addresses (relative to the site's home):
 * - normal: `sitemap.xml` (the index), `sitemap-<file>.xml`, `sitemap.xsl` (the human view);
 *   `sitemap_index.xml` and WordPress's `wp-sitemap*.xml` redirect to the index, so an address
 *   another plugin already submitted keeps working.
 * - no-trace (architecture §1.7): `<hash>-sitemap.xml` and `<hash>-sm-<file>.xml`, no stylesheet.
 *   The addresses of the other mode redirect (301) to these, in both directions, so switching
 *   never leaves search engines with a dead address.
 */
final class Sitemaps {

	/**
	 * Pages changed in this request, written once at shutdown.
	 *
	 * @var array<int, string>
	 */
	private static array $marks = array();

	/**
	 * The module's settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array(
			'sitemap_per_file'           => array(
				'type'    => 'int',
				'default' => 1000,
				'min'     => 100,
				'max'     => Writer::MAX_URLS,
				'group'   => 'sitemaps',
			),
			'sitemap_exclude_types'      => array(
				'type'    => 'list',
				'default' => array( 'attachment' ),
				'group'   => 'sitemaps',
			),
			'sitemap_exclude_taxonomies' => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'sitemaps',
			),
			'sitemap_exclude_ids'        => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 2000,
				'pattern' => '/^[\dt,\s]*$/i',
				'group'   => 'sitemaps',
			),
			'sitemap_images'             => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'sitemaps',
			),
			'sitemap_images_max'         => array(
				'type'    => 'int',
				'default' => 20,
				'min'     => 1,
				'max'     => 1000,
				'group'   => 'sitemaps',
			),
			'sitemap_authors'            => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'sitemaps',
			),
			'sitemap_alternates'         => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'sitemaps',
			),
			'sitemap_woo_outofstock'     => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'sitemaps',
			),
			'sitemap_xsl'                => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'sitemaps',
			),
			// The index's address. A site moving from Rank Math keeps `sitemap_index.xml`, the
			// address search engines already have (set by the importer), and `sitemap.xml` sends
			// visitors there, exactly as before the move.
			'sitemap_empty_taxonomies'   => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'sitemaps',
			),
			'sitemap_index_name'         => array(
				'type'    => 'string',
				'default' => 'sitemap.xml',
				'max'     => 20,
				'pattern' => '/^(sitemap|sitemap_index)\.xml$/',
				'group'   => 'sitemaps',
			),
		);
	}

	/**
	 * Is this module the one answering for sitemaps? Not while another SEO plugin is active and
	 * printing its own (the same rule as the head), unless the owner chose to run both.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return Head::should_print();
	}

	/**
	 * Hooks for every context.
	 *
	 * @param string $context Request context.
	 * @return void
	 */
	public static function boot( string $context ): void {
		if ( ! self::active() ) {
			return;
		}
		Builder::register();
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_filter( 'zinn_seo_robots_sitemaps', array( self::class, 'robots_lines' ) );
		add_filter( 'zinn_seo_notrace_changes', array( self::class, 'notrace_changes' ), 10, 2 );
		add_action( 'zinn_seo_notrace_changed', array( self::class, 'notrace_changed' ), 10, 2 );
		self::watch();
		if ( 'front' === $context ) {
			// Answered once every module has started (so the crawler log counts the request),
			// before WordPress loads the theme or queries a post.
			add_action( 'zinn_seo_loaded', array( self::class, 'maybe_serve' ) );
		}
	}

	/**
	 * Watch what changes a sitemap file: posts, terms, the SEO fields and the settings.
	 *
	 * @return void
	 */
	private static function watch(): void {
		add_action( 'post_updated', array( Lastmod::class, 'on_update' ), 10, 3 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 10, 1 );
		add_action( 'created_term', array( self::class, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( self::class, 'on_term' ), 10, 3 );
		add_action( 'pre_delete_term', array( self::class, 'on_term_delete' ), 10, 2 );
		add_action( 'edited_term_taxonomy', array( self::class, 'on_term_count' ), 10, 2 );
		add_action( 'zinn_seo_field_saved', array( self::class, 'on_field' ), 10, 3 );
		add_action( 'zinn_seo_settings_saved', array( self::class, 'on_settings' ), 10, 2 );
		add_action( 'update_option_show_on_front', array( self::class, 'mark_home' ) );
		add_action( 'update_option_page_on_front', array( self::class, 'mark_all' ) );
		add_action( 'update_option_permalink_structure', array( self::class, 'mark_all' ) );
		add_action( 'update_option_home', array( self::class, 'mark_all' ) );
		add_action( 'shutdown', array( self::class, 'flush_marks' ) );
	}

	// ── Serving ───────────────────────────────────────────────────────────────────────────────

	/**
	 * The request path relative to the site's home ('' for the home itself), or null when the
	 * request is not a plain GET/HEAD.
	 *
	 * @return string|null
	 */
	public static function request_path(): ?string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		return self::relative( $path, (string) wp_parse_url( self::root( '/' ), PHP_URL_PATH ) );
	}

	/**
	 * A path relative to the home path. Pure, for the tests.
	 *
	 * @param string $path Request path.
	 * @param string $home Home path (`/` or `/blog/`).
	 * @return string
	 */
	public static function relative( string $path, string $home ): string {
		$home = '/' . trim( $home, '/' );
		$home = '/' === $home ? '/' : $home . '/';
		if ( 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return ltrim( rawurldecode( $path ), '/' );
	}

	/**
	 * What an address is: [kind, file] with kind index|file|xsl|redirect, or null.
	 * Pure (the mode and hash are passed in), for the tests.
	 *
	 * @param string $path    Relative path.
	 * @param bool   $notrace No-trace mode on.
	 * @param string $hash    The site's no-trace hash.
	 * @param string $index   The index's address (`sitemap.xml`, or `sitemap_index.xml` for a site
	 *                        that kept Rank Math's).
	 * @return array{0: string, 1: string}|null
	 */
	public static function route( string $path, bool $notrace, string $hash, string $index = 'sitemap.xml' ): ?array {
		if ( '' === $path || ( false === strpos( $path, 'sitemap' ) && ( '' === $hash || 0 !== strpos( $path, $hash . '-' ) ) ) ) {
			return null;
		}
		// No-trace mode (zs-747) uses WordPress's own addresses, which millions of sites answer:
		// nothing about them points to this plugin, and nothing ties one of our sites to another.
		// Addresses a site used in an earlier no-trace scheme (`<hash>-sitemap.xml`) redirect.
		$kept = ! $notrace && 'sitemap_index.xml' === $index;
		if ( 'sitemap_index.xml' === $path ) {
			return $kept ? array( 'index', '' ) : array( 'redirect', '' );
		}
		if ( 'wp-sitemap.xml' === $path ) {
			return $notrace ? array( 'index', '' ) : array( 'redirect', '' );
		}
		// WordPress's own sitemap files (submitted before this plugin ran) go to the index.
		if ( 1 === preg_match( '/^wp-sitemap-((posts|taxonomies)-[a-z0-9_-]+|users)-\d+\.xml$/', $path ) ) {
			return array( 'redirect', '' );
		}
		if ( 1 === preg_match( '/^wp-sitemap-([a-z0-9-]+)\.xml$/', $path, $m ) ) {
			return $notrace ? array( 'file', $m[1] ) : array( 'redirect', $m[1] );
		}
		if ( 'sitemap.xml' === $path ) {
			return $notrace || $kept ? array( 'redirect', '' ) : array( 'index', '' );
		}
		if ( '' !== $hash && $hash . '-sitemap.xml' === $path ) {
			return array( 'redirect', '' );
		}
		// Other plugins' index addresses a site may have submitted: All in One SEO's RSS sitemap,
		// SEOPress's `sitemaps.xml`, a language's own index under its folder (`/fr/sitemap.xml`,
		// The SEO Framework with Polylang) — importer certification, queue Q254: they answered
		// 404 after a move. They go to the index.
		if ( in_array( $path, array( 'sitemap.rss', 'sitemaps.xml', 'sitemap-index.xml' ), true ) || 1 === preg_match( '#^[a-z]{2,3}(-[a-z]{2,4})?/(sitemap|sitemap_index|wp-sitemap)\.xml$#', $path ) ) {
			return array( 'redirect', '' );
		}
		if ( 'sitemap.xsl' === $path ) {
			return $notrace ? null : array( 'xsl', '' );
		}
		if ( 'sitemap.css' === $path ) {
			return $notrace ? null : array( 'css', '' );
		}
		// The plain-CSS view, for a browser that no longer applies XSLT (zs-762).
		if ( 'sitemap-view.css' === $path ) {
			return $notrace ? null : array( 'viewcss', '' );
		}
		if ( 1 === preg_match( '/^sitemap-([a-z0-9-]+)\.xml$/', $path, $m ) ) {
			return $notrace ? array( 'redirect', $m[1] ) : array( 'file', $m[1] );
		}
		if ( '' !== $hash && 1 === preg_match( '/^' . preg_quote( $hash, '/' ) . '-sm-([a-z0-9-]+)\.xml$/', $path, $m ) ) {
			return array( 'redirect', $m[1] );
		}
		// A file in the naming Rank Math and Yoast use (`post-sitemap.xml`, `product-sitemap3.xml`,
		// `zt-es-sitemap12.xml`). Another plugin may still answer it (a Rank Math sitemap provider,
		// through the Pro bridge); otherwise, if it names a content type, taxonomy or one of those
		// plugins' own files, it goes to our index, so the files a site submitted before the move
		// keep working. Decided at `wp_loaded` (content types are registered by then).
		if ( 1 === preg_match( '/^([a-z0-9_-]+)-sitemap(\d*)\.xml$/', $path, $m ) ) {
			return array( 'foreign', $m[1] . '|' . $m[2] );
		}

		return null;
	}

	/**
	 * Is a file name in another plugin's scheme one that plugin made for the site's own content
	 * (so its address goes to our index)? Pure apart from the lists passed in, for the tests.
	 *
	 * @param string             $type       The part before `-sitemap`.
	 * @param array<int, string> $post_types Public content types.
	 * @param array<int, string> $taxonomies Public taxonomies.
	 * @return bool
	 */
	public static function former_file( string $type, array $post_types, array $taxonomies ): bool {
		// Rank Math's and Yoast's own files beside the content types: authors, videos, the local
		// business file, news, and Yoast's custom-type index names.
		if ( in_array( $type, array( 'author', 'video', 'local', 'news', 'page', 'post', 'category', 'post_tag' ), true ) ) {
			return true;
		}

		return in_array( $type, $post_types, true ) || in_array( $type, $taxonomies, true );
	}

	/**
	 * Answer a file in another plugin's naming (see route()): what a plugin hooked on
	 * `zinn_seo_foreign_sitemap` gives, else a redirect to our index for a file that plugin made
	 * for the site's content, else nothing (WordPress carries on and answers the address).
	 *
	 * @param string $type The part before `-sitemap`.
	 * @param int    $page The number after it (1 when none).
	 * @return void
	 */
	public static function serve_foreign( string $type, int $page ): void {
		/**
		 * Filters the XML for a sitemap file in another plugin's naming (`<type>-sitemap<page>.xml`).
		 * Return the complete XML to answer it, `false` to answer "not found", null to leave it.
		 *
		 * @param string|false|null $xml  The XML so far.
		 * @param string            $type The part before `-sitemap`.
		 * @param int               $page The page (1 when the name has no number).
		 */
		$xml = apply_filters( 'zinn_seo_foreign_sitemap', null, $type, $page );
		if ( is_string( $xml ) && '' !== $xml ) {
			self::send( $xml, 'application/xml', md5( $xml ), 0 );
			exit;
		}
		if ( false === $xml ) {
			self::not_found();
		}
		$types = array_values( array_map( 'strval', get_post_types( array( 'public' => true ) ) ) );
		$taxes = array_values( array_map( 'strval', get_taxonomies( array( 'public' => true ) ) ) );
		if ( self::former_file( $type, $types, $taxes ) ) {
			wp_safe_redirect( self::url(), 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * The public address of the index, or of one file.
	 *
	 * @param string $file File name ('' for the index).
	 * @return string
	 */
	public static function url( string $file = '' ): string {
		if ( Notrace::enabled() ) {
			return self::root( '/' . ( '' === $file ? 'wp-sitemap.xml' : 'wp-sitemap-' . $file . '.xml' ) );
		}

		return self::root( '/' . ( '' === $file ? self::index_name() : 'sitemap-' . $file . '.xml' ) );
	}

	/**
	 * An address at the site's root, never under a language's folder: a language plugin
	 * (Polylang, WPML) adds the visitor's language to `home_url()`, which put robots.txt's
	 * sitemap at `/en/sitemap.xml` and doubled the folder in the files' addresses (zs-762).
	 *
	 * @param string $path Path from the root (`/sitemap.xml`).
	 * @return string
	 */
	public static function root( string $path ): string {
		$home = (string) get_option( 'home' );
		if ( '' === $home ) {
			return home_url( $path );
		}

		return set_url_scheme( untrailingslashit( $home ) ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * The index's file name (normal mode).
	 *
	 * @return string
	 */
	public static function index_name(): string {
		return 'sitemap_index.xml' === (string) Settings::get( 'sitemap_index_name', 'sitemap.xml' ) ? 'sitemap_index.xml' : 'sitemap.xml';
	}

	/**
	 * Answer a sitemap request, if this is one (and end the request).
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		$path = self::request_path();
		if ( null === $path ) {
			return;
		}
		$notrace = Notrace::enabled();
		$route   = self::route( $path, $notrace, $notrace ? Notrace::hash() : (string) Settings::get( 'notrace_hash', '' ), self::index_name() );
		if ( null === $route ) {
			return;
		}
		/**
		 * Fires when a sitemap address was requested (the AI-crawler log counts bots here).
		 *
		 * @param string $path The address, relative to home.
		 */
		do_action( 'zinn_seo_served_sitemap', $path );
		switch ( $route[0] ) {
			case 'redirect':
				wp_safe_redirect( self::url( $route[1] ), 301, 'WordPress' );
				exit;
			case 'xsl':
				// Translated text: answered once translations and the locale are ready.
				add_action(
					'wp_loaded',
					static function (): void {
						require_once __DIR__ . '/class-xsl.php';
						$xsl = Xsl::render();
						self::send( $xsl, 'text/xsl', md5( $xsl ), 0 );
						exit;
					}
				);
				return;
			case 'css':
				require_once __DIR__ . '/class-xsl.php';
				$css = Xsl::css();
				self::send( $css, 'text/css', md5( $css ), 0 );
				exit;
			case 'viewcss':
				// Translated text: answered once translations and the locale are ready.
				add_action(
					'wp_loaded',
					static function (): void {
						require_once __DIR__ . '/class-xsl.php';
						$css = Xsl::view_css();
						self::send( $css, 'text/css', md5( $css ), 0 );
						exit;
					}
				);
				return;
			case 'foreign':
				$parts = explode( '|', $route[1] );
				add_action(
					'wp_loaded',
					static function () use ( $parts ): void {
						self::serve_foreign( $parts[0], max( 1, (int) ( $parts[1] ?? 1 ) ) );
					}
				);
				return;
			case 'index':
				/**
				 * Filters whether the index waits for `wp_loaded` (content types and every plugin
				 * registered): true when a filter of its rows needs them (the Pro Rank Math bridge).
				 *
				 * @param bool $late Wait.
				 */
				if ( ! did_action( 'wp_loaded' ) && (bool) apply_filters( 'zinn_seo_sitemap_index_late', false ) ) {
					add_action( 'wp_loaded', array( self::class, 'serve_index' ) );
					return;
				}
				self::serve_index();
				exit;
			case 'file':
				$served = Store::served();
				if ( ! isset( $served['files'][ $route[1] ] ) ) {
					// A file name that is not one of ours now — another plugin's in the same
					// pattern (Squirrly's `sitemap-posts.xml`, submitted before a move: importer
					// certification, queue Q254), or one of ours from before the content changed —
					// goes to the index rather than answering "not found".
					wp_safe_redirect( self::url(), 301, 'WordPress' );
					exit;
				}
				$path = Store::path( $served['gen'], $route[1] );
				if ( ! is_readable( $path ) ) {
					self::ensure_built();
					self::not_found();
				}
				self::send_file( $path, $served['gen'] . $route[1] );
				exit;
		}
	}

	/**
	 * Answer the index (and end the request).
	 *
	 * @return void
	 */
	public static function serve_index(): void {
		$m = Store::manifest();
		if ( empty( $m['complete'] ) ) {
			self::ensure_built();
		}
		$served = Store::served( $m );
		$rows   = self::index_rows( $served['files'] );
		$xml    = Writer::index( $rows );
		// While the first build runs the index may list nothing: no cache may keep that empty
		// answer (zs-762).
		self::send( $xml, 'application/xml', md5( $xml ), (int) $m['refreshed'], ! empty( $m['complete'] ) && array() !== $rows );
		exit;
	}

	/**
	 * The index's rows, in unit order, with each file's newest page as its date.
	 *
	 * @param array<string, array<string, mixed>> $files Files.
	 * @return array<int, array{loc: string, lastmod: int}>
	 */
	public static function index_rows( array $files ): array {
		// Home first, then content types, taxonomies and authors (read from the names alone: this
		// runs before content types are registered).
		$rank = static function ( string $unit ): int {
			if ( 'home' === $unit ) {
				return 0;
			}
			if ( 0 === strpos( $unit, 'post:' ) ) {
				return 'post:page' === $unit ? 1 : ( 'post:post' === $unit ? 2 : 3 );
			}
			return 0 === strpos( $unit, 'tax:' ) ? 4 : 5;
		};
		uksort(
			$files,
			static function ( $a, $b ) use ( $files, $rank ): int {
				$fa = $files[ $a ];
				$fb = $files[ $b ];
				return array( $rank( (string) $fa['unit'] ), (string) $fa['unit'], (string) $fa['lang'], (int) $fa['n'] ) <=> array( $rank( (string) $fb['unit'] ), (string) $fb['unit'], (string) $fb['lang'], (int) $fb['n'] );
			}
		);
		$rows = array();
		foreach ( $files as $name => $f ) {
			if ( (int) $f['count'] < 1 ) {
				continue;
			}
			$rows[] = array(
				'loc'     => self::url( (string) $name ),
				'lastmod' => (int) $f['lastmod'],
			);
		}

		/**
		 * Filters the index's rows (the Pro news sitemap adds its own).
		 *
		 * @param array<int, array{loc: string, lastmod: int}> $rows Rows.
		 */
		return (array) apply_filters( 'zinn_seo_sitemap_index_rows', $rows );
	}

	/**
	 * Start the first build if none has run (or one is not already on its way).
	 *
	 * @return void
	 */
	public static function ensure_built(): void {
		$m = Store::manifest();
		if ( ! empty( $m['complete'] ) ) {
			return;
		}
		$job = Jobs::get( Builder::JOB );
		if ( ! in_array( (string) $job['status'], array( 'pending', 'paused' ), true ) ) {
			Builder::start( true );
		}
	}

	/**
	 * The headers every sitemap response carries.
	 *
	 * @param string $type     Media type.
	 * @param string $etag     Entity tag.
	 * @param int    $modified Last modified (0: unknown).
	 * @param bool   $settled  False while the sitemap is still being built: nothing may keep it.
	 * @return bool True when the client already has this version (304 sent).
	 */
	private static function headers( string $type, string $etag, int $modified, bool $settled = true ): bool {
		self::no_page_cache();
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=UTF-8' );
		// A sitemap is for crawlers: it should not itself appear in search results.
		header( 'X-Robots-Tag: noindex, follow' );
		header( $settled ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store, max-age=0' );
		header( 'ETag: "' . $etag . '"' );
		if ( $modified > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		}
		$match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) ), '"' ) : '';
		if ( '' !== $match && $match === $etag ) {
			status_header( 304 );
			return true;
		}

		return false;
	}

	/**
	 * Tell page-cache plugins (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache …) not
	 * to store this answer: the sitemap is already a file of ours, and a cached copy taken while
	 * it was being built stayed empty for days (zs-762). The short browser and CDN cache stays.
	 *
	 * @return void
	 */
	public static function no_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant every page-cache plugin reads.
		}
		do_action( 'litespeed_control_set_nocache', 'sitemap' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
	}

	/**
	 * Send a generated body.
	 *
	 * @param string $body     Body.
	 * @param string $type     Media type.
	 * @param string $etag     Entity tag.
	 * @param int    $modified Last modified.
	 * @param bool   $settled  False while the sitemap is still being built.
	 * @return void
	 */
	private static function send( string $body, string $type, string $etag, int $modified, bool $settled = true ): void {
		if ( self::headers( $type, $etag, $modified, $settled ) ) {
			return;
		}
		if ( 'application/xml' === $type ) {
			$body = self::with_stylesheet( $body );
		}
		header( 'Content-Length: ' . strlen( $body ) );
		if ( ! self::is_head() ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built by Writer, every value escaped there.
		}
	}

	/**
	 * Send a stored file, adding the stylesheet line after its XML declaration.
	 *
	 * @param string $path File.
	 * @param string $id   Stable id for the entity tag.
	 * @return void
	 */
	private static function send_file( string $path, string $id ): void {
		$mtime = (int) filemtime( $path );
		$size  = (int) filesize( $path );
		if ( self::headers( 'application/xml', md5( $id . '|' . $mtime . '|' . $size . '|' . (int) self::stylesheet_on() ), $mtime ) ) {
			return;
		}
		$pi = self::stylesheet_line();
		header( 'Content-Length: ' . ( $size + strlen( $pi ) ) );
		if ( self::is_head() ) {
			return;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams our own file without reading it into memory.
		if ( false === $handle ) {
			return;
		}
		$first = (string) fgets( $handle );
		echo $first . $pi; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the stored XML declaration and our own fixed stylesheet line.
		fpassthru( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
	}

	/**
	 * Add the stylesheet line to a generated document.
	 *
	 * @param string $xml XML.
	 * @return string
	 */
	private static function with_stylesheet( string $xml ): string {
		$pi = self::stylesheet_line();
		if ( '' === $pi ) {
			return $xml;
		}
		$at = strpos( $xml, "\n" );

		return false === $at ? $xml : substr( $xml, 0, $at + 1 ) . $pi . substr( $xml, $at + 1 );
	}

	/**
	 * Is the human view on (and allowed: never in no-trace mode)?
	 *
	 * @return bool
	 */
	public static function stylesheet_on(): bool {
		return (bool) Settings::get( 'sitemap_xsl', true ) && ! Notrace::enabled();
	}

	/**
	 * The processing instruction that shows the human view in a browser, or ''.
	 *
	 * @return string
	 */
	private static function stylesheet_line(): string {
		if ( ! self::stylesheet_on() ) {
			return '';
		}
		$href = (string) wp_parse_url( self::root( '/sitemap.xsl' ), PHP_URL_PATH );
		$css  = (string) wp_parse_url( self::root( '/sitemap-view.css' ), PHP_URL_PATH );

		// XSLT first, the preferred view; a browser that no longer applies XSLT (Chrome is
		// removing it) styles the XML itself with the CSS one, so the file stays readable (zs-762).
		return '<?xml-stylesheet type="text/xsl" href="' . esc_attr( $href ) . '"?>' . "\n"
			. '<?xml-stylesheet type="text/css" href="' . esc_attr( $css ) . '"?>' . "\n";
	}

	/**
	 * Is this a HEAD request?
	 *
	 * @return bool
	 */
	private static function is_head(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Answer 404 and stop.
	 *
	 * @return void
	 */
	private static function not_found(): void {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		exit;
	}

	// ── robots.txt and no-trace ─────────────────────────────────────────────────────────────

	/**
	 * The `Sitemap:` addresses robots.txt names.
	 *
	 * @param array<int, string> $lines Addresses so far.
	 * @return array<int, string>
	 */
	public static function robots_lines( $lines ): array {
		$lines   = (array) $lines;
		$lines[] = self::url();

		return $lines;
	}

	/**
	 * What a no-trace switch changes here (shown before the switch).
	 *
	 * @param array<int, array<string, mixed>> $changes Changes so far.
	 * @param bool                             $on      The mode after the switch.
	 * @return array<int, array<string, mixed>>
	 */
	public static function notrace_changes( $changes, $on ): array {
		$changes   = (array) $changes;
		$normal    = home_url( '/' . self::index_name() );
		$hidden    = home_url( '/wp-sitemap.xml' );
		$changes[] = array(
			'what'     => __( 'Sitemap address', 'zinn-seo' ),
			'from'     => (bool) $on ? $normal : $hidden,
			'to'       => (bool) $on ? $hidden : $normal,
			'resubmit' => true,
		);
		if ( (bool) Settings::get( 'sitemap_xsl', true ) ) {
			$changes[] = array(
				'what'     => __( 'Sitemap stylesheet (the readable view in a browser)', 'zinn-seo' ),
				'from'     => (bool) $on ? __( 'shown', 'zinn-seo' ) : __( 'none', 'zinn-seo' ),
				'to'       => (bool) $on ? __( 'none', 'zinn-seo' ) : __( 'shown', 'zinn-seo' ),
				'resubmit' => false,
			);
		}

		return $changes;
	}

	/**
	 * After a no-trace switch: the old addresses already redirect (route()); search engines are
	 * told through `zinn_seo_sitemap_changed` (Search Console resubmits there) and IndexNow.
	 *
	 * @param bool                             $on      The mode now.
	 * @param array<int, array<string, mixed>> $changes What changed.
	 * @return void
	 */
	public static function notrace_changed( $on, $changes ): void {
		unset( $on, $changes );
		do_action( 'zinn_seo_sitemap_changed', Store::manifest() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in Builder::finish().
	}

	// ── What changed ────────────────────────────────────────────────────────────────────────

	/**
	 * The mark of a post: unit|lang|date|ID.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function post_mark( \WP_Post $post ): string {
		$lang = '';
		if ( Builder::languages_on() && class_exists( '\ZinnDigital\Tranzly\Core\Relations' ) ) {
			$lang = (string) \ZinnDigital\Tranzly\Core\Relations::language_of( 'post', (int) $post->ID );
			if ( Builder::default_language() === $lang ) {
				$lang = '';
			}
		}

		return 'post:' . $post->post_type . '|' . $lang . '|' . $post->post_date . '|' . (int) $post->ID;
	}

	/**
	 * Queue a mark for shutdown.
	 *
	 * @param string $mark Mark.
	 * @return void
	 */
	public static function mark( string $mark ): void {
		self::$marks[] = $mark;
	}

	/**
	 * A post changed status: a page that appears or disappears.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ( 'publish' !== $new_status && 'publish' !== $old_status ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		self::mark( self::post_mark( $post ) );
		self::mark( 'home' );
	}

	/**
	 * A published post was saved: its file (and, when its date moved, its old file too).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after.
	 * @param \WP_Post $before  Post before.
	 * @return void
	 */
	public static function on_post_updated( $post_id, $after, $before ): void {
		unset( $post_id );
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || ( 'publish' !== $after->post_status && 'publish' !== $before->post_status ) ) {
			return;
		}
		self::mark( self::post_mark( $after ) );
		if ( $before->post_date !== $after->post_date || $before->post_type !== $after->post_type ) {
			self::mark( self::post_mark( $before ) );
		}
	}

	/**
	 * A post is about to be deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			self::mark( self::post_mark( $post ) );
		}
	}

	/**
	 * A term was created or edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		unset( $tt_id );
		if ( '' === (string) $taxonomy ) {
			return;
		}
		self::mark( 'tax:' . $taxonomy . '|||' . (int) $term_id );
	}

	/**
	 * A term is about to be deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term_delete( $term_id, $taxonomy ): void {
		self::on_term( $term_id, 0, (string) $taxonomy );
	}

	/**
	 * A term's post count changed (a term with no posts is not listed; its first post adds it).
	 *
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term_count( $tt_id, $taxonomy ): void {
		$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, (string) $taxonomy );
		if ( $term instanceof \WP_Term ) {
			self::on_term( (int) $term->term_id, (int) $tt_id, (string) $taxonomy );
		}
	}

	/**
	 * An SEO field changed (noindex or canonical can take a page out of the sitemap).
	 *
	 * @param string $kind `post` or `term`.
	 * @param int    $id   ID.
	 * @param string $name Field.
	 * @return void
	 */
	public static function on_field( $kind, $id, $name ): void {
		if ( ! in_array( (string) $name, array( 'robots', 'canonical', 'redirect' ), true ) ) {
			return;
		}
		if ( 'user' === $kind ) {
			// An author hidden, moved or redirected leaves the authors sitemap (zs-756); a user's
			// id is never read as a term's.
			self::mark( 'authors|||' . (int) $id );
			return;
		}
		if ( 'post' === $kind ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				self::mark( self::post_mark( $post ) );
			}
			return;
		}
		$term = get_term( (int) $id );
		if ( $term instanceof \WP_Term ) {
			self::on_term( (int) $term->term_id, 0, (string) $term->taxonomy );
		}
	}

	/**
	 * Settings that change what the sitemap lists rebuild it.
	 *
	 * @param array<string, mixed> $after  Settings now.
	 * @param array<string, mixed> $before Settings before.
	 * @return void
	 */
	public static function on_settings( $after, $before ): void {
		$after  = (array) $after;
		$before = (array) $before;
		$mods_a = (array) ( $after['modules'] ?? array() );
		$mods_b = (array) ( $before['modules'] ?? array() );
		if ( array_key_exists( 'sitemaps', $mods_a ) && false === $mods_a['sitemaps'] && ( $mods_b['sitemaps'] ?? true ) ) {
			// Switched off: forget the files, so switching it on again builds afresh.
			$m = Store::manifest();
			Store::remove_gen( (string) $m['gen'] );
			delete_option( Store::MANIFEST );
			Store::forget();
			return;
		}
		$after['modules']  = array_intersect_key( $mods_a, array_flip( array( 'sitemaps-pro' ) ) );
		$before['modules'] = array_intersect_key( $mods_b, array_flip( array( 'sitemaps-pro' ) ) );
		foreach ( array_unique( array_merge( array_keys( $after ), array_keys( $before ) ) ) as $key ) {
			$key = (string) $key;
			// Every `sitemap_*` setting (the Pro ones too) except those read at send time, plus
			// the templates (a type set to noindex) and the module switches.
			$watched = ( 0 === strpos( $key, 'sitemap_' ) && ! in_array( $key, array( 'sitemap_xsl', 'sitemap_news', 'sitemap_news_types', 'sitemap_news_name', 'sitemap_network' ), true ) ) || in_array( $key, array( 'templates', 'modules' ), true );
			if ( $watched && ( $after[ $key ] ?? null ) !== ( $before[ $key ] ?? null ) ) {
				self::mark_all();
				return;
			}
		}
	}

	/**
	 * Rebuild everything.
	 *
	 * @return void
	 */
	public static function mark_all(): void {
		self::mark( '*' );
	}

	/**
	 * Rebuild the home file.
	 *
	 * @return void
	 */
	public static function mark_home(): void {
		self::mark( 'home' );
	}

	/**
	 * Store this request's marks and start the job (one write and one kick per request).
	 *
	 * @return void
	 */
	public static function flush_marks(): void {
		if ( array() === self::$marks ) {
			return;
		}
		$marks       = array_values( array_unique( self::$marks ) );
		self::$marks = array();
		Store::mark( $marks );
		Builder::start( in_array( '*', $marks, true ) );
	}
}
