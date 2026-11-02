<?php
/**
 * Module: cookie consent banner with Google Consent Mode v2 — off until switched on (zs-783).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'consent',
	'name'        => static fn() => __( 'Cookie consent', 'zinn-seo' ),
	'description' => static fn() => __( 'Ask visitors before analytics and advertising cookies are used, with a separate choice for each, and pass the answer to Google tags through Consent Mode. Off by default: while it is off, nothing is added to your pages.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'cleanup',
	'default'     => false,
	'features'    => array( 'zs-783' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-consent.php' ),
	'contexts'    => array( 'front' ),
	// The banner is on every page: switching it, or a change to its settings, empties page caches.
	'page_cache'  => true,
	'boot'        => static function (): void {
		\ZinnDigital\ZinnSEO\Consent\Consent::boot_front();
	},
);
