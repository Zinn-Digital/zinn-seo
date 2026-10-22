<?php
/**
 * Per-user preferences for the admin shell.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-prefs.php by wp/bin/build-admin-kit.php.
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
 * Light/dark choice, dismissed cards and finished tours, remembered per USER.
 *
 * ⛔ Per user, never per site: a site with three administrators has three opinions about
 * whether they want to see a promotion, and an option would let the first decide for all.
 *
 * ⭐ One meta key for every plugin that carries the kit (`zinn_kit_prefs`), on purpose: a card
 * dismissed in one of our plugins stays dismissed in the other, and the colour mode follows the
 * person rather than the plugin. Every copy merges on write, so an older copy never drops a field
 * a newer one added.
 */
final class Prefs {

	/** The user-meta key, shared by every copy. */
	public const META = 'zinn_kit_prefs';

	/** Allowed colour modes. `auto` follows the operating system. */
	public const THEMES = array( 'auto', 'light', 'dark' );

	/**
	 * The current user's preferences.
	 *
	 * @param int $user_id User id; 0 for the current user.
	 * @return array{theme: string, dismissed: array<int, string>, tours: array<int, string>}
	 */
	public static function get( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$raw     = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : array();

		return self::clean( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Apply a change and save it.
	 *
	 * @param array<string, mixed> $change `theme`, `dismiss` (an id), `restore` (an id), `restore_all`
	 *                                     (true: every hidden card at once) or `tour` (a route id).
	 * @param int                  $user_id User id; 0 for the current user.
	 * @return array{theme: string, dismissed: array<int, string>, tours: array<int, string>}
	 */
	public static function update( array $change, int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$stored  = get_user_meta( $user_id, self::META, true );
		$stored  = is_array( $stored ) ? $stored : array();
		$prefs   = self::clean( $stored );

		if ( isset( $change['theme'] ) && in_array( $change['theme'], self::THEMES, true ) ) {
			$prefs['theme'] = (string) $change['theme'];
		}
		if ( isset( $change['dismiss'] ) && '' !== self::id( $change['dismiss'] ) ) {
			$prefs['dismissed'][] = self::id( $change['dismiss'] );
		}
		// ⛔ One request for "show them all": a request per card races, because each one reads the
		// list, removes its id and writes the list back, so the last write re-hides the others.
		if ( ! empty( $change['restore_all'] ) ) {
			$prefs['dismissed'] = array();
		}
		if ( isset( $change['restore'] ) ) {
			$prefs['dismissed'] = array_values( array_diff( $prefs['dismissed'], array( self::id( $change['restore'] ) ) ) );
		}
		if ( isset( $change['tour'] ) && '' !== self::id( $change['tour'] ) ) {
			$prefs['tours'][] = self::id( $change['tour'] );
		}
		$prefs = self::clean( $prefs );

		// Keep any field a newer copy of the kit wrote that this copy does not know about.
		update_user_meta( $user_id, self::META, array_merge( $stored, $prefs ) );

		return $prefs;
	}

	/**
	 * Normalise a stored value.
	 *
	 * @param array<string, mixed> $raw Stored value.
	 * @return array{theme: string, dismissed: array<int, string>, tours: array<int, string>}
	 */
	private static function clean( array $raw ): array {
		$theme = (string) ( $raw['theme'] ?? 'auto' );

		return array(
			'theme'     => in_array( $theme, self::THEMES, true ) ? $theme : 'auto',
			'dismissed' => self::ids( $raw['dismissed'] ?? array() ),
			'tours'     => self::ids( $raw['tours'] ?? array() ),
		);
	}

	/**
	 * A list of ids, cleaned, unique, capped.
	 *
	 * @param mixed $stored Stored list.
	 * @return array<int, string>
	 */
	private static function ids( $stored ): array {
		$out = array();
		foreach ( is_array( $stored ) ? $stored : array() as $item ) {
			$id = self::id( $item );
			if ( '' !== $id ) {
				$out[ $id ] = $id;
			}
		}

		return array_slice( array_values( $out ), -100 );
	}

	/**
	 * One id: lowercase letters, digits and dashes, at most 64.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function id( $value ): string {
		return is_scalar( $value ) ? substr( sanitize_key( (string) $value ), 0, 64 ) : '';
	}
}
