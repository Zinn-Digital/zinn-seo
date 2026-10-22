<?php
/**
 * Module: Core Web Vitals from real visitors per page and per template, with the cause named
 * (zs-322), and a lab speed test of any page (zs-411, Pro).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'cwv',
	'name'        => static fn() => __( 'Page speed (Core Web Vitals)', 'zinn-seo' ),
	'description' => static fn() => __( 'See how fast your pages really are for visitors — the speed Google ranks on — page by page and template by template, with what is slowing each one down and how to fix it.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'search',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-322', 'zs-411' ),
	'contexts'    => array( 'admin', 'rest' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-cwv.php';
		add_action( 'zinn_seo_disconnected', array( \ZinnDigital\ZinnSEO\Cwv\Cwv::class, 'forget' ) );
		require_once __DIR__ . '/class-cwv-rest.php';
		\ZinnDigital\ZinnSEO\Cwv\Cwv_Rest::init();
		unset( $context );
	},
);
