<?php
/**
 * Module: XML sitemaps — pre-built files, rebuilt only where pages changed, with images,
 * languages and an HTML sitemap (zs-040 … zs-051, zs-332 leak check).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-writer.php';
require_once __DIR__ . '/class-media.php';
require_once __DIR__ . '/class-store.php';
require_once __DIR__ . '/class-lastmod.php';
require_once __DIR__ . '/class-refresh.php';
require_once __DIR__ . '/class-builder.php';
require_once __DIR__ . '/class-sitemaps.php';

return array(
	'id'          => 'sitemaps',
	'name'        => static fn() => __( 'XML sitemaps', 'zinn-seo' ),
	'description' => static fn() => __( 'Sitemaps written ahead of time and sent as files, rebuilt only where pages changed, with images, honest dates, every language and a page of links for people. Fast at a million pages.', 'zinn-seo' ),
	'tier'        => 'free',
	// Shared (zs-715): other features build on its helpers (structured data, sitemaps,
	// redirects, the block library), so its code stays available to them while it is switched off.
	// Switched off, it adds no hook and does none of its own work.
	'shared'      => true,
	'area'        => 'sitemaps',
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-040', 'zs-041', 'zs-042', 'zs-043', 'zs-046', 'zs-047', 'zs-048', 'zs-049', 'zs-050', 'zs-051', 'zs-762' ),
	'settings'    => \ZinnDigital\ZinnSEO\Sitemaps\Sitemaps::settings(),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Sitemaps\Sitemaps::boot( $context );
		if ( in_array( $context, array( 'front', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-html.php';
			\ZinnDigital\ZinnSEO\Sitemaps\Html::register();
		}
		if ( 'front' === $context ) {
			return;
		}
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-sitemaps-rest.php';
			\ZinnDigital\ZinnSEO\Sitemaps\Sitemaps_Rest::init();
		}
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data             = (array) $data;
					$data['sitemaps'] = true;
					return $data;
				}
			);
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-sitemaps-cli.php';
			\WP_CLI::add_command( 'zinn-seo sitemaps', \ZinnDigital\ZinnSEO\Sitemaps\Sitemaps_Cli::class );
		}
	},
);
