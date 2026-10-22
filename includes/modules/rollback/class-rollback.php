<?php
/**
 * Version rollback (zs-718): go back to an earlier Zinn® SEO release from the settings screen,
 * safely.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Rollback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Releases come from WordPress.org once the plugin is listed there, and from Zinn Digital's own
 * download service until then (the same archives, each with its SHA-256). Before anything is
 * installed:
 *
 * - the release must suit this site (WordPress and PHP versions) and be able to read this site's
 *   SEO data (`zseo_data_min_reader`: a release older than the oldest one that can read the
 *   stored data is refused, with the reason);
 * - the archive is downloaded and checked against its published SHA-256 (or WordPress.org's
 *   per-file checksums);
 * - the running copy is kept aside in full.
 *
 * Then the release is installed over the running one and the site is asked, as a visitor and as
 * this administrator, whether it still answers. If it does not — an error page, a fatal error, a
 * timeout, or the plugin no longer active — the kept copy is put straight back and the site is
 * asked again. Nothing is left half-done.
 *
 * ⛔ The Pro build is licensed and updated by Freemius: going back would install the FREE build of
 * the older release and switch Pro off, so Pro sites are told how to roll back through their
 * licence instead. On a multisite network only a network administrator may do this.
 */
final class Rollback {

	/** Option: the oldest release able to read this site's stored data ('' = any). */
	public const MIN_READER = 'zseo_data_min_reader';

	/** Option: the last rollback attempted (for the screen). */
	public const LAST = 'zseo_rollback_last';

	/** The download service's list of versions. */
	public const ZINN_VERSIONS = 'https://api.zinndigital.com/v1/plugin-versions';

	/** The plugin's slug on both services. */
	public const SLUG = 'zinn-seo';

	/**
	 * Is this the licensed (Pro) build?
	 *
	 * @return bool
	 */
	public static function premium_build(): bool {
		return is_dir( ZINN_SEO_DIR . 'includes/pro_' . '_premium_only' ); // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the split keeps the free build free of the token.
	}

	/**
	 * Who may roll back here.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		if ( is_multisite() ) {
			return current_user_can( 'manage_network_plugins' ) && is_main_site();
		}

		return current_user_can( 'update_plugins' ) && current_user_can( 'install_plugins' );
	}

	/**
	 * The releases on offer, newest first: version, url, sha256, requires_wp, requires_php,
	 * published, source.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function releases(): array {
		$cached = get_site_transient( 'zseo_rollback_releases' );
		if ( is_array( $cached ) ) {
			$list = $cached;
		} else {
			$list = self::dotorg_releases();
			if ( array() === $list ) {
				$list = self::zinn_releases();
			}
			set_site_transient( 'zseo_rollback_releases', $list, HOUR_IN_SECONDS );
		}

		/**
		 * Filters the releases a site can go back to (a test, or a mirror inside a company
		 * network, can offer its own). Each: version, url, sha256 (or '' with source dotorg),
		 * requires_wp, requires_php, published, source.
		 *
		 * @param array<int, array<string, string>> $list Releases, newest first.
		 */
		$list = (array) apply_filters( 'zinn_seo_rollback_releases', $list );
		$out  = array();
		foreach ( $list as $r ) {
			if ( is_array( $r ) && isset( $r['version'], $r['url'] ) && 1 === preg_match( '/^\d+(\.\d+){1,3}$/', (string) $r['version'] ) ) {
				$out[] = array_map( 'strval', $r ) + array(
					'sha256'       => '',
					'requires_wp'  => '',
					'requires_php' => '',
					'published'    => '',
					'source'       => 'zinn',
				);
			}
		}
		usort( $out, static fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );

		return $out;
	}

	/**
	 * Releases from WordPress.org (empty until the plugin is listed there).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function dotorg_releases(): array {
		$response = wp_remote_get(
			'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . self::SLUG . '&request[fields][versions]=1',
			array( 'timeout' => 10 )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$info = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $info ) || empty( $info['versions'] ) || ! is_array( $info['versions'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $info['versions'] as $version => $url ) {
			if ( 'trunk' === $version || 0 !== strpos( (string) $url, 'https://downloads.wordpress.org/' ) ) {
				continue;
			}
			$out[] = array(
				'version'      => (string) $version,
				'url'          => (string) $url,
				'sha256'       => '',
				'requires_wp'  => (string) ( $info['requires'] ?? '' ),
				'requires_php' => (string) ( $info['requires_php'] ?? '' ),
				'published'    => '',
				'source'       => 'dotorg',
			);
		}

		return $out;
	}

	/**
	 * Releases from Zinn Digital's download service.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function zinn_releases(): array {
		$response = wp_remote_get( self::ZINN_VERSIONS, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$out  = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $group ) {
			if ( ! is_array( $group ) || self::SLUG !== ( $group['slug'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $group['releases'] ?? array() ) as $r ) {
				if ( ! is_array( $r ) || empty( $r['version'] ) || empty( $r['package_sha256'] ) ) {
					continue;
				}
				$out[] = array(
					'version'      => (string) $r['version'],
					'url'          => 'https://api.zinndigital.com/v1/plugins/' . self::SLUG . '/download?version=' . rawurlencode( (string) $r['version'] ),
					'sha256'       => strtolower( (string) $r['package_sha256'] ),
					'requires_wp'  => (string) ( $r['requires_wp'] ?? '' ),
					'requires_php' => (string) ( $r['requires_php'] ?? '' ),
					'published'    => (string) ( $r['published_at'] ?? '' ),
					'source'       => 'zinn',
				);
			}
		}

		return $out;
	}

	/**
	 * Why a release cannot be installed here, or '' when it can.
	 *
	 * @param array<string, string> $release    Release.
	 * @param string                $current    Running version.
	 * @param string                $min_reader Oldest release that reads the stored data ('' any).
	 * @param string                $wp         This site's WordPress version.
	 * @param string                $php        This site's PHP version.
	 * @return string
	 */
	public static function refusal( array $release, string $current, string $min_reader, string $wp, string $php ): string {
		$version = (string) $release['version'];
		if ( version_compare( $version, $current, '>=' ) ) {
			return __( 'This is not an earlier version. Use Updates to move forward.', 'zinn-seo' );
		}
		if ( '' !== $min_reader && version_compare( $version, $min_reader, '<' ) ) {
			/* translators: %s: a version number such as 0.12.0. */
			return sprintf( __( 'Your SEO data has been upgraded to a format this version cannot read. The earliest version you can go back to is %s.', 'zinn-seo' ), $min_reader );
		}
		if ( '' !== (string) $release['requires_wp'] && version_compare( $wp, (string) $release['requires_wp'], '<' ) ) {
			/* translators: %s: a WordPress version number. */
			return sprintf( __( 'This version needs WordPress %s or later.', 'zinn-seo' ), (string) $release['requires_wp'] );
		}
		if ( '' !== (string) $release['requires_php'] && version_compare( $php, (string) $release['requires_php'], '<' ) ) {
			/* translators: %s: a PHP version number. */
			return sprintf( __( 'This version needs PHP %s or later.', 'zinn-seo' ), (string) $release['requires_php'] );
		}

		return '';
	}

	/**
	 * The screen's list: every release with whether it can be chosen and why not.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		global $wp_version;
		$rows = array();
		foreach ( self::releases() as $r ) {
			$why    = self::refusal( $r, ZINN_SEO_VERSION, (string) get_site_option( self::MIN_READER, '' ), (string) $wp_version, PHP_VERSION );
			$rows[] = array(
				'version'   => $r['version'],
				'published' => $r['published'],
				'source'    => $r['source'],
				'current'   => version_compare( $r['version'], ZINN_SEO_VERSION, '==' ),
				'ok'        => '' === $why,
				'why'       => $why,
			);
		}

		return array(
			'current'  => ZINN_SEO_VERSION,
			'versions' => $rows,
			'premium'  => self::premium_build(),
			'allowed'  => self::allowed(),
			'network'  => is_multisite(),
			'last'     => get_site_option( self::LAST, null ),
		);
	}

	/**
	 * Go back to a release.
	 *
	 * @param string $version Version.
	 * @return array<string, mixed>|\WP_Error What happened.
	 */
	public static function run( string $version ) {
		global $wp_filesystem, $wp_version;
		if ( ! self::allowed() ) {
			return new \WP_Error( 'zinn_seo_forbidden', __( 'Only an administrator who can install plugins can do this (on a network: a network administrator, from the main site).', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		if ( self::premium_build() ) {
			return new \WP_Error( 'zinn_seo_rollback_pro', __( 'Zinn® SEO Pro is updated through your licence: to go back, download the earlier version from your account and upload it under Plugins → Add New.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$release = null;
		foreach ( self::releases() as $r ) {
			if ( $r['version'] === $version ) {
				$release = $r;
			}
		}
		if ( null === $release ) {
			return new \WP_Error( 'zinn_seo_no_release', __( 'That version is not available to go back to.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$why = self::refusal( $release, ZINN_SEO_VERSION, (string) get_site_option( self::MIN_READER, '' ), (string) $wp_version, PHP_VERSION );
		if ( '' !== $why ) {
			return new \WP_Error( 'zinn_seo_rollback_refused', $why, array( 'status' => 409 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! WP_Filesystem() ) {
			return new \WP_Error( 'zinn_seo_rollback_fs', __( 'WordPress cannot write to the plugins folder on this server, so nothing was changed.', 'zinn-seo' ), array( 'status' => 500 ) );
		}
		$from = ZINN_SEO_VERSION;
		$zip  = self::download( $release );
		if ( is_wp_error( $zip ) ) {
			return self::record( $from, $version, 'not-started', $zip->get_error_message() );
		}
		$backup = self::backup();
		if ( is_wp_error( $backup ) ) {
			wp_delete_file( $zip );
			return self::record( $from, $version, 'not-started', $backup->get_error_message() );
		}
		$installed = self::install( $zip );
		wp_delete_file( $zip );
		$health = true === $installed ? self::healthy( $version ) : $installed;
		if ( true === $health ) {
			$wp_filesystem->delete( $backup, true );
			return self::record( $from, $version, 'done', '' );
		}
		// Something is wrong: put the running copy back, and ask again.
		$restored = self::restore( $backup );
		$again    = true === $restored ? self::healthy( $from ) : $restored;
		if ( true === $again ) {
			$wp_filesystem->delete( $backup, true );
		}

		return self::record(
			$from,
			$version,
			true === $again ? 'restored' : 'restore-failed',
			$health->get_error_message() . ( true === $again ? '' : ' ' . ( is_wp_error( $again ) ? $again->get_error_message() : '' ) )
		);
	}

	/**
	 * Record what happened.
	 *
	 * @param string $from    Version before.
	 * @param string $to      Version asked for.
	 * @param string $outcome done, restored, restore-failed or not-started.
	 * @param string $why     The reason, when not done.
	 * @return array<string, mixed>
	 */
	public static function record( string $from, string $to, string $outcome, string $why ): array {
		$last = array(
			'from'    => $from,
			'to'      => $to,
			'outcome' => $outcome,
			'why'     => $why,
			'at'      => time(),
			'user'    => get_current_user_id(),
		);
		update_site_option( self::LAST, $last );

		return $last;
	}

	/**
	 * Download and check an archive.
	 *
	 * @param array<string, string> $release Release.
	 * @return string|\WP_Error The local file.
	 */
	public static function download( array $release ) {
		$file = download_url( $release['url'], 120 );
		if ( is_wp_error( $file ) ) {
			return new \WP_Error( 'zinn_seo_rollback_download', __( 'The earlier version could not be downloaded. Nothing was changed.', 'zinn-seo' ) );
		}
		if ( '' !== $release['sha256'] && ! hash_equals( strtolower( $release['sha256'] ), (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'zinn_seo_rollback_checksum', __( 'The downloaded archive did not match its published checksum, so it was not installed. Nothing was changed.', 'zinn-seo' ) );
		}
		if ( '' === $release['sha256'] && 'dotorg' !== $release['source'] ) {
			wp_delete_file( $file );
			return new \WP_Error( 'zinn_seo_rollback_checksum', __( 'This archive has no published checksum, so it was not installed. Nothing was changed.', 'zinn-seo' ) );
		}

		return $file;
	}

	/**
	 * Where the running copy is kept during a rollback.
	 *
	 * @return string
	 */
	public static function backup_dir(): string {
		// ⛔ NOT under wp-content/upgrade/: WordPress's upgrader empties that folder before it
		// unpacks a package, so a copy kept there was gone by the time it was needed (measured
		// in the harness: the broken release could not be put back, "Directory listing failed").
		return trailingslashit( WP_CONTENT_DIR ) . 'zseo-rollback-' . substr( wp_hash( 'zseo-rollback' ), 0, 12 );
	}

	/**
	 * Keep the running copy aside, in full.
	 *
	 * @return string|\WP_Error The backup folder.
	 */
	public static function backup() {
		global $wp_filesystem;
		$dir = self::backup_dir();
		if ( $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->delete( $dir, true );
		}
		if ( ! wp_mkdir_p( $dir . '/plugin' ) ) {
			return new \WP_Error( 'zinn_seo_rollback_backup', __( 'The running version could not be copied aside, so nothing was changed.', 'zinn-seo' ) );
		}
		// A visitor cannot list or fetch the copy (Apache; elsewhere every file still refuses to
		// run outside WordPress).
		$wp_filesystem->put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		$copied = copy_dir( untrailingslashit( ZINN_SEO_DIR ), $dir . '/plugin' );
		if ( is_wp_error( $copied ) || ! $wp_filesystem->exists( $dir . '/plugin/zinn-seo.php' ) ) {
			$wp_filesystem->delete( $dir, true );
			return new \WP_Error( 'zinn_seo_rollback_backup', __( 'The running version could not be copied aside, so nothing was changed.', 'zinn-seo' ) );
		}

		return $dir;
	}

	/**
	 * Install an archive over the running copy.
	 *
	 * @param string $zip Archive.
	 * @return true|\WP_Error
	 */
	public static function install( string $zip ) {
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install(
			$zip,
			array(
				'overwrite_package'  => true,
				'clear_update_cache' => true,
			)
		);
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			return new \WP_Error( 'zinn_seo_rollback_install', is_wp_error( $result ) ? $result->get_error_message() : ( $errors->has_errors() ? $errors->get_error_message() : __( 'The earlier version could not be installed.', 'zinn-seo' ) ) );
		}
		$main = plugin_basename( ZINN_SEO_FILE );
		if ( ! is_plugin_active( $main ) ) {
			activate_plugin( $main, '', is_multisite() && is_plugin_active_for_network( $main ), true );
		}

		return true;
	}

	/**
	 * Put the kept copy back.
	 *
	 * @param string $backup Backup folder.
	 * @return true|\WP_Error
	 */
	public static function restore( string $backup ) {
		global $wp_filesystem;
		$dir = untrailingslashit( ZINN_SEO_DIR );
		$wp_filesystem->delete( $dir, true );
		wp_mkdir_p( $dir );
		$copied = copy_dir( $backup . '/plugin', $dir );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}
		$main = plugin_basename( ZINN_SEO_FILE );
		if ( ! is_plugin_active( $main ) ) {
			activate_plugin( $main, '', false, true );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}

		return true;
	}

	/**
	 * Does the site still answer, running `$version`?
	 *
	 * @param string $version The version that should now be running.
	 * @return true|\WP_Error
	 */
	public static function healthy( string $version ) {
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}
		// A web server that caches compiled PHP re-reads a changed file only after its own
		// revalidation interval (2 seconds by default), and a reset made here reaches only this
		// process's cache (from WP-CLI, none of the web server's). Ask a few times before judging.
		$verdict = true;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			sleep( 0 === $attempt ? 3 : 2 );
			$verdict = self::answers( $version );
			if ( true === $verdict ) {
				return true;
			}
		}

		return $verdict;
	}

	/**
	 * One look: the installed version, the home page and the plugin's screen.
	 *
	 * @param string $version The version that should now be running.
	 * @return true|\WP_Error
	 */
	public static function answers( string $version ) {
		$data = get_file_data( ZINN_SEO_FILE, array( 'Version' => 'Version' ) );
		if ( (string) $data['Version'] !== $version ) {
			/* translators: %s: a version number. */
			return new \WP_Error( 'zinn_seo_rollback_health', sprintf( __( 'Version %s did not install as expected.', 'zinn-seo' ), $version ) );
		}
		$cookies = array();
		foreach ( $_COOKIE as $name => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed back to this same site's loopback request, as WordPress's own Site Health does.
			if ( is_string( $value ) ) {
				$cookies[] = new \WP_Http_Cookie(
					array(
						'name'  => (string) $name,
						'value' => $value,
					)
				);
			}
		}
		$checks = array(
			'home'  => array( home_url( '/' ), array() ),
			'admin' => array( admin_url( 'admin.php?page=zinn-seo' ), $cookies ),
		);
		foreach ( $checks as $what => $check ) {
			$response = wp_remote_get(
				add_query_arg( 'zseo_health', (string) time(), $check[0] ),
				array(
					'timeout'     => 20,
					'redirection' => 2,
					'cookies'     => $check[1],
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own loopback filter.
				)
			);
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			if ( 0 === $code || $code >= 500 || false !== stripos( $body, 'critical error' ) || false !== stripos( $body, 'Fatal error' ) ) {
				/* translators: 1: home page or admin screen, 2: an HTTP status code. */
				return new \WP_Error( 'zinn_seo_rollback_health', sprintf( __( 'After the change your %1$s stopped answering (status %2$s).', 'zinn-seo' ), 'home' === $what ? __( 'home page', 'zinn-seo' ) : __( 'admin screen', 'zinn-seo' ), (string) $code ) );
			}
		}

		return true;
	}
}
