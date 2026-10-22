<?php
/**
 * Bulk saves that do not set off every other plugin's save work (HARDEN1, from zinnhub).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Content;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ A BULK CHANGE MUST NOT MAKE EVERY OTHER PLUGIN QUEUE WORK FOR EVERY PAGE (HARDEN1).
 *
 * On zinnhub, 2026-10-19 17:06-17:49Z, one link-plan apply saved 2,403 pages through
 * `wp_update_post()`. Each save ran every plugin's save actions: a translation plugin re-queued the
 * page in 45 languages, an AI extract, a vector reindex, a search reindex — ~13,900 Action
 * Scheduler actions (2,057 AI calls and ~9,875 translations paid by the owner), a past-due backlog
 * that took the site's runners seven hours to drain. Zinn SEO itself queued nothing.
 *
 * Inside a background job's step (a bulk change: the link plan, Fix it on autopilot, link updates,
 * image text, AI product rewrites), a page is still saved with `wp_update_post()` — one revision.
 * WordPress's own save work (caches, revisions, `post_modified`), Zinn SEO's own (the score, the
 * link index, IndexNow, the sitemap's dates) and WooCommerce's (a shop's own data) all run, but
 * OTHER plugins' callbacks on the post-save actions are held for that one save and put back right
 * after. Filters that shape the saved data are never touched, and the slug is never changed (the
 * SAVE rule: a bulk save never re-keys a translated address).
 *
 * A site whose other plugins must react to every change (for example a translation plugin that must
 * re-translate a page whose links changed) turns on `jobs_full_saves` (Settings → Background jobs):
 * then every save is a full save, and `Jobs::over()` paces the job by what those saves queue. A
 * person's own one-page change (not in a job) is always a full save. Filter
 * `zinn_seo_quiet_save_keep` keeps a named callback running.
 */
final class Quiet_Save {

	/** The actions a post save fires that other plugins hang their own work on. */
	private const HOOKS = array(
		'pre_post_update',
		'edit_post',
		'post_updated',
		'save_post',
		'wp_insert_post',
		'wp_after_insert_post',
		'transition_post_status',
		'edit_attachment',
		'attachment_updated',
	);

	/**
	 * Saves made quietly in this request (for the step's record and tests).
	 *
	 * @var int
	 */
	private static int $quiet = 0;

	/**
	 * Other plugins' callbacks held so far in this request.
	 *
	 * @var int
	 */
	private static int $held = 0;

	/**
	 * Whether a save now is quiet: inside a background job's step, unless the site asked for full
	 * saves.
	 *
	 * @return bool
	 */
	public static function on(): bool {
		if ( '' === Jobs::current() ) {
			return false;
		}
		$full = class_exists( '\\ZinnDigital\\ZinnSEO\\Settings', false ) ? (bool) Settings::get( 'jobs_full_saves', false ) : false;

		/**
		 * Filters whether Zinn SEO's bulk saves hold other plugins' post-save actions.
		 *
		 * @param bool $quiet Default: in a job's step and `jobs_full_saves` off.
		 */
		return (bool) apply_filters( 'zinn_seo_quiet_saves', ! $full );
	}

	/**
	 * Run one save of post `$id` (`$save` calls `wp_update_post()`), quietly when `on()`.
	 *
	 * @param int      $id   Post.
	 * @param callable $save The save.
	 * @return mixed What `$save` returns.
	 */
	public static function run( int $id, callable $save ) {
		if ( ! self::on() ) {
			return $save();
		}
		global $wp_filter;
		$put_back = array();
		foreach ( self::hooks( $id ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}
			$quiet = clone $wp_filter[ $hook ];
			$n     = 0;
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( ! self::keep( $cb['function'], $hook ) ) {
						$quiet->remove_filter( $hook, $cb['function'], (int) $priority );
						++$n;
					}
				}
			}
			if ( $n > 0 ) {
				$put_back[ $hook ]  = $wp_filter[ $hook ];
				$wp_filter[ $hook ] = $quiet; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- held for this one save and put back in `finally`.
				self::$held        += $n;
			}
		}
		++self::$quiet;
		try {
			return $save();
		} finally {
			foreach ( $put_back as $hook => $hook_object ) {
				$wp_filter[ $hook ] = $hook_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- see above.
			}
		}
	}

	/**
	 * The post-save actions a save of `$id` fires (with the post type's and the status's own).
	 *
	 * @param int $id Post.
	 * @return array<int, string>
	 */
	public static function hooks( int $id ): array {
		$hooks  = self::HOOKS;
		$type   = (string) get_post_type( $id );
		$status = (string) get_post_status( $id );
		if ( '' !== $type ) {
			$hooks[] = 'save_post_' . $type;
			$hooks[] = 'edit_post_' . $type;
			if ( '' !== $status ) {
				$hooks[] = $status . '_' . $type;
				$hooks[] = $status . '_to_' . $status;
			}
		}

		return $hooks;
	}

	/**
	 * Does this callback run during a quiet save? WordPress's own (wp-includes, wp-admin) and Zinn
	 * SEO's do; another plugin's or theme's are held unless `zinn_seo_quiet_save_keep` says so.
	 *
	 * @param mixed  $callback   Callback.
	 * @param string $hook Action.
	 * @return bool
	 */
	public static function keep( $callback, string $hook ): bool {
		$file = self::file_of( $callback );
		$keep = '' !== $file && ( self::under( $file, ABSPATH . WPINC ) || self::under( $file, ABSPATH . 'wp-admin' ) || ( defined( 'ZINN_SEO_DIR' ) && self::under( $file, (string) ZINN_SEO_DIR ) ) );
		// WooCommerce's own save work keeps a shop's data whole (its lookup tables and caches):
		// it queues nothing per save, so it runs.
		if ( ! $keep && '' !== $file && defined( 'WP_PLUGIN_DIR' ) ) {
			$keep = self::under( $file, WP_PLUGIN_DIR . '/woocommerce' );
		}

		/**
		 * Filters whether a callback on a post-save action runs during a Zinn SEO bulk save.
		 *
		 * @param bool   $keep     WordPress's, WooCommerce's and Zinn SEO's: true; every other: false.
		 * @param mixed  $callback The callback.
		 * @param string $hook     The action.
		 * @param string $file     The file the callback is defined in ('' unknown).
		 */
		return (bool) apply_filters( 'zinn_seo_quiet_save_keep', $keep, $callback, $hook, $file );
	}

	/**
	 * Is `$file` inside directory `$dir`? Pure.
	 *
	 * @param string $file File.
	 * @param string $dir  Directory.
	 * @return bool
	 */
	private static function under( string $file, string $dir ): bool {
		$dir  = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
		$file = str_replace( '\\', '/', $file );

		return 0 === strpos( $file, $dir );
	}

	/**
	 * The file a callback is defined in ('' when it cannot be told).
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	public static function file_of( $callback ): string {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && false === strpos( $callback, '::' ) && function_exists( $callback ) ) ) {
				$r = new \ReflectionFunction( $callback );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$r                      = new \ReflectionMethod( $class, $method );
			} elseif ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ) ) {
				$r = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$r = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$file = $r->getFileName();

		return is_string( $file ) ? $file : '';
	}

	/**
	 * Saves made quietly, and other plugins' callbacks held, in this request.
	 *
	 * @return array{saves: int, held: int}
	 */
	public static function counts(): array {
		return array(
			'saves' => self::$quiet,
			'held'  => self::$held,
		);
	}
}
