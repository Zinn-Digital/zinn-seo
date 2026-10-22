<?php
/**
 * Page Builder Sandwich, our page builder (zs-135, zs-230).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The two plugins meet in three places, and each is wired where its code already runs:
 *
 * - The SEO blocks in Page Builder Sandwich's block list, and its FAQ, how-to, pricing and
 *   review data inside this plugin's ONE structured-data graph: the adapter of the block
 *   library (`includes/adapters/pbs/`), which loads with the blocks because the block list is
 *   built there. It asks {@see ZinnDigital\ZinnSEO\Integrations\Family\Family::off()} first, so
 *   the switch on the Integrations screen is the one switch for all of it.
 * - The content checks read the page as Page Builder Sandwich renders it (the analysis module
 *   renders every builder's page the same way).
 * - Page Builder Sandwich's own side: its AI writes the SEO title and description into this
 *   plugin's fields, and its editor leaves the SEO panel to this plugin's.
 *
 * So there is nothing more to start here: this file is what the Integrations screen lists.
 */
return static function ( string $context ): void {
	unset( $context );
};
