<?php
/**
 * Module: IndexNow on publish, update and removal, and "request indexing" links (zs-080, zs-082).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'indexnow',
	'name'        => static fn() => __( 'IndexNow', 'zinn-seo' ),
	'description' => static fn() => __( 'Tell Bing, Yandex and the other IndexNow search engines about new and changed pages the moment they change, and open any page in Google\'s URL inspection.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'indexing',
	// Off until the owner switches it on (the setup offers it): it sends this site's public
	// addresses to a search engine service, which the readme's "External services" names.
	'default'     => false,
	'requires'    => array( 'robots', 'templates' ),
	'features'    => array( 'zs-080', 'zs-082' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-indexnow.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		// The screen's routes and the posts lists' links are started by the robots module, so
		// "Inspect in Google" and the status work while IndexNow itself is switched off.
		\ZinnDigital\ZinnSEO\Indexnow\Indexnow::boot( $context );
	},
);
