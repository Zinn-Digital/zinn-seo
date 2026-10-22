<?php
/**
 * Module: notices from Zinn® inside WordPress and from the site's own e-mail (zs-180: low credit,
 * paused and resumed checks; the alerts of the paid tools).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'notices',
	'name'        => static fn() => __( 'Notices and alerts', 'zinn-seo' ),
	'description' => static fn() => __( 'Hear about it before it costs you: credit running low, a check that paused and when it resumed, a ranking that fell or a link you lost — shown here and e-mailed from your own site.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-180' ),
	// Written here, not read from the class: this file is included on every request to find the
	// modules, and a visitor's page must not load the class for it.
	'settings'    => array(
		// Which notices this site e-mails: the important ones (a paused check, a problem a
		// visitor or Google can see), all of them, or none.
		'notices_email'    => array(
			'type'    => 'enum',
			'default' => 'important',
			'choices' => array( 'important', 'all', 'off' ),
			'group'   => 'notices',
		),
		// Who gets them ('' is the site's admin address).
		'notices_email_to' => array(
			'type'    => 'string',
			'default' => '',
			'max'     => 320,
			'group'   => 'notices',
		),
	),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-notices.php';
		$n = \ZinnDigital\ZinnSEO\Notices\Notices::class;
		add_action( $n::CRON, array( $n, 'sync' ) );
		add_action( 'zinn_seo_connected', array( $n, 'schedule' ) );
		add_action( 'zinn_seo_connected', array( $n, 'sync' ), 20 );
		add_action( 'zinn_seo_disconnected', array( $n, 'forget' ) );
		add_action( 'deactivate_' . plugin_basename( ZINN_SEO_FILE ), static fn() => wp_clear_scheduled_hook( $n::CRON ) );
		if ( 'admin' === $context ) {
			// After the menu exists (the admin screen adds it at the default priority).
			add_action( 'admin_menu', array( $n, 'bubble' ), 99 );
			add_action( 'admin_notices', array( $n, 'notice' ) );
			add_action( 'admin_post_' . $n::DISMISS, array( $n, 'handle_dismiss' ) );
			// A site connected before this event existed (or whose cron was cleared) gets it back.
			add_action( 'admin_init', array( $n, 'schedule' ) );
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			$routes = static function () use ( $n ): void {
				static $done = false;
				if ( $done ) {
					return;
				}
				$done = true;
				$may  = static fn(): bool => current_user_can( \ZinnDigital\ZinnSEO\Capabilities::SPEND ) || current_user_can( \ZinnDigital\ZinnSEO\Capabilities::MANAGE );
				register_rest_route(
					\ZinnDigital\ZinnSEO\Plugin::REST_NS,
					'/notices',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( $n::feed( (bool) $r['fresh'] ) ),
						'permission_callback' => $may,
						'args'                => array(
							'fresh' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
					)
				);
				register_rest_route(
					\ZinnDigital\ZinnSEO\Plugin::REST_NS,
					'/notices/dismiss',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( $n::dismiss( (array) $r['ids'], (bool) $r['all'] ) ),
						'permission_callback' => $may,
						'args'                => array(
							'ids' => array(
								'type'     => 'array',
								'items'    => array( 'type' => 'string' ),
								'default'  => array(),
								'maxItems' => 100,
							),
							'all' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
					)
				);
				register_rest_route(
					\ZinnDigital\ZinnSEO\Plugin::REST_NS,
					'/notices/sync',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => static fn() => rest_ensure_response( $n::sync() ),
						'permission_callback' => $may,
					)
				);
			};
			\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( $routes );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo notices',
				static function ( $args ) use ( $n ): void {
					$out = 'sync' === ( $args[0] ?? 'list' ) ? $n::sync() : $n::feed( true );
					if ( is_wp_error( $out ) ) {
						\WP_CLI::error( $out->get_error_message() );
					}
					\WP_CLI::line( (string) wp_json_encode( $out ) );
				},
				array( 'shortdesc' => 'Notices from the connected Zinn® account: list | sync (read them now and send the site\'s own e-mails that are due).' )
			);
		}
	},
);
