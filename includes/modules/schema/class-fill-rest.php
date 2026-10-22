<?php
/**
 * REST for structured data in bulk (zs-764, zs-765): the screen, AI agents (MCP) and WP-CLI use
 * the same routes.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under `zinn-seo/v1/schema/fill`:
 * - `GET  /schema/fill` — the runs, what is waiting for review per kind, the AI state;
 * - `POST /schema/fill` `{kind, types, missing, mode: count|sample|start, sample}` — price a run,
 *   try it on a few pages now, or start it for the whole site;
 * - `POST /schema/fill/control` `{job, action}` — pause, resume, stop;
 * - `GET  /schema/fill/review?kind=&after=` — suggestions with their check and a preview;
 * - `POST /schema/fill/apply` `{kind, ids}` or `{kind, all: true}` — use them (one undoable batch);
 * - `POST /schema/fill/skip` `{kind, ids}` or `{kind, all: true}` — throw them away;
 * - `POST /schema/fill/check` `{post, kind, record}` — check a record before saving it (the bulk
 *   editor's structured data column).
 *
 * Generating and using need `zseo_manage` (they reach pages the person may never open) and the
 * right to use AI; checking needs the right to edit the post.
 */
final class Fill_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * May run AI over the site.
	 *
	 * @return bool
	 */
	public static function can_run(): bool {
		return Capabilities::can_manage() && current_user_can( Capabilities::AI );
	}

	/**
	 * The `kind` argument.
	 *
	 * @return array<string, mixed>
	 */
	private static function kind_arg(): array {
		return array(
			'type'     => 'string',
			'enum'     => Fill::KINDS,
			'required' => true,
		);
	}

	/**
	 * The `ids` argument.
	 *
	 * @return array<string, mixed>
	 */
	private static function ids_arg(): array {
		return array(
			'type'     => 'array',
			'items'    => array( 'type' => 'integer' ),
			'maxItems' => 200,
			'default'  => array(),
		);
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$ns = Plugin::REST_NS;
		register_rest_route(
			$ns,
			'/schema/fill',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'state' ),
					'permission_callback' => array( self::class, 'can_run' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'start' ),
					'permission_callback' => array( self::class, 'can_run' ),
					'args'                => array(
						'kind'    => self::kind_arg(),
						'types'   => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'missing' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'mode'    => array(
							'type'    => 'string',
							'enum'    => array( 'count', 'sample', 'start' ),
							'default' => 'count',
						),
						'sample'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 10,
							'default' => 5,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/schema/fill/control',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'control' ),
				'permission_callback' => array( self::class, 'can_run' ),
				'args'                => array(
					'job'    => array(
						'type'     => 'string',
						'enum'     => array( Fill_Ai::JOB, Fill_Ai::JOB_APPLY ),
						'required' => true,
					),
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'pause', 'resume', 'cancel' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/schema/fill/review',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'review' ),
				'permission_callback' => array( self::class, 'can_run' ),
				'args'                => array(
					'kind'     => self::kind_arg(),
					'after'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 20,
					),
				),
			)
		);
		foreach ( array( 'apply', 'skip' ) as $what ) {
			register_rest_route(
				$ns,
				'/schema/fill/' . $what,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, $what ),
					'permission_callback' => array( self::class, 'can_run' ),
					'args'                => array(
						'kind' => self::kind_arg(),
						'ids'  => self::ids_arg(),
						'all'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				)
			);
		}
		register_rest_route(
			$ns,
			'/schema/fill/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'check' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r->get_param( 'post' ) ),
				'args'                => array(
					'post'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'kind'   => self::kind_arg(),
					'record' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /schema/fill.
	 *
	 * @return \WP_REST_Response
	 */
	public static function state(): \WP_REST_Response {
		return rest_ensure_response( self::data() );
	}

	/**
	 * What the screen needs (also sent with the admin page).
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$kinds = array();
		foreach ( Fill::KINDS as $kind ) {
			$types = array();
			foreach ( Fill_Ai::types( $kind ) as $type ) {
				$object  = get_post_type_object( $type );
				$types[] = array(
					'name'  => $type,
					'label' => $object instanceof \WP_Post_Type ? (string) $object->labels->name : $type,
				);
			}
			$kinds[ $kind ] = array(
				'label'   => Fill_Ai::label( $kind ),
				'types'   => $types,
				'waiting' => Fill_Ai::available() ? Fill_Ai::waiting( $kind ) : 0,
			);
		}

		return array(
			'available' => Fill_Ai::available(),
			'kinds'     => $kinds,
			'jobs'      => Fill_Ai::status(),
			'ai'        => class_exists( '\ZinnDigital\ZinnSEO\Ai\Ai_Rest' ) ? \ZinnDigital\ZinnSEO\Ai\Ai_Rest::state() : null,
		);
	}

	/**
	 * POST /schema/fill.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start( \WP_REST_Request $r ) {
		$out = Fill_Ai::start(
			(string) $r['kind'],
			array(
				'types'   => (array) $r['types'],
				'missing' => (bool) $r['missing'],
			),
			(string) $r['mode'],
			(int) $r['sample']
		);

		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * POST /schema/fill/control.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function control( \WP_REST_Request $r ) {
		$done = Jobs::control( (string) $r['job'], (string) $r['action'] );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		return rest_ensure_response( Fill_Ai::status() );
	}

	/**
	 * GET /schema/fill/review.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function review( \WP_REST_Request $r ): \WP_REST_Response {
		return rest_ensure_response( Fill_Ai::review( (string) $r['kind'], (int) $r['after'], (int) $r['per_page'] ) );
	}

	/**
	 * POST /schema/fill/apply.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function apply( \WP_REST_Request $r ) {
		if ( ! empty( $r['all'] ) ) {
			$out = Fill_Ai::start_apply( (string) $r['kind'] );
			return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
		}
		$ids = array_map( 'intval', (array) $r['ids'] );
		if ( array() === $ids ) {
			return new \WP_Error( 'zinn_seo_nothing', __( 'Choose at least one page.', 'zinn-seo' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( Fill_Ai::apply_ids( (string) $r['kind'], $ids ) );
	}

	/**
	 * POST /schema/fill/skip.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function skip( \WP_REST_Request $r ): \WP_REST_Response {
		return rest_ensure_response( array( 'skipped' => Fill_Ai::skip( (string) $r['kind'], array_map( 'intval', (array) $r['ids'] ), ! empty( $r['all'] ) ) ) );
	}

	/**
	 * POST /schema/fill/check.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check( \WP_REST_Request $r ) {
		$post = get_post( (int) $r['post'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'There is no such page.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$out = Fill::check( (string) $r['kind'], (array) $r['record'], $post );
		unset( $out['nodes'] );

		return rest_ensure_response( $out );
	}
}
