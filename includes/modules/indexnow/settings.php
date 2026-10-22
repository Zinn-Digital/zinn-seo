<?php
/**
 * The IndexNow module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'indexnow_key'    => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 64,
		'pattern' => '/^([a-zA-Z0-9-]{8,128})?$/',
		'group'   => 'indexing',
		'export'  => false,
	),
	'indexnow_engine' => array(
		'type'    => 'enum',
		'default' => 'indexnow',
		// The keys of Indexnow::ENGINES (a test keeps the two lists equal).
		'choices' => array( 'indexnow', 'bing', 'yandex', 'seznam', 'naver' ),
		'group'   => 'indexing',
	),
	'indexnow_types'  => array(
		'type'    => 'list',
		'default' => array(),
		'group'   => 'indexing',
	),
	'gsc_property'    => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 300,
		'group'   => 'indexing',
	),
);
