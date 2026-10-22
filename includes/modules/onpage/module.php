<?php
/**
 * Module: the full-site audit Zinn® runs on credit, merged into Fix it — for sites too big or too
 * locked-down to crawl from inside WordPress (zs-652).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'onpage',
	'name'        => static fn() => __( 'Full-site audit in Fix it', 'zinn-seo' ),
	'description' => static fn() => __( 'Every problem the full-site audit finds — even on a site too big to check from inside WordPress — appears in Fix it beside everything else, with the pages it touches.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'tools',
	'requires'    => array( 'tools', 'fixit' ),
	'features'    => array( 'zs-652' ),
	'files'       => array( 'class-onpage.php' ),
	'contexts'    => array( 'admin', 'rest', 'cron', 'cli' ),
	'boot'        => static function ( string $context ): void {
		unset( $context );
		\ZinnDigital\ZinnSEO\Onpage\Onpage::hooks();
	},
);
