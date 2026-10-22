<?php
/**
 * Module: RSS feed SEO — a line linking back to the original on every feed item, the featured
 * image in the feed, and a delay before new posts reach it (zs-717).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'feeds',
	'name'        => static fn() => __( 'RSS feeds', 'zinn-seo' ),
	'description' => static fn() => __( 'Add a line to every feed item that links back to your post, so copies made by scraper sites credit you; add the featured image; and hold new posts back from the feed for a while.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'cleanup',
	'features'    => array( 'zs-717' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-feeds.php' ),
	'contexts'    => array( 'front' ),
	'boot'        => static function (): void {
		\ZinnDigital\ZinnSEO\Feeds\Feeds::boot_front();
	},
);
