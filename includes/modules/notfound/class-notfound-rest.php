<?php
/**
 * REST routes of the 404 monitor.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Notfound;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Counters;
use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /notfound?kind=404|404b&order=hits|last_seen|first_seen&after=&search=` — the counters,
 *   keyset; each row with a suggested page (the published page whose slug matches) and whether a
 *   redirect now answers it.
 * - `DELETE /notfound` with `hash` (one) or `all` (the whole kind).
 * - `POST /notfound/redirect` — make a redirect from a missing address (then the row goes).
 * - `POST /notfound/ignore` — add the address to the ignore list (then the row goes).
 */
final class Notfound_Rest {

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
		$done   = true;
		$view   = static fn(): bool => current_user_can( Capabilities::REDIRECTS ) || current_user_can( Capabilities::VIEW );
		$manage = static fn(): bool => current_user_can( Capabilities::REDIRECTS );
		$kind   = array(
			'type'    => 'string',
			'enum'    => array( '404', '404b' ),
			'default' => '404',
		);
		register_rest_route(
			Plugin::REST_NS,
			'/notfound',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list' ),
					'permission_callback' => $view,
					'args'                => array(
						'kind'   => $kind,
						'order'  => array(
							'type'    => 'string',
							'enum'    => array( 'hits', 'last_seen', 'first_seen' ),
							'default' => 'hits',
						),
						'after'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'search' => array(
							'type'    => 'string',
							'default' => '',
						),
						'per'    => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 200,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$hash = (string) $r['hash'];
						if ( '' === $hash && ! $r['all'] ) {
							return new \WP_Error( 'zinn_seo_notfound_which', __( 'Say which address to forget, or all of them.', 'zinn-seo' ), array( 'status' => 400 ) );
						}
						return rest_ensure_response( array( 'deleted' => Counters::forget( (string) $r['kind'], '' === $hash ? null : $hash ) ) );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'kind' => $kind,
						'hash' => array(
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{32}$|^$',
							'default' => '',
						),
						'all'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/notfound/redirect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'redirect' ),
				'permission_callback' => $manage,
				'args'                => array(
					'kind'    => $kind,
					'address' => array(
						'type'     => 'string',
						'required' => true,
					),
					'target'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'code'    => array(
						'type'    => 'integer',
						'default' => 301,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/notfound/ignore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$address = Notfound::address( (string) $r['address'] );
					if ( '' === $address ) {
						return new \WP_Error( 'zinn_seo_notfound_address', __( 'That is not an address on this site.', 'zinn-seo' ), array( 'status' => 400 ) );
					}
					$rules = trim( (string) Settings::get( 'notfound_ignore', '' ) );
					if ( ! Notfound::ignored( $address, $rules ) ) {
						$saved = Settings::save( array( 'notfound_ignore' => ltrim( $rules . "\n" . $address ) ) );
						if ( is_wp_error( $saved ) ) {
							return $saved;
						}
					}
					Counters::forget( (string) $r['kind'], md5( $address ) );
					return rest_ensure_response( array( 'ignored' => $address ) );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'kind'    => $kind,
					'address' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /notfound.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function list( \WP_REST_Request $r ) {
		$page              = Counters::rows( (string) $r['kind'], (string) $r['order'], (int) $r['per'], (string) $r['after'], (string) $r['search'] );
		$redirects         = Modules::enabled( 'redirects' ) && class_exists( '\ZinnDigital\ZinnSEO\Redirects\Serve' );
		$page['items']     = array_map(
			static function ( array $row ) use ( $redirects ): array {
				$row['suggest']    = Notfound::suggest( (string) $row['key'] );
				$row['redirected'] = $redirects && null !== \ZinnDigital\ZinnSEO\Redirects\Serve::match( (string) $row['key'], true );
				return $row;
			},
			$page['items']
		);
		$page['counts']    = array(
			'404'  => Counters::count( '404' ),
			'404b' => Counters::count( '404b' ),
		);
		$page['ceiling']   = Counters::ceiling( (string) $r['kind'] );
		$page['redirects'] = $redirects;

		return rest_ensure_response( $page );
	}

	/**
	 * POST /notfound/redirect.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function redirect( \WP_REST_Request $r ) {
		if ( ! Modules::enabled( 'redirects' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Redirects\Store' ) ) {
			return new \WP_Error( 'zinn_seo_redirects_off', __( 'Switch the Redirects module on first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$address = Notfound::address( (string) $r['address'] );
		$saved   = \ZinnDigital\ZinnSEO\Redirects\Store::save(
			array(
				'source' => $address,
				'target' => (string) $r['target'],
				'code'   => (int) $r['code'],
				'origin' => '404',
				'group'  => __( 'From the 404 list', 'zinn-seo' ),
			)
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Counters::forget( (string) $r['kind'], md5( $address ) );

		return new \WP_REST_Response( $saved, 201 );
	}
}
