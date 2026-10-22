<?php
/**
 * Module: internal link updates — when an address changes, every internal link to it is rewritten
 * to the new address, in the background, as revisions (zs-730).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'link-updates',
	'name'        => static fn() => __( 'Internal link updates', 'zinn-seo' ),
	'description' => static fn() => __( 'When you change a page\'s address, every link to it on your site is updated to the new one automatically, so visitors and search engines never take a redirect detour.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'links',
	'features'    => array( 'zs-730' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-link-updates.php' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\LinkUpdates\Link_Updates::boot();
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-link-updates-rest.php';
			\ZinnDigital\ZinnSEO\LinkUpdates\Link_Updates_Rest::init();
		}
	},
);
