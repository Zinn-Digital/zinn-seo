<?php
/**
 * The internal link updates's settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'link_updater_auto' => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'links',
	),
);
