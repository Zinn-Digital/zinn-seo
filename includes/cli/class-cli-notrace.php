<?php
/**
 * WP-CLI: the no-trace mode.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The no-trace mode.
 */
final class Cli_Notrace {

	/**
	 * Show whether it is on.
	 *
	 * @return void
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Notrace::describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Switch it on (lists what changes).
	 *
	 * @return void
	 */
	public function on(): void {
		self::switch_to( true );
	}

	/**
	 * Switch it off (lists what changes).
	 *
	 * @return void
	 */
	public function off(): void {
		self::switch_to( false );
	}

	/**
	 * Switch.
	 *
	 * @param bool $on Whether it should be on.
	 * @return void
	 */
	private static function switch_to( bool $on ): void {
		$result = Notrace::apply( $on, 'cli' );
		Cli::bail_on_error( $result );
		foreach ( $result['changes'] as $change ) {
			\WP_CLI::line( sprintf( '%s: %s -> %s%s', $change['what'], $change['from'], $change['to'], $change['resubmit'] ? ' (resubmit to search engines)' : '' ) );
		}
		\WP_CLI::success( $on ? 'No-trace mode is on.' : 'No-trace mode is off.' );
	}
}
