<?php
/**
 * REST for the bulk editor (the screen, integrations and AI agents use the same routes).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bulk;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /bulk` — a keyset page of rows (`before` = the last ID shown).
 * - `PUT /bulk` — save edited rows as one batch.
 * - `POST /bulk/apply` — change fields on every row matching the filters (background job).
 * - `GET /bulk/history`, `POST /bulk/undo` — the batches, and undo.
 *
 * Editing rows needs `zseo_edit` plus the right to edit each object; a change to every matching
 * row and undo need `zseo_manage` (they reach objects the person never saw on screen).
 */
final class Bulk_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * The filter arguments every list route takes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filter_args(): array {
		return array(
			'object'   => array(
				'type'    => 'string',
				'enum'    => array( 'post', 'term' ),
				'default' => 'post',
			),
			'type'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'status'   => array(
				'type'    => 'string',
				'default' => 'any',
			),
			'search'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'missing'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'robots'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'discover' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'term'     => array(
				'type'    => 'integer',
				'default' => 0,
			),
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
			'/bulk',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_rows' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => array_merge(
						self::filter_args(),
						array(
							// "<post_date>|<ID>" since 0.15.2 (`Bulk::cursor()`); a plain ID from an
							// older screen still works.
							// A JSON or internal caller may still send the integer (P1's harness did:
							// a string-only arg refused it with a 400 — caught by T1).
							'before'   => array(
								'type'    => array( 'integer', 'string' ),
								'default' => 0,
								'minimum' => 0,
								'pattern' => '^([0-9]+|[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}[|][0-9]+)$',
							),
							'per_page' => array(
								'type'    => 'integer',
								'default' => Bulk::PER_PAGE,
								'minimum' => 1,
								'maximum' => 200,
							),
						)
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'save_rows' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => array(
						'object' => array(
							'type'    => 'string',
							'enum'    => array( 'post', 'term' ),
							'default' => 'post',
						),
						'rows'   => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => 500,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/bulk/choices',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response(
						array(
							'types'  => Bulk::choices(),
							'fields' => Bulk::fields(),
							'schema' => Bulk::schema_choices(),
						)
					);
				},
				'permission_callback' => array( self::class, 'can_edit' ),
			)
		);
		register_rest_route(
			$ns,
			'/bulk/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'apply' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array_merge(
					self::filter_args(),
					array(
						'fields' => array(
							'type'     => 'object',
							'required' => true,
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/bulk/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'history' ),
				'permission_callback' => array( self::class, 'can_edit' ),
			)
		);
		register_rest_route(
			$ns,
			'/bulk/undo',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'undo' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'batch' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9]{12}$',
					),
				),
			)
		);
	}

	/**
	 * Permission: may edit SEO fields.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		return current_user_can( Capabilities::EDIT );
	}

	/**
	 * Permission: may change site-wide things.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Capabilities::can_manage();
	}

	/**
	 * GET /bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_rows( \WP_REST_Request $request ) {
		$f = Bulk::filter( $request->get_params() );
		if ( ! Bulk::allowed( $f ) ) {
			return new \WP_Error( 'zinn_seo_forbidden', __( 'Sorry, you are not allowed to edit this kind of content.', 'zinn-seo' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( Bulk::page( $f, (string) $request['before'], (int) $request['per_page'] ) );
	}

	/**
	 * PUT /bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_rows( \WP_REST_Request $request ) {
		$result = Bulk::save( 'term' === $request['object'] ? 'term' : 'post', (array) $request['rows'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /bulk/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function apply( \WP_REST_Request $request ) {
		$f = Bulk::filter( $request->get_params() );
		if ( ! Bulk::allowed( $f ) ) {
			return new \WP_Error( 'zinn_seo_forbidden', __( 'Sorry, you are not allowed to edit this kind of content.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		$result = Bulk::start_apply( $f, (array) $request['fields'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result + array( 'status' => Jobs::get( Bulk::JOB_APPLY )['status'] ) );
	}

	/**
	 * GET /bulk/history: the batches, with their names and the running job's progress.
	 *
	 * @return \WP_REST_Response
	 */
	public static function history(): \WP_REST_Response {
		$out = array();
		foreach ( History::index() as $row ) {
			$user          = get_userdata( (int) $row['user'] );
			$row['who']    = $user ? (string) $user->display_name : '';
			$row['when']   = (int) $row['at'];
			$row['undone'] = (string) $row['undone'];
			$out[]         = $row;
		}
		$jobs = array();
		foreach ( array( Bulk::JOB_APPLY, Bulk::JOB_UNDO ) as $id ) {
			$job         = Jobs::get( $id );
			$jobs[ $id ] = array(
				'status'   => $job['status'],
				'progress' => $job['progress'],
				'error'    => $job['error'],
			);
		}

		return rest_ensure_response(
			array(
				'batches' => $out,
				'jobs'    => $jobs,
			)
		);
	}

	/**
	 * POST /bulk/undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function undo( \WP_REST_Request $request ) {
		$result = Bulk::undo( (string) $request['batch'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
