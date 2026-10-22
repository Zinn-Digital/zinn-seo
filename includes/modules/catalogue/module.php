<?php
/**
 * Module: everything Zinn® sells for this site, read live — names, prices, what the account
 * already has, each offered on the screen where it is used (zs-650), with the managed AI and
 * agency products sold from here (zs-655, zs-656, zs-661).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'catalogue',
	'name'        => static fn() => __( 'Zinn® price list', 'zinn-seo' ),
	'description' => static fn() => __( 'See every Zinn® service that helps this site, with today\'s price and what you already have — always up to date, and bought in your Zinn® account in a new tab.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-650', 'zs-656', 'zs-661' ),
	'files'       => array( 'class-catalogue.php' ),
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		add_action( 'zinn_seo_disconnected', array( \ZinnDigital\ZinnSEO\Catalogue\Catalogue::class, 'forget' ) );
		add_action( 'zinn_seo_connected', array( \ZinnDigital\ZinnSEO\Catalogue\Catalogue::class, 'forget' ) );
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_admin_data', array( \ZinnDigital\ZinnSEO\Catalogue\Catalogue::class, 'admin_data' ) );
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			add_action( 'rest_api_init', array( \ZinnDigital\ZinnSEO\Catalogue\Catalogue::class, 'routes' ) );
			if ( did_action( 'rest_api_init' ) ) {
				\ZinnDigital\ZinnSEO\Catalogue\Catalogue::routes();
			}
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo catalogue',
				static function (): void {
					$list = \ZinnDigital\ZinnSEO\Catalogue\Catalogue::get( true );
					if ( is_wp_error( $list ) ) {
						\WP_CLI::error( $list->get_error_message() );
					}
					\WP_CLI::line( (string) wp_json_encode( $list ) );
				},
				array( 'shortdesc' => 'Every Zinn® service for this site with its live price, and what the account holds.' )
			);
		}
	},
);
