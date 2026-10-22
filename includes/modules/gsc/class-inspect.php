<?php
/**
 * Google's own index verdict for each page, from the site's Search Console (zs-083).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Gsc;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console's URL Inspection answers what Google itself thinks of ONE page: indexed or not,
 * and why. It is free, and Google allows 2,000 a day per property — so the whole-site check
 * spends them on the pages that matter first (most seen in search, then the newest), stops for the
 * day when the 2,000 are used, and carries on by itself the next day. Nothing is skipped and
 * nothing is checked twice in a week.
 *
 * Each answer is kept on its post (`_zseo_gsc_index`, one small array) so the editor, the content
 * list and this screen read it with no extra query; pages that are not posts (home, archives) are
 * kept in `zseo_gsc_index_other`.
 */
final class Inspect {

	/** Post meta holding the last answer. */
	public const META = '_zseo_gsc_index';

	/** Post meta holding only the verdict (to list pages by it). */
	public const VERDICT = '_zseo_gsc_verdict';

	/** Option for addresses that are not posts. */
	public const OTHER = 'zseo_gsc_index_other';

	/** Option: today's count. */
	public const BUDGET = 'zseo_gsc_inspect_budget';

	/** Google's daily allowance per property. */
	public const PER_DAY = 2000;

	/** A page checked within this many seconds is not checked again by the whole-site job. */
	public const FRESH = WEEK_IN_SECONDS;

	/** Job id. */
	public const JOB = 'gsc-inspect';

	/**
	 * Inspections left today (Google's day is Pacific time; ours is UTC, which is stricter for half
	 * the day and never over).
	 *
	 * @param int|null $now Timestamp (tests).
	 * @return int
	 */
	public static function left( ?int $now = null ): int {
		$b = (array) get_option( self::BUDGET, array() );

		return gmdate( 'Y-m-d', $now ?? time() ) === ( $b['day'] ?? '' ) ? max( 0, self::PER_DAY - (int) ( $b['used'] ?? 0 ) ) : self::PER_DAY;
	}

	/**
	 * Count one inspection against today.
	 *
	 * @return void
	 */
	private static function spend(): void {
		$day = gmdate( 'Y-m-d' );
		$b   = (array) get_option( self::BUDGET, array() );
		$b   = array(
			'day'  => $day,
			'used' => ( ( $b['day'] ?? '' ) === $day ? (int) ( $b['used'] ?? 0 ) : 0 ) + 1,
		);
		update_option( self::BUDGET, $b, false );
	}

	/**
	 * Ask Google about one page and keep the answer.
	 *
	 * @param string $url A page of this site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function page( string $url ) {
		if ( self::left() < 1 ) {
			return new \WP_Error( 'zinn_seo_inspect_day', __( 'Google allows 2,000 page checks a day for each site, and today\'s are used. They come back tomorrow; the whole-site check carries on by itself.', 'zinn-seo' ), array( 'status' => 429 ) );
		}
		$language = str_replace( '_', '-', (string) get_locale() );
		$answer   = Google::call(
			'POST',
			'/url-inspection',
			array(
				'url'      => $url,
				'language' => preg_match( '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $language ) ? $language : 'en-US',
			)
		);
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		self::spend();
		$kept = self::summary( $answer ) + array( 'at' => time() );
		self::keep( $url, $kept );

		return $kept;
	}

	/**
	 * The fields kept from Google's answer. Pure.
	 *
	 * @param array<string, mixed> $answer The API's answer.
	 * @return array<string, mixed>
	 */
	public static function summary( array $answer ): array {
		$out = array();
		foreach ( array( 'url', 'verdict', 'coverage', 'indexing', 'robots', 'fetch', 'last_crawl', 'google_canonical', 'user_canonical', 'crawled_as', 'mobile', 'rich_results', 'link' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $answer[ $k ] ?? '' ) );
		}
		$out['indexed'] = 'PASS' === $out['verdict'];
		// Google's own canonical differs from the page's: worth saying, it is often why a page is out.
		$out['canonical_differs'] = '' !== $out['google_canonical'] && '' !== $out['user_canonical'] && untrailingslashit( $out['google_canonical'] ) !== untrailingslashit( $out['user_canonical'] );

		return $out;
	}

	/**
	 * Keep an answer on its post, or with the other addresses.
	 *
	 * @param string               $url  Address.
	 * @param array<string, mixed> $kept Answer.
	 * @return void
	 */
	public static function keep( string $url, array $kept ): void {
		$id = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $url );
		if ( $id > 0 ) {
			update_post_meta( $id, self::META, $kept );
			update_post_meta( $id, self::VERDICT, (string) $kept['verdict'] );
			return;
		}
		$other         = (array) get_option( self::OTHER, array() );
		$other[ $url ] = $kept;
		if ( count( $other ) > 500 ) {
			// Home and archive addresses only; the oldest answer goes first.
			uasort( $other, static fn( $a, $b ) => (int) $a['at'] <=> (int) $b['at'] );
			$other = array_slice( $other, -500, null, true );
		}
		update_option( self::OTHER, $other, false );
	}

	/**
	 * The last answer for a page, or null.
	 *
	 * @param string $url Address.
	 * @return array<string, mixed>|null
	 */
	public static function last( string $url ): ?array {
		$id = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $url );
		if ( $id > 0 ) {
			$m = get_post_meta( $id, self::META, true );
			return is_array( $m ) ? $m : null;
		}
		$other = (array) get_option( self::OTHER, array() );

		return isset( $other[ $url ] ) && is_array( $other[ $url ] ) ? $other[ $url ] : null;
	}

	/**
	 * Counts of the kept answers by Google's coverage state, and the pages that are not indexed.
	 *
	 * @param int $per Rows of not-indexed pages.
	 * @param int $page Page of them.
	 * @return array<string, mixed>
	 */
	public static function report( int $per = 50, int $page = 1 ): array {
		global $wpdb;
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS verdict, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::VERDICT ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a count over the plugin's own meta, on its own screen.
		$by     = array();
		foreach ( (array) $counts as $c ) {
			$by[ (string) $c['verdict'] ] = (int) $c['n'];
		}
		$ids  = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => max( 1, min( 200, $per ) ),
				'paged'          => max( 1, $page ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the plugin's own key, on its own screen.
					array(
						'key'     => self::VERDICT,
						'value'   => 'PASS',
						'compare' => '!=',
					),
				),
				'orderby'        => 'modified',
				'no_found_rows'  => false,
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			$m = get_post_meta( (int) $id, self::META, true );
			if ( is_array( $m ) ) {
				$rows[] = $m + array(
					'post_id' => (int) $id,
					'title'   => get_the_title( (int) $id ),
					'edit'    => (string) get_edit_post_link( (int) $id, 'raw' ),
				);
			}
		}
		$job = Jobs::get( self::JOB );

		return array(
			'counts'  => $by,
			'checked' => array_sum( $by ),
			'out'     => $rows,
			'left'    => self::left(),
			'per_day' => self::PER_DAY,
			'job'     => array(
				'status'   => (string) ( $job['status'] ?? 'idle' ),
				'progress' => (array) ( $job['progress'] ?? array() ),
				'error'    => (string) ( $job['error'] ?? '' ),
			),
		);
	}

	/**
	 * The whole-site check, one step: first the pages seen in search (most impressions first), then
	 * every other published page by id (keyset paging — constant time at a million posts), within
	 * today's allowance. When the allowance is used the job waits for tomorrow (it stays pending).
	 *
	 * ⛔ The state is a CURSOR, never the list of every address: a million-page site's whole list
	 * in one option would be tens of megabytes read and written on every step.
	 *
	 * @param array<string, mixed> $state    `phase` (seen|posts), `seen` (search's pages), `i`, `after`, `done`.
	 * @param float                $deadline Microtime to stop.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		if ( ! isset( $state['phase'] ) ) {
			$state = array(
				'phase' => 'seen',
				'seen'  => self::seen(),
				'i'     => 0,
				'after' => 0,
				'done'  => 0,
				'total' => self::count_pages(),
			);
		}
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$url = self::next( $state );
			if ( '' === $url ) {
				return self::answer( $state, true );
			}
			if ( self::left() < 1 ) {
				self::back( $state );
				return self::answer( $state, false, array( 'wait' => max( 60, (int) strtotime( 'tomorrow', time() ) - time() + 60 ) ) );
			}
			$res = self::page( $url );
			if ( is_wp_error( $res ) ) {
				$code = strtolower( (string) $res->get_error_code() );
				if ( false !== strpos( $code, 'vendorbusy' ) || false !== strpos( $code, 'vendorunavailable' ) ) {
					self::back( $state );
					return self::answer( $state, false, array( 'wait' => 300 ) );
				}
				if ( false !== strpos( $code, 'needsreconnect' ) || false !== strpos( $code, 'notconnected' ) || false !== strpos( $code, 'propertynotchosen' ) || false !== strpos( $code, 'not_connected' ) ) {
					self::back( $state );
					return self::answer( $state, false, array( 'pause' => $res->get_error_message() ) );
				}
			}
			++$state['done'];
		}

		return self::answer( $state, false );
	}

	/**
	 * A step's answer.
	 *
	 * @param array<string, mixed> $state State.
	 * @param bool                 $done  Finished.
	 * @param array<string, mixed> $extra wait / pause.
	 * @return array<string, mixed>
	 */
	private static function answer( array $state, bool $done, array $extra = array() ): array {
		return array(
			'state'    => $state,
			'done'     => $done,
			'progress' => array(
				'done'  => (int) $state['done'],
				'total' => (int) $state['total'],
			),
		) + $extra;
	}

	/**
	 * The next address to check, advancing the cursor; '' when there is none.
	 *
	 * @param array<string, mixed> $state State (changed).
	 * @return string
	 */
	public static function next( array &$state ): string {
		if ( 'seen' === $state['phase'] ) {
			$total = count( $state['seen'] );
			while ( $state['i'] < $total ) {
				$url = (string) $state['seen'][ $state['i'] ];
				++$state['i'];
				$m = self::last( $url );
				if ( null === $m || (int) ( $m['at'] ?? 0 ) <= time() - self::FRESH ) {
					$state['last'] = array( 'seen', $state['i'] - 1 );
					return $url;
				}
			}
			$state['phase'] = 'posts';
		}
		global $wpdb;
		$types       = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$in          = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		static $flip = null;
		if ( null === $flip || count( $flip ) !== count( (array) $state['seen'] ) ) {
			$flip = array_flip( (array) $state['seen'] );
		}
		$seen = $flip;
		while ( true ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($in) AND ID > %d ORDER BY ID ASC LIMIT 200", array_merge( $types, array( (int) $state['after'] ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built for the type list.
			if ( array() === $ids ) {
				return '';
			}
			update_meta_cache( 'post', array_map( 'intval', $ids ) );
			foreach ( $ids as $id ) {
				$before         = (int) $state['after'];
				$state['after'] = (int) $id;
				$m              = get_post_meta( (int) $id, self::META, true );
				if ( is_array( $m ) && (int) ( $m['at'] ?? 0 ) > time() - self::FRESH ) {
					continue;
				}
				$url = (string) get_permalink( (int) $id );
				if ( '' !== $url && ! isset( $seen[ $url ] ) ) {
					$state['last'] = array( 'posts', $before );
					return $url;
				}
			}
		}
	}

	/**
	 * Put the last address back (it was not checked).
	 *
	 * @param array<string, mixed> $state State (changed).
	 * @return void
	 */
	private static function back( array &$state ): void {
		$last = (array) ( $state['last'] ?? array() );
		if ( 'seen' === ( $last[0] ?? '' ) ) {
			$state['phase'] = 'seen';
			$state['i']     = (int) $last[1];
		} elseif ( 'posts' === ( $last[0] ?? '' ) ) {
			$state['after'] = (int) $last[1];
		}
	}

	/**
	 * The pages seen in Google search over 90 days, most impressions first.
	 *
	 * @return array<int, string>
	 */
	public static function seen(): array {
		list( $from, $to ) = Data::window( 90 );
		$set               = Data::set(
			array(
				'startDate'  => $from,
				'endDate'    => $to,
				'dimensions' => array( 'page' ),
			),
			microtime( true ) + 15.0
		);
		if ( is_wp_error( $set ) ) {
			return array();
		}
		$rows = Data::named( $set['rows'], array( 'page' ) );
		usort( $rows, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );

		return array_values( array_map( static fn( $r ) => (string) $r['page'], $rows ) );
	}

	/**
	 * Published pages of public types (the job's total).
	 *
	 * @return int
	 */
	public static function count_pages(): int {
		$n = 0;
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$n += (int) ( wp_count_posts( (string) $type )->publish ?? 0 );
		}

		return $n;
	}
}
