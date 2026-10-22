<?php
/**
 * The plugin: start-up, activation, licence state.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts the parts each request needs and nothing else (zs-150, zs-155): a visitor's page view
 * runs the primer and the modules that print something; the admin, REST, WP-CLI and cron each get
 * their own set.
 */
final class Plugin {

	/** The REST namespace. */
	public const REST_NS = 'zinn-seo/v1';

	/**
	 * Hook a `register_block_type_args` filter AND apply it to the block types already
	 * registered. A WP-CLI command that runs our work starts the plugin after WordPress has
	 * loaded (Boot, stage PERF2), when core and other plugins' blocks are already registered:
	 * a filter added then never saw them (PERF3 found it: Snippets' attribute missing from every
	 * core block under WP-CLI). The filters this takes change only `attributes`.
	 *
	 * @param callable $filter `( array $args, string $name ): array`.
	 * @return void
	 */
	public static function on_block_type_args( callable $filter ): void {
		add_filter( 'register_block_type_args', $filter, 10, 2 );
		if ( ! did_action( 'init' ) || ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return;
		}
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$args = (array) call_user_func( $filter, array( 'attributes' => (array) $type->attributes ), (string) $name );
			if ( isset( $args['attributes'] ) && is_array( $args['attributes'] ) ) {
				$type->attributes = $args['attributes'];
			}
		}
	}

	/**
	 * CSS ready for wp_add_inline_style(): its comments removed, so that the call's
	 * wp_strip_all_tags() — the escaping WordPress.org asks for at the sink (review
	 * P0TDX382477HGN, Q200) — never meets a `<` inside a comment. Callers write
	 * `wp_add_inline_style( $handle, wp_strip_all_tags( Plugin::css_for_inline( $css ) ) )`. Pure.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function css_for_inline( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * Route registrars already handed to on_rest_api_init(), by name. A closure is kept (not just
	 * its id), so its id is never handed to another closure while this request lasts.
	 *
	 * @var array<string, mixed>
	 */
	private static $rest_registrars = array();

	/**
	 * Register REST routes ONCE, whenever this is called: on `rest_api_init` when it has not run
	 * yet, at once when it is running or has run — never both. Every module registers through
	 * here. ⛔ The old pattern (hook `rest_api_init`, and register at once when it had started)
	 * did BOTH when a module booted inside `rest_api_init` (Modules boots the `rest` context
	 * there, priority 1): WordPress then called the hook again at priority 10, and
	 * `register_rest_route()` appended a second copy of every handler (P13, 2026-10-13: 50 routes
	 * listed twice; the check is zinn_seo_p13.sh's real `/wp-json/zinn-seo/v1` index). A module
	 * booted for two contexts calls this twice: the second call is a no-op.
	 *
	 * @param callable $register Registers the routes.
	 * @return void
	 */
	public static function on_rest_api_init( callable $register ): void {
		if ( $register instanceof \Closure ) {
			$key = 'closure#' . spl_object_id( $register );
		} else {
			is_callable( $register, false, $key );
		}
		if ( isset( self::$rest_registrars[ $key ] ) ) {
			return;
		}
		self::$rest_registrars[ $key ] = $register instanceof \Closure ? $register : true;
		// did_action() counts an action from the moment it starts, so this is also true inside it.
		if ( did_action( 'rest_api_init' ) ) {
			call_user_func( $register );
			return;
		}
		add_action( 'rest_api_init', $register );
	}

	/**
	 * Start.
	 *
	 * @return void
	 */
	public static function init(): void {
		Capabilities::init();
		add_action( 'init', array( self::class, 'load_translations' ), 1 );
		add_action( 'init', array( Meta::class, 'register' ), 5 );
		add_action( 'plugins_loaded', array( self::class, 'loaded' ), 20 );
	}

	/**
	 * Everything that needs the licence and translations (on `plugins_loaded`).
	 *
	 * @return void
	 */
	public static function loaded(): void {
		$context = Modules::context();
		// A WP-CLI command that only reads plugins, themes, core or options starts nothing of ours
		// (Modules::cli_lean(): +18 MB and 361 files per command before, PF-450 with WooCommerce).
		if ( 'cli' === $context && Modules::cli_lean() ) {
			// No per-object fields either: registering them reads every module's definition.
			remove_action( 'init', array( Meta::class, 'register' ), 5 );
			Modules::lazy();
			/** This action is documented below. */
			do_action( 'zinn_seo_loaded', $context );
			return;
		}
		Notrace::boot();
		// Q377: a process holding a stale copy of the cron array (a long `wp cron event run`)
		// must not drop the events that keep our jobs going. Late, so it sees the final array.
		add_filter( 'pre_update_option_cron', array( Jobs_Pace::class, 'keep_events' ), 999, 2 );
		if ( 'front' === $context ) {
			Primer::init();
			// Q337: how long the site's own pages take, sampled while a job is pending, so the
			// jobs slow down when the visitors' pages do.
			add_action( 'shutdown', array( Jobs_Pace::class, 'sample' ) );
		} else {
			Jobs::init();
			Alert_Mail::boot( $context );
			Freemius_I18n::register();
			Notrace::adopt_platform_value();
		}
		if ( 'admin' === $context ) {
			Admin\Admin::register();
			add_action( 'admin_init', array( self::class, 'seed_sdk_options' ) );
		}
		Rest::init();
		// ⛔ The premium layer loads only when its file ships AND the licence allows it; the
		// directory name is split so the free package never carries the premium token.
		$pro = __DIR__ . '/pro_' . '_premium_only/class-roles.php'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
		if ( is_readable( $pro ) && self::is_pro() ) {
			require_once $pro;
			Pro\Roles::init();
			// Other plugins' Rank Math additions keep working once Rank Math is off.
			require_once dirname( $pro ) . '/class-rank-math-hooks.php';
			Pro\Rank_Math_Hooks::init();
			// A theme's breadcrumbs printed through the plugin a site moved from keep appearing.
			require_once dirname( $pro ) . '/class-seo-compat.php';
			Pro\Seo_Compat::init();
		}
		// ⚖️ §2.62: a trial (or licence) that ended unpaid stops every Pro feature, gracefully; paying
		// restores them (premium package only: the free one has nothing to stop).
		$lapse = dirname( $pro ) . '/class-lapse.php';
		if ( is_readable( $lapse ) ) {
			require_once $lapse;
			Pro\Lapse::boot( $context, self::is_pro() );
		}
		Modules::boot();

		/**
		 * Fires when Zinn® SEO has started, after its modules booted.
		 *
		 * @param string $context front, admin, rest, cli or cron.
		 */
		do_action( 'zinn_seo_loaded', $context );
	}

	/**
	 * The plugin's own catalogues (languages/, 57 locales). WordPress loads translations by itself
	 * only from its language-pack folder, so without this every PHP string — setup step titles, the
	 * Plans screen's feature names, notices — stayed English on an Arabic admin while the JavaScript
	 * strings were translated (measured 2026-10-08, zinn_seo_browser.mjs). Registers the folder only;
	 * WordPress reads a catalogue the first time a string of this plugin is translated.
	 *
	 * @return void
	 */
	public static function load_translations(): void {
		load_plugin_textdomain( 'zinn-seo', false, dirname( plugin_basename( ZINN_SEO_FILE ) ) . '/languages' );
	}

	/**
	 * Is a paid plan (or trial) active on this site?
	 *
	 * @return bool
	 */
	public static function is_pro(): bool {
		$fs  = function_exists( 'zinn_seo_fs' ) ? zinn_seo_fs() : null;
		$pro = is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) && (bool) $fs->can_use_premium_code();

		/**
		 * Filters whether the premium features run (the licensing SDK decides; tests set it).
		 *
		 * @param bool $pro Whether a paid plan or trial is active.
		 */
		return (bool) apply_filters( 'zinn_seo_is_pro', $pro );
	}

	/**
	 * Is the Agency plan active (white label, client logins, client reports)?
	 *
	 * @return bool
	 */
	public static function is_agency(): bool {
		/**
		 * Filters whether the Agency-plan features run (the plan decides; tests set it).
		 *
		 * @param bool $agency Whether the site is on the Agency plan.
		 */
		return (bool) apply_filters( 'zinn_seo_agency_plan', self::is_pro() && 'agency' === self::plan() );
	}

	/**
	 * The edition the site runs: free, or the plan's name.
	 *
	 * @return string free|personal|business|agency
	 */
	public static function plan(): string {
		if ( ! self::is_pro() ) {
			return 'free';
		}
		$fs = function_exists( 'zinn_seo_fs' ) ? zinn_seo_fs() : null;
		if ( ! is_object( $fs ) ) {
			return 'personal';
		}
		foreach ( array( 'agency', 'business', 'personal' ) as $plan ) {
			if ( method_exists( $fs, 'is_plan' ) && $fs->is_plan( $plan, true ) ) {
				return $plan;
			}
		}

		return 'personal';
	}

	/**
	 * Activation: the roles, and nothing on the front end until a module is switched on.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Capabilities::add_roles();
		self::seed_sdk_options();
		// The licensing SDK registers the uninstall hook that runs zinn_seo_uninstall(), but only on
		// an activation made in wp-admin; one made by WP-CLI (how hosts install plugins) left the
		// plugin with NO uninstall hook, so deleting it removed nothing (measured 2026-10-08,
		// zinn_seo.sh). Register our own only when nothing is registered, so the SDK's stays.
		$registered = (array) get_option( 'uninstall_plugins', array() );
		if ( empty( $registered[ plugin_basename( ZINN_SEO_FILE ) ] ) ) {
			register_uninstall_hook( ZINN_SEO_FILE, 'zinn_seo_uninstall' );
		}
		if ( false === get_option( Settings::OPTION, false ) ) {
			// A new site starts with the click-to-edit suggested titles (zs-757); a site that had
			// the plugin before keeps its fields as they were until its owner switches it on.
			add_option(
				Settings::OPTION,
				array(
					'_v'               => Settings::SCHEMA,
					'placeholder_edit' => true,
				),
				'',
				true
			);
			// A first install on our Footprint-Free (PBN) hosting starts in no-trace mode
			// (zs-747): the platform marks those sites with ZINN_FOOTPRINT_FREE in wp-config.php.
			// Only on a FIRST install, through the same hand-over a host uses, so a reactivation
			// keeps the site's own choice and the site can switch it off like any other.
			if ( Notrace::footprint_free_host() && false === get_option( Notrace::PLATFORM_OPTION, false ) ) {
				add_option( Notrace::PLATFORM_OPTION, 1, '', false );
			}
		}
	}

	/**
	 * The licensing SDK's options read on EVERY page view, visitors' included.
	 *
	 * @return array<int, string>
	 */
	public static function sdk_options(): array {
		return array( 'fs_storage_logger', 'fs_clone_management', 'fs_cache_41086' );
	}

	/**
	 * Create the SDK's per-request options, empty and autoloaded, when they do not exist yet.
	 *
	 * ⛔⛔ Measured 2026-10-08 (zinn_seo.sh, WordPress 7.1.3): the licensing SDK reads these three
	 * options at start-up on every request, and on a site where it has never written them (no
	 * opt-in, no licence) each read is a database query that finds nothing — +3 queries on every
	 * visitor's page, over the budget (zs-153). The SDK writes an empty store as an empty array
	 * itself (`FS_Option_Manager::clear( true )`), so creating that same empty value, autoloaded,
	 * is the state the SDK would reach on its own, and the reads come from the one query WordPress
	 * already makes. Two of the three are shared by every Freemius plugin on the site; they gain
	 * the same saving.
	 *
	 * @return void
	 */
	public static function seed_sdk_options(): void {
		foreach ( array_merge( self::sdk_options(), self::front_options() ) as $name ) {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, array(), '', true );
			}
		}
	}

	/**
	 * Our own options a visitor's page reads, which may not exist yet: created empty and
	 * autoloaded like the SDK's above (on activation, and for sites that updated, on the next
	 * admin request).
	 *
	 * ⛔⛔ Measured 2026-10-15 (MKT3, SAVEQUERIES on a visitor's post, 0.17.19): the CDN state
	 * (`zseo_cdn`, read by `Cdn::front` on every page) did not exist on a site that never set up the
	 * CDN, so every page view ran a query that found nothing — 32 queries against 30 without the
	 * plugin. Its own writes already autoload it; an empty array is what `Cdn::stored()` reads as
	 * "off".
	 *
	 * @return array<int, string>
	 */
	public static function front_options(): array {
		return array( 'zseo_cdn' );
	}

	/**
	 * Deactivation: stop background work; keep every setting.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Jobs::WATCHDOG );
	}
}
