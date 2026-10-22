<?php
/**
 * WP-CLI: read and change a post's or term's SEO fields.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and change a post's or term's SEO fields.
 */
final class Cli_Field {

	/**
	 * Print a field, or every field, of a post or term.
	 *
	 * ## OPTIONS
	 *
	 * <object>
	 * : post or term.
	 *
	 * <id>
	 * : Its ID.
	 *
	 * [<field>]
	 * : The field.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function get( array $args ): void {
		$id     = (int) $args[1];
		$fields = isset( $args[2] ) ? array( $args[2] => true ) : Meta::fields();
		$out    = array();
		foreach ( array_keys( $fields ) as $name ) {
			$out[ $name ] = 'term' === $args[0] ? Meta::get_term( $id, (string) $name ) : Meta::get_post( $id, (string) $name );
		}
		\WP_CLI::line( (string) wp_json_encode( isset( $args[2] ) ? $out[ $args[2] ] : $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Change a field of a post or term. The value is read as JSON when it parses.
	 *
	 * ## OPTIONS
	 *
	 * <object>
	 * : post or term.
	 *
	 * <id>
	 * : Its ID.
	 *
	 * <field>
	 * : The field.
	 *
	 * <value>
	 * : The new value ('' clears it).
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function set( array $args ): void {
		Cli::bail_on_error( Meta::set( 'term' === $args[0] ? 'term' : 'post', (int) $args[1], $args[2], Cli::value( $args[3] ), 'cli' ) );
		\WP_CLI::success( sprintf( '%s saved.', $args[2] ) );
	}
}
