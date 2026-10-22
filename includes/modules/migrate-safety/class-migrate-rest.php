<?php
/**
 * REST routes of address changes.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Migrate;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /address` — current structure, aliases, the job, the last change.
 * - `POST /address/preview` — every address a planned structure moves (keyset `after`).
 * - `POST /address/start` — write the redirects, then switch.
 * - `GET  /address/domain?from=` — server snippets for an old domain.
 *
 * Changing the structure is an administrator's decision (`manage_options`) and writing redirects
 * needs `zseo_redirects`: both are required.
 */
final class Migrate_Rest {

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
		$done      = true;
		$can       = static fn(): bool => current_user_can( 'manage_options' ) && current_user_can( Capabilities::REDIRECTS );
		$structure = array(
			'structure'     => array( 'type' => 'string' ),
			'category_base' => array( 'type' => 'string' ),
			'tag_base'      => array( 'type' => 'string' ),
		);
		register_rest_route(
			Plugin::REST_NS,
			'/address',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response(
					array(
						'current'  => Migrate::current(),
						'aliases'  => (array) Settings::get( 'redirect_aliases', array() ),
						'home'     => home_url( '/' ),
						'job'      => Jobs::view( Migrate::JOB ),
						'last'     => get_option( 'zseo_address_change', null ),
						'tags'     => array( '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%' ),
						'settings' => admin_url( 'options-permalink.php' ),
					)
				),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/address/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$to = Migrate::clean( $r->get_params() );
					if ( is_wp_error( $to ) ) {
						return $to;
					}
					return rest_ensure_response( Migrate::preview( $to, (string) $r['after'], (int) $r['per'] ) + array( 'to' => $to ) );
				},
				'permission_callback' => $can,
				'args'                => $structure + array(
					'after' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^((post|term):\d+)?$',
					),
					'per'   => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/address/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$to = Migrate::clean( $r->get_params() );
					if ( is_wp_error( $to ) ) {
						return $to;
					}
					if ( Migrate::current() === $to ) {
						return new \WP_Error( 'zinn_seo_structure_same', __( 'That is the structure the site already uses.', 'zinn-seo' ), array( 'status' => 400 ) );
					}
					$s = Migrate::start( Migrate::current(), $to, true );
					return is_wp_error( $s ) ? $s : rest_ensure_response( Jobs::view( Migrate::JOB ) );
				},
				'permission_callback' => $can,
				'args'                => $structure,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/address/domain',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Migrate::domain_rules( (string) $r['from'] ) ),
				'permission_callback' => $can,
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}
}
