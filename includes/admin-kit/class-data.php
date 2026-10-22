<?php
/**
 * The kit's data files.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-data.php by wp/bin/build-admin-kit.php.
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
 * Reads the JSON files rendered beside the classes (`data/*.json`), once per request.
 *
 * ⭐ Data, not code, so adding a product, a promotion or a help link is a change to a JSON row
 * that the render copies into every host. The files ship inside the plugin; nothing here reads
 * the network.
 */
final class Data {

	/**
	 * Decoded files, by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $cache = array();

	/**
	 * One data file, decoded. A missing or damaged file is an empty array: every reader has a
	 * safe default, and a broken data file must not take the admin screen down with it.
	 *
	 * @param string $name `products`, `plans`, `promotions`, `help` or `matrix`.
	 * @return array<string, mixed>
	 */
	public static function get( string $name ): array {
		if ( ! isset( self::$cache[ $name ] ) ) {
			$file    = __DIR__ . '/data/' . sanitize_key( $name ) . '.json';
			$decoded = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.

			self::$cache[ $name ] = is_array( $decoded ) ? $decoded : array();
		}

		return self::$cache[ $name ];
	}

	/**
	 * A product row from products.json.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string, mixed>
	 */
	public static function product( string $slug ): array {
		$products = (array) ( self::get( 'products' )['products'] ?? array() );

		return (array) ( $products[ $slug ] ?? array() );
	}

	/**
	 * Every product row, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function products(): array {
		return (array) ( self::get( 'products' )['products'] ?? array() );
	}

	/**
	 * Forget the cache (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = array();
	}
}
