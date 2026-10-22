<?php
/**
 * Image SEO rules' settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zinn_seo_tpl = array(
	'type'    => 'template',
	'default' => '',
	'max'     => 200,
	'group'   => 'images',
);

return array(
	'img_clean_names' => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'images',
	),
	'img_alt'         => $zinn_seo_tpl,
	'img_title'       => $zinn_seo_tpl,
	'img_caption'     => $zinn_seo_tpl,
	'img_case'        => array(
		'type'    => 'enum',
		'default' => '',
		'choices' => array( '', 'title', 'first', 'lower' ),
		'group'   => 'images',
	),
	'img_strip_punct' => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'images',
	),
	'img_alt_output'  => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'images',
	),
	// Per language (Tranzly): language => the three templates in that language.
	'img_lang'        => array(
		'type'    => 'dict',
		'default' => array(),
		'group'   => 'images',
		'fields'  => array(
			'alt'     => $zinn_seo_tpl,
			'title'   => $zinn_seo_tpl,
			'caption' => $zinn_seo_tpl,
		),
	),
);
