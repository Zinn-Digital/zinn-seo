<?php
/**
 * WP-CLI: list and switch modules.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List and switch modules.
 */
final class Cli_Modules {

	/**
	 * List the modules.
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
		foreach ( Modules::describe() as $module ) {
			$rows[] = array(
				'id'      => $module['id'],
				'name'    => $module['name'],
				'tier'    => $module['tier'],
				'enabled' => $module['enabled'] ? 'yes' : 'no',
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'name', 'tier', 'enabled' ) );
	}

	/**
	 * Switch a module on.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Module id.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function enable( array $args ): void {
		Cli::bail_on_error( Modules::set( $args[0], true ) );
		\WP_CLI::success( sprintf( '%s is on.', $args[0] ) );
	}

	/**
	 * Switch a module off.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Module id.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function disable( array $args ): void {
		Cli::bail_on_error( Modules::set( $args[0], false ) );
		\WP_CLI::success( sprintf( '%s is off.', $args[0] ) );
	}
}
