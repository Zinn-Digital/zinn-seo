<?php
/**
 * WP-CLI: the guided setup's state.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The guided setup's state.
 */
final class Cli_Setup {

	/**
	 * Show each step and what the setup found.
	 *
	 * @return void
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Setup::state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Start the setup again from the first step.
	 *
	 * @return void
	 */
	public function reset(): void {
		Setup::mark( '', 'reset' );
		\WP_CLI::success( 'The setup starts again at the first step.' );
	}
}
