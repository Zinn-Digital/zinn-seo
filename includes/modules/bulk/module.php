<?php
/**
 * Module: the bulk editor, with undo (zs-024, zs-351).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'bulk',
	'name'        => static fn() => __( 'Bulk editor', 'zinn-seo' ),
	'description' => static fn() => __( 'Edit SEO titles, descriptions, robots and canonical addresses across thousands of posts and terms, change every matching page at once, and undo any change.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'bulk',
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-024', 'zs-351' ),
	'files'       => array( 'class-history.php', 'class-bulk.php' ),
	// Admin, REST, CLI, and cron/admin-ajax where the background steps run. Never the front end.
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		// The history table is created the first time a change is recorded (History::open()), so
		// a site that never uses the bulk editor has no table and no extra admin query.
		\ZinnDigital\ZinnSEO\Bulk\Bulk::boot();
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data          = (array) $data;
					$data['bulk'] = true;
					return $data;
				}
			);
		}
		// WP-CLI too: `rest_do_request()` from a command (and agents driving WP-CLI) reach the same routes.
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-bulk-rest.php';
			\ZinnDigital\ZinnSEO\Bulk\Bulk_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-bulk-cli.php';
			\WP_CLI::add_command( 'zinn-seo bulk', \ZinnDigital\ZinnSEO\Bulk\Bulk_Cli::class );
		}
	},
);
