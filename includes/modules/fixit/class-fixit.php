<?php
/**
 * The "Fix it" inbox: one prioritised list of what to fix across the site (zs-242, zs-inn-10).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Fixit;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every module that can find a problem adds a SOURCE (`zinn_seo_fixit_sources`): a callable that
 * returns issues, each with a priority, how many pages it touches, an estimate of the minutes it
 * takes, and its fix — an ACTION run here with one click (allow search engines, build the link
 * index, score every page, find duplicates, rebuild the sitemaps) or a LINK to the exact screen
 * or editor where it is fixed. Later stages add their own sources (redirects and 404s, Search
 * Console, AI visibility, backlinks) without touching this file.
 *
 * Issues are worked out when the inbox is opened (indexed counts only) and kept five minutes;
 * a person can snooze an issue for a week or dismiss it for good.
 */
final class Fixit {

	/** Option of dismissed issues: id => until (0 = for good). */
	public const DISMISSED = 'zseo_fixit_dismissed';

	/** Cache key. */
	private const CACHE = 'zseo_fixit';

	/**
	 * The issues "Fix it" on autopilot (Pro, zs-767) can fix by itself, for the way to it on every
	 * edition (the Pro module's remedies are the source of truth on Pro; a unit test keeps this
	 * list equal to them). Link issues already carry the whole-site link plan's way (`more`).
	 */
	public const AUTOPILOT_ISSUES = array( 'check-desc_length', 'duplicates-title', 'duplicates-desc', 'check-alt_missing', 'content-unscored', 'links-index', 'duplicates-scan', 'site-noindex' );

	/**
	 * Registered one-click actions: id => [callable, capability check].
	 *
	 * @var array<string, callable>
	 */
	private static array $actions = array();

	/**
	 * Register a one-click fix.
	 *
	 * @param string   $id     Action id.
	 * @param callable $run    Returns true|\WP_Error.
	 * @return void
	 */
	public static function action( string $id, callable $run ): void {
		self::$actions[ $id ] = $run;
	}

	/**
	 * Run a one-click fix.
	 *
	 * @param string $id Action id.
	 * @return true|\WP_Error
	 */
	public static function run( string $id ) {
		self::register_core();
		if ( ! isset( self::$actions[ $id ] ) ) {
			return new \WP_Error( 'zinn_seo_unknown_fix', __( 'There is no such fix.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$out = call_user_func( self::$actions[ $id ] );
		delete_transient( self::CACHE );
		if ( ! is_wp_error( $out ) ) {
			/**
			 * Fires when a one-click fix ran (the client report's "work done" counts it).
			 *
			 * @param string $id The fix.
			 */
			do_action( 'zinn_seo_fixit_fixed', $id );
		}

		return is_wp_error( $out ) ? $out : true;
	}

	/**
	 * Every open issue, highest priority first.
	 *
	 * @param bool $fresh Ignore the five-minute cache.
	 * @return array<string, mixed>
	 */
	public static function issues( bool $fresh = false ): array {
		self::register_core();
		// ⛔ One list per LANGUAGE. An issue's title and detail are translated when it is worked
		// out, so a list kept under one key was shown, for five minutes, in the language of
		// whoever opened the inbox first (an Arabic reader saw English; found on the demo,
		// 2026-10-11). Still one transient, so one delete forgets every language's list.
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
		$store  = $fresh ? array() : get_transient( self::CACHE );
		$store  = is_array( $store ) && ! isset( $store['issues'] ) ? $store : array();
		$cached = $store[ $locale ] ?? null;
		if ( ! is_array( $cached ) || ! isset( $cached['issues'], $cached['at'] ) || (int) $cached['at'] < time() - 5 * MINUTE_IN_SECONDS ) {
			$all = array();
			/**
			 * Filters the sources of the Fix-it inbox: callables returning lists of issues
			 * (`id`, `area`, `priority` 1-100, `title`, `detail`, `count`, `minutes`, `fix`).
			 *
			 * @param array<string, callable> $sources Source id => callable.
			 */
			foreach ( (array) apply_filters( 'zinn_seo_fixit_sources', self::core_sources() ) as $source ) {
				if ( ! is_callable( $source ) ) {
					continue;
				}
				foreach ( (array) call_user_func( $source ) as $issue ) {
					if ( is_array( $issue ) && isset( $issue['id'], $issue['title'] ) ) {
						$all[] = $issue + array(
							'area'     => 'content',
							'priority' => 50,
							'detail'   => '',
							'count'    => 0,
							'minutes'  => 0,
							'fix'      => null,
							'items'    => array(),
						);
					}
				}
			}
			usort( $all, static fn( $a, $b ) => (int) $b['priority'] !== (int) $a['priority'] ? (int) $b['priority'] <=> (int) $a['priority'] : strcmp( (string) $a['id'], (string) $b['id'] ) );
			$cached           = array(
				'at'     => time(),
				'issues' => $all,
			);
			$store[ $locale ] = $cached;
			set_transient( self::CACHE, $store, 5 * MINUTE_IN_SECONDS );
		}
		$dismissed = self::dismissed();
		$open      = array();
		$hidden    = 0;
		foreach ( (array) $cached['issues'] as $issue ) {
			$until = $dismissed[ (string) $issue['id'] ] ?? null;
			if ( null !== $until && ( 0 === (int) $until || (int) $until > time() ) ) {
				++$hidden;
				continue;
			}
			// The whole-site way depends on the edition NOW, not when the list was cached (a
			// site that goes Pro, or back to Free, sees the right one at once).
			if ( isset( $issue['more'] ) ) {
				$issue['more'] = self::whole_site_links();
			} elseif ( in_array( (string) $issue['id'], self::AUTOPILOT_ISSUES, true ) && 'action' !== (string) ( $issue['fix']['kind'] ?? '' ) ) {
				// An issue with a one-click fix has its button already; the others get the way to
				// fix every page of them at once.
				$issue['auto'] = self::autopilot_link( (string) $issue['id'] );
			}
			$open[] = $issue;
		}

		return array(
			'at'        => (int) $cached['at'],
			'issues'    => $open,
			'dismissed' => $hidden,
			'autopilot' => array() === $open ? null : self::autopilot_link( '' ),
		);
	}

	/**
	 * How many issues are open, from the list already worked out in the last five minutes (in any
	 * language: the issues are the same, only their words differ). Nothing is worked out here, so
	 * the admin screen's navigation can show the count without slowing the page (UI1).
	 *
	 * @return int|null The count, or null when no list is kept.
	 */
	public static function cached_open(): ?int {
		$store  = get_transient( self::CACHE );
		$newest = null;
		foreach ( is_array( $store ) ? $store : array() as $list ) {
			if ( is_array( $list ) && isset( $list['issues'], $list['at'] ) && (int) $list['at'] >= time() - 5 * MINUTE_IN_SECONDS && ( null === $newest || (int) $list['at'] > (int) $newest['at'] ) ) {
				$newest = $list;
			}
		}
		if ( null === $newest ) {
			return null;
		}
		$dismissed = self::dismissed();
		$open      = 0;
		foreach ( (array) $newest['issues'] as $issue ) {
			if ( ! isset( $dismissed[ (string) ( $issue['id'] ?? '' ) ] ) ) {
				++$open;
			}
		}

		return $open;
	}

	/**
	 * Dismissed issues (expired snoozes dropped).
	 *
	 * @return array<string, int>
	 */
	public static function dismissed(): array {
		$d = get_option( self::DISMISSED, array() );

		return is_array( $d ) ? array_filter( $d, static fn( $until ) => 0 === (int) $until || (int) $until > time() ) : array();
	}

	/**
	 * Snooze (a number of days) or dismiss (0) an issue; null brings it back.
	 *
	 * @param string   $id   Issue id.
	 * @param int|null $days Days, 0 for good, null to restore.
	 * @return void
	 */
	public static function dismiss( string $id, ?int $days ): void {
		$d = self::dismissed();
		if ( null === $days ) {
			unset( $d[ $id ] );
		} else {
			$d[ $id ] = 0 === $days ? 0 : time() + $days * DAY_IN_SECONDS;
		}
		update_option( self::DISMISSED, $d, false );
	}

	/**
	 * Restore every dismissed issue.
	 *
	 * @return void
	 */
	public static function restore_all(): void {
		delete_option( self::DISMISSED );
	}

	/**
	 * A link to one of the plugin's screens.
	 *
	 * @param string $view View.
	 * @param string $tab  Tab.
	 * @return string
	 */
	public static function screen( string $view, string $tab = '' ): string {
		return admin_url( 'admin.php?page=zinn-seo&view=' . rawurlencode( $view ) ) . ( '' !== $tab ? '#/' . $tab : '' );
	}

	/**
	 * The way to fix internal links for the whole site at once (zs-763): the link plan on Pro,
	 * the Pro plans on Free.
	 *
	 * @return array{label: string, url: string, pro: bool}
	 */
	public static function whole_site_links(): array {
		if ( Plugin::is_pro() && Modules::enabled( 'link-plan' ) ) {
			return array(
				'label' => __( 'Fix them all with the link plan', 'zinn-seo' ),
				'url'   => self::screen( 'linkplan' ),
				'pro'   => false,
			);
		}

		return array(
			'label' => __( 'Do it for the whole site with Pro', 'zinn-seo' ),
			'url'   => self::screen( 'plans' ),
			'pro'   => true,
		);
	}

	/**
	 * The way to fix the whole list (or one issue of it) on autopilot (zs-767): the autopilot
	 * screen on Pro, the Pro plans on Free.
	 *
	 * @param string $issue One issue id ('' the whole list).
	 * @return array{label: string, url: string, pro: bool}
	 */
	public static function autopilot_link( string $issue ): array {
		if ( Plugin::is_pro() && Modules::enabled( 'autopilot' ) ) {
			return array(
				'label' => '' === $issue ? __( 'Fix everything on autopilot', 'zinn-seo' ) : __( 'Fix them all on autopilot', 'zinn-seo' ),
				'url'   => self::screen( 'autopilot', '' === $issue ? '' : '?issues=' . rawurlencode( $issue ) ),
				'pro'   => false,
			);
		}

		return array(
			'label' => __( 'Do it for the whole site with Pro', 'zinn-seo' ),
			'url'   => self::screen( 'plans' ),
			'pro'   => true,
		);
	}

	/**
	 * The one-click fixes this module knows.
	 *
	 * @return void
	 */
	private static function register_core(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		self::action(
			'allow-indexing',
			static function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return new \WP_Error( 'zinn_seo_forbidden', __( 'Only an administrator can change this.', 'zinn-seo' ), array( 'status' => 403 ) );
				}
				update_option( 'blog_public', '1' );
				return true;
			}
		);
		self::action(
			'score-all',
			static fn() => class_exists( '\ZinnDigital\ZinnSEO\Analysis\Analysis' ) ? \ZinnDigital\ZinnSEO\Analysis\Analysis::start_all() : new \WP_Error( 'zinn_seo_module_off', __( 'The content analysis is switched off.', 'zinn-seo' ) )
		);
		self::action(
			'build-links',
			static function () {
				if ( ! class_exists( '\ZinnDigital\ZinnSEO\Links\Index' ) ) {
					return new \WP_Error( 'zinn_seo_module_off', __( 'The internal links module is switched off.', 'zinn-seo' ) );
				}
				\ZinnDigital\ZinnSEO\Links\Index::maybe_install();
				return Jobs::start( \ZinnDigital\ZinnSEO\Links\Index::JOB, array() );
			}
		);
		self::action(
			'scan-duplicates',
			static fn() => class_exists( '\ZinnDigital\ZinnSEO\Duplicates\Duplicates' ) ? Jobs::start( \ZinnDigital\ZinnSEO\Duplicates\Duplicates::JOB, array() ) : new \WP_Error( 'zinn_seo_module_off', __( 'The duplicate finder is switched off.', 'zinn-seo' ) )
		);

		/**
		 * Fires so other modules register their one-click fixes (`Fixit::action()`).
		 */
		do_action( 'zinn_seo_fixit_actions' );
	}

	/**
	 * This module's own sources.
	 *
	 * @return array<string, callable>
	 */
	public static function core_sources(): array {
		return array(
			'site'       => array( self::class, 'site_issues' ),
			'content'    => array( self::class, 'content_issues' ),
			'links'      => array( self::class, 'link_issues' ),
			'duplicates' => array( self::class, 'duplicate_issues' ),
			'sitemaps'   => array( self::class, 'sitemap_issues' ),
		);
	}

	/**
	 * Site-wide settings that stop a site ranking.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function site_issues(): array {
		$out = array();
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$staging = class_exists( '\ZinnDigital\ZinnSEO\Robots\Staging' ) && \ZinnDigital\ZinnSEO\Robots\Staging::is_staging();
			if ( ! $staging ) {
				$out[] = array(
					'id'       => 'site-noindex',
					'area'     => 'site',
					'priority' => 100,
					'title'    => __( 'Search engines are asked not to index this site', 'zinn-seo' ),
					'detail'   => __( 'Settings → Reading has "Discourage search engines" ticked, so no page can appear in search results. Right for a site still being built; wrong for a live one.', 'zinn-seo' ),
					'minutes'  => 1,
					'fix'      => array(
						'kind'  => 'action',
						'id'    => 'allow-indexing',
						'label' => __( 'Let search engines index the site', 'zinn-seo' ),
					),
				);
			}
		}
		if ( class_exists( '\ZinnDigital\ZinnSEO\Robots\Robots' ) && \ZinnDigital\ZinnSEO\Robots\Robots::physical_file() ) {
			$out[] = array(
				'id'       => 'robots-file',
				'area'     => 'technical',
				'priority' => 60,
				'title'    => __( 'A robots.txt file on the server overrides your robots.txt settings', 'zinn-seo' ),
				'detail'   => __( 'Import it and move it aside on the robots.txt screen, so the rules you set here are the ones search engines read.', 'zinn-seo' ),
				'minutes'  => 2,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'discovery', 'robots' ),
					'label' => __( 'Open robots.txt', 'zinn-seo' ),
				),
			);
		}

		return $out;
	}

	/**
	 * Pages that need work, from the stored analysis.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function content_issues(): array {
		if ( ! Modules::enabled( 'analysis' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Analysis\Analysis' ) ) {
			return array();
		}
		global $wpdb;
		$types  = \ZinnDigital\ZinnSEO\Analysis\Analysis::types();
		$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$out    = array();
		$scope  = self::translation_scope( 'p' );
		$own    = $scope['own'];
		$theirs = $scope['theirs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; one count, admin only.
		$unscored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_zseo_scored' WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND m.post_id IS NULL", $types ) );
		if ( $unscored > 0 ) {
			$out[] = array(
				'id'       => 'content-unscored',
				'area'     => 'content',
				'priority' => 70,
				/* translators: %d: number of pages. */
				'title'    => sprintf( _n( '%d published page has not been analysed', '%d published pages have not been analysed', $unscored, 'zinn-seo' ), $unscored ),
				'detail'   => __( 'Score every page once, in the background, so this inbox can tell you which need work. New and edited pages are scored automatically.', 'zinn-seo' ),
				'count'    => $unscored,
				'minutes'  => 1,
				'fix'      => array(
					'kind'  => 'action',
					'id'    => 'score-all',
					'label' => __( 'Score every page', 'zinn-seo' ),
				),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		$bad = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_zseo_score' WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND CAST(m.meta_value AS SIGNED) < 50{$own}", $types ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		$bad_tr               = '' === $theirs ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SELECT zr.lang AS lang, COUNT(*) AS n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_zseo_score' {$theirs} WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND CAST(m.meta_value AS SIGNED) < 50 GROUP BY zr.lang", $types ), ARRAY_A );
		list( $bad, $bad_tr ) = self::originals_first( $bad, $bad_tr );
		if ( $bad > 0 ) {
			$out[] = array(
				'id'       => 'content-bad',
				'area'     => 'content',
				'priority' => 65,
				/* translators: %d: number of pages. */
				'title'    => sprintf( _n( '%d published page needs SEO work', '%d published pages need SEO work', $bad, 'zinn-seo' ), $bad ),
				'detail'   => __( 'Their SEO score is under 50. The Content screen lists them; each one\'s editor shows exactly what to change.', 'zinn-seo' ),
				'count'    => $bad,
				'minutes'  => $bad * 5,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'content', 'scores' ) . '?rating=bad',
					'label' => __( 'See the pages', 'zinn-seo' ),
				),
			) + self::with_translations( $bad_tr );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		$nofocus = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_zseo_focus' WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND m.post_id IS NULL{$own}", $types ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		$nofocus_tr                   = '' === $theirs ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SELECT zr.lang AS lang, COUNT(*) AS n FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_zseo_focus' {$theirs} WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND m.post_id IS NULL GROUP BY zr.lang", $types ), ARRAY_A );
		list( $nofocus, $nofocus_tr ) = self::originals_first( $nofocus, $nofocus_tr );
		if ( $nofocus > 0 ) {
			$out[] = array(
				'id'       => 'content-nofocus',
				'area'     => 'content',
				'priority' => 40,
				/* translators: %d: number of pages. */
				'title'    => sprintf( _n( '%d published page has no focus phrase', '%d published pages have no focus phrase', $nofocus, 'zinn-seo' ), $nofocus ),
				'detail'   => __( 'Without one, the analysis cannot tell whether the page targets what people search. Add the phrase each page should be found for.', 'zinn-seo' ),
				'count'    => $nofocus,
				'minutes'  => $nofocus,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'content', 'scores' ) . '?rating=nofocus',
					'label' => __( 'See the pages', 'zinn-seo' ),
				),
			) + self::with_translations( $nofocus_tr );
		}
		$checks = array(
			'alt_missing'    => array( 60, __( 'Images without alt text', 'zinn-seo' ), __( 'Screen readers and image search need a description of each image.', 'zinn-seo' ), 3 ),
			'desc_length'    => array( 55, __( 'Pages without a meta description', 'zinn-seo' ), __( 'Search engines then pick any text from the page for the result; a description written to be clicked does better.', 'zinn-seo' ), 3 ),
			'heading_order'  => array( 45, __( 'Pages whose headings skip a level', 'zinn-seo' ), __( 'Headings are the outline screen readers and search engines read; use the next level down.', 'zinn-seo' ), 2 ),
			'internal_links' => array( 50, __( 'Pages with no internal link', 'zinn-seo' ), __( 'Linking to related pages of your site helps readers and spreads ranking strength; the editor suggests links.', 'zinn-seo' ), 3 ),
			'empty_links'    => array( 45, __( 'Links with no readable text', 'zinn-seo' ), __( 'An icon or an image without alt text inside a link gives it no name.', 'zinn-seo' ), 2 ),
			'phrase_used'    => array( 58, __( 'Pages competing for the same focus phrase', 'zinn-seo' ), __( 'Two pages chasing one search split the result; give each its own phrase or merge them.', 'zinn-seo' ), 10 ),
		);
		foreach ( $checks as $check => $def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed count per check, admin only.
			$like = '%,' . $wpdb->esc_like( $check ) . ',%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $own is built by translation_scope() from a checked table name and an escaped language code.
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_zseo_issues' AND p.post_status = 'publish' AND p.post_type IN ({$in}) AND CONCAT(',', m.meta_value, ',') LIKE %s{$own}", array_merge( $types, array( $like ) ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above; one grouped count per check, admin only.
			$tr             = '' === $theirs ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SELECT zr.lang AS lang, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id {$theirs} WHERE m.meta_key = '_zseo_issues' AND p.post_status = 'publish' AND p.post_type IN ({$in}) AND CONCAT(',', m.meta_value, ',') LIKE %s GROUP BY zr.lang", array_merge( $types, array( $like ) ) ), ARRAY_A );
			list( $n, $tr ) = self::originals_first( $n, $tr );
			if ( $n <= 0 ) {
				continue;
			}
			$out[] = array(
				'id'       => 'check-' . $check,
				'area'     => 'content',
				'priority' => $def[0],
				/* translators: 1: a problem, 2: number of pages. */
				'title'    => sprintf( __( '%1$s (%2$d)', 'zinn-seo' ), $def[1], $n ),
				'detail'   => $def[2],
				'count'    => $n,
				'minutes'  => $n * $def[3],
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'content', 'scores' ) . '?issue=' . rawurlencode( $check ),
					'label' => __( 'See the pages', 'zinn-seo' ),
				),
			) + self::with_translations( $tr );
		}

		return $out;
	}

	/**
	 * How to tell a site's own pages from their translations, in SQL, on a site translated with
	 * Tranzly (both parts '' on any other site).
	 *
	 * ⛔ WHY. With Tranzly a translation is a page of its own. Counted like any page, a site of 30
	 * pages in 58 languages read "1,735 pages need SEO work, about 390 hours" (zinnseo demo,
	 * 2026-10-11): fifty-eight copies of the same thirty jobs. The work is done ONCE, on the
	 * original; its translations follow when it is translated again. So every count is of the
	 * originals, and the translations are told apart, per language.
	 *
	 * @param string $alias The posts table's alias in the query.
	 * @return array{own: string, theirs: string} `own`: an `AND …` that keeps pages in the main
	 *         language (a page Tranzly holds no language for is one); `theirs`: a `JOIN …` (alias
	 *         `zr`, with `zr.lang`) that keeps pages in another language.
	 */
	public static function translation_scope( string $alias = 'p' ): array {
		global $wpdb;
		$builder = '\ZinnDigital\ZinnSEO\Sitemaps\Builder';
		$none    = array(
			'own'    => '',
			'theirs' => '',
		);
		if ( 1 !== preg_match( '/^[a-z]{1,8}$/', $alias ) || ! class_exists( $builder ) || ! $builder::languages_on() ) {
			return $none;
		}
		$table   = (string) $builder::relations_table();
		$default = (string) $builder::default_language();
		if ( '' === $table || '' === $default ) {
			return $none;
		}

		return array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is checked against /^[A-Za-z0-9_]+$/ by Builder::relations_table(), the alias above.
			'own'    => $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$table} zo WHERE zo.object_type = 'post' AND zo.object_id = {$alias}.ID AND zo.lang <> %s )", $default ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
			'theirs' => $wpdb->prepare( " JOIN {$table} zr ON zr.object_type = 'post' AND zr.object_id = {$alias}.ID AND zr.lang <> %s", $default ),
		);
	}

	/**
	 * An issue's count and its translations' rows. Normally the originals are the count and the
	 * translations a note beside it. When NO original has the issue and translations do, the
	 * translations are the pages to work on: they become the count, with no note. Pure.
	 *
	 * @param int               $own  Originals with the issue.
	 * @param array<int, mixed> $rows Translations with it: rows of `lang` and `n`.
	 * @return array{0: int, 1: array<int, mixed>}
	 */
	public static function originals_first( int $own, array $rows ): array {
		if ( $own > 0 || array() === $rows ) {
			return array( $own, $rows );
		}
		$extra = self::with_translations( $rows );

		return array( (int) ( $extra['translations']['count'] ?? 0 ), array() );
	}

	/**
	 * What an issue says about the translations that share it: how many, in how many languages,
	 * and each language's count — beside the count of originals, never added to it. Pure.
	 *
	 * @param array<int, mixed> $rows Rows of `lang` and `n`.
	 * @return array<string, mixed> '' rows: nothing to add.
	 */
	public static function with_translations( array $rows ): array {
		$by = array();
		foreach ( $rows as $row ) {
			$lang = is_array( $row ) ? (string) ( $row['lang'] ?? '' ) : '';
			$n    = is_array( $row ) ? (int) ( $row['n'] ?? 0 ) : 0;
			if ( '' !== $lang && $n > 0 ) {
				$by[ $lang ] = $n;
			}
		}
		if ( array() === $by ) {
			return array();
		}
		ksort( $by );
		$total = array_sum( $by );

		return array(
			'translations' => array(
				'count'       => $total,
				'languages'   => count( $by ),
				'by_language' => $by,
				'note'        => sprintf(
					/* translators: 1: number of translated pages, 2: number of languages. */
					_n( 'The same goes for %1$d translation of these pages (%2$s): fix the original, translate it again, and the translation follows.', 'The same goes for %1$d translations of these pages (%2$s): fix the originals, translate them again, and the translations follow.', $total, 'zinn-seo' ),
					$total,
					/* translators: %d: number of languages. */
					sprintf( _n( '%d language', '%d languages', count( $by ), 'zinn-seo' ), count( $by ) )
				),
			),
		);
	}

	/**
	 * Links: the index, orphans, cornerstone pages too few pages link to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function link_issues(): array {
		if ( ! Modules::enabled( 'links' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Links\Index' ) ) {
			return array();
		}
		$index = '\ZinnDigital\ZinnSEO\Links\Index';
		if ( ! $index::built() ) {
			$job = Jobs::get( $index::JOB );
			if ( 'pending' === $job['status'] ) {
				return array();
			}
			return array(
				array(
					'id'       => 'links-index',
					'area'     => 'links',
					'priority' => 62,
					'title'    => __( 'Build the internal-link index', 'zinn-seo' ),
					'detail'   => __( 'It finds pages nothing links to and counts the links to each page. It runs in the background, once; after that every save keeps it current.', 'zinn-seo' ),
					'minutes'  => 1,
					'fix'      => array(
						'kind'  => 'action',
						'id'    => 'build-links',
						'label' => __( 'Build the index', 'zinn-seo' ),
					),
				),
			);
		}
		$out     = array();
		$orphans = $index::report_count( 'orphan' );
		if ( $orphans > 0 ) {
			$out[] = array(
				'id'       => 'links-orphans',
				'area'     => 'links',
				'priority' => 57,
				/* translators: %d: number of pages. */
				'title'    => sprintf( _n( '%d page has no internal link pointing to it', '%d pages have no internal link pointing to them', $orphans, 'zinn-seo' ), $orphans ),
				'detail'   => __( 'Search engines find and value pages through links. Link to each from a related page.', 'zinn-seo' ),
				'count'    => $orphans,
				'minutes'  => $orphans * 3,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'links', 'orphans' ),
					'label' => __( 'See the pages', 'zinn-seo' ),
				),
				'more'     => self::whole_site_links(),
			);
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed meta lookup, admin only.
		$corner = array_map( 'intval', (array) $wpdb->get_col( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_zseo_cornerstone' AND m.meta_value = '1' AND p.post_status = 'publish'" ) );
		if ( array() !== $corner ) {
			$weak = array();
			foreach ( $index::counts( $corner ) as $id => $c ) {
				if ( $c['in'] < 2 ) {
					$weak[] = array(
						'id'    => (int) $id,
						'title' => get_the_title( (int) $id ),
						'edit'  => (string) get_edit_post_link( (int) $id, 'raw' ),
					);
				}
			}
			if ( array() !== $weak ) {
				$out[] = array(
					'id'       => 'links-cornerstone',
					'area'     => 'links',
					'priority' => 64,
					/* translators: %d: number of pages. */
					'title'    => sprintf( _n( '%d cornerstone page has almost no links pointing to it', '%d cornerstone pages have almost no links pointing to them', count( $weak ), 'zinn-seo' ), count( $weak ) ),
					'detail'   => __( 'Your most important pages should be the most linked. Open each and use "Pages that could link here".', 'zinn-seo' ),
					'count'    => count( $weak ),
					'minutes'  => count( $weak ) * 10,
					'items'    => array_slice( $weak, 0, 20 ),
					'fix'      => array(
						'kind'  => 'link',
						'url'   => self::screen( 'links', 'overview' ),
						'label' => __( 'Open Links', 'zinn-seo' ),
					),
					'more'     => self::whole_site_links(),
				);
			}
		}

		return $out;
	}

	/**
	 * Duplicates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function duplicate_issues(): array {
		if ( ! Modules::enabled( 'duplicates' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Duplicates\Duplicates' ) ) {
			return array();
		}
		$dup = '\ZinnDigital\ZinnSEO\Duplicates\Duplicates';
		if ( array() === (array) get_option( $dup::BUILT, array() ) ) {
			if ( 'pending' === Jobs::get( $dup::JOB )['status'] ) {
				return array();
			}
			return array(
				array(
					'id'       => 'duplicates-scan',
					'area'     => 'content',
					'priority' => 48,
					'title'    => __( 'Check the whole site for duplicate titles and descriptions', 'zinn-seo' ),
					'detail'   => __( 'A quick background check of every published page (no page is rendered).', 'zinn-seo' ),
					'minutes'  => 1,
					'fix'      => array(
						'kind'  => 'action',
						'id'    => 'scan-duplicates',
						'label' => __( 'Check now', 'zinn-seo' ),
					),
				),
			);
		}
		$out    = array();
		$labels = array(
			'title' => array( 58, __( 'Groups of pages with the same SEO title', 'zinn-seo' ) ),
			'desc'  => array( 52, __( 'Groups of pages with the same meta description', 'zinn-seo' ) ),
			'h1'    => array( 46, __( 'Groups of pages with the same main heading', 'zinn-seo' ) ),
			'focus' => array( 56, __( 'Groups of pages with the same focus phrase', 'zinn-seo' ) ),
		);
		foreach ( $dup::counts() as $kind => $n ) {
			if ( $n <= 0 ) {
				continue;
			}
			$out[] = array(
				'id'       => 'duplicates-' . $kind,
				'area'     => 'content',
				'priority' => $labels[ $kind ][0],
				/* translators: 1: what is duplicated, 2: number of groups. */
				'title'    => sprintf( __( '%1$s: %2$d', 'zinn-seo' ), $labels[ $kind ][1], $n ),
				'detail'   => __( 'Search engines cannot tell such pages apart and may show only one. Make each title, description and heading say what is different about its page.', 'zinn-seo' ),
				'count'    => $n,
				'minutes'  => $n * 4,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'content', 'duplicates' ) . '?kind=' . $kind,
					'label' => __( 'See them', 'zinn-seo' ),
				),
			);
		}

		return $out;
	}

	/**
	 * Sitemap problems the sitemaps module recorded.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sitemap_issues(): array {
		if ( ! Modules::enabled( 'sitemaps' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Sitemaps\Store' ) ) {
			return array();
		}
		$m      = \ZinnDigital\ZinnSEO\Sitemaps\Store::manifest();
		$errors = (array) ( $m['errors'] ?? array() );
		$job    = class_exists( '\ZinnDigital\ZinnSEO\Sitemaps\Builder' ) ? Jobs::get( \ZinnDigital\ZinnSEO\Sitemaps\Builder::JOB ) : array( 'status' => '' );
		if ( array() === $errors && 'failed' !== $job['status'] ) {
			return array();
		}

		return array(
			array(
				'id'       => 'sitemaps-errors',
				'area'     => 'technical',
				'priority' => 75,
				'title'    => __( 'The XML sitemap needs attention', 'zinn-seo' ),
				'detail'   => 'failed' === $job['status'] ? __( 'The last sitemap build stopped with an error.', 'zinn-seo' ) : __( 'The sitemap health check found a problem. Open Sitemaps to see what and where.', 'zinn-seo' ),
				'minutes'  => 5,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => self::screen( 'discovery', 'sitemaps' ),
					'label' => __( 'Open Sitemaps', 'zinn-seo' ),
				),
			),
		);
	}
}
