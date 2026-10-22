<?php
/**
 * Background jobs that RESUME (zs-156): resumable step chains with a watchdog.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ NO CAP, NO DROPPED TAIL (CLAUDE.md §2.10). A job is a `step( array $state, float $deadline )`
 * callable that does as much as it can before the deadline, returns its new state (a keyset
 * cursor, never an offset) and says whether work remains. Nothing is a fixed slice per run: a site
 * with 50 posts and one with a million both finish; the second simply takes longer.
 *
 * Driven, most to least immediate:
 * 1. a non-blocking loopback POST to admin-ajax.php carrying a single-use token (the hash of the
 *    token is stored, the token itself only travels in the request);
 * 2. ONE WP-Cron watchdog event, scheduled only while some job is pending and removed when none
 *    is — a site with no work has no cron entry at all (no cron fan-out);
 * 3. `wp zinn-seo jobs run <id>` from WP-CLI, which loops in-process until done. (WP-CLI that
 *    is running cron — a host's `wp cron event run` — chains the loopback like web cron does.)
 *
 * ⭐ One step at a time per job, under an expiring lock: it never holds more than one PHP worker,
 * which keeps shared hosting's process limits happy.
 *
 * ⛔⛔ AND EACH STEP IS SHORT, AT THE SITE'S PACE (Q337, `Jobs_Pace`). A step works about five
 * seconds on a calm site (it was 20 s: zinnhub's host flagged 42 slow requests an hour). When the
 * site's own pages answer slowly, the server's load is high, or it is the site's quiet hours, the
 * steps get shorter and the site RESTS between them: no step runs anywhere until the rest ends
 * (a one-off cron event ends it; WP-CLI that a person runs waits it out in the terminal). Every
 * driver — the loopback, cron and WP-CLI — keeps that pace. It bounds the pace, never the work:
 * every job still finishes (§2.10).
 *
 * ⛔⛔ AND ONE JOB AT A TIME PER SITE (Q332). Two jobs used to step side by side: on 2026-10-17
 * zinnhub's link plan (12,755 items) and the autopilot's proposals (1,020 items) both chained
 * loopback requests for two hours, and 219 of 309 slow `zseo_job_step` requests fell in that
 * overlap. Now the pending jobs form a queue in the order they were started (or resumed): only
 * the first runs, the rest wait their turn and are never dropped, and each screen says which job
 * it is waiting for. A job that asked to `wait` steps aside until then. The turn is enforced by a
 * second lock row for the whole site (`zseo_joblock__site`), so two drivers of two different jobs
 * arriving at once cannot both step. This bounds CONCURRENCY, never the work (§2.10). A step's state is saved
 * BEFORE the next one is chained, so a step killed by the host resumes where it stopped.
 *
 * ⛔ The lock is a ROW OF ITS OWN taken with one INSERT (`zseo_joblock_<id>`), never a field read
 * and then written: two drivers arriving in the same millisecond (the loopback and the watchdog,
 * or a person at WP-CLI) cannot both run a step. A step that found the job held chains NOTHING —
 * the holder chains the next one — so a second driver waits instead of spinning requests.
 *
 * A step may also answer `wait` (seconds): the job stays pending but no step runs, and nothing
 * is chained, until then; the watchdog carries on afterwards. A job that must slow down (the
 * sitemap's runaway guard) uses it instead of spinning or stopping for good.
 *
 * The state lives in a non-autoloaded option per job, `zseo_job_<id>`, so the front end never
 * loads it.
 */
final class Jobs {

	/**
	 * The MOST seconds one step may ever run (well inside a 30 s max_execution_time). A step's own
	 * time is `Jobs_Pace::budget()`: about five seconds, less on a busy site.
	 */
	public const STEP_SECONDS = 20.0;

	/**
	 * Seconds a web request that runs one step should take in all, from its start (Q367).
	 *
	 * ⛔ PROOF4 (zinnhub 0.17.53): steps worked at most 4.0 s of their 5 s, yet 56 of the site's
	 * own load guard's slow requests (its mark: 5 s) were our step requests at 5.0-5.6 s — the
	 * step's budget did not count the request's WordPress start (~1-1.5 s there) or what follows
	 * the step. A step in a web request (the loopback chain, WP-Cron) now ends by this time after
	 * the request began, so the whole request stays under 5 s; never less than MIN_WORK of work.
	 */
	public const REQUEST_SECONDS = 4.0;

	/** The least work a step in a web request gets, however long its request took to start. */
	public const MIN_WORK = 1.0;

	/**
	 * Whether PHP runs from a terminal (null: ask PHP_SAPI; tests set it).
	 *
	 * @var bool|null
	 */
	private static ?bool $cli_sapi = null;

	/** Loopbacks one job may send in a minute (ZHJOB1); a healthy chain sends ~15. */
	public const KICKS_PER_MINUTE = 30;
	/** Option: the seconds a loopback call really takes on this site (ZHJOB1). */
	public const KICK_COST = 'zseo_jobs_kick_cost';
	/** Seconds of loopback the 1 s margin under REQUEST_SECONDS already covers (0.25 s spare). */
	private const KICK_FREE = 0.75;
	/** The most a step keeps back for its loopback. */
	private const KICK_RESERVE_MAX = 2.0;
	/** Seconds after which a lock is considered dead. */
	private const LOCK_SECONDS = 120;

	/**
	 * Seconds after which a job that never stepped is tried again when its driver was refused
	 * (Q377 a): its first loopback and a watchdog run can each find the other holding the turn,
	 * and then nothing chained it until the 15-minute watchdog.
	 */
	public const RETRY_SECONDS = 30;

	/**
	 * Pieces of other plugins' background work one step's saves may queue before the step ends
	 * (HARDEN1, from zinnhub's 2,403-page apply: ~13,900 actions queued by other plugins).
	 */
	public const QUEUED_PER_STEP = 25;

	/** Seconds of rest after a step for each piece of background work its saves queued. */
	public const QUEUED_REST = 2;

	/** Memory a step may add before it ends (or MEMORY_GROWTH_SHARE of the limit, the larger). */
	public const MEMORY_GROWTH = 64 * 1048576;

	/** Memory a step adds before the in-memory object cache is emptied (and every time again). */
	public const MEMORY_FREE = 24 * 1048576;

	/** Share of the memory limit a step may add. */
	public const MEMORY_GROWTH_SHARE = 0.25;

	/** Share of the memory limit in use at which a step that added MEMORY_OWN of its own ends. */
	public const MEMORY_SHARE = 0.8;

	/** Memory a step must have added itself before MEMORY_SHARE ends it (a heavy site still moves). */
	public const MEMORY_OWN = 8 * 1048576;

	/**
	 * Background work queued by other plugins during the running step.
	 *
	 * @var int
	 */
	private static int $queued = 0;

	/**
	 * Why the running step ended before its deadline ('' it did not).
	 *
	 * @var string
	 */
	private static string $early = '';

	/**
	 * Times the running step emptied the in-memory object cache.
	 *
	 * @var int
	 */
	private static int $freed = 0;

	/**
	 * Memory in use when the running outermost step began.
	 *
	 * @var int
	 */
	private static int $memory_start = 0;

	/**
	 * Whether only time ends the running step: its job's last step ended early (memory, queued
	 * work) and moved nothing, so this one must move (never a step that cannot make progress).
	 *
	 * @var bool
	 */
	private static bool $time_only = false;

	/**
	 * Memory in use at which the running step next empties the in-memory object cache.
	 *
	 * @var int
	 */
	private static int $free_mark = 0;

	/**
	 * Whether this step request already chained the queue after its step.
	 *
	 * @var bool
	 */
	private static bool $kicked = false;

	/**
	 * Loopbacks this process has sent.
	 *
	 * @var int
	 */
	private static int $sends = 0;

	/** Watchdog cron hook. */
	public const WATCHDOG = 'zseo_jobs_watchdog';

	/** AJAX action of a step. */
	public const AJAX = 'zseo_job_step';

	/**
	 * Registered jobs: id => {label, step}.
	 *
	 * @var array<string, array{label: callable|string, step: callable}>
	 */
	private static array $registry = array();

	/**
	 * The job whose step runs in this process now ('' none): what `renew()` and `checkpoint()`
	 * act on.
	 *
	 * @var string
	 */
	private static string $current = '';

	/**
	 * Whether this process holds the site's turn (the outermost step it runs took it).
	 *
	 * @var bool
	 */
	private static bool $site = false;

	/** The lock row that makes the site's jobs run one at a time. */
	private const SITE = '_site';

	/**
	 * Seconds of work the running outermost step has (0: no step runs), for `reserve()`.
	 *
	 * @var float
	 */
	private static float $budget = 0.0;

	/** Steps in a row that may end the request before a job is stopped as failed (Q335). */
	private const DEATHS_MAX = 3;

	/**
	 * The step running in this process now, for the shutdown guard: `{id, outermost}` (empty
	 * when no step runs).
	 *
	 * @var array<string, mixed>
	 */
	private static array $stepping = array();

	/**
	 * Whether the shutdown guard is registered in this process.
	 *
	 * @var bool
	 */
	private static bool $guarded = false;

	/**
	 * Memory held while a step runs and given back the moment the request ends, so the shutdown
	 * guard of a step that ran out of memory still has room to count the death, give the locks
	 * back and chain the next step (Q349).
	 *
	 * @var string|null
	 */
	private static ?string $spare = null;

	/**
	 * Whether this WP-CLI process chains the queue by a loopback request when it ends (Q349).
	 *
	 * @var bool
	 */
	private static bool $leaving = false;

	/**
	 * Whether the end of this WP-CLI process is already set to chain the queue (Q349).
	 *
	 * @var bool
	 */
	private static bool $exit_kick = false;

	/**
	 * Whether the end of this WP-CLI process was already dealt with (Q366): by `on_exit()` in
	 * WordPress's `shutdown` action, or by the shutdown guard of a step that died. The
	 * registered net then does nothing.
	 *
	 * @var bool
	 */
	private static bool $exit_ran = false;

	/**
	 * Priority of `on_exit()` in WordPress's `shutdown` action (Q366): after every other
	 * callback (a job queued by another plugin's shutdown work is still chained), and still
	 * before `wp_cache_close()`, which `shutdown_action_hook()` calls once the action is over.
	 */
	public const EXIT_PRIORITY = PHP_INT_MAX;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::pace();
		add_action( 'wp_ajax_nopriv_' . self::AJAX, array( self::class, 'ajax_step' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( self::class, 'ajax_step' ) );
		add_action( self::WATCHDOG, array( self::class, 'watchdog' ) );
		// The end of a rest between steps (Q337) carries on like the watchdog does.
		add_action( Jobs_Pace::RESUME, array( self::class, 'watchdog' ) );
		add_filter( 'cron_schedules', array( self::class, 'schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- fifteen minutes, see schedule().
	}

	/**
	 * Load the pace (Q337) where no autoloader runs (a unit test that reads this file alone).
	 * Inside a function, so reading this file does nothing by itself (the class map needs that).
	 *
	 * @return void
	 */
	private static function pace(): void {
		if ( ! class_exists( Jobs_Pace::class ) ) {
			require_once __DIR__ . '/class-jobs-pace.php';
		}
	}

	/**
	 * A fifteen-minute schedule for the watchdog.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function schedule( $schedules ): array {
		$schedules                         = (array) $schedules;
		$schedules['zseo_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			// The schedules are asked for whenever anything is scheduled, also before `init`
			// (when no translation may be loaded yet): the name is translated once WordPress can.
			'display'  => did_action( 'init' ) ? __( 'Every fifteen minutes', 'zinn-seo' ) : 'Every fifteen minutes',
		);

		return $schedules;
	}

	/**
	 * Register a job type (modules call this on boot).
	 *
	 * @param string          $id    Job id, `[a-z0-9-]`.
	 * @param callable|string $label Label (or a callable returning it, so it is translated late).
	 * @param callable        $step  `function ( array $state, float $deadline ): array` returning
	 *                               `array( 'state' => array, 'done' => bool, 'progress' => array{done:int,total:int|null} )`;
	 *                               a non-empty string under `pause` pauses the job with that reason.
	 * @return void
	 */
	public static function register( string $id, $label, callable $step ): void {
		self::pace();
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,40}$/', $id ) ) {
			return;
		}
		self::$registry[ $id ] = array(
			'label' => $label,
			'step'  => $step,
		);
	}

	/**
	 * Forget the registry (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::pace();
		self::$registry = array();
	}

	/**
	 * The job whose step runs in this process now ('' none).
	 *
	 * @return string
	 */
	public static function current(): string {
		return self::$current;
	}

	/**
	 * A job's stored record.
	 *
	 * @param string $id Job id.
	 * @return array<string, mixed>
	 */
	public static function get( string $id ): array {
		$stored = get_option( 'zseo_job_' . $id, array() );
		$stored = is_array( $stored ) && array() !== $stored ? $stored : self::stored_row( $id );

		return $stored + array(
			'id'         => $id,
			'status'     => 'idle',
			'state'      => array(),
			'progress'   => array(
				'done'  => 0,
				'total' => null,
			),
			'started'    => 0,
			'updated'    => 0,
			'finished'   => 0,
			'steps'      => 0,
			'lock'       => 0,
			'wait'       => 0,
			'token'      => '',
			'error'      => '',
			'queued'     => 0.0,
			// Q335: pages the job could not read (their rendering ends the request), and the
			// steps in a row that ended the request before they returned.
			'unreadable' => array(),
			'deaths'     => 0,
			// Q349: of those, the ones in a row with no page to blame (DEATHS_MAX of them stop the job).
			'blind'      => 0,
			// Q337: the pace of its last step — level (0 calm, 1 busy, 2 heavy), why, the seconds
			// it had and the seconds the site rested after it.
			'pace'       => array(),
			// Steps in a row that returned at once having moved nothing (Q337): each doubles the
			// next step's time (up to STEP_SECONDS), so a job whose one item needs more than a
			// short step still finishes.
			'stalls'     => 0,
			// ZHJOB1: since when the site's own signals hold the job (0: not held), and the
			// loopback requests that chained it (all of them, and the ones the rate limit held back).
			'held'       => 0,
			'kicks'      => 0,
			'kicks_held' => 0,
			'kick_min'   => array(),
			// FIX12: how many times the job was started. A step that finds it changed was running
			// when the job was started again, and leaves the new run alone.
			'run'        => 0,
		);
	}

	/**
	 * A job's record for a screen: the stored record plus `waiting`, the label of the job whose
	 * turn it is when this one is pending behind it ('' when it is not waiting).
	 *
	 * @param string $id Job id.
	 * @return array<string, mixed>
	 */
	public static function view( string $id ): array {
		$job            = self::get( $id );
		$ahead          = 'pending' === (string) $job['status'] ? self::waiting_for( $id ) : '';
		$job['waiting'] = '' === $ahead ? '' : self::label( $ahead );
		// ZHJOB1: held by the site's own signals — a calm line, never an error.
		$job['paused_note'] = 'pending' === (string) $job['status'] && (int) $job['held'] > 0
			? __( 'Paused to keep your site fast — resumes automatically.', 'zinn-seo' )
			: '';
		unset( $job['token'], $job['kick_min'] );

		return $job;
	}

	/**
	 * A registered job's label.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	private static function label( string $id ): string {
		$label = self::$registry[ $id ]['label'] ?? $id;

		return is_callable( $label ) ? (string) call_user_func( $label ) : (string) $label;
	}

	/**
	 * Where a pending job stands in the site's queue: earlier start (or resume) first, the id
	 * breaking a tie. A record from before the queue existed counts from its start.
	 *
	 * @param array<string, mixed> $job Record.
	 * @return array{0: float, 1: string}
	 */
	private static function place( array $job ): array {
		$queued = (float) ( $job['queued'] ?? 0 );

		return array( $queued > 0 ? $queued : (float) $job['started'], (string) $job['id'] );
	}

	/**
	 * The job whose turn it is: the first pending job in the queue that is not waiting by its
	 * own choice ('' when there is none).
	 *
	 * @return string
	 */
	public static function next_in_line(): string {
		$best = null;
		foreach ( array_keys( self::$registry ) as $id ) {
			$job = self::get( $id );
			if ( 'pending' !== (string) $job['status'] || (int) $job['wait'] > time() ) {
				continue;
			}
			if ( null === $best || self::place( $job ) < self::place( $best ) ) {
				$best = $job;
			}
		}

		return null === $best ? '' : (string) $best['id'];
	}

	/**
	 * The job this one waits for ('' when it may step now): another pending job that is in a
	 * step this moment, or one ahead of it in the queue. A step driven from inside another job's
	 * step (the malware scan drives the spam scan) is part of that job's turn and never waits.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	public static function waiting_for( string $id ): string {
		if ( '' !== self::$current ) {
			return '';
		}
		$mine = self::place( self::get( $id ) );
		foreach ( array_keys( self::$registry ) as $other ) {
			if ( $other === $id ) {
				continue;
			}
			$job = self::get( $other );
			if ( 'pending' !== (string) $job['status'] ) {
				continue;
			}
			if ( self::running( $job ) || ( (int) $job['wait'] <= time() && self::place( $job ) < $mine ) ) {
				return $other;
			}
		}

		return '';
	}

	/**
	 * Is a step of this job running now, in some process (its lock is held and not expired)?
	 *
	 * @param array<string, mixed> $job Record.
	 * @return bool
	 */
	public static function running( array $job ): bool {
		return 'pending' === (string) $job['status'] && (int) $job['lock'] > time() - self::LOCK_SECONDS;
	}

	/**
	 * Take the job's lock: one INSERT that only one process can win. A lock older than
	 * LOCK_SECONDS belongs to a step the host killed and is removed first.
	 *
	 * @param string $id Job id.
	 * @return bool Whether this process holds it now.
	 */
	private static function acquire( string $id ): bool {
		global $wpdb;
		$name = 'zseo_joblock_' . $id; // `_site` is the whole site's turn: no job id starts with `_`.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a lock must be one atomic statement on the row itself: the options API reads, then writes, and caches.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - self::LOCK_SECONDS ) );
		$won = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')", $name, time() ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return 1 === (int) $won;
	}

	/**
	 * Give the job's lock back.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	private static function release( string $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'zseo_joblock_' . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see acquire().
	}

	/**
	 * Keep the running step's lock fresh. A step that waits on the network (a HEAD request to the
	 * site, an AI call) calls this as it goes, so a step that legitimately takes longer than
	 * LOCK_SECONDS is never taken for dead and run a second time by another driver (ZH5-5: the
	 * WP-Cron watchdog and a WP-CLI run both stepped one links-fix job and proposed chunks twice).
	 *
	 * @return bool Whether this process holds a job's lock (and it is fresh now).
	 */
	public static function renew(): bool {
		if ( '' === self::$current ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock row, see acquire().
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s", time(), 'zseo_joblock_' . self::$current ) );
		if ( self::$site ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the site's lock row, see acquire().
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s", time(), 'zseo_joblock_' . self::SITE ) );
		}

		return true;
	}

	/**
	 * The seconds a step keeps in hand before its deadline for one more item that waits on the
	 * network (an AI call, a request to another site): what it asks for, but never more than 40%
	 * of the step's own time — a reserve longer than a short step (Q337: 12 s of a 5 s step)
	 * would leave no time for even one item, and the job would never finish.
	 *
	 * @param float $seconds Seconds one item may take.
	 * @return float
	 */
	public static function reserve( float $seconds ): float {
		$budget = self::$budget > 0 ? self::$budget : self::STEP_SECONDS;

		return min( $seconds, 0.4 * $budget );
	}

	/**
	 * ⛔⛔ THE ONE QUESTION EVERY LOOP IN A STEP ASKS: is this step over? (HARDEN1, the zinnhub
	 * lessons.) Over when the deadline (less `$reserve`) has passed, AND, inside a job's step:
	 *
	 * - when the step's own saves made other plugins queue `queued_cap()` pieces of background
	 *   work (Action Scheduler actions, one-off cron events): zinnhub 2026-10-19, a link-plan apply
	 *   of 2,403 pages made other plugins queue ~13,900 actions (translation, AI extracts, vector
	 *   reindexes) at full speed; the step now ends there and the site rests for them;
	 * - when the step's memory has grown too far (`memory_out()`): zinnhub's zinn-translate sweep
	 *   held every post's meta in the object cache and was killed at 1.5 GB every hour. The
	 *   in-memory object cache is emptied first; only if that is not enough does the step end.
	 *
	 * A step that ends early has saved its cursor like any other, so the next one carries on: it
	 * bounds the STEP, never the work (§2.10).
	 *
	 * @param float $deadline The step's deadline (or any deadline: outside a step only time counts).
	 * @param float $reserve  Seconds kept back before it.
	 * @return bool
	 */
	public static function over( float $deadline, float $reserve = 0.0 ): bool {
		if ( microtime( true ) >= $deadline - $reserve ) {
			return true;
		}
		if ( '' === self::$current || self::$time_only ) {
			return false;
		}
		if ( self::$queued >= self::queued_cap() ) {
			self::$early = 'queued';
			return true;
		}
		if ( self::memory_out() ) {
			self::$early = 'memory';
			return true;
		}

		return false;
	}

	/**
	 * Pieces of other plugins' background work one step's saves may queue before the step ends
	 * (filter `zinn_seo_jobs_queued_per_step`, at least 1).
	 *
	 * @return int
	 */
	public static function queued_cap(): int {
		/**
		 * Filters how much background work (Action Scheduler actions, one-off cron events) other
		 * plugins may queue in reaction to one Zinn SEO step's saves before the step ends.
		 *
		 * @param int $cap Default QUEUED_PER_STEP.
		 */
		return max( 1, (int) apply_filters( 'zinn_seo_jobs_queued_per_step', self::QUEUED_PER_STEP ) );
	}

	/**
	 * Count background work queued while a step runs (Action Scheduler's `action_scheduler_stored_action`,
	 * WP-Cron's `schedule_event`). Zinn SEO's own events (`zseo_*`, `zinn_seo_*`) are not counted.
	 *
	 * @param mixed $event A cron event (filter) or an action id (action).
	 * @return mixed The event, unchanged.
	 */
	public static function count_queued( $event = null ) {
		if ( '' !== self::$current ) {
			$hook = is_object( $event ) && isset( $event->hook ) ? (string) $event->hook : '';
			if ( 0 !== strpos( $hook, 'zseo_' ) && 0 !== strpos( $hook, 'zinn_seo_' ) ) {
				++self::$queued;
			}
		}

		return $event;
	}

	/**
	 * Background work queued so far in this step (for tests and the step's pace record).
	 *
	 * @return int
	 */
	public static function queued(): int {
		return self::$queued;
	}

	/**
	 * PHP's memory limit in bytes (an unlimited one counts as 2 GB, so growth still has a bound).
	 *
	 * @return int
	 */
	public static function memory_limit(): int {
		$raw   = (string) ini_get( 'memory_limit' );
		$bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $raw ) : (int) $raw;

		return $bytes > 0 ? $bytes : 2147483648;
	}

	/**
	 * Has this step's memory grown too far? Growth since the step began past
	 * `max( MEMORY_GROWTH, MEMORY_GROWTH_SHARE × limit )`, or memory in use past MEMORY_SHARE of the
	 * limit once the step has added some of its own. The in-memory object cache is emptied first
	 * (`free()`): a loop that only cached what it read goes on.
	 *
	 * @param int|null $used  Bytes in use now (null: ask PHP; tests pass it).
	 * @param int|null $limit Limit (null: PHP's).
	 * @return bool
	 */
	public static function memory_out( ?int $used = null, ?int $limit = null ): bool {
		$limit = $limit ?? self::memory_limit();
		$read  = null === $used;
		$used  = $used ?? memory_get_usage();
		if ( $used < self::$free_mark && ! self::memory_high( $used, $limit ) ) {
			return false;
		}
		// What the loop read so far sits in the in-memory object cache: let it go first (each time
		// memory grows another MEMORY_FREE), and end the step only if it is still too high after.
		self::free();
		if ( $read ) {
			$used = memory_get_usage();
		}
		self::$free_mark = $used + self::MEMORY_FREE;

		return self::memory_high( $used, $limit );
	}

	/**
	 * Pure test of memory_out()'s rule.
	 *
	 * @param int $used  Bytes in use.
	 * @param int $limit Limit.
	 * @return bool
	 */
	private static function memory_high( int $used, int $limit ): bool {
		$grown = $used - self::$memory_start;
		$most  = (int) max( self::MEMORY_GROWTH, self::MEMORY_GROWTH_SHARE * $limit );

		return $grown >= $most || ( $used >= self::MEMORY_SHARE * $limit && $grown >= self::MEMORY_OWN );
	}

	/**
	 * Empty WordPress's in-memory object cache (its copy of what this request read), the way an
	 * object cache supports: `wp_cache_flush_runtime()` when it can, the whole cache when it is
	 * WordPress's own in-memory one, never a persistent cache's stored data (HARDEN1: every chunk of
	 * every loop calls this, so memory stays flat at any site size).
	 *
	 * @return void
	 */
	public static function free(): void {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} elseif ( ! wp_using_ext_object_cache() && function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush(); // WordPress's own in-memory cache: it holds only this request's reads.
		}
		++self::$freed;
	}

	/**
	 * Why the step running now ended before its deadline ('' it did not), and how often it emptied
	 * the in-memory cache.
	 *
	 * @return array{early: string, freed: int}
	 */
	public static function early(): array {
		return array(
			'early' => self::$early,
			'freed' => self::$freed,
		);
	}

	/**
	 * Start the step-level counters (an outermost step; tests call it).
	 *
	 * @return void
	 */
	public static function begin_counters(): void {
		self::$time_only    = false;
		self::$queued       = 0;
		self::$early        = '';
		self::$freed        = 0;
		self::$memory_start = memory_get_usage();
		self::$free_mark    = self::$memory_start + self::MEMORY_FREE;
	}

	/**
	 * Save the running step's state now, before the step ends: work that writes rows as it goes
	 * commits its cursor right after them, so a step the host kills, or a driver that dies, does
	 * not repeat what was already written (ZH5-5). The record is read afresh, so a person's pause
	 * or cancel made meanwhile stands.
	 *
	 * @param array<string, mixed> $state    The step's state.
	 * @param array<string, mixed> $progress `{done, total}`.
	 * @return bool Whether it was saved.
	 */
	public static function checkpoint( array $state, array $progress ): bool {
		if ( '' === self::$current ) {
			return false;
		}
		$job = self::get( self::$current );
		if ( 'pending' !== (string) $job['status'] ) {
			return false;
		}
		$job['state']    = $state;
		$job['progress'] = $progress;
		$job['updated']  = time();
		self::put( $job );
		self::renew();

		return true;
	}

	/**
	 * Store a job's record.
	 *
	 * @param array<string, mixed> $job Record.
	 * @return void
	 */
	private static function put( array $job ): void {
		unset( $job['waiting'], $job['resting'] ); // Computed for screens and drivers, never stored.
		update_option( 'zseo_job_' . $job['id'], $job, false );
		self::forget_missing( 'zseo_job_' . $job['id'] );
	}

	/**
	 * A job record the object cache says is missing, read from the database itself (ZHFIX8).
	 *
	 * ⛔ With a persistent object cache, WordPress keeps the names of missing options in ONE shared
	 * `notoptions` entry. A process that read a job record before it existed (a long step listing
	 * every job) keeps that entry in its own memory and writes it back on its next read of any
	 * missing option, so a job started meanwhile reads as "not there" for every process: Apply
	 * answered 200 and nothing ran (harness harden1, 3 of 6 runs; race-notoptions-repro.sh makes
	 * it every time). The database row is the truth: a record the cache calls missing is read from
	 * it, and the stale entry is dropped. A record that is really missing costs one indexed query
	 * per request (kept per request).
	 *
	 * @param string $id Job id.
	 * @return array<string, mixed> The stored record, or array() when there is none.
	 */
	private static function stored_row( string $id ): array {
		global $wpdb;
		static $none = array();
		if ( isset( $none[ $id ] ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array();
		}
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'zseo_job_' . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the cache said missing; the row is the truth (see above).
		if ( null === $raw ) {
			$none[ $id ] = true;
			return array();
		}
		$row = maybe_unserialize( (string) $raw );
		self::forget_missing( 'zseo_job_' . $id );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Drop an option from the object cache's list of missing options, wherever it came from.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private static function forget_missing( string $name ): void {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return;
		}
		$not = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $not ) && isset( $not[ $name ] ) ) {
			unset( $not[ $name ] );
			wp_cache_set( 'notoptions', $not, 'options' );
		}
	}

	/**
	 * Every registered job with its record, for screens, REST and WP-CLI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe(): array {
		self::pace();
		$out = array();
		foreach ( self::$registry as $id => $def ) {
			$job   = self::get( $id );
			$out[] = array(
				'id'          => $id,
				'label'       => is_callable( $def['label'] ) ? (string) call_user_func( $def['label'] ) : (string) $def['label'],
				'status'      => $job['status'],
				'progress'    => $job['progress'],
				'started'     => (int) $job['started'],
				'updated'     => (int) $job['updated'],
				'finished'    => (int) $job['finished'],
				'steps'       => (int) $job['steps'],
				'error'       => (string) $job['error'],
				'waiting'     => (string) self::view( $id )['waiting'],
				'paused_note' => (string) self::view( $id )['paused_note'],
				'unreadable'  => array_values( array_map( 'intval', (array) $job['unreadable'] ) ),
				'pace'        => (array) $job['pace'],
				'resting'     => Jobs_Pace::resting_until(),
			);
		}

		return $out;
	}

	/**
	 * Start (or restart) a job from the beginning.
	 *
	 * @param string               $id    Job id.
	 * @param array<string, mixed> $state Initial state.
	 * @return true|\WP_Error
	 */
	public static function start( string $id, array $state = array() ) {
		if ( ! isset( self::$registry[ $id ] ) ) {
			/* translators: %s: a job id. */
			return new \WP_Error( 'zinn_seo_unknown_job', sprintf( __( 'There is no background job called %s.', 'zinn-seo' ), $id ), array( 'status' => 404 ) );
		}
		$was = self::get( $id );
		$job = array_merge(
			$was,
			array(
				'status'     => 'pending',
				'state'      => $state,
				'progress'   => array(
					'done'  => 0,
					'total' => null,
				),
				'started'    => time(),
				'updated'    => time(),
				'finished'   => 0,
				'steps'      => 0,
				'lock'       => 0,
				'wait'       => 0,
				'error'      => '',
				'unreadable' => array(),
				'deaths'     => 0,
				'blind'      => 0,
				// A restart of a job already in the queue keeps its place (the writer restarts its
				// job each time more ideas are queued); anything else joins at the back.
				'queued'     => 'pending' === (string) $was['status'] && (float) $was['queued'] > 0 ? (float) $was['queued'] : microtime( true ),
				'run'        => (int) $was['run'] + 1,
			)
		);
		self::put( $job );
		self::ensure_watchdog();
		self::kick_next( $id );
		self::kick_on_exit();

		return true;
	}

	/**
	 * Pause, resume or cancel a job.
	 *
	 * @param string $id     Job id.
	 * @param string $action pause|resume|cancel.
	 * @return array<string, mixed>|\WP_Error The record.
	 */
	public static function control( string $id, string $action ) {
		if ( ! isset( self::$registry[ $id ] ) ) {
			/* translators: %s: a job id. */
			return new \WP_Error( 'zinn_seo_unknown_job', sprintf( __( 'There is no background job called %s.', 'zinn-seo' ), $id ), array( 'status' => 404 ) );
		}
		$job = self::get( $id );
		if ( 'pause' === $action && 'pending' === $job['status'] ) {
			$job['status'] = 'paused';
		} elseif ( 'resume' === $action && in_array( $job['status'], array( 'paused', 'failed' ), true ) ) {
			$job['status'] = 'pending';
			$job['error']  = '';
			$job['wait']   = 0;
			$job['deaths'] = 0; // A person resumed it once the cause was fixed: the count starts again.
			$job['blind']  = 0;
			$job['queued'] = microtime( true ); // Back of the queue: the jobs started meanwhile go first.
		} elseif ( 'cancel' === $action && in_array( $job['status'], array( 'pending', 'paused', 'failed' ), true ) ) {
			$job['status']   = 'cancelled';
			$job['finished'] = time();
		} else {
			return new \WP_Error( 'zinn_seo_job_state', __( 'The job cannot do that in its current state.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$job['updated'] = time();
		self::put( $job );
		if ( 'pending' === $job['status'] ) {
			self::ensure_watchdog();
			self::kick_next( $id );
			self::kick_on_exit();
		}

		return $job;
	}

	/**
	 * Run ONE step of a job, under its lock.
	 *
	 * @param string     $id       Job id.
	 * @param float|null $deadline Absolute microtime deadline (default now + STEP_SECONDS).
	 * @return array<string, mixed> The record after the step.
	 */
	public static function step( string $id, ?float $deadline = null ): array {
		self::pace();
		$job = self::get( $id );
		if ( ! isset( self::$registry[ $id ] ) || 'pending' !== $job['status'] ) {
			return $job;
		}
		// The record's own lock field is what releases up to 0.9.1 took (and what the screens
		// read); the row is what makes taking it atomic. Either one held means: not now.
		// ⛔ Q332: not this job's turn — another one is ahead of it, or stepping now. It waits, still
		// pending; nothing is dropped and nothing is chained from here (the job ahead chains it).
		$ahead = self::waiting_for( $id );
		if ( '' !== $ahead ) {
			$job['waiting'] = self::label( $ahead );
			return $job;
		}
		$outermost = '' === self::$current;
		if ( $outermost ) {
			// Q337: the site rests between steps. A person at WP-CLI waits it out here; every other
			// driver steps nothing now (and chains nothing): the rest's own cron event carries on.
			$resting = Jobs_Pace::resting_until();
			if ( $resting > 0 && defined( 'WP_CLI' ) && WP_CLI && ! wp_doing_cron() ) {
				sleep( max( 1, min( 300, $resting - time() ) ) );
				$resting = Jobs_Pace::resting_until();
			}
			if ( $resting > 0 ) {
				// ⛔ Q377: and make sure something ends it. The event that ended an earlier rest
				// may have been the one that brought this driver here, or a stale copy of the cron
				// array may have dropped it: every driver that finds the site resting re-arms it.
				Jobs_Pace::schedule_resume( $resting );
				$job['resting'] = $resting;
				return $job;
			}
			Jobs_Pace::rest_over();
		}
		if ( $outermost && ! self::acquire( self::SITE ) ) {
			// Another driver holds the site's turn this moment (a job that just stepped aside).
			$job['waiting'] = self::label( self::next_in_line() );
			$job['refused'] = true;
			return $job;
		}
		if ( (int) $job['lock'] > time() - self::LOCK_SECONDS || ! self::acquire( $id ) ) {
			// Another process is in a step. Its record says so, whatever was read above.
			if ( $outermost ) {
				self::release( self::SITE );
			}
			$job['lock']    = max( (int) $job['lock'], time() );
			$job['refused'] = true;
			return $job;
		}
		// Read again under the lock: the step that just released it has moved the state on. A
		// job that asked to wait is left alone until then.
		$job = self::get( $id );
		if ( 'pending' !== $job['status'] || (int) $job['wait'] > time() ) {
			self::release( $id );
			if ( $outermost ) {
				self::release( self::SITE );
			}
			return $job;
		}
		self::$site  = self::$site || $outermost;
		$job['lock'] = time();
		self::put( $job );

		// Q337: about five seconds of work, less when the site is pressed. A step driven from
		// inside another job's step keeps that step's own deadline.
		$pressure = $outermost ? Jobs_Pace::pressure() : array(
			'level' => 0,
			'why'   => '',
		);
		if ( $outermost && (int) $pressure['level'] >= Jobs_Pace::HELD ) {
			// ZHJOB1: the site is struggling (an Action Scheduler backlog its runner is working
			// through, a front end or a server far past busy). No step runs: the job stays pending,
			// nothing is chained, the rest's own event looks again, and the job carries on by itself
			// once the site has recovered. Not a failure, not a stop: the screens say it is paused.
			$rest        = Jobs_Pace::rest_after( Jobs_Pace::HELD, 0.0, 1.0 );
			$job['lock'] = 0;
			$job['held'] = (int) ( $job['held'] ?? 0 ) > 0 ? (int) $job['held'] : time();
			$job['pace'] = array(
				'level'  => Jobs_Pace::HELD,
				'why'    => (string) $pressure['why'],
				'budget' => 0,
				'worked' => 0,
				'rest'   => $rest,
			);
			self::put( $job );
			Jobs_Pace::rest( $rest );
			self::release( $id );
			self::release( self::SITE );
			self::$site     = false;
			$job['resting'] = Jobs_Pace::resting_until();
			return $job;
		}
		$budget = min( self::STEP_SECONDS, Jobs_Pace::budget( (int) $pressure['level'] ) * ( 2 ** min( 5, (int) $job['stalls'] ) ) );
		$began  = microtime( true );
		if ( null === $deadline && $outermost && 0 === (int) $job['stalls'] ) {
			$deadline = self::request_deadline( $began, $budget );
		}
		$deadline     = $deadline ?? $began + $budget;
		$before_state = wp_json_encode( $job['state'] );
		if ( $outermost ) {
			self::$budget = max( 0.1, $deadline - $began );
			// HARDEN1: what this step makes other plugins queue, and its memory, end it early
			// (`over()`); counted from here for this step alone.
			self::begin_counters();
			self::$time_only = '' !== (string) ( $job['pace']['early'] ?? '' ) && (int) $job['stalls'] > 0;
			add_filter( 'schedule_event', array( self::class, 'count_queued' ), PHP_INT_MAX );
			add_action( 'action_scheduler_stored_action', array( self::class, 'count_queued' ) );
		}
		$outer          = self::$current; // A step may drive another job's step (the autopilot drives the link plan).
		$outer_stepping = self::$stepping;
		self::$current  = $id;
		self::$stepping = array(
			'id'        => $id,
			'outermost' => $outermost,
			// For the shutdown guard's rest after a step that dies (the same back-off as one that returns).
			'began'     => $began,
			'budget'    => $deadline - $began,
			'level'     => (int) $pressure['level'],
		);
		self::guard();
		try {
			$result = call_user_func( self::$registry[ $id ]['step'], (array) $job['state'], $deadline );
		} catch ( \Throwable $error ) {
			if ( $outermost ) {
				self::$budget = 0.0;
				remove_filter( 'schedule_event', array( self::class, 'count_queued' ), PHP_INT_MAX );
				remove_action( 'action_scheduler_stored_action', array( self::class, 'count_queued' ) );
			}
			self::$current     = $outer;
			self::$stepping    = $outer_stepping;
			$job['status']     = 'failed';
			$job['error']      = $error->getMessage();
			$job['lock']       = 0;
			$job['unreadable'] = self::unreadable_now( $job );
			self::put( $job );
			self::release( $id );
			if ( $outermost ) {
				self::release( self::SITE );
				self::$site = false;
			}
			return $job;
		}
		self::$current  = $outer;
		self::$stepping = $outer_stepping;
		if ( $outermost ) {
			remove_filter( 'schedule_event', array( self::class, 'count_queued' ), PHP_INT_MAX );
			remove_action( 'action_scheduler_stored_action', array( self::class, 'count_queued' ) );
		}
		// A person may have paused or cancelled the job while the step ran: that decision stands.
		$now = self::get( $id );
		if ( (int) $now['run'] !== (int) $job['run'] ) {
			// ⛔ FIX12: the job was STARTED AGAIN while this step ran (the writer restarts its job
			// with the ideas queued meanwhile; a person presses Start). Writing this step's record
			// back would undo that start — its new state, its reset counters — so the new run is
			// left as it is and carries on from its own beginning. This step's work is not lost to
			// the site (what it wrote, it wrote); only its now-stale cursor is dropped.
			if ( $outermost ) {
				self::$budget = 0.0;
			}
			self::release( $id );
			if ( $outermost ) {
				self::release( self::SITE );
				self::$site = false;
			}
			return $now;
		}
		$result            = is_array( $result ) ? $result : array();
		$job['state']      = (array) ( $result['state'] ?? $job['state'] );
		$job['progress']   = (array) ( $result['progress'] ?? $job['progress'] );
		$job['steps']      = (int) $job['steps'] + 1;
		$job['updated']    = time();
		$job['lock']       = 0;
		$job['wait']       = 0;
		$job['deaths']     = 0;
		$job['blind']      = 0;
		$job['held']       = 0;
		$job['unreadable'] = self::unreadable_now( $job );
		if ( in_array( (string) $now['status'], array( 'paused', 'cancelled' ), true ) ) {
			$job['status']   = (string) $now['status'];
			$job['finished'] = (int) $now['finished'];
		} elseif ( ! empty( $result['done'] ) ) {
			$job['status']   = 'done';
			$job['finished'] = time();
			// Finished — and says which pages it could not read (Q335), never silently.
			$job['error'] = self::unreadable_text( (array) $job['unreadable'] );
		} elseif ( isset( $result['pause'] ) && is_string( $result['pause'] ) && '' !== $result['pause'] ) {
			// The step cannot go on for a reason only a person (or a top-up) can change: it waits
			// where it is, with the reason, and `control( 'resume' )` carries on from its state.
			$job['status'] = 'paused';
			$job['error']  = $result['pause'];
		} elseif ( (int) ( $result['wait'] ?? 0 ) > 0 ) {
			// The step asks for a pause of its own: pending, but no step until then.
			$job['wait']  = time() + min( DAY_IN_SECONDS, (int) $result['wait'] );
			$job['error'] = (string) ( $result['reason'] ?? '' );
		} else {
			$job['error'] = '';
		}
		if ( $outermost ) {
			self::$budget = 0.0;
			$worked       = microtime( true ) - $began;
			$moved        = wp_json_encode( $job['state'] ) !== $before_state;
			// A step that returned at once and moved nothing had too little time for one item.
			$job['stalls'] = 'pending' === (string) $job['status'] && 0 === (int) $job['wait'] && ! $moved && $worked < 0.5 * ( $deadline - $began ) ? (int) $job['stalls'] + 1 : 0;
			$rest          = Jobs_Pace::rest_after( (int) $pressure['level'], microtime( true ) - $began, max( 0.1, $deadline - $began ) );
			// HARDEN1: the site's own runners get time for what this step's saves made other
			// plugins queue, before the next step adds more (never past 300 s).
			$rest        = max( $rest, min( 300, self::$queued * self::QUEUED_REST ) );
			$job['pace'] = array(
				'level'  => (int) $pressure['level'],
				'why'    => (string) $pressure['why'],
				'budget' => round( $deadline - $began, 2 ),
				'worked' => round( microtime( true ) - $began, 2 ),
				'rest'   => 'pending' === (string) $job['status'] ? $rest : 0,
				'queued' => self::$queued,
				'early'  => self::$early,
				'freed'  => self::$freed,
				'mem'    => (int) round( memory_get_peak_usage() / 1048576 ),
			);
			if ( 'pending' === (string) $job['status'] ) {
				Jobs_Pace::rest( $rest );
			}
		}
		self::put( $job );
		self::release( $id );
		if ( $outermost ) {
			self::release( self::SITE );
			self::$site = false;
		}

		return $job;
	}

	/**
	 * The pages this job could not read so far: what it had, plus the ones the step just ran
	 * was refused (Render's record of pages that end the request).
	 *
	 * @param array<string, mixed> $job Record.
	 * @return array<int, int>
	 */
	private static function unreadable_now( array $job ): array {
		$ids = array_map( 'intval', (array) ( $job['unreadable'] ?? array() ) );
		if ( class_exists( '\\ZinnDigital\\ZinnSEO\\Analysis\\Render', false ) ) {
			$ids = array_merge( $ids, \ZinnDigital\ZinnSEO\Analysis\Render::take_skipped() );
			// A page that changed since it ended a request is read again: no longer unreadable.
			$ids = array_diff( $ids, \ZinnDigital\ZinnSEO\Analysis\Render::take_cleared() );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * "N pages could not be read: <ids>" ('' when every page was read).
	 *
	 * @param array<int, int> $ids Pages.
	 * @return string
	 */
	public static function unreadable_text( array $ids ): string {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids ) {
			return '';
		}
		$shown = array_slice( $ids, 0, 20 );
		$list  = implode( ', ', $shown ) . ( count( $ids ) > 20 ? ', …' : '' );

		return sprintf(
			/* translators: 1: how many pages, 2: their post IDs. */
			_n(
				'%1$d page could not be read: %2$s. Something on it (a shortcode or a filter) redirects or ends the request while it is shown; it is skipped until it changes.',
				'%1$d pages could not be read: %2$s. Something on them (a shortcode or a filter) redirects or ends the request while they are shown; they are skipped until they change.',
				count( $ids ),
				'zinn-seo'
			),
			count( $ids ),
			$list
		);
	}

	/**
	 * When an outermost step that begins at `$began` with `$budget` seconds must end (Q367): in a
	 * web request, by REQUEST_SECONDS after the request began (its WordPress start counts), with
	 * MIN_WORK at least. WP-CLI, or PHP run from a terminal or system cron, is no web request: the
	 * budget alone.
	 * A step whose job made no progress last time (`stalls`) keeps its longer budget: its next
	 * item needs it.
	 *
	 * @param float      $began  Step start (microtime).
	 * @param float      $budget Seconds of work the pace gives it.
	 * @param float|null $start  Request start (default `$_SERVER['REQUEST_TIME_FLOAT']`; tests).
	 * @param bool|null  $cli    Running under WP-CLI (default: the constant; tests).
	 * @return float Absolute microtime.
	 */
	public static function request_deadline( float $began, float $budget, ?float $start = null, ?bool $cli = null ): float {
		// Not a web request: WP-CLI, and any PHP run from a terminal or system cron (`php
		// wp-cron.php`), whose process start is no request start.
		$sapi = self::$cli_sapi ?? ( 'cli' === PHP_SAPI );
		$cli  = $cli ?? ( $sapi || ( defined( 'WP_CLI' ) && WP_CLI ) );
		if ( null === $start ) {
			$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $began;
		}
		if ( $cli || $start <= 0 || $start > $began ) {
			return $began + $budget;
		}
		/**
		 * Filters the seconds a web request that runs one background-job step should take in
		 * all (a host whose slow-request mark is not 5 s can say so).
		 *
		 * @param float $seconds Seconds from the request's start.
		 */
		$whole = (float) apply_filters( 'zinn_seo_jobs_request_seconds', self::REQUEST_SECONDS );

		// ZHJOB1: and the loopback that chains the next step comes after the step, inside this
		// same request (~1-1.5 s on a real host). REQUEST_SECONDS leaves 1 s under the 5 s mark for
		// what follows the step; a loopback dearer than KICK_FREE takes the rest out of the step.
		$whole -= max( 0.0, self::kick_reserve() - self::KICK_FREE );

		return min( $began + $budget, max( $began + min( self::MIN_WORK, $budget ), $start + $whole ) );
	}

	/**
	 * Register the shutdown guard once per process, and hold its spare memory.
	 *
	 * ⛔ Q349 (LIVE1, zinnhub 0.17.51): a step that ended the request left its job dead for 13
	 * minutes — the record never counted the death (deaths 0), the locks stayed taken, nothing
	 * chained the next step, until the 15-minute watchdog. A function registered with
	 * `register_shutdown_function()` runs AFTER WordPress's own (`shutdown_action_hook`, which
	 * runs every plugin's `shutdown` callback), and if any of those ends the process (`exit`),
	 * or the host's time runs out there, nothing registered after it runs at all. So the guard
	 * runs FIRST in WordPress's `shutdown` action as well, and the registered function stays as
	 * the net for a request that never reaches the action. It acts once.
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( null === self::$spare ) {
			self::$spare = str_repeat( ' ', 1048576 );
		}
		if ( self::$guarded ) {
			return;
		}
		self::$guarded = true;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( self::class, 'on_shutdown' ), PHP_INT_MIN );
		}
		register_shutdown_function( array( self::class, 'on_shutdown' ) );
	}

	/**
	 * The request is ending. Inside a step, the step ended it before it returned (a page whose
	 * shortcode redirects and exits, a `die()`, a fatal error — ZH7-1).
	 *
	 * ⛔ Q349: what keeps the job alive is done FIRST and cheaply — the death is counted, the
	 * record's lock cleared and saved, the lock rows given back, the next step chained (after the
	 * site's usual rest, `Jobs_Pace`, the same back-off a step that returned gets) — and only then
	 * the page that did it is named and skipped from now on. Every death counts (`deaths`); a
	 * death with no page to blame also counts toward the cap (`blind`): after DEATHS_MAX of those
	 * in a row the job stops as failed, with its reason, and nothing chains it again. A page to
	 * blame never stops the job: the next step gets past it.
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		if ( array() === self::$stepping ) {
			return;
		}
		$stepping       = self::$stepping;
		$id             = (string) $stepping['id'];
		$outermost      = ! empty( $stepping['outermost'] );
		self::$stepping = array();
		self::$current  = '';
		self::headroom();

		$render = class_exists( '\\ZinnDigital\\ZinnSEO\\Analysis\\Render', false );
		$page   = $render ? \ZinnDigital\ZinnSEO\Analysis\Render::culprit() : 0;

		// 1. Count the death and clear the record's lock, at once (the rest may not get to run).
		$job            = self::get( $id );
		$job['lock']    = 0;
		$job['steps']   = (int) $job['steps'] + 1;
		$job['updated'] = time();
		$job['deaths']  = (int) $job['deaths'] + 1;
		// A page to blame is skipped from now on, so the next step gets past it: no step toward the cap.
		$job['blind'] = $page > 0 ? 0 : (int) $job['blind'] + 1;
		$job['error'] = $page > 0
			/* translators: %d: a post ID. */
			? sprintf( __( 'Page %d ended the request while it was read (a shortcode or a filter on it redirects or exits). It is skipped and the job carries on.', 'zinn-seo' ), $page )
			: __( 'A step of this job ended the request before it finished (something on the site stops PHP). It is tried again.', 'zinn-seo' );
		self::stop_at_cap( $job );
		self::put( $job );

		// 2. Record the page that did it, so it is skipped from now on, and drop what it printed.
		if ( $render ) {
			\ZinnDigital\ZinnSEO\Analysis\Render::on_shutdown();
		}
		$job['unreadable'] = self::unreadable_now( $job );
		if ( $page > 0 ) {
			$job['unreadable'] = array_values( array_unique( array_merge( $job['unreadable'], array( $page ) ) ) );
		}
		self::put( $job );

		// 3. Give the locks back and chain the next step, after the site's usual rest. Last: the
		// next step may start in another process the moment it is chained.
		self::release( $id );
		if ( $outermost ) {
			self::release( self::SITE );
			self::$site = false;
			if ( 'pending' === (string) $job['status'] ) {
				$began = (float) ( $stepping['began'] ?? microtime( true ) );
				Jobs_Pace::rest( Jobs_Pace::rest_after( (int) ( $stepping['level'] ?? 0 ), microtime( true ) - $began, max( 0.1, (float) ( $stepping['budget'] ?? self::STEP_SECONDS ) ) ) );
			}
		}
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( $cli ) {
			self::$exit_ran = true; // The end of this process is dealt with here: on_exit() chains nothing more.
		}
		if ( $cli && class_exists( '\\WP_CLI', false ) ) {
			\WP_CLI::warning( (string) $job['error'] . ' Run the command again to carry on.' );
		}
		if ( 'pending' === (string) $job['status'] && '' !== self::next_in_line() ) {
			if ( $cli && ! wp_doing_cron() ) {
				self::leave( $id ); // The terminal's own driving ended with the process.
			} else {
				self::kick_next( $id );
			}
		}
	}

	/**
	 * Stop a job as failed when DEATHS_MAX steps in a row ended the request with no page to blame.
	 *
	 * @param array<string, mixed> $job Record (changed in place).
	 * @return void
	 */
	private static function stop_at_cap( array &$job ): void {
		if ( 'pending' !== (string) $job['status'] || (int) $job['blind'] < self::DEATHS_MAX ) {
			return;
		}
		$job['status']   = 'failed';
		$job['finished'] = time();
		/* translators: %d: how many steps. */
		$job['error'] = sprintf( __( 'The job stopped: %d steps in a row ended the request before they finished (something on the site stops PHP). Resume it once that is fixed.', 'zinn-seo' ), (int) $job['blind'] );
	}

	/**
	 * Room for the shutdown guard of a step that ran out of memory or time: the spare memory goes
	 * back, the memory limit is raised as WordPress raises it for admin work, and the time limit
	 * starts again.
	 *
	 * @return void
	 */
	private static function headroom(): void {
		self::$spare = null;
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'zinn_seo_jobs' ); // WordPress's own: up to WP_MAX_MEMORY_LIMIT, never down.
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- the guard of a step that ran out of time must still finish its few writes.
		}
	}

	/**
	 * Run a job to the end in this process (WP-CLI).
	 *
	 * @param string        $id      Job id.
	 * @param callable|null $tick    Called after each step with the record.
	 * @return array<string, mixed> The final record.
	 */
	public static function run( string $id, ?callable $tick = null ): array {
		// Q339: since Q332 a job queued behind another only waited here, and a site whose jobs a
		// terminal drives (WP-CLI makes no loopback request) never ran the one ahead — so
		// `wp zinn-seo links build` and every other command that runs a job to the end in this
		// process stopped at "waiting" (a page save queues the analysis). Whoever runs a job to
		// the end here runs the jobs ahead of it first, one at a time, then this one. A job ahead
		// that another process is stepping now is left to it: nothing steps beside it.
		for ( $guard = 0; $guard < 50; $guard++ ) {
			// The FIRST job in the queue: the one ahead may itself be queued behind another (the
			// analysis a page save queued, behind the sitemaps a save also queued).
			$ahead = '' === self::waiting_for( $id ) ? '' : self::next_in_line();
			if ( '' === $ahead || $ahead === $id ) {
				break;
			}
			if ( class_exists( '\\WP_CLI', false ) ) {
				\WP_CLI::log( sprintf( 'Running "%s" first: one background job at a time per site, and it is ahead in the queue.', self::label( $ahead ) ) );
			}
			$before = (int) self::get( $ahead )['steps'];
			$after  = self::run_alone( $ahead, $tick );
			if ( 'pending' === (string) $after['status'] && (int) $after['steps'] === $before ) {
				break; // It could not step here (held by another process, or resting).
			}
		}

		return self::run_alone( $id, $tick );
	}

	/**
	 * Run ONE job to the end in this process, while it is its turn.
	 *
	 * @param string        $id   Job id.
	 * @param callable|null $tick Called after each step with the record.
	 * @return array<string, mixed> The final record.
	 */
	private static function run_alone( string $id, ?callable $tick = null ): array {
		$job = self::get( $id );
		while ( 'pending' === $job['status'] ) {
			$job = self::step( $id );
			if ( null !== $tick ) {
				$tick( $job );
			}
			// Held by another process, or waiting by its own choice: nothing to do here now.
			if ( (int) $job['lock'] > 0 || (int) $job['wait'] > time() || '' !== (string) ( $job['waiting'] ?? '' ) || (int) ( $job['resting'] ?? 0 ) > 0 ) {
				break;
			}
		}

		return $job;
	}

	/**
	 * Chain the next step with a non-blocking loopback request.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function kick( string $id ): void {
		self::pace();
		/**
		 * Filters whether the next step is chained by a loopback request. A site whose host or
		 * security plugin blocks requests to itself can turn it off: the watchdog then finishes
		 * every job, one step per run.
		 *
		 * @param bool   $loopback Use the loopback.
		 * @param string $id       Job id.
		 */
		if ( ! ( self::$leaving || self::loopback_here( defined( 'WP_CLI' ) && WP_CLI, wp_doing_cron() ) ) || ! apply_filters( 'zinn_seo_jobs_loopback', true, $id ) ) {
			return;
		}
		if ( self::$kicked && function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			// HARDEN1: this step request already chained the queue; the request it started steps
			// whoever is next, a job queued since included (IndexNow's, queued as a bulk apply's
			// request ends). A second loopback here cost the request another ~1 s (5.5 s).
			return;
		}
		$resting = Jobs_Pace::resting_until();
		if ( $resting > 0 ) {
			// Q337: the site rests; the rest's own event chains the next step when it ends.
			Jobs_Pace::schedule_resume( $resting );
			return;
		}
		$job = self::get( $id );
		// ZHJOB1: a rate limit on the chain, whatever drives it (a step, a re-kick after a death,
		// the CLI exit, the watchdog): at most KICKS_PER_MINUTE loopbacks a minute per job. A
		// healthy chain makes ~15 (one per ~4 s step); more is a loop, and the next one then waits
		// for the rest's own event at the next minute instead of answering itself at once.
		$minute = (int) floor( time() / 60 );
		$window = (array) ( $job['kick_min'] ?? array() );
		$count  = (int) ( $window[0] ?? -1 ) === $minute ? (int) ( $window[1] ?? 0 ) : 0;
		if ( $count >= self::KICKS_PER_MINUTE ) {
			$job['kicks_held'] = (int) ( $job['kicks_held'] ?? 0 ) + 1;
			self::put( $job );
			Jobs_Pace::rest( max( 1, ( $minute + 1 ) * 60 - time() ) );
			return;
		}
		$job['kick_min'] = array( $minute, $count + 1 );
		$job['kicks']    = (int) ( $job['kicks'] ?? 0 ) + 1;
		$token           = wp_generate_password( 32, false, false );
		$job['token']    = wp_hash( $token );
		self::put( $job );
		$sent = microtime( true );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
				'body'      => array(
					'action' => self::AJAX,
					'job'    => $id,
					'token'  => $token,
				),
			)
		);
		self::note_kick_cost( microtime( true ) - $sent );
		++self::$sends;
	}

	/**
	 * Remember what a "non-blocking" loopback really cost this request (ZHJOB1). WordPress's HTTP
	 * library gives cURL a timeout of one second at least, and the step it starts answers later
	 * than that, so on a real host the request that chains waits ~1-1.5 s (zinnhub: 1.45 s) —
	 * after its step, inside the request the host measures. The next steps end that much earlier
	 * (`request_deadline()`), so the whole request still stays inside REQUEST_SECONDS + a little.
	 * Written only when it moved by a tenth of a second or more.
	 *
	 * @param float $seconds Seconds the loopback call took.
	 * @return void
	 */
	private static function note_kick_cost( float $seconds ): void {
		$old = get_option( self::KICK_COST, null );
		$new = null === $old || '' === $old ? $seconds : 0.7 * (float) $old + 0.3 * $seconds;
		$new = round( max( 0.0, min( self::KICK_RESERVE_MAX, $new ) ), 2 );
		if ( null === $old || '' === $old || abs( $new - (float) $old ) >= 0.1 ) {
			update_option( self::KICK_COST, $new, false );
		}
	}

	/**
	 * Seconds a step in a web request keeps for the loopback that chains the next one: the cost
	 * measured on this site, else one second (what WordPress's cURL transport takes at least).
	 *
	 * @return float
	 */
	public static function kick_reserve(): float {
		$cost = get_option( self::KICK_COST, null );

		return null === $cost || '' === $cost ? 1.0 : max( 0.0, min( self::KICK_RESERVE_MAX, (float) $cost ) );
	}


	/**
	 * Chain whichever job's turn it is now (Q332): the one just started or stepped when it is
	 * first in the queue, else the job ahead of it. Nothing when no job is pending.
	 *
	 * @param string $fallback Job to chain when the queue cannot say (it is not registered here).
	 * @return void
	 */
	private static function kick_next( string $fallback ): void {
		$next = self::next_in_line();
		if ( '' !== $next ) {
			self::kick( $next );
		} elseif ( ! isset( self::$registry[ $fallback ] ) ) {
			self::kick( $fallback );
		}
	}

	/**
	 * A job queued from WP-CLI (Q349): chain the queue by a loopback request when this process
	 * ENDS, not at once. ⛔ At once would be a second driver beside a command that runs the job to
	 * the end right here (`wp zinn-seo jobs run`, `links build` …); at the end, a job that is
	 * still pending — the command only queued it (a page saved with `wp post update`), or it
	 * waits behind another job — starts within seconds instead of at the 15-minute watchdog
	 * (LIVE1: zinnhub's `autopilot apply` sat queued until the watchdog). The site's own switch
	 * (`zinn_seo_jobs_loopback`, for a host that blocks requests to itself) still decides, and
	 * WP-CLI running cron chains at once as before.
	 *
	 * @return void
	 */
	private static function kick_on_exit(): void {
		if ( self::$exit_kick || ! ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}
		self::$exit_kick = true;
		// ⛔ Q366 (PROOF4, zinnhub 0.17.53): a function registered with register_shutdown_function()
		// runs AFTER `shutdown_action_hook()` has called wp_cache_close(). A persistent object cache
		// that disconnects there (the Zinn Cache Redis drop-in) then keeps the new loopback token in
		// this process only: the database has it, the cache the loopback request reads does not,
		// and the step is refused (403) until the 15-minute watchdog. So the chain is set in
		// WordPress's own `shutdown` action, while the cache still takes writes; the registered
		// function stays as the net for a process that never reaches the action.
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( self::class, 'on_exit' ), self::EXIT_PRIORITY );
		}
		register_shutdown_function( array( self::class, 'on_exit' ) );
	}

	/**
	 * The WP-CLI process that queued a job ends: chain whichever job's turn it is, unless a step
	 * of it runs in another process now (that driver chains the next) or a step died here (the
	 * shutdown guard chains it).
	 *
	 * @return void
	 */
	public static function on_exit(): void {
		if ( self::$exit_ran ) {
			return; // Once: the action ran it, or the guard of a step that died chained the queue.
		}
		self::$exit_ran = true;
		if ( array() !== self::$stepping ) {
			return;
		}
		$next = self::next_in_line();
		if ( '' === $next || self::running( self::get( $next ) ) ) {
			return;
		}
		self::leave( $next );
	}

	/**
	 * Chain a job by a loopback request from a WP-CLI process that is ending (its own driving is
	 * over, so the loopback is the only one).
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	private static function leave( string $id ): void {
		self::$leaving = true;
		try {
			self::kick_next( $id );
		} finally {
			self::$leaving = false;
		}
	}

	/**
	 * May this process chain the next step by a loopback request?
	 *
	 * A person at WP-CLI (`wp zinn-seo jobs run`) drives the job in-process, so a loopback there
	 * would only be a second driver. ⛔ But WP-CLI that is RUNNING CRON is not a person: a host
	 * with DISABLE_WP_CRON runs `wp cron event run --due-now` from system cron, and the watchdog
	 * it runs must start the chain like web cron does. Refusing it there left a job one 20 s step
	 * per 15 minutes (ZH6-3: zinnhub's 12,755-page link plan, ~32 h instead of ~1 h).
	 *
	 * @param bool $cli  Running under WP-CLI.
	 * @param bool $cron Running WP-Cron (`wp_doing_cron()`).
	 * @return bool
	 */
	public static function loopback_here( bool $cli, bool $cron ): bool {
		return ! $cli || $cron;
	}

	/**
	 * The loopback endpoint: one step, then chain the next while work remains.
	 *
	 * ⭐ Authorised by the single-use token alone (the request comes from the site itself, with
	 * no user); the token's hash is cleared before the step runs, so a replayed request does
	 * nothing.
	 *
	 * @return void
	 */
	public static function ajax_step(): void {
		self::$kicked = false; // One request, one chaining loopback at most.
		$id           = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised by the single-use token below.
		$token        = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the authorisation.
		$job          = self::get( $id );
		if ( '' === $id || '' === $token || '' === (string) $job['token'] || ! hash_equals( (string) $job['token'], wp_hash( $token ) ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		$job['token'] = '';
		self::put( $job );
		$before = (int) $job['steps'];
		$job    = self::step( $id );
		// ⭐ Only a driver whose step RAN chains, and it chains whoever's turn it is now: the same
		// job while it is first, the next in the queue once it is finished, paused or waiting. A
		// driver that was refused (held, or not its turn) chains nothing, so no circle of requests.
		if ( (int) $job['steps'] > $before && '' !== self::next_in_line() ) {
			$sends = self::$sends;
			self::kick_next( $id );
			// Chained AFTER the step: a job queued later in this request (IndexNow's, at its end) is
			// stepped by the request just started. A loopback sent DURING the step (a job it
			// started) does not count: that one finds the step running and chains nothing.
			self::$kicked = self::$sends > $sends;
		} else {
			self::retry_refused( $job );
			self::maybe_clear_watchdog();
		}
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * The watchdog: restart any pending job whose chain broke (a host that blocks loopback
	 * requests still finishes, more slowly).
	 *
	 * @return void
	 */
	public static function watchdog(): void {
		self::pace();
		Jobs_Pace::rest_over();
		// Q332: one job per site, so the watchdog steps the one whose turn it is, never them all.
		$id = self::next_in_line();
		if ( '' === $id ) {
			self::maybe_clear_watchdog();
			return;
		}
		$before = (int) self::get( $id )['steps'];
		$job    = self::step( $id );
		if ( (int) $job['steps'] > $before && '' !== self::next_in_line() ) {
			self::kick_next( $id );
		} else {
			self::retry_refused( $job );
		}
	}

	/**
	 * ⛔ Q377 (a): a driver of a job that has NEVER stepped was refused the turn (another driver
	 * held it that moment). A driver that is refused chains nothing — the holder chains the next
	 * step — but when the holder is a driver of a step that never ran, the two can refuse each
	 * other (the job's first loopback and a watchdog run), and nothing chained the job until the
	 * 15-minute watchdog. So the refused driver of a job with no step yet asks for one short retry
	 * (the rest's own one-off event, RETRY_SECONDS from now). Only before the first step: once a
	 * job has stepped, its own chain carries on and a retry would only be a second driver.
	 *
	 * @param array<string, mixed> $job The record `step()` returned.
	 * @return void
	 */
	private static function retry_refused( array $job ): void {
		if ( empty( $job['refused'] ) || 'pending' !== (string) $job['status'] || (int) $job['steps'] > 0 ) {
			return;
		}
		Jobs_Pace::schedule_resume( time() + self::RETRY_SECONDS );
	}

	/**
	 * Does the driver that just ran `step()` chain the next one? Only when ITS step ran, work
	 * remains and the job did not ask to wait.
	 *
	 * ⛔ A driver whose step did not run (the job is held by another process) must not chain: the
	 * holder does. Chaining there made a second driver answer itself in a tight circle of
	 * requests for as long as the first one's step lasted.
	 *
	 * @param array<string, mixed> $job    The record after `step()`.
	 * @param int                  $before Steps counted before it.
	 * @return bool
	 */
	public static function chains( array $job, int $before ): bool {
		return 'pending' === (string) $job['status'] && (int) $job['steps'] > $before && (int) $job['wait'] <= time();
	}

	/**
	 * Schedule the watchdog if it is not.
	 *
	 * @return void
	 */
	private static function ensure_watchdog(): void {
		if ( ! wp_next_scheduled( self::WATCHDOG ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'zseo_fifteen_minutes', self::WATCHDOG );
		}
	}

	/**
	 * Remove the watchdog when no job is pending.
	 *
	 * @return void
	 */
	public static function maybe_clear_watchdog(): void {
		foreach ( array_keys( self::$registry ) as $id ) {
			if ( 'pending' === self::get( $id )['status'] ) {
				return;
			}
		}
		wp_clear_scheduled_hook( self::WATCHDOG );
	}
}
