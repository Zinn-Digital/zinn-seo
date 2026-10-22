<?php
/**
 * WP-CLI: `wp zinn-seo …` (zs-245).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/cli/class-cli-settings.php';
require_once __DIR__ . '/cli/class-cli-field.php';
require_once __DIR__ . '/cli/class-cli-modules.php';
require_once __DIR__ . '/cli/class-cli-jobs.php';
require_once __DIR__ . '/cli/class-cli-setup.php';
require_once __DIR__ . '/cli/class-cli-notrace.php';

/**
 * The root command. Modules add their own `wp zinn-seo` subcommands when they boot in the `cli`
 * context.
 *
 * ## EXAMPLES
 *
 *     wp zinn-seo info
 *     wp zinn-seo settings get site_type
 *     wp zinn-seo settings set site_type shop
 *     wp zinn-seo settings export > seo.json
 *     wp zinn-seo settings import seo.json --yes
 *     wp zinn-seo field set post 42 robots '["noindex"]'
 *     wp zinn-seo modules list
 *     wp zinn-seo jobs run <id>
 *     wp zinn-seo notrace on
 */
final class Cli {

	/**
	 * Register the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'zinn-seo info', array( self::class, 'info' ) );
		\WP_CLI::add_command( 'zinn-seo settings', Cli_Settings::class );
		\WP_CLI::add_command( 'zinn-seo field', Cli_Field::class );
		\WP_CLI::add_command( 'zinn-seo modules', Cli_Modules::class );
		\WP_CLI::add_command( 'zinn-seo features', array( self::class, 'features' ) );
		\WP_CLI::add_command( 'zinn-seo jobs', Cli_Jobs::class );
		\WP_CLI::add_command( 'zinn-seo setup', Cli_Setup::class );
		\WP_CLI::add_command( 'zinn-seo notrace', Cli_Notrace::class );
	}

	/**
	 * Show the version, plan, modules and setup state.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Named.
	 * @return void
	 */
	public static function info( array $args, array $assoc ): void {
		$rows = array(
			array(
				'key'   => 'version',
				'value' => ZINN_SEO_VERSION,
			),
			array(
				'key'   => 'plan',
				'value' => Plugin::plan(),
			),
			array(
				'key'   => 'modules',
				'value' => (string) count( Modules::discover() ),
			),
			array(
				'key'   => 'features',
				'value' => (string) count( Features::all() ),
			),
			array(
				'key'   => 'notrace',
				'value' => Notrace::enabled() ? 'on' : 'off',
			),
			array(
				'key'   => 'setup_next',
				'value' => Setup::next_step(),
			),
		);
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * List the features this build has.
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
	public static function features( array $args, array $assoc ): void {
		$rows = array();
		foreach ( Features::all() as $id => $row ) {
			$rows[] = array( 'id' => $id ) + $row;
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'tier', 'area', 'module' ) );
	}

	/**
	 * Decode a CLI value: JSON when it parses, else the string.
	 *
	 * @param string $raw Raw value.
	 * @return mixed
	 */
	public static function value( string $raw ) {
		$decoded = json_decode( $raw, true );

		return ( null === $decoded && 'null' !== $raw ) ? $raw : $decoded;
	}

	/**
	 * Stop with a WP_Error's message.
	 *
	 * @param mixed $result A result that may be a WP_Error.
	 * @return void
	 */
	public static function bail_on_error( $result ): void {
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
	}
}

Cli::register();
