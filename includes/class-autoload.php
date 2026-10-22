<?php
/**
 * Reads a Zinn® SEO class from disk the first time something uses it (stage PERF2, Q191).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ Measured 2026-10-13 (PERF2): a module that boots required every file it lists (`files`)
 * whether the request used it or not, so a full start compiled ~470 of our files. With the map,
 * a module's listed class files are read when their class is first used.
 *
 * A module's class is read only while that module's code is available — on, shared, loaded, or
 * while the module list is read (Modules::code_available()) — so `class_exists()` on a switched-off
 * module's class stays false, as it was.
 *
 * The map is generated (wp/bin/build-zinn-seo-classmap.php): includes/classmap.php for the free
 * code, includes/pro__premium_only/classmap.php when the premium code ships. A file that does
 * anything besides declaring is never in it, so whatever required it before still does.
 */
final class Autoload {

	/**
	 * Class => path relative to the plugin directory.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $map = null;

	/**
	 * Register once.
	 *
	 * @return void
	 */
	public static function register(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load one class of ours, if the map knows it.
	 *
	 * @param string $name Fully-qualified class name.
	 * @return void
	 */
	public static function load( string $name ): void {
		if ( 0 !== strncmp( $name, __NAMESPACE__ . '\\', strlen( __NAMESPACE__ ) + 1 ) ) {
			return;
		}
		$map = self::map();
		if ( ! isset( $map[ $name ] ) ) {
			return;
		}
		// A module's class only while that module's code is available (Modules::code_available()).
		if ( 1 === preg_match( '#^includes/(?:pro_' . '_premium_only/)?modules/([a-z0-9-]+)/#', $map[ $name ], $m ) && ! Modules::code_available( $m[1] ) ) { // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the premium token split.
			return;
		}
		require_once ZINN_SEO_DIR . $map[ $name ];
	}

	/**
	 * The map (free, plus premium when it ships).
	 *
	 * @return array<string, string>
	 */
	public static function map(): array {
		if ( null === self::$map ) {
			$map = require __DIR__ . '/classmap.php';
			// The directory name is split so the free package never carries the premium token.
			$pro = __DIR__ . '/pro_' . '_premium_only/classmap.php'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
			if ( is_readable( $pro ) ) {
				$map += (array) require $pro;
			}
			self::$map = is_array( $map ) ? $map : array();
		}

		return self::$map;
	}

	/**
	 * Is this file one the map loads on use (so a `require_once` of it at start-up is not needed)?
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function maps_file( string $path ): bool {
		static $files = null;
		if ( null === $files ) {
			$files = array_fill_keys( array_values( self::map() ), true );
		}
		$dir = (string) ZINN_SEO_DIR;

		return 0 === strpos( $path, $dir ) && isset( $files[ substr( $path, strlen( $dir ) ) ] );
	}
}
