<?php
/**
 * REST routes of the duplicate finder.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Duplicates;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /duplicates?kind=title|desc|h1|focus&after=<hash>`, `GET /duplicates/summary`,
 * `POST /duplicates/scan`.
 */
final class Duplicates_Rest {

	/**
	 * Hook in.
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
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$view = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::EDIT );
		register_rest_route(
			Plugin::REST_NS,
			'/duplicates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Duplicates::groups( (string) $r['kind'], (string) $r['after'], (int) $r['per'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'kind'  => array(
						'type'    => 'string',
						'enum'    => array_keys( Duplicates::KEYS ),
						'default' => 'title',
					),
					'after' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[^\x00]{0,191}$',
					),
					'per'   => array(
						'type'    => 'integer',
						'default' => 25,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/duplicates/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response(
					array(
						'counts' => Duplicates::counts(),
						'built'  => get_option( Duplicates::BUILT, array() ),
						'job'    => Jobs::view( Duplicates::JOB ),
					)
				),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/duplicates/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					$started = Jobs::start( Duplicates::JOB, array() );
					return is_wp_error( $started ) ? $started : rest_ensure_response( Jobs::view( Duplicates::JOB ) );
				},
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
			)
		);
	}
}
