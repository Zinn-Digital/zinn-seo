<?php
/**
 * Module: moving from another SEO plugin — import, side-by-side reading, the page-by-page
 * comparison, switching the other plugin off after the proof, and undo (zs-260, zs-261).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'import',
	'name'        => static fn() => __( 'Move from another SEO plugin', 'zinn-seo' ),
	'description' => static fn() => __( 'Bring titles, descriptions, robots choices, canonical addresses, social tags, focus phrases, templates, site details and redirects over from Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO, Squirrly SEO, SmartCrawl, Redirection and Safe Redirect Manager; compare every page; switch the other plugin off only when the pages say the same; undo it all.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'tools',
	'requires'    => array( 'head', 'templates', 'bulk' ),
	'features'    => array( 'zs-260', 'zs-261', 'zs-725' ),
	'settings'    => require __DIR__ . '/settings.php',
	'meta'        => require __DIR__ . '/meta.php',
	'files'       => array( 'class-source.php', 'class-import.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		// A visitor's page: nothing at all unless a move is under way.
		$live = (string) \ZinnDigital\ZinnSEO\Settings::get( 'import_live', '' );
		if ( '' !== $live ) {
			require_once __DIR__ . '/class-live.php';
			\ZinnDigital\ZinnSEO\Import\Live::boot( $live );
		}
		if ( 'front' === $context ) {
			return;
		}
		require_once __DIR__ . '/class-signature.php';
		require_once __DIR__ . '/class-check.php';
		require_once __DIR__ . '/class-switcher.php';
		require_once __DIR__ . '/class-verify.php';
		require_once __DIR__ . '/class-preflight.php';
		require_once __DIR__ . '/class-selfcheck.php';
		require_once __DIR__ . '/class-purge.php';
		\ZinnDigital\ZinnSEO\Import\Import::boot();
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-import-rest.php';
			\ZinnDigital\ZinnSEO\Import\Import_Rest::init();
		}
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_admin_data', array( \ZinnDigital\ZinnSEO\Import\Import::class, 'admin_data' ) );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-import-cli.php';
			\WP_CLI::add_command( 'zinn-seo import', \ZinnDigital\ZinnSEO\Import\Import_Cli::class );
		}
	},
);
