<?php
/**
 * Conflict detector and theme title fix (zs-703).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Conflicts;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two titles, two descriptions, two sets of Open Graph tags or two structured-data blocks on one
 * page leave search engines and social sites guessing which one is right. The detector reads three
 * of the site's own pages exactly as a visitor gets them (home, newest post, a category), counts
 * every SEO tag, and names who printed the extra one where it can tell (a plugin's own marker).
 * It also finds a robots.txt or sitemap file sitting in the site's folder, which the web server
 * serves instead of the real one.
 *
 * Fixes are one click and undoable: Jetpack's Open Graph tags off; a theme that writes its own
 * `<title>` corrected (its title is replaced by the right one as the page is sent); a stray file
 * renamed out of the way (kept, with the date in its name). The findings go to the Fix-it inbox.
 */
final class Conflicts {

	/** Non-autoloaded option with the last reading. */
	public const OPTION = 'zseo_conflicts';

	/** One-off cron event that re-reads the pages after a theme or plugin change. */
	public const RESCAN = 'zseo_conflicts_rescan';

	/**
	 * Known markers of other software in a duplicate tag: needle => name (brands, not translated).
	 *
	 * @var array<string, string>
	 */
	public const MARKERS = array(
		'yoast-schema-graph' => 'Yoast SEO',
		'rank-math-schema'   => 'Rank Math',
		'aioseo-schema'      => 'All in One SEO',
		'seopress'           => 'SEOPress',
		'Jetpack Open Graph' => 'Jetpack',
		'jetpack'            => 'Jetpack',
		'wpsso'              => 'WPSSO',
		'social-warfare'     => 'Social Warfare',
		'webSiteSchema'      => 'Your theme',
		'schema-pro'         => 'Schema Pro',
		'saswp'              => 'Schema & Structured Data for WP',
		'wp-schema-pro'      => 'Schema Pro',
		'bsf-schema'         => 'All In One Schema Rich Snippets',
		'og-tags'            => 'Open Graph plugin',
		'wonderm00n'         => 'Open Graph for Facebook, Google+ and Twitter Card Tags',
	);

	/**
	 * Themes whose own microdata (`itemscope`/`itemtype` in the page's markup) a one-click fix
	 * switches off through the theme's OWN documented switch: slug => [name, how].
	 * Nothing visible changes: only attributes go.
	 *
	 * ⛔ Each "how" was read in the theme's source (2026-10-15): Botiga 2.5 `botiga_schema_{place}`
	 * (inc/modules/schema-markup), Astra 4.14 `astra_schema_enabled` (inc/schema), GeneratePress
	 * 3.6 `generate_schema_type` (inc/theme-functions.php), Kadence 1.5 `kadence_microdata`
	 * (inc/components/microdata), OceanWP 4.2 theme setting `ocean_schema_markup`
	 * (inc/helpers.php), read through WordPress's own `theme_mod_*` filter so nothing is stored,
	 * and its breadcrumb trail's `schema` argument (`oceanwp_breadcrumb_trail_args`). A trail
	 * keeps its marks while Zinn® SEO prints no breadcrumb data of its own.
	 *
	 * @var array<string, string>
	 */
	public const THEME_MICRODATA = array(
		'botiga'        => 'Botiga',
		'astra'         => 'Astra',
		'generatepress' => 'GeneratePress',
		'kadence'       => 'Kadence',
		'oceanwp'       => 'OceanWP',
	);

	/** Botiga's places for its schema attributes (each has a `botiga_schema_{place}` filter). */
	private const BOTIGA_PLACES = array( 'html', 'header', 'article', 'blog', 'search', 'logo', 'nav', 'sidebar', 'footer', 'thumbnail', 'headline', 'entry_content', 'published_date', 'modified_date', 'author', 'author_name', 'author_url', 'image' );

	/**
	 * Other SEO plugins still printing their structured data beside ours: fix id => [name,
	 * marker in the page, their own documented switch]. Read in each plugin's code.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const PLUGIN_JSONLD = array(
		'yoast'    => array( 'Yoast SEO', 'yoast-schema-graph' ),
		'rankmath' => array( 'Rank Math', 'rank-math-schema' ),
		'aioseo'   => array( 'All in One SEO', 'aioseo-schema' ),
	);

	/**
	 * Hooks.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		$fixes = (array) Settings::get( 'conflict_fixes', array() );
		if ( in_array( 'jetpack-og', $fixes, true ) ) {
			add_filter( 'jetpack_enable_open_graph', '__return_false', 99 );
		}
		if ( 'front' === $context && in_array( 'theme-title', $fixes, true ) && ! current_theme_supports( 'title-tag' ) ) {
			add_action( 'get_header', array( self::class, 'start_title_buffer' ), 0 );
			add_action( 'wp_head', array( self::class, 'end_title_buffer' ), PHP_INT_MAX );
		}
		if ( 'front' === $context ) {
			self::apply_fixes( $fixes );
		}
		if ( 'front' !== $context ) {
			add_action( 'switch_theme', array( self::class, 'schedule_rescan' ) );
			add_action( 'activated_plugin', array( self::class, 'schedule_rescan' ) );
			add_action( 'deactivated_plugin', array( self::class, 'schedule_rescan' ) );
			add_action( self::RESCAN, array( self::class, 'scan' ) );
			add_filter( 'zinn_seo_fixit_sources', array( self::class, 'fixit_source' ) );
			add_action( 'zinn_seo_fixit_actions', array( self::class, 'fixit_actions' ) );
		}
	}

	// ── The one-click fixes for structured data ─────────────────────────────────────────────

	/**
	 * The structured-data fixes a person switched on, applied as the page is made. Each uses the
	 * source's own switch, so undoing it is taking the id out of the list.
	 *
	 * @param array<int, string> $fixes Fix ids switched on.
	 * @return void
	 */
	public static function apply_fixes( array $fixes ): void {
		foreach ( $fixes as $fix ) {
			foreach ( self::switches( (string) $fix, (bool) Settings::get( 'schema_breadcrumbs', true ) ) as $hook ) {
				add_filter( $hook[0], $hook[1], $hook[2] );
			}
		}
	}

	/**
	 * The filters one fix adds: each source's own documented switch. Pure, for the tests.
	 *
	 * @param string $fix         Fix id.
	 * @param bool   $breadcrumbs Whether Zinn® SEO prints a breadcrumb trail in its structured data.
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	public static function switches( string $fix, bool $breadcrumbs ): array {
		$off  = static fn(): bool => false;
		$out  = array();
		$spec = array(
			'microdata:astra'         => array( array( 'astra_schema_enabled', $off, 99 ) ),
			'microdata:generatepress' => array( array( 'generate_schema_type', static fn(): string => 'none', 99 ) ),
			'microdata:kadence'       => array( array( 'kadence_microdata', $off, 99 ) ),
			'microdata:oceanwp'       => array_merge(
				array( array( 'theme_mod_ocean_schema_markup', $off, 99 ) ),
				// Its breadcrumb trail has a switch of its own (inc/breadcrumbs.php `schema`).
				$breadcrumbs ? array( array( 'oceanwp_breadcrumb_trail_args', static fn( $args ): array => array( 'schema' => false ) + (array) $args, 99 ) ) : array()
			),
			'jsonld:yoast'            => array( array( 'wpseo_json_ld_output', $off, 99 ) ),
			'jsonld:rankmath'         => array( array( 'rank_math/json_ld', static fn(): array => array(), PHP_INT_MAX ) ),
			'jsonld:aioseo'           => array( array( 'aioseo_schema_disable', static fn(): bool => true, 99 ) ),
			'join-entities'           => array( array( 'template_redirect', array( self::class, 'start_join_buffer' ), PHP_INT_MAX ) ),
		);
		if ( 'microdata:botiga' === $fix ) {
			foreach ( self::BOTIGA_PLACES as $place ) {
				$out[] = array( 'botiga_schema_' . $place, static fn(): string => '', 99 );
			}
			// Its breadcrumb trail keeps its marks while it is the page's only trail.
			if ( $breadcrumbs ) {
				$out[] = array( 'botiga_breadcrumb_trail', array( self::class, 'strip_microdata' ), 99 );
			}
			return $out;
		}

		return $spec[ $fix ] ?? array();
	}

	/**
	 * Is a fix one the screen previews before switching it on (the structured-data fixes)? Pure.
	 *
	 * @param string $id Fix id.
	 * @return bool
	 */
	public static function previewable( string $id ): bool {
		return 'join-entities' === $id || 0 === strpos( $id, 'microdata:' ) || 0 === strpos( $id, 'jsonld:' );
	}

	/**
	 * Every fix id this module knows (for the REST pattern and the checks).
	 *
	 * @return array<int, string>
	 */
	public static function fix_ids(): array {
		$out = array( 'jetpack-og', 'theme-title', 'jetpack-sitemaps', 'join-entities' );
		foreach ( array_keys( self::THEME_MICRODATA ) as $slug ) {
			$out[] = 'microdata:' . $slug;
		}
		foreach ( array_keys( self::PLUGIN_JSONLD ) as $slug ) {
			$out[] = 'jsonld:' . $slug;
		}

		return $out;
	}

	/**
	 * Remove the microdata marks (`itemscope`, `itemtype`, `itemprop` and the `<meta itemprop>`
	 * tags they carry) from some markup; the text and the elements stay. Pure.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public static function strip_microdata( $html ): string {
		$html = (string) preg_replace( '#<meta\s[^>]*\b(itemprop|name=["\'](numberOfItems|itemListOrder)["\'])[^>]*>#i', '', (string) $html );

		return (string) preg_replace( '#\s(itemscope(=(["\'])[^"\']*\3)?|itemtype=(["\'])[^"\']*\4|itemprop=(["\'])[^"\']*\5)(?=[\s/>])#i', '', $html );
	}

	/**
	 * Start holding the page so blocks that repeat the site's own entities join ours.
	 *
	 * @return void
	 */
	public static function start_join_buffer(): void {
		if ( is_feed() || is_robots() || wp_doing_ajax() ) {
			return;
		}
		ob_start( static fn( string $html ): string => self::join_entities( $html, (string) home_url( '/' ) ) );
	}

	/**
	 * Give the site's own entities in other structured data blocks the `@id` of ours, so search
	 * engines read one site and one organisation instead of several. Only a WebSite or an
	 * Organization with NO `@id` whose address is on this site joins; nothing visible changes.
	 *
	 * ⛔ zinnhub.com, 2026-10-15: hand-written blocks on the home page said "Zinn Hub" four more
	 * times (a second WebSite with its search box, three Organizations with ratings and social
	 * profiles), none with an `@id`: read as four other entities.
	 *
	 * @param string $html The page.
	 * @param string $home The site's home address.
	 * @return string
	 */
	public static function join_entities( string $html, string $home ): string {
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		if ( '' === $host || false === stripos( $html, 'application/ld+json' ) ) {
			return $html;
		}
		$base = trailingslashit( $home );

		return (string) preg_replace_callback(
			'#(<script[^>]+application/ld\+json[^>]*>)(.*?)(</script>)#is',
			static function ( array $m ) use ( $host, $base ): string {
				$data = json_decode( trim( $m[2] ), true );
				if ( ! is_array( $data ) ) {
					return $m[0];
				}
				$changed = false;
				$join    = static function ( array $node ) use ( $host, $base, &$changed ): array {
					if ( isset( $node['@id'] ) ) {
						return $node;
					}
					$kind = '';
					foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
						$kind = in_array( self::entity_kind( is_string( $type ) ? $type : '' ), array( 'website', 'organization' ), true ) ? self::entity_kind( (string) $type ) : $kind;
					}
					$url = is_string( $node['url'] ?? null ) ? strtolower( (string) wp_parse_url( (string) $node['url'], PHP_URL_HOST ) ) : '';
					if ( '' === $kind || ! in_array( $host, array( $url, 'www.' . $url, (string) preg_replace( '/^www\./', '', $url ) ), true ) ) {
						return $node;
					}
					$changed = true;

					return array( '@id' => $base . '#' . $kind ) + $node;
				};
				if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
					$data['@graph'] = array_map( static fn( $n ) => is_array( $n ) ? $join( $n ) : $n, $data['@graph'] );
				} elseif ( self::is_list( $data ) ) {
					$data = array_map( static fn( $n ) => is_array( $n ) ? $join( $n ) : $n, $data );
				} else {
					$data = $join( $data );
				}

				return $changed ? $m[1] . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . $m[3] : $m[0];
			},
			$html
		);
	}

	/**
	 * What a fix would change on the pages the check reads, worked out on copies of them before
	 * anything is switched on: each page's problems now and after, and whether the text a
	 * visitor reads stays the same.
	 *
	 * @param string $id Fix id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function preview( string $id ) {
		if ( ! in_array( $id, self::fix_ids(), true ) ) {
			return new \WP_Error( 'zinn_seo_unknown_fix', __( 'There is no such fix.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$out  = array();
		$home = (string) home_url( '/' );
		$bc   = (bool) Settings::get( 'schema_breadcrumbs', true );
		foreach ( self::pages() as $label => $url ) {
			$html = self::read( $url );
			if ( null === $html ) {
				$out[ $label ] = array( 'read' => false );
				continue;
			}
			$after         = self::simulate( $id, $html, $home );
			$out[ $label ] = array(
				'read'         => true,
				'before'       => self::problems( self::count_tags( $html, $bc, $home ) ),
				'after'        => self::problems( self::count_tags( $after, $bc, $home ) ),
				'text_same'    => self::visible_text( $html ) === self::visible_text( $after ),
				'marks_before' => self::marks( $html ),
				'marks_after'  => self::marks( $after ),
			);
		}

		return array(
			'id'    => $id,
			'pages' => $out,
		);
	}

	/**
	 * One page as a visitor gets it (null when it cannot be read).
	 *
	 * @param string $url Address.
	 * @return string|null
	 */
	public static function read( string $url ): ?string {
		$response = wp_remote_get(
			add_query_arg( 'zseo_read', (string) time(), $url ),
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own loopback filter.
			)
		);

		return is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * A page as it would read with one fix on. Pure.
	 *
	 * @param string $id   Fix id.
	 * @param string $html The page now.
	 * @param string $home The site's home address.
	 * @return string
	 */
	public static function simulate( string $id, string $html, string $home ): string {
		if ( 'join-entities' === $id ) {
			return self::join_entities( $html, $home );
		}
		if ( 0 === strpos( $id, 'microdata:' ) ) {
			return self::strip_microdata( $html );
		}
		if ( 0 === strpos( $id, 'jsonld:' ) && isset( self::PLUGIN_JSONLD[ substr( $id, 7 ) ] ) ) {
			$marker = self::PLUGIN_JSONLD[ substr( $id, 7 ) ][1];
			return (string) preg_replace( '#<script[^>]*' . preg_quote( $marker, '#' ) . '[^>]*>.*?</script>#is', '', $html );
		}

		return $html;
	}

	/**
	 * The words a visitor reads on a page (scripts, styles and tags left out). Pure.
	 *
	 * @param string $html The page.
	 * @return string
	 */
	public static function visible_text( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );

		return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * How many microdata marks and structured data blocks a page carries. Pure.
	 *
	 * @param string $html The page.
	 * @return array{microdata: int, jsonld: int}
	 */
	public static function marks( string $html ): array {
		return array(
			'microdata' => (int) preg_match_all( '#\sitemtype=#i', $html ),
			'jsonld'    => (int) preg_match_all( '#application/ld\+json#i', $html ),
		);
	}

	// ── The title fix ───────────────────────────────────────────────────────────────────────

	/**
	 * Start holding the page from the theme's header (where an old theme writes its `<title>`).
	 *
	 * @return void
	 */
	public static function start_title_buffer(): void {
		if ( ! did_action( 'wp_head' ) ) {
			ob_start();
		}
	}

	/**
	 * At the end of the head: one title, the right one.
	 *
	 * @return void
	 */
	public static function end_title_buffer(): void {
		if ( ! did_action( 'get_header' ) || ob_get_level() < 1 ) {
			return;
		}
		$head = (string) ob_get_clean();
		echo self::one_title( $head, wp_get_document_title() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's own head, with a title escaped in one_title().
	}

	/**
	 * The head with every `<title>` removed and the right one put where the first was.
	 *
	 * @param string $head  The head's HTML.
	 * @param string $title The right title (plain or with entities).
	 * @return string
	 */
	public static function one_title( string $head, string $title ): string {
		$tag   = '<title>' . esc_html( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) . '</title>';
		$count = 0;
		$out   = (string) preg_replace_callback(
			'#<title\b[^>]*>.*?</title>#is',
			static function () use ( &$count, $tag ): string {
				++$count;
				return 1 === $count ? $tag : '';
			},
			$head
		);

		return 0 === $count ? $tag . "\n" . $out : $out;
	}

	// ── Reading the pages ───────────────────────────────────────────────────────────────────

	/**
	 * Count the SEO tags of one page's head.
	 *
	 * @param string $html        The page.
	 * @param bool   $breadcrumbs Whether we print a breadcrumb trail in the structured data.
	 * @param string $home        The site's home address (to tell its own entities in other blocks).
	 * @return array<string, mixed>
	 */
	public static function count_tags( string $html, bool $breadcrumbs = true, string $home = '' ): array {
		$end            = stripos( $html, '</head>' );
		$head           = false === $end ? $html : substr( $html, 0, $end );
		$body           = false === $end ? '' : substr( $html, $end );
		$out            = array(
			'title'       => preg_match_all( '#<title\b#i', $head ),
			'description' => preg_match_all( '#<meta[^>]+name=["\']description["\']#i', $head ),
			'canonical'   => preg_match_all( '#<link[^>]+rel=["\']canonical["\']#i', $head ),
			'robots'      => preg_match_all( '#<meta[^>]+name=["\']robots["\']#i', $head ),
			'og_title'    => preg_match_all( '#<meta[^>]+property=["\']og:title["\']#i', $head ),
			'twitter'     => preg_match_all( '#<meta[^>]+name=["\']twitter:card["\']#i', $head ),
			'jsonld'      => self::jsonld_count( $head . $body ),
			// A trail in the theme's own markup repeats ours only when we print one: with the
			// breadcrumb structured data off (zinnhub.com, after a move from Rank Math with its
			// breadcrumbs off), the theme's is the page's only trail, not a duplicate.
			'microdata'   => preg_match_all( '#itemtype=["\']https?://schema\.org/(WebSite|Organization|WebPage|Article|BlogPosting' . ( $breadcrumbs ? '|BreadcrumbList' : '' ) . ')["\']#i', $html ),
		);
		$out['markers'] = self::markers( $html );
		// How many of the repeats are this site's own WebSite or Organization said again with no
		// `@id`: the ones the "join" fix can merge into ours.
		$out['joinable'] = '' !== $home && $out['jsonld'] > 1 ? max( 0, $out['jsonld'] - self::jsonld_count( self::join_entities( $head . $body, $home ) ) ) : 0;
		// Whether Zinn® SEO's own graph is on the page (its WebSite under the site's @id): another
		// plugin's structured data is only switched off where ours carries the page.
		$out['ours'] = '' !== $home && self::has_our_graph( $head . $body, $home );

		return $out;
	}

	/**
	 * Is an array a list (keys 0..n-1)? ⛔ Not array_is_list(): PHP 8.1+ only, and the plugin runs
	 * on PHP 7.4 with WordPress 6.4, whose polyfill came in 6.5 — Plugin Check refused the
	 * 0.17.19 release for it, and on 7.4/6.4 it was a fatal error (SAFE1, 2026-10-15). Pure.
	 *
	 * @param array<mixed> $a Array.
	 * @return bool
	 */
	public static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * The structured data on a page, counted as entities that say the same thing twice: 1 when
	 * every site-level entity (the site, the organisation, the page, its trail, the article or
	 * product) is said once, 1 + each repeat otherwise; 0 with no structured data at all.
	 *
	 * ⛔ WHY NOT COUNT THE BLOCKS. A page may carry several blocks that describe DIFFERENT things
	 * (a service, an event, a list of links): that is correct, and Google reads them all. Counting
	 * blocks told zinnhub.com (2026-10-15) its home page had 13 duplicates; 10 were other things,
	 * and the 3 real repeats (a second site and two more organisations, with no `@id`) were lost
	 * in the noise. Two nodes with the same `@id` are one entity said in two places, which is
	 * how a theme or a hand-written block joins the plugin's graph: not a repeat. A block that
	 * cannot be read counts as a repeat beside another block (another SEO plugin's empty or
	 * broken graph).
	 *
	 * @param string $html The page.
	 * @return int
	 */
	public static function jsonld_count( string $html ): int {
		if ( ! preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m ) ) {
			return 0;
		}
		$repeats = 0;
		$unread  = 0;
		$seen    = array();
		foreach ( $m[1] as $json ) {
			$data = json_decode( trim( (string) $json ), true );
			if ( ! is_array( $data ) || array() === $data ) {
				++$unread;
				continue;
			}
			$nodes = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( self::is_list( $data ) ? $data : array( $data ) );
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$id = is_string( $node['@id'] ?? null ) ? rtrim( (string) $node['@id'], '/' ) : '';
				foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
					$kind = self::entity_kind( is_string( $type ) ? $type : '' );
					if ( '' === $kind ) {
						continue;
					}
					if ( ! isset( $seen[ $kind ] ) ) {
						$seen[ $kind ] = array( $id );
					} elseif ( '' === $id || ! in_array( $id, $seen[ $kind ], true ) ) {
						$seen[ $kind ][] = $id;
						++$repeats;
					}
					break;
				}
			}
		}

		// A block that cannot be read is a repeat beside any other block (the page's only block
		// is the page's structured data, whatever it holds).
		return 1 + $repeats + ( array() === $seen && $unread > 0 && count( $m[1] ) === $unread ? $unread - 1 : $unread );
	}

	/**
	 * Does a page carry Zinn® SEO's graph (a WebSite under `<home>#website` in a block no other
	 * SEO plugin marked)? Pure.
	 *
	 * @param string $html The page.
	 * @param string $home The site's home address.
	 * @return bool
	 */
	public static function has_our_graph( string $html, string $home ): bool {
		$want = rtrim( trailingslashit( $home ) . '#website', '/' );
		if ( ! preg_match_all( '#<script([^>]+application/ld\+json[^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $m as $block ) {
			if ( array() !== self::markers( $block[1] ) ) {
				continue;
			}
			$data = json_decode( trim( $block[2] ), true );
			foreach ( (array) ( is_array( $data ) && isset( $data['@graph'] ) ? $data['@graph'] : array( $data ) ) as $node ) {
				if ( is_array( $node ) && is_string( $node['@id'] ?? null ) && rtrim( (string) $node['@id'], '/' ) === $want ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The site-level entity a schema type stands for ('' for any other type).
	 *
	 * @param string $type A schema.org type.
	 * @return string
	 */
	public static function entity_kind( string $type ): string {
		$kinds = array(
			'website'      => array( 'WebSite' ),
			'organization' => array( 'Organization', 'Corporation', 'OnlineBusiness', 'OnlineStore', 'NGO', 'EducationalOrganization' ),
			'webpage'      => array( 'WebPage', 'CollectionPage', 'ItemPage', 'ProfilePage', 'AboutPage', 'ContactPage', 'SearchResultsPage', 'CheckoutPage', 'MedicalWebPage', 'RealEstateListing' ),
			'breadcrumb'   => array( 'BreadcrumbList' ),
			'article'      => array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report', 'ScholarlyArticle' ),
			'product'      => array( 'Product', 'ProductGroup' ),
		);
		foreach ( $kinds as $kind => $types ) {
			if ( in_array( $type, $types, true ) ) {
				return $kind;
			}
		}

		return '';
	}

	/**
	 * Names of the software whose markers the page carries.
	 *
	 * @param string $html The page.
	 * @return array<int, string>
	 */
	public static function markers( string $html ): array {
		$found = array();
		foreach ( self::MARKERS as $needle => $name ) {
			if ( false !== stripos( $html, $needle ) ) {
				$found[] = $name;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Problems in one page's counts: tag => how many extra.
	 *
	 * @param array<string, mixed> $counts From count_tags().
	 * @return array<string, int>
	 */
	public static function problems( array $counts ): array {
		$out = array();
		foreach ( array( 'title', 'description', 'canonical', 'robots', 'og_title', 'twitter', 'jsonld' ) as $tag ) {
			if ( (int) ( $counts[ $tag ] ?? 0 ) > 1 ) {
				$out[ $tag ] = (int) $counts[ $tag ] - 1;
			}
		}
		if ( (int) ( $counts['microdata'] ?? 0 ) > 0 ) {
			$out['microdata'] = (int) $counts['microdata'];
		}

		return $out;
	}

	/**
	 * The pages read: home, newest post, a category with posts.
	 *
	 * @return array<string, string> Label => address.
	 */
	public static function pages(): array {
		$pages = array( 'home' => home_url( '/' ) );
		$post  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'has_password'   => false,
				'fields'         => 'ids',
			)
		);
		if ( isset( $post[0] ) ) {
			$pages['post'] = (string) get_permalink( (int) $post[0] );
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_array( $cats ) && isset( $cats[0] ) && $cats[0] instanceof \WP_Term ) {
			$link = get_term_link( $cats[0] );
			if ( ! is_wp_error( $link ) ) {
				$pages['category'] = (string) $link;
			}
		}

		return $pages;
	}

	/**
	 * Read the pages and the site folder, store and return the findings.
	 *
	 * @return array<string, mixed>
	 */
	public static function scan(): array {
		$pages = array();
		foreach ( self::pages() as $label => $url ) {
			$response = wp_remote_get(
				add_query_arg( 'zseo_read', (string) time(), $url ),
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own loopback filter.
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$pages[ $label ] = array(
					'url'  => $url,
					'read' => false,
				);
				continue;
			}
			$counts          = self::count_tags( (string) wp_remote_retrieve_body( $response ), (bool) Settings::get( 'schema_breadcrumbs', true ), (string) home_url( '/' ) );
			$pages[ $label ] = array(
				'url'      => $url,
				'read'     => true,
				'counts'   => $counts,
				'problems' => self::problems( $counts ),
			);
		}
		$result           = array(
			'at'       => time(),
			'pages'    => $pages,
			'files'    => self::stray_files(),
			'software' => self::software(),
			'theme'    => array(
				'name'      => (string) wp_get_theme()->get( 'Name' ),
				'template'  => (string) get_template(),
				'title_tag' => current_theme_supports( 'title-tag' ),
			),
		);
		$result['issues'] = self::issues( $result );
		update_option( self::OPTION, $result, false );

		return $result;
	}

	/**
	 * Files in the site folder that the web server serves instead of the real ones.
	 *
	 * @return array<int, string>
	 */
	public static function stray_files(): array {
		$out = array();
		foreach ( array( 'robots.txt', 'sitemap.xml', 'sitemap_index.xml', 'sitemap.xml.gz' ) as $name ) {
			if ( is_file( ABSPATH . $name ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Other active software known to print SEO tags or sitemaps.
	 *
	 * @return array<string, bool>
	 */
	public static function software(): array {
		$jetpack = class_exists( '\Jetpack' ) && method_exists( '\Jetpack', 'is_module_active' );

		return array(
			'jetpack_og'       => $jetpack && ( \Jetpack::is_module_active( 'publicize' ) || \Jetpack::is_module_active( 'sharedaddy' ) ),
			'jetpack_sitemaps' => $jetpack && \Jetpack::is_module_active( 'sitemaps' ),
		);
	}

	/**
	 * Turn a reading into issues: id, what, where, who, and the fix (a one-click fix id or '').
	 *
	 * @param array<string, mixed> $result A reading.
	 * @return array<int, array<string, mixed>>
	 */
	public static function issues( array $result ): array {
		$issues = array();
		$fixes  = (array) Settings::get( 'conflict_fixes', array() );
		$seen   = array();
		foreach ( (array) ( $result['pages'] ?? array() ) as $label => $page ) {
			foreach ( (array) ( $page['problems'] ?? array() ) as $tag => $extra ) {
				$seen[ $tag ][] = (string) $label;
			}
		}
		foreach ( $seen as $tag => $labels ) {
			$fix = '';
			$who = array();
			foreach ( $labels as $label ) {
				$who = array_merge( $who, (array) ( $result['pages'][ $label ]['counts']['markers'] ?? array() ) );
			}
			if ( 'title' === $tag && empty( $result['theme']['title_tag'] ) && ! in_array( 'theme-title', $fixes, true ) ) {
				$fix = 'theme-title';
			} elseif ( in_array( $tag, array( 'og_title', 'twitter' ), true ) && ! empty( $result['software']['jetpack_og'] ) && ! in_array( 'jetpack-og', $fixes, true ) ) {
				$fix = 'jetpack-og';
			} elseif ( 'microdata' === $tag ) {
				$fix = self::fix_for_microdata( (string) ( $result['theme']['template'] ?? '' ), $fixes );
			} elseif ( 'jsonld' === $tag ) {
				$joinable = 0;
				foreach ( $labels as $label ) {
					$joinable += (int) ( $result['pages'][ $label ]['counts']['joinable'] ?? 0 );
				}
				$ours = true;
				foreach ( $labels as $label ) {
					$ours = $ours && ! empty( $result['pages'][ $label ]['counts']['ours'] );
				}
				$fix = self::fix_for_jsonld( array_values( array_unique( $who ) ), $joinable, $fixes, $ours );
			}
			$issues[] = array(
				'id'    => 'tag-' . $tag,
				'tag'   => $tag,
				'pages' => array_values( array_unique( $labels ) ),
				'who'   => array_values( array_unique( $who ) ),
				'fix'   => $fix,
			);
		}
		foreach ( (array) ( $result['files'] ?? array() ) as $file ) {
			$issues[] = array(
				'id'    => 'file-' . sanitize_key( $file ),
				'tag'   => 'file',
				'file'  => (string) $file,
				'pages' => array(),
				'who'   => array(),
				'fix'   => 'file:' . $file,
			);
		}
		if ( ! empty( $result['software']['jetpack_sitemaps'] ) ) {
			$issues[] = array(
				'id'    => 'jetpack-sitemaps',
				'tag'   => 'sitemap',
				'pages' => array(),
				'who'   => array( 'Jetpack' ),
				'fix'   => 'jetpack-sitemaps',
			);
		}

		return $issues;
	}

	/**
	 * The last reading (none: an empty one).
	 *
	 * @return array<string, mixed>
	 */
	public static function last(): array {
		$r = get_option( self::OPTION, array() );

		return is_array( $r ) ? $r : array();
	}

	/**
	 * Re-read the pages a minute after a theme or plugin change (once, however many changed).
	 *
	 * @return void
	 */
	public static function schedule_rescan(): void {
		if ( ! wp_next_scheduled( self::RESCAN ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RESCAN );
		}
	}

	/**
	 * Apply a fix.
	 *
	 * @param string $id Fix id: jetpack-og, theme-title, jetpack-sitemaps or file:<name>.
	 * @return true|\WP_Error
	 */
	public static function fix( string $id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'zinn_seo_forbidden', __( 'Only an administrator can change this.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		if ( in_array( $id, array( 'jetpack-og', 'theme-title', 'join-entities' ), true ) || ( ( 0 === strpos( $id, 'microdata:' ) || 0 === strpos( $id, 'jsonld:' ) ) && in_array( $id, self::fix_ids(), true ) ) ) {
			$fixes   = (array) Settings::get( 'conflict_fixes', array() );
			$fixes[] = $id;
			$saved   = Settings::save( array( 'conflict_fixes' => array_values( array_unique( $fixes ) ) ) );
			return is_wp_error( $saved ) ? $saved : true;
		}
		if ( 'jetpack-sitemaps' === $id ) {
			if ( class_exists( '\Jetpack' ) && method_exists( '\Jetpack', 'deactivate_module' ) ) {
				\Jetpack::deactivate_module( 'sitemaps' );
				return true;
			}
			return new \WP_Error( 'zinn_seo_no_jetpack', __( 'Jetpack is not active.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( 0 === strpos( $id, 'file:' ) ) {
			return self::move_file( substr( $id, 5 ) );
		}

		return new \WP_Error( 'zinn_seo_unknown_fix', __( 'There is no such fix.', 'zinn-seo' ), array( 'status' => 404 ) );
	}

	/**
	 * Undo a switch-type fix.
	 *
	 * @param string $id Fix id.
	 * @return true|\WP_Error
	 */
	public static function undo( string $id ) {
		$fixes = array_values( array_diff( (array) Settings::get( 'conflict_fixes', array() ), array( $id ) ) );
		$saved = Settings::save( array( 'conflict_fixes' => $fixes ) );

		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * Rename a stray file out of the web server's way (kept beside it, dated).
	 *
	 * @param string $name File name.
	 * @return true|\WP_Error
	 */
	public static function move_file( string $name ) {
		if ( ! in_array( $name, array( 'robots.txt', 'sitemap.xml', 'sitemap_index.xml', 'sitemap.xml.gz' ), true ) || ! is_file( ABSPATH . $name ) ) {
			return new \WP_Error( 'zinn_seo_no_file', __( 'That file is not there any more.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$to = ABSPATH . $name . '.old-' . gmdate( 'Ymd-His' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- one rename of a stray file in the site root, the fix a person chose; its failure is answered below with what to do (a PHP warning would only reach the log). WP_Filesystem's move() is a copy + delete.
		if ( ! @rename( ABSPATH . $name, $to ) ) {
			return new \WP_Error( 'zinn_seo_cannot_move', __( 'The file could not be renamed: the site folder is read-only for WordPress. Rename or delete it with your host\'s file manager.', 'zinn-seo' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Fix-it inbox source.
	 *
	 * @param array<string, callable> $sources Sources.
	 * @return array<string, callable>
	 */
	public static function fixit_source( $sources ): array {
		$sources              = (array) $sources;
		$sources['conflicts'] = static function (): array {
			$out = array();
			foreach ( (array) ( self::last()['issues'] ?? array() ) as $issue ) {
				$out[] = array(
					'id'       => 'conflict-' . $issue['id'],
					'area'     => 'site',
					'priority' => 'file' === $issue['tag'] ? 85 : 75,
					'title'    => self::issue_title( $issue ),
					'detail'   => self::issue_detail( $issue ),
					'count'    => max( 1, count( (array) $issue['pages'] ) ),
					'minutes'  => '' !== $issue['fix'] ? 1 : 10,
					// A fix with a preview is chosen on the Conflicts screen, where its preview shows
					// first (owner, 2026-10-15: "a preview first, and a one-click undo").
					'fix'      => '' !== $issue['fix'] && ! self::previewable( (string) $issue['fix'] )
						? array(
							'kind'  => 'action',
							'id'    => 'conflict:' . $issue['fix'],
							'label' => __( 'Fix it now', 'zinn-seo' ),
						)
						: array(
							'kind'  => 'link',
							'url'   => admin_url( 'admin.php?page=zinn-seo&view=cleanup#/conflicts' ),
							'label' => '' !== $issue['fix'] ? __( 'Fix it for me', 'zinn-seo' ) : __( 'See where it comes from', 'zinn-seo' ),
						),
				);
			}
			return $out;
		};

		return $sources;
	}

	/**
	 * Register the one-click fixes.
	 *
	 * @return void
	 */
	public static function fixit_actions(): void {
		if ( ! class_exists( '\ZinnDigital\ZinnSEO\Fixit\Fixit' ) ) {
			return;
		}
		foreach ( (array) ( self::last()['issues'] ?? array() ) as $issue ) {
			if ( '' === $issue['fix'] || self::previewable( (string) $issue['fix'] ) ) {
				continue;
			}
			$fix = (string) $issue['fix'];
			\ZinnDigital\ZinnSEO\Fixit\Fixit::action(
				'conflict:' . $fix,
				static function () use ( $fix ) {
					$done = self::fix( $fix );
					if ( true === $done ) {
						self::scan();
					}
					return $done;
				}
			);
		}
	}

	/**
	 * The one-click fix for a theme's microdata ('' when the theme has no known switch, or it
	 * is on already). Pure.
	 *
	 * @param string             $template The theme's folder (a child theme's parent).
	 * @param array<int, string> $fixes    Fixes switched on.
	 * @return string
	 */
	public static function fix_for_microdata( string $template, array $fixes ): string {
		$id = 'microdata:' . strtolower( $template );

		return isset( self::THEME_MICRODATA[ strtolower( $template ) ] ) && ! in_array( $id, $fixes, true ) ? $id : '';
	}

	/**
	 * The one-click fix for repeated structured data: another SEO plugin's own switch when its
	 * marks are on the page, else joining the site's own entities into ours when that is what
	 * repeats ('' when neither applies). Pure.
	 *
	 * @param array<int, string> $who      Software whose marks the pages carry.
	 * @param int                $joinable Repeats the join fix can merge.
	 * @param array<int, string> $fixes    Fixes switched on.
	 * @param bool               $ours     Zinn® SEO's own graph is on every page concerned (else
	 *                                     switching the other plugin's off would leave none).
	 * @return string
	 */
	public static function fix_for_jsonld( array $who, int $joinable, array $fixes, bool $ours = true ): string {
		foreach ( self::PLUGIN_JSONLD as $slug => $plugin ) {
			if ( $ours && in_array( $plugin[0], $who, true ) && ! in_array( 'jsonld:' . $slug, $fixes, true ) ) {
				return 'jsonld:' . $slug;
			}
		}

		return $joinable > 0 && ! in_array( 'join-entities', $fixes, true ) ? 'join-entities' : '';
	}

	/**
	 * What a switched-on fix does, in a sentence (for the list with its Undo).
	 *
	 * @param string $id Fix id.
	 * @return string
	 */
	public static function fix_label( string $id ): string {
		if ( 0 === strpos( $id, 'microdata:' ) && isset( self::THEME_MICRODATA[ substr( $id, 10 ) ] ) ) {
			/* translators: %s: a theme's name. */
			return sprintf( __( '%s\'s own structured data marks are switched off; its pages look the same.', 'zinn-seo' ), self::THEME_MICRODATA[ substr( $id, 10 ) ] );
		}
		if ( 0 === strpos( $id, 'jsonld:' ) && isset( self::PLUGIN_JSONLD[ substr( $id, 7 ) ] ) ) {
			/* translators: %s: a plugin's name. */
			return sprintf( __( '%s no longer prints structured data; Zinn® SEO\'s stays.', 'zinn-seo' ), self::PLUGIN_JSONLD[ substr( $id, 7 ) ][0] );
		}
		$labels = array(
			'theme-title'      => __( 'Your theme\'s own title is replaced with the right one.', 'zinn-seo' ),
			'jetpack-og'       => __( 'Jetpack\'s sharing tags are switched off.', 'zinn-seo' ),
			'jetpack-sitemaps' => __( 'Jetpack\'s sitemaps are switched off.', 'zinn-seo' ),
			'join-entities'    => __( 'Other blocks that describe your site or organisation are joined to Zinn® SEO\'s, so search engines read one of each.', 'zinn-seo' ),
		);

		return $labels[ $id ] ?? $id;
	}

	/**
	 * A sentence for an issue.
	 *
	 * @param array<string, mixed> $issue Issue.
	 * @return string
	 */
	public static function issue_title( array $issue ): string {
		$names = array(
			'title'       => __( 'Your pages have two titles', 'zinn-seo' ),
			'description' => __( 'Your pages have two meta descriptions', 'zinn-seo' ),
			'canonical'   => __( 'Your pages name two canonical addresses', 'zinn-seo' ),
			'robots'      => __( 'Your pages have two robots tags', 'zinn-seo' ),
			'og_title'    => __( 'Your pages have two sets of social sharing tags', 'zinn-seo' ),
			'twitter'     => __( 'Your pages have two sets of X (Twitter) tags', 'zinn-seo' ),
			'jsonld'      => __( 'Your pages have more than one structured data block', 'zinn-seo' ),
			'microdata'   => __( 'Your theme adds its own structured data', 'zinn-seo' ),
			'sitemap'     => __( 'Two sitemaps are being made', 'zinn-seo' ),
		);
		if ( 'file' === $issue['tag'] ) {
			/* translators: %s: a file name such as robots.txt. */
			return sprintf( __( 'A %s file in your site folder hides the real one', 'zinn-seo' ), (string) $issue['file'] );
		}

		return $names[ $issue['tag'] ] ?? __( 'Another plugin prints SEO tags too', 'zinn-seo' );
	}

	/**
	 * Who printed it and what the fix does.
	 *
	 * @param array<string, mixed> $issue Issue.
	 * @return string
	 */
	public static function issue_detail( array $issue ): string {
		$who = array() !== (array) $issue['who']
			/* translators: %s: names of plugins or "Your theme". */
			? sprintf( __( 'Found the marks of: %s.', 'zinn-seo' ), implode( ', ', (array) $issue['who'] ) )
			: __( 'Your theme or another plugin prints it.', 'zinn-seo' );
		$fix = array(
			'theme-title'      => __( 'Fix it now replaces your theme\'s title with the right one as each page is sent.', 'zinn-seo' ),
			'jetpack-og'       => __( 'Fix it now switches off Jetpack\'s sharing tags; Zinn® SEO\'s stay.', 'zinn-seo' ),
			'jetpack-sitemaps' => __( 'Fix it now switches off Jetpack\'s sitemaps; yours stay.', 'zinn-seo' ),
			'join-entities'    => __( 'Other blocks describe your site or organisation again, without saying it is the same one. Fix it now joins them to Zinn® SEO\'s, so search engines read one of each; nothing on the page changes.', 'zinn-seo' ),
		);
		if ( 0 === strpos( (string) $issue['fix'], 'microdata:' ) ) {
			/* translators: %s: a theme's name. */
			return sprintf( __( '%s marks up its pages for search engines too, which repeats what Zinn® SEO already says. Fix it now switches those marks off with the theme\'s own setting; nothing on the page changes.', 'zinn-seo' ), self::THEME_MICRODATA[ substr( (string) $issue['fix'], 10 ) ] ?? '' );
		}
		if ( 0 === strpos( (string) $issue['fix'], 'jsonld:' ) ) {
			/* translators: %s: a plugin's name. */
			return sprintf( __( '%s still prints its structured data. Fix it now switches that off with its own setting; Zinn® SEO\'s stays.', 'zinn-seo' ), self::PLUGIN_JSONLD[ substr( (string) $issue['fix'], 7 ) ][0] ?? '' );
		}
		if ( '' === (string) $issue['fix'] && in_array( $issue['tag'], array( 'jsonld', 'microdata' ), true ) ) {
			return $who . ' ' . __( 'Switch the duplicate off in that plugin\'s or theme\'s settings. If you can tell which one it is, tell us, so the next version can fix it for you in one click.', 'zinn-seo' );
		}
		if ( 0 === strpos( (string) $issue['fix'], 'file:' ) ) {
			return __( 'The web server sends this file instead of the one Zinn® SEO keeps up to date. Fix it now renames it (it is kept, with the date in its name).', 'zinn-seo' );
		}

		return $who . ' ' . ( $fix[ $issue['fix'] ] ?? __( 'Switch the duplicate off in that plugin\'s or theme\'s settings.', 'zinn-seo' ) );
	}
}
