<?php
/**
 * Module: internal links — the link index, suggestions both ways, orphans and dead ends, link
 * counts, link attribute rules and breadcrumbs for themes (zs-100, zs-102, zs-105, zs-106).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'links',
	'name'        => static fn() => __( 'Internal links', 'zinn-seo' ),
	'description' => static fn() => __( 'Which page links to which, suggestions of the links to add while you write, pages nothing links to, rules for nofollow, sponsored and new-tab links, and breadcrumbs for themes.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'links',
	'requires'    => array( 'analysis' ),
	'features'    => array( 'zs-100', 'zs-102', 'zs-105', 'zs-106', 'zs-758', 'zs-759' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-attributes.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-breadcrumbs.php';
		require_once __DIR__ . '/functions.php';
		\ZinnDigital\ZinnSEO\Links\Breadcrumbs::boot();
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Links\Attributes::boot_front();
			return;
		}
		\ZinnDigital\ZinnSEO\Analysis\Loader::classes();
		require_once dirname( __DIR__ ) . '/analysis/class-render.php';
		require_once dirname( __DIR__ ) . '/analysis/class-analysis.php';
		require_once __DIR__ . '/class-index.php';
		require_once __DIR__ . '/class-suggest.php';
		\ZinnDigital\ZinnSEO\Links\Index::boot();
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-links-rest.php';
			\ZinnDigital\ZinnSEO\Links\Links_Rest::init();
		}
		if ( 'admin' === $context ) {
			require_once __DIR__ . '/class-lists.php';
			\ZinnDigital\ZinnSEO\Links\Lists::boot();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-links-cli.php';
			\WP_CLI::add_command( 'zinn-seo links', \ZinnDigital\ZinnSEO\Links\Links_Cli::class );
		}
	},
);
