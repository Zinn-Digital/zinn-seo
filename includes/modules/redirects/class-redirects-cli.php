<?php
/**
 * `wp zinn-seo redirects …`
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage redirects from the command line.
 */
final class Redirects_Cli {

	/**
	 * List redirects (newest first).
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only rules whose source, target or note contains this.
	 *
	 * [--problem=<problem>]
	 * : chain, loop, missing, shadows or any.
	 *
	 * [--per=<n>]
	 * : Rows. Default 50.
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default table.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function list( $args, $assoc ): void {
		$page = Store::page(
			array(
				'search'  => (string) ( $assoc['search'] ?? '' ),
				'problem' => (string) ( $assoc['problem'] ?? '' ),
				'per'     => (int) ( $assoc['per'] ?? 50 ),
			)
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $page['items'], array( 'id', 'source', 'target', 'code', 'regex', 'enabled', 'hits', 'origin', 'problem' ) );
	}

	/**
	 * Add (or, with --replace, replace) a redirect.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The address to redirect from (a path, or a pattern with --regex).
	 *
	 * [<target>]
	 * : Where to send visitors (not for 410/451).
	 *
	 * [--code=<code>]
	 * : 301, 302, 307, 308, 410 or 451. Default 301.
	 *
	 * [--regex]
	 * : The source is a regular expression.
	 *
	 * [--group=<group>]
	 * : A group name.
	 *
	 * [--replace]
	 * : Replace a rule that already has this source.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function add( $args, $assoc ): void {
		$saved = Store::save(
			array(
				'source' => (string) $args[0],
				'target' => (string) ( $args[1] ?? '' ),
				'code'   => (int) ( $assoc['code'] ?? 301 ),
				'regex'  => isset( $assoc['regex'] ),
				'group'  => (string) ( $assoc['group'] ?? '' ),
				'origin' => 'cli',
			),
			isset( $assoc['replace'] )
		);
		if ( is_wp_error( $saved ) ) {
			\WP_CLI::error( $saved->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Redirect %d: %s -> %s (%d)', $saved['id'], $saved['source'], '' === $saved['target'] ? '-' : $saved['target'], $saved['code'] ) );
	}

	/**
	 * Delete redirects by id.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Rule ids.
	 *
	 * @param array<int, string> $args Ids.
	 * @return void
	 */
	public function delete( $args ): void {
		\WP_CLI::success( sprintf( '%d deleted.', Store::delete( array_map( 'intval', $args ) ) ) );
	}

	/**
	 * What a visitor asking for an address gets.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : A path or an address on this site.
	 *
	 * @param array<int, string> $args Address.
	 * @return void
	 */
	public function test( $args ): void {
		$match = Serve::match( (string) $args[0], true );
		if ( null === $match ) {
			\WP_CLI::log( 'No redirect.' );
			return;
		}
		\WP_CLI::log( sprintf( 'Rule %d answers %d%s', $match['id'], $match['code'], '' === $match['location'] ? '' : ' -> ' . $match['location'] ) );
		if ( '' !== $match['location'] ) {
			$f = Checker::follow( (int) $match['id'], (string) $match['location'] );
			\WP_CLI::log( sprintf( 'Follows: %s, %d more hop(s), ends at %s', $f['status'], $f['hops'], $f['final'] ) );
		}
	}

	/**
	 * Import redirects (dry run unless --apply).
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : redirection, yoast, rankmath, aioseo, seopress, srm, or a CSV file path.
	 *
	 * [--apply]
	 * : Store them (otherwise only counted).
	 *
	 * @param array<int, string>    $args  Source.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function import( $args, $assoc ): void {
		$source = (string) $args[0];
		if ( ! isset( Importer::names()[ $source ] ) ) {
			if ( ! is_readable( $source ) ) {
				\WP_CLI::error( 'Unknown source and no such file: ' . $source );
			}
			$token = Importer::keep_upload( (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
			if ( is_wp_error( $token ) ) {
				\WP_CLI::error( $token->get_error_message() );
			}
			$source = 'csv:' . $token;
		}
		if ( ! isset( $assoc['apply'] ) ) {
			$p = Importer::preview( $source );
			\WP_CLI::log( (string) wp_json_encode( $p['counts'] ) );
			foreach ( $p['refused'] as $r ) {
				\WP_CLI::log( '  refused ' . $r['source'] . ': ' . $r['reason'] );
			}
			\WP_CLI::log( 'Dry run. Add --apply to import.' );
			return;
		}
		$s = Importer::start( $source );
		if ( is_wp_error( $s ) ) {
			\WP_CLI::error( $s->get_error_message() );
		}
		$job = Jobs::run( Importer::JOB );
		\WP_CLI::success( 'Imported: ' . wp_json_encode( $job['state']['counts'] ?? array() ) );
	}

	/**
	 * Export every redirect as a spreadsheet.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Where to write it.
	 *
	 * @param array<int, string> $args File.
	 * @return void
	 */
	public function export( $args ): void {
		$fh = fopen( (string) $args[0], 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a file named on the command line.
		if ( false === $fh ) {
			\WP_CLI::error( 'Cannot write ' . $args[0] );
		}
		fwrite( $fh, implode( ',', Export::COLUMNS ) . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		$n = Export::each(
			static function ( array $row ) use ( $fh ): void {
				fwrite( $fh, Export::csv_line( $row ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			}
		);
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		\WP_CLI::success( sprintf( '%d redirects written to %s', $n, $args[0] ) );
	}

	/**
	 * Find chains and loops (and, with --targets, targets that are missing); --fix fixes them.
	 *
	 * ## OPTIONS
	 *
	 * [--targets]
	 * : Also ask every target on this site whether it answers.
	 *
	 * [--fix]
	 * : Point chains at their final address, switch loops off.
	 *
	 * @param array<int, string>    $args  Unused.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function check( $args, $assoc ): void {
		Checker::start( isset( $assoc['targets'] ) );
		$job = Jobs::run( Checker::JOB );
		\WP_CLI::log( 'Found: ' . wp_json_encode( $job['state']['found'] ?? array() ) );
		if ( isset( $assoc['fix'] ) ) {
			\WP_CLI::success( 'Fixed: ' . wp_json_encode( Checker::fix() ) );
		}
	}

	/**
	 * Rewrite the redirect index visitors' requests read.
	 *
	 * @return void
	 */
	public function rebuild(): void {
		Store::maybe_install();
		Jobs::start( Index::JOB, array() );
		$job = Jobs::run( Index::JOB );
		\WP_CLI::success( sprintf( 'Index written: %d rules, kept as %s.', (int) ( $job['state']['rules'] ?? 0 ), Index::store() ) );
	}
}
