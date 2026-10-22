<?php
/**
 * REST for Search appearance › Content types (Q277, zs-768).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Templates;

use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /content-types` lists every content type and taxonomy with who registered it, whether it
 * has pages of its own and its four choices. `POST /content-types/hide` previews (`dry_run`, the
 * default) or applies "Hide from search" for some types; `POST /content-types/undo` puts the last
 * one back. A single choice (one switch) is the ordinary `PUT /settings` (`templates`,
 * `sitemap_exclude_types`, `sitemap_exclude_taxonomies`, `content_types`).
 */
final class Content_Types_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Plugin::REST_NS,
			'/content-types',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn(): \WP_REST_Response => rest_ensure_response( Content_Types::rows() ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/content-types/hide',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'hide' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
				'args'                => array(
					'keys'    => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 400,
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^(post|tax):[a-z0-9_-]{1,32}$',
						),
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/content-types/undo',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'undo' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
			)
		);
	}

	/**
	 * POST /content-types/hide.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function hide( \WP_REST_Request $request ) {
		$keys = array_map( 'strval', (array) $request['keys'] );
		$out  = false === (bool) $request['dry_run'] ? Content_Types::hide( $keys ) : Content_Types::plan( $keys );
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		return rest_ensure_response(
			array(
				'dry_run' => false !== (bool) $request['dry_run'],
				'types'   => $out,
			)
		);
	}

	/**
	 * POST /content-types/undo.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function undo() {
		$out = Content_Types::undo();

		return is_wp_error( $out ) ? $out : rest_ensure_response( array( 'restored' => $out ) );
	}
}
