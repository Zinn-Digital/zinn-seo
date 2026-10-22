<?php
/**
 * When Zinn® SEO starts: at once, or — in a WP-CLI command that runs our work — right after
 * WordPress has loaded (stage PERF2, Q191).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ Measured 2026-10-13 (PERF2, WordPress 7.1.3 / PHP 8.4, WooCommerce + the five other hosted
 * plugins): WP-CLI loads WordPress under PHP's own `memory_limit` (128 MB on our hosting) and lifts
 * it only AFTER `wp-settings.php` has finished (WP_CLI\Runner::load_wordpress). WooCommerce and the
 * other plugins alone reach 123.6 MB by `wp_loaded`; our full start (every module, the admin kit,
 * the AI core) added 23.3 MB on top, so `wp eval`, `wp cron event run`, `wp action-scheduler run`
 * and `wp mcp-adapter serve` — how the platform drives every hosted site — died of memory before
 * the command ran (Q181/Q191). WP-CLI itself never needs our modules while it loads WordPress.
 *
 * So a WP-CLI command that runs our work (Modules::cli_lean() is false) starts us on WP-CLI's
 * `after_wp_load` — after the limit is lifted, before the command runs — and then REPLAYS, for
 * the callbacks our start added, every WordPress action that already fired while it loaded
 * (`plugins_loaded`, `init`, `wp_loaded` …, see REPLAY), in WordPress's order and by priority.
 * The command itself (an eval that inserts an attachment or trashes a post, a cron event, an
 * Action Scheduler action, an MCP tool) runs after that, so every one of our hooks still fires.
 * Everything else — the front end, wp-admin, REST, wp-cron.php, the lean read-only commands —
 * starts exactly as before.
 */
final class Boot {

	/**
	 * Actions WordPress fires while WP-CLI loads it that our start hooks into, with what each passes.
	 * A fired action that is NOT here and that our start hooked is reported by missed() (the
	 * real-site harness asserts it is empty), never silently skipped.
	 */
	public const REPLAY = array(
		'plugins_loaded',
		'setup_theme',
		'after_setup_theme',
		'set_current_user',
		'init',
		'widgets_init',
		'cli_init',
		'wp_loaded',
		'rest_api_init',
	);

	/**
	 * Fired actions whose callbacks were added by our start but are not replayed (diagnostics).
	 *
	 * @var array<int, string>
	 */
	private static array $missed = array();

	/**
	 * Was the start deferred in this request?
	 *
	 * @var bool
	 */
	private static bool $deferred = false;

	/**
	 * Start now, or schedule the start for WP-CLI's `after_wp_load`.
	 *
	 * @param callable $start Starts the plugin (bootstrap.php's start).
	 * @return void
	 */
	public static function start( callable $start ): void {
		if ( self::defer() ) {
			self::$deferred = true;
			\WP_CLI::add_hook(
				'after_wp_load',
				static function () use ( $start ): void {
					self::resume( $start );
				}
			);
			return;
		}
		call_user_func( $start );
	}

	/**
	 * Should this request start us after WordPress has loaded?
	 *
	 * Only a WP-CLI command that runs our work, only while WordPress is still loading (a plugin
	 * file read later — `wp plugin activate` — starts at once, as before), and only with a WP-CLI
	 * that has the hook.
	 *
	 * @return bool
	 */
	public static function defer(): bool {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) || ! class_exists( '\WP_CLI' ) || ! method_exists( '\WP_CLI', 'add_hook' ) ) {
			return false;
		}
		if ( did_action( 'wp_loaded' ) || Modules::cli_lean() ) {
			return false;
		}

		/**
		 * Filters whether a WP-CLI command starts Zinn® SEO after WordPress has loaded.
		 *
		 * @param bool $defer Start after WordPress has loaded.
		 */
		return (bool) apply_filters( 'zinn_seo_cli_defer', true );
	}

	/**
	 * Start, then run what our start hooked into actions that already fired.
	 *
	 * @param callable $start Starts the plugin.
	 * @return void
	 */
	public static function resume( callable $start ): void {
		global $wp_filter;
		$fired  = self::fired();
		$before = array();
		foreach ( $fired as $hook ) {
			$before[ $hook ] = self::ids( $hook );
		}
		call_user_func( $start );
		foreach ( $fired as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			if ( ! in_array( $hook, self::REPLAY, true ) ) {
				if ( array() !== array_diff_key( self::ids( $hook ), $before[ $hook ] ) ) {
					self::$missed[] = $hook;
				}
				continue;
			}
			self::replay( $hook, $before[ $hook ] );
		}
	}

	/**
	 * Call, in priority order, every callback on a fired action that was not there before our
	 * start — including those a replayed callback adds (a module booted on `rest_api_init` adds
	 * its route registrar there).
	 *
	 * @param string             $hook   Action.
	 * @param array<string, int> $called Callback ids not to call (there before, or called already).
	 * @return int How many were called.
	 */
	public static function replay( string $hook, array $called ): int {
		global $wp_filter;
		$args = self::args( $hook );
		$runs = 0;
		while ( isset( $wp_filter[ $hook ] ) ) {
			$next = null;
			foreach ( self::by_priority( $hook ) as $id => $callback ) {
				if ( ! isset( $called[ $id ] ) ) {
					$next = array( $id, $callback );
					break;
				}
			}
			if ( null === $next ) {
				break;
			}
			$called[ $next[0] ] = 1;
			call_user_func_array( $next[1]['function'], array_slice( $args, 0, (int) $next[1]['accepted_args'] ) );
			++$runs;
		}

		return $runs;
	}

	/**
	 * What WordPress passes to a replayed action.
	 *
	 * @param string $hook Action.
	 * @return array<int, mixed>
	 */
	private static function args( string $hook ): array {
		if ( 'rest_api_init' === $hook && function_exists( 'rest_get_server' ) ) {
			return array( rest_get_server() );
		}

		return array();
	}

	/**
	 * Actions that have fired, in the order they first fired.
	 *
	 * @return array<int, string>
	 */
	private static function fired(): array {
		global $wp_actions;

		return is_array( $wp_actions ) ? array_map( 'strval', array_keys( $wp_actions ) ) : array();
	}

	/**
	 * Callback ids on an action (id => 1).
	 *
	 * @param string $hook Action.
	 * @return array<string, int>
	 */
	private static function ids( string $hook ): array {
		return array_fill_keys( array_keys( self::by_priority( $hook ) ), 1 );
	}

	/**
	 * An action's callbacks in the order WordPress runs them, keyed `priority|id`.
	 *
	 * @param string $hook Action.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_priority( string $hook ): array {
		global $wp_filter;
		$callbacks = isset( $wp_filter[ $hook ] ) && is_object( $wp_filter[ $hook ] ) ? (array) $wp_filter[ $hook ]->callbacks : array();
		ksort( $callbacks, SORT_NUMERIC );
		$out = array();
		foreach ( $callbacks as $priority => $list ) {
			foreach ( (array) $list as $id => $callback ) {
				$out[ $priority . '|' . $id ] = $callback;
			}
		}

		return $out;
	}

	/**
	 * Was our start deferred in this request?
	 *
	 * @return bool
	 */
	public static function deferred(): bool {
		return self::$deferred;
	}

	/**
	 * Fired actions our deferred start hooked into but did not replay (should be none).
	 *
	 * @return array<int, string>
	 */
	public static function missed(): array {
		return self::$missed;
	}
}
