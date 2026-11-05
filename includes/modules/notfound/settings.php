<?php
/**
 * The notfound module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'notfound_bots'   => array(
		'type'    => 'enum',
		'default' => 'apart',
		'choices' => array( 'apart', 'skip' ),
		'group'   => 'notfound',
	),
	'notfound_ignore' => array(
		'type'    => 'text',
		'default' => "*.php\n/.*\n*.asp*\n/cgi-bin/*",
		'max'     => 3000,
		'group'   => 'notfound',
	),
	'notfound_email'  => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'notfound',
	),
);
