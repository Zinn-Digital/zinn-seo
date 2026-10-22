<?php
/**
 * REST for keyword lists (zs-731).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Keywords;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /keyword-lists?after=&search=` — a page of lists (keyset);
 * - `POST /keyword-lists` — create; `PUT|DELETE /keyword-lists/{id}`;
 * - `GET /keyword-lists/{id}/posts?after=` — the posts it is assigned to (keyset);
 * - `POST /keyword-lists/{id}/posts` `{add: [], remove: []}`;
 * - `GET|PUT /keyword-lists/post/{post}` — the lists of one post (the editor).
 */
final class Keyword_Lists_Rest {

	/**
	 * Register on rest_api_init.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * May the current user work with lists (they are the site's research, edited by SEO editors)?
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( Capabilities::EDIT ) || Capabilities::can_manage();
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$ns     = Plugin::REST_NS;
		$fields = array(
			'label'   => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'color'   => array(
				'type' => 'string',
				'enum' => Keyword_Lists::COLORS,
			),
			'phrases' => array(
				'type'  => array( 'array', 'string' ),
				'items' => array( 'type' => 'string' ),
			),
		);
		register_rest_route(
			$ns,
			'/keyword-lists',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Keyword_Lists::page( (int) $r['after'], (int) $r['limit'], (string) $r['search'] ) ),
					'permission_callback' => array( self::class, 'can' ),
					'args'                => array(
						'after'  => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						'limit'  => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 500,
						),
						'search' => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 120,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Keyword_Lists::create( (array) $r->get_json_params() ) ),
					'permission_callback' => array( self::class, 'can' ),
					'args'                => $fields,
				),
			)
		);
		register_rest_route(
			$ns,
			'/keyword-lists/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Keyword_Lists::update( (int) $r['id'], array_intersect_key( (array) $r->get_json_params(), $fields ) ) ),
					'permission_callback' => array( self::class, 'can' ),
					'args'                => $fields,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( array( 'deleted' => Keyword_Lists::delete( (int) $r['id'] ) ) ),
					'permission_callback' => array( self::class, 'can' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/keyword-lists/(?P<id>\d+)/posts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Keyword_Lists::posts_of( (int) $r['id'], (int) $r['after'] ) ),
					'permission_callback' => array( self::class, 'can' ),
					'args'                => array(
						'after' => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'assign' ),
					'permission_callback' => array( self::class, 'can' ),
					'args'                => array(
						'add'    => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'default'  => array(),
							'maxItems' => 500,
						),
						'remove' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'default'  => array(),
							'maxItems' => 500,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/keyword-lists/(?P<id>\d+)/track',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$out = Keyword_Lists::track( (int) $r['id'] );
					return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
				},
				// Rank tracking is a paid check: the people who may spend Zinn® credit.
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::SPEND ) || Capabilities::can_manage(),
			)
		);
		register_rest_route(
			$ns,
			'/keyword-lists/(?P<id>\d+)/coverage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$out = Keyword_Lists::coverage( (int) $r['id'] );
					return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
				},
				'permission_callback' => array( self::class, 'can' ),
			)
		);
		register_rest_route(
			$ns,
			'/keyword-lists/post/(?P<post>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( array( 'lists' => Keyword_Lists::ids_for_post( (int) $r['post'] ) ) ),
					'permission_callback' => static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r['post'] ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( array( 'lists' => Keyword_Lists::set_for_post( (int) $r['post'], (array) $r['lists'] ) ) ),
					'permission_callback' => static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r['post'] ),
					'args'                => array(
						'lists' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'required' => true,
							'maxItems' => 200,
						),
					),
				),
			)
		);
	}

	/**
	 * Add posts to a list or take them off — only posts the person may edit.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function assign( \WP_REST_Request $r ) {
		$may = static fn( array $ids ): array => array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => current_user_can( 'edit_post', $id ) ) );
		$out = Keyword_Lists::assign( (int) $r['id'], $may( (array) $r['add'] ), $may( (array) $r['remove'] ) );

		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}
}
