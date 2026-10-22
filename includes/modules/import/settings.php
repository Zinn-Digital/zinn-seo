<?php
/**
 * The import module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'import_live' => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 20,
		'pattern' => '/^[a-z]*$/',
		'group'   => 'import',
		'export'  => false,
	),
);
