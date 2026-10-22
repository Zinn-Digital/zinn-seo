<?php
/**
 * Module: Dublin Core metadata — off until switched on (zs-726).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'dublin-core',
	'name'        => static fn() => __( 'Dublin Core', 'zinn-seo' ),
	'description' => static fn() => __( 'Describe each page in Dublin Core terms for the libraries, archives and catalogues that read them. Off by default: search engines do not use it, so it only adds to the page when you need it.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'appearance',
	'default'     => false,
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-726' ),
	'files'       => array( 'class-dublin-core.php' ),
	'contexts'    => array( 'front' ),
	'boot'        => static function (): void {
		\ZinnDigital\ZinnSEO\DublinCore\Dublin_Core::boot_front();
	},
);
