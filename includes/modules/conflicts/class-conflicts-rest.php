<?php
/**
 * REST for the conflict detector (zs-703).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Conflicts;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /conflicts` the last reading; `POST /conflicts/scan` read the pages now;
 * `POST /conflicts/fix` apply a fix (`id`); `POST /conflicts/undo` take a switch-type fix back.
 */
final class Conflicts_Rest {

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
			'/conflicts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( self::shape( Conflicts::last() ) ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/conflicts/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn() => rest_ensure_response( self::shape( Conflicts::scan() ) ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/conflicts/preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $request ) {
					$out = Conflicts::preview( (string) $request->get_param( 'id' ) );
					return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^(join-entities|microdata:[a-z]+|jsonld:[a-z]+)$',
					),
				),
			)
		);
		foreach ( array( 'fix', 'undo' ) as $verb ) {
			register_rest_route(
				Plugin::REST_NS,
				'/conflicts/' . $verb,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function ( \WP_REST_Request $request ) use ( $verb ) {
						$id   = (string) $request->get_param( 'id' );
						$done = 'fix' === $verb ? Conflicts::fix( $id ) : Conflicts::undo( $id );
						return is_wp_error( $done ) ? $done : rest_ensure_response( self::shape( Conflicts::scan() ) );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'id' => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^(jetpack-og|theme-title|jetpack-sitemaps|join-entities|microdata:[a-z]+|jsonld:[a-z]+|file:[a-z_.]+)$',
						),
					),
				)
			);
		}
	}

	/**
	 * A reading with each issue's sentences, for the screen.
	 *
	 * @param array<string, mixed> $reading Reading.
	 * @return array<string, mixed>
	 */
	public static function shape( array $reading ): array {
		$issues = array();
		foreach ( (array) ( $reading['issues'] ?? array() ) as $issue ) {
			$issues[] = $issue + array(
				'title'  => Conflicts::issue_title( $issue ),
				'detail' => Conflicts::issue_detail( $issue ),
			);
		}
		$reading['issues'] = $issues;
		$reading['fixes']  = (array) \ZinnDigital\ZinnSEO\Settings::get( 'conflict_fixes', array() );
		$reading['labels'] = array();
		foreach ( $reading['fixes'] as $fix ) {
			$reading['labels'][ (string) $fix ] = Conflicts::fix_label( (string) $fix );
		}
		$reading['tell'] = admin_url( 'admin.php?page=zinn-seo&view=feedback' );

		return $reading;
	}
}
