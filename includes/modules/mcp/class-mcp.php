<?php
/**
 * AI agents (MCP): the site's own MCP server for Zinn® SEO (zs-210), its switch and its screen.
 *
 * Loaded by bootstrap.php on every request, BEFORE Boot::start(), because the WordPress MCP
 * adapter is chosen at `plugins_loaded` priority 5 — also in a WP-CLI command whose start Boot
 * defers to `after_wp_load` (stage PERF2). offer() is all that runs then: one option read, and
 * with the switch on, the shared MCP kit and the server's configuration. ⛔ It must not ask
 * Modules (that reads every module's definition: ~160 files while WordPress loads; zinn_seo.sh
 * refuses more than 15). boot() — the routes of the AI agents tab, the write guard and the
 * agents' log — runs with the rest of the plugin (start.php).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Mcp;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Guide;
use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\McpKit\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boot, the switch and the screen's routes.
 *
 * Routes (none of them an ability — an agent never turns its own access on, nor approves its own
 * change):
 *   GET  /mcp-settings              the panel's data (Server::describe() + `saved` + `writes`)
 *   POST /mcp-settings              `mcp` (bool) the switch; `writes` apply|approve|off
 *   GET  /agents/activity           what agents did, newest first
 *   GET  /agents/approvals          changes waiting for a person
 *   POST /agents/approvals/{id}     `decision` approve|reject
 */
final class Mcp {

	/** The module id (the switch). */
	public const MODULE = 'mcp';

	/**
	 * Boot the kit (the server, abilities and OAuth sign-in) when the module is on, and the routes.
	 *
	 * @return void
	 */
	public static function boot(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
		if ( ! self::enabled() ) {
			return;
		}
		self::start();
		Guard::boot();
	}

	/**
	 * Offer the server to the WordPress MCP adapter while WordPress loads (bootstrap.php).
	 * With the switch off, nothing beyond the one option read.
	 *
	 * @return void
	 */
	public static function offer(): void {
		if ( self::switched_on() && ! self::cli_without_mcp() ) {
			self::start();
		}
	}

	/**
	 * A WP-CLI command other than `wp mcp-adapter …` serves no MCP: nothing of the server is
	 * loaded for it. ⛔ Measured 2026-10-13 (P13, WooCommerce + the six hosts, every module on):
	 * with the switch on, `wp plugin is-active` and `wp option get` died at 128 MB, and passed with
	 * it off. Read without the kit (the kit's own Adapter::cli_without_mcp() would load it).
	 *
	 * @return bool
	 */
	public static function cli_without_mcp(): bool {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) || ! class_exists( '\\WP_CLI' ) || ! method_exists( '\\WP_CLI', 'get_runner' ) ) {
			return false;
		}
		$runner = \WP_CLI::get_runner();
		// Runner::$arguments is private behind __get() (no __isset()): read it, never isset() it.
		$args = is_object( $runner ) ? $runner->arguments : array();

		return 'mcp-adapter' !== (string) ( is_array( $args ) ? ( $args[0] ?? '' ) : '' );
	}

	/**
	 * The switch, read straight from the stored choice: no module definition is read. The `mcp`
	 * module is free, needs no other module and is off unless switched on, so this equals
	 * enabled() — McpSwitchTest holds the module's definition to exactly that.
	 *
	 * @return bool
	 */
	public static function switched_on(): bool {
		$settings = get_option( 'zseo_settings', array() );
		$modules  = is_array( $settings ) && is_array( $settings['modules'] ?? null ) ? $settings['modules'] : array();

		return ! empty( $modules[ self::MODULE ] );
	}

	/**
	 * Load the kit and this module's classes, and hand the kit the server's configuration (it
	 * registers nothing while the switch is off). Safe to call more than once.
	 *
	 * @return void
	 */
	public static function start(): void {
		static $started = false;
		if ( $started ) {
			return;
		}
		$started = true;
		// The shared MCP kit's classes and this module's own (Abilities, Guard, Log, Approvals)
		// load through the class map when first used (stage PERF2): offering the server reads
		// the kit's Server, Adapter and OAuth only.
		Server::boot(
			array(
				'id'             => 'zinn-seo',
				'rest_namespace' => Plugin::REST_NS,
				'name'           => 'Zinn® SEO',
				'description'    => static fn(): string => __( 'Check and improve this site\'s search and AI visibility with Zinn® SEO: titles, descriptions, structured data, redirects, sitemaps, robots.txt, llms.txt, audits and Zinn® credit checks.', 'zinn-seo' ),
				'version'        => ZINN_SEO_VERSION,
				'capability'     => Capabilities::VIEW,
				'category'       => array(
					'slug'        => Abilities::CATEGORY,
					'label'       => static fn(): string => __( 'Zinn® SEO', 'zinn-seo' ),
					'description' => static fn(): string => __( 'Search engine and AI visibility: settings, page fields, structured data, redirects, sitemaps, audits and checks.', 'zinn-seo' ),
				),
				'enabled'        => array( self::class, 'enabled' ),
				'abilities'      => array( Abilities::class, 'register' ),
				'vendor_dir'     => ZINN_SEO_DIR . 'vendor/wordpress',
				// The pages every MCP plugin of ours has on zinndigital.com (the user guide and the
				// abilities reference, generated from abilities.json). describe() swaps the guide
				// for the step-by-step guide on zinnseo.com in the admin's own language.
				'docs'           => array(
					'guide'      => 'https://zinndigital.com/wordpress-plugins/zinn-seo/mcp',
					'developers' => 'https://zinndigital.com/wordpress-plugins/zinn-seo/mcp-api',
				),
			)
		);
	}

	/**
	 * Is the site owner's switch on (the `mcp` module)?
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return Modules::enabled( self::MODULE );
	}

	/**
	 * The routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		self::start();
		register_rest_route(
			Plugin::REST_NS,
			'/mcp-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( self::describe() ),
					'permission_callback' => array( Capabilities::class, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save' ),
					// The owner's switch: changing who may run AI agents on the site is an
					// administrator's decision, not an SEO manager's.
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'args'                => array(
						'mcp'    => array( 'type' => 'boolean' ),
						'writes' => array(
							'type' => 'string',
							'enum' => array( 'apply', 'approve', 'off' ),
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/agents/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array( 'items' => Log::recent( (int) ( $request->get_param( 'limit' ) ?? 100 ) ) )
				),
				'permission_callback' => array( Capabilities::class, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Log::KEEP,
						'default' => 100,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/agents/approvals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( array( 'items' => Approvals::pending() ) ),
				'permission_callback' => array( Capabilities::class, 'can_manage' ),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/agents/approvals/(?P<id>[a-f0-9]{12})',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $request ) {
					$done = Approvals::decide( (string) $request['id'], (string) $request->get_param( 'decision' ) );
					return is_wp_error( $done ) ? $done : rest_ensure_response( $done );
				},
				'permission_callback' => array( Capabilities::class, 'can_manage' ),
				'args'                => array(
					'decision' => array(
						'type'     => 'string',
						'enum'     => array( 'approve', 'reject' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The panel's data: the kit's description, the saved switch and the write mode.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		self::start();
		$mcp                  = array( 'saved' => self::enabled() ) + Server::describe();
		$mcp['docs']['guide'] = Guide::url( 'agents' );

		return array(
			'mcp' => $mcp + array(
				'writes'  => self::writes(),
				'pending' => count( Approvals::pending() ),
			),
		);
	}

	/**
	 * What happens to an agent's change: apply, approve or off.
	 *
	 * @return string
	 */
	public static function writes(): string {
		$mode = (string) \ZinnDigital\ZinnSEO\Settings::get( 'mcp_writes', 'apply' );

		return in_array( $mode, array( 'apply', 'approve', 'off' ), true ) ? $mode : 'apply';
	}

	/**
	 * `POST /mcp-settings`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'writes' ) ) {
			$saved = \ZinnDigital\ZinnSEO\Settings::save( array( 'mcp_writes' => (string) $request->get_param( 'writes' ) ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		if ( null !== $request->get_param( 'mcp' ) ) {
			$set = Modules::set( self::MODULE, (bool) $request->get_param( 'mcp' ) );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		return rest_ensure_response( self::describe() );
	}
}
