<?php
/**
 * The REST API root, `zinn-seo/v1` (zs-245).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every site-wide setting and every per-object field is reachable here (per-object fields also
 * through WordPress's own `/wp/v2/<type>` `meta`, where register_meta() put them). The admin
 * screens use exactly these routes, so the screen and an integration can never disagree (stage
 * P13 makes each route an MCP ability for AI agents).
 *
 * Every route has a permission callback on one of our capabilities; none is public.
 */
final class Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'register_routes' ) );
	}

	/**
	 * Permission: site-wide settings.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Capabilities::can_manage();
	}

	/**
	 * Permission: reading the plugin's state.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( Capabilities::VIEW ) || Capabilities::can_manage();
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = Plugin::REST_NS;

		register_rest_route(
			$ns,
			'/info',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'info' ),
				'permission_callback' => array( self::class, 'can_view' ),
			)
		);
		register_rest_route(
			$ns,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'put_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/settings/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( Transfer::export() );
				},
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/settings/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'import' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'document' => array(
						'type'     => 'object',
						'required' => true,
					),
					'dry_run'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/modules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( Modules::describe() );
				},
				'permission_callback' => array( self::class, 'can_view' ),
			)
		);
		register_rest_route(
			$ns,
			'/modules/(?P<id>[a-z][a-z0-9-]{0,40})',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'put_module' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/features',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( Features::all() );
				},
				'permission_callback' => array( self::class, 'can_view' ),
			)
		);
		register_rest_route(
			$ns,
			'/setup',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static function () {
						return rest_ensure_response( Setup::state() );
					},
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'put_setup' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'step'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'done', 'skip', 'redo', 'reset' ),
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/visibility',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'put_visibility' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'public' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/notrace',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static function () {
						return rest_ensure_response( Notrace::describe() );
					},
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'put_notrace' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/jobs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( Jobs::describe() );
				},
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/jobs/(?P<id>[a-z][a-z0-9-]{0,40})',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'control_job' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'start', 'pause', 'resume', 'cancel' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/fields/(?P<object>post|term|user)/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_fields' ),
					'permission_callback' => array( self::class, 'can_edit_object' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'put_fields' ),
					'permission_callback' => array( self::class, 'can_edit_object' ),
					'args'                => array(
						'fields' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/fields',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'describe_fields' ),
				'permission_callback' => static function (): bool {
					return current_user_can( Capabilities::EDIT );
				},
			)
		);
	}

	/**
	 * GET /info.
	 *
	 * @return \WP_REST_Response
	 */
	public static function info(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'version'  => ZINN_SEO_VERSION,
				'plan'     => Plugin::plan(),
				'modules'  => count( Modules::discover() ),
				'features' => count( Features::all() ),
				'notrace'  => Notrace::enabled(),
				'setup'    => array(
					'next'     => Setup::next_step(),
					'finished' => Setup::progress()['finished'],
				),
				'php'      => PHP_VERSION,
				'wp'       => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * GET /settings: every setting, plus its schema for forms and agents.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings(): \WP_REST_Response {
		$schema = array();
		foreach ( Settings::schema() as $key => $def ) {
			unset( $def['pattern'] );
			$schema[ $key ] = $def;
		}

		return rest_ensure_response(
			array(
				'settings' => Settings::all(),
				'schema'   => $schema,
			)
		);
	}

	/**
	 * PUT /settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_settings( \WP_REST_Request $request ) {
		$changes = (array) $request->get_param( 'settings' );
		// The no-trace switch has its own route, which lists what moves before it moves.
		if ( array_key_exists( 'notrace', $changes ) || array_key_exists( 'notrace_hash', $changes ) ) {
			return new \WP_Error( 'zinn_seo_use_notrace_route', __( 'Change the footprint setting with its own screen, which shows what changes first.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$saved = Settings::save( $changes, self::actor() );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( array( 'settings' => $saved ) );
	}

	/**
	 * POST /settings/import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import( \WP_REST_Request $request ) {
		$result = Transfer::import( $request->get_param( 'document' ), (bool) $request->get_param( 'dry_run' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * PUT /modules/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_module( \WP_REST_Request $request ) {
		$result = Modules::set( (string) $request['id'], (bool) $request->get_param( 'enabled' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Modules::describe() );
	}

	/**
	 * PUT /setup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_setup( \WP_REST_Request $request ) {
		$result = Setup::mark( (string) $request->get_param( 'step' ), (string) $request->get_param( 'action' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * PUT /visibility: WordPress's own "Discourage search engines" switch, from the setup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_visibility( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'zinn_seo_forbidden', __( 'Only an administrator can change whether search engines may index this site.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		update_option( 'blog_public', $request->get_param( 'public' ) ? '1' : '0' );

		return rest_ensure_response( array( 'public' => '1' === (string) get_option( 'blog_public' ) ) );
	}

	/**
	 * PUT /notrace: preview (dry_run) or switch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_notrace( \WP_REST_Request $request ) {
		$on = (bool) $request->get_param( 'enabled' );
		if ( $request->get_param( 'dry_run' ) ) {
			return rest_ensure_response(
				array(
					'enabled' => Notrace::enabled(),
					'changes' => Notrace::plan( $on ),
				)
			);
		}
		$result = Notrace::apply( $on, self::actor() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /jobs/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function control_job( \WP_REST_Request $request ) {
		$id     = (string) $request['id'];
		$action = (string) $request->get_param( 'action' );
		$result = 'start' === $action ? Jobs::start( $id ) : Jobs::control( $id, $action );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Jobs::describe() );
	}

	/**
	 * Permission for /fields/{object}/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_edit_object( \WP_REST_Request $request ): bool {
		$id = (int) $request['id'];
		if ( 'user' === $request['object'] ) {
			return self::can_edit_author( $id );
		}

		return 'term' === $request['object'] ? Capabilities::can_edit_term( $id ) : Capabilities::can_edit_post( $id );
	}

	/**
	 * May this person change an author's page (zs-756)? Their own, or anyone's for someone who
	 * manages SEO — the same rule as the profile screen.
	 *
	 * @param int $user_id The author.
	 * @return bool
	 */
	private static function can_edit_author( int $user_id ): bool {
		return current_user_can( 'edit_user', $user_id ) && ( current_user_can( Capabilities::EDIT ) || current_user_can( Capabilities::MANAGE ) );
	}

	/**
	 * GET /fields: the per-object fields and their types.
	 *
	 * @return \WP_REST_Response
	 */
	public static function describe_fields(): \WP_REST_Response {
		$out = array();
		foreach ( Meta::fields() as $name => $def ) {
			$def['description'] = Meta::describe( $def );
			$out[ $name ]       = $def;
		}

		return rest_ensure_response( $out );
	}

	/**
	 * GET /fields/{object}/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_fields( \WP_REST_Request $request ) {
		$object = (string) $request['object'];
		$id     = (int) $request['id'];
		if ( ! self::object_exists( $object, $id ) ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$out = array();
		foreach ( Meta::fields() as $name => $def ) {
			if ( in_array( $object, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
				$out[ $name ] = 'post' === $object ? Meta::get_post( $id, $name ) : ( 'user' === $object ? Meta::get_user( $id, $name ) : Meta::get_term( $id, $name ) );
			}
		}

		return rest_ensure_response( $out );
	}

	/**
	 * PUT /fields/{object}/{id}: only the fields sent change. Every value is checked first; when
	 * one is refused, none is stored.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_fields( \WP_REST_Request $request ) {
		$object = (string) $request['object'];
		$id     = (int) $request['id'];
		if ( ! self::object_exists( $object, $id ) ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$fields  = (array) $request->get_param( 'fields' );
		$defined = Meta::fields();
		foreach ( $fields as $name => $value ) {
			$def = $defined[ (string) $name ] ?? null;
			if ( null === $def || ! in_array( $object, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
				/* translators: %s: a field name. */
				return new \WP_Error( 'zinn_seo_unknown_field', sprintf( __( 'There is no SEO field called %s here.', 'zinn-seo' ), (string) $name ), array( 'status' => 400 ) );
			}
			// An author page's redirect sends its visitors to another site: only someone who
			// manages SEO may set it (zs-756).
			if ( 'user' === $object && 'redirect' === (string) $name && ! current_user_can( Capabilities::MANAGE ) ) {
				return new \WP_Error( 'zinn_seo_forbidden', __( 'Only someone who manages SEO can redirect an author\'s page.', 'zinn-seo' ), array( 'status' => 403 ) );
			}
			if ( is_wp_error( Meta::sanitize( $def, $value ) ) ) {
				/* translators: %s: a field name. */
				return new \WP_Error( 'zinn_seo_invalid_value', sprintf( __( 'The value for %s is not allowed.', 'zinn-seo' ), (string) $name ), array( 'status' => 400 ) );
			}
		}
		foreach ( $fields as $name => $value ) {
			Meta::set( $object, $id, (string) $name, $value, self::actor() );
		}

		return self::get_fields( $request );
	}

	/**
	 * Does the object exist?
	 *
	 * @param string $kind post|term|user.
	 * @param int    $id   ID.
	 * @return bool
	 */
	private static function object_exists( string $kind, int $id ): bool {
		if ( 'user' === $kind ) {
			return get_userdata( $id ) instanceof \WP_User;
		}

		return 'term' === $kind ? get_term( $id ) instanceof \WP_Term : get_post( $id ) instanceof \WP_Post;
	}

	/**
	 * Who is acting, for the change history: `user` unless something that knows better (the MCP
	 * layer while it runs an ability) says otherwise.
	 *
	 * @return string
	 */
	public static function actor(): string {
		/**
		 * Filters who is making a change through the REST API (user or agent).
		 *
		 * @param string $actor Actor.
		 */
		return (string) apply_filters( 'zinn_seo_actor', 'user' );
	}
}
