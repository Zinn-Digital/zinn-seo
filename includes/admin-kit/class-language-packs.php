<?php
/**
 * Per-site language packs: the plugin's translations, fetched for the languages this site uses.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-language-packs.php by wp/bin/build-admin-kit.php.
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
 * Installs this plugin's translations for the site's language and its users' languages.
 *
 * ⛔⛔ WHY (ZIP1, Q187, 2026-10-13). Every translation used to ride inside the plugin zip: 57
 * locales x (`.mo` + `.l10n.php` + one `.json` per script bundle). The zip grew with every string
 * x 57, and Zinn® SEO Pro reached 28.44 MiB against the 30 MiB build cap (Freemius refuses 32 MB)
 * with three features still to land. Trimming catalogues only bought time. So a zip now carries
 * the source strings only, and each language arrives as a LANGUAGE PACK from the Zinn Digital
 * update channel, the way WordPress core fetches translate.wordpress.org packs: only the
 * languages this site actually uses, installed into `wp-content/languages/plugins/`, where
 * WordPress already looks first.
 *
 * ⛔⛔ AUTHENTICATED BY THE PLUGIN ITSELF, NOT BY A KEY. The zip carries
 * `languages/language-packs.json`: for every locale, the name, size and SHA-256 of every file
 * its pack holds. A pack is installed only when its files are EXACTLY that set, at exactly
 * those sizes and digests. The manifest arrived inside the plugin, which the site already
 * trusted enough to run, so a pack that matches it is the one the release built; a modified,
 * swapped, replayed (another version's) or padded pack is refused. No signing key exists that
 * could leak, and nothing on the channel can make a site accept a file the release did not
 * build.
 *
 * ⭐ English is the fallback, always. Nothing here can stop the plugin working: no fetch on the
 * front end, no admin notice, no fatal. A site that cannot reach the channel, cannot write
 * `wp-content/languages/` without FTP credentials, or uses a language we do not translate,
 * shows English and tries again later.
 *
 * ⭐ Only GETs a public URL naming the plugin, its version and the locale. It sends nothing
 * about the site. A build for the WordPress.org directory does not carry this file at all
 * (translate.wordpress.org serves those translations), and without a manifest it does nothing.
 */
final class Language_Packs {

	/** The manifest format this version reads. */
	public const FORMAT = 'zinn-language-packs/1';

	/** Where Zinn Digital serves the packs (`<URL>/<domain>/language-packs/<version>/<locale>`). */
	public const URL = 'https://api.zinndigital.com/v1/plugins';

	/** The largest pack this will download, in bytes. */
	public const MAX_PACK_BYTES = 16777216;

	/**
	 * The most packs one background run installs; the rest follow in the next run, at once.
	 * At the 15 s download timeout a run stays well inside the 300 s lock.
	 */
	public const PER_RUN = 8;

	/** How long to wait before retrying a locale that failed, in seconds (1 hour). */
	public const RETRY_AFTER = 3600;

	/** Installed state for this plugin: version, installed files per locale, failures. */
	private const STATE = 'zseo_kit_language_packs';

	/** One sync at a time per plugin. */
	private const LOCK = 'zseo_kit_language_packs_lock';

	/** The background sync (every wanted locale). */
	private const HOOK = 'zseo_kit_language_packs_sync';

	/**
	 * The version every wanted pack is installed for: autoloaded and a few bytes, so the check on
	 * every request (on_init()) costs no query.
	 */
	private const SYNCED = 'zseo_kit_language_packs_version';

	/**
	 * The manifest, per request.
	 *
	 * @var array<string, mixed>|false|null False when there is none.
	 */
	private static $manifest = null;

	/**
	 * A different manifest file (tests only).
	 *
	 * @var string|null
	 */
	private static ?string $manifest_file = null;

	/**
	 * Hook in. Does nothing for a build that carries no manifest.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( null === self::manifest() ) {
			return;
		}
		if ( did_action( 'init' ) ) {
			self::on_init();
		} else {
			add_action( 'init', array( __CLASS__, 'on_init' ) );
		}
		add_action( 'admin_init', array( __CLASS__, 'on_admin' ) );
		add_action( self::HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'update_option_WPLANG', array( __CLASS__, 'languages_changed' ), 10, 0 );
		add_action( 'add_option_WPLANG', array( __CLASS__, 'languages_changed' ), 10, 0 );
		add_action( 'added_user_meta', array( __CLASS__, 'user_meta_changed' ), 10, 3 );
		add_action( 'updated_user_meta', array( __CLASS__, 'user_meta_changed' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'languages_changed' ), 10, 0 );
		// The languages the site serves visitors (see served_locales()).
		add_action( 'add_option_tranzly_settings', array( __CLASS__, 'languages_changed' ), 10, 0 );
		add_action( 'update_option_tranzly_settings', array( __CLASS__, 'languages_changed' ), 10, 0 );
		add_action( 'add_option_trp_settings', array( __CLASS__, 'languages_changed' ), 10, 0 );
		add_action( 'update_option_trp_settings', array( __CLASS__, 'languages_changed' ), 10, 0 );
		add_action( 'pll_add_language', array( __CLASS__, 'languages_changed' ), 10, 0 );
	}

	/**
	 * Read the manifest from another file, or null to restore (tests only).
	 *
	 * @param string|null $path File.
	 * @return void
	 */
	public static function use_manifest( ?string $path ): void {
		self::$manifest_file = $path;
		self::$manifest      = null;
	}

	/**
	 * The manifest this build shipped, validated, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function manifest(): ?array {
		if ( null === self::$manifest ) {
			$file           = self::$manifest_file ?? dirname( Kit::host( 'file' ) ) . '/languages/language-packs.json';
			$raw            = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.
			$parsed         = self::parse_manifest( $raw, Kit::host( 'slug' ), Kit::host( 'version' ) );
			self::$manifest = null === $parsed ? false : $parsed;
		}

		return false === self::$manifest ? null : self::$manifest;
	}

	/**
	 * Validate a manifest. Pure: no I/O.
	 *
	 * @param string $raw     Manifest JSON.
	 * @param string $domain  The text domain it must be for.
	 * @param string $version The plugin version it must be for.
	 * @return array{format:string,domain:string,version:string,locales:array<string,array{files:array<string,array{bytes:int,sha256:string}>}>}|null
	 */
	public static function parse_manifest( string $raw, string $domain, string $version ): ?array {
		$data = json_decode( $raw, true );
		if ( '' === $domain || ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) || ! is_array( $data['locales'] ?? null )
			|| ( $data['domain'] ?? null ) !== $domain || ( $data['version'] ?? null ) !== $version ) {
			return null;
		}
		$locales = array();
		foreach ( $data['locales'] as $locale => $entry ) {
			$locale = (string) $locale;
			if ( ! self::is_locale( $locale ) || ! is_array( $entry ) || ! is_array( $entry['files'] ?? null ) || array() === $entry['files'] ) {
				return null;
			}
			$files = array();
			foreach ( $entry['files'] as $name => $file ) {
				$name = (string) $name;
				if ( ! self::is_pack_file( $name, $domain, $locale ) || ! is_array( $file )
					|| ! is_int( $file['bytes'] ?? null ) || $file['bytes'] < 0 || ! is_string( $file['sha256'] ?? null )
					|| 1 !== preg_match( '/^[0-9a-f]{64}$/', $file['sha256'] ) ) {
					return null;
				}
				$files[ $name ] = array(
					'bytes'  => $file['bytes'],
					'sha256' => $file['sha256'],
				);
			}
			if ( ! isset( $files[ $domain . '-' . $locale . '.mo' ] ) ) {
				return null;
			}
			$locales[ $locale ] = array( 'files' => $files );
		}

		return array(
			'format'  => self::FORMAT,
			'domain'  => $domain,
			'version' => $version,
			'locales' => $locales,
		);
	}

	/**
	 * Whether a string is a WordPress locale (`de_DE`, `ja`, `de_DE_formal`, `pt_PT_ao90`).
	 *
	 * @param string $locale Locale.
	 * @return bool
	 */
	public static function is_locale( string $locale ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/', $locale );
	}

	/**
	 * Whether a name is one a pack may install: `<domain>-<locale>.mo` or a script catalogue
	 * `<domain>-<locale>-<md5 or handle>.json`. Nothing with a path, nothing executable.
	 *
	 * @param string $name   File name.
	 * @param string $domain Text domain.
	 * @param string $locale Locale.
	 * @return bool
	 */
	public static function is_pack_file( string $name, string $domain, string $locale ): bool {
		$base = $domain . '-' . $locale;
		if ( $base . '.mo' === $name ) {
			return true;
		}

		return str_starts_with( $name, $base . '-' )
			&& 1 === preg_match( '/^[a-z0-9-]+\.json$/', substr( $name, strlen( $base ) + 1 ) );
	}

	/**
	 * Check a downloaded pack against the manifest. Pure: no I/O.
	 *
	 * @param array<string, string>                         $files    Name => bytes, as read from the pack.
	 * @param array<string, array{bytes:int,sha256:string}> $expected Name => size and digest, from the manifest.
	 * @return string Empty when the pack is exactly what the release built; otherwise why not.
	 */
	public static function pack_problem( array $files, array $expected ): string {
		if ( array() === $files ) {
			return 'empty';
		}
		$extra = array_diff_key( $files, $expected );
		if ( array() !== $extra ) {
			return 'unexpected file ' . (string) array_key_first( $extra );
		}
		foreach ( $expected as $name => $want ) {
			if ( ! array_key_exists( $name, $files ) ) {
				return 'missing ' . $name;
			}
			if ( strlen( $files[ $name ] ) !== $want['bytes'] || ! hash_equals( $want['sha256'], hash( 'sha256', $files[ $name ] ) ) ) {
				return 'digest mismatch ' . $name;
			}
		}

		return '';
	}

	/**
	 * The URL of one pack.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function pack_url( string $locale ): string {
		$manifest = (array) self::manifest();

		return self::base_url() . '/' . rawurlencode( (string) ( $manifest['domain'] ?? '' ) ) . '/language-packs/'
			. rawurlencode( (string) ( $manifest['version'] ?? '' ) ) . '/' . rawurlencode( $locale );
	}

	/**
	 * On every request: a version whose packs are not all installed yet queues the background sync.
	 *
	 * ⛔⛔ s1014 L71 (2026-10-18), the class of Tranzly 3.27.18. This used to happen only on
	 * `admin_init` and `upgrader_process_complete`. An update by WP-CLI or by a fleet updater
	 * (V1's own-plugin updater, staff site 244462) fires neither, so the packs stayed one version
	 * behind until somebody opened wp-admin: the site's languages read the previous release's
	 * strings, or none at all for a language added since (244462: tl missing after 3.27.18).
	 * The check is one autoloaded option compared with the manifest already read at load; the
	 * work itself runs in WP-Cron, never in the request.
	 *
	 * @return void
	 */
	public static function on_init(): void {
		$manifest = self::manifest();
		if ( null === $manifest || get_option( self::SYNCED ) === $manifest['version'] || wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * On an admin screen: make sure the language being shown is installed, now, and queue the rest.
	 *
	 * @return void
	 */
	public static function on_admin(): void {
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return;
		}
		$state = self::state();
		if ( $state['version'] !== $manifest['version'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		$locale = determine_locale();
		if ( ! isset( $manifest['locales'][ $locale ] ) || self::installed( $locale, $state ) || self::cooling( $locale, $state ) ) {
			return;
		}
		if ( '' === self::install_locales( array( $locale ) ) ) {
			// Strings already printed on this screen stay English; the rest of it, and every
			// screen after it, read the pack.
			load_textdomain( $manifest['domain'], self::target_dir() . '/' . $manifest['domain'] . '-' . $locale . '.mo', $locale );
		}
	}

	/**
	 * The site's language or a user's changed: forget waiting periods and fetch in the background.
	 *
	 * @return void
	 */
	public static function languages_changed(): void {
		$state           = self::state();
		$state['failed'] = array();
		self::save( $state );
		// ⛔ Now, not at a retry an hour away: a run that failed earlier leaves its retry
		// scheduled, and a language chosen since must not wait behind it (found by the harness).
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * A user's language changed.
	 *
	 * @param int    $meta_id  Meta row (unused).
	 * @param int    $user_id  User (unused).
	 * @param string $meta_key Key.
	 * @return void
	 */
	public static function user_meta_changed( $meta_id, $user_id, $meta_key ): void {
		unset( $meta_id, $user_id );
		if ( 'locale' === $meta_key ) {
			self::languages_changed();
		}
	}

	/**
	 * Install every wanted language this version does not have yet (the background run).
	 *
	 * @return string Empty on success, otherwise what failed.
	 */
	public static function sync(): string {
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return '';
		}
		$state   = self::state();
		$pending = array();
		$cooling = array();
		foreach ( self::wanted( $state ) as $locale ) {
			if ( ! self::installed( $locale, $state ) ) {
				// A language that failed recently goes last, so it can never starve the others.
				if ( self::cooling( $locale, $state ) ) {
					$cooling[] = $locale;
				} else {
					$pending[] = $locale;
				}
			}
		}
		$pending = array_merge( $pending, $cooling );
		$batch   = array_slice( $pending, 0, self::PER_RUN );
		$problem = array() === $batch ? '' : self::install_locales( $batch );
		$state   = self::state();
		if ( '' !== $problem ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + self::RETRY_AFTER, self::HOOK );
			}
		} elseif ( count( $pending ) > count( $batch ) ) {
			// ⭐ Bound the run, never the work: the rest go in the next run, now, not in an hour.
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_single_event( time(), self::HOOK );
		} else {
			$state['version'] = $manifest['version'];
			self::save( $state );
			update_option( self::SYNCED, $manifest['version'], true );
		}

		return $problem;
	}

	/**
	 * The locales this site uses that this version translates: the site's, every user's, the
	 * languages it serves visitors (served_locales()), and any installed before (so an update
	 * refreshes them).
	 *
	 * ⛔⛔ R25 (s1014 L63, 2026-10-17): this used to stop at the site's and the users' languages.
	 * A Tranzly site serving German pages switches the locale to de_DE on those pages, so our
	 * plugins' front-end strings were read from a German pack nothing had installed, and stayed
	 * English on every translated page (our own three plugin sites had 0 packs).
	 *
	 * @param array<string, mixed> $state Installed state.
	 * @return array<int, string>
	 */
	public static function wanted( array $state ): array {
		global $wpdb;
		$manifest = (array) self::manifest();
		$locales  = array( get_locale() => true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DISTINCT over usermeta's indexed meta_key, background only; no WordPress API returns the set of languages users chose.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", 'locale' ) ) as $user_locale ) {
			$locales[ (string) $user_locale ] = true;
		}
		foreach ( self::served_locales() as $served ) {
			$locales[ $served ] = true;
		}
		foreach ( array_keys( (array) ( $state['locales'] ?? array() ) ) as $before ) {
			$locales[ (string) $before ] = true;
		}

		return array_values( array_filter( array_keys( $locales ), static fn( $l ) => isset( $manifest['locales'][ $l ] ) ) );
	}

	/**
	 * The languages this site serves visitors through a multilingual plugin: Tranzly's, and
	 * TranslatePress's, Polylang's or WPML's when one of those runs. Read from each plugin's own
	 * setting or public API, so none of them has to be loaded first; unknown shapes add nothing.
	 *
	 * @return array<int, string> WordPress locales.
	 */
	public static function served_locales(): array {
		$found   = array();
		$tranzly = get_option( 'tranzly_settings' );
		foreach ( is_array( $tranzly ) && is_array( $tranzly['languages'] ?? null ) ? $tranzly['languages'] : array() as $row ) {
			$found[] = is_array( $row ) ? (string) ( $row['code'] ?? '' ) : '';
		}
		$trp = get_option( 'trp_settings' );
		foreach ( is_array( $trp ) && is_array( $trp['translation-languages'] ?? null ) ? $trp['translation-languages'] : array() as $code ) {
			$found[] = is_string( $code ) ? $code : '';
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list( array( 'fields' => 'locale' ) ) as $code ) {
				$found[] = is_string( $code ) ? $code : '';
			}
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API for its active languages, not a hook of ours.
		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		foreach ( is_array( $wpml ) ? $wpml : array() as $row ) {
			$found[] = is_array( $row ) ? (string) ( $row['default_locale'] ?? '' ) : '';
		}

		// ⛔ R27 (s1014): Tranzly stores Filipino as ISO `fil`; WordPress and our packs are `tl`.
		$found = array_map( static fn( string $l ): string => 1 === preg_match( '/^fil(?:_[A-Z]{2})?$/', $l ) ? 'tl' : $l, $found );

		return array_values( array_unique( array_filter( $found, static fn( string $l ): bool => self::is_locale( $l ) ) ) );
	}

	/**
	 * Download, verify and install packs. Holds a lock so two requests never write one file.
	 *
	 * @param array<int, string> $locales Locales the manifest carries.
	 * @return string Empty when every one installed, otherwise the first problem.
	 */
	private static function install_locales( array $locales ): string {
		if ( ! add_option( self::LOCK, (string) time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK, '0' ) < 300 ) {
				return 'busy';
			}
			update_option( self::LOCK, (string) time(), false );
		}
		$first = '';
		try {
			foreach ( $locales as $locale ) {
				$problem = self::install( $locale );
				$state   = self::state();
				if ( '' === $problem ) {
					unset( $state['failed'][ $locale ] );
				} else {
					$state['failed'][ $locale ] = time();
					$first                      = '' === $first ? $locale . ': ' . $problem : $first;
				}
				self::save( $state );
			}
		} finally {
			delete_option( self::LOCK );
		}

		return $first;
	}

	/**
	 * Download, verify and install one pack.
	 *
	 * @param string $locale Locale.
	 * @return string Empty on success, otherwise why not.
	 */
	private static function install( string $locale ): string {
		$manifest = (array) self::manifest();
		$expected = (array) ( $manifest['locales'][ $locale ]['files'] ?? array() );
		if ( array() === $expected ) {
			return 'not in this version';
		}
		$writer = self::filesystem();
		if ( null === $writer ) {
			return 'the languages folder cannot be written without credentials';
		}
		$files = self::download( $locale, $expected );
		if ( is_string( $files ) ) {
			return $files;
		}
		$problem = self::pack_problem( $files, $expected );
		if ( '' !== $problem ) {
			return 'refused: ' . $problem;
		}

		$dir = self::target_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return 'cannot create ' . $dir;
		}
		foreach ( $files as $name => $bytes ) {
			$temp = $dir . '/.' . $name . '.' . wp_generate_password( 8, false ) . '.tmp';
			if ( ! $writer->put_contents( $temp, $bytes, FS_CHMOD_FILE ) || ! $writer->move( $temp, $dir . '/' . $name, true ) ) {
				$writer->delete( $temp );
				return 'cannot write ' . $name;
			}
		}

		// ⭐ The fast `.l10n.php` form is made HERE from the verified `.mo` by WordPress's own
		// converter (6.5+), so a pack never carries anything PHP will execute. Where it cannot be
		// made, an older one is removed, or WordPress would keep reading last version's strings.
		$domain = (string) $manifest['domain'];
		$mo     = $dir . '/' . $domain . '-' . $locale . '.mo';
		$php    = $dir . '/' . $domain . '-' . $locale . '.l10n.php';
		$made   = class_exists( '\WP_Translation_File' ) ? \WP_Translation_File::transform( $mo, 'php' ) : false;
		if ( ( ! is_string( $made ) || '' === $made || ! $writer->put_contents( $php, $made, FS_CHMOD_FILE ) ) && $writer->exists( $php ) ) {
			$writer->delete( $php );
		}

		$state = self::state();
		foreach ( (array) ( $state['locales'][ $locale ]['files'] ?? array() ) as $old ) {
			if ( ! isset( $files[ $old ] ) && self::is_pack_file( (string) $old, $domain, $locale ) ) {
				$writer->delete( $dir . '/' . $old );
			}
		}
		$state['locales'][ $locale ] = array(
			'version' => (string) $manifest['version'],
			'files'   => array_keys( $files ),
		);
		self::save( $state );

		self::forget_file_list( $domain, $locale );

		return '';
	}

	/**
	 * Fetch a pack and read its files, refusing anything larger than the manifest says.
	 *
	 * @param string                                        $locale   Locale.
	 * @param array<string, array{bytes:int,sha256:string}> $expected Manifest files.
	 * @return array<string, string>|string Name => bytes, or why not.
	 */
	private static function download( string $locale, array $expected ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$temp = wp_tempnam( 'zinn-language-pack' );
		$args = array(
			'timeout'             => 15,
			'redirection'         => 0,
			'stream'              => true,
			'filename'            => $temp,
			'limit_response_size' => self::MAX_PACK_BYTES,
			'user-agent'          => 'ZinnLanguagePacks/1 (+https://zinndigital.com)',
		);
		// ⭐ The default channel is fetched with WordPress's SAFE request, which refuses private
		// and local addresses. A site owner who points ZINN_LANGUAGE_PACKS_URL somewhere else
		// (a mirror, a test harness) has chosen that host; the manifest still decides what installs.
		$response = self::URL === self::base_url() ? wp_safe_remote_get( self::pack_url( $locale ), $args ) : wp_remote_get( self::pack_url( $locale ), $args );
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp );
			return 'unreachable: ' . $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $temp );
			return 'HTTP ' . $code;
		}
		$files = self::read_zip( $temp, $expected );
		wp_delete_file( $temp );

		return $files;
	}

	/**
	 * Read a pack's files into memory, refusing any entry the manifest does not name or that is
	 * bigger than it says (so a crafted archive cannot inflate past what we expect).
	 *
	 * @param string                                        $path     Pack file.
	 * @param array<string, array{bytes:int,sha256:string}> $expected Manifest files.
	 * @return array<string, string>|string
	 */
	private static function read_zip( string $path, array $expected ) {
		$files = array();
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return 'refused: not a zip';
			}
			$count = (int) $zip->count();
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				$name = is_array( $stat ) ? (string) $stat['name'] : '';
				if ( ! isset( $expected[ $name ] ) || (int) $stat['size'] !== $expected[ $name ]['bytes'] ) {
					$zip->close();
					return 'refused: unexpected entry ' . $name;
				}
				$files[ $name ] = (string) $zip->getFromIndex( $i );
			}
			$zip->close();

			return $files;
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new \PclZip( $path );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			return 'refused: not a zip';
		}
		foreach ( $list as $item ) {
			$name = (string) $item['filename'];
			if ( ! isset( $expected[ $name ] ) || (int) $item['size'] !== $expected[ $name ]['bytes'] ) {
				return 'refused: unexpected entry ' . $name;
			}
		}
		$read = $archive->extract( PCLZIP_OPT_EXTRACT_AS_STRING );
		foreach ( is_array( $read ) ? $read : array() as $item ) {
			$files[ (string) $item['filename'] ] = (string) $item['content'];
		}

		return $files;
	}

	/**
	 * Whether a locale is installed for this version and its files are still there.
	 *
	 * @param string               $locale Locale.
	 * @param array<string, mixed> $state  Installed state.
	 * @return bool
	 */
	private static function installed( string $locale, array $state ): bool {
		$manifest = (array) self::manifest();
		$entry    = (array) ( $state['locales'][ $locale ] ?? array() );

		return ( $entry['version'] ?? '' ) === ( $manifest['version'] ?? null )
			&& is_readable( self::target_dir() . '/' . $manifest['domain'] . '-' . $locale . '.mo' );
	}

	/**
	 * Whether a locale failed recently (and should not be retried on every screen).
	 *
	 * @param string               $locale Locale.
	 * @param array<string, mixed> $state  Installed state.
	 * @return bool
	 */
	private static function cooling( string $locale, array $state ): bool {
		$at = (int) ( $state['failed'][ $locale ] ?? 0 );

		return $at > 0 && time() - $at < self::RETRY_AFTER;
	}

	/**
	 * The installed state.
	 *
	 * @return array{version:string,locales:array<string,mixed>,failed:array<string,int>}
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'version' => (string) ( $state['version'] ?? '' ),
			'locales' => is_array( $state['locales'] ?? null ) ? $state['locales'] : array(),
			'failed'  => is_array( $state['failed'] ?? null ) ? $state['failed'] : array(),
		);
	}

	/**
	 * Save the installed state (not autoloaded: only admin screens and the background run read it).
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function save( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Where WordPress looks first for a plugin's translations.
	 *
	 * @return string
	 */
	private static function target_dir(): string {
		return WP_LANG_DIR . '/plugins';
	}

	/**
	 * The channel's base URL.
	 *
	 * @return string
	 */
	private static function base_url(): string {
		$custom = defined( 'ZINN_LANGUAGE_PACKS_URL' ) ? (string) constant( 'ZINN_LANGUAGE_PACKS_URL' ) : '';

		return '' !== $custom ? untrailingslashit( $custom ) : self::URL;
	}

	/**
	 * WordPress's filesystem, when it can write without asking for credentials.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem(): ?\WP_Filesystem_Base {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), WP_LANG_DIR ) || ! WP_Filesystem() ) {
			return null;
		}
		global $wp_filesystem;

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Tell WordPress the languages folder changed (it caches the list of files for an hour).
	 *
	 * @param string $domain Text domain.
	 * @param string $locale Locale.
	 * @return void
	 */
	private static function forget_file_list( string $domain, string $locale ): void {
		global $wp_textdomain_registry;
		if ( is_object( $wp_textdomain_registry ) && method_exists( $wp_textdomain_registry, 'invalidate_mo_files_cache' ) ) {
			$wp_textdomain_registry->invalidate_mo_files_cache(
				null,
				array(
					'type'         => 'translation',
					'translations' => array(
						array(
							'type'     => 'plugin',
							'slug'     => $domain,
							'language' => $locale,
						),
					),
				)
			);
		}
	}
}
