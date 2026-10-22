<?php
/**
 * Module: conflict detector — finds a second title, description, canonical, sharing tags,
 * structured data, robots.txt or sitemap printed by a theme or another plugin, and fixes the
 * common ones in one click (zs-703).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'conflicts',
	'name'        => static fn() => __( 'Conflict detector', 'zinn-seo' ),
	'description' => static fn() => __( 'Find themes and plugins that print a second title, description, sharing tags or structured data on your pages, and fix the common ones in one click, so search engines read one clear answer.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'cleanup',
	'features'    => array( 'zs-703' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-conflicts.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cron', 'cli' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Conflicts\Conflicts::boot( $context );
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-conflicts-rest.php';
			\ZinnDigital\ZinnSEO\Conflicts\Conflicts_Rest::init();
		}
	},
);
