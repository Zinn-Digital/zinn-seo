<?php
/**
 * REST routes of the site authority module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Authority;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /authority` (`fresh`) — sources, keys (last four characters only), what is tracked,
 *   the latest values.
 * - `PUT|DELETE /authority/sources/{source}/key` — connect / forget the site owner's key.
 * - `PUT  /authority/sources/{source}/feed` — what to show, track and be told about, how often.
 * - `POST /authority/sources/{source}/estimate` — what a schedule will spend, before saving it.
 * - `POST /authority/sources/{source}/run` — check now.
 * - `GET  /authority/history` (`source`, `metric`, `days`).
 *
 * Reading needs the right to see SEO reports; keys and schedules need the right to manage the
 * plugin; Zinn Authority (it spends Zinn® credit) needs the right to spend.
 */
final class Authority_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * A REST answer from a result or an error.
	 *
	 * @param mixed $result Result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function answer( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * May the current user change this source? Zinn Authority spends credit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_change( \WP_REST_Request $request ): bool {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return false;
		}

		return 'zinn_authority' !== (string) $request['source'] || current_user_can( Capabilities::SPEND );
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
		$ns     = Plugin::REST_NS;
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE );
		$source = array(
			'type'    => 'string',
			'pattern' => '^[a-z_]{2,32}$',
		);

		register_rest_route(
			$ns,
			'/authority',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::state( (bool) $r['fresh'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/authority/sources/(?P<source>[a-z_]{2,32})/key',
			array(
				array(
					'methods'             => 'PUT',
					// ⛔ The key goes straight to Zinn® (never stored here) — see Authority.
					'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::connect_key( (string) $r['source'], (array) $r->get_json_params() ) ),
					'permission_callback' => $manage,
					'args'                => array( 'source' => $source ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::remove_key( (string) $r['source'] ) ),
					'permission_callback' => $manage,
					'args'                => array( 'source' => $source ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/authority/sources/(?P<source>[a-z_]{2,32})/feed',
			array(
				'methods'             => 'PUT',
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::save_feed( (string) $r['source'], (array) $r->get_json_params() ) ),
				'permission_callback' => array( self::class, 'can_change' ),
				'args'                => array( 'source' => $source ),
			)
		);
		register_rest_route(
			$ns,
			'/authority/sources/(?P<source>[a-z_]{2,32})/estimate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::estimate( (string) $r['source'], array_map( 'strval', (array) $r['metrics'] ), (int) $r['cadence_days'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'source'       => $source,
					'metrics'      => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'cadence_days' => array(
						'type'    => 'integer',
						'enum'    => array( 1, 7, 30 ),
						'default' => 30,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/authority/sources/(?P<source>[a-z_]{2,32})/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::run( (string) $r['source'] ) ),
				'permission_callback' => array( self::class, 'can_change' ),
				'args'                => array( 'source' => $source ),
			)
		);
		register_rest_route(
			$ns,
			'/authority/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Authority::history( (string) $r['source'], (string) $r['metric'], (int) $r['days'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'source' => $source + array( 'required' => true ),
					'metric' => array(
						'type'     => 'string',
						'pattern'  => '^[a-z_]{2,40}$',
						'required' => true,
					),
					'days'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 1095,
						'default' => 365,
					),
				),
			)
		);
	}
}
