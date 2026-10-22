<?php
/**
 * Module: site authority — your own Ahrefs Domain Rating (free key) and Open PageRank, and Zinn
 * Authority on Zinn® credit, with history, alerts when they move, and the vendor's attribution on
 * every number (zs-501, zs-502, zs-503, zs-504, zs-508, zs-509).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'ahrefs-dr',
	'name'        => static fn() => __( 'Site authority', 'zinn-seo' ),
	'description' => static fn() => __( 'Watch your Ahrefs Domain Rating with your own free Ahrefs key, your Open PageRank, or Zinn® Authority on credit — see the history and get an e-mail when it goes up or down.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'search',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-501', 'zs-502', 'zs-503', 'zs-504', 'zs-508', 'zs-509' ),
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-authority.php';
		add_action( 'zinn_seo_disconnected', array( \ZinnDigital\ZinnSEO\Authority\Authority::class, 'forget' ) );
		add_action( 'zinn_seo_connected', array( \ZinnDigital\ZinnSEO\Authority\Authority::class, 'forget' ) );
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-authority-rest.php';
			\ZinnDigital\ZinnSEO\Authority\Authority_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo authority',
				static function ( array $args ): void {
					$what = (string) ( $args[0] ?? 'status' );
					if ( 'run' === $what ) {
						$res = \ZinnDigital\ZinnSEO\Authority\Authority::run( (string) ( $args[1] ?? '' ) );
					} else {
						$res = \ZinnDigital\ZinnSEO\Authority\Authority::state( true );
					}
					if ( is_wp_error( $res ) ) {
						\WP_CLI::error( $res->get_error_message() );
					}
					\WP_CLI::line( (string) wp_json_encode( $res ) );
				},
				array(
					'shortdesc' => 'Site authority: the connected SEO data sources, what is tracked and the latest values; `run <source>` checks one now.',
					'synopsis'  => array(
						array(
							'type'        => 'positional',
							'name'        => 'action',
							'optional'    => true,
							'description' => 'status (default) or run.',
						),
						array(
							'type'        => 'positional',
							'name'        => 'source',
							'optional'    => true,
							'description' => 'The source to check now.',
						),
					),
				)
			);
		}
	},
);
