<?php
/**
 * The admin shell's REST routes.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-rest.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnSEO\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `<host namespace>/kit/*`: preferences, the wizard, the trial, and the support screens.
 *
 * Every route needs `manage_options` and the REST nonce the shell sends (cookie authentication).
 * The support routes call the Zinn Digital API from the SERVER (contract §3); the browser never
 * calls it. ⛔ No route here signs anyone in or accepts a call from outside the site.
 */
final class Rest {

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$ns = Kit::host( 'rest_namespace' );
		if ( '' === $ns ) {
			return;
		}
		register_rest_route(
			$ns,
			'/kit/prefs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( Prefs::get() ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_prefs' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'theme'       => array(
							'type' => 'string',
							'enum' => Prefs::THEMES,
						),
						'dismiss'     => array( 'type' => 'string' ),
						'restore'     => array( 'type' => 'string' ),
						'restore_all' => array( 'type' => 'boolean' ),
						'tour'        => array( 'type' => 'string' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/kit/wizard',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response => new \WP_REST_Response( Kit::set_wizard_state( (string) $request['state'] ) ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'state' => array(
						'type'     => 'string',
						'enum'     => array( 'done', 'skipped', 'new' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/kit/trial',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( Licence::start_trial() ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/kit/licence',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( Licence::snapshot() ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/kit/support/consent',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'consent' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/kit/support/diagnostics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( Diagnostics::collect() ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/kit/support/connection',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response => new \WP_REST_Response( Connection::status( (bool) $request['refresh'] ) ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'connect' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'email'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'name'    => array( 'type' => 'string' ),
						'consent' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'disconnect' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/kit/support/ticket',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'ticket' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission: a site administrator (never a support user an earlier version created).
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && ! Legacy_Support_Users::is_legacy_user( get_current_user_id() );
	}

	/**
	 * Update the current user's preferences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function update_prefs( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( Prefs::update( (array) $request->get_json_params() ) );
	}

	/**
	 * Record consent.
	 *
	 * @return \WP_REST_Response
	 */
	public static function consent(): \WP_REST_Response {
		Support::give_consent();

		return new \WP_REST_Response( array( 'consented' => true ) );
	}

	/**
	 * Connect this site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function connect( \WP_REST_Request $request ): \WP_REST_Response {
		if ( true !== $request['consent'] ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Agree to connecting this site first.', 'zinn-seo' ),
				),
				422
			);
		}
		$email = sanitize_email( (string) $request['email'] );
		if ( ! is_email( $email ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Enter a valid e-mail address.', 'zinn-seo' ),
				),
				400
			);
		}
		Support::give_consent();
		$result = Connection::connect( $email, sanitize_text_field( (string) $request['name'] ) );

		return new \WP_REST_Response( $result, $result['ok'] ? 201 : 502 );
	}

	/**
	 * Disconnect this site.
	 *
	 * @return \WP_REST_Response
	 */
	public static function disconnect(): \WP_REST_Response {
		return new \WP_REST_Response( Connection::revoke() );
	}

	/**
	 * File a ticket.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket( \WP_REST_Request $request ): \WP_REST_Response {
		$result = Support::send( (array) $request->get_json_params() );
		$status = $result['ok'] ? 201 : max( 400, min( 599, (int) $result['status'] ) );

		return new \WP_REST_Response( $result, $status );
	}
}
