<?php
/**
 * REST routes of the Fix-it inbox.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Fixit;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /fixit` (`fresh=1` to work it out again), `POST /fixit/run {action}`,
 * `POST /fixit/dismiss {id, days}` (days 0 = for good, -1 = bring back), `POST /fixit/restore`.
 */
final class Fixit_Rest {

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
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::EDIT );
		$manage = static fn(): bool => Capabilities::can_manage();
		register_rest_route(
			Plugin::REST_NS,
			'/fixit',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Fixit::issues( (bool) $r['fresh'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/fixit/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$done = Fixit::run( (string) $r['action'] );
					return is_wp_error( $done ) ? $done : rest_ensure_response( Fixit::issues( true ) );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9-]{1,40}$',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/fixit/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$days = (int) $r['days'];
					Fixit::dismiss( (string) $r['id'], $days < 0 ? null : $days );
					return rest_ensure_response( Fixit::issues() );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'id'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_-]{1,80}$',
					),
					'days' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => -1,
						'maximum' => 365,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/fixit/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					Fixit::restore_all();
					return rest_ensure_response( Fixit::issues() );
				},
				'permission_callback' => $manage,
			)
		);
	}
}
