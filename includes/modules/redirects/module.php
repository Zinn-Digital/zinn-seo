<?php
/**
 * Module: redirects — the manager, served before the theme loads, automatic redirects for changed
 * and removed pages, chain and loop fixer, imports from other plugins and spreadsheets (zs-094,
 * zs-095, zs-096, zs-098, zs-099).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-redirects.php';

return array(
	'id'          => 'redirects',
	'name'        => static fn() => __( 'Redirects', 'zinn-seo' ),
	'description' => static fn() => __( 'Send old addresses to new ones (301, 302, 307, 308) or mark them gone (410, 451), answered before your theme loads; automatic redirects when a page moves or is deleted; chain and loop fixer; import from other plugins and spreadsheets.', 'zinn-seo' ),
	'tier'        => 'free',
	// Shared (zs-715): other features build on its helpers (structured data, sitemaps,
	// redirects, the block library), so its code stays available to them while it is switched off.
	// Switched off, it adds no hook and does none of its own work.
	'shared'      => true,
	'area'        => 'redirects',
	'features'    => array( 'zs-094', 'zs-095', 'zs-096', 'zs-098', 'zs-099' ),
	'settings'    => \ZinnDigital\ZinnSEO\Redirects\Redirects::settings(),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-path.php';
		require_once __DIR__ . '/class-store.php';
		require_once __DIR__ . '/class-index.php';
		require_once __DIR__ . '/class-serve.php';
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Redirects\Serve::boot();
			return;
		}
		require_once __DIR__ . '/class-auto.php';
		require_once __DIR__ . '/class-checker.php';
		require_once __DIR__ . '/class-importer.php';
		require_once __DIR__ . '/class-export.php';
		\ZinnDigital\ZinnSEO\Redirects\Redirects::boot( $context );
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-redirects-rest.php';
			\ZinnDigital\ZinnSEO\Redirects\Redirects_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-redirects-cli.php';
			\WP_CLI::add_command( 'zinn-seo redirects', \ZinnDigital\ZinnSEO\Redirects\Redirects_Cli::class );
		}
	},
);
