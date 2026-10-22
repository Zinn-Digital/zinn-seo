<?php
/**
 * REST for the headline analyser (zs-723).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Headline;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /headline` `{ titles: string[], language? }` — the analysis of up to 20 titles at once (the
 * editor's title and the AI's rewrites side by side). Pure computation; nothing is stored.
 */
final class Headline_Rest {

	/**
	 * Register on rest_api_init.
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
		register_rest_route(
			Plugin::REST_NS,
			'/headline',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'analyse' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EDIT ) || Capabilities::can_manage(),
				'args'                => array(
					'titles'   => array(
						'type'     => 'array',
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'required' => true,
						'minItems' => 1,
						'maxItems' => 20,
					),
					'language' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[A-Za-z_-]{0,12}$',
					),
				),
			)
		);
	}

	/**
	 * Analyse.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function analyse( \WP_REST_Request $request ): \WP_REST_Response {
		$out = array();
		foreach ( (array) $request['titles'] as $title ) {
			$out[] = array( 'title' => (string) $title ) + Headline::analyse( (string) $title, (string) $request['language'] );
		}

		return rest_ensure_response(
			array(
				'items'     => $out,
				'languages' => Headline::languages(),
			)
		);
	}
}
