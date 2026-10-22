<?php
/**
 * Image licence fields of one image (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zinn_seo_img = static fn( string $type, callable $d, array $more = array() ): array => array(
	'type'        => $type,
	'objects'     => array( 'post' ),
	'description' => $d,
) + $more;

return array(
	'lic_creator'   => $zinn_seo_img( 'string', static fn() => __( 'Who made the image (a person or an organisation).', 'zinn-seo' ), array( 'max' => 200 ) ),
	'lic_credit'    => $zinn_seo_img( 'string', static fn() => __( 'The credit line to show with the image.', 'zinn-seo' ), array( 'max' => 200 ) ),
	'lic_copyright' => $zinn_seo_img( 'string', static fn() => __( 'The copyright notice.', 'zinn-seo' ), array( 'max' => 300 ) ),
	'lic_license'   => $zinn_seo_img( 'url', static fn() => __( 'The address of the licence the image is offered under.', 'zinn-seo' ) ),
	'lic_acquire'   => $zinn_seo_img( 'url', static fn() => __( 'The address where someone can license the image.', 'zinn-seo' ) ),
);
