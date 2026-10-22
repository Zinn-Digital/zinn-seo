<?php
/**
 * REST for the internal link updates (zs-730).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\LinkUpdates;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /link-updates` the runs, the queue and the job; `POST /link-updates` rewrite every internal
 * link from one address to another now (an address moved outside WordPress, an old domain).
 */
final class Link_Updates_Rest {

	/**
	 * Register on rest_api_init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					Plugin::REST_NS,
					'/link-updates',
					array(
						array(
							'methods'             => \WP_REST_Server::READABLE,
							'callback'            => array( self::class, 'get' ),
							'permission_callback' => array( Capabilities::class, 'can_manage' ),
						),
						array(
							'methods'             => \WP_REST_Server::CREATABLE,
							'callback'            => array( self::class, 'post' ),
							'permission_callback' => array( Capabilities::class, 'can_manage' ),
							'args'                => array(
								'old' => array(
									'type'     => 'string',
									'required' => true,
								),
								'new' => array(
									'type'     => 'string',
									'required' => true,
								),
							),
						),
					)
				);
			}
		);
	}

	/**
	 * State for the screen.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'log'   => Link_Updates::log(),
				'queue' => Link_Updates::queued(),
				'job'   => Jobs::view( Link_Updates::JOB ),
			)
		);
	}

	/**
	 * Queue a manual change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function post( \WP_REST_Request $request ) {
		$home = home_url( '/' );
		$abs  = static function ( string $url ) use ( $home ): string {
			$url = trim( $url );
			return 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ? untrailingslashit( $home ) . $url : $url;
		};
		$old  = esc_url_raw( $abs( (string) $request->get_param( 'old' ) ), array( 'http', 'https' ) );
		$new  = esc_url_raw( $abs( (string) $request->get_param( 'new' ) ), array( 'http', 'https' ) );
		if ( '' === $old || '' === $new || untrailingslashit( $old ) === untrailingslashit( $new ) ) {
			return new \WP_Error( 'zinn_seo_bad_addresses', __( 'Enter two different addresses: the old one and the new one.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$path = (string) wp_parse_url( $old, PHP_URL_PATH );
		if ( '' === trim( $path, '/' ) && wp_parse_url( $old, PHP_URL_HOST ) === wp_parse_url( $home, PHP_URL_HOST ) ) {
			return new \WP_Error( 'zinn_seo_bad_addresses', __( 'The old address cannot be your home page: every link on the site starts with it.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$queued = Link_Updates::queue( $old, $new, (int) url_to_postid( $new ), 'manual' );

		return is_wp_error( $queued ) ? $queued : self::get();
	}
}
