<?php
/**
 * Switching the other SEO plugin off — only after the proof — and back on (owner ruling, round 3:
 * "Import, then switch the old one off").
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ THE OTHER PLUGIN IS DEACTIVATED ONLY WHEN THE SIDE-BY-SIDE CHECK HAS PASSED for this site:
 * every page equal, or every listed difference accepted by a person. `off()` refuses otherwise.
 *
 * ⭐ And then it is proven a second time, by the page itself: once the other plugin is off, the
 * addresses kept in the baseline are fetched again — rendered by THIS plugin now — and compared
 * with what the other plugin showed there. One page that says something else, or one page with a
 * tag printed twice, and the other plugin is switched straight back on and the move reported as
 * not done. Nothing of the other plugin is deleted, so switching it back on restores it exactly.
 *
 * ⭐ Queue Q263 (zinnhub, 2026-10-14): the copy must have been read back first (Verify), and the
 * switch is one job, in this order — each step that fails leaves the other plugin serving:
 *
 * 1. `before` — the key pages and files (SelfCheck) are read while the other plugin serves them;
 *    a home page that cannot be read stops the switch before anything changes.
 * 2. `off` — the other plugin is deactivated (one that will not go off is switched straight back
 *    on: half a switch is the worst of both), then the page caches are emptied (Purge), so what
 *    is read next is this plugin and not a cached copy of the other.
 * 3. `pages` — the compared spread, as above.
 * 4. `files` — the key pages and files again; any LOSS (SelfCheck::losses) fails.
 *
 * A failure after step 2 is a rollback: the other plugin on at once, the caches emptied again,
 * and the whole move undone (values, settings, redirects) by the undo job.
 */
final class Switcher {

	/** The job that re-reads the baseline after the switch-off. */
	public const JOB = 'import-verify';

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Checking the pages after the switch', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * Merge fields into one template record, remembering what it replaced so an undo restores it.
	 *
	 * @param string               $key    Template key (`home`, `archive:<type>`, `404`).
	 * @param array<string, mixed> $record Fields.
	 * @return int Fields written.
	 */
	public static function write_template( string $key, array $record ): int {
		$all  = (array) Settings::get( 'templates', array() );
		$mine = is_array( $all[ $key ] ?? null ) ? $all[ $key ] : array();
		$n    = 0;
		foreach ( $record as $name => $value ) {
			if ( ( $mine[ $name ] ?? null ) !== $value ) {
				$mine[ $name ] = $value;
				++$n;
			}
		}
		if ( 0 === $n ) {
			return 0;
		}
		$state = Import::state();
		if ( ! array_key_exists( 'templates', (array) $state['settings']['before'] ) ) {
			$state['settings']['before']['templates'] = $all;
		}
		$all[ $key ] = $mine;
		$saved       = Settings::save( array( 'templates' => $all ), 'import' );
		if ( is_wp_error( $saved ) ) {
			return 0;
		}
		$state['settings']['written']['templates'] = $saved['templates'];
		// The caller holds the state too; only the settings part is ours to change here.
		$fresh             = Import::state();
		$fresh['settings'] = $state['settings'];
		Import::put( $fresh );

		return $n;
	}

	/**
	 * Why the other plugin cannot be switched off now, or ''.
	 *
	 * @param array<string, mixed> $state State.
	 * @return string
	 */
	public static function refusal( array $state ): string {
		$source = Import::source( (string) $state['source'] );
		if ( null === $source ) {
			return __( 'Start with an import.', 'zinn-seo' );
		}
		$active = $source->active();
		if ( array() === $active ) {
			/* translators: %s: the name of another SEO plugin. */
			return sprintf( __( '%s is already switched off.', 'zinn-seo' ), $source->name() );
		}
		if ( 'pending' === Jobs::get( self::JOB )['status'] ) {
			return __( 'The switch is already under way.', 'zinn-seo' );
		}
		if ( ! Check::passed( $state['check'] ) ) {
			return __( 'The pages have not been compared, or differences are still open. Compare them, then fix or accept what differs.', 'zinn-seo' );
		}
		// The copy itself must have been read back and found stored (queue Q263, point 3).
		$proof = Verify::refusal( $state );
		if ( '' !== $proof ) {
			return $proof;
		}
		if ( is_array( $state['preflight'] ?? null ) && $source->id() === ( $state['preflight']['source'] ?? '' ) ) {
			$stop = Preflight::refusal( (array) $state['preflight'] );
			if ( '' !== $stop ) {
				return $stop;
			}
		}
		if ( is_multisite() ) {
			$network = array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			if ( array() !== array_intersect( $active, $network ) ) {
				/* translators: %s: the name of another SEO plugin. */
				return sprintf( __( '%s is active for the whole network. A network administrator switches it off for every site at once on the network\'s Plugins screen.', 'zinn-seo' ), $source->name() );
			}
		}
		if ( ! current_user_can( 'deactivate_plugins' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return __( 'You are not allowed to deactivate plugins on this site.', 'zinn-seo' );
		}

		return '';
	}

	/**
	 * Start the switch: the key pages are read while the other plugin still serves them, then it
	 * is switched off, the page caches are emptied, and every page is read again.
	 *
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public static function off() {
		$state  = Import::state();
		$reason = self::refusal( $state );
		if ( '' !== $reason ) {
			return new \WP_Error( 'zinn_seo_switch_refused', $reason, array( 'status' => 409 ) );
		}
		$state['switching']         = array(
			'at' => time(),
			'by' => get_current_user_id(),
		);
		$state['switch_error']      = '';
		$state['switch_error_code'] = '';
		unset( $state['rollback'], $state['purge'] );
		Import::put( $state );
		delete_option( SelfCheck::BEFORE );
		Jobs::start(
			self::JOB,
			array(
				'phase'  => 'sitemaps',
				'cursor' => 0,
			)
		);

		return Import::state();
	}

	/**
	 * Why a switch stopped before anything changed, in the reader's language.
	 *
	 * @param string $code home|deactivate|sitemaps.
	 * @return string
	 */
	public static function error_text( string $code ): string {
		switch ( $code ) {
			case 'home':
				return __( 'The home page could not be read before the switch, so nothing was switched off. Try again in a moment.', 'zinn-seo' );
			case 'deactivate':
				return __( 'The plugin could not be deactivated, so it stays active.', 'zinn-seo' );
			case 'sitemaps':
				return __( 'The sitemaps could not be built, so nothing was switched off. Build them under Sitemaps, then try again.', 'zinn-seo' );
		}

		return '';
	}

	/**
	 * Are this plugin's sitemaps built (or switched off)? Moves the build on while it is not.
	 *
	 * @param float $deadline Microtime.
	 * @return bool|\WP_Error True when built, false while building, an error when it cannot be.
	 */
	public static function sitemaps_ready( float $deadline ) {
		$builder = '\ZinnDigital\ZinnSEO\Sitemaps\Builder';
		$store   = '\ZinnDigital\ZinnSEO\Sitemaps\Store';
		if ( ! \ZinnDigital\ZinnSEO\Modules::enabled( 'sitemaps' ) || ! class_exists( $builder ) || ! class_exists( $store ) ) {
			return true;
		}
		if ( ! empty( $store::manifest()['complete'] ) ) {
			return true;
		}
		// The module answers for nothing while the other plugin is active, so its build job is
		// not registered in this request: register it to build ahead of the switch.
		$builder::register();
		$job = Jobs::get( $builder::JOB );
		if ( 'failed' === (string) $job['status'] ) {
			return new \WP_Error( 'zinn_seo_sitemaps', __( 'The sitemaps could not be built, so nothing was switched off. Build them under Sitemaps, then try again.', 'zinn-seo' ) );
		}
		if ( ! in_array( (string) $job['status'], array( 'pending', 'paused' ), true ) ) {
			$builder::start( true );
		}
		// Moved on here too: under WP-CLI no loopback request carries the build.
		$before = (int) ( Jobs::get( $builder::JOB )['updated'] ?? 0 );
		$after  = Jobs::step( $builder::JOB, $deadline );
		if ( ! empty( $store::manifest()['complete'] ) ) {
			return true;
		}
		// A build that has not moved for ten minutes is stuck: say so rather than wait for ever. One
		// waiting its turn behind another job (Q332) is queued, not stuck.
		if ( '' === (string) ( $after['waiting'] ?? '' ) && (int) ( $after['updated'] ?? 0 ) <= $before && $before > 0 && $before < time() - 10 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'zinn_seo_sitemaps', __( 'The sitemaps could not be built, so nothing was switched off. Build them under Sitemaps, then try again.', 'zinn-seo' ) );
		}

		return false;
	}

	/**
	 * Switch the other plugin off (the job's second phase).
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public static function deactivate( array $state ) {
		$source = Import::source( (string) $state['source'] );
		if ( null === $source ) {
			return new \WP_Error( 'zinn_seo_switch_failed', __( 'Start with an import.', 'zinn-seo' ) );
		}
		$files = $source->active();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		// This plugin's own listener would begin a second move when it sees the plugin go.
		remove_action( 'deactivated_plugin', array( Import::class, 'other_plugin_switched_off' ), 10 );
		/**
		 * Filters the plugin files the switch deactivates (the harness forces a failure with it).
		 *
		 * @param array<int, string> $files Plugin files.
		 */
		deactivate_plugins( (array) apply_filters( 'zinn_seo_import_deactivate', $files ) );
		add_action( 'deactivated_plugin', array( Import::class, 'other_plugin_switched_off' ), 10, 1 );
		if ( array() !== $source->active() ) {
			// Whatever did go off goes back on: half a switch is the worst of both.
			$state['switched'] = array( 'plugins' => $files );
			self::reactivate( $state );
			return new \WP_Error( 'zinn_seo_switch_failed', __( 'The plugin could not be deactivated, so it stays active.', 'zinn-seo' ) );
		}
		$state['switched'] = array(
			'at'        => time(),
			'plugins'   => $files,
			'by'        => (int) ( $state['switching']['by'] ?? get_current_user_id() ),
			'verifying' => true,
			'verified'  => 0,
			'failed'    => array(),
		);

		return $state;
	}

	/**
	 * The addresses whose cache is emptied: the key pages and the compared spread.
	 *
	 * @return array<int, string>
	 */
	public static function purge_urls(): array {
		$urls = array();
		$kept = get_option( SelfCheck::BEFORE, array() );
		foreach ( is_array( $kept ) ? $kept : array() as $entry ) {
			$urls[] = (string) ( $entry['url'] ?? '' );
		}
		$base = get_option( Check::BASELINE, array() );
		foreach ( is_array( $base ) ? $base : array() as $entry ) {
			$urls[] = (string) ( $entry['url'] ?? '' );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Empty the caches.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	public static function purge( array $state ): array {
		return Purge::run( self::purge_urls(), (array) ( $state['preflight']['headers'] ?? array() ) );
	}

	/**
	 * Put everything back: the other plugin on at once, the caches emptied, then the whole move
	 * undone (values, settings, redirects) by the undo job.
	 *
	 * @param array<string, mixed>             $state   State.
	 * @param array<int, array<string, mixed>> $failed  What was found.
	 * @param int                              $checked Pages read.
	 * @return array<string, mixed> The state.
	 */
	public static function rollback( array $state, array $failed, int $checked ): array {
		$back               = self::reactivate( $state );
		$state['rollback']  = array(
			'at'      => time(),
			'plugins' => $back,
			'failed'  => $failed,
			'checked' => $checked,
			'undo'    => true,
		);
		$state['switched']  = null;
		$state['switching'] = null;
		$state['purge']     = self::purge( $state );
		// What the comparison found no longer describes the site: it has to be run again.
		if ( is_array( $state['check'] ) ) {
			$state['check']['accepted'] = 0;
			$state['check']['finished'] = 0;
		}
		Import::put( $state );
		// Then the move is undone as a whole (queue Q263: "reactivate the old plugin, restore
		// settings"). The old plugin is serving already; the undo takes back what was written.
		Import::undo( true );

		return Import::state();
	}

	/**
	 * One step of the switch.
	 *
	 * @param array<string, mixed> $run      State.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $run, float $deadline ): array {
		$state = Import::state();
		$phase = (string) ( $run['phase'] ?? 'pages' );
		$base  = get_option( Check::BASELINE, array() );
		$base  = is_array( $base ) ? array_values( $base ) : array();
		$total = count( $base );
		$done  = false;
		$ok    = false;
		$at    = (int) ( $run['cursor'] ?? 0 );
		// The key files are read in one go: in this step when it began there, or when time is left.
		$files_now = 'files' === $phase;

		if ( 'sitemaps' === $phase ) {
			// This plugin's sitemaps are built BEFORE the switch, while the other plugin still
			// answers for them: switched off first, the sitemap index would list nothing until a
			// build ran (found by this check's own first run, 2026-10-15: 4 sitemaps -> 0).
			$ready = self::sitemaps_ready( $deadline );
			if ( is_wp_error( $ready ) ) {
				$state['switching']         = null;
				$state['switch_error']      = $ready->get_error_message();
				$state['switch_error_code'] = 'sitemaps';
				Import::put( $state );
				return array(
					'state'    => $run,
					'done'     => true,
					'progress' => array(
						'done'  => 0,
						'total' => $total,
					),
				);
			}
			if ( true === $ready ) {
				$run['phase'] = 'before';
				$phase        = 'before';
			} else {
				Import::put( $state );
				return array(
					'state'    => $run,
					'done'     => false,
					'progress' => array(
						'done'  => 0,
						'total' => $total,
					),
				);
			}
		}
		if ( 'before' === $phase && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			// The key pages as the other plugin serves them (it is still active).
			$before = SelfCheck::capture( SelfCheck::targets() );
			if ( 200 === (int) ( $before['home']['got']['code'] ?? 0 ) ) {
				$before += SelfCheck::capture( SelfCheck::language_targets( (array) $before['home']['got'] ) );
			}
			if ( 200 !== (int) ( $before['home']['got']['code'] ?? 0 ) ) {
				// Nothing can be compared after: stop before anything changes.
				$state['switching']         = null;
				$state['switch_error']      = self::error_text( 'home' );
				$state['switch_error_code'] = 'home';
				Import::put( $state );
				$done = true;
			} else {
				update_option( SelfCheck::BEFORE, $before, false );
				$run['phase'] = 'off';
				$phase        = 'off';
			}
		}
		if ( ! $done && 'off' === $phase ) {
			$next = self::deactivate( $state );
			if ( is_wp_error( $next ) ) {
				$state                      = Import::state();
				$state['switching']         = null;
				$state['switched']          = null;
				$state['switch_error']      = $next->get_error_message();
				$state['switch_error_code'] = 'deactivate';
				Import::put( $state );
				$done = true;
			} else {
				$state          = $next;
				$state['purge'] = self::purge( $state );
				Import::put( $state );
				$run['phase'] = 'pages';
				$phase        = 'pages';
			}
		}
		$bad = (array) ( $state['switched']['failed'] ?? array() );
		if ( ! $done && 'pages' === $phase ) {
			while ( $at < $total && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) && array() === $bad ) {
				$entry = (array) $base[ $at ];
				$got   = Check::fetch( (string) $entry['url'] );
				if ( 0 === $got['code'] || $got['code'] >= 500 ) {
					// Once more before calling it a failure: the first request after a plugin change
					// can meet a cold cache.
					$got = Check::fetch( (string) $entry['url'] );
				}
				if ( 0 === $got['code'] || $got['code'] >= 500 ) {
					$bad[] = array(
						'url'    => (string) $entry['url'],
						'fields' => array( 'page' => array( '200', '' !== $got['error'] ? $got['error'] : 'HTTP ' . $got['code'] ) ),
					);
					break;
				}
				if ( isset( $entry['expect'] ) ) {
					// A redirect: the same code to the same place, now answered by this plugin alone.
					$want = (array) $entry['expect'];
					if ( (int) $want['code'] !== $got['code'] || Signature::address( (string) $want['location'] ) !== Signature::address( (string) $got['location'] ) ) {
						$bad[] = array(
							'url'    => (string) $entry['url'],
							'fields' => array( 'redirect' => array( $want['code'] . ' ' . $want['location'], $got['code'] . ' ' . $got['location'] ) ),
						);
						break;
					}
					++$at;
					continue;
				}
				$now  = Signature::from_html( $got['html'] );
				$diff = array_diff_key( Signature::diff( (array) $entry['theirs'], $now, (string) ( $entry['page'] ?? '' ), (string) $entry['url'] ), array_flip( (array) ( $entry['open'] ?? array() ) ) );
				if ( array() !== (array) ( $now['doubled'] ?? array() ) ) {
					$diff['doubled'] = array( array(), (array) $now['doubled'] );
				}
				if ( array() !== $diff ) {
					$bad[] = array(
						'url'    => (string) $entry['url'],
						'fields' => $diff,
					);
					break;
				}
				++$at;
			}
			if ( $at >= $total && array() === $bad ) {
				$run['phase'] = 'files';
				$phase        = 'files';
			}
		}
		if ( ! $done && 'files' === $phase && ( $files_now || ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) ) {
			// The key pages and files again, served by this plugin now.
			$before  = get_option( SelfCheck::BEFORE, array() );
			$before  = is_array( $before ) ? $before : array();
			$targets = array();
			foreach ( $before as $key => $entry ) {
				$targets[] = array(
					'key'  => (string) $key,
					'url'  => (string) $entry['url'],
					'kind' => (string) $entry['kind'],
				);
			}
			$after = SelfCheck::capture( $targets );
			$bad   = array_merge( $bad, SelfCheck::losses( $before, $after ) );
			if ( array() === $bad ) {
				$ok   = true;
				$done = true;
			}
		}
		if ( ! $done && array() !== $bad ) {
			$done = true;
		}

		if ( is_array( $state['switched'] ) ) {
			$state['switched']['verified'] = $at;
			$state['switched']['failed']   = $bad;
			if ( $done ) {
				$state['switched']['verifying'] = false;
			}
		}
		$run['cursor'] = $at;
		if ( $done && is_array( $state['switched'] ) && array() !== $bad ) {
			// Not the same page: the other plugin goes back on, and the move is undone.
			$state = self::rollback( $state, $bad, $at );
		} elseif ( $done && $ok ) {
			$state['switching'] = null;
			unset( $state['rollback'] );
			// Once more now every page was read: what a visitor got in between is gone too.
			$state['purge'] = self::purge( $state );
			Import::put( $state );
		} elseif ( ! $done ) {
			Import::put( $state );
		}
		if ( $done && ( is_array( $state['switched'] ?? null ) || isset( $state['rollback'] ) ) ) {
			/**
			 * Fires when the switch-off was proven on the rendered pages, or rolled back.
			 *
			 * @param bool  $ok    The pages said the same.
			 * @param array $state The move's state.
			 */
			do_action( 'zinn_seo_import_switched', $ok, $state );
		}

		return array(
			'state'    => $run,
			'done'     => $done,
			'progress' => array(
				'done'  => $at,
				'total' => $total,
			),
		);
	}

	/**
	 * Switch the other plugin back on (rollback and undo). Silent: its activation routine set
	 * the site up once already, and must not redirect this request to its welcome screen.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<int, string> The files activated.
	 */
	public static function reactivate( array $state ): array {
		$files = (array) ( $state['switched']['plugins'] ?? array() );
		if ( array() === $files ) {
			return array();
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$back = array();
		foreach ( $files as $file ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) && ! is_plugin_active( (string) $file ) ) {
				$result = activate_plugin( (string) $file, '', false, true );
				if ( ! is_wp_error( $result ) ) {
					$back[] = (string) $file;
				}
			}
		}

		return $back;
	}
}
