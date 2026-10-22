<?php
/**
 * Module: llms.txt and llms-full.txt for AI assistants, per language with Tranzly
 * (zs-061, zs-062, zs-inn-01).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'llms',
	'name'        => static fn() => __( 'llms.txt', 'zinn-seo' ),
	'description' => static fn() => __( 'Tell AI assistants what the site is and which pages matter, with a full-text version, in every language the site has.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'ai',
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-061', 'zs-062', 'zs-inn-01' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-markdown.php', 'class-llms-text.php', 'class-llms.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Llms\Llms::boot( $context );
		if ( in_array( $context, array( 'admin', 'rest', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-llms-rest.php';
			\ZinnDigital\ZinnSEO\Llms\Llms_Rest::init();
		}
	},
);
