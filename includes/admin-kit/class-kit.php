<?php
/**
 * The admin kit's entry point.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-kit.php by wp/bin/build-admin-kit.php.
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
 * Boots the kit for its host plugin and hands the React shell its data.
 *
 * ⭐ Every plugin that carries the kit has its own rendered copy in its own namespace
 * (`<host>\AdminKit`, docs/adr/0034's pattern), so two plugins on one site at different versions
 * never collide. The only state the copies share is deliberate and named where it is written:
 * the per-user preferences (`zinn_kit_prefs`), and the temporary support users and their role.
 */
final class Kit {

	/**
	 * The host plugin.
	 *
	 * @var array<string, string>
	 */
	private static array $host = array();

	/**
	 * Start the kit. Called once from the host's bootstrap.
	 *
	 * @param array<string, string> $host `slug`, `name`, `version`, `fs` (the SDK accessor
	 *                                    function's name), `rest_namespace`, `menu_slug`, `file`.
	 * @return void
	 */
	public static function boot( array $host ): void {
		self::$host = array_map( 'strval', $host );

		Screens::register();
		Licence::register();
		Legacy_Support_Users::register();
		Rest::register();
		if ( class_exists( __NAMESPACE__ . '\\Language_Packs', false ) ) {
			Language_Packs::register();
		}
	}

	/**
	 * A host fact.
	 *
	 * @param string $key Key given to boot().
	 * @return string Empty when unknown.
	 */
	public static function host( string $key ): string {
		return self::$host[ $key ] ?? '';
	}

	/**
	 * The host's licensing SDK instance, or null when it did not load.
	 *
	 * @return object|null
	 */
	public static function fs(): ?object {
		$accessor = self::host( 'fs' );
		if ( '' === $accessor || ! function_exists( $accessor ) ) {
			return null;
		}
		$fs = $accessor();

		return is_object( $fs ) ? $fs : null;
	}

	/**
	 * The URL of one of the shell's screens.
	 *
	 * @param string $view Route id, e.g. `help`.
	 * @return string
	 */
	public static function screen_url( string $view = '' ): string {
		$url = admin_url( 'admin.php?page=' . rawurlencode( self::host( 'menu_slug' ) ) );

		return '' === $view ? $url : add_query_arg( 'view', rawurlencode( $view ), $url );
	}

	/**
	 * Give the shell its boot data. Called by the host from its own enqueue, on its own screen.
	 *
	 * ⛔ Printed into the page, so nothing secret belongs here: no token, no licence key, no
	 * credential. The connection card gets its status from a REST call instead.
	 *
	 * @param string $handle The host's admin script handle.
	 * @return void
	 */
	public static function enqueue( string $handle ): void {
		wp_add_inline_script(
			$handle,
			'window.zinnAdminKit = window.zinnAdminKit || {}; window.zinnAdminKit[' . wp_json_encode( self::host( 'slug' ) ) . '] = ' . wp_json_encode( self::boot_data() ) . ';',
			'before'
		);
	}

	/**
	 * Everything the shell needs to render without a round trip.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_data(): array {
		$slug     = self::host( 'slug' );
		$products = Data::get( 'products' );
		$help     = (array) ( Data::get( 'help' )[ $slug ] ?? array() );
		$product  = Data::product( $slug );
		$user     = wp_get_current_user();

		return array(
			'slug'          => $slug,
			'name'          => self::host( 'name' ),
			'version'       => self::host( 'version' ),
			'restNamespace' => self::host( 'rest_namespace' ),
			'screenUrl'     => self::screen_url(),
			'adminUrl'      => admin_url(),
			'licence'       => Licence::snapshot(),
			'plans'         => Matrix::plans(),
			'matrix'        => Matrix::for_js(),
			'prefs'         => Prefs::get(),
			'wizard'        => self::wizard_state(),
			'promotions'    => Promotions::all(),
			'help'          => array(
				'docsRoot' => (string) ( $product['docs_root'] ?? ( $products['company_url'] ?? '' ) ),
				'guides'   => $help,
			),
			'privacyUrl'    => (string) ( $products['privacy_url'] ?? '' ),
			'companyUrl'    => (string) ( $products['company_url'] ?? '' ),
			'support'       => array(
				'consented' => Support::has_consent(),
				'email'     => $user instanceof \WP_User ? (string) $user->user_email : '',
				'name'      => $user instanceof \WP_User ? (string) $user->display_name : '',
				'recipient' => 'Zinn Digital® Ltd',
			),
			'rtl'           => is_rtl(),
		);
	}

	/**
	 * Whether the first-run wizard has been completed or skipped on this site.
	 *
	 * @return array{state: string, at: int}
	 */
	public static function wizard_state(): array {
		$stored = get_option( 'zseo_kit_wizard', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = (string) ( $stored['state'] ?? '' );

		return array(
			'state' => in_array( $state, array( 'done', 'skipped' ), true ) ? $state : 'new',
			'at'    => (int) ( $stored['at'] ?? 0 ),
		);
	}

	/**
	 * Record the wizard's outcome.
	 *
	 * @param string $state `done`, `skipped` or `new` (start again).
	 * @return array{state: string, at: int}
	 */
	public static function set_wizard_state( string $state ): array {
		if ( 'new' === $state ) {
			delete_option( 'zseo_kit_wizard' );
		} elseif ( in_array( $state, array( 'done', 'skipped' ), true ) ) {
			update_option(
				'zseo_kit_wizard',
				array(
					'state' => $state,
					'at'    => time(),
				),
				false
			);
		}

		return self::wizard_state();
	}
}
