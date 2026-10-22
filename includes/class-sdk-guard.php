<?php
/**
 * The licensing SDK never saves a FILTERED plugin list over the site's real one.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * While plugins load, the licensing SDK (Freemius, `fs_newest_sdk_plugin_first()`) moves the
 * plugin carrying its newest copy to the front of `active_plugins` and SAVES the list. It reads
 * the list through `get_option()`, so when anything filters `option_active_plugins` (a plugin
 * that loads plugins per page, a host's guard, a preview tool) the FILTERED list is what gets
 * saved: plugins switched off for one request stay off for good, and plugins switched on for one
 * request stay on. That happened on zinnhub.com twice on 2026-10-14 (a preview of this plugin was
 * saved as the live plugin list and served to visitors).
 *
 * So from this plugin's load until `plugins_loaded`, a write of `active_plugins` that contains
 * exactly the filtered list — not the stored one — is turned back into the stored list with the
 * same plugin moved to the front. Any other write passes untouched.
 */
final class Sdk_Guard {

	/**
	 * Armed.
	 *
	 * @var bool
	 */
	private static bool $armed = false;

	/**
	 * Start guarding (called by the main file just before the SDK starts).
	 *
	 * @return void
	 */
	public static function arm(): void {
		if ( self::$armed || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$armed = true;
		add_filter( 'pre_update_option_active_plugins', array( self::class, 'keep_stored' ), -1000, 2 );
		add_action( 'plugins_loaded', array( self::class, 'disarm' ), -1000 );
	}

	/**
	 * Stop guarding (everything has loaded).
	 *
	 * @return void
	 */
	public static function disarm(): void {
		remove_filter( 'pre_update_option_active_plugins', array( self::class, 'keep_stored' ), -1000 );
		self::$armed = false;
	}

	/**
	 * `pre_update_option_active_plugins`.
	 *
	 * @param mixed $value The list about to be saved.
	 * @param mixed $old   The list as read (filtered).
	 * @return mixed
	 */
	public static function keep_stored( $value, $old ) {
		if ( ! is_array( $value ) || ! function_exists( 'wp_load_alloptions' ) ) {
			return $value;
		}
		$all    = wp_load_alloptions();
		$stored = isset( $all['active_plugins'] ) ? maybe_unserialize( $all['active_plugins'] ) : null;
		if ( ! is_array( $stored ) ) {
			return $value;
		}

		return self::repair( array_values( array_map( 'strval', $stored ) ), is_array( $old ) ? array_values( array_map( 'strval', $old ) ) : array(), array_values( array_map( 'strval', $value ) ) );
	}

	/**
	 * The list to save. Pure, for the tests.
	 *
	 * @param array<int, string> $stored  What the site has saved.
	 * @param array<int, string> $seen    What the writer read (after filters).
	 * @param array<int, string> $written What the writer wants to save.
	 * @return array<int, string>
	 */
	public static function repair( array $stored, array $seen, array $written ): array {
		$same = static function ( array $a, array $b ): bool {
			sort( $a );
			sort( $b );
			return $a === $b;
		};
		// Only a reorder of a list that differs from the stored one is repaired.
		if ( $same( $written, $stored ) || ! $same( $written, $seen ) || array() === $written ) {
			return $written;
		}
		$first = $written[0];
		if ( ! in_array( $first, $stored, true ) ) {
			return $stored;
		}

		return array_values( array_merge( array( $first ), array_diff( $stored, array( $first ) ) ) );
	}
}
