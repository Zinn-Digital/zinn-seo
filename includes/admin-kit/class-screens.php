<?php
/**
 * Which admin screens are ours, and keeping every offer on them.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-screens.php by wp/bin/build-admin-kit.php.
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
 * Keeps upgrade offers, trials and promotions on the plugin's own screens (features adm-4,
 * adm-5, adm-6, sh-r7; WordPress.org guideline 11).
 *
 * ⛔⛔ The kit itself never hooks `admin_notices`, `all_admin_notices` or the dashboard: every
 * card it draws is part of the React shell, which only the host's own page loads. What this
 * class governs is the licensing SDK, which on its own would show its trial offer and its
 * affiliate invitation as notices on EVERY admin page. Those are routed here: shown on our
 * screens, suppressed everywhere else.
 */
final class Screens {

	/**
	 * Hook the SDK's notice filters.
	 *
	 * @return void
	 */
	public static function register(): void {
		$fs = Kit::fs();
		if ( null === $fs || ! method_exists( $fs, 'add_filter' ) ) {
			return;
		}
		$fs->add_filter( 'show_admin_notice', array( self::class, 'filter_sdk_notice' ), 10, 2 );
		$fs->add_filter( 'show_trial', array( self::class, 'only_on_ours' ) );
		$fs->add_filter( 'show_affiliate_program_notice', array( self::class, 'only_on_ours' ) );
		$fs->add_filter( 'support_forum_url', array( self::class, 'support_url' ) );
	}

	/**
	 * Is the current admin screen one of the host plugin's own?
	 *
	 * The host's top-level page is `toplevel_page_<menu>`; its sub-pages (including the ones the
	 * licensing SDK adds under our menu: account, pricing) are `<menu>_page_<…>`.
	 *
	 * @return bool
	 */
	public static function is_ours(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		$menu   = Kit::host( 'menu_slug' );
		if ( null === $screen || '' === $menu ) {
			return false;
		}
		$id = (string) $screen->id;

		return 'toplevel_page_' . $menu === $id || str_starts_with( $id, $menu . '_page_' ) || str_starts_with( $id, sanitize_title( Kit::host( 'name' ) ) . '_page_' );
	}

	/**
	 * `show_admin_notice`: a promotional SDK notice shows on our screens only; every other kind
	 * (an activation result, an error) is left as the SDK decided.
	 *
	 * @param bool                 $show Whether the SDK would show it.
	 * @param array<string, mixed> $msg  The SDK's message, with `type`.
	 * @return bool
	 */
	public static function filter_sdk_notice( $show, $msg ): bool {
		$type = is_array( $msg ) ? (string) ( $msg['type'] ?? '' ) : '';

		return (bool) $show && ( 'promotion' !== $type || self::is_ours() );
	}

	/**
	 * A filter that answers "only on our screens".
	 *
	 * @param bool $show The SDK's default.
	 * @return bool
	 */
	public static function only_on_ours( $show ): bool {
		return (bool) $show && self::is_ours();
	}

	/**
	 * Point the SDK's "support" link at our Get help screen: one support inbox (docs/843 D17).
	 *
	 * @return string
	 */
	public static function support_url(): string {
		return Kit::screen_url( 'help' );
	}
}
