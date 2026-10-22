<?php
/**
 * The Google index checker: is a page in Google's index — one page, a few, or the whole site.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Tools;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Credits\Credits;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A check is paid per page and answered in the background at Zinn®: the pages are sent (up to
 * 1,000 a call), the price is taken up front, and pages Google could not be asked about come
 * back "unknown" and refunded — the site pays for answers only.
 *
 * ⛔⛔ THE WHOLE SITE IS NEVER A SLICE (CLAUDE.md §2.10). "Check every page" is a background job
 * that walks the published pages by id (keyset, never an offset), a thousand at a time, until
 * there are none left: a site with 50 pages and one with a million both finish. It says what it
 * will cost before it starts. When Zinn® refuses a batch — no credit, the site's monthly limit,
 * an account on hold — the job PAUSES where it is with that reason, nothing is charged for the
 * batch, and it carries on from the same page when credit is back (`resume()`, called when
 * Zinn® says checks have resumed, or by hand).
 *
 * Each page's last answer is one small private field on the post (`_zseo_gindex`:
 * `1|0|?` + `:` + when), so the Content list can show it with no extra table; the last runs are
 * kept in one option that is not autoloaded.
 */
final class Index {

	/** The post field holding the last answer. */
	public const META = Meta::PREFIX . 'gindex';

	/** The last runs. */
	public const RUNS = 'zseo_index_runs';

	/** The whole-site job. */
	public const JOB = 'index-site';

	/** Pages per call (the API's own ceiling). */
	public const BATCH = 1000;

	/**
	 * Register the whole-site job.
	 *
	 * @return void
	 */
	public static function register(): void {
		Jobs::register( self::JOB, static fn(): string => __( 'Checking every page in Google\'s index', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * The content types whose pages a visitor can open.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$types = array_values( array_filter( $types, 'is_post_type_viewable' ) );

		/**
		 * Filters the content types "check every page" walks.
		 *
		 * @param array<int, string> $types Post type names.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'zinn_seo_index_check_types', $types ) ) );
	}

	/**
	 * How many published pages there are to check (one COUNT, for the price shown up front).
	 *
	 * @return int
	 */
	public static function total(): int {
		global $wpdb;
		$types = self::types();
		if ( ! $types ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one COUNT over the posts index; the placeholders are built from the count of types.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)", $types ) );
	}

	/**
	 * The next published pages after an id, in id order.
	 *
	 * @param int $after Last id handled.
	 * @param int $limit How many.
	 * @param int $until Highest id wanted (0: no bound).
	 * @return array<int, int>
	 */
	public static function ids_after( int $after, int $limit, int $until = 0 ): array {
		global $wpdb;
		$types = self::types();
		if ( ! $types ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args = array_merge( array( $after ), $types, array( $until > 0 ? $until : PHP_INT_MAX, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- keyset walk on the primary key; the placeholders are built from the count of types.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ($in) AND ID <= %d ORDER BY ID ASC LIMIT %d", $args ) ) );
	}

	/**
	 * Is a page one Google is asked NOT to index? Checking it would be paying for a "no".
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function hidden( int $post_id ): bool {
		return in_array( 'noindex', (array) Meta::get_post( $post_id, 'robots' ), true );
	}

	/**
	 * Address => post id for a range of ids, skipping pages set to noindex.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return array<string, int>
	 */
	public static function addresses( array $ids ): array {
		$out = array();
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		foreach ( $ids as $id ) {
			if ( self::hidden( $id ) ) {
				continue;
			}
			$url = get_permalink( $id );
			if ( is_string( $url ) && '' !== $url ) {
				$out[ $url ] = $id;
			}
		}

		return $out;
	}

	/**
	 * Start a check of some addresses of this site.
	 *
	 * @param array<int, string> $urls Addresses.
	 * @return array<string, mixed>|\WP_Error The run.
	 */
	public static function check( array $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( static fn( $u ): string => Client::web( $u ), $urls ), 'strlen' ) ) );
		if ( ! $urls ) {
			return new \WP_Error( 'zinn_seo_index_none', __( 'Choose at least one page to check.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( count( $urls ) > self::BATCH ) {
			/* translators: %s: a number. */
			return new \WP_Error( 'zinn_seo_index_many', sprintf( __( 'That is more than %s pages at once. Use "Check every page" for the whole site.', 'zinn-seo' ), number_format_i18n( self::BATCH ) ), array( 'status' => 422 ) );
		}
		if ( ! Connection::can_spend() ) {
			return Client::as_error( Client::classify( 403, array( 'code' => 'insufficient_scope' ) ) );
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/index-checks', array( 'urls' => $urls ) );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		Credits::forget();
		$run = self::run_view( (array) $result['body'] );
		self::remember( $run );

		return $run;
	}

	/**
	 * A run as the screens use it.
	 *
	 * @param array<string, mixed> $run The API's run.
	 * @return array<string, mixed>
	 */
	public static function run_view( array $run ): array {
		$results = array();
		foreach ( (array) ( $run['results'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['url'] ) ) {
				$results[] = array(
					'url'     => Client::web( $row['url'] ),
					// null is "Google could not be asked", never "not indexed".
					'indexed' => isset( $row['indexed'] ) ? (bool) $row['indexed'] : null,
				);
			}
		}

		return array(
			'id'              => sanitize_text_field( (string) ( $run['id'] ?? '' ) ),
			'status'          => in_array( (string) ( $run['status'] ?? '' ), array( 'queued', 'complete', 'failed' ), true ) ? (string) $run['status'] : 'queued',
			'urls'            => (int) ( $run['urls'] ?? count( $results ) ),
			'indexed'         => (int) ( $run['indexed'] ?? 0 ),
			'not_indexed'     => (int) ( $run['not_indexed'] ?? 0 ),
			'unknown'         => (int) ( $run['unknown'] ?? 0 ),
			'charged_micros'  => (int) ( $run['charged_micros'] ?? 0 ),
			'refunded_micros' => (int) ( $run['refunded_micros'] ?? 0 ),
			'at'              => (int) strtotime( (string) ( $run['created_at'] ?? '' ) ),
			'results'         => $results,
		);
	}

	/**
	 * Read a run; once it is finished, write each page's answer onto its post.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function read( string $run_id ) {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $run_id ) ) {
			return new \WP_Error( 'zinn_seo_unknown_run', __( 'That check could not be found.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/index-checks/' . rawurlencode( $run_id ) );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$run = self::run_view( (array) $result['body'] );
		if ( 'queued' !== $run['status'] ) {
			$map = array();
			foreach ( $run['results'] as $row ) {
				$id = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $row['url'] );
				if ( $id > 0 ) {
					$map[ $row['url'] ] = $id;
				}
			}
			self::apply( $run, $map );
			self::remember( $run );
			Credits::forget();
		}

		return $run;
	}

	/**
	 * Write a finished run's answers onto the posts.
	 *
	 * @param array<string, mixed> $run A run view.
	 * @param array<string, int>   $map Address => post id.
	 * @return int How many posts were written.
	 */
	public static function apply( array $run, array $map ): int {
		$when    = time();
		$written = 0;
		$by      = array();
		foreach ( $map as $url => $id ) {
			$by[ self::fold( $url ) ] = $id;
		}
		foreach ( (array) $run['results'] as $row ) {
			$id = $by[ self::fold( (string) $row['url'] ) ] ?? 0;
			if ( $id < 1 ) {
				continue;
			}
			$mark = null === $row['indexed'] ? '?' : ( $row['indexed'] ? '1' : '0' );
			update_post_meta( $id, self::META, $mark . ':' . $when );
			++$written;
		}

		return $written;
	}

	/**
	 * One spelling of an address, so the API's copy of it meets ours.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function fold( string $url ): string {
		return strtolower( untrailingslashit( (string) preg_replace( '#^https?://(www\.)?#i', '', trim( $url ) ) ) );
	}

	/**
	 * A post's last answer.
	 *
	 * @param int $post_id Post.
	 * @return array{state: string, at: int} `state`: yes|no|unknown|never.
	 */
	public static function state( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, self::META, true );
		if ( 1 !== preg_match( '/^([10?]):(\d+)$/', $raw, $m ) ) {
			return array(
				'state' => 'never',
				'at'    => 0,
			);
		}

		return array(
			'state' => '1' === $m[1] ? 'yes' : ( '0' === $m[1] ? 'no' : 'unknown' ),
			'at'    => (int) $m[2],
		);
	}

	/**
	 * Keep the last twenty runs (without their per-page rows).
	 *
	 * @param array<string, mixed> $run A run view.
	 * @return void
	 */
	public static function remember( array $run ): void {
		if ( '' === (string) $run['id'] ) {
			return;
		}
		unset( $run['results'] );
		$runs = get_option( self::RUNS, array() );
		$runs = is_array( $runs ) ? $runs : array();
		$runs = array( $run['id'] => $run ) + $runs;
		update_option( self::RUNS, array_slice( $runs, 0, 20, true ), false );
	}

	/**
	 * The screen's starting point: the totals, the price of the whole site, the job, the runs.
	 *
	 * @return array<string, mixed>
	 */
	public static function overview(): array {
		global $wpdb;
		$counts = array(
			'yes'     => 0,
			'no'      => 0,
			'unknown' => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one grouped COUNT over the meta key index.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT LEFT(meta_value, 1) AS mark, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY LEFT(meta_value, 1)", self::META ) );
		foreach ( $rows as $row ) {
			$key             = '1' === $row->mark ? 'yes' : ( '0' === $row->mark ? 'no' : 'unknown' );
			$counts[ $key ] += (int) $row->n;
		}
		$runs  = get_option( self::RUNS, array() );
		$total = self::total();
		$price = Credits::prices()['index_check'] ?? null;

		return array(
			'pages'      => $total,
			'counts'     => $counts,
			'unit_price' => $price,
			'site_cost'  => null === $price ? null : $price * $total,
			'job'        => self::job(),
			'runs'       => array_values( is_array( $runs ) ? $runs : array() ),
		);
	}

	/**
	 * The whole-site job as the screens use it.
	 *
	 * @return array<string, mixed>
	 */
	public static function job(): array {
		$job   = Jobs::get( self::JOB );
		$state = (array) $job['state'];

		return array(
			'status'   => (string) $job['status'],
			'reason'   => (string) ( $job['error'] ?? '' ),
			'held'     => 'paused' === $job['status'] && ! empty( $state['held'] ),
			'done'     => (int) ( $state['checked'] ?? 0 ),
			'total'    => (int) ( $state['total'] ?? 0 ),
			'indexed'  => (int) ( $state['indexed'] ?? 0 ),
			'missing'  => (int) ( $state['not_indexed'] ?? 0 ),
			'unknown'  => (int) ( $state['unknown'] ?? 0 ),
			'charged'  => (int) ( $state['charged'] ?? 0 ),
			'refunded' => (int) ( $state['refunded'] ?? 0 ),
			'started'  => (int) ( $job['started'] ?? 0 ),
			'finished' => (int) ( $job['finished'] ?? 0 ),
		);
	}

	/**
	 * Start checking every page.
	 *
	 * @return array<string, mixed>|\WP_Error The job.
	 */
	public static function start_site() {
		if ( ! Connection::can_spend() ) {
			return Client::as_error( Client::classify( 403, array( 'code' => 'insufficient_scope' ) ) );
		}
		if ( in_array( Jobs::get( self::JOB )['status'], array( 'pending', 'paused' ), true ) ) {
			return new \WP_Error( 'zinn_seo_index_running', __( 'A check of every page is already under way.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$total = self::total();
		if ( $total < 1 ) {
			return new \WP_Error( 'zinn_seo_index_none', __( 'There are no published pages to check yet.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$started = Jobs::start(
			self::JOB,
			array(
				'after' => 0,
				'total' => $total,
			)
		);

		return is_wp_error( $started ) ? $started : self::job();
	}

	/**
	 * One step of the whole-site job: send the next thousand pages, or collect the batch that is
	 * out and write its answers.
	 *
	 * @param array<string, mixed> $state    The job's state.
	 * @param float                $deadline Absolute microtime deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$state        += array(
			'after'       => 0,
			'from'        => 0,
			'run'         => '',
			'run_at'      => 0,
			'total'       => 0,
			'checked'     => 0,
			'indexed'     => 0,
			'not_indexed' => 0,
			'unknown'     => 0,
			'charged'     => 0,
			'refunded'    => 0,
			'held'        => false,
		);
		$state['held'] = false;
		$progress      = static fn( array $s ): array => array(
			'done'  => (int) $s['checked'],
			'total' => (int) $s['total'],
		);

		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			if ( '' !== (string) $state['run'] ) {
				// A batch is out: collect it (the answer usually takes under a minute).
				$result = Connection::call( 'GET', '/v1/seo-connection/index-checks/' . rawurlencode( (string) $state['run'] ) );
				if ( ! $result['ok'] ) {
					return self::stalled( $state, $result, $progress );
				}
				$run = self::run_view( (array) $result['body'] );
				if ( 'queued' === $run['status'] ) {
					// ⛔ Asking is BOUNDED (CLAUDE.md §2.55). The answer usually takes under a
					// minute, so the first two minutes ask every three seconds. A batch still
					// not answered then is asked for ever more rarely (the job WAITS, holding no
					// PHP worker and chaining no request), and after a day the job pauses with
					// the reason. Before, a batch Zinn® never answered was asked about every
					// three seconds for as long as the site lived.
					if ( 0 === (int) $state['run_at'] ) {
						$state['run_at'] = time(); // A batch sent by a release that did not date it.
					}
					list( $plan, $seconds ) = self::poll_plan( time() - (int) $state['run_at'] );
					if ( 'pause' === $plan ) {
						return array(
							'state'    => $state,
							'done'     => false,
							'pause'    => __( 'Zinn® has not answered this batch of pages for a day. Nothing more was charged for it. Resume the check to ask again.', 'zinn-seo' ),
							'progress' => $progress( $state ),
						);
					}
					if ( 'wait' === $plan ) {
						return array(
							'state'    => $state,
							'done'     => false,
							'wait'     => $seconds,
							'reason'   => __( 'Waiting for Zinn® to answer a batch of pages.', 'zinn-seo' ),
							'progress' => $progress( $state ),
						);
					}
					if ( microtime( true ) + 4 >= $deadline ) {
						break;
					}
					sleep( $seconds );
					continue;
				}
				// The same pages again, by id range: no list of a thousand addresses is stored.
				self::apply( $run, self::addresses( self::ids_after( (int) $state['from'], self::BATCH, (int) $state['after'] ) ) );
				self::remember( $run );
				$state['checked']     += $run['urls'];
				$state['indexed']     += $run['indexed'];
				$state['not_indexed'] += $run['not_indexed'];
				$state['unknown']     += $run['unknown'];
				$state['charged']     += $run['charged_micros'];
				$state['refunded']    += $run['refunded_micros'];
				$state['run']          = '';
				$state['run_at']       = 0;
				continue;
			}
			$ids = self::ids_after( (int) $state['after'], self::BATCH );
			if ( ! $ids ) {
				Credits::forget();
				return array(
					'state'    => $state,
					'done'     => true,
					'progress' => $progress( $state ),
				);
			}
			$last = (int) end( $ids );
			$urls = array_keys( self::addresses( $ids ) );
			if ( ! $urls ) {
				$state['after'] = $last;
				continue;
			}
			$result = Connection::call( 'POST', '/v1/seo-connection/index-checks', array( 'urls' => $urls ) );
			if ( ! $result['ok'] ) {
				return self::stalled( $state, $result, $progress );
			}
			$state['from']   = (int) $state['after'];
			$state['after']  = $last;
			$state['run']    = sanitize_text_field( (string) ( $result['body']['id'] ?? '' ) );
			$state['run_at'] = time();
			// Saved at once (the step ends here): a batch that was paid for must be on record
			// before anything else can go wrong, or a restart would send and pay for it again.
			break;
		}

		return array(
			'state'    => $state,
			'done'     => false,
			'progress' => $progress( $state ),
		);
	}

	/**
	 * How to ask again about a batch that is still queued: at once (every 3 s) for the first two
	 * minutes, then after a wait that grows with the time already waited (30 s … 15 min), and
	 * not at all — pause — after a day. At most about 40 + 110 questions per batch, ever.
	 *
	 * @param int $waited Seconds since the batch was sent.
	 * @return array{0: string, 1: int} `poll`, `wait` or `pause`, and the seconds to wait.
	 */
	public static function poll_plan( int $waited ): array {
		if ( $waited >= DAY_IN_SECONDS ) {
			return array( 'pause', 0 );
		}
		if ( $waited >= 2 * MINUTE_IN_SECONDS ) {
			return array( 'wait', max( 30, min( 15 * MINUTE_IN_SECONDS, (int) ( $waited / 4 ) ) ) );
		}

		return array( 'poll', 3 );
	}

	/**
	 * A batch Zinn® would not take or could not answer: pause where the job is, with the reason
	 * in plain words. Nothing was charged for a refused batch.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param array<string, mixed> $result   The failed call.
	 * @param callable             $progress Progress of a state.
	 * @return array<string, mixed>
	 */
	private static function stalled( array $state, array $result, callable $progress ): array {
		$class         = (string) ( $result['error']['class'] ?? '' );
		$state['held'] = 'refused' === $class;

		return array(
			'state'    => $state,
			'done'     => false,
			'pause'    => (string) ( $result['error']['message'] ?? '' ),
			'progress' => $progress( $state ),
		);
	}

	/**
	 * Credit is back: carry on a whole-site check that paused for it.
	 *
	 * @return void
	 */
	public static function resume(): void {
		$job = Jobs::get( self::JOB );
		if ( 'paused' === $job['status'] && ! empty( $job['state']['held'] ) ) {
			Jobs::control( self::JOB, 'resume' );
		}
	}
}
