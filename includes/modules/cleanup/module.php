<?php
/**
 * Module: clean-up — the head links and feeds nobody needs, unknown address parameters, spam
 * site-search pages, category bases, attachment pages, one host and slash style, comment-reply
 * copies, title capitalisation and link previews (zs-701, zs-714, zs-727).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'cleanup',
	'name'        => static fn() => __( 'Clean-up', 'zinn-seo' ),
	'description' => static fn() => __( 'Remove the links, feeds and copies of pages WordPress makes that waste search engines\' time, tidy your addresses, and make link previews in Slack, Discord and LinkedIn show your SEO title and image.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'cleanup',
	'features'    => array( 'zs-701', 'zs-714', 'zs-727' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-cleanup.php', 'class-bases.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Cleanup\Cleanup::boot_any();
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Cleanup\Cleanup::boot_front();
			return;
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-cleanup-rest.php';
			\ZinnDigital\ZinnSEO\Cleanup\Cleanup_Rest::init();
		}
	},
);
