<?php
/**
 * The llms module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'llms_full'       => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'ai',
	),
	'llms_summary'    => array(
		'type'    => 'text',
		'default' => '',
		'max'     => 1000,
		'group'   => 'ai',
	),
	'llms_details'    => array(
		'type'    => 'text',
		'default' => '',
		'max'     => 50000,
		// Up to 50,000 characters: kept apart from the autoloaded settings (a Rank Math site's
		// llms.txt text was 33,188 characters and could not be stored at all, zinnhub.com).
		'apart'   => true,
		'group'   => 'ai',
	),
	'llms_pages'      => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 1000,
		'pattern' => '/^[\d,\s]*$/',
		'group'   => 'ai',
	),
	'llms_types'      => array(
		'type'    => 'list',
		'default' => array(),
		'group'   => 'ai',
	),
	'llms_taxonomies' => array(
		'type'    => 'list',
		'default' => array(),
		'group'   => 'ai',
	),
	'llms_per_type'   => array(
		'type'    => 'int',
		'default' => 25,
		'min'     => 1,
		'max'     => 5000,
		'group'   => 'ai',
	),
);
