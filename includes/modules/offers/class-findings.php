<?php
/**
 * Findings the Fix-it inbox did not have yet, read from what the plugin already keeps.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Offers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each finding is a real problem with its own fix link, whether or not anything is for sale:
 *
 * - `offers-slow-server` — Google's real-visitor record says the server takes long to answer;
 * - `offers-heavy-files` — the same record says the page's biggest image or block appears late
 *   (Largest Contentful Paint): large files travelling far (zs-658, the CDN);
 * - `offers-not-indexed` — Search Console's inspection says pages are not in Google's index;
 * - `offers-old-addresses` — visitors and links keep asking for addresses that do not exist;
 * - `offers-other-languages` — a one-language site gets a real share of its Google clicks from
 *   countries that speak another language;
 * - `offers-no-https` — the site's address is not https;
 * - `offers-bulk-change` — many pages were changed in bulk this week (a backup first is wise);
 * - `offers-guard-incident` — SEO Guard reported an incident that nobody has acknowledged;
 * - `offers-many-fixes` — many urgent problems at once (added per request, as it counts the
 *   other sources' issues: see `Offers::decorate`).
 *
 * ⛔ Each reads what the site ALREADY keeps (the speed record, the inspection answers, the 404
 * counters). The one exception is Search Console's clicks by country, which the Search screen's
 * own 12-hour set holds: read again at most every 12 hours, within three seconds, and only when
 * Search Console is connected. Working out the inbox never calls Zinn®, and a finding whose
 * data is not there simply does not appear.
 */
final class Findings {

	/** A server answering slower than this (ms, 75th percentile) is a finding. Google's "good" is 800. */
	public const SLOW_TTFB_MS = 800;

	/** The largest image or block appearing later than this (ms, 75th percentile) is a finding. Google's "good" is 2500. */
	public const SLOW_LCP_MS = 2500;

	/** An address asked for this many times that does not exist counts as an "old address". */
	public const OLD_ADDRESS_HITS = 3;

	/** How many old addresses make a finding. */
	public const OLD_ADDRESSES = 5;

	/** Share of clicks from one other language that makes a finding. */
	public const OTHER_LANGUAGE_SHARE = 0.1;

	/** And the least number of clicks it must be. */
	public const OTHER_LANGUAGE_CLICKS = 50;

	/** Pages changed in bulk in a week that make a finding. */
	public const BULK_PAGES = 20;

	/** Urgent problems that make "many fixes". */
	public const MANY_URGENT = 5;

	/**
	 * Every finding of this module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function issues(): array {
		$out = array();
		foreach ( array( 'slow_server', 'heavy_files', 'not_indexed', 'old_addresses', 'other_languages', 'no_https', 'bulk_change', 'guard_incident' ) as $method ) {
			$issue = self::$method();
			if ( null !== $issue ) {
				$out[] = $issue;
			}
		}

		return $out;
	}

	/**
	 * The server answers slowly for real visitors (Chrome's record, kept by the speed screen).
	 *
	 * @param array<string, mixed>|null $record A kept record (tests); null reads the site's.
	 * @return array<string, mixed>|null
	 */
	public static function slow_server( ?array $record = null ): ?array {
		if ( null === $record ) {
			$key    = 'zseo_cwv_' . substr( md5( home_url( '/' ) . '|origin|phone' ), 0, 16 );
			$kept   = get_option( $key );
			$record = is_array( $kept ) && is_array( $kept['r'] ?? null ) ? $kept['r'] : array();
		}
		$ttfb = $record['metrics']['ttfb']['p75'] ?? null;
		if ( null === $ttfb || (float) $ttfb < self::SLOW_TTFB_MS ) {
			return null;
		}
		$seconds = number_format_i18n( (float) $ttfb / 1000, 1 );

		return array(
			'id'       => 'offers-slow-server',
			'area'     => 'technical',
			'priority' => 'poor' === ( $record['metrics']['ttfb']['rating'] ?? '' ) ? 80 : 60,
			/* translators: %s: seconds, e.g. "1.4". */
			'title'    => sprintf( __( 'Your server takes %s seconds to start answering', 'zinn-seo' ), $seconds ),
			'detail'   => __( 'Before a visitor sees anything, their browser waits for your server to answer. Google measures this on real visits; under 0.8 seconds is good. A cache, lighter plugins or faster hosting bring it down.', 'zinn-seo' ),
			'minutes'  => 30,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=search#/speed' ),
				'label' => __( 'See the speed report', 'zinn-seo' ),
			),
		);
	}

	/**
	 * The page's largest image or block shows late for real visitors (Chrome's record, kept by
	 * the speed screen) — large files travelling a long way, which a CDN shortens (zs-658).
	 *
	 * @param array<string, mixed>|null $record A kept record (tests); null reads the site's.
	 * @return array<string, mixed>|null
	 */
	public static function heavy_files( ?array $record = null ): ?array {
		if ( null === $record ) {
			$key    = 'zseo_cwv_' . substr( md5( home_url( '/' ) . '|origin|phone' ), 0, 16 );
			$kept   = get_option( $key );
			$record = is_array( $kept ) && is_array( $kept['r'] ?? null ) ? $kept['r'] : array();
		}
		$lcp = $record['metrics']['lcp']['p75'] ?? null;
		if ( null === $lcp || (float) $lcp < self::SLOW_LCP_MS ) {
			return null;
		}
		$seconds = number_format_i18n( (float) $lcp / 1000, 1 );

		return array(
			'id'       => 'offers-heavy-files',
			'area'     => 'technical',
			'priority' => 'poor' === ( $record['metrics']['lcp']['rating'] ?? '' ) ? 75 : 55,
			/* translators: %s: seconds, e.g. "3.1". */
			'title'    => sprintf( __( 'Your main image takes %s seconds to appear', 'zinn-seo' ), $seconds ),
			'detail'   => __( 'Google measures how long the biggest image or block on a page takes to show for real visitors; under 2.5 seconds is good. Smaller images, and serving files from servers close to each visitor, bring it down.', 'zinn-seo' ),
			'minutes'  => 15,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=search#/speed' ),
				'label' => __( 'See the speed report', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Pages Search Console says are not indexed (from the answers the Indexing tab kept).
	 *
	 * @param int|null $count Not-indexed pages (tests); null counts the site's.
	 * @return array<string, mixed>|null
	 */
	public static function not_indexed( ?int $count = null ): ?array {
		if ( null === $count ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed count over the plugin's own meta, inside the cached inbox.
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> %s", '_zseo_gsc_verdict', 'PASS' ) );
		}
		if ( $count < 1 ) {
			return null;
		}

		return array(
			'id'       => 'offers-not-indexed',
			'area'     => 'site',
			'priority' => 70,
			/* translators: %d: number of pages. */
			'title'    => sprintf( _n( 'Google has not indexed %d of your pages', 'Google has not indexed %d of your pages', $count, 'zinn-seo' ), $count ),
			'detail'   => __( 'A page that is not in Google’s index cannot appear in search. The Indexing tab shows why for each one.', 'zinn-seo' ),
			'count'    => $count,
			'minutes'  => 10,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=search#/index' ),
				'label' => __( 'See which pages and why', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Addresses that keep being asked for and do not exist (people's 404s, not bots').
	 *
	 * @param int|null $count Such addresses (tests); null counts the site's.
	 * @return array<string, mixed>|null
	 */
	public static function old_addresses( ?int $count = null ): ?array {
		if ( null === $count ) {
			$count = 0;
			if ( class_exists( '\ZinnDigital\ZinnSEO\Counters' ) && \ZinnDigital\ZinnSEO\Counters::ready() ) {
				foreach ( \ZinnDigital\ZinnSEO\Counters::rows( '404', 'hits', 200 )['items'] as $row ) {
					$count += (int) $row['hits'] >= self::OLD_ADDRESS_HITS && self::page_like( (string) $row['key'] ) ? 1 : 0;
				}
			}
		}
		if ( $count < self::OLD_ADDRESSES ) {
			return null;
		}

		return array(
			'id'       => 'offers-old-addresses',
			'area'     => 'links',
			'priority' => 55,
			/* translators: %d: number of addresses. */
			'title'    => sprintf( _n( 'Visitors keep asking for %d address that does not exist', 'Visitors keep asking for %d addresses that do not exist', $count, 'zinn-seo' ), $count ),
			'detail'   => __( 'Old links and bookmarks still point at these pages. Redirect each one to the closest page you have, or bring the old pages back, so the visits and the links count for you.', 'zinn-seo' ),
			'count'    => $count,
			'minutes'  => 15,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=redirects#/notfound' ),
				'label' => __( 'See the addresses', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Could this missing address have been a page someone linked to or bookmarked? Not a file (an
	 * image size that was never made, an icon, a script), not the home or the 404 page itself, not
	 * a probe for a login or a config file, and not a crawler's loop of empty segments
	 * (zinnhub.com, 2026-10-16: `/-/-/-/-/-/-/-/-/-/-/` was its top "old address", 282 hits).
	 *
	 * @param string $path Address path.
	 * @return bool
	 */
	public static function page_like( string $path ): bool {
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$bare = trim( $path, '/' );
		if ( '' === $bare || '404' === $bare || 1 === preg_match( '#^[/\-_.]+$#', $path ) ) {
			return false;
		}
		if ( 1 === preg_match( '#(^|/)(wp-content|wp-includes|wp-admin|\.well-known)/|(^|/)(wp-login|xmlrpc)\.php$|(^|/)\.[a-z]#i', $path ) ) {
			return false;
		}

		return 1 !== preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|css|js|map|json|txt|xml|woff2?|ttf|eot|mp4|webm|mp3|pdf|zip|gz|php|env|ini|log|bak|sql)$#i', $path );
	}

	/**
	 * The site is served over plain http.
	 *
	 * @param string|null $home The home address (tests); null reads the site's.
	 * @return array<string, mixed>|null
	 */
	public static function no_https( ?string $home = null ): ?array {
		$home = null === $home ? home_url( '/' ) : $home;
		if ( 'http' !== strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) ) ) {
			return null;
		}

		return array(
			'id'       => 'offers-no-https',
			'area'     => 'site',
			'priority' => 85,
			'title'    => __( 'Your site is not served over HTTPS', 'zinn-seo' ),
			'detail'   => __( 'Browsers mark the site "Not secure" and Google prefers secure pages. Install a certificate and switch the site address to https.', 'zinn-seo' ),
			'minutes'  => 30,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'options-general.php' ),
				'label' => __( 'Open the site address settings', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Many pages changed in bulk in the last week (the bulk editor's own history).
	 *
	 * @param array<int, array<string, mixed>>|null $batches History rows (tests); null reads the site's.
	 * @param int|null                              $now     Timestamp (tests).
	 * @return array<string, mixed>|null
	 */
	public static function bulk_change( ?array $batches = null, ?int $now = null ): ?array {
		$now     = $now ?? time();
		$batches = null === $batches ? (array) get_option( 'zseo_bulk_batches', array() ) : $batches;
		$pages   = 0;
		foreach ( $batches as $b ) {
			if ( is_array( $b ) && (int) ( $b['at'] ?? 0 ) > $now - WEEK_IN_SECONDS && '' === (string) ( $b['undone'] ?? '' ) ) {
				$pages += (int) ( $b['count'] ?? 0 );
			}
		}
		if ( $pages < self::BULK_PAGES ) {
			return null;
		}

		return array(
			'id'       => 'offers-bulk-change',
			'area'     => 'content',
			'priority' => 40,
			/* translators: %d: number of changes. */
			'title'    => sprintf( _n( '%d change was made in bulk this week', '%d changes were made in bulk this week', $pages, 'zinn-seo' ), $pages ),
			'detail'   => __( 'Big changes can be undone from the bulk editor\'s history, but only a backup kept away from your server brings back everything if something else goes wrong.', 'zinn-seo' ),
			'count'    => $pages,
			'minutes'  => 5,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=bulk' ),
				'label' => __( 'See the bulk changes', 'zinn-seo' ),
			),
		);
	}

	/**
	 * An SEO Guard incident on the notice feed that nobody has acknowledged (last seven days).
	 *
	 * @param array<int, array<string, mixed>>|null $items Notice items (tests); null reads the kept feed.
	 * @param int|null                              $now   Timestamp (tests).
	 * @return array<string, mixed>|null
	 */
	public static function guard_incident( ?array $items = null, ?int $now = null ): ?array {
		$now = $now ?? time();
		if ( null === $items ) {
			$items = array();
			$row   = get_option( 'zseo_notices', array() );
			foreach ( (array) ( is_array( $row ) ? ( $row['by'] ?? array() ) : array() ) as $by ) {
				$items = array_merge( $items, (array) ( $by['items'] ?? array() ) );
			}
		}
		$open = array_filter( $items, static fn( $i ) => is_array( $i ) && 'guard_incident' === ( $i['kind'] ?? '' ) && empty( $i['acked'] ) && (int) ( $i['at'] ?? 0 ) > $now - WEEK_IN_SECONDS );
		if ( ! $open ) {
			return null;
		}
		$first = reset( $open );

		return array(
			'id'       => 'offers-guard-incident',
			'area'     => 'site',
			'priority' => 90,
			/* translators: %s: what SEO Guard saw. */
			'title'    => sprintf( __( 'SEO Guard: %s', 'zinn-seo' ), sanitize_text_field( (string) ( $first['title'] ?? '' ) ) ),
			'detail'   => sanitize_text_field( (string) ( $first['message'] ?? '' ) ),
			'count'    => count( $open ),
			'minutes'  => 15,
			'fix'      => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=credits' ),
				'label' => __( 'See the notices', 'zinn-seo' ),
			),
		);
	}

	/**
	 * A one-language site with a real share of clicks from countries speaking another language.
	 *
	 * @param array<string, int>|null $clicks Clicks by country code (tests); null reads the kept set.
	 * @return array<string, mixed>|null
	 */
	public static function other_languages( ?array $clicks = null ): ?array {
		if ( self::multilingual() ) {
			return null;
		}
		if ( null === $clicks ) {
			$clicks = self::kept_country_clicks();
		}
		$site  = strtolower( substr( (string) get_locale(), 0, 2 ) );
		$found = self::other_language( $clicks, $site );
		if ( null === $found ) {
			return null;
		}
		list( $lang, $share ) = $found;
		$name                 = self::language_name( $lang );

		return array(
			'id'         => 'offers-other-languages',
			'area'       => 'content',
			'priority'   => 50,
			/* translators: 1: a share in percent, 2: a language, e.g. "German". */
			'title'      => sprintf( __( '%1$d%% of your Google clicks come from %2$s speakers', 'zinn-seo' ), (int) round( $share * 100 ), $name ),
			'detail'     => __( 'Every page is in one language. People searching in their own language find, trust and buy from pages written in it.', 'zinn-seo' ),
			'minutes'    => 30,
			'offer_lang' => $name,
			'fix'        => array(
				'kind'  => 'link',
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=search' ),
				'label' => __( 'See where clicks come from', 'zinn-seo' ),
			),
		);
	}

	/**
	 * The other language with the largest share of clicks, when it is big enough. Pure.
	 *
	 * @param array<string, int> $clicks Clicks by ISO 3166 alpha-3 or alpha-2 country code.
	 * @param string             $site   The site's language (two letters).
	 * @return array{0: string, 1: float}|null [language, share].
	 */
	public static function other_language( array $clicks, string $site ): ?array {
		$by_lang = array();
		$total   = 0;
		foreach ( $clicks as $country => $n ) {
			$lang   = self::COUNTRY_LANGUAGE[ strtolower( (string) $country ) ] ?? '';
			$total += (int) $n;
			if ( '' !== $lang && $lang !== $site ) {
				$by_lang[ $lang ] = ( $by_lang[ $lang ] ?? 0 ) + (int) $n;
			}
		}
		if ( $total < 1 || ! $by_lang ) {
			return null;
		}
		arsort( $by_lang );
		$lang = (string) array_key_first( $by_lang );
		$n    = (int) $by_lang[ $lang ];
		if ( $n < self::OTHER_LANGUAGE_CLICKS || $n / $total < self::OTHER_LANGUAGE_SHARE ) {
			return null;
		}

		return array( $lang, $n / $total );
	}

	/**
	 * Does the site already publish in several languages (Tranzly, WPML, Polylang, TranslatePress)?
	 *
	 * @return bool
	 */
	public static function multilingual(): bool {
		return defined( 'TRANZLY_VERSION' ) || defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) || defined( 'TRP_PLUGIN_VERSION' )
			|| Offers::plugin_active( array( 'tranzly/tranzly.php', 'tranzly-premium/tranzly.php' ) );
	}

	/**
	 * Clicks by country over the last 28 days, from Search Console (the set the Search screen
	 * keeps for 12 hours; read again at most that often, within three seconds, and only when
	 * Search Console is connected).
	 *
	 * @return array<string, int>
	 */
	public static function kept_country_clicks(): array {
		$data   = '\ZinnDigital\ZinnSEO\Gsc\Data';
		$google = '\ZinnDigital\ZinnSEO\Gsc\Google';
		if ( ! class_exists( $data ) || ! class_exists( $google ) || ! call_user_func( array( $google, 'has' ), 'search_console' ) ) {
			return array();
		}
		list( $from, $to ) = call_user_func( array( $data, 'window' ), 28 );
		$set               = call_user_func(
			array( $data, 'set' ),
			array(
				'startDate'  => $from,
				'endDate'    => $to,
				'dimensions' => array( 'country' ),
			),
			microtime( true ) + 3.0
		);
		$out               = array();
		if ( is_array( $set ) ) {
			foreach ( call_user_func( array( $data, 'named' ), (array) $set['rows'], array( 'country' ) ) as $row ) {
				$out[ (string) $row['country'] ] = ( $out[ (string) $row['country'] ] ?? 0 ) + (int) $row['clicks'];
			}
		}

		return $out;
	}

	/**
	 * A language's name in the reader's language when PHP knows it, else its code. Pure-ish.
	 *
	 * @param string $lang Two-letter code.
	 * @return string
	 */
	public static function language_name( string $lang ): string {
		if ( class_exists( '\Locale' ) ) {
			$name = (string) \Locale::getDisplayLanguage( $lang, (string) determine_locale() );
			if ( '' !== $name && $name !== $lang ) {
				return $name;
			}
		}

		return strtoupper( $lang );
	}

	/**
	 * Add "many urgent fixes" after the other sources ran (it counts their issues).
	 *
	 * @param array<int, array<string, mixed>> $issues Every issue.
	 * @return array<string, mixed>|null
	 */
	public static function many_fixes( array $issues ): ?array {
		$urgent = count( array_filter( $issues, static fn( $i ) => (int) ( $i['priority'] ?? 0 ) >= 75 ) );
		if ( $urgent < self::MANY_URGENT ) {
			return null;
		}

		return array(
			'id'       => 'offers-many-fixes',
			'area'     => 'site',
			'priority' => 76,
			/* translators: %d: number of urgent problems. */
			'title'    => sprintf( __( '%d problems need fixing first', 'zinn-seo' ), $urgent ),
			'detail'   => __( 'Work through the list from the top, one fix at a time — or have an expert do it for you.', 'zinn-seo' ),
			'count'    => $urgent,
			'minutes'  => 0,
			'fix'      => null,
		);
	}

	/**
	 * The main language of each country with meaningful search traffic (ISO 3166 alpha-3, as
	 * Search Console reports it, and alpha-2).
	 */
	public const COUNTRY_LANGUAGE = array(
		'usa' => 'en',
		'us'  => 'en',
		'gbr' => 'en',
		'gb'  => 'en',
		'irl' => 'en',
		'ie'  => 'en',
		'aus' => 'en',
		'au'  => 'en',
		'nzl' => 'en',
		'nz'  => 'en',
		'can' => 'en',
		'ca'  => 'en',
		'zaf' => 'en',
		'za'  => 'en',
		'ind' => 'en',
		'in'  => 'en',
		'sgp' => 'en',
		'sg'  => 'en',
		'phl' => 'en',
		'ph'  => 'en',
		'nga' => 'en',
		'ng'  => 'en',
		'deu' => 'de',
		'de'  => 'de',
		'aut' => 'de',
		'at'  => 'de',
		'che' => 'de',
		'ch'  => 'de',
		'fra' => 'fr',
		'fr'  => 'fr',
		'bel' => 'fr',
		'be'  => 'fr',
		'lux' => 'fr',
		'lu'  => 'fr',
		'esp' => 'es',
		'es'  => 'es',
		'mex' => 'es',
		'mx'  => 'es',
		'arg' => 'es',
		'ar'  => 'es',
		'col' => 'es',
		'co'  => 'es',
		'chl' => 'es',
		'cl'  => 'es',
		'per' => 'es',
		'pe'  => 'es',
		'ita' => 'it',
		'it'  => 'it',
		'prt' => 'pt',
		'pt'  => 'pt',
		'bra' => 'pt',
		'br'  => 'pt',
		'nld' => 'nl',
		'nl'  => 'nl',
		'pol' => 'pl',
		'pl'  => 'pl',
		'swe' => 'sv',
		'se'  => 'sv',
		'nor' => 'nb',
		'no'  => 'nb',
		'dnk' => 'da',
		'dk'  => 'da',
		'fin' => 'fi',
		'fi'  => 'fi',
		'tur' => 'tr',
		'tr'  => 'tr',
		'rus' => 'ru',
		'ru'  => 'ru',
		'ukr' => 'uk',
		'ua'  => 'uk',
		'cze' => 'cs',
		'cz'  => 'cs',
		'rou' => 'ro',
		'ro'  => 'ro',
		'hun' => 'hu',
		'hu'  => 'hu',
		'grc' => 'el',
		'gr'  => 'el',
		'jpn' => 'ja',
		'jp'  => 'ja',
		'kor' => 'ko',
		'kr'  => 'ko',
		'chn' => 'zh',
		'cn'  => 'zh',
		'twn' => 'zh',
		'tw'  => 'zh',
		'idn' => 'id',
		'id'  => 'id',
		'vnm' => 'vi',
		'vn'  => 'vi',
		'tha' => 'th',
		'th'  => 'th',
		'sau' => 'ar',
		'sa'  => 'ar',
		'are' => 'ar',
		'ae'  => 'ar',
		'egy' => 'ar',
		'eg'  => 'ar',
		'isr' => 'he',
		'il'  => 'he',
		'irn' => 'fa',
		'ir'  => 'fa',
	);
}
