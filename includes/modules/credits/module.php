<?php
/**
 * Module: credit — the balance, the 30-day forecast, what is paused, buying more (zs-180, zs-inn-04).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'credits',
	'name'        => static fn() => __( 'Credit', 'zinn-seo' ),
	'description' => static fn() => __( 'See your credit, how long it lasts at your current pace and what each check costs, and add more in two clicks. No subscription: you pay for what you run.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-180', 'zs-inn-04', 'zs-746' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-credits.php';
		add_action( 'zinn_seo_disconnected', array( \ZinnDigital\ZinnSEO\Credits\Credits::class, 'forget' ) );
		add_action( 'zinn_seo_connected', array( \ZinnDigital\ZinnSEO\Credits\Credits::class, 'forget' ) );
		// News about the credit (ran out, came back, running low) makes the kept balance wrong: found
		// live — a site showed "paused, $0.00" for minutes after the top-up had resumed its checks.
		add_action( 'zinn_seo_credit_news', array( \ZinnDigital\ZinnSEO\Credits\Credits::class, 'forget' ) );
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			$routes = static function (): void {
				static $done = false;
				if ( $done ) {
					return;
				}
				$done = true;
				register_rest_route(
					\ZinnDigital\ZinnSEO\Plugin::REST_NS,
					'/credits',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( \ZinnDigital\ZinnSEO\Credits\Credits::account( (bool) $r['fresh'] ) ),
						// Balance and prices are the owner's business: people who may spend, or manage the site's SEO.
						'permission_callback' => static fn(): bool => current_user_can( \ZinnDigital\ZinnSEO\Capabilities::SPEND ) || current_user_can( \ZinnDigital\ZinnSEO\Capabilities::MANAGE ),
						'args'                => array(
							'fresh' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
					)
				);
			};
			\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( $routes );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo credits',
				static function (): void {
					$account = \ZinnDigital\ZinnSEO\Credits\Credits::account( true );
					if ( is_wp_error( $account ) ) {
						\WP_CLI::error( $account->get_error_message() );
					}
					\WP_CLI::line( (string) wp_json_encode( $account ) );
				},
				array( 'shortdesc' => 'The connected Zinn® account\'s credit: balance, 30-day forecast, paused checks, prices.' )
			);
		}
	},
);
