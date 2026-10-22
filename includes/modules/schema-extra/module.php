<?php
/**
 * Module: more of the one graph — carousels on archive pages (zs-736) and image licence details
 * for Google Images (zs-739).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'schema-extra',
	'name'        => static fn() => __( 'Carousels and image licences', 'zinn-seo' ),
	'description' => static fn() => __( 'Category and archive pages of recipes, courses, films, restaurants or products can show as a swipeable carousel in Google, and your images get the "Licensable" badge in Google Images with who made them, the credit and where to license them.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'schema',
	'requires'    => array( 'schema' ),
	'features'    => array( 'zs-736', 'zs-739' ),
	'settings'    => require __DIR__ . '/settings.php',
	'meta'        => require __DIR__ . '/meta.php',
	'files'       => array( 'class-carousel.php', 'class-image-licence.php' ),
	'contexts'    => array( 'front', 'admin', 'rest' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context ) {
			add_filter( 'zinn_seo_graph', array( \ZinnDigital\ZinnSEO\SchemaExtra\Carousel::class, 'graph' ), 40, 2 );
			add_filter( 'zinn_seo_graph', array( \ZinnDigital\ZinnSEO\SchemaExtra\Image_Licence::class, 'graph' ), 45, 2 );
			return;
		}
		// The graph a REST request computes (the validator, the editor's preview) includes them.
		if ( 'rest' === $context ) {
			add_filter( 'zinn_seo_graph', array( \ZinnDigital\ZinnSEO\SchemaExtra\Image_Licence::class, 'graph' ), 45, 2 );
		}
		// The licence fields on an image: the media library's details and the edit screen.
		add_filter( 'attachment_fields_to_edit', array( \ZinnDigital\ZinnSEO\SchemaExtra\Image_Licence::class, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( \ZinnDigital\ZinnSEO\SchemaExtra\Image_Licence::class, 'save' ), 10, 2 );
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_admin_data', array( \ZinnDigital\ZinnSEO\SchemaExtra\Carousel::class, 'admin_data' ) );
		}
	},
);
