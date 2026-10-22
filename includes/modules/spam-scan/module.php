<?php
/**
 * Module: hacked-site SEO spam scan — the Japanese keyword hack, hidden and spam links, injected
 * scripts, stray sitemaps, pages cloaked for Googlebot, visitors from Google sent away (zs-400,
 * the scan; Pro runs it every week and e-mails what is new).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'spam-scan',
	'name'        => static fn() => __( 'Hacked-site spam scan', 'zinn-seo' ),
	'description' => static fn() => __( 'Look for what SEO spam hacks leave behind: pages in another script, hidden and spam links, scripts from unknown sites, stray sitemaps, and pages that show Google something different from visitors.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'technical',
	'features'    => array( 'zs-400' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-scan.php';
		require_once __DIR__ . '/class-scan-rest.php';
		\ZinnDigital\ZinnSEO\SpamScan\Scan::boot();
		// The Pro weekly watch's event stays only while that module runs (licence ended, switched off).
		if ( ! \ZinnDigital\ZinnSEO\Modules::enabled( 'spam-watch' ) && wp_next_scheduled( 'zseo_spam_watch' ) ) {
			wp_clear_scheduled_hook( 'zseo_spam_watch' );
		}
		add_filter( 'zinn_seo_fixit_sources', array( \ZinnDigital\ZinnSEO\SpamScan\Scan_Rest::class, 'fixit_sources' ) );
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			\ZinnDigital\ZinnSEO\SpamScan\Scan_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo spam',
				static function (): void {
					\ZinnDigital\ZinnSEO\SpamScan\Scan::start();
					\ZinnDigital\ZinnSEO\Jobs::run( \ZinnDigital\ZinnSEO\SpamScan\Scan::JOB );
					$r = \ZinnDigital\ZinnSEO\SpamScan\Scan::result();
					foreach ( (array) ( $r['findings'] ?? array() ) as $kind => $f ) {
						\WP_CLI::log( sprintf( '%s: %d', $kind, (int) $f['count'] ) );
						foreach ( array_slice( (array) $f['items'], 0, 10 ) as $item ) {
							\WP_CLI::log( '  ' . ( $item['title'] ?? '' ) . ( empty( $item['detail'] ) ? '' : ' — ' . $item['detail'] ) );
						}
					}
					\WP_CLI::success( sprintf( 'Scanned %d pages.', (int) ( $r['pages'] ?? 0 ) ) );
				},
				array( 'shortdesc' => 'Scan the site for SEO spam left by a hack.' )
			);
		}
	},
);
