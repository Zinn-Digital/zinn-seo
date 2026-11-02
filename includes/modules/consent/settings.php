<?php
/**
 * The cookie consent banner's settings (data only — zs-715, zs-783).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'consent_ads'    => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'consent',
	),
	'consent_reopen' => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'consent',
	),
);
