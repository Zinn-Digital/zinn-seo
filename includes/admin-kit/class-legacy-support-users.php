<?php
/**
 * Removes the temporary support users an earlier version of this plugin could create.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-legacy-support-users.php by wp/bin/build-admin-kit.php.
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
 * Clean-up only: the plugin no longer creates support users and never sends a login anywhere.
 *
 * ⛔⛔ WordPress.org review, 2026-10-01 ("Remote administration"): earlier versions let the site
 * owner create an expiring "support" user from the support screen, and its login details were
 * sent with the support ticket. That path is gone from every plugin carrying this kit. A ticket
 * carries the message and, with consent, diagnostics, and nothing that signs anyone in. If the
 * owner wants someone to look at the site, they create and share a user themselves in wp-admin.
 *
 * What remains acts only on THIS site, for its own users: any support user an earlier version
 * created (marked by {@see self::META_EXPIRES}) is refused at the login form and deleted the next
 * time an administrator opens wp-admin, and the role and the hourly cron those versions added
 * are removed.
 */
final class Legacy_Support_Users {

	/** The role earlier versions created. */
	public const ROLE = 'zinn_support_temp';

	/** The user meta earlier versions stamped on each support user they created. */
	public const META_EXPIRES = 'zinn_support_expires_at';

	/** The cron hook earlier versions scheduled. */
	public const CRON = 'zseo_kit_expire_support_access';

	/**
	 * Hook the clean-up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'remove' ) );
		add_filter( 'authenticate', array( self::class, 'refuse_login' ), 99 );
	}

	/**
	 * Whether a user is one an earlier version created for support.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_legacy_user( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_EXPIRES, true );
	}

	/**
	 * Delete every legacy support user, drop the role, clear the cron. Idempotent and cheap once
	 * done: one indexed usermeta lookup on admin pages.
	 *
	 * @return int How many users were deleted.
	 */
	public static function remove(): int {
		if ( ! current_user_can( 'delete_users' ) ) {
			return 0;
		}
		$ids     = get_users(
			array(
				'meta_key' => self::META_EXPIRES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- usermeta is indexed on meta_key; a handful of users at most.
				'fields'   => 'ID',
				'number'   => 100,
			)
		);
		$deleted = 0;
		if ( array() !== $ids ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $ids as $id ) {
				if ( get_current_user_id() !== (int) $id && wp_delete_user( (int) $id ) ) {
					++$deleted;
				}
			}
		}
		if ( null !== get_role( self::ROLE ) ) {
			remove_role( self::ROLE );
		}
		if ( false !== wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}

		return $deleted;
	}

	/**
	 * Refuse a legacy support user at the login form, before the clean-up has run.
	 *
	 * @param \WP_User|\WP_Error|null $user The authentication result so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function refuse_login( $user ) {
		if ( $user instanceof \WP_User && self::is_legacy_user( (int) $user->ID ) ) {
			return new \WP_Error( 'zinn_support_access_removed', __( 'This temporary support access has expired.', 'zinn-seo' ) );
		}

		return $user;
	}
}
