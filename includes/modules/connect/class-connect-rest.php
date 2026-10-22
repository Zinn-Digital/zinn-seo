<?php
/**
 * REST routes of the Zinn® account connection.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Connect;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /connect` — is this site connected, to which account, and what connecting sends.
 * - `POST /connect` `{consent: true}` — ask for a code to approve on zinndigital.com.
 * - `POST /connect/signup` `{consent: true, email, name}` — make an account and start connecting.
 * - `POST /connect/poll` — has the owner approved yet? (waiting|connected|denied|expired)
 * - `DELETE /connect` — disconnect; `DELETE /connect?pending=1` — stop waiting.
 *
 * Connecting decides who pays, so every write needs the capability to spend the owner's credit
 * (administrators by default); anyone who may see SEO reports can read the state.
 */
final class Connect_Rest {

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
		$done    = true;
		$view    = static fn(): bool => current_user_can( Capabilities::SPEND ) || current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$spend   = static fn(): bool => current_user_can( Capabilities::SPEND );
		$consent = array(
			'type'    => 'boolean',
			'default' => false,
		);
		register_rest_route(
			Plugin::REST_NS,
			'/connect',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( Connection::describe() ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Connection::start( (bool) $r['consent'] ) ),
					'permission_callback' => $spend,
					'args'                => array( 'consent' => $consent ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( $r['pending'] ? Connection::cancel() : Connection::disconnect() ),
					'permission_callback' => $spend,
					'args'                => array(
						'pending' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/connect/signup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Connection::signup( (bool) $r['consent'], (string) $r['email'], (string) $r['name'] ) ),
				'permission_callback' => $spend,
				'args'                => array(
					'consent' => $consent,
					'email'   => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 320,
					),
					'name'    => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 200,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/connect/poll',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn() => rest_ensure_response( Connection::poll() ),
				'permission_callback' => $spend,
			)
		);
	}
}
