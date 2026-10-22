<?php
/**
 * Module: the live content optimiser — as you write, a score for how well the post covers what the
 * top 10 results for its focus phrase cover, with what to add and what is overused (zs-766).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'optimiser',
	'name'        => static fn() => __( 'Content optimiser', 'zinn-seo' ),
	'description' => static fn() => __( 'As you write, see how well your post covers the words, names, questions and headings of the pages that rank for your phrase, with what to add and what you use too often. The top 10 are read once per phrase from your Zinn® credits and kept; the score itself is worked out in your editor, free.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'requires'    => array( 'analysis', 'connect', 'credits', 'tools', 'tools-extra' ),
	'features'    => array( 'zs-766' ),
	'files'       => array( 'class-optimiser.php' ),
	// Nothing on a visitor's page: the editor, the REST API and WP-CLI only.
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-optimiser-rest.php';
			\ZinnDigital\ZinnSEO\Optimiser\Optimiser_Rest::init();
		}
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_editor_data',
				static function ( $data ): array {
					$data              = (array) $data;
					$data['optimiser'] = \ZinnDigital\ZinnSEO\Optimiser\Optimiser_Rest::boot_data();
					return $data;
				}
			);
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-optimiser-cli.php';
			\WP_CLI::add_command( 'zinn-seo optimiser', \ZinnDigital\ZinnSEO\Optimiser\Optimiser_Cli::class, array( 'shortdesc' => 'The live content optimiser: score <post> | read <phrase> [--location=] [--language=] [--again] [--yes] | searches | forget <key>.' ) );
		}
	},
);
