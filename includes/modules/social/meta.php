<?php
/**
 * The social module's per-object fields (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its fields — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zinn_seo_both = array( 'post', 'term' );
// The sharing fields of an author's page too (zs-756).
$zinn_seo_all = array( 'post', 'term', 'user' );

return array(
	'og_title' => array(
		'type'        => 'template',
		'objects'     => $zinn_seo_all,
		'max'         => 300,
		'description' => static fn() => __( 'Title when the page is shared. Empty: its SEO title.', 'zinn-seo' ),
	),
	'og_desc'  => array(
		'type'        => 'template',
		'objects'     => $zinn_seo_all,
		'max'         => 1000,
		'description' => static fn() => __( 'Text when the page is shared. Empty: its meta description.', 'zinn-seo' ),
	),
	'og_image' => array(
		'type'        => 'int',
		'objects'     => $zinn_seo_all,
		'min'         => 0,
		'description' => static fn() => __( 'Image when the page is shared (a media library ID). Empty: picked automatically.', 'zinn-seo' ),
	),
	'x_title'  => array(
		'type'        => 'template',
		'objects'     => $zinn_seo_all,
		'max'         => 300,
		'description' => static fn() => __( 'Title on X only. Empty: the shared title.', 'zinn-seo' ),
	),
	'x_desc'   => array(
		'type'        => 'template',
		'objects'     => $zinn_seo_all,
		'max'         => 1000,
		'description' => static fn() => __( 'Text on X only. Empty: the shared text.', 'zinn-seo' ),
	),
	'x_image'  => array(
		'type'        => 'int',
		'objects'     => $zinn_seo_all,
		'min'         => 0,
		'description' => static fn() => __( 'Image on X only. Empty: the shared image.', 'zinn-seo' ),
	),
	// A TikTok username of an author (Users → Profile), a profile in their Person node (zs-760).
	'tiktok'   => array(
		'type'        => 'string',
		'objects'     => array( 'user' ),
		'max'         => 60,
		'description' => static fn() => __( 'TikTok username.', 'zinn-seo' ),
	),
	// The X card's size on this page only (The SEO Framework kept one per post; IMP2). Empty:
	// the site's card size.
	'x_card'   => array(
		'type'        => 'string',
		'objects'     => $zinn_seo_both,
		'max'         => 19,
		'pattern'     => '/^(summary|summary_large_image)?$/',
		'description' => static fn() => __( 'Card size on X for this page: summary or summary_large_image. Empty: the site\'s card size.', 'zinn-seo' ),
	),
	'nopin'    => array(
		'type'        => 'bool',
		'objects'     => $zinn_seo_both,
		'description' => static fn() => __( 'Ask Pinterest not to let visitors pin this page.', 'zinn-seo' ),
	),
);
