<?php
/**
 * Module: the SEO block library — one renderer, every builder (zs-300, zs-301 … zs-311, zs-070,
 * zs-072, zs-074, zs-313).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-html.php';
require_once __DIR__ . '/class-fields.php';
require_once __DIR__ . '/class-render-context.php';
require_once __DIR__ . '/class-block.php';
require_once __DIR__ . '/class-collector.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-headings.php';
require_once __DIR__ . '/class-people.php';
require_once __DIR__ . '/class-trail.php';
require_once __DIR__ . '/class-foreign.php';
require_once __DIR__ . '/class-library.php';
require_once __DIR__ . '/class-blocks.php';

return array(
	'id'          => 'blocks',
	'name'        => static fn() => __( 'SEO blocks', 'zinn-seo' ),
	'description' => static fn() => __( 'Quick Answer, article index, contents, FAQ, how-to, reviews, pros and cons, author and expert box, related content, local business and breadcrumbs — written once, in the block editor, Page Builder Sandwich, Elementor and as a shortcode, each adding its structured data to the page\'s one graph and its few styles only to pages that use it.', 'zinn-seo' ),
	'tier'        => 'free',
	// Shared (zs-715): other features build on its helpers (structured data, sitemaps,
	// redirects, the block library), so its code stays available to them while it is switched off.
	// Switched off, it adds no hook and does none of its own work.
	'shared'      => true,
	'area'        => 'blocks',
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-070', 'zs-072', 'zs-074', 'zs-300', 'zs-301', 'zs-302', 'zs-303', 'zs-304', 'zs-306', 'zs-309', 'zs-311', 'zs-313', 'zs-inn-02' ),
	'settings'    => \ZinnDigital\ZinnSEO\Blocks\Blocks::settings(),
	'meta'        => \ZinnDigital\ZinnSEO\Blocks\Blocks::meta(),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Blocks\Blocks::boot( $context );
	},
);
