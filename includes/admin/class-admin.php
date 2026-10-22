<?php
/**
 * The wp-admin screen.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Admin;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One top-level menu whose page is a React app (`src/admin/`, built by @wordpress/scripts into
 * `build/`) inside the shared admin shell (the admin kit: overview, setup, plans, help).
 *
 * ⛔ zs-155: the app's script and style are enqueued on THIS screen only — every other admin
 * screen gets nothing from this plugin. The real-WordPress harness fetches a set of other admin
 * screens and fails when any of them references this plugin's assets.
 */
final class Admin {

	/** Menu slug, shared with the licensing SDK's menu integration. */
	public const SLUG = 'zinn-seo';

	/** Script handle. */
	public const HANDLE = 'zinn-seo-admin';

	/**
	 * The page hook suffix returned by add_menu_page().
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hook the menu and assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ZINN_SEO_FILE ), array( self::class, 'action_links' ) );
		// Settings → AI providers is the shared AI core's own page (no admin shell): its guide
		// goes in WordPress's Help tab (zs-685).
		add_action( 'load-settings_page_zinn-ai', array( self::class, 'ai_providers_help' ) );
	}

	/**
	 * The guide link on Settings → AI providers, in WordPress's Help tab (zs-685).
	 *
	 * @return void
	 */
	public static function ai_providers_help(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'zinn-seo-guide',
				'title'   => __( 'Step-by-step guide', 'zinn-seo' ),
				'content' => '<p>' . \ZinnDigital\ZinnSEO\Guide::link( 'ai-settings' ) . '</p>',
			)
		);
	}

	/**
	 * Add the top-level menu (open to anyone who may see SEO reports or change settings).
	 *
	 * @return void
	 */
	public static function menu(): void {
		$cap = current_user_can( Capabilities::MANAGE ) ? Capabilities::MANAGE : Capabilities::VIEW;
		/**
		 * Filters the admin menu entry (an agency's white label renames it and sets its icon).
		 *
		 * @param array{title: string, label: string, icon: string} $menu Page title, menu label, icon.
		 */
		$menu       = (array) apply_filters(
			'zinn_seo_menu',
			array(
				'title' => __( 'Zinn® SEO', 'zinn-seo' ),
				'label' => __( 'SEO', 'zinn-seo' ),
				'icon'  => self::menu_icon(),
			)
		);
		self::$hook = (string) add_menu_page(
			(string) $menu['title'],
			(string) $menu['label'],
			$cap,
			self::SLUG,
			array( self::class, 'render' ),
			(string) $menu['icon'],
			// ⛔ Below Settings (80), never higher: WordPress.org flags a high menu position.
			81
		);
	}

	/**
	 * The Settings link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public static function action_links( $links ): array {
		$links = (array) $links;
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'zinn-seo' ) . '</a>' );

		return $links;
	}

	/**
	 * Print the mount point.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) && ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'zinn-seo' ), 403 );
		}
		// The page heading is the admin shell's. wp-header-end: WordPress places admin notices
		// here, above the shell, rather than inside the shell's own header.
		echo '<div class="wrap"><hr class="wp-header-end">';
		if ( ! is_readable( ZINN_SEO_DIR . 'build/settings.asset.php' ) ) {
			echo '<h1>' . esc_html__( 'Zinn® SEO', 'zinn-seo' ) . '</h1>';
			// A source checkout that was never built. Say so rather than show an empty page.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The admin screen\'s scripts are missing from this copy of the plugin. Reinstall it from a released package.', 'zinn-seo' ) . '</p></div>';
		}
		echo '<div id="zinn-seo-admin-root"></div>';
		echo '<noscript><h1>' . esc_html__( 'Zinn® SEO', 'zinn-seo' ) . '</h1><p>' . esc_html__( 'This screen needs JavaScript.', 'zinn-seo' ) . '</p></noscript></div>';
	}

	/**
	 * Enqueue the app on this screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		$asset_file = ZINN_SEO_DIR . 'build/settings.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		self::register_jsx_runtime();
		wp_enqueue_media();
		wp_enqueue_script(
			self::HANDLE,
			ZINN_SEO_URL . 'build/settings.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? ZINN_SEO_VERSION ),
			true
		);
		wp_set_script_translations( self::HANDLE, 'zinn-seo', ZINN_SEO_DIR . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.zinnSeoAdmin = ' . wp_json_encode( self::data() ) . ';', 'before' );
		self::enqueue_kit();

		if ( is_readable( ZINN_SEO_DIR . 'build/settings.css' ) ) {
			wp_enqueue_style( self::HANDLE, ZINN_SEO_URL . 'build/settings.css', array( 'wp-components' ), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * The admin shell's boot data, through `zinn_seo_kit_data` (an agency's white label renames
	 * the shell, adds its logo and colours, and hides the kit's own screens; a client login gets
	 * no licence, plan or promotion data at all). Same output as the kit's own enqueue otherwise.
	 *
	 * @return void
	 */
	public static function enqueue_kit(): void {
		$kit = \ZinnDigital\ZinnSEO\AdminKit\Kit::boot_data();
		// zs-685: every screen's Help menu opens its own guide, in the admin's language (one map,
		// Guide::slugs()). Set BEFORE the filter, so an agency's white label still replaces it.
		$kit['help'] = \ZinnDigital\ZinnSEO\Guide::kit_help();
		/**
		 * Filters the admin shell's boot data. ⛔ Printed into the page: nothing secret.
		 *
		 * @param array<string, mixed> $kit Boot data.
		 */
		$kit = (array) apply_filters( 'zinn_seo_kit_data', $kit );
		wp_add_inline_script(
			self::HANDLE,
			'window.zinnAdminKit = window.zinnAdminKit || {}; window.zinnAdminKit["zinn-seo"] = ' . wp_json_encode( $kit ) . ';',
			'before'
		);
	}

	/**
	 * WordPress 6.4 and 6.5 do not register `react-jsx-runtime` (core added it in 6.6), and the
	 * build names it as a dependency, so WordPress silently dropped the whole admin app there
	 * (measured 2026-10-08, zinn_seo.sh on WordPress 6.4.13: an empty screen). Below 6.6 the handle
	 * is registered here as the same three functions core's copy exports, built on `React`.
	 *
	 * @return void
	 */
	public static function register_jsx_runtime(): void {
		if ( wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
			return;
		}
		wp_register_script( 'react-jsx-runtime', false, array( 'react' ), ZINN_SEO_VERSION, true );
		wp_add_inline_script(
			'react-jsx-runtime',
			'window.ReactJSXRuntime = window.ReactJSXRuntime || ( function ( R ) { function jsx( type, props, key ) { var p = props || {}; if ( key !== undefined ) { p = Object.assign( {}, p, { key: key } ); } return R.createElement( type, p ); } return { jsx: jsx, jsxs: jsx, Fragment: R.Fragment }; } )( window.React );'
		);
	}

	/**
	 * Data the app boots with. ⛔ Printed into the page: nothing secret belongs here.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		/**
		 * Filters the data the admin app boots with.
		 *
		 * @param array<string, mixed> $data Boot data.
		 */
		return (array) apply_filters(
			'zinn_seo_admin_data',
			array(
				'version'    => ZINN_SEO_VERSION,
				'plan'       => Plugin::plan(),
				'restPath'   => '/' . Plugin::REST_NS,
				'canManage'  => Capabilities::can_manage(),
				'isAdmin'    => current_user_can( 'manage_options' ),
				'siteName'   => (string) get_bloginfo( 'name' ),
				'homeUrl'    => home_url( '/' ),
				'adminUrl'   => admin_url(),
				'readingUrl' => admin_url( 'options-reading.php' ),
				'usersUrl'   => admin_url( 'users.php' ),
				'setup'      => \ZinnDigital\ZinnSEO\Setup::state(),
				// Module id => switched on (screens show a switch instead of a screen that cannot load).
				'modules'    => array_column( \ZinnDigital\ZinnSEO\Modules::describe(), 'enabled', 'id' ),
				'multisite'  => is_multisite(),
				'roles'      => false,
				'productUrl' => 'https://zinndigital.com/wordpress-plugins/',
				'companyUrl' => 'https://zinndigital.com',
				// Q356: Settings › Alert e-mails lists every kind of e-mail, to mute it.
				'alertKinds' => \ZinnDigital\ZinnSEO\Alert_Mail::kinds(),
			)
		);
	}

	/**
	 * The admin menu icon: a monochrome SVG data URI (WordPress recolours `fill` to the scheme).
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		$menu_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black"><path fill-rule="evenodd" d="M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4-1.4 1.4-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9z"/><path d="M5.5 10.5h1.6V8.9H5.5zm2.2 0h1.6V7.3H7.7zm2.2 0h1.6V5.7H9.9z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $menu_svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a data URI is the documented form for a menu icon.
	}
}
