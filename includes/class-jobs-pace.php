<?php
/**
 * The pace of background jobs (Q337): how long one step may work, and how long the site rests
 * between steps, from how the site is doing right now.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ BOUND THE PACE, NEVER THE WORK (CLAUDE.md §2.10). On 2026-10-18 zinnhub's host flagged 42
 * slow requests an hour for two hours: every `zseo_job_step` POST worked ~21.7 s, one every ~20 s,
 * on shared hosting. A step now works about five seconds; when the site itself is slow (its own
 * pages take long to answer, or the server's load is high) or inside the site's quiet hours, the
 * steps get shorter and the site rests between them. Nothing is skipped and nothing is capped:
 * every job still finishes, it only takes longer on a busy site.
 *
 * Four levels, each `[seconds of work per step, seconds of rest after a step]`:
 * - 0 calm:  5 s of work, no rest (the chain goes straight on);
 * - 1 busy:  2.5 s of work, 15 s of rest;
 * - 2 heavy: 1 s of work, 60 s of rest (also the quiet hours);
 * - 3 held:  no step at all, checked again every 3 minutes, and the jobs carry on by themselves
 *            once the site has recovered (ZHJOB1). The screens say so: "Paused to keep your site
 *            fast — resumes automatically."
 *
 * ⛔⛔ ANY SITE, NO OUTSIDE GUARD (owner, 2026-10-19: "what happens if this is a customer site,
 * surely we need it to deal with this itself"). zinnhub had a host-side load guard; a customer's
 * site has only this. So the plugin reads the site's own signals — and holds, never adds to, an
 * Action Scheduler backlog that is already past due (our page writes make other plugins queue
 * work: 13,890 past due on zinnhub after one link-plan apply).
 *
 * A step that ran far past its time (one page that renders for 20 s) is followed by a rest as
 * long as the step, so the jobs never hold PHP more than half of the time on their own.
 *
 * The signals, all cheap:
 * - the site's own front-end answer time, sampled on one front-end request in five while a job
 *   is pending (one small non-autoloaded option, written at most every 30 s);
 * - the server's load per CPU (`sys_getloadavg()`), where the host allows reading it;
 * - the Action Scheduler actions past due (everyone's) and whether the site's runner is moving;
 * - how late the oldest due WP-Cron event is;
 * - how long this step's own request took to start WordPress;
 * - the quiet-hours window (Settings › Jobs), in the site's time zone.
 */
final class Jobs_Pace {

	/** Seconds of work in one step on a calm site. */
	public const BUDGET = 5.0;

	/** Seconds of work and of rest per level. */
	public const LEVELS = array(
		0 => array( 5.0, 0 ),
		1 => array( 2.5, 15 ),
		2 => array( 1.0, 60 ),
		// 3 held: no step at all; the site is checked again after the rest, and the jobs carry on
		// by themselves the moment it has recovered (ZHJOB1).
		3 => array( 0.0, 180 ),
	);
	/** The level at which no step runs: the jobs are held until the site recovers (ZHJOB1). */
	public const HELD = 3;
	/**
	 * Action Scheduler actions past due (everyone's: the site's plugins and ours) from which the
	 * site is busy, and from which our jobs are held (ZHJOB1). On 2026-10-19 a link plan applied
	 * to 2,403 zinnhub pages made the site's other plugins queue ~2,000 actions each (translation,
	 * search and catalogue re-indexing, AI extraction): 13,890 past due, ~22 h to drain at the
	 * site's own runner pace. Writing more pages then only adds to that.
	 */
	public const QUEUE_BUSY = 50;
	public const QUEUE_HELD = 200;
	/** Seconds past due before an action counts (a freshly queued async action is not a backlog). */
	public const QUEUE_GRACE = 120;
	/** Seconds the oldest due WP-Cron event may be late before the site is busy, and heavy. */
	public const CRON_BUSY  = 1800;
	public const CRON_HEAVY = 3600;
	/** Seconds this request's own WordPress start-up may take before the site is busy, and heavy. */
	public const BOOT_BUSY  = 3.0;
	public const BOOT_HEAVY = 6.0;
	/** Front-end answer time (ms) and load per CPU from which our jobs are held. */
	public const FRONT_HELD_MS = 8000;
	public const LOAD_HELD     = 4.0;

	/** Front-end answer time (ms) from which the site is busy, and heavy. */
	public const FRONT_BUSY_MS  = 2000;
	public const FRONT_HEAVY_MS = 4000;

	/** Load per CPU from which the server is busy, and heavy. */
	public const LOAD_BUSY  = 1.0;
	public const LOAD_HEAVY = 2.0;

	/** The front-end pulse: `{ms, at}`. */
	public const PULSE = 'zseo_jobs_pulse';

	/** Until when the site's jobs rest (a timestamp; site-wide, one job runs at a time). */
	public const REST = 'zseo_jobs_rest';

	/** The one-off cron event that ends a rest. */
	public const RESUME = 'zseo_jobs_resume';

	/** Seconds after a rest's end from which a missing RESUME event counts as lost (Q377). */
	public const HEAL_AFTER = 15;

	/**
	 * The Action Scheduler backlog to read instead of the table (unit tests set it; null: read).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $queue_given = null;
	/** The site's dynamic-request start-up: `{s, at}` (ZHJOB1). */
	public const BOOT = 'zseo_jobs_boot';
	/** A pulse older than this says nothing about now. */
	private const PULSE_FRESH = 600;

	/**
	 * The level now, and why.
	 *
	 * @param int|null $now Timestamp (tests).
	 * @return array{level: int, why: string}
	 */
	public static function pressure( ?int $now = null ): array {
		$now     = $now ?? time();
		$signals = array(
			'quiet' => self::quiet_now( $now ),
			'front' => self::front_ms( $now ),
			'load'  => self::load(),
			'queue' => self::queue(),
			'cron'  => self::cron_lag( $now ),
			'boot'  => self::boot(),
		);
		$found   = self::classify( $signals );
		/**
		 * Filters how hard the site is pressed right now (0 calm, 1 busy, 2 heavy, 3 held: no
		 * step until it recovers). A host that knows better (its own load figure) can answer here.
		 *
		 * @param int    $level Level.
		 * @param string $why   quiet|front|load|queue|cron|boot|''.
		 */
		$level = (int) apply_filters( 'zinn_seo_jobs_pressure', $found['level'], $found['why'] );

		return array(
			'level' => max( 0, min( self::HELD, $level ) ),
			'why'   => $found['why'],
		);
	}

	/**
	 * The level from the site's signals, and the signal that set it (the first of the highest).
	 * Pure (ZHJOB1): every signal that is null was not readable here and says nothing.
	 *
	 * - quiet hours: heavy;
	 * - front-end answer time (ms): busy, heavy, held;
	 * - load per CPU: busy, heavy, held;
	 * - Action Scheduler past due `{n, running}`: busy from QUEUE_BUSY; from QUEUE_HELD held while
	 *   the site's runner is working through it (it recovers by itself, and our page writes would
	 *   only add to it), heavy when nothing runs it at all (holding then would wait forever);
	 * - WP-Cron lag (s): busy, heavy;
	 * - this request's own WordPress start-up (s): busy, heavy.
	 *
	 * @param array<string, mixed> $s Signals.
	 * @return array{level: int, why: string}
	 */
	public static function classify( array $s ): array {
		$at    = array();
		$front = $s['front'] ?? null;
		$load  = $s['load'] ?? null;
		$queue = $s['queue'] ?? null;
		$cron  = $s['cron'] ?? null;
		$boot  = $s['boot'] ?? null;
		if ( ! empty( $s['quiet'] ) ) {
			$at['quiet'] = 2;
		}
		if ( null !== $front ) {
			$f           = (int) $front;
			$at['front'] = $f >= self::FRONT_HELD_MS ? self::HELD : ( $f >= self::FRONT_HEAVY_MS ? 2 : ( $f >= self::FRONT_BUSY_MS ? 1 : 0 ) );
		}
		if ( null !== $load ) {
			$l          = (float) $load;
			$at['load'] = $l >= self::LOAD_HELD ? self::HELD : ( $l >= self::LOAD_HEAVY ? 2 : ( $l >= self::LOAD_BUSY ? 1 : 0 ) );
		}
		if ( is_array( $queue ) ) {
			$n           = (int) ( $queue['n'] ?? 0 );
			$at['queue'] = $n >= self::QUEUE_HELD ? ( empty( $queue['running'] ) ? 2 : self::HELD ) : ( $n >= self::QUEUE_BUSY ? 1 : 0 );
		}
		if ( null !== $cron ) {
			$c          = (int) $cron;
			$at['cron'] = $c >= self::CRON_HEAVY ? 2 : ( $c >= self::CRON_BUSY ? 1 : 0 );
		}
		if ( null !== $boot ) {
			$b          = (float) $boot;
			$at['boot'] = $b >= self::BOOT_HEAVY ? 2 : ( $b >= self::BOOT_BUSY ? 1 : 0 );
		}
		$level = 0;
		$why   = '';
		foreach ( $at as $name => $value ) {
			if ( $value > $level ) {
				$level = $value;
				$why   = $name;
			}
		}

		return array(
			'level' => $level,
			'why'   => $why,
		);
	}

	/**
	 * Action Scheduler actions past due, everyone's, counted up to QUEUE_HELD × 10, and whether the
	 * site's runner has run any in the last ten minutes (null where Action Scheduler is not here).
	 * Two indexed reads (status + scheduled date; status + last attempt): ~3 ms on 53,000 rows.
	 *
	 * @return array{n: int, running: bool}|null
	 */
	public static function queue(): ?array {
		global $wpdb;
		$given = self::$queue_given;
		if ( is_array( $given ) ) {
			return array(
				'n'       => (int) ( $given['n'] ?? 0 ),
				'running' => ! empty( $given['running'] ),
			);
		}
		if ( ! class_exists( 'ActionScheduler', false ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$due   = gmdate( 'Y-m-d H:i:s', time() - self::QUEUE_GRACE );
		$since = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$limit = self::QUEUE_HELD * 10;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Action Scheduler's own table, read live on purpose (a cached count says nothing about now).
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT action_id FROM %i WHERE status = 'pending' AND scheduled_date_gmt < %s LIMIT %d) x", $table, $due, $limit ) );
		if ( null === $n ) {
			return null; // No such table here.
		}
		$ran = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT action_id FROM %i WHERE status IN ('complete','failed') AND last_attempt_gmt > %s LIMIT 1) x", $table, $since ) );
		// phpcs:enable

		return array(
			'n'       => (int) $n,
			'running' => $ran > 0,
		);
	}

	/**
	 * Seconds the oldest due WP-Cron event is late (null: no cron array here).
	 *
	 * @param int $now Timestamp.
	 * @return int|null
	 */
	public static function cron_lag( int $now ): ?int {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return null;
		}
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) || array() === $crons ) {
			return 0;
		}
		$first = (int) min( array_keys( $crons ) );

		return max( 0, $now - $first );
	}

	/**
	 * Seconds the site's dynamic requests take to start WordPress: this web request's own
	 * start-up, remembered (one small non-autoloaded option, written at most every 30 s or when it
	 * moved by half a second) so a step run from WP-CLI or system cron knows it too; null when no
	 * fresh reading exists. A site that struggles to start PHP answers every uncached request
	 * slowly, however fast its cached front end looks (zinnhub: front end 0.8 s while 255
	 * requests an hour took over 5 s).
	 *
	 * @return float|null
	 */
	public static function boot(): ?float {
		$now  = time();
		$seen = get_option( self::BOOT, array() );
		$seen = is_array( $seen ) ? $seen : array();
		if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) || ! isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return isset( $seen['s'], $seen['at'] ) && (int) $seen['at'] >= $now - self::PULSE_FRESH ? (float) $seen['s'] : null;
		}
		$secs = round( max( 0.0, microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ), 2 );
		if ( (int) ( $seen['at'] ?? 0 ) < $now - 30 || abs( $secs - (float) ( $seen['s'] ?? 0 ) ) >= 0.5 ) {
			update_option(
				self::BOOT,
				array(
					's'  => $secs,
					'at' => $now,
				),
				false
			);
		}

		return $secs;
	}

	/**
	 * Seconds of work for the next step.
	 *
	 * @param int|null $level Level (null: measure it now).
	 * @return float
	 */
	public static function budget( ?int $level = null ): float {
		$level = $level ?? self::pressure()['level'];
		/**
		 * Filters the seconds one background-job step may work at this level. Kept between half
		 * a second and twenty: one step must stay well inside a 30 s max_execution_time.
		 *
		 * @param float $seconds Seconds.
		 * @param int   $level   0 calm, 1 busy, 2 heavy.
		 */
		$seconds = (float) apply_filters( 'zinn_seo_jobs_step_seconds', (float) self::LEVELS[ max( 0, min( 2, $level ) ) ][0], $level );

		return max( 0.5, min( Jobs::STEP_SECONDS, $seconds ) );
	}

	/**
	 * Seconds the site rests after a step that worked `$worked` seconds within `$budget`.
	 *
	 * @param int   $level  Level.
	 * @param float $worked Seconds the step took.
	 * @param float $budget Seconds it had.
	 * @return int
	 */
	public static function rest_after( int $level, float $worked, float $budget ): int {
		$rest = (int) self::LEVELS[ max( 0, min( self::HELD, $level ) ) ][1];
		if ( $worked > 2 * $budget ) {
			// One item ran far past the step's time: rest as long as it worked (half the time at most).
			$rest = max( $rest, (int) ceil( $worked ) );
		}

		return min( 300, $rest );
	}

	/**
	 * Until when the jobs rest (0: they do not).
	 *
	 * @return int
	 */
	public static function resting_until(): int {
		$until = (int) get_option( self::REST, 0 );

		return $until > time() ? $until : 0;
	}

	/**
	 * Rest for `$seconds` from now, and make sure something ends the rest: a one-off cron event
	 * (the 15-minute watchdog stays as the safety net).
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	public static function rest( int $seconds ): void {
		if ( $seconds <= 0 ) {
			return;
		}
		$until = time() + $seconds;
		update_option( self::REST, $until, false );
		self::schedule_resume( $until );
	}

	/**
	 * The rest has ended and a driver is looking at the jobs now: forget it (Q377). A rest that
	 * is still written down AFTER its end therefore means nothing has looked since — see heal().
	 *
	 * @return void
	 */
	public static function rest_over(): void {
		$until = (int) get_option( self::REST, 0 );
		if ( $until > 0 && $until <= time() ) {
			delete_option( self::REST );
		}
	}

	/**
	 * ⛔ Q377 (ZHJOB1, zinnhub AND the demo, 0.17.56): a held job came back only at the 15-minute
	 * watchdog, though its rest was 180 s — the one-off zseo_jobs_resume event had gone. When a
	 * rest ended more than HEAL_AFTER seconds ago, nothing has looked at the jobs since (the event
	 * that ends a rest clears it, rest_over()) and that event is not scheduled, it was lost: it is
	 * scheduled again, due now, so the site's next cron run carries on. Asked by the front-end
	 * sampler, which runs on visitors' requests while a job is pending; one option read, nothing
	 * written unless the event was lost.
	 *
	 * @param int|null $now Timestamp (tests).
	 * @return bool Whether it had to schedule the event again.
	 */
	public static function heal( ?int $now = null ): bool {
		$now   = $now ?? time();
		$until = (int) get_option( self::REST, 0 );
		if ( $until <= 0 || $until > $now - self::HEAL_AFTER || false !== wp_next_scheduled( self::RESUME ) ) {
			return false;
		}
		wp_schedule_single_event( $now, self::RESUME );

		return true;
	}

	/**
	 * ⛔ THE CAUSE OF Q377, traced in a harness with system cron (FIX12). A host runs
	 * `wp cron event run --due-now` every minute, and one run can take minutes (another plugin's
	 * event: 150 s in the harness). WordPress reads the cron array ONCE into that process, and
	 * before each event it runs it reschedules or unschedules the event from that copy and writes
	 * the WHOLE array back — so an event added by another process while the first one was busy
	 * (our zseo_jobs_resume, scheduled by the step that held) is deleted by a write that never
	 * knew about it. The job then waited for the 15-minute watchdog.
	 *
	 * So, as any process writes the cron array: our own events that the database has, that this
	 * process's copy never had, and that the array written would drop, are kept. An event this
	 * process did know about and removes (it ran, or was cleared) stays removed. One read of the
	 * row, and the array is only unpacked when it holds an event of ours.
	 *
	 * @param mixed $value The cron array about to be written.
	 * @param mixed $old   This process's copy of it.
	 * @return mixed
	 */
	public static function keep_events( $value, $old ) {
		global $wpdb;
		if ( ! is_array( $value ) || ! isset( $wpdb ) ) {
			return $value;
		}
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'cron' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the row as it is NOW, not this process's cached copy (that copy is the problem).
		if ( ! is_string( $raw ) || false === strpos( $raw, 'zseo_jobs_' ) ) {
			return $value;
		}
		$stored = maybe_unserialize( $raw );

		return is_array( $stored ) ? self::merge_events( $value, is_array( $old ) ? $old : array(), $stored ) : $value;
	}

	/**
	 * The cron array to write, with our events from `$stored` that `$old` never had put back
	 * (pure; keep_events() reads the row).
	 *
	 * @param array<int|string, mixed> $value  The array about to be written.
	 * @param array<int|string, mixed> $old    The writer's own copy.
	 * @param array<int|string, mixed> $stored The row as stored now.
	 * @return array<int|string, mixed>
	 */
	public static function merge_events( array $value, array $old, array $stored ): array {
		$added = false;
		foreach ( $stored as $time => $events ) {
			if ( ! is_int( $time ) || ! is_array( $events ) ) {
				continue;
			}
			foreach ( array( self::RESUME, Jobs::WATCHDOG ) as $hook ) {
				if ( empty( $events[ $hook ] ) || ! is_array( $events[ $hook ] ) ) {
					continue;
				}
				foreach ( $events[ $hook ] as $key => $event ) {
					if ( isset( $old[ $time ][ $hook ][ $key ] ) || isset( $value[ $time ][ $hook ][ $key ] ) ) {
						continue; // The writer knew it: kept or removed on purpose.
					}
					if ( Jobs::WATCHDOG === $hook && self::has_hook( $value, $hook ) ) {
						continue; // The recurring watchdog, moved on by the writer: once is enough.
					}
					$value[ $time ][ $hook ][ $key ] = $event;
					$added                           = true;
				}
			}
		}
		if ( $added ) {
			// WordPress keeps the events in time order, its `version` entry last.
			uksort(
				$value,
				static function ( $a, $b ): int {
					if ( is_int( $a ) && is_int( $b ) ) {
						return $a <=> $b;
					}
					return is_int( $a ) ? -1 : ( is_int( $b ) ? 1 : strcmp( (string) $a, (string) $b ) );
				}
			);
		}

		return $value;
	}

	/**
	 * Does a cron array hold an event for this hook?
	 *
	 * @param array<int|string, mixed> $crons Cron array.
	 * @param string                   $hook  Hook.
	 * @return bool
	 */
	private static function has_hook( array $crons, string $hook ): bool {
		foreach ( $crons as $events ) {
			if ( is_array( $events ) && ! empty( $events[ $hook ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Make sure the rest ends with a step: a one-off event at `$until`.
	 *
	 * @param int $until Timestamp.
	 * @return void
	 */
	public static function schedule_resume( int $until ): void {
		$next = wp_next_scheduled( self::RESUME );
		if ( false !== $next && (int) $next <= $until + 5 ) {
			return;
		}
		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::RESUME );
		}
		wp_schedule_single_event( $until, self::RESUME );
	}

	/**
	 * Is it the site's quiet hours now (Settings › Jobs, in the site's time zone)?
	 *
	 * @param int|null $now Timestamp.
	 * @return bool
	 */
	public static function quiet_now( ?int $now = null ): bool {
		$on   = (bool) self::setting( 'jobs_quiet', false );
		$from = (int) self::setting( 'jobs_quiet_from', 9 );
		$to   = (int) self::setting( 'jobs_quiet_to', 17 );
		if ( ! $on || $from === $to ) {
			return false;
		}
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$hour = (int) ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $zone )->format( 'G' );

		return self::in_window( $hour, $from, $to );
	}

	/**
	 * Is an hour inside `[from, to)`? A window may run over midnight (22 to 6). Pure.
	 *
	 * @param int $hour Hour 0-23.
	 * @param int $from First quiet hour.
	 * @param int $to   First hour after the window.
	 * @return bool
	 */
	public static function in_window( int $hour, int $from, int $to ): bool {
		if ( $from === $to ) {
			return false;
		}

		return $from < $to ? ( $hour >= $from && $hour < $to ) : ( $hour >= $from || $hour < $to );
	}

	/**
	 * The site's recent front-end answer time in ms (null: no fresh sample).
	 *
	 * @param int $now Timestamp.
	 * @return int|null
	 */
	private static function front_ms( int $now ): ?int {
		$pulse = get_option( self::PULSE, array() );
		if ( ! is_array( $pulse ) || ! isset( $pulse['ms'], $pulse['at'] ) || (int) $pulse['at'] < $now - self::PULSE_FRESH ) {
			return null;
		}

		return (int) $pulse['ms'];
	}

	/**
	 * The server's one-minute load per CPU (null where the host does not let PHP read it).
	 *
	 * ⛔ An INPUT, never ambient (HARDEN1): the filter `zinn_seo_jobs_load` gives it (a float per
	 * CPU, or false for "cannot be read"); only when nothing gives it is the machine asked. Unit
	 * tests read the machine's load through here and failed on a busy build box (13 of 3,556).
	 *
	 * @return float|null
	 */
	public static function load(): ?float {
		/**
		 * Filters the server load the jobs pace by (per CPU; false: cannot be read; null: ask
		 * the machine).
		 *
		 * @param float|false|null $load Null.
		 */
		$given = apply_filters( 'zinn_seo_jobs_load', null );
		if ( null !== $given ) {
			return false === $given ? null : max( 0.0, (float) $given );
		}
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return null;
		}
		$avg = sys_getloadavg();
		if ( ! is_array( $avg ) || ! isset( $avg[0] ) ) {
			return null;
		}

		return (float) $avg[0] / self::cpus();
	}

	/**
	 * CPUs on this server (1 when it cannot be read).
	 *
	 * @return int
	 */
	private static function cpus(): int {
		static $cpus = 0;
		if ( $cpus > 0 ) {
			return $cpus;
		}
		$cpus = 1;
		if ( '' === (string) ini_get( 'open_basedir' ) && is_readable( '/proc/cpuinfo' ) ) {
			$info = (string) file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local system file, not a URL.
			$cpus = max( 1, (int) preg_match_all( '/^processor\s*:/m', $info ) );
		}

		return $cpus;
	}

	/**
	 * Record how long this front-end request took (on `shutdown`): one request in five, while a
	 * job is pending, at most every 30 s. Requests served from a page cache never reach PHP and
	 * cost nothing here. At the same pace it asks heal() whether a rest's event was lost (Q377).
	 *
	 * @return void
	 */
	public static function sample(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_TIME_FLOAT'] ) || false === wp_next_scheduled( Jobs::WATCHDOG ) || 1 !== wp_rand( 1, 5 ) ) {
			return;
		}
		$ms    = (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		$pulse = get_option( self::PULSE, array() );
		$pulse = is_array( $pulse ) ? $pulse : array();
		$at    = (int) ( $pulse['at'] ?? 0 );
		if ( $at > time() - 30 ) {
			return;
		}
		// Q377: a job whose rest's event was lost carries on from the visitors' requests too.
		self::heal();
		$old = $at > time() - self::PULSE_FRESH ? (int) ( $pulse['ms'] ?? $ms ) : $ms;
		update_option(
			self::PULSE,
			array(
				'ms' => (int) round( 0.6 * $old + 0.4 * $ms ),
				'at' => time(),
			),
			false
		);
	}

	/**
	 * A setting, when the settings are loaded (unit tests run the jobs alone).
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private static function setting( string $key, $fallback ) {
		return class_exists( '\\ZinnDigital\\ZinnSEO\\Settings', false ) ? Settings::get( $key, $fallback ) : $fallback;
	}
}
