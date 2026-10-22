<?php
/**
 * The internal-link graph: which page links to which (zs-100, zs-102).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

use ZinnDigital\ZinnSEO\Analysis\Analysis;
use ZinnDigital\ZinnSEO\Analysis\Checks;
use ZinnDigital\ZinnSEO\Analysis\Doc;
use ZinnDigital\ZinnSEO\Analysis\Loader;
use ZinnDigital\ZinnSEO\Analysis\Render;
use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The table `{prefix}zseo_links` (architecture §1.5): one row per (source post, target post),
 * flags bit 1 = nofollow, bit 2 = an image link. About 16 bytes a link; dropped and rebuilt at any
 * time. Read from the RENDERED page, so links made by blocks, shortcodes and builders count.
 *
 * - A post's row set is replaced whenever its analysis is stored (after every save), and the
 *   `links-index` job builds the whole graph (keyset by ID, resumes after a kill).
 * - Counts, orphans (published pages nothing links to) and dead ends (pages that link nowhere)
 *   are indexed queries: `KEY target (target_id)` and the primary key on `source_id`.
 * - Nothing here runs on a visitor's page.
 */
final class Index {

	/** Table schema version. */
	public const DB_VERSION = 1;

	/** Option holding the schema version. */
	public const DB_OPTION = 'zseo_links_db';

	/** Option recording when the whole index was last built. */
	public const BUILT = 'zseo_links_built';

	/** Job id. */
	public const JOB = 'links-index';

	/** Flag: the link carries rel=nofollow (or sponsored/ugc). */
	public const NOFOLLOW = 1;

	/** Flag: the link is an image. */
	public const IMAGE = 2;

	/**
	 * Resolved addresses in this request: address => post ID (0 = not a post).
	 *
	 * @var array<string, int>
	 */
	private static array $resolved = array();

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_links';
	}

	/**
	 * Create the table when its version is behind.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
source_id bigint(20) unsigned NOT NULL,
target_id bigint(20) unsigned NOT NULL,
flags tinyint(3) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (source_id,target_id),
KEY target (target_id)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Is the table there (without creating it)?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION;
	}

	/**
	 * Has the whole site been indexed at least once?
	 *
	 * @return bool
	 */
	public static function built(): bool {
		$built = get_option( self::BUILT, array() );

		return self::ready() && is_array( $built ) && ! empty( $built['at'] );
	}

	/**
	 * Hooks (admin, REST, CLI, cron).
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Building the internal-link index', 'zinn-seo' ), array( self::class, 'step' ) );
		add_action( 'zinn_seo_analysis_stored', array( self::class, 'on_stored' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'forget' ) );
		add_action( 'trashed_post', array( self::class, 'forget' ) );
		add_filter( 'zinn_seo_inbound_links', array( self::class, 'inbound_filter' ), 10, 2 );
	}

	/**
	 * A post's analysis was stored (it was saved): refresh its outgoing links.
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $result  Analysis (carries `links`).
	 * @return void
	 */
	public static function on_stored( $post_id, $result ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || ! is_array( $result ) || ! isset( $result['links'] ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			self::forget( (int) $post_id );
			return;
		}
		self::maybe_install();
		self::save( (int) $post_id, (array) $result['links'] );
	}

	/**
	 * Forget a post's outgoing links.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function forget( $post_id ): void {
		/**
		 * Fires when a page's links leave the index (deleted, trashed or no longer published):
		 * the outbound links report (zs-779) forgets them too.
		 *
		 * @param int $post_id Post.
		 */
		do_action( 'zinn_seo_links_forgotten', (int) $post_id );
		if ( ! self::ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
	}

	/**
	 * The post an address points at (0 when none), memoised per request and, where the site
	 * has a persistent object cache, for a day across requests.
	 *
	 * ⛔ WHY NOT `url_to_postid()` FIRST. It walks every rewrite rule with a regular expression.
	 * On zinnhub.com (2026-10-15, 46 languages' rules) it took 110 ms per link: one page with
	 * 87 internal links cost 9.5 s, so building the index slowed the live site. Most links point
	 * at a post by its slug, so the post is looked up by the address's last part and accepted
	 * only when its own permalink IS the address; anything else falls back to WordPress.
	 *
	 * @param string $href Address (absolute or relative).
	 * @return int
	 */
	public static function resolve( string $href ): int {
		$href = (string) preg_replace( '/#.*$/', '', trim( $href ) );
		if ( '' === $href ) {
			return 0;
		}
		if ( isset( self::$resolved[ $href ] ) ) {
			return self::$resolved[ $href ];
		}
		$absolute = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? home_url( $href ) : $href;
		$cached   = wp_cache_get( md5( $absolute ), 'zseo_link_resolve' );
		if ( false !== $cached ) {
			$id = (int) $cached;
		} else {
			$id = self::by_slug( $absolute );
			// A search-results address is never a post; WordPress would run the whole search to
			// find that out (about a second per link on zinnhub.com, which links to many).
			if ( $id <= 0 && ! self::is_search( $absolute, (string) ( $GLOBALS['wp_rewrite']->search_base ?? 'search' ) ) ) {
				$id = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $absolute );
			}
			wp_cache_set( md5( $absolute ), $id, 'zseo_link_resolve', DAY_IN_SECONDS );
		}
		if ( count( self::$resolved ) > 5000 ) {
			self::$resolved = array();
		}
		self::$resolved[ $href ] = $id;

		return $id;
	}

	/**
	 * A post found by the last part of an address, when its permalink is that address (0
	 * otherwise): one indexed query instead of every rewrite rule.
	 *
	 * @param string $url Absolute address.
	 * @return int
	 */
	public static function by_slug( string $url ): int {
		$slug = self::slug_of( $url );
		if ( '' === $slug ) {
			return 0;
		}
		global $wpdb;
		// Only the page types (a menu item shares its page's slug, and its "permalink" can read the same).
		$types = Analysis::types();
		if ( array() === $types ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one indexed lookup (post_name), memoised by the caller; placeholders built above.
		$ids        = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type IN ({$in}) LIMIT 10", array_merge( array( $slug ), $types ) ) ) );
		$candidates = array();
		foreach ( $ids as $id ) {
			$candidates[ $id ] = (string) get_permalink( $id );
		}

		return self::matching( $url, $candidates );
	}

	/**
	 * Is the address a search-results page (`/search/…` or `?s=`)? Pure.
	 *
	 * @param string $url  Address.
	 * @param string $base The site's search base (`search`).
	 * @return bool
	 */
	public static function is_search( string $url, string $base ): bool {
		$path  = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );

		return isset( $args['s'] ) || ( '' !== $base && 1 === preg_match( '#^(/[a-z]{2,3}(-[a-z]{2,4})?)?/' . preg_quote( trim( $base, '/' ), '#' ) . '(/|$)#i', $path ) );
	}

	/**
	 * The slug an address ends with ('' for the home page or an address with a query). Pure.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function slug_of( string $url ): string {
		if ( false !== strpos( $url, '?' ) ) {
			return '';
		}
		$path  = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$parts = '' === $path ? array() : explode( '/', $path );

		return array() === $parts ? '' : strtolower( rawurlencode( rawurldecode( (string) end( $parts ) ) ) );
	}

	/**
	 * The candidate whose permalink is the address (scheme, case of the encoding and a trailing
	 * slash aside); 0 for none. Pure.
	 *
	 * @param string             $url        Address.
	 * @param array<int, string> $candidates Post id => permalink.
	 * @return int
	 */
	public static function matching( string $url, array $candidates ): int {
		$norm = static fn( string $u ): string => strtolower( rawurldecode( untrailingslashit( (string) preg_replace( '#^https?://#i', '', $u ) ) ) );
		$want = $norm( $url );
		foreach ( $candidates as $id => $link ) {
			if ( '' !== $link && $norm( $link ) === $want ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Replace a post's outgoing links.
	 *
	 * @param int                              $post_id Post.
	 * @param array<int, array<string, mixed>> $links   `{href, internal, rel, image}`.
	 * @return int Links stored.
	 */
	public static function save( int $post_id, array $links ): int {
		global $wpdb;
		/**
		 * Fires with every link of a page as the index reads it (internal AND to other sites,
		 * each `{href, text, alt, internal, rel, image}`), after each save and in the whole-site
		 * build. ⭐ ONE scanner: the outbound links report (zs-779) keeps its rows from this,
		 * never from a read of its own.
		 *
		 * @param int                              $post_id Post.
		 * @param array<int, array<string, mixed>> $links   Links.
		 */
		do_action( 'zinn_seo_links_indexed', $post_id, $links );
		$rows = array();
		foreach ( $links as $l ) {
			if ( empty( $l['internal'] ) ) {
				continue;
			}
			$target = self::resolve( (string) ( $l['href'] ?? '' ) );
			if ( $target <= 0 || $target === $post_id ) {
				continue;
			}
			$flags = 0;
			if ( array_intersect( array( 'nofollow', 'sponsored', 'ugc' ), (array) ( $l['rel'] ?? array() ) ) ) {
				$flags |= self::NOFOLLOW;
			}
			if ( ! empty( $l['image'] ) ) {
				$flags |= self::IMAGE;
			}
			// The target counts as followed if ANY link to it is followed; as an image link if any is.
			$prev            = $rows[ $target ] ?? null;
			$rows[ $target ] = null === $prev ? $flags : ( ( $prev & $flags & self::NOFOLLOW ) | ( ( $prev | $flags ) & self::IMAGE ) );
		}
		$table = self::table();
		$wpdb->delete( $table, array( 'source_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		if ( array() === $rows ) {
			return 0;
		}
		$values = array();
		$args   = array();
		foreach ( $rows as $target => $flags ) {
			$values[] = '(%d,%d,%d)';
			array_push( $args, $post_id, (int) $target, (int) $flags );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; $values are placeholder groups.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (source_id, target_id, flags) VALUES " . implode( ',', $values ), $args ) );

		return count( $rows );
	}

	/**
	 * One step of the whole-site build: published posts by ID, rendered and indexed.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		global $wpdb;
		Loader::classes();
		self::maybe_install();
		$after = (int) ( $state['after'] ?? 0 );
		$done  = (int) ( $state['done'] ?? 0 );
		$types = Analysis::types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total = $state['total'] ?? null;
		if ( null === $total ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; once per build.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status = 'publish'", $types ) );
		}
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$finished = false;
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset by ID.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$in}) AND post_status = 'publish' ORDER BY ID ASC LIMIT 50", array_merge( array( $after ), $types ) ) );
			if ( ! is_array( $ids ) || array() === $ids ) {
				$finished = true;
				break;
			}
			_prime_post_caches( array_map( 'intval', $ids ), false, true );
			foreach ( $ids as $id ) {
				if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
					break 2;
				}
				$post = get_post( (int) $id );
				if ( $post instanceof \WP_Post ) {
					Render::sweeping( true ); // Every page once: no cached copy each (T1, 1M posts).
					$html = Render::html( $post );
					Render::sweeping( false );
					if ( is_string( $html ) ) {
						self::save( (int) $id, Analysis::links_of( Doc::parse( $html ), $host ) );
					}
				}
				$after = (int) $id;
				++$done;
			}
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime(); // Without the guard a persistent cache lacking it logs a notice every page.
			} else {
				foreach ( $ids as $flushed ) {
					wp_cache_delete( (int) $flushed, 'posts' );
					wp_cache_delete( (int) $flushed, 'post_meta' );
				}
			}
		}
		if ( $finished ) {
			update_option(
				self::BUILT,
				array(
					'at'    => time(),
					'posts' => $done,
				),
				false
			);
			/**
			 * Fires when a whole-site build of the link index has read every page.
			 *
			 * @param int $done Pages read.
			 */
			do_action( 'zinn_seo_links_index_built', $done );
		}

		return array(
			'state'    => array(
				'after' => $after,
				'done'  => $done,
				'total' => $total,
			),
			'done'     => $finished,
			'progress' => array(
				'done'  => $done,
				'total' => (int) $total,
			),
		);
	}

	/**
	 * Answer `zinn_seo_inbound_links`.
	 *
	 * @param int|null $count   Count so far.
	 * @param int      $post_id Post.
	 * @return int|null
	 */
	public static function inbound_filter( $count, $post_id ) {
		if ( null !== $count || ! self::built() ) {
			return $count;
		}

		return self::counts( array( (int) $post_id ) )[ (int) $post_id ]['in'] ?? 0;
	}

	/**
	 * Inbound and outbound internal links of some posts, in two grouped queries.
	 *
	 * @param array<int, int> $ids Posts.
	 * @return array<int, array{in: int, out: int}>
	 */
	public static function counts( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'in'  => 0,
				'out' => 0,
			);
		}
		if ( array() === $ids || ! self::ready() ) {
			return $out;
		}
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; placeholders built above.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT target_id AS id, COUNT(*) AS n FROM {$table} WHERE target_id IN ({$in}) GROUP BY target_id", $ids ), ARRAY_A ) as $row ) {
			$out[ (int) $row['id'] ]['in'] = (int) $row['n'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; placeholders built above.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT source_id AS id, COUNT(*) AS n FROM {$table} WHERE source_id IN ({$in}) GROUP BY source_id", $ids ), ARRAY_A ) as $row ) {
			$out[ (int) $row['id'] ]['out'] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Pages that link to a post.
	 *
	 * @param int $post_id Post.
	 * @param int $limit   How many.
	 * @return array<int, int>
	 */
	public static function sources( int $post_id, int $limit = 100 ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT source_id FROM {$table} WHERE target_id = %d ORDER BY source_id DESC LIMIT %d", $post_id, $limit ) ) );
	}

	/**
	 * Pages a post links to.
	 *
	 * @param int $post_id Post.
	 * @return array<int, int>
	 */
	public static function targets( int $post_id ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT target_id FROM {$table} WHERE source_id = %d", $post_id ) ) );
	}

	/**
	 * Orphans (nothing links to them) or dead ends (they link nowhere), newest first, keyset.
	 *
	 * @param string $kind   orphan|deadend.
	 * @param int    $before Keyset cursor (0: start).
	 * @param int    $per    Page size.
	 * @param string $type   One post type, or '' for all analysed types.
	 * @return array{items: array<int, int>, next: int}
	 */
	public static function report( string $kind, int $before, int $per, string $type = '' ): array {
		global $wpdb;
		list( $where, $args ) = self::report_where( $kind, $type );
		$sql                  = "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where}";
		if ( $before > 0 ) {
			$sql   .= ' AND p.ID < %d';
			$args[] = $before;
		}
		$sql   .= ' ORDER BY p.ID DESC LIMIT %d';
		$args[] = $per + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; every part is a placeholder.
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
		$more = count( $ids ) > $per;
		$ids  = array_slice( $ids, 0, $per );

		return array(
			'items' => $ids,
			'next'  => $more && array() !== $ids ? (int) end( $ids ) : 0,
		);
	}

	/**
	 * How many orphans or dead ends there are (exact; indexed NOT EXISTS per published page).
	 *
	 * @param string $kind orphan|deadend.
	 * @return int
	 */
	public static function report_count( string $kind ): int {
		global $wpdb;
		list( $where, $args ) = self::report_where( $kind, '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; every part is a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}", $args ) );
	}

	/**
	 * The WHERE of the orphan / dead-end report: published, indexable, not the home or blog page.
	 *
	 * @param string $kind orphan|deadend.
	 * @param string $type One post type or ''.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function report_where( string $kind, string $type ): array {
		global $wpdb;
		$types  = Analysis::types();
		$types  = '' !== $type && in_array( $type, $types, true ) ? array( $type ) : $types;
		$table  = self::table();
		$col    = 'deadend' === $kind ? 'source_id' : 'target_id';
		$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$skip   = array_values( array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) ) );
		$args   = $types;
		$where  = "p.post_type IN ({$in}) AND p.post_status = 'publish' AND NOT EXISTS (SELECT 1 FROM {$table} l WHERE l.{$col} = p.ID)";
		$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id = p.ID AND r.meta_key = '_zseo_robots' AND r.meta_value LIKE '%%\"noindex\"%%')";
		if ( array() !== $skip ) {
			$where .= ' AND p.ID NOT IN (' . implode( ',', array_fill( 0, count( $skip ), '%d' ) ) . ')';
			$args   = array_merge( $args, $skip );
		}

		return array( $where, $args );
	}

	/**
	 * Totals for the Links screen and the Fix-it inbox.
	 *
	 * @return array<string, int|bool>
	 */
	public static function totals(): array {
		if ( ! self::built() ) {
			return array( 'built' => false );
		}
		global $wpdb;
		$table = self::table();
		$built = get_option( self::BUILT, array() );

		return array(
			'built'    => true,
			'at'       => (int) ( $built['at'] ?? 0 ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table.
			'links'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'orphans'  => self::report_count( 'orphan' ),
			'deadends' => self::report_count( 'deadend' ),
		);
	}
}
