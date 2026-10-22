<?php
/**
 * Module: working with the other plugins a site runs — shops, marketplaces, forums, courses,
 * memberships, events, jobs, directories, recipes, podcasts, page builders, translation plugins,
 * custom fields, multisite and headless front ends — and the site's own business details
 * (zs-110…112, zs-115…118, zs-120…129, zs-133, zs-314, zs-440, zs-620…623) — and working as one
 * with our own Tranzly, Page Builder Sandwich, Zinn® Cache and Zinn® Chat (zs-130, zs-131,
 * zs-134…137, zs-230, zs-231, zs-inn-17; `includes/integrations/family/`).
 *
 * ⭐ One file is read to know what could be supported (`includes/integrations/registry.php`); the
 * code for a plugin is read only when that plugin is active on this site. A site with none of
 * them pays for one array.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ZINN_SEO_DIR . 'includes/integrations/class-integrations.php';

return array(
	'id'           => 'integrations',
	'name'         => static fn() => __( 'Works with your other plugins', 'zinn-seo' ),
	'description'  => static fn() => __( 'Product pages search engines can show with price, stock and reviews; forum threads, seller stores, courses, events, jobs and recipes marked up and in the sitemap; members-only pages kept out of search; the same SEO panel inside your page builder. Each part switches itself on when its plugin is active and costs nothing when it is not.', 'zinn-seo' ),
	'tier'         => 'free',
	// Shared (zs-715): other features build on its helpers (structured data, sitemaps,
	// redirects, the block library), so its code stays available to them while it is switched off.
	// Switched off, it adds no hook and does none of its own work.
	'shared'       => true,
	'area'         => 'integrations',
	'requires'     => array( 'head', 'templates' ),
	'features'     => array( 'zs-110', 'zs-111', 'zs-112', 'zs-115', 'zs-116', 'zs-117', 'zs-118', 'zs-120', 'zs-121', 'zs-122', 'zs-123', 'zs-124', 'zs-125', 'zs-126', 'zs-127', 'zs-128', 'zs-129', 'zs-133', 'zs-314', 'zs-440', 'zs-620', 'zs-621', 'zs-622', 'zs-623', 'zs-inn-11', 'zs-inn-12', 'zs-130', 'zs-131', 'zs-134', 'zs-135', 'zs-136', 'zs-137', 'zs-230', 'zs-231', 'zs-inn-17', 'zs-702', 'zs-705', 'zs-712', 'zs-719', 'zs-735', 'zs-761' ),
	'pro_features' => array( 'zs-111', 'zs-112', 'zs-117', 'zs-118', 'zs-120', 'zs-123', 'zs-124', 'zs-125', 'zs-126', 'zs-127', 'zs-128', 'zs-129', 'zs-133', 'zs-314', 'zs-440', 'zs-620', 'zs-621', 'zs-622', 'zs-623', 'zs-inn-11', 'zs-inn-12', 'zs-702', 'zs-705', 'zs-712', 'zs-719', 'zs-735', 'zs-761' ),
	'settings'     => \ZinnDigital\ZinnSEO\Integrations\Integrations::settings(),
	'meta'         => \ZinnDigital\ZinnSEO\Integrations\Integrations::meta(),
	'contexts'     => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'         => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Integrations\Integrations::boot( $context );
	},
);
