<?php
/**
 * Feature modules, found on disk and booted only where they are needed (architecture §1.2).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module is a directory `includes/modules/<id>/` with a `module.php` that RETURNS its
 * definition (and declares nothing), so loading the list costs one include per module and no hook.
 * The definition's keys: `id`; `name` and `description` (strings, or callables so they are
 * translated only when shown); `default` (on for a new site); `tier` (free, pro or agency);
 * `requires` (other module ids); `features` (the catalogue ids it implements, which the feature
 * registry reads); `contexts` (front, admin, rest, cli, cron: where it boots); `locked` (cannot be
 * switched off); `files` (the module's own class files, relative to its directory, loaded only
 * when it boots — zs-715); and `boot`, a callable that receives the context and registers the hooks.
 *
 * ⭐ zs-715: a switched-off module costs its definition and nothing else. Its `files` are never
 * read, its `boot` never runs, so it adds no hook, no query and no code to any request. Its
 * settings stay stored (their keys are part of the schema whether it is on or off), so switching
 * it back on finds everything as it was. `module.php` therefore declares data only: the settings
 * and per-object fields come from data files (`settings.php`, `meta.php`), never from a class.
 *
 * Premium modules live in `includes/pro__premium_only/modules/<id>/`, which the free package does
 * not contain, and boot only when the licence allows (`Plugin::is_pro()`).
 *
 * ⭐ Discovery is by directory, in sorted order, never by a list: two agents adding two modules
 * never edit the same file. A module that is switched off costs one array read.
 */
final class Modules {

	/**
	 * Every module found, by id.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $all = null;

	/**
	 * Booted modules: id => the contexts each was booted for.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static array $booted = array();

	/**
	 * Modules whose code was asked for in this request (load()): id => true.
	 *
	 * @var array<string, bool>
	 */
	private static array $loaded = array();

	/**
	 * Set while the module list is being read.
	 *
	 * @var bool
	 */
	private static bool $discovering = false;

	/**
	 * The directories modules are read from (free, then premium when it ships).
	 *
	 * @return array<int, string>
	 */
	public static function roots(): array {
		$roots = array( ZINN_SEO_DIR . 'includes/modules' );
		// The directory name is split so the free package never carries the premium token.
		$pro = ZINN_SEO_DIR . 'includes/pro_' . '_premium_only/modules'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
		if ( is_dir( $pro ) ) {
			$roots[] = $pro;
		}

		/**
		 * Filters the directories modules are read from. An add-on plugin can ship its own modules
		 * by adding its directory; the first directory is the free one, every later one is treated
		 * as premium (booted only under a licence).
		 *
		 * @param array<int, string> $roots Directories.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'zinn_seo_module_roots', $roots ) ) );
	}

	/**
	 * Read every module definition.
	 *
	 * @param array<int, string>|null $roots Directories (tests pass fixtures).
	 * @return array<string, array<string, mixed>>
	 */
	public static function discover( ?array $roots = null ): array {
		if ( null === $roots && null !== self::$all ) {
			return self::$all;
		}
		$found = array();
		foreach ( ( $roots ?? self::roots() ) as $index => $root ) {
			$files = glob( rtrim( $root, '/' ) . '/*/module.php' );
			if ( ! is_array( $files ) ) {
				continue;
			}
			sort( $files );
			foreach ( $files as $file ) {
				self::$discovering = true;
				try {
					$def = include $file;
				} finally {
					self::$discovering = false;
				}
				if ( ! is_array( $def ) || ! isset( $def['id'] ) || 1 !== preg_match( '/^[a-z][a-z0-9-]{0,40}$/', (string) $def['id'] ) ) {
					continue;
				}
				$def['premium']               = $index > 0;
				$def['dir']                   = dirname( $file );
				$def                         += array(
					'name'     => $def['id'],
					'default'  => true,
					'tier'     => 'free',
					'requires' => array(),
					'features' => array(),
					'contexts' => array( 'front', 'admin', 'rest', 'cli', 'cron' ),
					'files'    => array(),
				);
				$found[ (string) $def['id'] ] = $def;
			}
		}
		if ( null === $roots ) {
			self::$all = $found;
		}

		return $found;
	}

	/**
	 * Forget the per-request state (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$all    = null;
		self::$booted = array();
		self::$loaded = array();
	}

	/**
	 * Is a module switched on? The site's choice wins; otherwise the module's default. A premium
	 * module is on only with a licence that allows it.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function enabled( string $id ): bool {
		$def = self::discover()[ $id ] ?? null;
		if ( null === $def ) {
			return false;
		}
		if ( ! empty( $def['premium'] ) && ! Plugin::is_pro() ) {
			return false;
		}
		// An Agency-tier module runs on the Agency plan only (a Personal or Business licence
		// carries the premium code too, so the licence alone cannot decide).
		if ( 'agency' === ( $def['tier'] ?? '' ) && ! empty( $def['premium'] ) && ! Plugin::is_agency() ) {
			return false;
		}
		$choices = (array) Settings::get( 'modules', array() );
		$on      = array_key_exists( $id, $choices ) ? (bool) $choices[ $id ] : (bool) $def['default'];
		if ( ! $on ) {
			return false;
		}
		foreach ( (array) $def['requires'] as $needed ) {
			if ( ! self::enabled( (string) $needed ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The request type, so a module registers hooks only where they run.
	 *
	 * @return string front, admin, rest, cli or cron.
	 */
	public static function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( is_admin() ) {
			return 'admin';
		}

		return 'front';
	}

	/**
	 * Is this a WP-CLI command that never needs a module (zs-715 carried to WP-CLI)?
	 *
	 * ⛔ Measured 2026-10-13 (stage T1, for P13 and the fleet rollout D1): every WP-CLI command —
	 * `wp plugin is-active`, `wp plugin list`, `wp option get` — booted all modules: +18 MB and 361
	 * Zinn SEO files on WP 7.1 / PHP 8.4 beside WooCommerce, which with the other hosted plugins
	 * took `wp plugin is-active` past WP-CLI's 128 MB, and the platform drives every hosted site
	 * through such commands (PF-450). A command that only READS the site's plugins, themes, core,
	 * options or caches now starts no module. Everything that can change content or run our work
	 * keeps the full boot: `wp zinn-seo …`, `wp cron …`, `wp action-scheduler …`, `wp eval`, and
	 * every command not listed here.
	 *
	 * @param array<int, string>|null $args WP-CLI's positional arguments (null: the running ones).
	 * @return bool
	 */
	public static function cli_lean( ?array $args = null ): bool {
		if ( null === $args ) {
			if ( ! class_exists( '\WP_CLI' ) || ! method_exists( '\WP_CLI', 'get_runner' ) ) {
				return false;
			}
			// The runner's properties are read through its __get(): isset() on them is always false.
			$runner = \WP_CLI::get_runner();
			$given  = is_object( $runner ) ? $runner->arguments : null;
			$args   = is_array( $given ) ? $given : array();
		}
		$args    = array_values( array_map( 'strval', $args ) );
		$command = strtolower( $args[0] ?? '' );
		$sub     = strtolower( $args[1] ?? '' );
		// Read-only subcommands only (`*`: every subcommand of a command that touches no content).
		// Not `help` or `cli`: they list the commands, and our module subcommands register on boot.
		$read = array(
			'plugin'           => array( 'is-active', 'is-installed', 'list', 'get', 'status', 'path', 'search', 'verify-checksums' ),
			'theme'            => array( 'is-active', 'is-installed', 'list', 'get', 'status', 'path', 'search' ),
			'core'             => array( 'version', 'is-installed', 'check-update', 'verify-checksums' ),
			'option'           => array( 'get', 'list', 'pluck' ),
			'user'             => array( 'list', 'get' ),
			'site'             => array( 'list' ),
			'db'               => array( 'size', 'tables', 'check', 'prefix', 'columns' ),
			'config'           => '*',
			'language'         => '*',
			'package'          => '*',
			'maintenance-mode' => '*',
			'cache'            => '*',
			'transient'        => '*',
		);
		// ⛔ Never `eval` / `eval-file`, even when the code does not name us: an eval that inserts
		// an attachment, trashes a post or saves a term must run our hooks (T1 watched X1A's and
		// P11's harnesses fail on exactly that when eval started lean).
		$lean = isset( $read[ $command ] ) && ( '*' === $read[ $command ] || in_array( $sub, (array) $read[ $command ], true ) );

		/**
		 * Filters whether this WP-CLI command starts without Zinn SEO's modules.
		 *
		 * @param bool               $lean Start lean.
		 * @param array<int, string> $args WP-CLI's positional arguments.
		 */
		return (bool) apply_filters( 'zinn_seo_cli_lean', $lean, $args );
	}

	/**
	 * In a lean WP-CLI command, a module class asked for after all (by other code) boots the
	 * modules first, so nothing that needs one can fail — it only costs what it costs.
	 *
	 * @return void
	 */
	public static function lazy(): void {
		// Ahead of the class map's loader (prepend): asking for any class of ours boots the
		// modules first, then the map loads the class itself.
		spl_autoload_register(
			static function ( string $name ): void {
				static $booting = false;
				if ( ! $booting && 0 === strpos( $name, __NAMESPACE__ . '\\' ) ) {
					$booting = true;
					// What the lean start left out (start.php), then the modules.
					require_once ZINN_SEO_DIR . 'includes/admin-kit/load.php';
					require_once ZINN_SEO_DIR . 'includes/ai-core/load.php';
					require_once ZINN_SEO_DIR . 'includes/admin/class-admin.php';
					self::boot_for( 'cli' );
				}
			},
			true,
			true
		);
	}

	/**
	 * Boot every switched-on module that runs in this context (on `plugins_loaded`, priority 20,
	 * so the licence is known). REST requests are recognised late by WordPress, so a module that
	 * runs in `rest` also gets booted on `rest_api_init`.
	 *
	 * @return void
	 */
	public static function boot(): void {
		self::boot_for( self::context() );
		add_action(
			'rest_api_init',
			static function (): void {
				self::boot_for( 'rest' );
			},
			1
		);
	}

	/**
	 * Boot the modules for one context.
	 *
	 * @param string $context Context.
	 * @return void
	 */
	public static function boot_for( string $context ): void {
		foreach ( self::discover() as $id => $def ) {
			// Booted once per context: a REST request is first taken for a front-end one (WordPress
			// recognises REST only when it parses the request), so a module that runs in both
			// is booted for `front` on plugins_loaded and again for `rest` on rest_api_init —
			// measured 2026-10-09: booting once left every module's REST routes unregistered.
			if ( isset( self::$booted[ $id ][ $context ] ) || ! in_array( $context, (array) $def['contexts'], true ) || ! self::enabled( $id ) ) {
				continue;
			}
			self::$booted[ $id ][ $context ] = true;
			self::load( $id );
			if ( isset( $def['boot'] ) && is_callable( $def['boot'] ) ) {
				call_user_func( $def['boot'], $context );
			}
		}
	}

	/**
	 * Load a module's own class files (its `files`), once. Called when it boots; another part of
	 * the plugin that needs a module's classes while that module is on may call it too.
	 *
	 * @param string $id Module id.
	 * @return bool Whether the module exists and its files are loaded.
	 */
	public static function load( string $id ): bool {
		$def = self::discover()[ $id ] ?? null;
		if ( null === $def ) {
			return false;
		}
		self::$loaded[ $id ] = true;
		foreach ( (array) $def['files'] as $file ) {
			$path = $def['dir'] . '/' . ltrim( (string) $file, '/' );
			// A class file the class map knows is read when its class is first used (PERF2):
			// loading it here would compile it for every request the module boots in.
			if ( ! ( class_exists( Autoload::class, false ) && Autoload::maps_file( $path ) ) && is_readable( $path ) ) {
				require_once $path;
			}
		}

		return true;
	}

	/**
	 * May the class map read this module's classes (stage PERF2)?
	 *
	 * Before the class map, a module's classes existed only once it was loaded — it booted (it is
	 * on), something called load(), or its definition required them (the shared modules) — and
	 * code across the plugin reads `class_exists( '…\\Module\\Class' )` as "that module is
	 * available". The map keeps that meaning: it reads a module's class only for a module that is
	 * on, shared, loaded, or while the module list is read. A switched-off module still costs
	 * nothing (zs-715).
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function code_available( string $id ): bool {
		if ( self::$discovering || null === self::$all || isset( self::$loaded[ $id ] ) ) {
			return true;
		}
		$def = self::$all[ $id ] ?? null;
		if ( null === $def ) {
			return true; // Not a module directory we know: the map decides alone.
		}

		return ! empty( $def['shared'] ) || self::enabled( $id );
	}

	/**
	 * The modules that need this one (they switch off with it).
	 *
	 * @param string $id Module id.
	 * @return array<int, string>
	 */
	public static function needed_by( string $id ): array {
		$out = array();
		foreach ( self::discover() as $other => $def ) {
			if ( in_array( $id, (array) $def['requires'], true ) ) {
				$out[] = (string) $other;
			}
		}

		return $out;
	}

	/**
	 * Was a module booted in this request?
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function booted( string $id ): bool {
		return isset( self::$booted[ $id ] );
	}

	/**
	 * The list for screens, REST and WP-CLI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe(): array {
		$out = array();
		foreach ( self::discover() as $id => $def ) {
			$out[] = array(
				'id'          => $id,
				'name'        => is_callable( $def['name'] ) ? (string) call_user_func( $def['name'] ) : (string) $def['name'],
				'description' => isset( $def['description'] ) ? ( is_callable( $def['description'] ) ? (string) call_user_func( $def['description'] ) : (string) $def['description'] ) : '',
				'tier'        => (string) $def['tier'],
				'area'        => (string) ( $def['area'] ?? 'other' ),
				'premium'     => (bool) $def['premium'],
				'default'     => (bool) $def['default'],
				'enabled'     => self::enabled( $id ),
				'locked'      => ! empty( $def['locked'] ),
				'shared'      => ! empty( $def['shared'] ),
				'requires'    => array_values( (array) $def['requires'] ),
				'needed_by'   => self::needed_by( $id ),
				'available'   => empty( $def['premium'] ) || ( Plugin::is_pro() && ( 'agency' !== ( $def['tier'] ?? '' ) || Plugin::is_agency() ) ),
				'features'    => array_values( (array) $def['features'] ),
			);
		}

		return $out;
	}

	/**
	 * Switch a module on or off.
	 *
	 * @param string $id Module id.
	 * @param bool   $on Whether it should be on.
	 * @return true|\WP_Error
	 */
	public static function set( string $id, bool $on ) {
		$def = self::discover()[ $id ] ?? null;
		if ( null === $def ) {
			/* translators: %s: a module id. */
			return new \WP_Error( 'zinn_seo_unknown_module', sprintf( __( 'There is no module called %s.', 'zinn-seo' ), $id ), array( 'status' => 404 ) );
		}
		if ( ! empty( $def['locked'] ) && ! $on ) {
			return new \WP_Error( 'zinn_seo_module_locked', __( 'This module is part of the core and cannot be switched off.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$choices        = (array) Settings::get( 'modules', array() );
		$choices[ $id ] = $on;
		$saved          = Settings::save( array( 'modules' => $choices ) );

		return is_wp_error( $saved ) ? $saved : true;
	}
}
