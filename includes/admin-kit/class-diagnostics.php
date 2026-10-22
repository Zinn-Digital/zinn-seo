<?php
/**
 * The site facts a support ticket carries, shown to the site owner first.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-diagnostics.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnSEO\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the diagnostics a ticket may carry (feature sh-r6), in the shape the API expects
 * (x1-x7-contract.md §2.4 `diagnostics`).
 *
 * ⛔⛔ **THE PREVIEW IS THE FEATURE.** The screen renders exactly what collect() returns before
 * anything is sent, and the send path calls collect() again rather than trusting the browser, so
 * what leaves the site is what the owner was shown — nothing is added in between.
 *
 * ⛔ **Nothing secret by construction, not by pattern.** The report is built from a DECLARED
 * list of facts (versions, names, flags). No option is dumped, so there is nothing to redact
 * after the fact. The one free-text field is the error-log excerpt, and in it the site's own
 * file-system paths are replaced (`[site root]/…`), because a path can name a customer's account.
 */
final class Diagnostics {

	/** The most of the error log that is ever read or sent (contract: max 20000). */
	public const LOG_BYTES = 20000;

	/**
	 * Build the report.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		global $wp_version;

		$theme = wp_get_theme();

		$report = array(
			'site_url'          => home_url(),
			'wp_version'        => (string) $wp_version,
			'php_version'       => PHP_VERSION,
			'theme'             => trim( (string) $theme->get( 'Name' ) . ' ' . (string) $theme->get( 'Version' ) ),
			'plugins'           => self::plugins(),
			'plugin_version'    => Kit::host( 'version' ),
			'multisite'         => is_multisite(),
			'error_log_excerpt' => self::error_log_excerpt(),
			'extra'             => array(
				'locale'           => get_locale(),
				'https'            => is_ssl(),
				'environment'      => wp_get_environment_type(),
				'memory_limit'     => (string) ini_get( 'memory_limit' ),
				'max_execution'    => (string) ini_get( 'max_execution_time' ),
				'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'object_cache'     => wp_using_ext_object_cache(),
				'edition'          => Licence::tier(),
			),
		);

		/**
		 * Filters the diagnostics before the site owner sees them. A plugin adds its own facts
		 * under `extra`. ⛔ This runs BEFORE the preview, never between the preview and the send.
		 *
		 * @param array<string, mixed> $report The report.
		 */
		return (array) apply_filters( 'zseo_kit_diagnostics', $report );
	}

	/**
	 * Installed plugins: slug, version, active. Names and paths of the site's own files are not
	 * included, only the plugin directory slug.
	 *
	 * @return array<int, array{slug: string, version: string, active: bool}>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$file  = (string) $file;
			$out[] = array(
				'slug'    => false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => is_plugin_active( $file ),
			);
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => array( ! $a['active'], $a['slug'] ) <=> array( ! $b['active'], $b['slug'] )
		);

		return $out;
	}

	/**
	 * The end of the PHP error log, when WordPress is writing one this plugin may read.
	 *
	 * @return string Empty when there is no log.
	 */
	public static function error_log_excerpt(): string {
		$file = self::log_file();
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}
		$size = (int) filesize( $file );
		if ( $size <= 0 ) {
			return '';
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the tail of the site's own debug log; WP_Filesystem has no seek.
		if ( false === $handle ) {
			return '';
		}
		fseek( $handle, max( 0, $size - self::LOG_BYTES ) );
		$tail = (string) fread( $handle, self::LOG_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		if ( $size > self::LOG_BYTES ) {
			$newline = strpos( $tail, "\n" );
			$tail    = false === $newline ? $tail : substr( $tail, $newline + 1 ); // Start on a whole line.
		}

		return self::scrub( $tail );
	}

	/**
	 * Replace the site's own paths so a log line does not name the account it runs under.
	 *
	 * @param string $text Log text.
	 * @return string
	 */
	public static function scrub( string $text ): string {
		$paths = array_filter(
			array(
				untrailingslashit( ABSPATH ),
				defined( 'WP_CONTENT_DIR' ) ? untrailingslashit( WP_CONTENT_DIR ) : '',
			),
			static fn( string $path ): bool => strlen( $path ) > 1
		);
		foreach ( $paths as $path ) {
			$text = str_replace( $path, '[site root]', $text );
		}

		return wp_check_invalid_utf8( $text, true );
	}

	/**
	 * Where WordPress writes its debug log, or empty.
	 *
	 * @return string
	 */
	private static function log_file(): string {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return '';
		}
		if ( is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG && ! in_array( strtolower( WP_DEBUG_LOG ), array( '1', 'true' ), true ) ) {
			return WP_DEBUG_LOG;
		}

		return WP_CONTENT_DIR . '/debug.log';
	}
}
