<?php
/**
 * Module: the "Fix it" inbox — one prioritised list of what to fix across the site, each with a
 * one-click fix or a link to where it is fixed (zs-242, zs-inn-10).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'fixit',
	'name'        => static fn() => __( 'Fix it inbox', 'zinn-seo' ),
	'description' => static fn() => __( 'One list of everything worth fixing across the site, most important first, with a time estimate and a one-click fix or a link to exactly where to fix it.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'dashboard',
	'features'    => array( 'zs-242', 'zs-inn-10' ),
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-fixit.php';
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-fixit-rest.php';
			\ZinnDigital\ZinnSEO\Fixit\Fixit_Rest::init();
		}
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data          = (array) $data;
					$data['fixit'] = true;
					// The count beside "Fix it" in the side navigation, only when already known.
					$open = \ZinnDigital\ZinnSEO\Fixit\Fixit::cached_open();
					if ( null !== $open ) {
						$data['fixitOpen'] = $open;
					}
					return $data;
				}
			);
		}
	},
);
