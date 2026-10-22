<?php
/**
 * The crawlers module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Crawler id => allowed. Absent = allowed.
	'ai_crawlers'     => array(
		'type'    => 'map',
		'default' => array(),
		'group'   => 'ai',
	),
	'ai_signals'      => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'ai',
	),
	'ai_sig_search'   => array(
		'type'    => 'enum',
		'default' => 'yes',
		'choices' => array( 'yes', 'no' ),
		'group'   => 'ai',
	),
	'ai_sig_input'    => array(
		'type'    => 'enum',
		'default' => 'yes',
		'choices' => array( 'yes', 'no' ),
		'group'   => 'ai',
	),
	'ai_sig_train'    => array(
		'type'    => 'enum',
		'default' => 'no',
		'choices' => array( 'yes', 'no' ),
		'group'   => 'ai',
	),
	'tdm_reservation' => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'ai',
	),
	'tdm_policy'      => array(
		'type'    => 'url',
		'default' => '',
		'group'   => 'ai',
	),
);
