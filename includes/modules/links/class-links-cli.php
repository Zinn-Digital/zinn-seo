<?php
/**
 * WP-CLI: `wp zinn-seo links`.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build and read the internal-link index.
 */
final class Links_Cli {

	/**
	 * Build the whole index (in this process; resumes where a killed build stopped with --resume).
	 *
	 * ## OPTIONS
	 *
	 * [--resume]
	 * : Continue a build that was stopped, instead of starting again.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function build( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		Index::maybe_install();
		$job = Jobs::get( Index::JOB );
		if ( ! isset( $assoc['resume'] ) || ! in_array( $job['status'], array( 'pending', 'paused', 'failed' ), true ) ) {
			Jobs::start( Index::JOB, array() );
		} elseif ( 'pending' !== $job['status'] ) {
			Jobs::control( Index::JOB, 'resume' );
		}
		$job = Jobs::run(
			Index::JOB,
			static function ( array $job ): void {
				\WP_CLI::log( sprintf( '%d of %d', (int) $job['progress']['done'], (int) $job['progress']['total'] ) );
			}
		);
		\WP_CLI::success( sprintf( 'Indexed %d pages (%s).', (int) $job['progress']['done'], (string) $job['status'] ) );
	}

	/**
	 * Totals: links, orphans, dead ends.
	 *
	 * @return void
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Index::totals() ) );
	}

	/**
	 * Pages nothing links to (orphan) or that link nowhere (deadend).
	 *
	 * ## OPTIONS
	 *
	 * <kind>
	 * : orphan or deadend.
	 *
	 * @param array<int, string> $args Arguments.
	 * @return void
	 */
	public function report( array $args ): void {
		$before = 0;
		do {
			$page = Index::report( 'deadend' === ( $args[0] ?? '' ) ? 'deadend' : 'orphan', $before, 500 );
			foreach ( $page['items'] as $id ) {
				\WP_CLI::line( $id . "\t" . get_the_title( $id ) );
			}
			$before = $page['next'];
		} while ( $before > 0 );
	}
}
