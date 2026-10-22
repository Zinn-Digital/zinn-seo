<?php
/**
 * Uninstall: remove every row this plugin created, unless the owner chose to keep it (zs-158).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything this plugin stores is named so it can be found again: options `zseo_*`, meta keys
 * `_zseo_*` (posts, terms, users), tables `{prefix}zseo_*`, cron hooks `zseo_*`, the two roles.
 * `wp/plugins.json` lists the same families under `db_state`, and the real-WordPress harness
 * (`zinn_seo.sh uninstall`) counts them after deleting the plugin: 0 rows, or the run fails.
 *
 * On multisite every site of the network is cleaned, because each site keeps its own copy.
 */
final class Uninstall {

	/**
	 * Run (from the licensing SDK's `after_uninstall`).
	 *
	 * @return void
	 */
	public static function run(): void {
		self::unhook_updater();
		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clean_site();
				restore_current_blog();
			}
			delete_site_option( 'zseo_network' );
			return;
		}
		self::clean_site();
	}

	/**
	 * Clean the current site, honouring its "keep data" choice.
	 *
	 * @return void
	 */
	public static function clean_site(): void {
		$settings = get_option( Settings::OPTION, array() );
		if ( is_array( $settings ) && ! empty( $settings['keep_data'] ) ) {
			return;
		}
		// A site connected to a Zinn® account tells Zinn® to retire its token before the token
		// is deleted here, so no working credential for the account outlives the plugin.
		$connection = __DIR__ . '/modules/connect/class-connection.php';
		if ( is_array( get_option( 'zseo_connect' ) ) && is_readable( $connection ) ) {
			require_once __DIR__ . '/modules/connect/class-client.php';
			require_once $connection;
			\ZinnDigital\ZinnSEO\Connect\Connection::disconnect();
		}
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall removes whole key families; there is no API for a LIKE delete and nothing to cache.
		// The Update Guard's test copies (Pro, zs-775): the database copies it still knows of, its
		// staging folder, and its must-use loader with the watch list.
		$copies = get_option( 'zseo_guard_copies', array() );
		foreach ( array_keys( is_array( $copies ) ? $copies : array() ) as $prefix ) {
			if ( 1 === preg_match( '/^zg[a-z0-9]{6}_$/', (string) $prefix ) ) {
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $prefix ) . '%' ) ) as $table ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseSchemaChange -- the plugin's own temporary copies.
				}
			}
		}
		foreach ( array( trailingslashit( WP_PLUGIN_DIR ) . 'zinn-seo-guard-stage', trailingslashit( WPMU_PLUGIN_DIR ) . 'zinn-seo-guard' ) as $dir ) {
			if ( is_dir( $dir ) ) {
				self::remove_tree( $dir );
			}
		}
		if ( is_file( trailingslashit( WPMU_PLUGIN_DIR ) . 'zinn-seo-guard.php' ) ) {
			wp_delete_file( trailingslashit( WPMU_PLUGIN_DIR ) . 'zinn-seo-guard.php' );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'zseo_' ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_zseo_' ) . '%', $wpdb->esc_like( '_transient_timeout_zseo_' ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( Meta::PREFIX ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( Meta::PREFIX ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( Meta::PREFIX ) . '%', $wpdb->esc_like( $wpdb->get_blog_prefix() . 'zseo_' ) . '%' ) );
		// This product's own licensing-SDK cache (the shared SDK options belong to every Freemius plugin).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'fs_cache_41086' ) );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'zseo_' ) . '%' ) );
		foreach ( (array) $tables as $table ) {
			$table = (string) $table;
			if ( 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseSchemaChange -- uninstall drops the plugin's own tables.
			}
		}
		// phpcs:enable

		// The locations a business with branches made and the SEO tasks (Pro): this plugin's own
		// content types, so
		// its pages go with it — a batch at a time, however many there are.
		// ⛔ By keyset (`ID > $after`), never "the first 200 again": a page another plugin refuses
		// to delete (a `pre_delete_post` filter) would be selected again for ever, and deleting
		// the plugin would never end.
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the type is not registered during uninstall, so no query API lists it.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND ID > %d ORDER BY ID ASC LIMIT %d", 'zseo_location', 'zseo_task', $after, 200 ) ) );
			$got = count( $ids );
			foreach ( $ids as $id ) {
				$after = $id;
				wp_delete_post( $id, true );
			}
		} while ( 200 === $got );

		$crons = _get_cron_array();
		foreach ( (array) $crons as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'zseo_' ) ) {
					wp_clear_scheduled_hook( (string) $hook );
				}
			}
		}
		Capabilities::remove_roles();
		self::remove_files();
		self::remove_backups();
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'zseo' );
		}
	}

	/**
	 * Stop this plugin's licensing-SDK updater from running later in this request.
	 *
	 * ⛔ Measured 2026-10-09 (zinn_seo_p1.sh, WP-CLI `plugin uninstall` on a site with Arabic
	 * installed): WordPress includes the plugin to run its uninstall, deletes its files, and WP-CLI
	 * then saves the plugin-update list — which runs the SDK's `pre_set_site_transient_update_*`
	 * filter from the now-deleted plugin, which requires a file that no longer exists: a PHP fatal
	 * after a successful uninstall. Only callbacks of THIS plugin's SDK instance are removed.
	 *
	 * @return void
	 */
	public static function unhook_updater(): void {
		global $wp_filter;
		if ( ! function_exists( 'zinn_seo_fs' ) || ! class_exists( 'FS_Plugin_Updater' ) || ! is_array( $wp_filter ) ) {
			return;
		}
		$ours = zinn_seo_fs();
		try {
			$property = new \ReflectionProperty( 'FS_Plugin_Updater', '_fs' );
		} catch ( \ReflectionException $e ) {
			return;
		}
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions -- needed below PHP 8.1, a no-op after.
		}
		foreach ( $wp_filter as $hook => $handlers ) {
			if ( ! $handlers instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $handlers->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'] ?? null;
					if ( is_array( $fn ) && isset( $fn[0] ) && $fn[0] instanceof \FS_Plugin_Updater && $property->getValue( $fn[0] ) === $ours ) {
						remove_filter( (string) $hook, $fn, (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * The backups the server rules editor (`wp-content/zseo-rules-<hash>/`) and the version
	 * rollback (`wp-content/zseo-rollback-<hash>/`) keep (zs-716, zs-718).
	 *
	 * @return void
	 */
	public static function remove_backups(): void {
		$dirs = array_merge(
			(array) glob( trailingslashit( WP_CONTENT_DIR ) . 'zseo-rules-*', GLOB_ONLYDIR ),
			(array) glob( trailingslashit( WP_CONTENT_DIR ) . 'zseo-rollback-*', GLOB_ONLYDIR )
		);
		foreach ( $dirs as $dir ) {
			if ( is_string( $dir ) && 1 === preg_match( '#/(zseo-rules|zseo-rollback)-[a-f0-9]{12}$#', $dir ) ) {
				self::remove_tree( $dir );
			}
		}
		// Site care's backups of the changes it made (Pro, zs-772..776). The code fixes it switched
		// on stay: they are the site's code now, and removing them could stop the site.
		$uploads = wp_get_upload_dir();
		foreach ( (array) glob( trailingslashit( (string) $uploads['basedir'] ) . 'zinn-seo-care-*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_string( $dir ) && 1 === preg_match( '#/zinn-seo-care-[a-z0-9]{20}$#', $dir ) ) {
				self::remove_tree( $dir );
			}
		}
	}

	/**
	 * Delete a folder of ours and everything in it.
	 *
	 * @param string $dir Folder.
	 * @return void
	 */
	private static function remove_tree( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( ! is_string( $name ) || '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_tree( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty, folder.
	}

	/**
	 * The plugin's own files under uploads: generated social cards (`zd-og/<post>-<hash>.jpg`),
	 * spreadsheets waiting to be imported (`zd-private/<token>.csv`), the sitemap files
	 * (`zd-private/sm/g<id>/*.xml`), the llms.txt and Markdown caches, and the blocks' stylesheet
	 * copies (`zd-css/<hash>.css`). Only names of the
	 * plugin's own shape are deleted, never anything else a folder may hold.
	 *
	 * @return void
	 */
	public static function remove_files(): void {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] );
		// Deepest first, so each folder is empty by the time it is checked.
		$shapes = array(
			'zd-og'           => '/^\d+-[a-f0-9]{8}\.jpg$/',
			'zd-private/llms' => '/^[a-z0-9-]+\.txt(\.tmp)?$/',
			'zd-private/md'   => '/^\d+-[a-f0-9]{8}\.md(\.tmp)?$/',
			// The SEO blocks' stylesheet copies (only used above the inline limit).
			'zd-css'          => '/^[a-f0-9]{12}\.css$/',
		);
		// No-trace mode keeps the blocks' stylesheets and the social cards in folders of the site's
		// own prefix (zs-747): `c<hash>-css`, `c<hash>-og`.
		if ( 1 === preg_match( '/^[a-z0-9]{6}$/', (string) Settings::get( 'notrace_hash', '' ) ) ) {
			$own                     = Notrace::prefix( 'zd' );
			$shapes[ $own . '-og' ]  = '/^\d+-[a-f0-9]{8}\.jpg$/';
			$shapes[ $own . '-css' ] = '/^[a-f0-9]{12}\.css$/';
		}
		// Sitemap generations: `zd-private/sm/g<8>/<name>.xml`.
		if ( is_dir( $base . 'zd-private/sm' ) ) {
			foreach ( (array) scandir( $base . 'zd-private/sm' ) as $gen ) {
				if ( is_string( $gen ) && 1 === preg_match( '/^g[a-z0-9]{8}$/', $gen ) ) {
					$shapes[ 'zd-private/sm/' . $gen ] = '/^[a-z0-9-]+\.(xml|tmp)$/';
				}
			}
			$shapes['zd-private/sm'] = '/^$/';
		}
		// The redirect index: `zd-private/rd/<shard>.json`, `regex.json`.
		$shapes['zd-private/rd'] = '/^(([a-f0-9]{3}|regex)\.json(\.[A-Za-z0-9]{6}\.tmp)?|index\.php)$/';
		$shapes['zd-private']    = '/^([a-z0-9]{32}\.csv|index\.php|\.htaccess)$/';
		// The Pro "fast redirects" must-use file (only ours).
		$dropin = trailingslashit( WPMU_PLUGIN_DIR ) . 'zd-redirects.php';
		if ( is_readable( $dropin ) && false !== strpos( (string) file_get_contents( $dropin ), 'redirects-pro/early.php' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local file.
			wp_delete_file( $dropin );
		}
		// The Pro `.htaccess` block (between its own markers only).
		$htaccess = ABSPATH . '.htaccess';
		if ( is_readable( $htaccess ) && is_writable( $htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- the site's own .htaccess.
			$body = (string) file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- see above.
			$kept = (string) preg_replace( '/# BEGIN ZD redirects\R.*?# END ZD redirects\R*/s', '', $body );
			if ( $kept !== $body ) {
				file_put_contents( $htaccess, $kept ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- see above.
			}
		}
		foreach ( $shapes as $dir => $shape ) {
			if ( ! is_dir( $base . $dir ) ) {
				continue;
			}
			foreach ( (array) scandir( $base . $dir ) as $name ) {
				if ( is_string( $name ) && 1 === preg_match( $shape, $name ) ) {
					wp_delete_file( $base . $dir . '/' . $name );
				}
			}
			if ( array( '.', '..' ) === array_values( (array) scandir( $base . $dir ) ) ) {
				rmdir( $base . $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty, folder.
			}
		}
	}
}
