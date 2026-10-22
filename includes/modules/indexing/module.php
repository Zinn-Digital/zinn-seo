<?php
/**
 * Module: the indexing console — send any page to the search engines by hand, keep pages out of
 * automatic submission, and see every engine's answer per page, retry and download (zs-742).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'indexing',
	'name'        => static fn() => __( 'Indexing console', 'zinn-seo' ),
	'description' => static fn() => __( 'Send any page to Bing, IndexNow engines and Google in one go, keep the pages you choose out of automatic sending, and see what each search engine answered — with one click to send again.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'indexing',
	'features'    => array( 'zs-742' ),
	'files'       => array( 'class-console.php', 'class-console-rest.php' ),
	'contexts'    => array( 'admin', 'rest', 'cron', 'cli' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Indexing\Console::hooks();
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_admin_data', array( \ZinnDigital\ZinnSEO\Indexing\Console_Rest::class, 'admin_data' ) );
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			add_action( 'rest_api_init', array( \ZinnDigital\ZinnSEO\Indexing\Console_Rest::class, 'routes' ) );
			if ( did_action( 'rest_api_init' ) ) {
				\ZinnDigital\ZinnSEO\Indexing\Console_Rest::routes();
			}
		}
	},
);
