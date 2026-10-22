<?php
/**
 * Module: address changes — before a permalink, category-base or domain change, every address that
 * will move is shown and its redirect written first; old host names keep answering (zs-333).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'migrate-safety',
	'name'        => static fn() => __( 'Address changes', 'zinn-seo' ),
	'description' => static fn() => __( 'Before you change the permalink structure, a category or tag base or the domain, see every address that will move; the redirects are written first, then the switch is made. An old domain name that still reaches the site is sent to the new one.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'redirects',
	'requires'    => array( 'redirects' ),
	'features'    => array( 'zs-333' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-migrate.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Migrate\Migrate::boot_front();
			return;
		}
		\ZinnDigital\ZinnSEO\Migrate\Migrate::boot( $context );
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-migrate-rest.php';
			\ZinnDigital\ZinnSEO\Migrate\Migrate_Rest::init();
		}
	},
);
