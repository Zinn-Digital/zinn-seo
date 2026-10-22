<?php
/**
 * REST for the smart robots defaults (zs-713).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SmartRobots;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /robots-defaults` — each rule, whether it is on and how many pages it covers. Switching is
 * an ordinary settings change (`PUT /settings`).
 */
final class Smart_Robots_Rest {

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
					'/robots-defaults',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( self::class, 'get' ),
						'permission_callback' => array( Capabilities::class, 'can_manage' ),
					)
				);
			}
		);
	}

	/**
	 * Rules with their state and counts.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		$counts = Smart_Robots::counts();
		$rules  = array();
		foreach ( Smart_Robots::RULES as $id => $key ) {
			$rules[] = array(
				'id'      => $id,
				'setting' => $key,
				'on'      => Smart_Robots::on( $id ),
				'count'   => $counts[ $id ] ?? -1,
			);
		}

		return rest_ensure_response( array( 'rules' => $rules ) );
	}
}
