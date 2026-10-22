<?php
/**
 * Capabilities and the two SEO roles (zs-244).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every check in this plugin is on one of these capabilities, never on `manage_options`, which is
 * what lets a colleague look after SEO without being an administrator.
 *
 *  - `zseo_manage`     site-wide SEO settings, setup, modules, import/export;
 *  - `zseo_edit`       a post's or term's SEO fields (on top of being allowed to edit that post);
 *  - `zseo_view`       SEO reports and dashboards;
 *  - `zseo_redirects`  redirects and the 404 log;
 *  - `zseo_ai`         AI features (with the site's own AI key);
 *  - `zseo_spend`      anything that spends the site owner's Zinn® credits. Administrators only by
 *                      default, never part of a role here: spending money is the owner's call.
 *
 * ⭐ Administrators (and network administrators) hold every one of them through `user_has_cap`,
 * computed, never written to the database — so there is nothing to grant on activation, nothing
 * to repair when a role editor strips it, and nothing to remove on uninstall for them.
 *
 * Two roles are added: SEO Manager (an Editor plus every capability except spending) and SEO
 * Editor (an Editor plus `zseo_edit` and `zseo_view`).
 */
final class Capabilities {

	public const MANAGE    = 'zseo_manage';
	public const EDIT      = 'zseo_edit';
	public const VIEW      = 'zseo_view';
	public const REDIRECTS = 'zseo_redirects';
	public const AI        = 'zseo_ai';
	public const SPEND     = 'zseo_spend';

	public const ROLE_MANAGER = 'zseo_manager';
	public const ROLE_EDITOR  = 'zseo_editor';

	/** Stamp of the roles, so they are re-applied when this list changes. */
	private const VERSION = 1;

	/**
	 * Every capability, with what it allows (for the roles screen and the docs).
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE    => __( 'Change site-wide SEO settings, run the setup and import or export settings', 'zinn-seo' ),
			self::EDIT      => __( 'Edit the SEO fields of posts and terms the person may already edit', 'zinn-seo' ),
			self::VIEW      => __( 'See SEO reports and dashboards', 'zinn-seo' ),
			self::REDIRECTS => __( 'Manage redirects and the list of missing pages', 'zinn-seo' ),
			self::AI        => __( 'Use AI features with the site\'s own AI key', 'zinn-seo' ),
			self::SPEND     => __( 'Spend the site owner\'s Zinn® credits', 'zinn-seo' ),
		);
	}

	/**
	 * The capability names alone.
	 *
	 * ⛔ Used by the `user_has_cap` filter, which another plugin may trigger while plugins are
	 * still loading (SEOPress asks `current_user_can()` on `plugins_loaded`): nothing here may
	 * call a translation function, or WordPress logs "translation loading was triggered too
	 * early" on every signed-in request (measured 2026-10-10 with SEOPress 10.3 active).
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array( self::MANAGE, self::EDIT, self::VIEW, self::REDIRECTS, self::AI, self::SPEND );
	}

	/**
	 * The capabilities a feature needs; modules ask here instead of naming a capability.
	 *
	 * @param string $feature `settings`, `fields`, `reports`, `redirects`, `ai`, `credits`.
	 * @return string
	 */
	public static function for_feature( string $feature ): string {
		$map = array(
			'settings'  => self::MANAGE,
			'fields'    => self::EDIT,
			'reports'   => self::VIEW,
			'redirects' => self::REDIRECTS,
			'ai'        => self::AI,
			'credits'   => self::SPEND,
		);

		return $map[ $feature ] ?? self::MANAGE;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_administrators' ), 10, 4 );
		add_action( 'admin_init', array( self::class, 'maybe_add_roles' ) );
	}

	/**
	 * Administrators hold every capability here, computed per check.
	 *
	 * @param array<string, bool> $allcaps Capabilities the user has.
	 * @param array<int, string>  $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    The check's arguments.
	 * @param \WP_User|null       $user    The user.
	 * @return array<string, bool>
	 */
	public static function grant_administrators( $allcaps, $caps = array(), $args = array(), $user = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's signature.
		$allcaps = (array) $allcaps;
		if ( empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}
		foreach ( self::names() as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Add (or refresh) the two roles once per VERSION.
	 *
	 * @return void
	 */
	public static function maybe_add_roles(): void {
		if ( (int) get_option( 'zseo_roles', 0 ) >= self::VERSION ) {
			return;
		}
		self::add_roles();
	}

	/**
	 * Add the SEO Manager and SEO Editor roles, built on the Editor role's own capabilities so
	 * they keep up with whatever the site's Editor may do.
	 *
	 * @return void
	 */
	public static function add_roles(): void {
		$editor = get_role( 'editor' );
		$base   = $editor instanceof \WP_Role ? (array) $editor->capabilities : array(
			'read'       => true,
			'edit_posts' => true,
		);

		remove_role( self::ROLE_MANAGER );
		remove_role( self::ROLE_EDITOR );
		add_role(
			self::ROLE_MANAGER,
			__( 'SEO Manager', 'zinn-seo' ),
			array_merge(
				$base,
				array(
					self::MANAGE    => true,
					self::EDIT      => true,
					self::VIEW      => true,
					self::REDIRECTS => true,
					self::AI        => true,
				)
			)
		);
		add_role(
			self::ROLE_EDITOR,
			__( 'SEO Editor', 'zinn-seo' ),
			array_merge(
				$base,
				array(
					self::EDIT => true,
					self::VIEW => true,
				)
			)
		);
		update_option( 'zseo_roles', self::VERSION, false );
	}

	/**
	 * Remove the roles, and every SEO capability given to another role (uninstall). Users who held
	 * one of the two roles fall back to no role, which WordPress shows as "No role for this site"
	 * so an administrator can see and fix it.
	 *
	 * @return void
	 */
	public static function remove_roles(): void {
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			$role = get_role( (string) $slug );
			if ( $role instanceof \WP_Role ) {
				foreach ( array_keys( self::all() ) as $cap ) {
					if ( $role->has_cap( $cap ) ) {
						$role->remove_cap( $cap );
					}
				}
			}
		}
		remove_role( self::ROLE_MANAGER );
		remove_role( self::ROLE_EDITOR );
		// The Agency plan's client login role (Pro), whose users fall back to no role.
		remove_role( 'zseo_client' );
		delete_option( 'zseo_roles' );
	}

	/**
	 * May the current user change site-wide settings?
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::MANAGE );
	}

	/**
	 * May the current user edit the SEO fields of this post?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_edit_post( int $post_id ): bool {
		return current_user_can( self::EDIT ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * May the current user edit the SEO fields of this term?
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function can_edit_term( int $term_id ): bool {
		return current_user_can( self::EDIT ) && current_user_can( 'edit_term', $term_id );
	}
}
