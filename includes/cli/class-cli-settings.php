<?php
/**
 * WP-CLI: read, change, export and import the settings.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read, change, export and import the settings.
 */
final class Cli_Settings {

	/**
	 * Print one setting, or all of them.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : The setting.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function get( array $args ): void {
		$value = isset( $args[0] ) ? Settings::get( $args[0] ) : Settings::all();
		\WP_CLI::line( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Change one setting. The value is read as JSON when it parses (true, 3, ["a"]).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting.
	 *
	 * <value>
	 * : The new value.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function set( array $args ): void {
		if ( in_array( $args[0], array( 'notrace', 'notrace_hash' ), true ) ) {
			\WP_CLI::error( 'Use `wp zinn-seo notrace on|off`, which lists what changes.' );
		}
		Cli::bail_on_error( Settings::save( array( $args[0] => Cli::value( $args[1] ) ), 'cli' ) );
		\WP_CLI::success( sprintf( '%s saved.', $args[0] ) );
	}

	/**
	 * Print the settings export (JSON) for another site.
	 *
	 * @return void
	 */
	public function export(): void {
		\WP_CLI::line( (string) wp_json_encode( Transfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Import a settings export. Without --yes it only lists what would change.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The JSON file (or - for standard input).
	 *
	 * [--yes]
	 * : Apply the changes.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public function import( array $args, array $assoc ): void {
		$raw    = '-' === $args[0] ? (string) file_get_contents( 'php://stdin' ) : (string) file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
		$result = Transfer::import( $raw, empty( $assoc['yes'] ) );
		Cli::bail_on_error( $result );
		foreach ( $result['changes'] as $change ) {
			\WP_CLI::line( sprintf( '%s: %s -> %s', $change['key'], wp_json_encode( $change['from'] ), wp_json_encode( $change['to'] ) ) );
		}
		if ( $result['applied'] ) {
			\WP_CLI::success( sprintf( '%d settings imported.', count( $result['changes'] ) ) );
		} else {
			\WP_CLI::log( sprintf( '%d settings would change. Run again with --yes to apply.', count( $result['changes'] ) ) );
		}
	}
}
