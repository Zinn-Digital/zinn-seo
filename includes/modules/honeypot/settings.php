<?php
/**
 * The comment honeypot's settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'honeypot_min_seconds' => array(
		'type'    => 'int',
		'default' => 3,
		'min'     => 0,
		'max'     => 120,
		'group'   => 'honeypot',
	),
	'honeypot_action'      => array(
		'type'    => 'enum',
		'default' => 'spam',
		'choices' => array( 'spam', 'reject' ),
		'group'   => 'honeypot',
	),
);
