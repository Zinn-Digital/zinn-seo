<?php
/**
 * WP-CLI: `wp zinn-seo ai …`.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI on the command line: what is set up, run a task, review and accept suggestions, and (Pro)
 * run a whole-site job in this process until it is done.
 */
final class Ai_Cli {

	/**
	 * How AI is set up: own key or Zinn® credits, and the tasks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-seo ai status
	 *
	 * @return void
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Ai_Rest::state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Run a task on items now. The answers are kept as suggestions; nothing changes until accepted.
	 *
	 * ## OPTIONS
	 *
	 * <task>
	 * : Task id (meta, alt, schema, …).
	 *
	 * [<id>...]
	 * : Post or image ids.
	 *
	 * [--options=<json>]
	 * : Task options as JSON.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		$task = (string) array_shift( $args );
		$opts = json_decode( (string) ( $assoc['options'] ?? '{}' ), true );
		$run  = Ai::run( $task, array() === $args ? array( 0 ) : array_map( 'intval', $args ), is_array( $opts ) ? $opts : array() );
		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Accept a suggestion (as the AI wrote it).
	 *
	 * ## OPTIONS
	 *
	 * <task>
	 * : Task id.
	 *
	 * <id>
	 * : Post or image id.
	 *
	 * @param array<int, string> $args Arguments.
	 * @return void
	 */
	public function accept( array $args ): void {
		$done = Suggestions::accept( (int) ( $args[1] ?? 0 ), (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $done ) ) {
			\WP_CLI::error( $done->get_error_message() );
		}
		\WP_CLI::success( (string) wp_json_encode( $done, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * The review queue of a task.
	 *
	 * ## OPTIONS
	 *
	 * <task>
	 * : Task id.
	 *
	 * [--after=<id>]
	 * : Page after this id.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function queue( array $args, array $assoc ): void {
		\WP_CLI::line( (string) wp_json_encode( Suggestions::queue( (string) ( $args[0] ?? '' ), (int) ( $assoc['after'] ?? 0 ), 200 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Pro: write suggestions for the whole site (or count them first) and run the job to the end.
	 *
	 * ## OPTIONS
	 *
	 * <task>
	 * : meta, alt, product or llms.
	 *
	 * [--dry-run]
	 * : Only count what it would do.
	 *
	 * [--types=<list>]
	 * : Post types, comma separated (default: every public type).
	 *
	 * [--all]
	 * : Include items that already have their own (default: only those missing it).
	 *
	 * [--accept]
	 * : Accept every waiting suggestion of the task instead (after you reviewed them).
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function bulk( array $args, array $assoc ): void {
		$class = '\ZinnDigital\ZinnSEO\Pro\Ai\Bulk_Ai';
		if ( ! class_exists( $class ) ) {
			\WP_CLI::error( __( 'Whole-site AI runs are part of Zinn® SEO Pro.', 'zinn-seo' ) );
		}
		$task  = (string) ( $args[0] ?? '' );
		$scope = array( 'missing' => ! isset( $assoc['all'] ) );
		if ( isset( $assoc['types'] ) ) {
			$scope['types'] = array_map( 'trim', explode( ',', (string) $assoc['types'] ) );
		}
		$out = $class::start( $task, $scope, isset( $assoc['dry-run'] ), isset( $assoc['accept'] ) );
		if ( is_wp_error( $out ) ) {
			\WP_CLI::error( $out->get_error_message() );
		}
		if ( isset( $assoc['dry-run'] ) ) {
			\WP_CLI::line( (string) wp_json_encode( $out ) );
			return;
		}
		$job = \ZinnDigital\ZinnSEO\Jobs::run(
			(string) $out['job'],
			static function ( array $job ): void {
				\WP_CLI::log( sprintf( '%s %d/%s', $job['status'], (int) $job['progress']['done'], null === $job['progress']['total'] ? '?' : (string) $job['progress']['total'] ) );
			}
		);
		\WP_CLI::line(
			(string) wp_json_encode(
				$job['progress'] + array(
					'status' => $job['status'],
					'error'  => $job['error'],
				)
			)
		);
	}
}
