<?php
/**
 * WP-CLI: `wp zinn-seo analysis`.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyse pages and score the site from the command line.
 */
final class Analysis_Cli {

	/**
	 * Analyse one post and print every check.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function show( array $args, array $assoc ): void {
		$post = get_post( (int) ( $args[0] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( 'No such post.' );
			return;
		}
		$result = Analysis::run( $post );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}
		$rows = array();
		foreach ( array( 'seo', 'readability', 'a11y', 'answer' ) as $group ) {
			if ( ! is_array( $result[ $group ] ?? null ) ) {
				continue;
			}
			$checks = array_merge( (array) $result[ $group ]['checks'], (array) ( $result[ $group ]['inclusive'] ?? array() ) );
			foreach ( $checks as $c ) {
				$rows[] = array(
					'group'  => $group,
					'check'  => $c['id'],
					'status' => $c['status'],
					'text'   => $c['text'],
				);
			}
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'group', 'check', 'status', 'text' ) );
		\WP_CLI::line( sprintf( 'SEO %s · readability %s · accessibility %s', (string) ( $result['seo']['score'] ?? '-' ), (string) ( $result['readability']['score'] ?? '-' ), (string) ( $result['a11y']['score'] ?? '-' ) ) );
	}

	/**
	 * Score and store posts: the given IDs, or every post with --all (runs the resumable job).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs.
	 *
	 * [--all]
	 * : Score every post of every analysed type.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function score( array $args, array $assoc ): void {
		if ( isset( $assoc['all'] ) ) {
			Analysis::start_all();
			$job = Jobs::run(
				Analysis::JOB,
				static function ( array $job ): void {
					\WP_CLI::log( sprintf( '%d of %s', (int) $job['progress']['done'], null === $job['progress']['total'] ? '?' : (string) $job['progress']['total'] ) );
				}
			);
			\WP_CLI::success( sprintf( 'Scored %d posts (%s).', (int) $job['progress']['done'], (string) $job['status'] ) );
			return;
		}
		foreach ( $args as $id ) {
			$result = Analysis::score( (int) $id );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $id . ': ' . $result->get_error_message() );
				continue;
			}
			\WP_CLI::log( sprintf( '%d: SEO %s, readability %s', (int) $id, (string) ( $result['seo']['score'] ?? '-' ), (string) ( $result['readability']['score'] ?? '-' ) ) );
		}
	}
}
