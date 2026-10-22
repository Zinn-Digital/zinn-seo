<?php
/**
 * Module: Zinn® CDN — the site's images, CSS, JavaScript and fonts from a global CDN (zs-658).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'cdn',
	'name'        => static fn() => __( 'Zinn® CDN', 'zinn-seo' ),
	'description' => static fn() => __( 'Serve your images, CSS, scripts and fonts from a fast global CDN, switched on in one click and off just as fast. Paid from your Zinn® credit, only for what visitors download.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'technical',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-658' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-cdn.php';
		$cdn = \ZinnDigital\ZinnSEO\Cdn\Cdn::class;
		if ( 'front' === $context ) {
			// Late, after redirects and caching plugins have decided what to send.
			add_action( 'template_redirect', array( $cdn, 'front' ), PHP_INT_MAX );
			return;
		}
		add_action( 'zinn_seo_disconnected', array( $cdn, 'forget' ) );
		add_action( $cdn::PURGE_HOOK, array( $cdn, 'run_purge' ) );
		add_action( $cdn::WARM_HOOK, array( $cdn, 'warm' ) );
		add_action( $cdn::CRON, array( $cdn, 'refresh' ) );
		// Twice a day while it is on, so a CDN paused at Zinn® stops being used; nothing when off.
		$scheduled = wp_next_scheduled( $cdn::CRON );
		if ( $cdn::stored()['on'] && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', $cdn::CRON );
		} elseif ( ! $cdn::stored()['on'] && $scheduled ) {
			wp_clear_scheduled_hook( $cdn::CRON );
		}
		// A changed file must not be served stale from the CDN: purge a minute later.
		foreach ( array( 'edit_attachment', 'delete_attachment', 'switch_theme', 'upgrader_process_complete', 'customize_save_after' ) as $hook ) {
			add_action( $hook, array( $cdn, 'schedule_purge' ) );
		}
		add_filter(
			'wp_update_attachment_metadata',
			static function ( $data ) use ( $cdn ) {
				$cdn::schedule_purge();
				return $data;
			}
		);
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) use ( $cdn ) {
					$data        = (array) $data;
					$data['cdn'] = array( 'local' => $cdn::stored() );
					return $data;
				}
			);
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-cdn-rest.php';
			\ZinnDigital\ZinnSEO\Cdn\Cdn_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo cdn',
				static function ( $args ) use ( $cdn ): void {
					$sub = (string) ( $args[0] ?? 'status' );
					$map = array(
						'status' => 'status',
						'on'     => 'enable',
						'off'    => 'disable',
						'purge'  => 'purge',
					);
					if ( ! isset( $map[ $sub ] ) ) {
						\WP_CLI::error( 'Use: wp zinn-seo cdn status|on|off|purge' );
					}
					$out = call_user_func( array( $cdn, $map[ $sub ] ) );
					if ( is_wp_error( $out ) ) {
						\WP_CLI::error( $out->get_error_message() );
					}
					\WP_CLI::line( (string) wp_json_encode( $out ) );
				},
				array( 'shortdesc' => 'The Zinn® CDN for this site: status, on, off, purge.' )
			);
		}
	},
);
