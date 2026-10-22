<?php
/**
 * Keyword lists (zs-731).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A list is a row in `{prefix}zseo_kwlists` (label, colour, its phrases one per line); which posts
 * it is assigned to is `{prefix}zseo_kwlist_posts` (list, post) — two indexed tables, so "the
 * posts of a list" and "the lists of a post" are each one indexed read, at any size. Both tables
 * carry the plugin's prefix, so uninstall drops them with the rest.
 *
 * ⛔ No cap on the work: lists and their posts are read a page at a time by keyset (`id > after`),
 * never "the first N". A list's own phrases are bounded (`PHRASES_MAX`) because one list is sent
 * whole to a screen and to rank tracking; a site keeps as many lists as it likes.
 */
final class Keyword_Lists {

	/** Table schema version. */
	public const DB_VERSION = 1;

	/** Option holding the schema version. */
	public const DB_OPTION = 'zseo_kwlists_db';

	/** Most phrases in one list (one list is one screen and one rank-tracking upload). */
	public const PHRASES_MAX = 1000;

	/** Longest phrase. */
	public const PHRASE_LEN = 120;

	/** The colours a list may have (the admin kit's tag colours). */
	public const COLORS = array( 'blue', 'green', 'amber', 'red', 'purple', 'teal', 'grey' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		// A deleted post leaves no assignment behind.
		add_action( 'deleted_post', array( self::class, 'forget_post' ) );
		// A content brief planned from a list reads the list's phrases (zs-194 reuses zs-731).
		add_filter( 'zinn_seo_brief_searches', array( self::class, 'brief_searches' ), 10, 3 );
	}

	/**
	 * The phrases of the list a brief was asked from, after the related searches already found.
	 *
	 * @param mixed  $searches Related searches.
	 * @param string $topic    Topic.
	 * @param mixed  $opts     The brief's options (`kwlist`: a list id).
	 * @return array<int, array{phrase: string, searches: int|null}>
	 */
	public static function brief_searches( $searches, $topic = '', $opts = array() ): array {
		$searches = is_array( $searches ) ? $searches : array();
		$id       = is_array( $opts ) ? (int) ( $opts['kwlist'] ?? 0 ) : 0;
		$list     = $id > 0 ? self::get( $id ) : null;
		if ( null === $list ) {
			return $searches;
		}
		$have = array();
		foreach ( $searches as $s ) {
			$have[ strtolower( (string) ( $s['phrase'] ?? '' ) ) ] = true;
		}
		foreach ( array_slice( $list['phrases'], 0, 60 ) as $phrase ) {
			if ( ! isset( $have[ $phrase ] ) && strtolower( (string) $topic ) !== $phrase ) {
				$searches[] = array(
					'phrase'   => $phrase,
					'searches' => null,
				);
			}
		}

		return $searches;
	}

	/**
	 * The lists table.
	 *
	 * @return string
	 */
	public static function lists_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_kwlists';
	}

	/**
	 * The assignments table.
	 *
	 * @return string
	 */
	public static function posts_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_kwlist_posts';
	}

	/**
	 * Create the tables when their version is behind.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$lists   = self::lists_table();
		$posts   = self::posts_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$lists} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
label varchar(100) NOT NULL,
color varchar(16) NOT NULL DEFAULT 'blue',
phrases longtext NOT NULL,
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
KEY label (label)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$posts} (
list_id bigint(20) unsigned NOT NULL,
post_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (list_id,post_id),
KEY post_id (post_id)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Phrases from text or a list: trimmed, lower-cased, one per line, no duplicates.
	 *
	 * @param mixed $phrases A list, or text with one phrase per line (commas also separate).
	 * @return array<int, string>
	 */
	public static function clean_phrases( $phrases ): array {
		if ( is_string( $phrases ) ) {
			$phrases = preg_split( '/[\r\n,]+/', $phrases );
		}
		$out = array();
		foreach ( is_array( $phrases ) ? $phrases : array() as $phrase ) {
			if ( ! is_scalar( $phrase ) ) {
				continue;
			}
			$phrase = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $phrase ) ) );
			$phrase = function_exists( 'mb_strtolower' ) ? mb_strtolower( $phrase, 'UTF-8' ) : strtolower( $phrase );
			if ( '' !== $phrase && ( function_exists( 'mb_strlen' ) ? mb_strlen( $phrase ) : strlen( $phrase ) ) <= self::PHRASE_LEN ) {
				$out[ $phrase ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Check a list's fields.
	 *
	 * @param array<string, mixed> $fields `label`, `color`, `phrases`.
	 * @return array{label: string, color: string, phrases: array<int, string>}|\WP_Error
	 */
	public static function validate( array $fields ) {
		$label = trim( sanitize_text_field( (string) ( $fields['label'] ?? '' ) ) );
		if ( '' === $label || ( function_exists( 'mb_strlen' ) ? mb_strlen( $label ) : strlen( $label ) ) > 100 ) {
			return new \WP_Error( 'zinn_seo_kwlist_label', __( 'Give the list a name of up to 100 characters.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		$color   = in_array( $fields['color'] ?? '', self::COLORS, true ) ? (string) $fields['color'] : 'blue';
		$phrases = self::clean_phrases( $fields['phrases'] ?? array() );
		if ( count( $phrases ) > self::PHRASES_MAX ) {
			/* translators: %s: a number. */
			return new \WP_Error( 'zinn_seo_kwlist_size', sprintf( __( 'A list holds up to %s phrases. Split it into two lists.', 'zinn-seo' ), number_format_i18n( self::PHRASES_MAX ) ), array( 'status' => 422 ) );
		}

		return array(
			'label'   => $label,
			'color'   => $color,
			'phrases' => $phrases,
		);
	}

	/**
	 * A row as the screens see it.
	 *
	 * @param array<string, mixed> $row   Row.
	 * @param int                  $posts Posts assigned.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row, int $posts ): array {
		return array(
			'id'      => (int) $row['id'],
			'label'   => (string) $row['label'],
			'color'   => (string) $row['color'],
			'phrases' => self::clean_phrases( (string) $row['phrases'] ),
			'posts'   => $posts,
			'updated' => (string) $row['updated'],
		);
	}

	/**
	 * One list.
	 *
	 * @param int $id List.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		self::maybe_install();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::lists_table(), $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$posts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE list_id = %d', self::posts_table(), $id ) );
		// phpcs:enable

		return self::shape( $row, $posts );
	}

	/**
	 * A page of lists, by id (keyset), each with how many posts it is assigned to.
	 *
	 * @param int    $after  Last id seen.
	 * @param int    $limit  Page size.
	 * @param string $search Part of a label or phrase.
	 * @return array{items: array<int, array<string, mixed>>, next: int}
	 */
	public static function page( int $after = 0, int $limit = 50, string $search = '' ): array {
		self::maybe_install();
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, keyset paged.
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND ( label LIKE %s OR phrases LIKE %s ) ORDER BY id ASC LIMIT %d', self::lists_table(), $after, $like, $like, $limit + 1 ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', self::lists_table(), $after, $limit + 1 ), ARRAY_A );
		}
		$rows   = is_array( $rows ) ? $rows : array();
		$more   = count( $rows ) > $limit;
		$rows   = array_slice( $rows, 0, $limit );
		$counts = array();
		$ids    = array_map( static fn( array $r ): int => (int) $r['id'], $rows );
		if ( array() !== $ids ) {
			$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// One grouped read for the page's lists.
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT list_id, COUNT(*) AS n FROM %i WHERE list_id IN ($in) GROUP BY list_id", self::posts_table(), ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders, one per id spread after the table.
			foreach ( is_array( $found ) ? $found : array() as $f ) {
				$counts[ (int) $f['list_id'] ] = (int) $f['n'];
			}
		}
		// phpcs:enable

		return array(
			'items' => array_map( static fn( array $r ): array => self::shape( $r, $counts[ (int) $r['id'] ] ?? 0 ), $rows ),
			'next'  => $more && array() !== $ids ? (int) end( $ids ) : 0,
		);
	}

	/**
	 * Create a list.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( array $fields ) {
		$clean = self::validate( $fields );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		self::maybe_install();
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$ok = $wpdb->insert(
			self::lists_table(),
			array(
				'label'   => $clean['label'],
				'color'   => $clean['color'],
				'phrases' => implode( "\n", $clean['phrases'] ),
				'created' => $now,
				'updated' => $now,
			)
		);
		if ( false === $ok ) {
			return new \WP_Error( 'zinn_seo_kwlist_db', __( 'The list could not be saved. Try again.', 'zinn-seo' ), array( 'status' => 500 ) );
		}

		return self::get( (int) $wpdb->insert_id ) ?? array();
	}

	/**
	 * Change a list.
	 *
	 * @param int                  $id     List.
	 * @param array<string, mixed> $fields Fields (missing ones keep their value).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( int $id, array $fields ) {
		$now = self::get( $id );
		if ( null === $now ) {
			return new \WP_Error( 'zinn_seo_kwlist_missing', __( 'That keyword list no longer exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$clean = self::validate( $fields + $now );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update(
			self::lists_table(),
			array(
				'label'   => $clean['label'],
				'color'   => $clean['color'],
				'phrases' => implode( "\n", $clean['phrases'] ),
				'updated' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return self::get( $id ) ?? array();
	}

	/**
	 * Delete a list and its assignments.
	 *
	 * @param int $id List.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		self::maybe_install();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables.
		$wpdb->delete( self::posts_table(), array( 'list_id' => $id ) );
		$gone = (int) $wpdb->delete( self::lists_table(), array( 'id' => $id ) );
		// phpcs:enable

		return $gone > 0;
	}

	/**
	 * Assign posts to a list, or take them off it.
	 *
	 * @param int             $id     List.
	 * @param array<int, int> $add    Posts to add.
	 * @param array<int, int> $remove Posts to take off.
	 * @return array{added: int, removed: int}|\WP_Error
	 */
	public static function assign( int $id, array $add, array $remove = array() ) {
		if ( null === self::get( $id ) ) {
			return new \WP_Error( 'zinn_seo_kwlist_missing', __( 'That keyword list no longer exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$added   = 0;
		$removed = 0;
		foreach ( array_unique( array_map( 'intval', $add ) ) as $post ) {
			if ( $post > 0 && get_post( $post ) instanceof \WP_Post ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; INSERT IGNORE keeps it idempotent.
				$added += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (list_id, post_id) VALUES (%d, %d)', self::posts_table(), $id, $post ) );
			}
		}
		foreach ( array_unique( array_map( 'intval', $remove ) ) as $post ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
			$removed += (int) $wpdb->delete(
				self::posts_table(),
				array(
					'list_id' => $id,
					'post_id' => $post,
				)
			);
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Set exactly which lists a post is in.
	 *
	 * @param int             $post  Post.
	 * @param array<int, int> $lists Lists.
	 * @return array<int, int> The post's lists now.
	 */
	public static function set_for_post( int $post, array $lists ): array {
		$now  = self::ids_for_post( $post );
		$want = array_values( array_unique( array_filter( array_map( 'intval', $lists ) ) ) );
		foreach ( array_diff( $want, $now ) as $id ) {
			self::assign( (int) $id, array( $post ) );
		}
		foreach ( array_diff( $now, $want ) as $id ) {
			self::assign( (int) $id, array(), array( $post ) );
		}

		return self::ids_for_post( $post );
	}

	/**
	 * The ids of a post's lists.
	 *
	 * @param int $post Post.
	 * @return array<int, int>
	 */
	public static function ids_for_post( int $post ): array {
		self::maybe_install();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, indexed by post.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT list_id FROM %i WHERE post_id = %d ORDER BY list_id', self::posts_table(), $post ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * The phrases of every list a post is in (what the post should rank for), first list first.
	 *
	 * @param int $post Post.
	 * @return array<int, string>
	 */
	public static function phrases_for_post( int $post ): array {
		$out = array();
		foreach ( self::ids_for_post( $post ) as $id ) {
			$list = self::get( $id );
			foreach ( null === $list ? array() : $list['phrases'] as $phrase ) {
				$out[ $phrase ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * A page of the posts a list is assigned to (keyset by post id).
	 *
	 * @param int $id    List.
	 * @param int $after Last post id seen.
	 * @param int $limit Page size.
	 * @return array{items: array<int, array<string, mixed>>, next: int}
	 */
	public static function posts_of( int $id, int $after = 0, int $limit = 50 ): array {
		self::maybe_install();
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, keyset paged.
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE list_id = %d AND post_id > %d ORDER BY post_id ASC LIMIT %d', self::posts_table(), $id, $after, $limit + 1 ) ) );
		$more = count( $ids ) > $limit;
		$ids  = array_slice( $ids, 0, $limit );
		$out  = array();
		foreach ( $ids as $post ) {
			$p = get_post( $post );
			if ( $p instanceof \WP_Post ) {
				$out[] = array(
					'id'     => $post,
					'title'  => wp_strip_all_tags( get_the_title( $p ) ),
					'type'   => (string) $p->post_type,
					'status' => (string) $p->post_status,
					'link'   => (string) get_permalink( $p ),
					'edit'   => (string) get_edit_post_link( $post, 'raw' ),
				);
			}
		}

		return array(
			'items' => $out,
			'next'  => $more && array() !== $ids ? (int) end( $ids ) : 0,
		);
	}

	/**
	 * Forget a deleted post's assignments.
	 *
	 * @param int $post Post.
	 * @return void
	 */
	public static function forget_post( $post ): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) < self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete( self::posts_table(), array( 'post_id' => (int) $post ) );
	}

	/**
	 * Send a list's phrases to rank tracking: added to the phrases tracked already, none lost.
	 *
	 * @param int $id List.
	 * @return array{added: int, tracked: int}|\WP_Error
	 */
	public static function track( int $id ) {
		$list  = self::get( $id );
		$tools = '\ZinnDigital\ZinnSEO\Tools\Tools';
		if ( null === $list ) {
			return new \WP_Error( 'zinn_seo_kwlist_missing', __( 'That keyword list no longer exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( ! class_exists( $tools ) && ! ( \ZinnDigital\ZinnSEO\Modules::enabled( 'tools' ) && \ZinnDigital\ZinnSEO\Modules::load( 'tools' ) && class_exists( $tools ) ) ) {
			return new \WP_Error( 'zinn_seo_kwlist_tools', __( 'Rank tracking is switched off on this site.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$target = $tools::target();
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$rows = $tools::clean_keywords( (array) ( $target['keywords'] ?? array() ) );
		$have = array();
		foreach ( $rows as $row ) {
			$have[ strtolower( (string) ( $row['phrase'] ?? '' ) ) ] = true;
		}
		$added = 0;
		foreach ( $list['phrases'] as $phrase ) {
			if ( ! isset( $have[ $phrase ] ) ) {
				$rows[]          = array( 'phrase' => $phrase );
				$have[ $phrase ] = true;
				++$added;
			}
		}
		if ( 0 === $added ) {
			return array(
				'added'   => 0,
				'tracked' => count( $rows ),
			);
		}
		$saved = $tools::save_list( 'rank', $rows );

		return is_wp_error( $saved ) ? $saved : array(
			'added'   => $added,
			'tracked' => count( $rows ),
		);
	}

	/**
	 * A list held against the topical map (zs-091): for each phrase, the topic it falls in, its
	 * impressions and best position over 90 days, or nothing — a page still to write.
	 *
	 * @param int $id List.
	 * @return array{phrases: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function coverage( int $id ) {
		$list     = self::get( $id );
		$insights = '\ZinnDigital\ZinnSEO\Pro\Insights\Search_Insights';
		if ( null === $list ) {
			return new \WP_Error( 'zinn_seo_kwlist_missing', __( 'That keyword list no longer exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( ! class_exists( $insights ) && ! ( \ZinnDigital\ZinnSEO\Modules::enabled( 'search-insights' ) && \ZinnDigital\ZinnSEO\Modules::load( 'search-insights' ) && class_exists( $insights ) ) ) {
			return new \WP_Error( 'zinn_seo_kwlist_insights', __( 'The topical map needs Search Console connected (Pro).', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$map = $insights::topical( 90, false );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$seen = array();
		foreach ( (array) ( $map['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['searches'] ?? array() ) as $q ) {
				$seen[ strtolower( (string) ( $q['query'] ?? '' ) ) ] = array(
					'topic'       => (string) ( $g['topic'] ?? '' ),
					'impressions' => (int) ( $q['impressions'] ?? 0 ),
					'best'        => (string) ( $q['best'] ?? '' ),
				);
			}
		}
		$out = array();
		foreach ( $list['phrases'] as $phrase ) {
			$out[] = array( 'phrase' => $phrase ) + ( $seen[ $phrase ] ?? array(
				'topic'       => '',
				'impressions' => 0,
				'best'        => '',
			) );
		}

		return array( 'phrases' => $out );
	}
}
