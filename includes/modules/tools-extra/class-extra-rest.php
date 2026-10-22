<?php
/**
 * REST routes of the research toolbox.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Extra;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /toolbox` — the tools, their prices, what this edition runs, the credit.
 * - `POST /toolbox/<tool>/quote` `{params}` — what a run would cost (nothing is spent).
 * - `POST /toolbox/<tool>/run` `{params}` — run it now (spends credit).
 * - `GET  /toolbox/runs?tool=&watch=` — recent runs; `GET /toolbox/run/<id>` — one run.
 * - `POST /toolbox/places` `{query, lat, lng, location}` — find a business on Google Maps.
 * - `GET|POST /toolbox/watches`, `PATCH|DELETE /toolbox/watches/<id>` — the schedule.
 * - `POST /toolbox/faq` `{items, post_id, title}` — put questions on a page as an FAQ block.
 *
 * Reading needs the right to see SEO reports; anything that can spend needs the right to spend
 * the owner's credit (administrators by default); an FAQ needs the right to edit that page.
 */
final class Extra_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Wrap a result for REST.
	 *
	 * @param mixed $result Result or error.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function out( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
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
		$done   = true;
		$view   = static fn(): bool => current_user_can( Capabilities::SPEND ) || current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$spend  = static fn(): bool => current_user_can( Capabilities::SPEND );
		$tool   = '(?P<tool>[a-z_]{2,20})';
		$uuid   = '(?P<id>[0-9a-fA-F-]{36})';
		$ns     = Plugin::REST_NS;
		$params = array(
			'params' => array(
				'type'     => 'object',
				'required' => true,
			),
		);

		register_rest_route(
			$ns,
			'/toolbox',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => self::out( Extra::overview() ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/' . $tool . '/quote',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::quote( (string) $r['tool'], (array) $r['params'] ) ),
				'permission_callback' => $view,
				'args'                => $params,
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/' . $tool . '/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::run( (string) $r['tool'], (array) $r['params'] ) ),
				'permission_callback' => $spend,
				'args'                => $params,
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/runs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::runs( sanitize_key( (string) $r['tool'] ), (string) $r['watch'] ) ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/run/' . $uuid,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::run_get( (string) $r['id'] ) ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/places',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::places( (array) $r->get_json_params() ) ),
				'permission_callback' => $spend,
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/watches',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => self::out( Extra::watches() ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::watch( sanitize_key( (string) $r['tool'] ), (array) $r['params'], (int) $r['every'] ) ),
					'permission_callback' => $spend,
					'args'                => $params + array(
						'tool'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'every' => array(
							'type'    => 'integer',
							'enum'    => array( 1, 7, 30 ),
							'default' => 7,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/watches/' . $uuid,
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::watch_change( (string) $r['id'], (array) $r->get_json_params() ) ),
					'permission_callback' => $spend,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::unwatch( (string) $r['id'] ) ),
					'permission_callback' => $spend,
				),
			)
		);
		register_rest_route(
			$ns,
			'/toolbox/faq',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Extra::faq_to_page( (array) $r['items'], (int) $r['post_id'], (string) $r['title'] ) ),
				// The page's own capability is checked inside (edit that post, or create posts).
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'items'   => array(
						'type'     => 'array',
						'required' => true,
					),
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'title'   => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 200,
					),
				),
			)
		);
	}
}
