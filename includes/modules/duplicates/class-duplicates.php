<?php
/**
 * Duplicate titles, descriptions and H1s, and two pages chasing one phrase (zs-431, zs-009).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Duplicates;

use ZinnDigital\ZinnSEO\Analysis\Analysis;
use ZinnDigital\ZinnSEO\Analysis\Text;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each published page carries a short hash of what search engines see — its SEO title
 * (`_zseo_h_title`), meta description (`_zseo_h_desc`) and main heading (`_zseo_h_h1`) — and the
 * analysis already stores its focus phrases (`_zseo_fk`). A duplicate is a hash two pages share:
 * one grouped query per kind, read a page of groups at a time (keyset on the hash), so a site of
 * a million pages lists its duplicates without loading them.
 *
 * The hashes are written when a page is saved and by the `duplicates` job (keyset by ID, no
 * rendering — titles and descriptions come from the templates — so it runs at about a thousand
 * pages a second, and resumes after a kill). The Search Console half of cannibalisation (two
 * pages ranking for one query) arrives with the Search Console connection (stage P11).
 */
final class Duplicates {

	/** Job id. */
	public const JOB = 'duplicates';

	/** Meta key per kind. */
	public const KEYS = array(
		'title' => '_zseo_h_title',
		'desc'  => '_zseo_h_desc',
		'h1'    => '_zseo_h_h1',
		'focus' => '_zseo_fk',
	);

	/** Option recording the last full run. */
	public const BUILT = 'zseo_duplicates_built';

	/**
	 * Hooks (admin, REST, CLI, cron).
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Finding duplicate titles, descriptions and headings', 'zinn-seo' ), array( self::class, 'step' ) );
		add_action( 'save_post', array( self::class, 'on_save' ), 40, 2 );
	}

	/**
	 * The short hash of a text as search engines compare it ('' for empty text).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function hash( string $text ): string {
		$n = Text::norm( $text );

		return '' === $n ? '' : substr( md5( $n ), 0, 16 );
	}

	/**
	 * Write a post's hashes.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function store( \WP_Post $post ): void {
		$id = (int) $post->ID;
		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, Analysis::types(), true ) ) {
			foreach ( array( 'title', 'desc', 'h1' ) as $kind ) {
				delete_post_meta( $id, self::KEYS[ $kind ] );
			}
			return;
		}
		$c      = Context::for_post( $post );
		$values = array(
			'title' => self::hash( Resolver::title( $c ) ),
			'desc'  => self::hash( Resolver::description( $c ) ),
			'h1'    => self::hash( (string) $post->post_title ),
		);
		foreach ( $values as $kind => $hash ) {
			'' === $hash ? delete_post_meta( $id, self::KEYS[ $kind ] ) : update_post_meta( $id, self::KEYS[ $kind ], $hash );
		}
	}

	/**
	 * A post was saved.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && ! wp_is_post_revision( (int) $post_id ) && ! wp_is_post_autosave( (int) $post_id ) ) {
			self::store( $post );
		}
	}

	/**
	 * One step of the job: every published page by ID.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		global $wpdb;
		$after    = (int) ( $state['after'] ?? 0 );
		$done     = (int) ( $state['done'] ?? 0 );
		$types    = Analysis::types();
		$in       = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total    = $state['total'] ?? null;
		$finished = false;
		if ( null === $total ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; once per run.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status = 'publish'", $types ) );
		}
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset by ID.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$in}) AND post_status = 'publish' ORDER BY ID ASC LIMIT 200", array_merge( array( $after ), $types ) ) );
			if ( ! is_array( $ids ) || array() === $ids ) {
				$finished = true;
				break;
			}
			$ids = array_map( 'intval', $ids );
			_prime_post_caches( $ids, true, true );
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post ) {
					self::store( $post );
				}
				$after = $id;
				++$done;
			}
			Resolver::flush();
			wp_cache_flush_runtime();
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
	 * Groups of pages sharing one value, largest first within a page of hashes (keyset on hash).
	 *
	 * @param string $kind  title|desc|h1|focus.
	 * @param string $after Keyset cursor (the last hash of the previous page, '' to start).
	 * @param int    $per   Groups per page.
	 * @return array{groups: array<int, array<string, mixed>>, next: string}
	 */
	public static function groups( string $kind, string $after = '', int $per = 50 ): array {
		global $wpdb;
		$key   = self::KEYS[ $kind ] ?? self::KEYS['title'];
		$types = Analysis::types();
		$in    = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );
		// Only the page types analysed: a page of a type left out since (a page builder's
		// template, an FAQ item with no page) keeps an old hash until it is saved again.
		$sql  = "SELECT m.meta_value AS h, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ({$in})";
		$args = array_merge( array( $key ), array() === $types ? array( '' ) : $types );
		if ( '' !== $after ) {
			$sql   .= ' AND m.meta_value > %s';
			$args[] = $after;
		}
		$sql   .= ' GROUP BY m.meta_value HAVING COUNT(*) > 1 ORDER BY m.meta_value ASC LIMIT %d';
		$args[] = $per + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every part is a placeholder.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$more = count( $rows ) > $per;
		$rows = array_slice( $rows, 0, $per );
		$out  = array();
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one indexed lookup per group on this page; placeholders built above.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = 'publish' AND p.post_type IN ({$in}) ORDER BY m.post_id DESC LIMIT 50", array_merge( array( $key, (string) $row['h'] ), array() === $types ? array( '' ) : $types ) ) ) );
			_prime_post_caches( $ids, false, true );
			$pages = array();
			$shown = '';
			foreach ( $ids as $id ) {
				$p = get_post( $id );
				if ( ! $p instanceof \WP_Post ) {
					continue;
				}
				if ( '' === $shown ) {
					$c     = Context::for_post( $p );
					$shown = 'title' === $kind ? Resolver::title( $c ) : ( 'desc' === $kind ? Resolver::description( $c ) : ( 'h1' === $kind ? (string) $p->post_title : (string) $row['h'] ) );
				}
				$pages[] = array(
					'id'    => $id,
					'title' => '' !== $p->post_title ? $p->post_title : __( '(no title)', 'zinn-seo' ),
					'edit'  => (string) get_edit_post_link( $id, 'raw' ),
					'view'  => (string) get_permalink( $p ),
				);
			}
			$out[] = array(
				'hash'  => (string) $row['h'],
				'count' => (int) $row['n'],
				'value' => $shown,
				'pages' => $pages,
			);
		}

		return array(
			'groups' => $out,
			'next'   => $more && array() !== $rows ? (string) end( $rows )['h'] : '',
		);
	}

	/**
	 * How many groups of each kind (for the Fix-it inbox and the screen's tabs).
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$out   = array();
		$types = Analysis::types();
		$in    = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );
		foreach ( self::KEYS as $kind => $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one grouped count per kind, admin only; placeholders built above.
			$out[ $kind ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ({$in}) GROUP BY m.meta_value HAVING COUNT(*) > 1) g", array_merge( array( $key ), array() === $types ? array( '' ) : $types ) ) );
		}

		return $out;
	}
}
