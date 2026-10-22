<?php
/**
 * Zinn® Cache, our page cache (zs-136).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * A change made here must reach visitors at once. This plugin announces every change
 * (`zinn_seo_field_saved`, `zinn_seo_settings_saved`, `zinn_seo_redirect_saved`,
 * `zinn_seo_site_texts_changed`, the importers, the no-trace switch); Zinn® Cache listens and
 * purges exactly the pages concerned, and its warm-up fills the cache again from this plugin's
 * sitemap, which it finds in robots.txt.
 *
 * The switch on the Integrations screen is read by Zinn® Cache itself (through
 * `zinn_seo()->setting( 'integrations_off' )`): switched off, it leaves this plugin's changes
 * alone. So there is nothing to start here: this file is what the Integrations screen lists.
 */
return static function ( string $context ): void {
	unset( $context );
};
