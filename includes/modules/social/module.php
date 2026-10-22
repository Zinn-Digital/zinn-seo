<?php
/**
 * Module: social sharing — Open Graph, X cards, fediverse, Pinterest, LinkedIn — and Google
 * Discover readiness (zs-022, zs-026, zs-415).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'social',
	'name'        => static fn() => __( 'Social sharing', 'zinn-seo' ),
	'description' => static fn() => __( 'How a shared link looks on Facebook, LinkedIn, X, Mastodon, WhatsApp and Pinterest, and Google Discover readiness.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'social',
	'requires'    => array( 'head' ),
	'features'    => array( 'zs-022', 'zs-026', 'zs-415', 'zs-744' ),
	'settings'    => require __DIR__ . '/settings.php',
	'meta'        => require __DIR__ . '/meta.php',
	'files'       => array( 'class-social.php', 'class-discover.php', 'class-rescrape.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Social\Social::boot_front();
			return;
		}
		// zs-744: refresh shared links on Facebook when a page's card changes (opt-in, batched).
		\ZinnDigital\ZinnSEO\Social\Rescrape::hooks();
		if ( 'cron' === $context ) {
			return;
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			add_action( 'rest_api_init', array( \ZinnDigital\ZinnSEO\Social\Rescrape::class, 'routes' ) );
			if ( did_action( 'rest_api_init' ) ) {
				\ZinnDigital\ZinnSEO\Social\Rescrape::routes();
			}
		}
		\ZinnDigital\ZinnSEO\Social\Social::boot_admin();
		add_filter(
			'zinn_seo_setup_steps',
			static function ( $steps ) {
				$steps           = (array) $steps;
				$steps['social'] = array(
					'title'   => __( 'Sharing on social networks', 'zinn-seo' ),
					'section' => 'social',
				);
				return $steps;
			},
			11
		);
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data          = (array) $data;
					$data['social'] = true;
					return $data;
				}
			);
		}
	},
);
