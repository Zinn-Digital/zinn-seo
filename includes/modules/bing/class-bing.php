<?php
/**
 * Bing Webmaster Tools in WordPress, with the site owner's own API key (zs-090, zs-092).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bing;

use ZinnDigital\ZinnSEO\AdminKit\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bing gives every Webmaster Tools user an API key (Settings → API access). The key is the owner's
 * and is kept on this site only, sealed with a key derived from the site's own salts
 * (`zseo_bing`): this plugin calls Bing directly from the server, nothing passes through Zinn®.
 *
 * Read: the site's clicks and impressions by day, by search and by page; the sitemaps Bing knows.
 * Written: the sitemap (on connect and whenever its address changes). Answers are kept 6 hours.
 *
 * External service: Microsoft Bing Webmaster API, https://ssl.bing.com/webmaster/api.svc — only
 * when the site owner pastes their key (terms: https://www.microsoft.com/servicesagreement,
 * privacy: https://privacy.microsoft.com/privacystatement).
 */
final class Bing {

	/** Option: the sealed key and the chosen site. */
	public const OPTION = 'zseo_bing';

	/** Option prefix: kept answers. */
	public const CACHE = 'zseo_bing_c_';

	/** API base. */
	public const BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';

	/** Seconds an answer is kept. */
	public const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Replaces the HTTP call in tests: `function ( string $method, string $url, array $args ): array|\WP_Error`.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/**
	 * The stored row.
	 *
	 * @return array{key: string, site: string, at: int}
	 */
	public static function row(): array {
		$row = (array) get_option( self::OPTION, array() );

		return array(
			'key'  => (string) ( $row['key'] ?? '' ),
			'site' => (string) ( $row['site'] ?? '' ),
			'at'   => (int) ( $row['at'] ?? 0 ),
		);
	}

	/**
	 * The owner's key, or ''.
	 *
	 * @return string
	 */
	public static function key(): string {
		$sealed = self::row()['key'];

		return '' === $sealed ? '' : (string) Crypto::open( $sealed );
	}

	/**
	 * Connected (a key that worked, and a site chosen).
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return '' !== self::key() && '' !== self::row()['site'];
	}

	/**
	 * One call to Bing.
	 *
	 * @param string                    $method  GET|POST.
	 * @param string                    $name    API method, e.g. GetUserSites.
	 * @param array<string, string>     $query   Query arguments.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param string                    $key     A key to use instead of the stored one (connecting).
	 * @return mixed|\WP_Error Bing's `d` value.
	 */
	public static function call( string $method, string $name, array $query = array(), ?array $body = null, string $key = '' ) {
		$key = '' !== $key ? $key : self::key();
		if ( '' === $key ) {
			return new \WP_Error( 'zinn_seo_bing_key', __( 'Paste your Bing Webmaster Tools API key first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$url  = self::BASE . rawurlencode( $name ) . '?' . http_build_query( $query + array( 'apikey' => $key ), '', '&', PHP_QUERY_RFC3986 );
		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'        => null === $body ? null : wp_json_encode( $body ),
		);
		$res  = null !== self::$transport ? call_user_func( self::$transport, $method, $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'zinn_seo_bing_unreachable', __( 'This site could not reach Bing. Try again in a few minutes.', 'zinn-seo' ), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$json   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $status >= 200 && $status < 300 ) {
			return is_array( $json ) && array_key_exists( 'd', $json ) ? $json['d'] : $json;
		}
		$message = is_array( $json ) ? (string) ( $json['Message'] ?? '' ) : '';
		if ( 401 === $status || false !== stripos( $message, 'InvalidApiKey' ) || false !== stripos( $message, 'api key' ) ) {
			return new \WP_Error( 'zinn_seo_bing_key', __( 'Bing did not accept that API key. Copy it again from Bing Webmaster Tools → Settings → API access.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		if ( false !== stripos( $message, 'NotAuthorized' ) || false !== stripos( $message, 'not verified' ) || 403 === $status ) {
			return new \WP_Error( 'zinn_seo_bing_site', __( 'This site is not verified in the Bing account that key belongs to. Add and verify it in Bing Webmaster Tools first.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		if ( 429 === $status || false !== stripos( $message, 'quota' ) || false !== stripos( $message, 'ThrottleUser' ) ) {
			return new \WP_Error( 'zinn_seo_bing_busy', __( 'Bing asked us to slow down. Try again later today.', 'zinn-seo' ), array( 'status' => 429 ) );
		}

		return new \WP_Error( 'zinn_seo_bing', __( 'Bing could not answer just now. Try again in a few minutes.', 'zinn-seo' ), array( 'status' => 502 ) );
	}

	/**
	 * The Bing site entry for this site among the account's sites. Pure.
	 *
	 * @param array<int, mixed> $sites Bing's GetUserSites answer.
	 * @param string            $home  This site's home address.
	 * @return string The matching `Url`, or ''.
	 */
	public static function match( array $sites, string $home ): string {
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
		$best = '';
		foreach ( $sites as $s ) {
			$url = is_array( $s ) ? (string) ( $s['Url'] ?? '' ) : '';
			$h   = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
			if ( '' !== $url && $h === $host ) {
				// A verified entry wins; then the one that is exactly this site's scheme and host.
				if ( '' === $best || ! empty( $s['IsVerified'] ) || 0 === strpos( $home, $url ) ) {
					$best = $url;
				}
			}
		}

		return $best;
	}

	/**
	 * Connect: check the key, find this site in the account, keep both, send the sitemap.
	 *
	 * @param string $key The owner's API key.
	 * @return array<string, mixed>|\WP_Error What `view()` returns.
	 */
	public static function connect( string $key ) {
		$key = preg_replace( '/[^A-Za-z0-9]/', '', $key );
		if ( strlen( (string) $key ) < 16 ) {
			return new \WP_Error( 'zinn_seo_bing_key', __( 'That does not look like a Bing API key. Copy it from Bing Webmaster Tools → Settings → API access.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		$sites = self::call( 'GET', 'GetUserSites', array(), null, (string) $key );
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		$site = self::match( (array) $sites, home_url( '/' ) );
		if ( '' === $site ) {
			$added = self::add_and_verify( (string) $key );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
			$site = $added;
		}
		update_option(
			self::OPTION,
			array(
				'key'  => Crypto::seal( (string) $key ),
				'site' => $site,
				'at'   => time(),
			),
			false
		);
		self::forget();
		self::submit( 'connect' );

		return self::view();
	}

	/**
	 * The site is not in the owner's Bing account yet: add it, prove it is theirs with Bing's own
	 * verification tag (printed in the page head, like every search engine's verification), and ask
	 * Bing to verify — so connecting Bing is one paste, not a trip through Bing's screens.
	 *
	 * @param string $key The owner's API key.
	 * @return string|\WP_Error The site's address in Bing.
	 */
	public static function add_and_verify( string $key ) {
		$home = home_url( '/' );
		$add  = self::call( 'POST', 'AddSite', array(), array( 'siteUrl' => $home ), $key );
		if ( is_wp_error( $add ) ) {
			return new \WP_Error( 'zinn_seo_bing_site', __( 'This site is not in that Bing Webmaster Tools account, and Bing would not let us add it. Add it in Bing Webmaster Tools (you can import it from Google Search Console in one click), then connect again.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$sites = self::call( 'GET', 'GetUserSites', array(), null, $key );
		$code  = '';
		foreach ( is_wp_error( $sites ) ? array() : (array) $sites as $s ) {
			if ( is_array( $s ) && self::match( array( $s ), $home ) !== '' ) {
				$code = sanitize_text_field( (string) ( $s['AuthenticationCode'] ?? '' ) );
			}
		}
		if ( '' !== $code && class_exists( '\ZinnDigital\ZinnSEO\Settings' ) ) {
			\ZinnDigital\ZinnSEO\Settings::save( array( 'verify_bing' => $code ) );
		}
		$ok = self::call( 'POST', 'VerifySite', array(), array( 'siteUrl' => $home ), $key );
		if ( is_wp_error( $ok ) || false === $ok ) {
			return new \WP_Error( 'zinn_seo_bing_unverified', __( 'This site was added to your Bing account, but Bing has not confirmed it yet. Wait a minute (a page cache may still show the old page), then connect again.', 'zinn-seo' ), array( 'status' => 409 ) );
		}

		return $home;
	}

	/**
	 * Disconnect: forget the key.
	 *
	 * @return array<string, mixed>
	 */
	public static function disconnect(): array {
		delete_option( self::OPTION );
		self::forget();

		return self::view();
	}

	/**
	 * Send the sitemap to Bing.
	 *
	 * @param string $why connect|notrace|manual.
	 * @return array<string, mixed> The outcome kept for the screen.
	 */
	public static function submit( string $why ): array {
		$sitemap = \ZinnDigital\ZinnSEO\Gsc\Sitemap_Submit::sitemap();
		if ( '' === $sitemap ) {
			return \ZinnDigital\ZinnSEO\Gsc\Sitemap_Submit::keep( 'bing', false, __( 'The sitemap is switched off, so there was nothing to send.', 'zinn-seo' ), '', $why );
		}
		$res = self::call(
			'POST',
			'SubmitFeed',
			array(),
			array(
				'siteUrl' => self::row()['site'],
				'feedUrl' => $sitemap,
			)
		);

		return \ZinnDigital\ZinnSEO\Gsc\Sitemap_Submit::keep( 'bing', ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '', $sitemap, $why );
	}

	/**
	 * The sitemap-submission filter: Bing joins every "send the sitemap" moment.
	 *
	 * @param array<string, array<string, mixed>> $out     Outcomes.
	 * @param string                              $sitemap Address.
	 * @param string                              $why     Why.
	 * @return array<string, array<string, mixed>>
	 */
	public static function on_submit( $out, $sitemap, $why ): array {
		unset( $sitemap );
		$out = (array) $out;
		if ( self::connected() ) {
			$out['bing'] = self::submit( (string) $why );
		}

		return $out;
	}

	/**
	 * Bing's `/Date(1700000000000)/` (or `/Date(…-0700)/`) as Y-m-d. Pure.
	 *
	 * @param string $value Bing's date.
	 * @return string
	 */
	public static function day( string $value ): string {
		return preg_match( '/Date\((-?\d+)/', $value, $m ) ? gmdate( 'Y-m-d', (int) floor( (int) $m[1] / 1000 ) ) : '';
	}

	/**
	 * A kept answer, or a fresh one.
	 *
	 * @param string                $name  API method.
	 * @param array<string, string> $query Arguments.
	 * @param bool                  $fresh Ask again.
	 * @return mixed|\WP_Error
	 */
	private static function kept( string $name, array $query, bool $fresh ) {
		$opt  = self::CACHE . substr( md5( $name . wp_json_encode( $query ) ), 0, 12 );
		$kept = get_option( $opt );
		if ( ! $fresh && is_array( $kept ) && (int) $kept['at'] > time() - self::TTL ) {
			return $kept['d'];
		}
		$d = self::call( 'GET', $name, $query );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		update_option(
			$opt,
			array(
				'at' => time(),
				'd'  => $d,
			),
			false
		);
		$index         = (array) get_option( self::CACHE . 'index', array() );
		$index[ $opt ] = time();
		update_option( self::CACHE . 'index', $index, false );

		return $d;
	}

	/**
	 * Forget kept answers.
	 *
	 * @return void
	 */
	public static function forget(): void {
		foreach ( array_keys( (array) get_option( self::CACHE . 'index', array() ) ) as $opt ) {
			delete_option( (string) $opt );
		}
		delete_option( self::CACHE . 'index' );
	}

	/**
	 * Bing's rows summed over `$days` days. Pure.
	 *
	 * @param array<int, mixed> $rows  Bing rows with `Date`, `Clicks`, `Impressions` (and a key field).
	 * @param string            $field The key field (Query) or '' for daily totals.
	 * @param int               $since Timestamp: rows before it are left out.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sum( array $rows, string $field, int $since ): array {
		$by = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$day = self::day( (string) ( $r['Date'] ?? '' ) );
			if ( '' === $day || strtotime( $day . ' UTC' ) < $since ) {
				continue;
			}
			$k = '' === $field ? $day : (string) ( $r[ $field ] ?? '' );
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = array(
					'key'         => $k,
					'clicks'      => 0,
					'impressions' => 0,
					'weight'      => 0.0,
				);
			}
			$by[ $k ]['clicks']      += (int) ( $r['Clicks'] ?? 0 );
			$by[ $k ]['impressions'] += (int) ( $r['Impressions'] ?? 0 );
			$by[ $k ]['weight']      += (float) ( $r['AvgImpressionPosition'] ?? 0 ) * (int) ( $r['Impressions'] ?? 0 );
		}
		$out = array();
		foreach ( $by as $row ) {
			$out[] = array(
				'key'         => $row['key'],
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'position'    => $row['impressions'] > 0 ? round( $row['weight'] / $row['impressions'], 1 ) : 0.0,
			);
		}
		usort( $out, static fn( $a, $b ) => '' === $field ? strcmp( $a['key'], $b['key'] ) : array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] ) );

		return $out;
	}

	/**
	 * What the screen shows: connected or not, totals, day by day, top searches and pages.
	 *
	 * @param int  $days  Days.
	 * @param bool $fresh Ask Bing again.
	 * @return array<string, mixed>
	 */
	public static function view( int $days = 28, bool $fresh = false ): array {
		$row  = self::row();
		$base = array(
			'connected' => self::connected(),
			'site'      => $row['site'],
			'since'     => $row['at'],
		);
		if ( ! $base['connected'] ) {
			return $base;
		}
		$since = time() - ( $days + 2 ) * DAY_IN_SECONDS;
		$site  = array( 'siteUrl' => $row['site'] );
		$out   = $base + array(
			'days'    => $days,
			'daily'   => array(),
			'queries' => array(),
			'pages'   => array(),
			'error'   => '',
		);
		$daily = self::kept( 'GetRankAndTrafficStats', $site, $fresh );
		if ( is_wp_error( $daily ) ) {
			$out['error'] = $daily->get_error_message();
			return $out;
		}
		$out['daily']   = self::sum( (array) $daily, '', $since );
		$queries        = self::kept( 'GetQueryStats', $site, $fresh );
		$pages          = self::kept( 'GetPageStats', $site, $fresh );
		$out['queries'] = is_wp_error( $queries ) ? array() : array_slice( self::sum( (array) $queries, 'Query', $since ), 0, 50 );
		$out['pages']   = is_wp_error( $pages ) ? array() : array_slice( self::sum( (array) $pages, 'Query', $since ), 0, 50 );
		$out['totals']  = array(
			'clicks'      => array_sum( array_column( $out['daily'], 'clicks' ) ),
			'impressions' => array_sum( array_column( $out['daily'], 'impressions' ) ),
		);
		$feeds          = self::kept( 'GetFeeds', $site, $fresh );
		$out['feeds']   = is_wp_error( $feeds ) ? array() : array_values(
			array_map(
				static fn( $f ) => array(
					'url'       => (string) ( $f['Url'] ?? '' ),
					'status'    => (string) ( $f['Status'] ?? '' ),
					'urls'      => (int) ( $f['UrlCount'] ?? 0 ),
					'submitted' => self::day( (string) ( $f['Submitted'] ?? '' ) ),
					'crawled'   => self::day( (string) ( $f['LastCrawled'] ?? '' ) ),
				),
				array_filter( (array) $feeds, 'is_array' )
			)
		);

		return $out;
	}
}
