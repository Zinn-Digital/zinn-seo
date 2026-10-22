<?php
/**
 * The robots.txt module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'robots_defaults' => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'robots',
	),
	'staging_mode'    => array(
		'type'    => 'enum',
		'default' => 'auto',
		'choices' => array( 'auto', 'on', 'off' ),
		'group'   => 'indexing',
	),
	'staging_hosts'   => array(
		'type'    => 'text',
		'default' => '',
		'max'     => 1000,
		'group'   => 'indexing',
	),
);
