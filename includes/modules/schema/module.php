<?php
/**
 * Module: the structured-data graph — the site's knowledge graph, the page and article nodes,
 * one graph that absorbs other plugins' nodes, the offline validator and the site graph for
 * agents (zs-030, zs-031, zs-032, zs-035, zs-038, zs-inn-07, zs-inn-08).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/blocks/class-html.php';
require_once dirname( __DIR__ ) . '/blocks/class-people.php';
require_once dirname( __DIR__ ) . '/blocks/class-trail.php';
require_once __DIR__ . '/class-schema.php';
require_once __DIR__ . '/class-fill.php';

return array(
	'id'          => 'schema',
	'name'        => static fn() => __( 'Structured data', 'zinn-seo' ),
	'description' => static fn() => __( 'One schema.org graph per page: who runs the site, the page, the article and its author\'s expertise, breadcrumbs, languages, and everything your blocks, WooCommerce and Page Builder Sandwich describe — checked offline against schema.org and Google\'s current rules, and readable by AI agents at one address.', 'zinn-seo' ),
	'tier'        => 'free',
	// Shared (zs-715): other features build on its helpers (structured data, sitemaps,
	// redirects, the block library), so its code stays available to them while it is switched off.
	// Switched off, it adds no hook and does none of its own work.
	'shared'      => true,
	'area'        => 'schema',
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-030', 'zs-031', 'zs-032', 'zs-035', 'zs-038', 'zs-inn-07', 'zs-inn-08', 'zs-760', 'zs-764', 'zs-765' ),
	'settings'    => \ZinnDigital\ZinnSEO\Schema\Schema::settings(),
	// zs-764: the FAQ, how-to, product and article details a post carries (bulk editor, AI fill).
	'meta'        => array_merge( \ZinnDigital\ZinnSEO\Schema\Schema::meta(), \ZinnDigital\ZinnSEO\Schema\Fill::meta() ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		// The article picture's copies are cut by WP-Cron, never on a page view (IMP2).
		add_action( \ZinnDigital\ZinnSEO\Schema\Article_Images::CRON, array( \ZinnDigital\ZinnSEO\Schema\Article_Images::class, 'make' ) );
		add_filter( 'wp_update_attachment_metadata', array( \ZinnDigital\ZinnSEO\Schema\Article_Images::class, 'forget' ), 10, 2 );
		// zs-765: the AI fill's runs step in the background (cron, a loopback, WP-CLI).
		if ( 'front' !== $context ) {
			require_once __DIR__ . '/class-fill-ai.php';
			\ZinnDigital\ZinnSEO\Schema\Fill_Ai::boot();
		}
		// zs-764: a filled FAQ and how-to join the page's graph (the page, the check, the site graph).
		add_filter( 'zinn_seo_page_nodes', array( \ZinnDigital\ZinnSEO\Schema\Fill::class, 'page_nodes' ), 20, 3 );
		if ( 'cron' === $context ) {
			return;
		}
		add_action( 'save_post', array( \ZinnDigital\ZinnSEO\Schema\Schema::class, 'store_word_count' ), 20, 2 );
		add_action( 'zinn_seo_settings_saved', array( \ZinnDigital\ZinnSEO\Schema\Schema::class, 'refresh_logo' ), 10, 2 );
		require_once __DIR__ . '/class-feed.php';
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Schema\Schema::boot_front();
			\ZinnDigital\ZinnSEO\Schema\Feed::boot();
			add_action( 'wp', array( \ZinnDigital\ZinnSEO\Schema\Fill::class, 'prepare' ), 21 );
			return;
		}
		require_once __DIR__ . '/class-vocab.php';
		require_once __DIR__ . '/class-rich-rules.php';
		require_once __DIR__ . '/class-validator.php';
		require_once __DIR__ . '/class-schema-rest.php';
		\ZinnDigital\ZinnSEO\Schema\Schema_Rest::init();
		require_once __DIR__ . '/class-fill-rest.php';
		\ZinnDigital\ZinnSEO\Schema\Fill_Rest::init();
		if ( 'admin' === $context ) {
			require_once __DIR__ . '/class-profile.php';
			\ZinnDigital\ZinnSEO\Schema\Profile::boot();
			add_filter( 'zinn_seo_admin_data', array( \ZinnDigital\ZinnSEO\Schema\Schema::class, 'admin_data' ) );
			add_filter( 'zinn_seo_editor_data', array( \ZinnDigital\ZinnSEO\Schema\Schema::class, 'editor_data' ) );
			require_once __DIR__ . '/class-classic.php';
			\ZinnDigital\ZinnSEO\Schema\Classic::boot();
		}
		if ( 'rest' === $context ) {
			// The graph a REST request computes (validator, editor) includes the site's nodes.
			add_filter( 'zinn_seo_graph', array( \ZinnDigital\ZinnSEO\Schema\Schema::class, 'graph' ), 15, 2 );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			add_filter( 'zinn_seo_graph', array( \ZinnDigital\ZinnSEO\Schema\Schema::class, 'graph' ), 15, 2 );
			require_once __DIR__ . '/class-schema-cli.php';
			\WP_CLI::add_command( 'zinn-seo schema', \ZinnDigital\ZinnSEO\Schema\Schema_Cli::class );
		}
	},
);
