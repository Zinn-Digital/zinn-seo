<?php
/**
 * Module: main category — one primary category per post (and shop category per product),
 * driving its address, breadcrumbs and structured data (zs-745).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'primary-term',
	'name'        => static fn() => __( 'Main category', 'zinn-seo' ),
	'description' => static fn() => __( 'Choose the main category of a post in several categories, and of a product in several shop categories: its address, breadcrumb trail and structured data all follow it.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'appearance',
	'features'    => array( 'zs-745' ),
	'meta'        => require __DIR__ . '/meta.php',
	'files'       => array( 'class-primary-term.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\PrimaryTerm\Primary_Term::boot();
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_editor_data', array( \ZinnDigital\ZinnSEO\PrimaryTerm\Primary_Term::class, 'editor_data' ) );
			add_filter( 'zinn_seo_form_fields', array( \ZinnDigital\ZinnSEO\PrimaryTerm\Primary_Term::class, 'form_field' ) );
		}
	},
);
