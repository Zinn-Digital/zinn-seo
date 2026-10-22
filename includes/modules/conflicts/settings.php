<?php
/**
 * The conflict detector's settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Fixes the owner switched on, each one undoable (Conflicts::fix_ids() less jetpack-sitemaps,
	// which switches Jetpack's own module and is not kept here; a test holds the two equal).
	'conflict_fixes' => array(
		'type'    => 'list',
		'default' => array(),
		'choices' => array( 'jetpack-og', 'theme-title', 'join-entities', 'microdata:botiga', 'microdata:astra', 'microdata:generatepress', 'microdata:kadence', 'microdata:oceanwp', 'jsonld:yoast', 'jsonld:rankmath', 'jsonld:aioseo' ),
		'group'   => 'conflicts',
	),
);
