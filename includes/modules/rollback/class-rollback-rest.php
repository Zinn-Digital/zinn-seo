<?php
/**
 * REST for version rollback (zs-718).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Rollback;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /rollback` the versions this site can go back to (and why not, for the others);
 * `POST /rollback` go back to `version` (checked, backed up, health-checked, restored on failure).
 */
final class Rollback_Rest {

	/**
	 * Register on rest_api_init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					Plugin::REST_NS,
					'/rollback',
					array(
						array(
							'methods'             => \WP_REST_Server::READABLE,
							'callback'            => static function ( \WP_REST_Request $request ) {
								if ( $request->get_param( 'fresh' ) ) {
									delete_site_transient( 'zseo_rollback_releases' );
								}
								return rest_ensure_response( Rollback::describe() );
							},
							'permission_callback' => array( Capabilities::class, 'can_manage' ),
						),
						array(
							'methods'             => \WP_REST_Server::CREATABLE,
							'callback'            => static function ( \WP_REST_Request $request ) {
								$done = Rollback::run( (string) $request->get_param( 'version' ) );
								return is_wp_error( $done ) ? $done : rest_ensure_response( $done );
							},
							'permission_callback' => static fn(): bool => Capabilities::can_manage() && Rollback::allowed(),
							'args'                => array(
								'version' => array(
									'type'     => 'string',
									'required' => true,
									'pattern'  => '^[0-9]+(\.[0-9]+){1,3}$',
								),
							),
						),
					)
				);
			}
		);
	}
}
