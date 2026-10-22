<?php
/**
 * After the copy: prove it is really stored, before anything else changes (queue Q263, point 2).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Bulk\History;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ A copy that REPORTS success is not a copy that happened (zinnhub, 2026-10-14: Rank Math's
 * 33,188-character llms.txt text "saved" and was not there; a switch script read the wrong exit
 * code and went on). So the copy is read back from the database, by a mechanism that does not
 * share the copy's bookkeeping:
 *
 * 1. **Settings** — the import's own plan is worked out AGAIN against what is stored now. Every
 *    setting the other plugin holds that this site has not set itself must already be here, so a
 *    fresh plan must have nothing left to write. One setting it would still write is one that did
 *    not stick.
 * 2. **Values** — every row the copy recorded in its history batch is read back from the object it
 *    names (keyset, no cap — CLAUDE.md §2.10). A value that is EMPTY now was lost; a value that is
 *    different but not empty was changed by a person since and is counted, not held against it.
 * 3. **Redirects** — the rules the copy added are counted in the redirects table.
 *
 * Until this passes, the comparison and the switch-off stay closed (Check::refusal,
 * Switcher::refusal). It runs by itself when a copy finishes.
 */
final class Verify {

	/** The job. */
	public const JOB = 'import-proof';

	/** Lost values listed (all are counted). */
	public const LISTED = 50;

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Checking that everything copied is stored', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * Start reading the copy back.
	 *
	 * @return true|\WP_Error
	 */
	public static function start() {
		$state = Import::state();
		if ( ! is_array( $state['applied'] ) ) {
			return new \WP_Error( 'zinn_seo_nothing_copied', __( 'Copy the data first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$state['proof'] = array(
			'source'   => (string) $state['applied']['source'],
			'batch'    => (string) $state['applied']['batch'],
			'started'  => time(),
			'finished' => 0,
			'ok'       => false,
		);
		Import::put( $state );

		return Jobs::start( self::JOB, self::fresh() );
	}

	/**
	 * A read-back from its beginning. A job started with no state of its own (`wp zinn-seo jobs
	 * run import-proof` once the last one is done restarts it empty) begins here too (Q297).
	 *
	 * @return array<string, mixed>
	 */
	public static function fresh(): array {
		return array(
			'phase'   => 'settings',
			'cursor'  => 0,
			'checked' => 0,
			'stored'  => 0,
			'changed' => 0,
			'lost'    => 0,
			'listed'  => array(),
		);
	}

	/**
	 * The settings the other plugin holds that are still not here (a fresh plan's keys).
	 *
	 * @param Source               $source Source.
	 * @param array<string, mixed> $state  The move.
	 * @return array<int, string>
	 */
	public static function missing_settings( Source $source, array $state ): array {
		$plan = Import::settings_plan( $source, false );
		$keys = array_keys( $plan['changes'] );
		// A setting the copy wrote and a person changed since is theirs (restore_settings treats
		// it the same way); a setting the copy never wrote but the plan wants is the defect.
		$written = array_keys( (array) ( $state['settings']['written'] ?? array() ) );
		$now     = \ZinnDigital\ZinnSEO\Settings::all();
		$out     = array();
		foreach ( $keys as $key ) {
			$before = $state['settings']['before'][ $key ] ?? null;
			if ( in_array( $key, $written, true ) && ( $now[ $key ] ?? null ) !== $before ) {
				continue;
			}
			$out[] = (string) $key;
		}
		sort( $out );

		return $out;
	}

	/**
	 * One step.
	 *
	 * @param array<string, mixed> $run      Job state.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When there is no copy to read back.
	 */
	public static function step( array $run, float $deadline ): array {
		$state  = Import::state();
		$source = Import::source( (string) ( $state['proof']['source'] ?? '' ) );
		if ( null === $source || ! is_array( $state['proof'] ?? null ) || ! Import::history() ) {
			throw new \RuntimeException( esc_html__( 'There is no copy to check.', 'zinn-seo' ) );
		}
		$run += self::fresh();
		$done = false;
		while ( ! $done && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			if ( 'settings' === $run['phase'] ) {
				$run['settings'] = self::missing_settings( $source, $state );
				$run['phase']    = 'values';
				continue;
			}
			if ( 'values' === $run['phase'] ) {
				$rows = self::rows( (string) $state['proof']['batch'], (int) $run['cursor'] );
				if ( array() === $rows ) {
					$run['phase'] = 'redirects';
					continue;
				}
				$by_kind = array();
				foreach ( $rows as $row ) {
					$by_kind[ self::kind( (string) $row['object_type'] ) ][] = (int) $row['object_id'];
				}
				foreach ( $by_kind as $type => $ids ) {
					$ids = array_values( array_unique( $ids ) );
					foreach ( $ids as $object ) {
						wp_cache_delete( $object, $type . '_meta' );
					}
					update_meta_cache( $type, $ids );
				}
				foreach ( $rows as $row ) {
					$run['cursor'] = (int) $row['id'];
					++$run['checked'];
					$kind  = self::kind( (string) $row['object_type'] );
					$def   = Meta::fields()[ (string) $row['field'] ] ?? null;
					$now   = Meta::get( $kind, (int) $row['object_id'], (string) $row['field'] );
					$wrote = json_decode( (string) $row['new_value'], true );
					if ( History::same( $now, $wrote ) ) {
						++$run['stored'];
						continue;
					}
					$empty = null === $def ? ( '' === $now || null === $now || array() === $now ) : Meta::empty_value( $def ) === $now;
					if ( ! $empty ) {
						++$run['changed'];
						continue;
					}
					++$run['lost'];
					if ( count( $run['listed'] ) < self::LISTED ) {
						$run['listed'][] = array(
							'kind'  => $kind,
							'id'    => (int) $row['object_id'],
							'field' => (string) $row['field'],
						);
					}
				}
				History::release( $by_kind );
				continue;
			}
			// Redirects the copy added, still in the table.
			$expected         = (int) ( $state['applied']['counts']['redirects']['new'] ?? 0 );
			$run['redirects'] = array(
				'expected' => $expected,
				'present'  => $expected > 0 ? self::redirects_present( $source, $state ) : 0,
			);
			$done             = true;
		}
		if ( $done ) {
			self::finish( $run );
		}

		return array(
			'state'    => $run,
			'done'     => $done,
			'progress' => array(
				'done'  => (int) $run['checked'],
				'total' => null,
			),
		);
	}

	/**
	 * Record the result.
	 *
	 * @param array<string, mixed> $run Job state at its end.
	 * @return void
	 */
	public static function finish( array $run ): void {
		$run           += self::fresh();
		$state          = Import::state();
		$settings       = (array) ( $run['settings'] ?? array() );
		$redirects      = (array) ( $run['redirects'] ?? array() );
		$short          = max( 0, (int) ( $redirects['expected'] ?? 0 ) - (int) ( $redirects['present'] ?? 0 ) );
		$ok             = array() === $settings && 0 === (int) $run['lost'] && 0 === $short;
		$state['proof'] = array_merge(
			(array) $state['proof'],
			array(
				'finished'  => time(),
				'ok'        => $ok,
				'settings'  => array(
					'written' => count( (array) ( $state['settings']['written'] ?? array() ) ),
					'missing' => $settings,
				),
				'values'    => array(
					'checked' => (int) $run['checked'],
					'stored'  => (int) $run['stored'],
					'changed' => (int) $run['changed'],
					'lost'    => (int) $run['lost'],
					'listed'  => (array) $run['listed'],
				),
				'redirects' => array(
					'expected' => (int) ( $redirects['expected'] ?? 0 ),
					'present'  => (int) ( $redirects['present'] ?? 0 ),
				),
			)
		);
		Import::put( $state );

		/**
		 * Fires when a copy has been read back.
		 *
		 * @param bool  $ok    Everything copied is stored.
		 * @param array $proof What was found.
		 */
		do_action( 'zinn_seo_import_verified', $ok, $state['proof'] );
	}

	/**
	 * Has the copy now in place been read back and found stored?
	 *
	 * @param array<string, mixed> $state The move.
	 * @return bool
	 */
	public static function passed( array $state ): bool {
		$proof = $state['proof'] ?? null;
		if ( ! is_array( $proof ) || ! is_array( $state['applied'] ) ) {
			return false;
		}

		return ! empty( $proof['ok'] ) && (int) ( $proof['finished'] ?? 0 ) > 0 && (string) ( $proof['batch'] ?? '' ) === (string) ( $state['applied']['batch'] ?? '' );
	}

	/**
	 * Why the copy does not count as proven, or ''.
	 *
	 * @param array<string, mixed> $state The move.
	 * @return string
	 */
	public static function refusal( array $state ): string {
		if ( self::passed( $state ) ) {
			return '';
		}
		$proof = $state['proof'] ?? null;
		if ( is_array( $proof ) && (int) ( $proof['finished'] ?? 0 ) > 0 && ( $proof['batch'] ?? '' ) === ( $state['applied']['batch'] ?? '' ) ) {
			return __( 'Not everything copied is stored on this site (see the list). Undo the import and copy again; the other plugin stays active meanwhile.', 'zinn-seo' );
		}

		return __( 'The copy has not been checked yet. Check it first; the other plugin stays active meanwhile.', 'zinn-seo' );
	}

	/**
	 * A page of a batch's history rows after a cursor.
	 *
	 * @param string $batch Batch id.
	 * @param int    $after Row id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( string $batch, int $after ): array {
		if ( '' === $batch ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, keyset by its batch index.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, object_type, object_id, field, new_value FROM %i WHERE batch = %s AND id > %d ORDER BY id ASC LIMIT 500', History::table(), $batch, $after ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The meta type of a history row's object type.
	 *
	 * @param string $type Stored type.
	 * @return string post|term|user
	 */
	public static function kind( string $type ): string {
		return in_array( $type, array( 'term', 'user' ), true ) ? $type : 'post';
	}

	/**
	 * How many of the redirects the copy added are still in the table.
	 *
	 * @param Source               $source Source.
	 * @param array<string, mixed> $state  The move.
	 * @return int
	 */
	public static function redirects_present( Source $source, array $state ): int {
		$store = '\ZinnDigital\ZinnSEO\Redirects\Store';
		if ( ! class_exists( $store ) || ! $store::ready() ) {
			return 0;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, once per check.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND origin = %s', $store::table(), (int) ( $state['redirects_before'] ?? 0 ), Import::origin( $source ) ) );
	}
}
