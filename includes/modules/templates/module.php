<?php
/**
 * Module: title, description and robots templates for every kind of page (zs-020, zs-021).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-vars.php';
require_once __DIR__ . '/class-context.php';
require_once __DIR__ . '/class-templates.php';
require_once __DIR__ . '/class-resolver.php';
require_once __DIR__ . '/class-content-types.php';

return array(
	'id'          => 'templates',
	'name'        => static fn() => __( 'Titles and descriptions', 'zinn-seo' ),
	'description' => static fn() => __( 'Title, description and robots templates for every kind of page, with variables, and per-page overrides.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'appearance',
	'locked'      => true,
	'features'    => array( 'zs-020', 'zs-021', 'zs-768' ),
	'settings'    => \ZinnDigital\ZinnSEO\Templates\Templates::settings(),
	'meta'        => \ZinnDigital\ZinnSEO\Templates\Templates::meta(),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		if ( in_array( $context, array( 'admin', 'rest', 'cli' ), true ) ) {
			add_filter(
				'zinn_seo_setup_steps',
				static function ( $steps ) {
					$steps               = (array) $steps;
					$steps['appearance'] = array(
						'title'   => __( 'Titles in search results', 'zinn-seo' ),
						'section' => 'appearance',
					);
					return $steps;
				}
			);
		}
		// WP-CLI too: `rest_do_request()` from a command (and agents driving WP-CLI) reach the same routes.
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-templates-rest.php';
			\ZinnDigital\ZinnSEO\Templates\Templates_Rest::init();
			// Who registers each content type (Q277): noted from here on, before `init`.
			\ZinnDigital\ZinnSEO\Templates\Content_Types::capture();
			require_once __DIR__ . '/class-content-types-rest.php';
			\ZinnDigital\ZinnSEO\Templates\Content_Types_Rest::init();
		}
	},
);
