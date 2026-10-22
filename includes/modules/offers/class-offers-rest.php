<?php
/**
 * REST routes of the offers and orders (zs-600 … zs-616).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Offers;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /offers` — every offer this person may see, priced by Zinn® (`?fresh=1` asks again).
 * - `POST /offers/seen` `{ids}` — count a view (the frequency cap).
 * - `POST /offers/dismiss` `{id, restore}` — hide one for good, or bring one (`*`: all) back.
 * - `GET  /offers/listings?offer=&product=&q=&max_price_minor=` — marketplace listings to choose.
 * - `POST /offers/domains` `{query}` — is a domain free, and its price.
 * - `GET|POST /offers/orders` — the site's orders; place one (opens a basket at Zinn®).
 * - `POST /offers/orders/<id>/placement` `{url}` — where a bought link went live (watched).
 *
 * Seeing offers needs the right to manage the plugin and not to be a white-labelled client;
 * placing an order needs the right to spend the owner's money (administrators by default),
 * though nothing is charged until the owner pays at Zinn®.
 */
final class Offers_Rest {

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
		$done  = true;
		$ns    = Plugin::REST_NS;
		$see   = static fn(): bool => Offers::may_show();
		$order = static fn(): bool => Offers::may_show() && current_user_can( Capabilities::SPEND );

		register_rest_route(
			$ns,
			'/offers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Offers::services( (bool) $r['fresh'] ) ),
				'permission_callback' => $see,
				'args'                => array( 'fresh' => array( 'type' => 'boolean' ) ),
			)
		);
		register_rest_route(
			$ns,
			'/offers/seen',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Offers::seen( array_map( 'strval', (array) $r['ids'] ) ) ),
				'permission_callback' => $see,
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'maxItems' => 50,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/offers/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Offers::dismiss( (string) $r['id'], (bool) $r['restore'] ) ),
				'permission_callback' => $see,
				'args'                => array(
					'id'      => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 64,
					),
					'restore' => array( 'type' => 'boolean' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/offers/listings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$offer   = sanitize_key( (string) $r['offer'] );
					$product = sanitize_key( (string) $r['product'] );
					$query   = array_filter(
						array(
							'q'               => substr( sanitize_text_field( (string) $r['q'] ), 0, 100 ),
							'max_price_minor' => null === $r['max_price_minor'] ? '' : (string) absint( $r['max_price_minor'] ),
						),
						static fn( $v ) => '' !== $v
					);
					$path    = '/v1/seo-connection/offers/' . rawurlencode( $offer ) . '/' . rawurlencode( $product ) . '/listings' . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
					return self::out( Offers::pass( 'GET', $path ) );
				},
				'permission_callback' => $see,
				'args'                => array(
					'offer'           => array(
						'type'     => 'string',
						'required' => true,
					),
					'product'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'q'               => array( 'type' => 'string' ),
					'max_price_minor' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/offers/domains',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Offers::pass( 'POST', '/v1/seo-connection/offers/domains/search', array( 'query' => substr( sanitize_text_field( (string) $r['query'] ), 0, 63 ) ) ) ),
				'permission_callback' => $see,
				'args'                => array(
					'query' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 63,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/offers/orders',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					// A site not yet connected has no orders: an empty list, not a failed request (the
					// Services screen loads it on every visit — T1 found its 409 as a console error).
					'callback'            => static fn() => Connection::connected()
						? self::out( Offers::pass( 'GET', '/v1/seo-connection/offers/orders' ) )
						: rest_ensure_response(
							array(
								'orders'    => array(),
								'connected' => false,
							)
						),
					'permission_callback' => $see,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => self::out( Offers::order( (array) $r->get_json_params() ) ),
					'permission_callback' => $order,
				),
			)
		);
		register_rest_route(
			$ns,
			'/offers/orders/(?P<id>[0-9a-fA-F-]{36})/placement',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Offers::pass( 'POST', '/v1/seo-connection/offers/orders/' . strtolower( (string) $r['id'] ) . '/placement', array( 'url' => esc_url_raw( (string) $r['url'], array( 'http', 'https' ) ) ) ) ),
				'permission_callback' => $order,
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}
}
