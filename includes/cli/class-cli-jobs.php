<?php
/**
 * WP-CLI: background jobs.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background jobs.
 */
final class Cli_Jobs {

	/**
	 * List the jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function list( array $args, array $assoc ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames,WordPress.NamingConventions.ValidFunctionName -- WP-CLI's own subcommand name.
		$rows = array();
		foreach ( Jobs::describe() as $job ) {
			$rows[] = array(
				'id'     => $job['id'],
				'label'  => $job['label'],
				'status' => $job['status'],
				'done'   => (string) ( $job['progress']['done'] ?? 0 ),
				'total'  => (string) ( $job['progress']['total'] ?? '' ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'label', 'status', 'done', 'total' ) );
	}

	/**
	 * Run a job to the end in this process (starts it when it is not running).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * [--restart]
	 * : Start from the beginning even when it is part-way through.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		$job = Jobs::get( $args[0] );
		if ( ! empty( $assoc['restart'] ) || ! in_array( $job['status'], array( 'pending', 'paused', 'failed' ), true ) ) {
			Cli::bail_on_error( Jobs::start( $args[0] ) );
		} elseif ( 'pending' !== $job['status'] ) {
			Cli::bail_on_error( Jobs::control( $args[0], 'resume' ) );
		}
		$tick = static function ( array $job ): void {
			\WP_CLI::log( sprintf( 'step %d: %d done', (int) $job['steps'], (int) ( $job['progress']['done'] ?? 0 ) ) );
		};
		// Q339: Jobs::run() runs the jobs queued ahead of this one first.
		$job = Jobs::run( $args[0], $tick );
		if ( 'pending' === $job['status'] && '' !== (string) ( $job['waiting'] ?? '' ) ) {
			// Q332: one job at a time per site. Queued, not failed: it starts when its turn comes.
			\WP_CLI::log( sprintf( 'The job is waiting for "%s" to finish (one background job at a time per site). It starts by itself when its turn comes.', (string) $job['waiting'] ) );
			return;
		}
		if ( 'pending' === $job['status'] && Jobs::running( $job ) ) {
			// Another process (the site's own background chain) is in a step: not a failure.
			\WP_CLI::log( sprintf( 'The job is already running in the background (%d done so far). It carries on by itself; run this again to take the next step here.', (int) ( $job['progress']['done'] ?? 0 ) ) );
			return;
		}
		if ( 'pending' === $job['status'] && (int) $job['wait'] > time() ) {
			\WP_CLI::warning( sprintf( 'The job is resting for %d s: %s', (int) $job['wait'] - time(), $job['error'] ) );
			return;
		}
		if ( 'done' !== $job['status'] ) {
			\WP_CLI::error( sprintf( 'The job stopped: %s %s', $job['status'], $job['error'] ) );
		}
		if ( '' !== (string) $job['error'] ) {
			\WP_CLI::warning( (string) $job['error'] ); // Q335: the pages it could not read, by ID.
		}
		\WP_CLI::success( 'Done.' );
	}
}
