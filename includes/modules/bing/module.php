<?php
/**
 * Module: Bing Webmaster Tools in WordPress — clicks and impressions by day, search and page, and
 * the sitemap sent to Bing on connect (zs-090, zs-092); crawl problems, crawl rate, backlinks and
 * keyword research from the same key (zs-743).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'bing',
	'name'        => static fn() => __( 'Bing Webmaster Tools', 'zinn-seo' ),
	'description' => static fn() => __( 'See your Bing searches and clicks next to Google\'s, and send your sitemap to Bing in one click — with your own free Bing key, straight from your site.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'search',
	'requires'    => array( 'gsc' ),
	'features'    => array( 'zs-090', 'zs-092', 'zs-743' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-bing.php';
		add_filter( 'zinn_seo_sitemap_submit', array( \ZinnDigital\ZinnSEO\Bing\Bing::class, 'on_submit' ), 10, 3 );
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-bing-rest.php';
			require_once __DIR__ . '/class-bing-data.php';
			\ZinnDigital\ZinnSEO\Bing\Bing_Rest::init();
			// zs-743: crawl problems, crawl rate, backlinks and keyword research from the same key.
			add_action( 'rest_api_init', array( \ZinnDigital\ZinnSEO\Bing\Bing_Data::class, 'routes' ) );
			if ( did_action( 'rest_api_init' ) ) {
				\ZinnDigital\ZinnSEO\Bing\Bing_Data::routes();
			}
		}
	},
);
