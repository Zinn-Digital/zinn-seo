<?php
/**
 * Module: comment-spam honeypot — no script, no captcha (zs-728).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'honeypot',
	'name'        => static fn() => __( 'Comment spam trap', 'zinn-seo' ),
	'description' => static fn() => __( 'Stop spam bots from filling your pages with junk comments and links — with no captcha for visitors to solve and nothing added to load in their browser.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'cleanup',
	'features'    => array( 'zs-728' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-honeypot.php' ),
	'contexts'    => array( 'front', 'admin', 'rest' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context || wp_doing_ajax() ) {
			\ZinnDigital\ZinnSEO\Honeypot\Honeypot::boot();
		}
		if ( 'front' === $context ) {
			return;
		}
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					\ZinnDigital\ZinnSEO\Plugin::REST_NS,
					'/honeypot',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static fn() => rest_ensure_response(
							array(
								'caught'   => \ZinnDigital\ZinnSEO\Honeypot\Honeypot::caught(),
								'comments' => 'open' === (string) get_option( 'default_comment_status' ) ? 'open' : 'closed',
							)
						),
						'permission_callback' => array( \ZinnDigital\ZinnSEO\Capabilities::class, 'can_manage' ),
					)
				);
			}
		);
	},
);
