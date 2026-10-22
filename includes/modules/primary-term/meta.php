<?php
/**
 * The primary term picker's per-post field (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'primary_term' => array(
		'type'        => 'int',
		'objects'     => array( 'post' ),
		'min'         => 0,
		'description' => static fn() => __( 'The main category: the address, the breadcrumb trail and the article section use it.', 'zinn-seo' ),
	),
);
