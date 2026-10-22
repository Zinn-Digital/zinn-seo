<?php
/**
 * The RSS feed module's settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'feed_before' => array(
		'type'    => 'template',
		'default' => '',
		'max'     => 500,
		'group'   => 'feeds',
	),
	'feed_after'  => array(
		'type'    => 'template',
		'default' => '',
		'max'     => 500,
		'group'   => 'feeds',
	),
	'feed_image'  => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'feeds',
	),
	'feed_delay'  => array(
		'type'    => 'int',
		'default' => 0,
		'min'     => 0,
		'max'     => 10080,
		'group'   => 'feeds',
	),
);
