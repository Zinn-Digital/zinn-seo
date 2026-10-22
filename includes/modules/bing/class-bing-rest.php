<?php
/**
 * REST routes of the Bing module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bing;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /search/bing?days=&fresh=` — connected or not, totals, day by day, searches, pages, sitemaps.
 * - `POST /search/bing` `{key}` — connect with the owner's API key; `DELETE /search/bing` — forget it.
 * - `POST /search/bing/submit` — send the sitemap now.
 */
final class Bing_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
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
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE );
		register_rest_route(
			Plugin::REST_NS,
			'/search/bing',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Bing::view( in_array( (int) $r['days'], array( 7, 28, 90 ), true ) ? (int) $r['days'] : 28, (bool) $r['fresh'] ) ),
					'permission_callback' => $view,
					'args'                => array(
						'days'  => array(
							'type'    => 'integer',
							'default' => 28,
						),
						'fresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$res = Bing::connect( (string) $r['key'] );
						return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'key' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 100,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn() => rest_ensure_response( Bing::disconnect() ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/bing/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn() => rest_ensure_response( Bing::submit( 'manual' ) ),
				'permission_callback' => $manage,
			)
		);
	}
}
