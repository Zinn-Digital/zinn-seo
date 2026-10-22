<?php
/**
 * WP-CLI: `wp zinn-seo sitemaps …`.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the XML sitemaps and see their health.
 *
 * ## EXAMPLES
 *
 *     # Build every sitemap file now, in this process, and report time and memory.
 *     wp zinn-seo sitemaps build
 *
 *     wp zinn-seo sitemaps status
 *     wp zinn-seo sitemaps files --format=csv
 */
final class Sitemaps_Cli {

	/**
	 * Build every sitemap file now (runs here, to the end), then any page changed meanwhile.
	 *
	 * When a background build is already running, this says so, shows how far it is and ends
	 * (exit code 0): the build you asked for is queued behind it. Add --wait to finish it in
	 * this terminal instead.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : When a background build is running, wait for its current step and finish the build here.
	 *
	 * [--quiet-progress]
	 * : Print only the result.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-seo sitemaps build
	 *     wp zinn-seo sitemaps build --wait
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function build( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		Store::mark( array( '*' ) );
		$job = Jobs::get( Builder::JOB );
		if ( 'paused' === (string) $job['status'] ) {
			Jobs::control( Builder::JOB, 'resume' );
		} elseif ( 'pending' !== (string) $job['status'] ) {
			Jobs::start( Builder::JOB, array() );
		}
		$started = microtime( true );
		$quiet   = isset( $assoc['quiet-progress'] );
		$wait    = isset( $assoc['wait'] );
		$tick    = static function ( array $job ) use ( $quiet ): void {
			if ( ! $quiet && ! Jobs::running( $job ) ) {
				\WP_CLI::log( self::progress( $job ) );
			}
		};
		$job     = Jobs::run( Builder::JOB, $tick );
		// Held by another process (the site's own background chain, or another terminal).
		while ( 'pending' === (string) $job['status'] && Jobs::running( $job ) ) {
			if ( ! $wait ) {
				\WP_CLI::log( self::already_running( $job ) );
				return; // Exit code 0: nothing failed, the build is on its way.
			}
			if ( ! $quiet ) {
				\WP_CLI::log( sprintf( 'A background build is running (%s). Waiting for its step to end…', self::progress( Jobs::get( Builder::JOB ) ) ) );
			}
			sleep( 2 );
			$job = Jobs::run( Builder::JOB, $tick );
		}
		if ( 'pending' === (string) $job['status'] && (int) $job['wait'] > time() ) {
			\WP_CLI::warning( sprintf( '%1$s Time left: about %2$d min. `wp zinn-seo jobs run sitemaps --restart` starts it now.', (string) $job['error'], (int) ceil( ( (int) $job['wait'] - time() ) / 60 ) ) );
			return;
		}
		if ( 'done' !== (string) $job['status'] ) {
			\WP_CLI::error( sprintf( 'The build stopped: %s %s', (string) $job['status'], (string) $job['error'] ) );
		}
		$d = Sitemaps_Rest::describe();
		\WP_CLI::success(
			sprintf(
				'%d addresses in %d files, built in %.2f s (this process: %.2f s); peak PHP memory %.1f MB, peak RSS %s MB; index %s',
				(int) $d['urls'],
				(int) $d['files'],
				(float) $d['took'],
				microtime( true ) - $started,
				memory_get_peak_usage( true ) / 1048576,
				self::peak_rss(),
				(string) $d['url']
			)
		);
	}

	/**
	 * How far the job is, in words: "340 of 1,000 files" (a refresh) or "12,000 of 100,000
	 * addresses" (a full build).
	 *
	 * @param array<string, mixed> $job The job's record.
	 * @return string
	 */
	public static function progress( array $job ): string {
		$p     = Builder::progress( $job );
		$done  = number_format_i18n( $p['done'] );
		$total = null !== $p['total'] ? number_format_i18n( $p['total'] ) : '?';
		// WP-CLI output is English, as everywhere in this command.
		return sprintf( 'files' === $p['unit'] ? '%1$s of %2$s files' : '%1$s of %2$s addresses', $done, $total );
	}

	/**
	 * What to tell a person whose build found another one running.
	 *
	 * @param array<string, mixed> $job The job's record.
	 * @return string
	 */
	public static function already_running( array $job ): string {
		return sprintf(
			'A sitemap build is already running in the background: %1$s so far. Nothing is wrong and nothing was lost: the full build you asked for is queued and runs when it ends. To follow it: %2$s. To finish it in this terminal instead: %3$s.',
			self::progress( $job ),
			'wp zinn-seo sitemaps status',
			'wp zinn-seo sitemaps build --wait'
		);
	}

	/**
	 * The process's peak resident memory (Linux `VmHWM`), in MB, or `?` where it cannot be read.
	 *
	 * @return string
	 */
	private static function peak_rss(): string {
		$status = is_readable( '/proc/self/status' ) ? (string) file_get_contents( '/proc/self/status' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the kernel's own status file.
		if ( 1 === preg_match( '/^VmHWM:\s+(\d+)\s+kB/m', $status, $m ) ) {
			return number_format( (int) $m[1] / 1024, 1, '.', '' );
		}

		return '?';
	}

	/**
	 * The sitemap's health as JSON.
	 *
	 * @return void
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Sitemaps_Rest::describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Every sitemap file.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function files( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		$rows = Sitemaps_Rest::files()->get_data();
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), (array) $rows, array( 'name', 'urls', 'images', 'bytes', 'lastmod', 'url' ) );
	}
}
