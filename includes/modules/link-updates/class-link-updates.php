<?php
/**
 * Internal link updates (zs-730): when an address changes, every internal link to it is rewritten
 * to the new address, so visitors and crawlers never take the redirect hop.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\LinkUpdates;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The redirect from the old address keeps outside links working; links INSIDE the site should
 * point at the new address directly (a redirect hop costs crawl budget and a little link value).
 *
 * ⭐ Resumable and uncapped (§2.10): each change is queued and a background job walks every post
 * whose content holds the old address (a keyset scan), at any site size. Every rewritten post is saved through WordPress, so each
 * change is an ordinary revision a person can compare and restore. Page builders' and blocks'
 * stored addresses (`"url":"…"` in a block's settings) and menu links are updated too.
 *
 * A child page's address starts with its parent's, so a moved parent rewrites its children's
 * links in the same pass (prefix match, on whole path segments only).
 */
final class Link_Updates {

	/** Background job id. */
	public const JOB = 'link-updates';

	/** Queue of changes waiting (non-autoloaded). */
	public const QUEUE = 'zseo_link_updates_queue';

	/** Log of finished runs (non-autoloaded), newest first. */
	public const LOG = 'zseo_link_updates_log';

	/** Runs kept in the log. */
	private const LOG_SIZE = 50;

	/** An address in the HTML: href="…" or href='…'. */
	private const HREF = '#(\shref\s*=\s*)(["\'])([^"\']*)\2#i';

	/** An address kept in a block's settings: "url":"…" (plain or with escaped slashes). */
	private const BLOCK_URL = '#("(?:url|href|link)"\s*:\s*")([^"]*)(")#';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Updating internal links to changed addresses', 'zinn-seo' ), array( self::class, 'step' ) );
		if ( (bool) Settings::get( 'link_updater_auto', true ) ) {
			add_action( 'post_updated', array( self::class, 'post_updated' ), 25, 3 );
		}
	}

	/**
	 * A published post's address changed: queue the rewrite.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $after   After.
	 * @param \WP_Post $before  Before.
	 * @return void
	 */
	public static function post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $after ) || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}
		$old = (string) get_permalink( $before );
		$new = (string) get_permalink( $after );
		if ( '' === $old || '' === $new || untrailingslashit( $old ) === untrailingslashit( $new ) ) {
			return;
		}
		self::queue( $old, $new, (int) $post_id, 'post' );
	}

	/**
	 * Queue one address change and make sure the job runs.
	 *
	 * @param string $old    Old address.
	 * @param string $to    New address.
	 * @param int    $target The post now at the new address (0: unknown).
	 * @param string $why    post or manual.
	 * @return true|\WP_Error
	 */
	public static function queue( string $old, string $to, int $target, string $why ) {
		$queue   = self::queued();
		$queue[] = array(
			'old'    => esc_url_raw( $old ),
			'new'    => esc_url_raw( $to ),
			'target' => $target,
			'why'    => $why,
			'at'     => time(),
		);
		update_option( self::QUEUE, $queue, false );
		$job = Jobs::get( self::JOB );
		if ( 'pending' === ( $job['status'] ?? '' ) ) {
			return true;
		}

		return Jobs::start( self::JOB, array() );
	}

	/**
	 * The waiting changes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function queued(): array {
		$q = get_option( self::QUEUE, array() );

		return is_array( $q ) ? array_values( $q ) : array();
	}

	/**
	 * The finished runs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function log(): array {
		$l = get_option( self::LOG, array() );

		return is_array( $l ) ? $l : array();
	}

	/**
	 * The forms a link to an address can take in content: the full address, the address without
	 * its scheme (`//host/path`) and the path alone (`/path/`).
	 *
	 * @param string $url  Address.
	 * @param string $home The home address.
	 * @return array<int, string>
	 */
	public static function forms( string $url, string $home ): array {
		$u = wp_parse_url( $url );
		$h = wp_parse_url( $home );
		if ( ! is_array( $u ) || empty( $u['host'] ) ) {
			return array( $url );
		}
		$path  = (string) ( $u['path'] ?? '/' );
		$host  = (string) $u['host'] . ( isset( $u['port'] ) ? ':' . $u['port'] : '' );
		$forms = array(
			'https://' . $host . $path,
			'http://' . $host . $path,
			'//' . $host . $path,
		);
		if ( is_array( $h ) && strtolower( (string) ( $h['host'] ?? '' ) ) === strtolower( (string) $u['host'] ) ) {
			$forms[] = $path;
		}

		return $forms;
	}

	/**
	 * Rewrite every link to `$old` (or below it) in a piece of content.
	 *
	 * @param string $html Content.
	 * @param string $old  Old address.
	 * @param string $to  New address.
	 * @param string $home Home address.
	 * @param int    $count Out: links rewritten.
	 * @param bool   $exact Only the address itself, never the addresses below it (a single link
	 *                      fixed, zs-778 — not a moved section).
	 * @return string
	 */
	public static function rewrite( string $html, string $old, string $to, string $home, int &$count = 0, bool $exact = false ): string {
		$count   = 0;
		$olds    = self::forms( $old, $home );
		$news    = self::forms( $to, $home );
		$replace = static function ( string $value ) use ( $olds, $news, &$count, $exact ): string {
			foreach ( $olds as $i => $prefix ) {
				$bare = untrailingslashit( $prefix );
				if ( '' === $bare || '/' === $prefix ) {
					continue;
				}
				// The address itself, or something below it on a path boundary.
				$slash = trailingslashit( $bare );
				$below = ! $exact && 0 === strpos( $value, $slash ) && $slash !== $value;
				if ( in_array( $value, array( $prefix, $bare, $slash ), true ) || $below || 1 === preg_match( '#^' . preg_quote( $bare, '#' ) . '/?[?\#]#', $value ) ) {
					$rest = substr( $value, strlen( $bare ) );
					$to   = untrailingslashit( $news[ min( $i, count( $news ) - 1 ) ] );
					if ( '' === $rest && '/' === substr( $prefix, -1 ) ) {
						$rest = '/';
					}
					++$count;
					return $to . ( '' === $rest && '/' === substr( $news[ min( $i, count( $news ) - 1 ) ], -1 ) ? '/' : $rest );
				}
			}
			return $value;
		};
		// href="…" and href='…' in the HTML.
		$html = (string) preg_replace_callback(
			self::HREF,
			static fn( array $m ): string => $m[1] . $m[2] . $replace( $m[3] ) . $m[2],
			$html
		);
		// Addresses kept in a block's settings: "url":"…" (plain or with escaped slashes).
		$html = (string) preg_replace_callback(
			self::BLOCK_URL,
			static function ( array $m ) use ( $replace ): string {
				$escaped = false !== strpos( $m[2], '\/' );
				$value   = $escaped ? str_replace( '\/', '/', $m[2] ) : $m[2];
				$out     = $replace( $value );
				return $m[1] . ( $escaped ? str_replace( '/', '\/', $out ) : $out ) . $m[3];
			},
			$html
		);

		return $html;
	}

	/**
	 * Every address `rewrite()` would see in a piece of content — the same two patterns, read
	 * instead of replaced — so a sweep that FINDS links and the rewrite that CHANGES them never
	 * disagree about what a link is (zs-778 builds on this: one link-rewriting path).
	 *
	 * @param string $html Content.
	 * @return array<int, string> Addresses as written (block settings' escaped slashes undone), once each.
	 */
	public static function addresses( string $html ): array {
		$out = array();
		if ( preg_match_all( self::HREF, $html, $m ) ) {
			foreach ( $m[3] as $v ) {
				$out[ (string) $v ] = true;
			}
		}
		if ( preg_match_all( self::BLOCK_URL, $html, $m ) ) {
			foreach ( $m[2] as $v ) {
				$out[ str_replace( '\/', '/', (string) $v ) ] = true;
			}
		}
		unset( $out[''] );

		return array_keys( $out );
	}

	/**
	 * Post types whose content can hold internal links.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$types = array_diff( $types, array( 'attachment' ) );
		foreach ( array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ) as $extra ) {
			if ( post_type_exists( $extra ) ) {
				$types[] = $extra;
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Posts after a cursor whose content holds the old address's path (keyset, so a run resumes
	 * where it stopped and reaches every post at any site size). A content search rather than the
	 * link index: it also finds addresses kept in block settings, patterns and navigation menus,
	 * which the index does not hold.
	 *
	 * @param array<string, mixed> $change The change.
	 * @param int                  $after  Cursor.
	 * @return array<int, int>
	 */
	public static function candidates( array $change, int $after ): array {
		global $wpdb;
		$path  = (string) wp_parse_url( (string) $change['old'], PHP_URL_PATH );
		$path  = '' === trim( $path, '/' ) ? (string) $change['old'] : untrailingslashit( $path );
		$types = self::types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// A page builder keeps its layout in post meta (Bricks and Oxygen leave the content box
		// empty; Elementor's JSON escapes the slashes): those pages are found there too (Q328).
		$keys  = array_keys( \ZinnDigital\ZinnSEO\Content\Writer::keys() );
		$kin   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$plain = '%' . $wpdb->esc_like( $path ) . '%';
		$json  = '%' . $wpdb->esc_like( str_replace( '/', '\\/', $path ) ) . '%';
		$args  = array_merge( array( $after ), $types, array( $plain ), $keys, array( $plain, $json ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.SlowDBQuery.slow_query_meta_key -- keyset over the primary key; $in and $kin are one %s placeholder per post type / layout key, all filled from $args.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID > %d AND p.post_type IN ({$in}) AND p.post_status IN ('publish','draft','pending','future','private') AND ( p.post_content LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ({$kin}) AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s ) ) ) ORDER BY p.ID ASC LIMIT 100", $args ) ) );
	}

	/**
	 * Rewrite one post. Saved through WordPress (a revision), with the content filters that would
	 * strip HTML set aside: this is the post's own content moved, not new input.
	 *
	 * @param int                  $id     Post.
	 * @param array<string, mixed> $change The change.
	 * @return int Links rewritten.
	 */
	public static function update_post( int $id, array $change ): int {
		$w = \ZinnDigital\ZinnSEO\Content\Writer::open( $id );
		if ( null === $w ) {
			return 0;
		}
		// The content box AND a page builder's own layout (Elementor, Bricks, Beaver Builder…):
		// a builder page renders from its layout, so rewriting the content box alone changes nothing
		// a visitor sees (ZH6-9, Q328).
		$count = 0;
		$w->apply(
			static function ( string $html ) use ( $change, &$count ): string {
				$n     = 0;
				$out   = self::rewrite( $html, (string) $change['old'], (string) $change['new'], home_url( '/' ), $n );
				$count = max( $count, $n );
				return $out;
			}
		);
		if ( ! $w->changed() ) {
			return 0;
		}
		$done = $w->save();

		return is_wp_error( $done ) ? 0 : $count;
	}

	/**
	 * Menu links typed as custom addresses.
	 *
	 * @param array<string, mixed> $change The change.
	 * @return int Updated.
	 */
	public static function update_menus( array $change ): int {
		global $wpdb;
		$done = 0;
		foreach ( self::forms( (string) $change['old'], home_url( '/' ) ) as $form ) {
			if ( '/' === $form ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_query_meta_key,WordPress.DB.SlowDBQuery.slow_query_meta_value -- custom menu links, a handful per site.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_url' AND meta_value LIKE %s", $wpdb->esc_like( untrailingslashit( $form ) ) . '%' ) );
			foreach ( (array) $rows as $row ) {
				$n   = 0;
				$new = self::rewrite( ' href="' . (string) $row->meta_value . '"', (string) $change['old'], (string) $change['new'], home_url( '/' ), $n );
				if ( $n > 0 && 1 === preg_match( '/href="([^"]*)"/', $new, $m ) ) {
					update_post_meta( (int) $row->post_id, '_menu_item_url', esc_url_raw( $m[1] ) );
					++$done;
				}
			}
		}

		return $done;
	}

	/**
	 * One step: work through the queue, change by change, page by page.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$done_total = (int) ( $state['done'] ?? 0 );
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$queue = self::queued();
			if ( array() === $queue ) {
				return array(
					'state'    => array( 'done' => $done_total ),
					'done'     => true,
					'progress' => array(
						'done'  => $done_total,
						'total' => $done_total,
					),
				);
			}
			$change = $queue[0];
			$cursor = (int) ( $state['after'] ?? 0 );
			$ids    = self::candidates( $change, $cursor );
			if ( array() === $ids ) {
				// This change is finished: menus, the log, then the next one.
				$menus = self::update_menus( $change );
				$log   = self::log();
				array_unshift(
					$log,
					array(
						'old'   => $change['old'],
						'new'   => $change['new'],
						'why'   => $change['why'],
						'at'    => time(),
						'posts' => (int) ( $state['posts'] ?? 0 ),
						'links' => (int) ( $state['links'] ?? 0 ),
						'menus' => $menus,
					)
				);
				update_option( self::LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
				array_shift( $queue );
				update_option( self::QUEUE, $queue, false );
				$state = array( 'done' => ++$done_total );
				continue;
			}
			foreach ( $ids as $id ) {
				$links = self::update_post( $id, $change );
				if ( $links > 0 ) {
					$state['posts'] = (int) ( $state['posts'] ?? 0 ) + 1;
					$state['links'] = (int) ( $state['links'] ?? 0 ) + $links;
				}
				$state['after'] = $id;
				if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
					break;
				}
			}
		}
		$state['done'] = $done_total;

		return array(
			'state'    => $state,
			'done'     => false,
			'progress' => array(
				'done'  => $done_total,
				'total' => $done_total + count( self::queued() ),
			),
		);
	}
}
