<?php
/**
 * Module: image SEO rules — clean file names, alt text, titles and captions from templates on
 * upload and in every language, and library-wide fixes in the background (zs-710).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'images',
	'name'        => static fn() => __( 'Image SEO', 'zinn-seo' ),
	'description' => static fn() => __( 'Give every image a clean file name and alt text, title and caption automatically, in every language, and fix the whole media library in one go — so images bring visitors from image search too.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'features'    => array( 'zs-710' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-images.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Images\Images::boot( $context );
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-images-rest.php';
			\ZinnDigital\ZinnSEO\Images\Images_Rest::init();
		}
	},
);
