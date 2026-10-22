<?php
/**
 * Hacked-site SEO spam detector, the local scan (zs-400).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SpamScan;

use ZinnDigital\ZinnSEO\Jobs;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset reads of the posts and options tables.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What SEO spam hacks leave behind, looked for from inside the site (the `spam-scan` job: keyset,
 * resumes, no cap — a million posts are all read):
 *
 * | Finding          | How it is found                                                                |
 * |------------------|--------------------------------------------------------------------------------|
 * | `cjk`            | the "Japanese keyword hack": published pages whose title is mostly Japanese/   |
 * |                  | Chinese on a site whose language (and no multilingual plugin) explains it      |
 * | `hidden_links`   | links inside text hidden from people (display:none, visibility:hidden,         |
 * |                  | font-size:0, far off-screen) in posts and in widgets/options                   |
 * | `spam_links`     | links whose text or address is pharma/casino/loan/adult spam                   |
 * | `injected`       | scripts or iframes to other sites inside post content or text widgets          |
 * | `rogue_sitemaps` | sitemap files in the site's root folder that this site did not write, and      |
 * |                  | robots.txt `Sitemap:` lines pointing to another host                          |
 * | `cloaking`       | the home page and the newest pages fetched as a visitor AND as Googlebot: a    |
 * |                  | different title, very different text, or spam only Googlebot sees              |
 * | `search_redirect`| a visitor arriving from Google is sent to another site                         |
 *
 * ⚠️ A loopback cannot prove what Google's own addresses see (some hacks check Googlebot's IP); the
 * fetch from outside is the engine's `cloak-checks` endpoint (stage E4). Nothing found here is
 * deleted automatically: each finding names the page or file, and the clean-up is a person's (or the
 * host's malware scanner's) job.
 */
final class Scan {

	/** Job id. */
	public const JOB = 'spam-scan';

	/** Option holding the last result. */
	public const RESULT = 'zseo_spam_scan';

	/** Items listed per finding (all are counted). */
	public const LIST = 50;

	/** Option: findings a person marked "this is normal" (key => time), never shown or e-mailed again. */
	public const IGNORED = 'zseo_spam_ignored';

	/** Below this many characters of difference, visitor and Googlebot text are the same page. */
	public const CLOAK_MIN_CHARS = 200;

	/**
	 * Spam words (pharma, casino, loans, adult, fake goods). Filter: `zinn_seo_spam_words`.
	 *
	 * @return string Regular expression.
	 */
	public static function words(): string {
		$words = array( 'viagra', 'cialis', 'levitra', 'kamagra', 'tramadol', 'xanax', 'phentermine', 'pharmacy online', 'online casino', 'casino bonus', 'slot gacor', 'judi online', 'togel', 'sbobet', 'payday loan', 'replica watch', 'replica handbag', 'cheap jerseys', 'porn', 'xxx', 'escort', 'hentai', 'essay writing service', 'buy followers', 'crypto airdrop', 'casino online', 'bandar', 'poker online' );

		/**
		 * Filters the words that mark a link as spam in the hacked-site scan.
		 *
		 * @param array<int, string> $words Words and phrases.
		 */
		$words = (array) apply_filters( 'zinn_seo_spam_words', $words );

		return '/\b(' . implode( '|', array_map( static fn( $w ) => preg_quote( (string) $w, '/' ), $words ) ) . ')\b/iu';
	}

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Scanning the site for SEO spam', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * Start a scan.
	 *
	 * @return true|\WP_Error
	 */
	public static function start() {
		return Jobs::start(
			self::JOB,
			array(
				'phase'    => 'posts',
				'after'    => 0,
				'findings' => array(),
				'pages'    => 0,
			)
		);
	}

	/**
	 * Does the site's language (or a multilingual plugin) explain Japanese/Chinese/Korean titles?
	 *
	 * @return bool
	 */
	public static function cjk_expected(): bool {
		if ( self::any_cjk( self::site_languages() ) ) {
			return true;
		}
		// A multilingual plugin whose language list could not be read may publish any language.
		foreach ( array( 'ICL_SITEPRESS_VERSION', 'POLYLANG_VERSION', 'TRP_PLUGIN_VERSION' ) as $c ) {
			if ( defined( $c ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The languages this site publishes: its own, and every language a multilingual plugin serves
	 * (Tranzly, Zinn Translate, Polylang, WPML, TranslatePress).
	 *
	 * @return array<int, mixed> Language codes or locales (Tranzly's `{code, name}` entries as they come).
	 */
	public static function site_languages(): array {
		$out = array( (string) get_locale() );
		if ( function_exists( 'tranzly_languages' ) ) {
			$out = array_merge( $out, (array) call_user_func( 'tranzly_languages' ) );
		}
		// Zinn Translate: the languages it routes (/zh/…).
		$out = array_merge( $out, (array) get_option( 'zt_routed_languages', array() ) );
		if ( function_exists( 'pll_languages_list' ) ) {
			$out = array_merge( $out, (array) call_user_func( 'pll_languages_list', array( 'fields' => 'locale' ) ) );
		}
		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
		if ( is_array( $wpml ) ) {
			foreach ( $wpml as $code => $l ) {
				$out[] = is_array( $l ) ? (string) ( $l['default_locale'] ?? $l['code'] ?? $code ) : (string) $code;
			}
		}
		$trp = get_option( 'trp_settings', array() );
		if ( is_array( $trp ) ) {
			$out = array_merge( $out, (array) ( $trp['translation-languages'] ?? array() ) );
		}

		return $out;
	}

	/**
	 * Is one of these languages Japanese, Chinese or Korean?
	 *
	 * @param array<int|string, mixed> $langs Codes, locales, or `{code}` entries.
	 * @return bool
	 */
	public static function any_cjk( array $langs ): bool {
		foreach ( $langs as $l ) {
			$code = is_array( $l ) ? (string) ( $l['code'] ?? $l['locale'] ?? '' ) : ( is_scalar( $l ) ? (string) $l : '' );
			if ( 1 === preg_match( '/^(ja|zh|ko)([_-]|$)/i', $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this page a sign of the Japanese keyword hack? A title mostly in Japanese/Chinese, on a page
	 * Google can index (a FAQ item or a block with no page of its own cannot be one), on a site
	 * that does not publish those languages.
	 *
	 * @param string $title    Title.
	 * @param bool   $viewable Whether the page's type has pages of its own.
	 * @param bool   $expected Whether the site publishes a CJK language.
	 * @return bool
	 */
	public static function cjk_page( string $title, bool $viewable, bool $expected ): bool {
		return $viewable && ! $expected && self::cjk_share( $title ) >= 0.3;
	}

	/**
	 * Post types that are not content: revisions, menus, media, styles, fonts, and WordPress's
	 * oEmbed cache (the HTML of an embed core fetched for an address in a post; the post itself is
	 * what is read).
	 *
	 * @return array<int, string>
	 */
	public static function skipped_types(): array {
		return array( 'revision', 'nav_menu_item', 'attachment', 'wp_global_styles', 'wp_font_face', 'wp_font_family', 'oembed_cache' );
	}

	/**
	 * Is this address another site? The site's own pages (absolute, `www.` or relative), anchors,
	 * `mailto:`/`tel:` and unrendered template code (`<?php echo … ?>`, no host) are not.
	 *
	 * @param string $href Address as written.
	 * @return bool
	 */
	public static function foreign( string $href ): bool {
		$href = trim( $href );
		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		return self::bare( $host ) !== self::bare( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * A host without its `www.`.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare( string $host ): string {
		$host = strtolower( $host );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Sites WordPress embeds from on its own (its oEmbed providers: YouTube, Vimeo, Flickr, Behance,
	 * …) plus the usual social embeds.
	 *
	 * @return array<int, string> Registrable hosts (`flickr.com` also covers `embedr.flickr.com`).
	 */
	public static function embed_hosts(): array {
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- hosts to RECOGNISE in post content as normal embeds (no spam finding); nothing is loaded from them.
		$hosts = array( 'youtube.com', 'youtube-nocookie.com', 'youtu.be', 'vimeo.com', 'google.com', 'gstatic.com', 'twitter.com', 'x.com', 'instagram.com', 'facebook.com', 'spotify.com', 'soundcloud.com', 'wp.com', 'wordpress.com', 'wordpress.tv', 'flickr.com', 'behance.net', 'dailymotion.com', 'tiktok.com', 'reddit.com', 'pinterest.com', 'ted.com', 'mixcloud.com', 'issuu.com', 'scribd.com', 'slideshare.net', 'speakerdeck.com', 'kickstarter.com', 'cloudup.com', 'imgur.com', 'smugmug.com', 'tumblr.com', 'amazon.com', 'pocketcasts.com', 'crowdsignal.com', 'polldaddy.com', 'animoto.com', 'videopress.com', 'bsky.app', 'twitch.tv' );
		if ( function_exists( '_wp_oembed_get_object' ) ) {
			foreach ( (array) _wp_oembed_get_object()->providers as $provider ) {
				$host = self::bare( (string) wp_parse_url( (string) ( is_array( $provider ) ? ( $provider[0] ?? '' ) : '' ), PHP_URL_HOST ) );
				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Is this host one of {@see self::embed_hosts()} (or under one)?
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function embed_host( string $host ): bool {
		$host = self::bare( $host );
		foreach ( self::embed_hosts() as $known ) {
			$known = self::bare( $known );
			if ( $host === $known || substr( $host, -strlen( '.' . $known ) ) === '.' . $known ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The share of CJK letters in a text (0..1).
	 *
	 * @param string $text Text.
	 * @return float
	 */
	public static function cjk_share( string $text ): float {
		$text = (string) preg_replace( '/[\s\p{P}\p{N}]+/u', '', $text );
		$all  = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $all < 4 ) {
			return 0.0;
		}
		$cjk = (int) preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $text );

		return $cjk / $all;
	}

	/**
	 * Links hidden from people in a piece of HTML.
	 *
	 * @param string $html HTML.
	 * @return array<int, string> The hidden links' addresses.
	 */
	public static function hidden_links( string $html ): array {
		$out = array();
		if ( false === stripos( $html, '<a' ) ) {
			return $out;
		}
		$hide = '(?:display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0(?:px|em|rem|pt)?\s*[;"\']|(?:left|top|text-indent)\s*:\s*-\d{3,}\s*px|height\s*:\s*0(?:px)?\s*;\s*overflow\s*:\s*hidden|opacity\s*:\s*0(?:\.0+)?\s*[;"\'])';
		// An element styled hidden, and every link inside it (to its closing tag, one level).
		if ( preg_match_all( '#<(div|span|p|section|font|ins|small)\b[^>]*style\s*=\s*["\'][^"\']*' . $hide . '[^>]*>(.*?)</\1>#is', $html, $m ) ) {
			foreach ( $m[2] as $inner ) {
				if ( preg_match_all( '#<a\b[^>]*href\s*=\s*["\']([^"\']+)#i', $inner, $links ) ) {
					foreach ( $links[1] as $href ) {
						$out[] = (string) $href;
					}
				}
			}
		}
		// A link itself styled hidden.
		if ( preg_match_all( '#<a\b[^>]*style\s*=\s*["\'][^"\']*' . $hide . '[^>]*href\s*=\s*["\']([^"\']+)|<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*style\s*=\s*["\'][^"\']*' . $hide . '#i', $html, $m ) ) {
			foreach ( array_merge( $m[1], $m[2] ) as $href ) {
				if ( '' !== $href ) {
					$out[] = (string) $href;
				}
			}
		}

		return array_values( array_unique( array_filter( $out, array( self::class, 'foreign' ) ) ) );
	}

	/**
	 * Spam links in a piece of HTML (text or address matching the spam words).
	 *
	 * @param string $html HTML.
	 * @return array<int, string> Addresses.
	 */
	public static function spam_links( string $html ): array {
		$out = array();
		if ( false === stripos( $html, '<a' ) || ! preg_match_all( '#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		$re = self::words();
		foreach ( $m as $link ) {
			if ( 1 === preg_match( $re, wp_strip_all_tags( (string) $link[2] ) ) || 1 === preg_match( $re, str_replace( array( '-', '_' ), ' ', rawurldecode( (string) $link[1] ) ) ) ) {
				$out[] = (string) $link[1];
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Scripts and frames pointing at another site in a piece of HTML.
	 *
	 * @param string $html HTML.
	 * @return array<int, string> Their addresses (or "inline" for an obfuscated inline script).
	 */
	public static function injected( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<(script|iframe)\b([^>]*)>#i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $tag ) {
				if ( ! preg_match( '#\bsrc\s*=\s*["\']([^"\']+)#i', $tag[2], $src ) ) {
					continue;
				}
				$src  = (string) $src[1];
				$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
				if ( ! self::foreign( $src ) || self::embed_host( $host ) ) {
					continue;
				}
				// WordPress's own embed of another WordPress site: a sandboxed frame showing that
				// site's post card (core writes `class="wp-embedded-content"` with `sandbox`).
				if ( 'iframe' === strtolower( $tag[1] ) && 1 === preg_match( '#\bclass\s*=\s*["\'][^"\']*\bwp-embedded-content\b#i', $tag[2] ) && 1 === preg_match( '#\bsandbox\s*=\s*["\']allow-scripts["\']#i', $tag[2] ) && 1 === preg_match( '#/embed/(\#|\?|$)#', $src ) ) {
					continue;
				}
				$out[] = $src;
			}
		}
		if ( preg_match( '#<script\b[^>]*>[^<]*(eval\s*\(\s*(atob|unescape|String\.fromCharCode)|document\.write\s*\(\s*unescape|window\.location\s*=\s*["\']https?://)#i', $html ) ) {
			$out[] = 'inline';
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Add a finding.
	 *
	 * @param array<string, mixed> $findings Findings (by kind).
	 * @param string               $kind     Kind.
	 * @param array<string, mixed> $item     `id`/`url`/`file`/`option`, `title`, `detail`.
	 * @return array<string, mixed>
	 */
	private static function add( array $findings, string $kind, array $item ): array {
		if ( ! isset( $findings[ $kind ] ) ) {
			$findings[ $kind ] = array(
				'count' => 0,
				'items' => array(),
			);
		}
		++$findings[ $kind ]['count'];
		if ( count( $findings[ $kind ]['items'] ) < self::LIST ) {
			$findings[ $kind ]['items'][] = $item;
		}

		return $findings;
	}

	/**
	 * Check one piece of HTML and add what it hides.
	 *
	 * @param array<string, mixed> $findings Findings.
	 * @param string               $html     HTML.
	 * @param array<string, mixed> $where    Where it is (id/title or option).
	 * @return array<string, mixed>
	 */
	public static function check_html( array $findings, string $html, array $where ): array {
		foreach ( array(
			'hidden_links' => self::hidden_links( $html ),
			'spam_links'   => self::spam_links( $html ),
			'injected'     => self::injected( $html ),
		) as $kind => $found ) {
			if ( array() !== $found ) {
				$findings = self::add( $findings, $kind, $where + array( 'detail' => implode( ' ', array_slice( $found, 0, 5 ) ) ) );
			}
		}

		return $findings;
	}

	/**
	 * One step of the scan.
	 *
	 * @param array<string, mixed> $state    phase, after, findings, pages.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		global $wpdb;
		$findings = (array) ( $state['findings'] ?? array() );
		$phase    = (string) ( $state['phase'] ?? 'posts' );
		$after    = (int) ( $state['after'] ?? 0 );
		$pages    = (int) ( $state['pages'] ?? 0 );
		$expected = self::cjk_expected();
		$viewable = array();
		$skipped  = array_values( array_map( 'strval', self::skipped_types() ) );
		$skipped  = array() === $skipped ? array( '' ) : $skipped;
		$skip_in  = implode( ',', array_fill( 0, count( $skipped ), '%s' ) );
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) && 'done' !== $phase ) {
			if ( 'posts' === $phase ) {
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_content, post_type FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type NOT IN ({$skip_in}) ORDER BY ID ASC LIMIT 200", array_merge( array( $after ), $skipped ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $skip_in is only %s placeholders, one per skipped type; every value is bound by prepare().
				if ( array() === $rows ) {
					$phase = 'options';
					$after = 0;
					continue;
				}
				foreach ( $rows as $row ) {
					$after = (int) $row['ID'];
					++$pages;
					$where = array(
						'id'    => (int) $row['ID'],
						'title' => wp_html_excerpt( (string) $row['post_title'], 120, '…' ),
						'type'  => (string) $row['post_type'],
					);
					$type  = (string) $row['post_type'];
					if ( ! isset( $viewable[ $type ] ) ) {
						$viewable[ $type ] = is_post_type_viewable( $type );
					}
					if ( self::cjk_page( (string) $row['post_title'], $viewable[ $type ], $expected ) ) {
						$findings = self::add( $findings, 'cjk', $where + array( 'detail' => '' ) );
					}
					$findings = self::check_html( $findings, (string) $row['post_content'], $where );
				}
				continue;
			}
			if ( 'options' === $phase ) {
				// Widgets, the site tagline, theme footers and every other option that holds HTML.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND (option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s) ORDER BY option_id ASC LIMIT 200", $after, $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', '%<a %', '%<script%', '%<iframe%' ), ARRAY_A );
				if ( array() === $rows ) {
					$phase = 'files';
					continue;
				}
				foreach ( $rows as $row ) {
					$after    = (int) $row['option_id'];
					$value    = maybe_unserialize( (string) $row['option_value'] );
					$html     = is_string( $value ) ? $value : (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					$html     = str_replace( array( '\\"', '\\/' ), array( '"', '/' ), $html );
					$findings = self::check_html(
						$findings,
						$html,
						array(
							'option' => (string) $row['option_name'],
							'title'  => (string) $row['option_name'],
						)
					);
				}
				continue;
			}
			if ( 'files' === $phase ) {
				$findings = self::rogue_sitemaps( $findings );
				$phase    = 'fetch';
				continue;
			}
			if ( 'fetch' === $phase ) {
				$findings = self::cloaking( $findings );
				$phase    = 'done';
			}
		}
		$done = 'done' === $phase;
		if ( $done ) {
			update_option(
				self::RESULT,
				array(
					'at'       => time(),
					'pages'    => $pages,
					'findings' => $findings,
				),
				false
			);

			/**
			 * Fires when a hacked-site scan finishes (Pro e-mails new findings here).
			 *
			 * @param array<string, mixed> $findings Findings by kind.
			 */
			do_action( 'zinn_seo_spam_scanned', $findings );
		}

		return array(
			'state'    => array(
				'phase'    => $phase,
				'after'    => $after,
				'findings' => $findings,
				'pages'    => $pages,
			),
			'done'     => $done,
			'progress' => array(
				'done'  => $pages,
				'total' => null,
			),
		);
	}

	/**
	 * Sitemaps in the site's root folder that are not this site's, and robots.txt pointing away.
	 *
	 * @param array<string, mixed> $findings Findings.
	 * @return array<string, mixed>
	 */
	public static function rogue_sitemaps( array $findings ): array {
		$root  = trailingslashit( ABSPATH );
		$files = glob( $root . '*.xml' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the first bytes of a local file.
			if ( false === stripos( $head, '<urlset' ) && false === stripos( $head, '<sitemapindex' ) ) {
				continue;
			}
			$findings = self::add(
				$findings,
				'rogue_sitemaps',
				array(
					'file'   => basename( $file ),
					'title'  => basename( $file ),
					'detail' => sprintf( /* translators: 1: a size in KB, 2: a date. */ __( '%1$s KB, changed %2$s', 'zinn-seo' ), number_format_i18n( (int) ( filesize( $file ) / 1024 ) ), gmdate( 'Y-m-d', (int) filemtime( $file ) ) ),
				)
			);
		}
		$robots = $root . 'robots.txt';
		if ( is_readable( $robots ) ) {
			$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			foreach ( (array) preg_split( '/\R/', (string) file_get_contents( $robots ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
				if ( 1 === preg_match( '/^\s*sitemap\s*:\s*(\S+)/i', $line, $m ) && strtolower( (string) wp_parse_url( $m[1], PHP_URL_HOST ) ) !== $home ) {
					$findings = self::add(
						$findings,
						'rogue_sitemaps',
						array(
							'file'   => 'robots.txt',
							'title'  => 'robots.txt',
							'detail' => $m[1],
						)
					);
				}
			}
		}

		return $findings;
	}

	/**
	 * Fetch an address as someone.
	 *
	 * @param string                $url     Address.
	 * @param array<string, string> $headers Headers.
	 * @return array{code: int, location: string, title: string, text: string, spam: int}
	 */
	public static function fetch( string $url, array $headers ): array {
		$res  = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => $headers,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
			)
		);
		$body = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) preg_replace( '#<(script|style)\b.*?</\1>#is', '', $body ) ) ) );

		return array(
			'code'     => is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res ),
			'location' => is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'location' ),
			'title'    => preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ? trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES ) ) : '',
			'text'     => $text,
			'spam'     => (int) preg_match_all( self::words(), $text ),
		);
	}

	/**
	 * The home page and the newest pages, as a visitor and as Googlebot; and as a visitor from
	 * Google's results.
	 *
	 * @param array<string, mixed> $findings Findings.
	 * @return array<string, mixed>
	 */
	public static function cloaking( array $findings ): array {
		$urls  = array( home_url( '/' ) );
		$types = array_values( array_diff( array_filter( (array) get_post_types( array( 'public' => true ) ), 'is_post_type_viewable' ), self::skipped_types() ) );
		foreach ( array() === $types ? array() : get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		) as $id ) {
			$urls[] = (string) get_permalink( (int) $id );
		}
		$browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';
		$google  = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$home    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( array_unique( $urls ) as $url ) {
			$a = self::fetch( $url, array( 'User-Agent' => $browser ) );
			$b = self::fetch( $url, array( 'User-Agent' => $google ) );
			if ( 0 === $a['code'] || 0 === $b['code'] ) {
				continue;
			}
			$why = self::cloak_reasons( $a, $b );
			if ( array() !== $why ) {
				$findings = self::add(
					$findings,
					'cloaking',
					array(
						'url'    => $url,
						'title'  => $url,
						'detail' => implode( '; ', $why ),
					)
				);
			}
			$c    = self::fetch(
				$url,
				array(
					'User-Agent' => $browser,
					'Referer'    => 'https://www.google.com/',
				)
			);
			$host = strtolower( (string) wp_parse_url( $c['location'], PHP_URL_HOST ) );
			if ( $c['code'] >= 300 && $c['code'] < 400 && '' !== $host && $host !== $home && $a['code'] < 300 ) {
				$findings = self::add(
					$findings,
					'search_redirect',
					array(
						'url'    => $url,
						'title'  => $url,
						'detail' => $c['location'],
					)
				);
			}
		}

		return $findings;
	}

	/**
	 * Why a page looks cloaked: what Googlebot was shown that a visitor was not. A difference of a
	 * few characters (a page with almost no text, a counter, a date) is not cloaking.
	 *
	 * @param array{code: int, title: string, text: string, spam: int} $a As a visitor.
	 * @param array{code: int, title: string, text: string, spam: int} $b As Googlebot.
	 * @return array<int, string> Reasons; empty when the two are the same page.
	 */
	public static function cloak_reasons( array $a, array $b ): array {
		$why = array();
		if ( $a['title'] !== $b['title'] ) {
			/* translators: 1: page title for visitors, 2: page title for Googlebot. */
			$why[] = sprintf( __( 'title "%1$s" for visitors, "%2$s" for Googlebot', 'zinn-seo' ), $a['title'], $b['title'] );
		}
		$la = strlen( $a['text'] );
		$lb = strlen( $b['text'] );
		$r  = $lb / max( 1, $la );
		if ( abs( $la - $lb ) >= self::CLOAK_MIN_CHARS && ( $r > 1.5 || $r < 0.5 ) ) {
			/* translators: 1: characters for visitors, 2: characters for Googlebot. */
			$why[] = sprintf( __( '%1$d characters of text for visitors, %2$d for Googlebot', 'zinn-seo' ), $la, $lb );
		}
		if ( $b['spam'] > $a['spam'] ) {
			$why[] = __( 'spam words only Googlebot is shown', 'zinn-seo' );
		}
		if ( $a['code'] !== $b['code'] ) {
			/* translators: 1: status for visitors, 2: status for Googlebot. */
			$why[] = sprintf( __( 'status %1$d for visitors, %2$d for Googlebot', 'zinn-seo' ), $a['code'], $b['code'] );
		}

		return $why;
	}

	/**
	 * The key of one finding: its kind, where it is (page, option, file or address) and what was found.
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $item Item.
	 * @return string
	 */
	public static function key( string $kind, array $item ): string {
		return $kind . ':' . (string) ( $item['id'] ?? $item['option'] ?? $item['file'] ?? $item['url'] ?? '' ) . ':' . md5( (string) ( $item['detail'] ?? '' ) );
	}

	/**
	 * Mark a finding "this is normal" (it is never shown or e-mailed again), or show every one again.
	 *
	 * @param string $key     {@see self::key()}.
	 * @param bool   $restore True: forget every mark.
	 * @return int How many are marked now.
	 */
	public static function ignore( string $key, bool $restore = false ): int {
		$ignored = $restore ? array() : (array) get_option( self::IGNORED, array() );
		if ( ! $restore && '' !== $key ) {
			$ignored[ $key ] = time();
		}
		update_option( self::IGNORED, $ignored, false );

		return count( $ignored );
	}

	/**
	 * Findings without those marked "this is normal" (counts lowered, emptied kinds dropped).
	 *
	 * @param array<string, mixed> $findings Findings by kind.
	 * @return array<string, mixed>
	 */
	public static function visible( array $findings ): array {
		$ignored = (array) get_option( self::IGNORED, array() );
		if ( array() === $ignored ) {
			return $findings;
		}
		foreach ( $findings as $kind => $f ) {
			$keep = array();
			foreach ( (array) ( $f['items'] ?? array() ) as $item ) {
				if ( ! isset( $ignored[ self::key( (string) $kind, (array) $item ) ] ) ) {
					$keep[] = $item;
				}
			}
			$count = (int) ( $f['count'] ?? 0 ) - ( count( (array) ( $f['items'] ?? array() ) ) - count( $keep ) );
			if ( $count <= 0 ) {
				unset( $findings[ $kind ] );
				continue;
			}
			$findings[ $kind ]['count'] = $count;
			$findings[ $kind ]['items'] = $keep;
		}

		return $findings;
	}

	/**
	 * The last result, with each finding's name and advice.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function result(): ?array {
		$r = get_option( self::RESULT, null );
		if ( ! is_array( $r ) ) {
			return null;
		}
		$names         = self::kinds();
		$r['findings'] = self::visible( (array) $r['findings'] );
		$r['ignored']  = count( (array) get_option( self::IGNORED, array() ) );
		foreach ( (array) $r['findings'] as $kind => $f ) {
			foreach ( (array) ( $f['items'] ?? array() ) as $i => $item ) {
				$r['findings'][ $kind ]['items'][ $i ]['key'] = self::key( (string) $kind, (array) $item );
			}
			$r['findings'][ $kind ]['name']   = $names[ $kind ][0] ?? $kind;
			$r['findings'][ $kind ]['advice'] = $names[ $kind ][1] ?? '';
			foreach ( (array) ( $f['items'] ?? array() ) as $i => $item ) {
				if ( isset( $item['id'] ) ) {
					$r['findings'][ $kind ]['items'][ $i ]['edit'] = (string) get_edit_post_link( (int) $item['id'], 'raw' );
					$r['findings'][ $kind ]['items'][ $i ]['view'] = (string) get_permalink( (int) $item['id'] );
				}
			}
		}

		return $r;
	}

	/**
	 * Finding kinds: name and what to do.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function kinds(): array {
		return array(
			'cjk'             => array( __( 'Pages with Japanese or Chinese titles', 'zinn-seo' ), __( 'The "Japanese keyword hack" publishes thousands of such pages to sell fake goods under your name. If you did not write them, delete them, change every password and have the site cleaned.', 'zinn-seo' ) ),
			'hidden_links'    => array( __( 'Links hidden from visitors', 'zinn-seo' ), __( 'Text styled so people cannot see it but search engines can is how hacks sell links on your site, and Google penalises it. Remove the hidden part.', 'zinn-seo' ) ),
			'spam_links'      => array( __( 'Spam links', 'zinn-seo' ), __( 'Links about pharmacy, casino, loans or adult content you did not add are a sign of a hack. Remove them and look for how they got in.', 'zinn-seo' ) ),
			'injected'        => array( __( 'Scripts or frames from other sites', 'zinn-seo' ), __( 'A script from an unknown site inside a post or a widget can redirect visitors or show spam. Remove it unless you added it on purpose.', 'zinn-seo' ) ),
			'rogue_sitemaps'  => array( __( 'Other sitemaps in the site\'s folder', 'zinn-seo' ), __( 'Hacks drop sitemap files listing their spam pages and point robots.txt at them. If no plugin of yours writes these files, delete them and remove them in Search Console; Zinn® SEO serves your sitemap itself.', 'zinn-seo' ) ),
			'cloaking'        => array( __( 'Pages that look different to Google', 'zinn-seo' ), __( 'Showing search engines different content from visitors (cloaking) gets a site removed from results. A hack usually does this from a changed theme or plugin file: have the site scanned for malware.', 'zinn-seo' ) ),
			'search_redirect' => array( __( 'Visitors from Google sent elsewhere', 'zinn-seo' ), __( 'Someone arriving from a search is sent to another site — the classic sign of a redirect hack. Have the site scanned for malware and restore clean files.', 'zinn-seo' ) ),
		);
	}
}
