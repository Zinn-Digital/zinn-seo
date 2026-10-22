<?php
/**
 * Module: duplicate titles, descriptions and main headings across the site, and pages that
 * compete for one focus phrase (zs-431, zs-009).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'duplicates',
	'name'        => static fn() => __( 'Duplicate finder', 'zinn-seo' ),
	'description' => static fn() => __( 'Finds pages that share a title, a meta description or a main heading, and pages that compete for the same focus phrase, across the whole site.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'requires'    => array( 'analysis' ),
	'features'    => array( 'zs-009', 'zs-431' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Analysis\Loader::classes();
		require_once dirname( __DIR__ ) . '/analysis/class-render.php';
		require_once dirname( __DIR__ ) . '/analysis/class-analysis.php';
		require_once __DIR__ . '/class-duplicates.php';
		\ZinnDigital\ZinnSEO\Duplicates\Duplicates::boot();
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-duplicates-rest.php';
			\ZinnDigital\ZinnSEO\Duplicates\Duplicates_Rest::init();
		}
	},
);
