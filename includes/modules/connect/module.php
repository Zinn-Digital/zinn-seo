<?php
/**
 * Module: the site's connection to a Zinn® account — the door to the pay-per-use checks (zs-180).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'connect',
	'name'        => static fn() => __( 'Zinn® account', 'zinn-seo' ),
	'description' => static fn() => __( 'Connect this site to a Zinn® account to check rankings, Google\'s index, AI answers and backlinks from here, paying only for what you run. Nothing is sent until you agree.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'features'    => array( 'zs-180' ),
	// Never on a visitor's page: the connection is read only where someone can act on it.
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-client.php';
		require_once __DIR__ . '/class-connection.php';
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data               = (array) $data;
					// Who may connect, buy and run paid checks (administrators by default).
					$data['canSpend']   = current_user_can( \ZinnDigital\ZinnSEO\Capabilities::SPEND );
					$data['adminEmail'] = current_user_can( 'manage_options' ) ? (string) get_option( 'admin_email' ) : '';
					return $data;
				}
			);
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-connect-rest.php';
			\ZinnDigital\ZinnSEO\Connect\Connect_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo connect',
				static function ( $args, $assoc ): void {
					$sub = (string) ( $args[0] ?? 'status' );
					$c   = \ZinnDigital\ZinnSEO\Connect\Connection::class;
					if ( 'start' === $sub ) {
						$out = $c::start( isset( $assoc['yes'] ) );
					} elseif ( 'signup' === $sub ) {
						$out = $c::signup( isset( $assoc['yes'] ), (string) ( $assoc['email'] ?? '' ), (string) ( $assoc['name'] ?? '' ) );
					} elseif ( 'poll' === $sub ) {
						$out = $c::poll();
					} elseif ( 'disconnect' === $sub ) {
						$out = $c::disconnect();
					} else {
						$out = $c::describe();
					}
					if ( is_wp_error( $out ) ) {
						\WP_CLI::error( $out->get_error_message() );
					}
					\WP_CLI::line( (string) wp_json_encode( $out ) );
				},
				array(
					'shortdesc' => 'This site\'s Zinn® account connection: status | start --yes | signup --yes --email=<address> | poll | disconnect. --yes is your agreement to send the site address, the plugin and WordPress versions and the language to Zinn Digital®.',
				)
			);
		}
	},
);
