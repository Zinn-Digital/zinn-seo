<?php
/**
 * REST routes of the Core Web Vitals module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cwv;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /search/speed?form=phone|desktop` — the whole site's record, and each template's.
 * - `GET /search/speed/page?url=&form=` — one page's record with its likely causes.
 * - `POST /search/speed/lab` `{url}` — a lab test (Pro; credit unless hosted by Zinn®);
 *   `GET /search/speed/lab/<id>` — its result.
 */
final class Cwv_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Only addresses of this site.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function own( string $url ): bool {
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$it   = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );

		return '' !== $it && $it === $host;
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
		$view  = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$spend = static fn(): bool => current_user_can( Capabilities::SPEND );
		$form  = array(
			'type'    => 'string',
			'enum'    => array( 'phone', 'desktop' ),
			'default' => 'phone',
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/speed',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$f      = (string) $r['form'];
					$origin = Cwv::record( '', 'origin', $f, (bool) $r['fresh'] );
					if ( is_wp_error( $origin ) ) {
						return $origin;
					}
					$templates = array();
					foreach ( Cwv::templates() as $t ) {
						$templates[] = Cwv::template_record( $t, $f );
					}
					return rest_ensure_response(
						array(
							'form'      => $f,
							'site'      => $origin,
							'causes'    => Cwv::causes( $origin ),
							'templates' => $templates,
							'labs'      => (array) get_option( Cwv::LAB, array() ),
							'pro'       => Plugin::is_pro(),
						)
					);
				},
				'permission_callback' => $view,
				'args'                => array(
					'form'  => $form,
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/speed/page',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$url = esc_url_raw( (string) $r['url'] );
					if ( ! self::own( $url ) ) {
						return new \WP_Error( 'zinn_seo_not_this_site', __( 'Only pages of this site can be measured here.', 'zinn-seo' ), array( 'status' => 422 ) );
					}
					$rec = Cwv::record( $url, 'url', (string) $r['form'] );
					if ( is_wp_error( $rec ) ) {
						return $rec;
					}
					if ( empty( $rec['has_data'] ) ) {
						$site = Cwv::record( '', 'origin', (string) $r['form'] );
						return rest_ensure_response(
							array(
								'page'   => $rec,
								'site'   => is_wp_error( $site ) ? null : $site,
								'causes' => is_wp_error( $site ) ? array() : Cwv::causes( $site ),
							)
						);
					}
					return rest_ensure_response(
						array(
							'page'   => $rec,
							'causes' => Cwv::causes( $rec ),
						)
					);
				},
				'permission_callback' => $view,
				'args'                => array(
					'url'  => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
					'form' => $form,
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/speed/lab',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$url = esc_url_raw( (string) $r['url'] );
					if ( ! self::own( $url ) ) {
						return new \WP_Error( 'zinn_seo_not_this_site', __( 'Only pages of this site can be measured here.', 'zinn-seo' ), array( 'status' => 422 ) );
					}
					$res = Cwv::lab( $url );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
				},
				'permission_callback' => $spend,
				'args'                => array(
					'url' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/speed/lab/(?P<id>[0-9a-fA-F-]{36})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = Cwv::lab_result( (string) $r['id'] );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
				},
				'permission_callback' => $view,
			)
		);
	}
}
