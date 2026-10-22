<?php
/**
 * The Integrations screen's data, REST routes and WP-CLI command.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET zinn-seo/v1/integrations` lists every supported plugin with whether it is on this site and
 * working; `POST zinn-seo/v1/integrations/<id>` switches one off or back on. Both need
 * `zseo_manage`. `wp zinn-seo integrations list|on|off|loaded` does the same from a shell.
 */
final class Integrations_Rest {

	/**
	 * Hook in (admin, REST and WP-CLI requests only).
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function init( string $context ): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_admin_data', array( self::class, 'admin_data' ) );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'zinn-seo integrations', array( self::class, 'cli' ) );
		}
	}

	/**
	 * May the current user change which integrations run?
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		register_rest_route(
			Plugin::REST_NS,
			'/integrations',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'can' ),
				'callback'            => static fn() => rest_ensure_response( self::payload() ),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/integrations/(?P<id>[a-z0-9-]{1,40})',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'on' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$result = self::set( (string) $request['id'], (bool) $request['on'] );

					return is_wp_error( $result ) ? $result : rest_ensure_response( self::payload() );
				},
			)
		);
	}

	/**
	 * What the screen shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		return array(
			'items' => Integrations::describe(),
			'pro'   => Plugin::is_pro(),
		);
	}

	/**
	 * Switch one integration off, or back on.
	 *
	 * @param string $id Integration.
	 * @param bool   $on On.
	 * @return true|\WP_Error
	 */
	public static function set( string $id, bool $on ) {
		if ( ! isset( Integrations::registry()[ $id ] ) ) {
			/* translators: %s: an integration's id. */
			return new \WP_Error( 'zinn_seo_unknown_integration', sprintf( __( 'There is no integration called %s.', 'zinn-seo' ), $id ), array( 'status' => 404 ) );
		}
		$off = array_values( array_diff( array_map( 'strval', (array) Settings::get( 'integrations_off', array() ) ), array( $id ) ) );
		if ( ! $on ) {
			$off[] = $id;
		}
		$saved = Settings::save( array( 'integrations_off' => $off ) );

		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * Boot data for the admin app.
	 *
	 * @param mixed $data Data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data                 = (array) $data;
		$data['integrations'] = self::payload();
		$data['restRoot']     = esc_url_raw( rest_url() );

		return $data;
	}

	/**
	 * The plugins Zinn® SEO works with on this site.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list (every supported plugin), loaded (those running in this request), on or off.
	 *
	 * [<id>]
	 * : The integration, for on and off.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public static function cli( array $args ): void {
		$action = (string) ( $args[0] ?? 'list' );
		if ( 'loaded' === $action ) {
			\WP_CLI::line( (string) wp_json_encode( Integrations::started() ) );
			return;
		}
		if ( 'on' === $action || 'off' === $action ) {
			$result = self::set( (string) ( $args[1] ?? '' ), 'on' === $action );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::success( 'on' === $action ? __( 'Switched on.', 'zinn-seo' ) : __( 'Switched off.', 'zinn-seo' ) );
			return;
		}
		\WP_CLI::line( (string) wp_json_encode( Integrations::describe(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
