<?php
/**
 * WP-CLI for the pay-per-use checks.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Tools;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp zinn-seo tools …` — the same calls the screens make, for hosts and scripts.
 */
final class Tools_Cli {

	/**
	 * Run a sub-command.
	 *
	 * @param array<int, string>    $args  Positional arguments.
	 * @param array<string, string> $assoc Flags.
	 * @return void
	 */
	public static function run( $args, $assoc ): void {
		$sub = (string) ( $args[0] ?? 'status' );
		$a   = (string) ( $args[1] ?? '' );
		switch ( $sub ) {
			case 'schedule':
				$on  = (string) ( $args[2] ?? '' );
				$out = Tools::schedule( $a, 'on' === $on );
				break;
			case 'site':
				$out = Tools::update_site( array_intersect_key( $assoc, array_flip( array( 'brand', 'category', 'market', 'cadence_days' ) ) ) );
				break;
			case 'list':
				$file  = (string) ( $args[2] ?? '' );
				$items = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
				$out   = is_array( $items ) ? Tools::save_list( $a, $items ) : new \WP_Error( 'zinn_seo_cli', 'Give a JSON file holding the list.' );
				break;
			case 'run':
				$out = Tools::run(
					$a,
					array(
						'seed'     => (string) ( $assoc['seed'] ?? '' ),
						'page_url' => (string) ( $assoc['page'] ?? '' ),
					)
				);
				break;
			case 'results':
				$out = Tools::results( $a );
				break;
			case 'result':
				$out = Tools::audit( $a );
				break;
			case 'index':
				$out = Index::check( array_slice( $args, 1 ) );
				break;
			case 'index-run':
				$out = Index::read( $a );
				break;
			case 'index-site':
				if ( 'start' === $a ) {
					$out = Index::start_site();
					if ( ! is_wp_error( $out ) && isset( $assoc['wait'] ) ) {
						Jobs::run( Index::JOB );
						$out = Index::job();
					}
				} elseif ( in_array( $a, array( 'pause', 'resume', 'cancel' ), true ) ) {
					$out = Jobs::control( Index::JOB, $a );
					if ( ! is_wp_error( $out ) && 'resume' === $a && isset( $assoc['wait'] ) ) {
						Jobs::run( Index::JOB );
					}
					$out = is_wp_error( $out ) ? $out : Index::job();
				} else {
					$out = Index::overview();
				}
				break;
			default:
				$out = Tools::overview();
		}
		if ( is_wp_error( $out ) ) {
			$data = (array) $out->get_error_data();
			\WP_CLI::error( $out->get_error_message() . ( isset( $data['zinn']['class'] ) ? ' [' . $data['zinn']['class'] . ':' . ( $data['zinn']['code'] ?? '' ) . ']' : '' ) );
		}
		\WP_CLI::line( (string) wp_json_encode( $out ) );
	}
}
