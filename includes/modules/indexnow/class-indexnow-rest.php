<?php
/**
 * REST: IndexNow status, a manual submission and the "request indexing" links.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Indexnow;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Robots\Staging;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /indexing`, `POST /indexing/submit` (addresses), `GET /indexing/inspect?url=`.
 */
final class Indexnow_Rest {

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
		$view = static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage();
		register_rest_route(
			Plugin::REST_NS,
			'/indexing',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/indexing/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'submit' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EDIT ) || Capabilities::can_manage(),
				'args'                => array(
					'urls' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/indexing/inspect',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'inspect' ),
				'permission_callback' => $view,
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /indexing.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		$job   = Jobs::get( Indexnow::JOB );
		$retry = get_option( Indexnow::RETRY, array() );
		$key   = (string) Settings::get( 'indexnow_key', '' );

		return rest_ensure_response(
			array(
				'enabled'   => \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ),
				'refusal'   => Indexnow::refusal(),
				'staging'   => array(
					'reason' => Staging::reason(),
					'mode'   => (string) Settings::get( 'staging_mode', 'auto' ),
					'host'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				),
				'key'       => $key,
				'key_url'   => '' === $key ? '' : home_url( '/' . $key . '.txt' ),
				'queue'     => count( Indexnow::pending_queue() ),
				'retry_at'  => is_array( $retry ) ? (int) ( $retry['at'] ?? 0 ) : 0,
				'job'       => array(
					'status'   => (string) $job['status'],
					'progress' => $job['progress'],
				),
				'log'       => array_slice( Indexnow::history(), 0, 20 ),
				'engines'   => array_keys( Indexnow::ENGINES ),
				'property'  => '' !== (string) Settings::get( 'gsc_property', '' ) ? (string) Settings::get( 'gsc_property', '' ) : home_url( '/' ),
				'webmaster' => array(
					'google_sitemaps' => add_query_arg( 'resource_id', rawurlencode( '' !== (string) Settings::get( 'gsc_property', '' ) ? (string) Settings::get( 'gsc_property', '' ) : home_url( '/' ) ), 'https://search.google.com/search-console/sitemaps' ),
					'bing_sitemaps'   => add_query_arg( 'siteUrl', rawurlencode( home_url( '/' ) ), 'https://www.bing.com/webmasters/sitemaps' ),
				),
			)
		);
	}

	/**
	 * POST /indexing/submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit( \WP_REST_Request $request ) {
		$refusal = Indexnow::refusal();
		if ( '' !== $refusal ) {
			return new \WP_Error( 'zinn_seo_indexnow_refused', self::refusal_text( $refusal ), array( 'status' => 409 ) );
		}
		if ( ! \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ) ) {
			return new \WP_Error( 'zinn_seo_indexnow_off', __( 'IndexNow is switched off. Switch it on first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$queued = Indexnow::queue( array_map( 'strval', (array) $request['urls'] ) );
		if ( 0 === $queued ) {
			return new \WP_Error( 'zinn_seo_indexnow_nothing', __( 'None of these addresses is on this site.', 'zinn-seo' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'queue'  => count( Indexnow::pending_queue() ),
			)
		);
	}

	/**
	 * GET /indexing/inspect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function inspect( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( Indexnow::inspect_links( esc_url_raw( (string) $request['url'] ) ) );
	}

	/**
	 * A refusal in words.
	 *
	 * @param string $refusal Code.
	 * @return string
	 */
	public static function refusal_text( string $refusal ): string {
		switch ( $refusal ) {
			case 'staging':
				return __( 'This looks like a staging copy, so nothing is sent to search engines from it.', 'zinn-seo' );
			case 'discouraged':
				return __( 'The site asks search engines not to index it (Settings, Reading), so nothing is sent.', 'zinn-seo' );
			case 'unreachable':
				return __( 'Search engines cannot reach this address (a local or private host), so nothing is sent.', 'zinn-seo' );
		}

		return '';
	}
}
