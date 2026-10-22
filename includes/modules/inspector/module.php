<?php
/**
 * Module: the admin-bar SEO inspector on the live page, for editors only (zs-430).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'inspector',
	'name'        => static fn() => __( 'SEO inspector', 'zinn-seo' ),
	'description' => static fn() => __( 'An "SEO" item in the admin bar on your live pages: the title, description, canonical address, robots, structured data, language versions, headings and missing alt texts as search engines get them. Only for people who may see SEO data; visitors get nothing.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'features'    => array( 'zs-430' ),
	'contexts'    => array( 'front' ),
	'boot'        => static function (): void {
		// ⛔ A visitor's request returns here: no hook, no file read (zs-430 "zero bytes").
		if ( ! is_user_logged_in() ) {
			return;
		}
		require_once __DIR__ . '/class-inspector.php';
		\ZinnDigital\ZinnSEO\Inspector\Inspector::boot();
	},
);
