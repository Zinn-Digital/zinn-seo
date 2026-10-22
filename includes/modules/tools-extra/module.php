<?php
/**
 * Module: more research tools on Zinn® credit — the local rank grid, reviews from Google,
 * Trustpilot and Tripadvisor, competitor traffic, and "People also ask" questions turned into an
 * FAQ (zs-520, zs-521, zs-523, zs-529). Pro adds Google Shopping and Amazon shelves, YouTube
 * rankings, results-page snapshots, brand sentiment, technology lookup and content briefs.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'tools-extra',
	'name'        => static fn() => __( 'Research toolbox', 'zinn-seo' ),
	'description' => static fn() => __( 'See where your business shows on the map street by street, read and answer your reviews from one place, find out how much traffic a competitor gets and from which pages, and turn the questions people ask Google into an FAQ — paying only for what you run.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'requires'    => array( 'connect', 'credits' ),
	'features'    => array( 'zs-520', 'zs-521', 'zs-523', 'zs-529', 'zs-653', 'zs-709' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-extra.php';
		add_action( 'zinn_seo_disconnected', static fn() => delete_option( \ZinnDigital\ZinnSEO\Extra\Extra::OPTION ) );
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-extra-rest.php';
			\ZinnDigital\ZinnSEO\Extra\Extra_Rest::init();
		}
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data          = (array) $data;
					$data['extra'] = \ZinnDigital\ZinnSEO\Extra\Extra::boot_data();
					return $data;
				}
			);
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-extra-cli.php';
			\WP_CLI::add_command( 'zinn-seo toolbox', array( \ZinnDigital\ZinnSEO\Extra\Extra_Cli::class, 'run' ), array( 'shortdesc' => 'The research toolbox: products | quote <tool> <params.json> | run <tool> <params.json> | runs [<tool>] | run-get <id> | watches | watch <tool> <params.json> [--every=<days>] | unwatch <id>.' ) );
		}
	},
);
