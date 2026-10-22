<?php
/**
 * Module: AI crawlers — allow or block each one, with what it is for, and content-usage signals
 * (zs-064, zs-067).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'crawlers',
	'name'        => static fn() => __( 'AI crawlers', 'zinn-seo' ),
	'description' => static fn() => __( 'Choose which AI crawlers may read the site, knowing which ones train models and which ones answer questions with a link to you, and state how your content may be used.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'ai',
	'requires'    => array( 'robots' ),
	'features'    => array( 'zs-064', 'zs-067' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-crawlers.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		add_filter( 'zinn_seo_robots_groups', array( \ZinnDigital\ZinnSEO\Crawlers\Crawlers::class, 'robots_groups' ) );
		add_filter( 'zinn_seo_robots_star', array( \ZinnDigital\ZinnSEO\Crawlers\Crawlers::class, 'star_lines' ) );
		if ( 'front' === $context ) {
			add_action( 'zinn_seo_loaded', array( \ZinnDigital\ZinnSEO\Crawlers\Crawlers::class, 'maybe_serve_tdmrep' ) );
			return;
		}
		add_filter(
			'zinn_seo_setup_steps',
			static function ( $steps ) {
				$steps              = (array) $steps;
				$steps['discovery'] = array(
					'title'   => __( 'Sitemaps and AI crawlers', 'zinn-seo' ),
					'section' => 'discovery',
				);
				return $steps;
			}
		);
		require_once __DIR__ . '/class-crawlers-rest.php';
		\ZinnDigital\ZinnSEO\Crawlers\Crawlers_Rest::init();
	},
);
