<?php
/**
 * REST: llms.txt preview, addresses and refresh.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Llms;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /llms` (addresses per language and a fresh preview of llms.txt), `POST /llms/refresh`.
 */
final class Llms_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Plugin::REST_NS,
			'/llms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
				'args'                => array(
					'lang' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/llms/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'refresh' ),
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
			)
		);
	}

	/**
	 * GET /llms.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$languages = Llms::languages();
		$lang      = sanitize_text_field( (string) $request['lang'] );
		if ( ! isset( $languages[ $lang ] ) || Llms::default_language() === $lang ) {
			$lang = '';
		}
		$files = array(
			array(
				'lang' => '',
				'name' => '',
				'url'  => Llms::url( '', false ),
				'full' => Llms::url( '', true ),
			),
		);
		foreach ( $languages as $code => $name ) {
			if ( Llms::default_language() !== $code ) {
				$files[] = array(
					'lang' => (string) $code,
					'name' => (string) $name,
					'url'  => Llms::url( (string) $code, false ),
					'full' => Llms::url( (string) $code, true ),
				);
			}
		}
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( ! in_array( $type->name, array( 'page', 'attachment' ), true ) ) {
				$types[] = array(
					'name'  => $type->name,
					'label' => (string) $type->labels->name,
				);
			}
		}

		return rest_ensure_response(
			array(
				'files'   => $files,
				'preview' => Llms::build( $lang, false ),
				'lang'    => $lang,
				'types'   => $types,
				'chosen'  => Llms::types(),
			)
		);
	}

	/**
	 * POST /llms/refresh.
	 *
	 * @return \WP_REST_Response
	 */
	public static function refresh(): \WP_REST_Response {
		Llms::forget();

		return rest_ensure_response( array( 'refreshed' => true ) );
	}
}
