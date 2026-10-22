<?php
/**
 * Module: internal site-search insights — what visitors search for on the site, and what finds
 * nothing, as content ideas (zs-414).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'site-search',
	'name'        => static fn() => __( 'Site search insights', 'zinn-seo' ),
	'description' => static fn() => __( 'What visitors search for on your own site, and which searches find nothing — your next pages to write. One counter per phrase, never who searched.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'features'    => array( 'zs-414' ),
	'contexts'    => array( 'front', 'admin', 'rest' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-site-search.php';
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\SiteSearch\Site_Search::boot_front();
			return;
		}
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( \ZinnDigital\ZinnSEO\SiteSearch\Site_Search::class, 'routes' ) );
	},
);
