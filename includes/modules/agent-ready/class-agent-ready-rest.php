<?php
/**
 * REST: the Agent Ready report and its one-click fixes (zs-784).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AgentReady;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /agent-ready` (the score and every check; `live=1` also fetches robots.txt and llms.txt
 * over HTTP) and `POST /agent-ready/fix` (`check`: the id of a check with a one-click fix).
 */
final class Agent_Ready_Rest {

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
			'/agent-ready',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response => new \WP_REST_Response( Agent_Ready::report( (bool) $request['live'] ) ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
				'args'                => array(
					'live' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also fetch robots.txt and llms.txt over HTTP and judge what the site really serves.', 'zinn-seo' ),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/agent-ready/fix',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'fix' ),
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
				'args'                => array(
					'check' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'answer_bots', 'usage_signals', 'llms', 'llms_full', 'sitemaps', 'schema', 'mcp', 'markdown' ),
					),
				),
			)
		);
	}

	/**
	 * POST /agent-ready/fix.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function fix( \WP_REST_Request $request ) {
		$report = Agent_Ready::fix( (string) $request['check'] );

		return is_wp_error( $report ) ? $report : new \WP_REST_Response( $report );
	}
}
