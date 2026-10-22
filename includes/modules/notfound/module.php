<?php
/**
 * Module: the 404 monitor — missing addresses as counters with a hard row ceiling, robots kept
 * apart, ignore list, suggested pages, one-click redirect, weekly e-mail (zs-093).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'notfound',
	'name'        => static fn() => __( '404 monitor', 'zinn-seo' ),
	'description' => static fn() => __( 'See which missing addresses visitors and search engines ask for, how often and from where — one counter per address, never a database row per visit — and redirect them in one click.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'redirects',
	'features'    => array( 'zs-093' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-notfound.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		if ( 'front' === $context ) {
			\ZinnDigital\ZinnSEO\Notfound\Notfound::boot_front();
			return;
		}
		add_action( \ZinnDigital\ZinnSEO\Notfound\Notfound::DIGEST, array( \ZinnDigital\ZinnSEO\Notfound\Notfound::class, 'digest' ) );
		add_action( 'zinn_seo_settings_saved', array( \ZinnDigital\ZinnSEO\Notfound\Notfound::class, 'sync_digest' ) );
		if ( in_array( $context, array( 'rest', 'admin', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-notfound-rest.php';
			\ZinnDigital\ZinnSEO\Notfound\Notfound_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo notfound',
				static function ( $args, $assoc ): void {
					$sub = (string) ( $args[0] ?? 'list' );
					if ( 'clear' === $sub ) {
						\WP_CLI::success( sprintf( '%d forgotten.', \ZinnDigital\ZinnSEO\Counters::forget( (string) ( $assoc['kind'] ?? '404' ) ) ) );
						return;
					}
					$page = \ZinnDigital\ZinnSEO\Counters::rows( (string) ( $assoc['kind'] ?? '404' ), (string) ( $assoc['order'] ?? 'hits' ), (int) ( $assoc['per'] ?? 50 ) );
					\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $page['items'], array( 'key', 'hits', 'first_seen', 'last_seen', 'sample' ) );
				},
				array(
					'shortdesc' => 'List the missing addresses (list [--kind=404|404b] [--order=hits|last_seen] [--per=50] [--format=table]) or forget them (clear [--kind=404]).',
				)
			);
		}
	},
);
