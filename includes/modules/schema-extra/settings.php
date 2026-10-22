<?php
/**
 * Carousels and image licences: settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Per content type (`post:<type>`): what its entries are, for the carousel of the archive and
	// category pages that list them ('' = no carousel; `auto` = products and courses by their
	// type, recipes by the recipe in them).
	'carousel_types'    => array(
		'type'    => 'dict',
		'default' => array(),
		'group'   => 'schema',
		'fields'  => array(
			'item' => array(
				'type'    => 'enum',
				'default' => 'auto',
				'choices' => array( 'auto', 'off', 'Recipe', 'Course', 'Movie', 'Restaurant', 'Product' ),
			),
		),
	),
	// The library's licence defaults (an image's own fields win).
	'img_lic_creator'   => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 200,
		'group'   => 'schema',
	),
	'img_lic_credit'    => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 200,
		'group'   => 'schema',
	),
	'img_lic_copyright' => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 300,
		'group'   => 'schema',
	),
	'img_lic_license'   => array(
		'type'    => 'url',
		'default' => '',
		'group'   => 'schema',
	),
	'img_lic_acquire'   => array(
		'type'    => 'url',
		'default' => '',
		'group'   => 'schema',
	),
);
