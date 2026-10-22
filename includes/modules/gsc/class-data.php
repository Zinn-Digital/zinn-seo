<?php
/**
 * Search Console data for this site: kept answers, paged to the end, and the summaries the
 * screens show (zs-090).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Gsc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ NO CAP ON THE WORK (§2.10): a data set is read page after page (`startRow`, 25,000 rows a
 * page — Google's own page size) until Google says there is no more. A big site's set can need
 * more than one request's worth of time, so the read keeps its cursor in the kept set and the
 * next call (the screen asking again, or the background job) carries on from there; the screen
 * says "still reading" meanwhile. The only ceiling is Google's own (it keeps 16 months, and its
 * API returns at most its top rows a day).
 *
 * A set is kept 12 hours in a non-autoloaded option (`zseo_gsc_ds_<hash>`), compressed: one row
 * is `[key, key…, clicks, impressions, position]`. Search Console's figures settle after about
 * two days, so windows end three days ago unless a caller asks for fresh hour-by-hour data.
 *
 * ⛔ Rows are confined to THIS site's addresses: a `sc-domain:` property also holds the owner's
 * other subdomains, and a page of `shop.example.com` is not a page of `example.com`.
 */
final class Data {

	/** Option name prefix of a kept data set. */
	public const PREFIX = 'zseo_gsc_ds_';

	/** Index of kept sets (so they can all be forgotten). */
	public const INDEX = 'zseo_gsc_ds_index';

	/** Seconds a complete set is kept. */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/** Rows per request (Google's ceiling). */
	public const PAGE = 25000;

	/** Days Search Console needs before a day's figures settle. */
	public const LAG = 3;

	/**
	 * Replaces the API call in tests: `function ( array $query ): array|\WP_Error`.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/**
	 * A date window: `$days` days ending `self::LAG + $offset` days ago. Pure but for the clock.
	 *
	 * @param int      $days   Length.
	 * @param int      $offset Days earlier still (the previous period: `$days`).
	 * @param int|null $now    Timestamp (tests).
	 * @return array{0: string, 1: string}
	 */
	public static function window( int $days, int $offset = 0, ?int $now = null ): array {
		$now = $now ?? time();
		$end = $now - ( self::LAG + $offset ) * DAY_IN_SECONDS;

		return array( gmdate( 'Y-m-d', $end - ( $days - 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $end ) );
	}

	/**
	 * The filter that keeps a property's rows to this site's own addresses.
	 *
	 * @param string $home The site's home address.
	 * @return array<string, mixed>
	 */
	public static function site_filter( string $home ): array {
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $home, PHP_URL_PATH );
		$bare = preg_replace( '/^www\./', '', $host );

		return array(
			'dimension'  => 'page',
			'operator'   => 'includingRegex',
			'expression' => '^https?://(www\\.)?' . preg_quote( (string) $bare, '' ) . preg_quote( rtrim( $path, '/' ), '' ) . '(/|$|\\?)',
		);
	}

	/**
	 * The site's public address: the one it is connected to Zinn® as (the same as its home address,
	 * except behind a proxy or on a copy proving against the live site's data).
	 *
	 * @return string
	 */
	public static function site(): string {
		return class_exists( '\ZinnDigital\ZinnSEO\Connect\Connection' ) ? \ZinnDigital\ZinnSEO\Connect\Connection::site_url() : home_url( '/' );
	}

	/**
	 * A query with this site's filter added.
	 *
	 * @param array<string, mixed> $query Google's query fields.
	 * @return array<string, mixed>
	 */
	public static function scoped( array $query ): array {
		$filters = array( self::site_filter( self::site() ) );
		foreach ( (array) ( $query['filters'] ?? array() ) as $f ) {
			$filters[] = $f;
		}
		unset( $query['filters'] );
		$query['dimensionFilterGroups'] = array( array( 'filters' => $filters ) );

		return $query;
	}

	/**
	 * One page of rows from Google.
	 *
	 * @param array<string, mixed> $query Query (already scoped).
	 * @return array{rows: array<int, array<string, mixed>>, more: bool}|\WP_Error
	 */
	public static function page( array $query ) {
		$answer = null !== self::$transport ? call_user_func( self::$transport, $query ) : Google::call( 'POST', '/search-analytics', $query );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return array(
			'rows' => array_values( array_filter( (array) ( $answer['rows'] ?? array() ), 'is_array' ) ),
			'more' => ! empty( $answer['more'] ),
		);
	}

	/**
	 * A data set: every row of a query, read page by page, kept for 12 hours.
	 *
	 * @param array<string, mixed> $query    Google's fields: startDate, endDate, dimensions, filters, type, dataState.
	 * @param float                $deadline Stop reading at this microtime and carry on next time.
	 * @param bool                 $fresh    Read again even when a kept set is younger than 12 hours.
	 * @return array{rows: array<int, array<int, mixed>>, complete: bool, at: int, dims: array<int, string>}|\WP_Error
	 */
	public static function set( array $query, float $deadline = 0.0, bool $fresh = false ) {
		$query    = self::scoped( $query );
		$deadline = $deadline > 0 ? $deadline : microtime( true ) + 15.0;
		$dims     = array_values( array_map( 'strval', (array) ( $query['dimensions'] ?? array() ) ) );
		$key      = self::PREFIX . substr( md5( (string) wp_json_encode( $query ) ), 0, 16 );
		$kept     = self::read( $key );
		if ( $fresh || null === $kept || ( $kept['complete'] && $kept['at'] < time() - self::TTL ) ) {
			$kept = array(
				'rows'     => array(),
				'next'     => 0,
				'complete' => false,
				'at'       => time(),
				'dims'     => $dims,
			);
		}
		while ( ! $kept['complete'] && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$page = self::page( $query + array( 'rowLimit' => self::PAGE, 'startRow' => (int) $kept['next'] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one line reads as the API call it is.
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['rows'] as $row ) {
				$kept['rows'][] = array_merge(
					array_map( 'strval', (array) ( $row['keys'] ?? array() ) ),
					array( (int) ( $row['clicks'] ?? 0 ), (int) ( $row['impressions'] ?? 0 ), round( (float) ( $row['position'] ?? 0 ), 2 ) )
				);
			}
			$kept['next']    += count( $page['rows'] );
			$kept['complete'] = ! $page['more'] || array() === $page['rows'];
			$kept['at']       = time();
		}
		self::write( $key, $kept );

		return array(
			'rows'     => $kept['rows'],
			'complete' => (bool) $kept['complete'],
			'at'       => (int) $kept['at'],
			'dims'     => $dims,
		);
	}

	/**
	 * Rows of a set as named records. Pure.
	 *
	 * @param array<int, array<int, mixed>> $rows Compact rows.
	 * @param array<int, string>            $dims Dimension names, in order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function named( array $rows, array $dims ): array {
		$n   = count( $dims );
		$out = array();
		foreach ( $rows as $row ) {
			$rec = array();
			foreach ( $dims as $i => $d ) {
				$rec[ $d ] = (string) $row[ $i ];
			}
			$rec['clicks']      = (int) $row[ $n ];
			$rec['impressions'] = (int) $row[ $n + 1 ];
			$rec['position']    = (float) $row[ $n + 2 ];
			$rec['ctr']         = $rec['impressions'] > 0 ? $rec['clicks'] / $rec['impressions'] : 0.0;
			$out[]              = $rec;
		}

		return $out;
	}

	/**
	 * Totals of named rows: clicks, impressions, CTR, and position weighted by impressions (how
	 * Search Console itself averages). Pure.
	 *
	 * @param array<int, array<string, mixed>> $rows Named rows.
	 * @return array{clicks: int, impressions: int, ctr: float, position: float}
	 */
	public static function totals( array $rows ): array {
		$clicks = 0;
		$impr   = 0;
		$weight = 0.0;
		foreach ( $rows as $r ) {
			$clicks += (int) $r['clicks'];
			$impr   += (int) $r['impressions'];
			$weight += (float) $r['position'] * (int) $r['impressions'];
		}

		return array(
			'clicks'      => $clicks,
			'impressions' => $impr,
			'ctr'         => $impr > 0 ? $clicks / $impr : 0.0,
			'position'    => $impr > 0 ? round( $weight / $impr, 1 ) : 0.0,
		);
	}

	/**
	 * The overview: this period and the one before, day by day, and the top pages and searches.
	 *
	 * @param int  $days  28 or 90.
	 * @param bool $fresh Read again.
	 * @param int  $top   Rows in each top list (the full lists are Pro's).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function overview( int $days = 28, bool $fresh = false, int $top = 10 ) {
		$days                = in_array( $days, array( 7, 28, 90, 180, 365 ), true ) ? $days : 28;
		list( $from, $to )   = self::window( $days );
		list( $pfrom, $pto ) = self::window( $days, $days );
		$deadline            = microtime( true ) + 20.0;
		$daily               = self::set(
			array(
				'startDate'  => $pfrom,
				'endDate'    => $to,
				'dimensions' => array( 'date' ),
			),
			$deadline,
			$fresh
		);
		if ( is_wp_error( $daily ) ) {
			return $daily;
		}
		$cur  = array();
		$prev = array();
		foreach ( self::named( $daily['rows'], array( 'date' ) ) as $r ) {
			if ( $r['date'] >= $from ) {
				$cur[] = $r;
			} elseif ( $r['date'] <= $pto ) {
				$prev[] = $r;
			}
		}
		usort( $cur, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		$lists = array();
		foreach ( array( 'page', 'query' ) as $dim ) {
			$set = self::set(
				array(
					'startDate'  => $from,
					'endDate'    => $to,
					'dimensions' => array( $dim ),
				),
				$deadline,
				$fresh
			);
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			$rows = self::named( $set['rows'], array( $dim ) );
			usort( $rows, static fn( $a, $b ) => array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] ) );
			$lists[ $dim ] = array(
				'rows'     => array_slice( $rows, 0, $top ),
				'total'    => count( $rows ),
				'complete' => $set['complete'],
			);
		}

		return array(
			'from'     => $from,
			'to'       => $to,
			'days'     => $days,
			'totals'   => self::totals( $cur ),
			'previous' => self::totals( $prev ),
			'daily'    => $cur,
			'pages'    => $lists['page'],
			'queries'  => $lists['query'],
			'complete' => $daily['complete'] && $lists['page']['complete'] && $lists['query']['complete'],
			'at'       => (int) $daily['at'],
		);
	}

	/**
	 * A kept set.
	 *
	 * @param string $key Option name.
	 * @return array<string, mixed>|null
	 */
	private static function read( string $key ): ?array {
		$raw = get_option( $key );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$plain = function_exists( 'gzuncompress' ) ? @gzuncompress( (string) base64_decode( $raw, true ) ) : base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- our own stored data; a broken value reads as absent.
		$data  = is_string( $plain ) ? json_decode( $plain, true ) : null;

		return is_array( $data ) && isset( $data['rows'], $data['next'], $data['complete'], $data['at'] ) ? $data : null;
	}

	/**
	 * Keep a set.
	 *
	 * @param string               $key  Option name.
	 * @param array<string, mixed> $data The set.
	 * @return void
	 */
	private static function write( string $key, array $data ): void {
		$json = (string) wp_json_encode( $data );
		$raw  = base64_encode( function_exists( 'gzcompress' ) ? (string) gzcompress( $json, 6 ) : $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- compressed bytes stored as text.
		update_option( $key, $raw, false );
		$index         = (array) get_option( self::INDEX, array() );
		$index[ $key ] = time();
		update_option( self::INDEX, $index, false );
	}

	/**
	 * Forget every kept set (another property chosen, Google disconnected, uninstall).
	 *
	 * @return void
	 */
	public static function forget(): void {
		foreach ( array_keys( (array) get_option( self::INDEX, array() ) ) as $key ) {
			if ( 0 === strpos( (string) $key, self::PREFIX ) ) {
				delete_option( (string) $key );
			}
		}
		delete_option( self::INDEX );
	}

	/**
	 * Drop sets nobody has refreshed for a week (the index never grows without bound).
	 *
	 * @return void
	 */
	public static function prune(): void {
		$index = (array) get_option( self::INDEX, array() );
		foreach ( $index as $key => $at ) {
			if ( (int) $at < time() - WEEK_IN_SECONDS ) {
				delete_option( (string) $key );
				unset( $index[ $key ] );
			}
		}
		update_option( self::INDEX, $index, false );
	}
}
