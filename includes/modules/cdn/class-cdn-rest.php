<?php
/**
 * REST routes of the Zinn® CDN module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cdn;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /cdn` — the CDN screen: offered here, the zone, this month, the price.
 * - `POST /cdn` — switch it on (spends credit: `zseo_spend`).
 * - `DELETE /cdn` — switch it off (instant: the rewrite stops first).
 * - `POST /cdn/purge` — empty the CDN's cache.
 */
final class Cdn_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		if ( did_action( 'rest_api_init' ) ) {
			self::routes();
		}
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done   = true;
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE ) || current_user_can( Capabilities::SPEND );
		register_rest_route(
			Plugin::REST_NS,
			'/cdn',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( Cdn::status() ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn() => rest_ensure_response( Cdn::enable() ),
					// Switching it on spends the account's credit: the owner's call.
					'permission_callback' => static fn(): bool => current_user_can( Capabilities::SPEND ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn() => rest_ensure_response( Cdn::disable() ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/cdn/purge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn() => rest_ensure_response( Cdn::purge() ),
				'permission_callback' => $manage,
			)
		);
	}
}
