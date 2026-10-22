<?php
/**
 * Module: the page head — title, description, robots, canonical, verification, one JSON-LD
 * graph — and the per-page fields in the editors (zs-020, zs-021, zs-027, zs-415).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-head.php';
require_once __DIR__ . '/class-graph.php';

return array(
	'id'          => 'head',
	'name'        => static fn() => __( 'Page head', 'zinn-seo' ),
	'description' => static fn() => __( 'The title, meta description, robots, canonical address and site verification tags of every page, and the page\'s one structured-data graph.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'appearance',
	'locked'      => true,
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-027', 'zs-756', 'zs-757' ),
	'settings'    => \ZinnDigital\ZinnSEO\Head\Head::settings(),
	'meta'        => array(
		// An author archive that moved: its 301 target (zs-756).
		'redirect' => array(
			'type'        => 'url',
			'objects'     => array( 'user' ),
			'description' => static fn() => __( 'Send visitors of this author\'s page to another address (301). Empty: no redirect.', 'zinn-seo' ),
		),
	),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Head\Head::boot_front();
			return;
		}
		require_once __DIR__ . '/class-editor.php';
		if ( 'admin' === $context ) {
			\ZinnDigital\ZinnSEO\Head\Editor::boot_admin();
		}
		require_once __DIR__ . '/class-head-rest.php';
		\ZinnDigital\ZinnSEO\Head\Head_Rest::init();
	},
);
