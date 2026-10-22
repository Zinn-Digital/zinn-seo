<?php
/**
 * REST: the AI crawler list, presets and choices.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Crawlers;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /crawlers`: every crawler with its kind and the site's choice, and the presets. Choices
 * are saved through `PUT /settings` (`ai_crawlers`), like every other setting.
 */
final class Crawlers_Rest {

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
			'/crawlers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
			)
		);
	}

	/**
	 * GET /crawlers.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		$blocked = Crawlers::blocked();
		$rows    = array();
		foreach ( Crawlers::all() as $id => $c ) {
			$rows[] = array(
				'id'      => (string) $id,
				'token'   => (string) $c['token'],
				'company' => (string) $c['company'],
				'kind'    => (string) $c['kind'],
				'docs'    => (string) $c['docs'],
				'allowed' => ! in_array( $id, $blocked, true ),
			);
		}

		/**
		 * Filters the crawler screen's data (the Pro hit log adds `hits`).
		 *
		 * @param array<string, mixed> $out Data.
		 */
		return rest_ensure_response(
			(array) apply_filters(
				'zinn_seo_crawlers_data',
				array(
					'crawlers' => $rows,
					'presets'  => Crawlers::presets(),
					'tdm_url'  => home_url( '/.well-known/tdmrep.json' ),
				)
			)
		);
	}
}
