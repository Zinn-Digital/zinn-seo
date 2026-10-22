<?php
/**
 * Module: robots.txt — an editor with checks and an address tester, and staging awareness
 * (zs-060, zs-332).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'robots',
	'name'        => static fn() => __( 'robots.txt', 'zinn-seo' ),
	'description' => static fn() => __( 'Edit robots.txt with checks that read it the way search engines do, test any address against it, and keep staging copies out of search.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'indexing',
	'features'    => array( 'zs-060', 'zs-332' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-rules.php', 'class-staging.php', 'class-robots.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Robots\Staging::boot( $context );
		if ( 'front' === $context ) {
			// Beside another active SEO plugin (and not told to run with it), that plugin keeps
			// robots.txt; staging protection above stays on either way.
			if ( \ZinnDigital\ZinnSEO\Head\Head::should_print() ) {
				\ZinnDigital\ZinnSEO\Robots\Robots::boot();
			}
			return;
		}
		require_once __DIR__ . '/class-robots-rest.php';
		\ZinnDigital\ZinnSEO\Robots\Robots_Rest::init();
		// Indexing status, manual submission and "request indexing" links (the IndexNow module's
		// own hooks run only while it is switched on).
		$indexnow = dirname( __DIR__ ) . '/indexnow/';
		if ( is_readable( $indexnow . 'class-indexnow.php' ) ) {
			require_once $indexnow . 'class-indexnow.php';
			require_once $indexnow . 'class-indexnow-rest.php';
			\ZinnDigital\ZinnSEO\Indexnow\Indexnow_Rest::init();
			if ( 'admin' === $context ) {
				require_once $indexnow . 'class-indexnow-admin.php';
				\ZinnDigital\ZinnSEO\Indexnow\Indexnow_Admin::boot();
			}
		}
	},
);
