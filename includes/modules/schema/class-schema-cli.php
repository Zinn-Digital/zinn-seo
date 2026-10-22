<?php
/**
 * WP-CLI: `wp zinn-seo schema` (zs-031, zs-035, zs-038).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print and check structured data from the command line.
 */
final class Schema_Cli {

	/**
	 * Print the graph a page prints.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-seo schema graph 42
	 *
	 * @param array<int, string> $args Arguments.
	 * @return void
	 */
	public function graph( $args ): void {
		$post = get_post( (int) ( $args[0] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( __( 'There is no such page.', 'zinn-seo' ) );
		}
		\WP_CLI::line( (string) wp_json_encode( Feed::graph_for( $post ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Validate a page's structured data offline (schema.org and Google's rich-result rules).
	 *
	 * ## OPTIONS
	 *
	 * [<post>]
	 * : Post ID.
	 *
	 * [--file=<file>]
	 * : A JSON-LD file (or saved HTML page) instead of a post.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-seo schema validate 42
	 *     wp zinn-seo schema validate --file=page.html --format=json
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function validate( $args, $assoc ): void {
		if ( ! empty( $assoc['file'] ) ) {
			$text  = is_readable( (string) $assoc['file'] ) ? (string) file_get_contents( (string) $assoc['file'] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
			$graph = Schema_Rest::parse( $text );
			if ( null === $graph ) {
				\WP_CLI::error( __( 'No JSON-LD found in that file.', 'zinn-seo' ) );
			}
		} else {
			$post = get_post( (int) ( $args[0] ?? 0 ) );
			if ( ! $post instanceof \WP_Post ) {
				\WP_CLI::error( __( 'There is no such page.', 'zinn-seo' ) );
			}
			$graph = Feed::graph_for( $post );
		}
		$out = Validator::check( (array) $graph, Schema_Rest::own_names() );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array();
		foreach ( $out['nodes'] as $n ) {
			$rows[] = array(
				'type'     => $n['type'],
				'feature'  => $n['feature'],
				'status'   => $n['status'],
				'errors'   => implode( ' | ', $n['errors'] ),
				'warnings' => count( $n['warnings'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'feature', 'status', 'errors', 'warnings' ) );
		if ( $out['errors'] > 0 ) {
			/* translators: %d: a number of errors. */
			\WP_CLI::error( sprintf( _n( '%d error.', '%d errors.', (int) $out['errors'], 'zinn-seo' ), (int) $out['errors'] ) );
		}
		\WP_CLI::success( __( 'No errors.', 'zinn-seo' ) );
	}
}
