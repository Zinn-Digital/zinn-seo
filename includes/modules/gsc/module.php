<?php
/**
 * Module: Google Search Console in WordPress — clicks, impressions and position per page and
 * search, Google's own index verdict per page, sitemaps sent on connect, and the Indexing API for
 * job and livestream pages (zs-090, zs-092, zs-083, zs-081).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'gsc',
	'name'        => static fn() => __( 'Google Search Console', 'zinn-seo' ),
	'description' => static fn() => __( 'See the searches that bring people to every page, which pages Google has indexed and why the others are not, and send your sitemap to Google and Bing in one click — inside WordPress, free.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'search',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-090', 'zs-092', 'zs-083', 'zs-081' ),
	'settings'    => array(
		'gsc_indexing'       => array(
			'type'    => 'bool',
			'default' => false,
			'group'   => 'search',
		),
		'gsc_indexing_types' => array(
			'type'    => 'list',
			'default' => array(),
			'group'   => 'search',
		),
	),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-google.php';
		require_once __DIR__ . '/class-data.php';
		require_once __DIR__ . '/class-sitemap-submit.php';
		require_once __DIR__ . '/class-inspect.php';
		require_once __DIR__ . '/class-indexing.php';
		\ZinnDigital\ZinnSEO\Jobs::register( \ZinnDigital\ZinnSEO\Gsc\Inspect::JOB, static fn() => __( 'Asking Google which pages it has indexed', 'zinn-seo' ), array( \ZinnDigital\ZinnSEO\Gsc\Inspect::class, 'step' ) );
		\ZinnDigital\ZinnSEO\Jobs::register( \ZinnDigital\ZinnSEO\Gsc\Indexing::JOB, static fn() => __( 'Telling Google about job and livestream pages', 'zinn-seo' ), array( \ZinnDigital\ZinnSEO\Gsc\Indexing::class, 'step' ) );
		add_action( 'transition_post_status', array( \ZinnDigital\ZinnSEO\Gsc\Indexing::class, 'on_status' ), 10, 3 );
		add_action( 'zinn_seo_notrace_changed', array( \ZinnDigital\ZinnSEO\Gsc\Sitemap_Submit::class, 'on_notrace' ), 10, 2 );
		add_action( 'zinn_seo_disconnected', array( \ZinnDigital\ZinnSEO\Gsc\Data::class, 'forget' ) );
		add_action(
			'zinn_seo_disconnected',
			static function (): void {
				delete_option( \ZinnDigital\ZinnSEO\Gsc\Google::CACHE );
			}
		);
		if ( 'admin' === $context ) {
			add_action(
				'admin_init',
				static function (): void {
					if ( isset( $_GET['page'] ) && 'zinn-seo' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen this is.
						\ZinnDigital\ZinnSEO\Gsc\Google::returned();
						\ZinnDigital\ZinnSEO\Gsc\Data::prune();
					}
				}
			);
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-gsc-rest.php';
			\ZinnDigital\ZinnSEO\Gsc\Gsc_Rest::init();
		}
	},
);
