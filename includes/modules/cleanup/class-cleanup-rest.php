<?php
/**
 * REST for the clean-up screen (zs-701, zs-714, zs-727).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cleanup;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /cleanup` — what each switch removes on this site (read from the home page), the pages a
 * base-less category address would clash with, and the site's languages (title capitalisation).
 * `POST /cleanup/recommended` — switch on the recommended set in one go. Every other change is an
 * ordinary settings change (`PUT /settings`), so an AI agent and the screen use the same path.
 */
final class Cleanup_Rest {

	/** Transient holding the home page reading. */
	private const CACHE = 'zseo_cleanup_home';

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
		register_rest_route(
			Plugin::REST_NS,
			'/cleanup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get' ),
				'permission_callback' => array( Capabilities::class, 'can_manage' ),
				'args'                => array(
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/cleanup/recommended',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'recommended' ),
				'permission_callback' => array( Capabilities::class, 'can_manage' ),
			)
		);
	}

	/**
	 * The screen's state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$home = $request->get_param( 'fresh' ) ? null : get_transient( self::CACHE );
		if ( ! is_array( $home ) ) {
			$home = self::read_home();
			set_transient( self::CACHE, $home, 10 * MINUTE_IN_SECONDS );
		}

		return rest_ensure_response(
			array(
				'found'       => $home['found'],
				'read'        => $home['ok'],
				'read_at'     => $home['at'],
				'recommended' => Cleanup::RECOMMENDED,
				'keep_params' => Cleanup::KEEP_PARAMS,
				'collisions'  => array(
					'category'    => Bases::collisions( 'category' ),
					'product_cat' => taxonomy_exists( 'product_cat' ) ? Bases::collisions( 'product_cat' ) : array(),
				),
				'shop'        => taxonomy_exists( 'product_cat' ),
				'bases'       => array(
					'category'    => Bases::base( 'category' ),
					'product_cat' => taxonomy_exists( 'product_cat' ) ? Bases::base( 'product_cat' ) : '',
				),
				'languages'   => self::languages(),
				'pretty'      => '' !== (string) get_option( 'permalink_structure', '' ),
			)
		);
	}

	/**
	 * Switch on the recommended set.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function recommended() {
		$changes = array_fill_keys( Cleanup::RECOMMENDED, true );
		$saved   = Settings::save( $changes );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		delete_transient( self::CACHE );

		return rest_ensure_response( array( 'settings' => $saved ) );
	}

	/**
	 * Read the home page as a visitor (a loopback request) and find each switch's lines.
	 *
	 * @return array{ok: bool, at: int, found: array<string, array<int, string>>}
	 */
	public static function read_home(): array {
		$response = wp_remote_get(
			add_query_arg( 'zseo_read', (string) time(), home_url( '/' ) ),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for loopback requests.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'ok'    => false,
				'at'    => time(),
				'found' => array(),
			);
		}
		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = (array) $value;
		}

		return array(
			'ok'    => true,
			'at'    => time(),
			'found' => Cleanup::found_lines( (string) wp_remote_retrieve_body( $response ), $headers ),
		);
	}

	/**
	 * The site's languages (Tranzly's when it runs, else the site language), two letters each.
	 *
	 * @return array<int, array{code: string, name: string}>
	 */
	public static function languages(): array {
		$codes = array( strtolower( substr( (string) get_locale(), 0, 2 ) ) );
		if ( function_exists( 'tranzly_languages' ) ) {
			foreach ( (array) tranzly_languages() as $lang ) {
				$codes[] = strtolower( substr( (string) ( is_array( $lang ) ? ( $lang['code'] ?? '' ) : $lang ), 0, 2 ) );
			}
		}
		$out = array();
		foreach ( array_unique( array_filter( $codes ) ) as $code ) {
			$name  = class_exists( '\Locale' ) ? (string) \Locale::getDisplayLanguage( $code, (string) get_user_locale() ) : $code;
			$out[] = array(
				'code' => $code,
				'name' => '' !== $name ? $name : $code,
			);
		}

		return $out;
	}
}
