<?php
/**
 * Module: smart robots defaults — password-protected posts, empty archives, comment pages and feeds
 * kept out of search results, and page numbers a post does not have sent to the post (zs-713).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'smart-robots',
	'name'        => static fn() => __( 'Smart robots defaults', 'zinn-seo' ),
	'description' => static fn() => __( 'Keep thin pages out of search results automatically: password-protected posts, empty categories and tags, comment pages, feeds, and page numbers a post does not have.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'indexing',
	'features'    => array( 'zs-713' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-smart-robots.php' ),
	'contexts'    => array( 'front', 'admin', 'rest' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context ) {
			// Beside another SEO plugin that keeps the head, that plugin decides robots too.
			if ( ! class_exists( '\ZinnDigital\ZinnSEO\Head\Head' ) || \ZinnDigital\ZinnSEO\Head\Head::should_print() ) {
				\ZinnDigital\ZinnSEO\SmartRobots\Smart_Robots::boot_front();
			}
			return;
		}
		require_once __DIR__ . '/class-smart-robots-rest.php';
		\ZinnDigital\ZinnSEO\SmartRobots\Smart_Robots_Rest::init();
	},
);
