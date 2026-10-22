<?php
/**
 * WP-CLI for the research toolbox.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Extra;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp zinn-seo toolbox …` — the same calls the screen makes, for hosts and scripts.
 */
final class Extra_Cli {

	/**
	 * A JSON file named on the command line, as an array.
	 *
	 * @param string $file Path.
	 * @return array<string, mixed>|null
	 */
	private static function json( string $file ): ?array {
		if ( '' === $file || ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Run a sub-command.
	 *
	 * @param array<int, string>    $args  Positional arguments.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public static function run( $args, $assoc ): void {
		$sub    = (string) ( $args[0] ?? 'products' );
		$a      = (string) ( $args[1] ?? '' );
		$params = self::json( (string) ( $args[2] ?? '' ) );
		$need   = new \WP_Error( 'zinn_seo_cli', 'Give a JSON file holding the parameters.' );
		switch ( $sub ) {
			case 'quote':
				$out = null === $params ? $need : Extra::quote( $a, $params );
				break;
			case 'run':
				$out = null === $params ? $need : Extra::run( $a, $params );
				break;
			case 'runs':
				$out = Extra::runs( $a );
				break;
			case 'run-get':
				$out = Extra::run_get( $a );
				break;
			case 'watches':
				$out = Extra::watches();
				break;
			case 'watch':
				$out = null === $params ? $need : Extra::watch( $a, $params, (int) ( $assoc['every'] ?? 7 ) );
				break;
			case 'unwatch':
				$out = Extra::unwatch( $a );
				break;
			default:
				$out = Extra::overview();
		}
		if ( is_wp_error( $out ) ) {
			$data = (array) $out->get_error_data();
			\WP_CLI::error( $out->get_error_message() . ( isset( $data['zinn']['class'] ) ? ' [' . $data['zinn']['class'] . ':' . ( $data['zinn']['code'] ?? '' ) . ']' : '' ) );
		}
		\WP_CLI::line( (string) wp_json_encode( $out ) );
	}
}
