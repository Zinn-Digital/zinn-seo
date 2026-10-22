<?php
/**
 * Redirect chains and loops: found and fixed (zs-098).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Jobs;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; every value is a placeholder.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A **chain** is a rule whose target is itself redirected (A → B → C): every hop costs the visitor a
 * round trip and search engines stop following after a few. A **loop** comes back to where it
 * started (A → B → A) and no browser can open it.
 *
 * - `collapse()` runs on every rule written by this plugin (and, with `redirects_collapse` on, by a
 *   person): rules that pointed at the old address are re-pointed at the new one, so a chain is
 *   never created in the first place.
 * - The `redirects-check` job walks every rule (keyset by id, resumes after a kill) and follows its
 *   target through the SAME matcher visitors get (plain rules, then patterns), up to 10 hops,
 *   marking `problem` = `chain` or `loop`; with "check targets" on it also asks each address on
 *   this site whether it answers (`missing` when it is a 404 or 410). Nothing leaves the site.
 * - `fix()` points every chained rule at its final address and switches looped rules off (a loop
 *   has no right answer for a machine to pick: the screen lists them for a person).
 */
final class Checker {

	/** Job id. */
	public const JOB = 'redirects-check';

	/** Hops followed before a chain is called a loop. */
	public const MAX_HOPS = 10;

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Checking redirects for chains and loops', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * Re-point rules that targeted `$from` at `$to`.
	 *
	 * @param string $from Old address.
	 * @param string $to   New address.
	 * @return int Rules changed.
	 */
	public static function collapse( string $from, string $to ): int {
		global $wpdb;
		$n      = 0;
		$to_key = Path::key( $to, Store::base(), Store::sensitive() );
		foreach ( Store::pointing_to( $from ) as $row ) {
			if ( ! Store::is_local( (string) $row['target'] ) ) {
				continue;
			}
			if ( Path::key( (string) $row['source'], Store::base(), Store::sensitive() ) === $to_key ) {
				continue; // It would point at itself: the "live again" rule handles that address.
			}
			$n += (int) $wpdb->update(
				Store::table(),
				array(
					'target'      => $to,
					'target_hash' => Store::target_hash( $to ),
					'problem'     => '',
					'updated'     => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $row['id'] )
			);
			Index::changed( null, Store::get( (int) $row['id'] ) );
		}

		return $n;
	}

	/**
	 * Follow a rule's target to the end.
	 *
	 * @param int    $id     The rule being checked.
	 * @param string $target Its target.
	 * @return array{status: string, final: string, hops: int, path: array<int, int>}
	 *               status: ok, chain, loop or external.
	 */
	public static function follow( int $id, string $target ): array {
		$seen     = array( $id => true );
		$path     = array( $id );
		$location = $target;
		$hops     = 0;
		while ( Store::is_local( $location ) ) {
			$p     = (string) wp_parse_url( $location, PHP_URL_PATH );
			$q     = (string) wp_parse_url( $location, PHP_URL_QUERY );
			$match = Serve::match( ( '' === $p ? '/' : $p ) . ( '' === $q ? '' : '?' . $q ), true );
			if ( null === $match ) {
				break;
			}
			if ( isset( $seen[ (int) $match['id'] ] ) ) {
				return array(
					'status' => 'loop',
					'final'  => $location,
					'hops'   => $hops + 1,
					'path'   => $path,
				);
			}
			$seen[ (int) $match['id'] ] = true;
			$path[]                     = (int) $match['id'];
			++$hops;
			if ( '' === (string) $match['location'] ) {
				return array(
					'status' => 'chain',
					'final'  => '',
					'hops'   => $hops,
					'path'   => $path,
				);
			}
			$location = (string) $match['location'];
			if ( $hops >= self::MAX_HOPS ) {
				return array(
					'status' => 'loop',
					'final'  => $location,
					'hops'   => $hops,
					'path'   => $path,
				);
			}
		}

		return array(
			'status' => $hops > 0 ? 'chain' : ( Store::is_local( $location ) ? 'ok' : 'external' ),
			'final'  => $location,
			'hops'   => $hops,
			'path'   => $path,
		);
	}

	/**
	 * Start a check.
	 *
	 * @param bool $targets Also ask every local target whether it answers.
	 * @return true|\WP_Error
	 */
	public static function start( bool $targets = false ) {
		return Jobs::start(
			self::JOB,
			array(
				'after'   => 0,
				'targets' => $targets,
				'found'   => array(
					'chain'   => 0,
					'loop'    => 0,
					'missing' => 0,
				),
			)
		);
	}

	/**
	 * One step: rules after the cursor, until the deadline.
	 *
	 * @param array<string, mixed> $state    after, targets, found.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		global $wpdb;
		if ( ! Store::ready() ) {
			return array(
				'state' => $state,
				'done'  => true,
			);
		}
		$after = (int) ( $state['after'] ?? 0 );
		$found = (array) ( $state['found'] ?? array() );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Store::table() ) );
		$done  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id <= %d', Store::table(), $after ) );
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, target, code, is_regex, enabled, problem FROM %i WHERE id > %d ORDER BY id ASC LIMIT 200', Store::table(), $after ), ARRAY_A );
			if ( array() === $rows ) {
				update_option(
					'zseo_redirects_checked',
					array(
						'at'    => time(),
						'found' => $found,
					),
					false
				);
				return array(
					'state'    => array(
						'after'   => $after,
						'targets' => ! empty( $state['targets'] ),
						'found'   => $found,
					),
					'done'     => true,
					'progress' => array(
						'done'  => $total,
						'total' => $total,
					),
				);
			}
			foreach ( $rows as $row ) {
				$after   = (int) $row['id'];
				$problem = self::problem_of( $row, ! empty( $state['targets'] ) );
				if ( '' !== $problem ) {
					$found[ $problem ] = (int) ( $found[ $problem ] ?? 0 ) + 1;
				}
				if ( $problem !== (string) $row['problem'] && 'shadows' !== (string) $row['problem'] ) {
					$wpdb->update( Store::table(), array( 'problem' => $problem ), array( 'id' => (int) $row['id'] ) );
				}
				++$done;
				if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
					break;
				}
			}
		}

		return array(
			'state'    => array(
				'after'   => $after,
				'targets' => ! empty( $state['targets'] ),
				'found'   => $found,
			),
			'done'     => false,
			'progress' => array(
				'done'  => $done,
				'total' => $total,
			),
		);
	}

	/**
	 * The problem of one rule ('' when fine).
	 *
	 * @param array<string, mixed> $row     Raw row.
	 * @param bool                 $targets Ask local targets whether they answer.
	 * @return string
	 */
	public static function problem_of( array $row, bool $targets ): string {
		if ( ! (int) $row['enabled'] || ! in_array( (int) $row['code'], Store::MOVES, true ) || (int) $row['is_regex'] ) {
			return (int) $row['enabled'] ? '' : (string) ( 'loop' === (string) $row['problem'] ? 'loop' : '' );
		}
		$f = self::follow( (int) $row['id'], (string) $row['target'] );
		if ( 'loop' === $f['status'] || 'chain' === $f['status'] ) {
			return $f['status'];
		}
		if ( $targets && 'ok' === $f['status'] ) {
			$url  = 1 === preg_match( '#^https?://#i', (string) $f['final'] ) ? (string) $f['final'] : home_url( (string) $f['final'] );
			$head = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 0,
				)
			);
			$code = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );
			if ( in_array( $code, array( 404, 410 ), true ) ) {
				return 'missing';
			}
		}

		return '';
	}

	/**
	 * Fix what can be fixed: chains point at their final address, loops are switched off.
	 *
	 * @return array{chains: int, loops: int}
	 */
	public static function fix(): array {
		global $wpdb;
		$out = array(
			'chains' => 0,
			'loops'  => 0,
		);
		if ( ! Store::ready() ) {
			return $out;
		}
		$after = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE problem IN ('chain','loop') AND id > %d ORDER BY id ASC LIMIT 500", Store::table(), $after ), ARRAY_A );
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$f     = self::follow( (int) $row['id'], (string) $row['target'] );
				if ( 'loop' === $f['status'] ) {
					$wpdb->update(
						Store::table(),
						array(
							'enabled' => 0,
							'problem' => 'loop',
							'updated' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'id' => (int) $row['id'] )
					);
					Index::changed( $row, Store::get( (int) $row['id'] ) );
					++$out['loops'];
					continue;
				}
				if ( 'chain' === $f['status'] ) {
					$final  = (string) $f['final'];
					$target = '' === $final ? '' : ( Store::is_local( $final ) ? Auto::path( 1 === preg_match( '#^https?://#i', $final ) ? $final : home_url( $final ) ) : $final );
					$wpdb->update(
						Store::table(),
						array(
							'target'      => $target,
							'target_hash' => Store::target_hash( $target ),
							'code'        => '' === $final ? 410 : (int) $row['code'],
							'problem'     => '',
							'origin'      => (string) $row['origin'],
							'updated'     => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'id' => (int) $row['id'] )
					);
					Index::changed( $row, Store::get( (int) $row['id'] ) );
					++$out['chains'];
					continue;
				}
				$wpdb->update( Store::table(), array( 'problem' => '' ), array( 'id' => (int) $row['id'] ) );
			}
		} while ( array() !== $rows );

		return $out;
	}
}
