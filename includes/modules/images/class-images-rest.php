<?php
/**
 * REST for image SEO (zs-710).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Images;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /images` — counts (images, empty alt text) and the background run;
 * `POST /images/preview` — what an action would change (counts and examples);
 * `POST /images/run` — run it over the whole library in the background.
 * Templates and switches are ordinary settings (`PUT /settings`).
 */
final class Images_Rest {

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
		$manage = array( Capabilities::class, 'can_manage' );
		register_rest_route(
			Plugin::REST_NS,
			'/images',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get' ),
				'permission_callback' => $manage,
			)
		);
		foreach ( array( 'preview', 'run' ) as $verb ) {
			register_rest_route(
				Plugin::REST_NS,
				'/images/' . $verb,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, $verb ),
					'permission_callback' => $manage,
				)
			);
		}
	}

	/**
	 * Counts and the job.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- two counts for the screen.
		$images = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s", 'image/%' ) );
		$with   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' AND m.meta_value <> '' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s", 'image/%' ) );
		// phpcs:enable

		return rest_ensure_response(
			array(
				'images'    => $images,
				'no_alt'    => max( 0, $images - $with ),
				'job'       => Jobs::view( Images::JOB ),
				'languages' => function_exists( 'tranzly_languages' ) ? array_values(
					array_map(
						static fn( $l ) => is_array( $l ) ? array(
							'code' => strtolower( substr( (string) ( $l['code'] ?? '' ), 0, 2 ) ),
							'name' => (string) ( $l['name'] ?? '' ),
						) : array(
							'code' => (string) $l,
							'name' => (string) $l,
						),
						(array) tranzly_languages()
					)
				) : array(),
			)
		);
	}

	/**
	 * Preview an action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$action = Images::check_action( (array) $request->get_param( 'action' ) );

		return is_wp_error( $action ) ? $action : rest_ensure_response( Images::preview( $action ) );
	}

	/**
	 * Run an action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run( \WP_REST_Request $request ) {
		$action = Images::check_action( (array) $request->get_param( 'action' ) );
		if ( is_wp_error( $action ) ) {
			return $action;
		}
		$started = Images::start( $action );

		return is_wp_error( $started ) ? $started : rest_ensure_response( array( 'job' => Jobs::view( Images::JOB ) ) );
	}
}
