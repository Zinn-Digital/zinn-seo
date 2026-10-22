<?php
/**
 * The bulk editor: titles, descriptions, robots and canonicals across any number of posts and
 * terms (zs-024, zs-351).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bulk;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Primer;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;
use ZinnDigital\ZinnSEO\Templates\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ KEYSET, NEVER OFFSET. A page of rows is "the next N with an ID below the last one shown",
 * an indexed range read that costs the same on page 1 and on page 10,000 of a million-post site.
 * There is no page count (that is a full scan); the screen loads more as the person scrolls.
 *
 * Three ways to change things, each one batch that can be undone (History):
 * 1. edit rows inline and save the changed ones (`PUT /bulk`);
 * 2. change one or more fields on every row matching the filters (`POST /bulk/apply`) — a
 *    background job, resumable, with no cap on how many rows it touches;
 * 3. undo any batch (`POST /bulk/undo`), which restores only fields nobody changed since.
 */
final class Bulk {

	/** Mass-change job. */
	public const JOB_APPLY = 'bulk-apply';

	/** Undo job. */
	public const JOB_UNDO = 'bulk-undo';

	/** Rows per page (screen default). */
	public const PER_PAGE = 50;

	/** Rows a computed filter may scan in one request before returning what it has. */
	private const SCAN_LIMIT = 2000;

	/**
	 * Fields the bulk editor edits (per-object meta fields).
	 *
	 * @return array<int, string>
	 */
	public static function fields(): array {
		$out = array( 'title', 'desc', 'canonical', 'robots' );
		foreach ( array_merge( array( 'og_title', 'og_desc', 'og_image' ), self::SCHEMA_FIELDS ) as $name ) {
			if ( isset( Meta::fields()[ $name ] ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * The structured data column (zs-764): page type, article type, and the FAQ, how-to, product
	 * and article details (posts only).
	 */
	public const SCHEMA_FIELDS = array( 'schema_page', 'schema_article', 'schema_faq', 'schema_howto', 'schema_product', 'schema_article_data' );

	/**
	 * Fields a change to every matching row may set (a record of details belongs to one page).
	 *
	 * @param string $name Field.
	 * @return bool
	 */
	public static function mass_changeable( string $name ): bool {
		$def = Meta::fields()[ $name ] ?? null;

		return null !== $def && 'json' !== (string) $def['type'];
	}

	/**
	 * Hooks (admin, REST, CLI, cron — wherever a job step can run).
	 *
	 * @return void
	 */
	public static function boot(): void {
		History::listen();
		Jobs::register( self::JOB_APPLY, static fn() => __( 'Change SEO fields in bulk', 'zinn-seo' ), array( self::class, 'apply_step' ) );
		Jobs::register( self::JOB_UNDO, static fn() => __( 'Undo a bulk change', 'zinn-seo' ), array( self::class, 'undo_step' ) );
	}

	/**
	 * Normalise a filter from a request.
	 *
	 * @param array<string, mixed> $raw Raw.
	 * @return array<string, mixed>
	 */
	public static function filter( array $raw ): array {
		$kind   = 'term' === ( $raw['object'] ?? 'post' ) ? 'term' : 'post';
		$type   = sanitize_key( (string) ( $raw['type'] ?? '' ) );
		$type   = '' !== $type ? $type : ( 'term' === $kind ? 'category' : 'post' );
		$status = sanitize_key( (string) ( $raw['status'] ?? 'any' ) );

		return array(
			'object'   => $kind,
			'type'     => $type,
			'status'   => in_array( $status, array( 'any', 'publish', 'draft', 'pending', 'future', 'private' ), true ) ? $status : 'any',
			'search'   => mb_substr( sanitize_text_field( (string) ( $raw['search'] ?? '' ) ), 0, 100 ),
			'missing'  => in_array( ( $raw['missing'] ?? '' ), array( 'title', 'desc' ), true ) ? (string) $raw['missing'] : '',
			'robots'   => in_array( ( $raw['robots'] ?? '' ), array( 'noindex', 'index' ), true ) ? (string) $raw['robots'] : '',
			'discover' => 'post' === $kind && ! empty( $raw['discover'] ),
			'term'     => max( 0, (int) ( $raw['term'] ?? 0 ) ),
		);
	}

	/**
	 * May the current user use the bulk editor on this kind of object?
	 *
	 * @param array<string, mixed> $f Filter.
	 * @return bool
	 */
	public static function allowed( array $f ): bool {
		if ( ! current_user_can( Capabilities::EDIT ) ) {
			return false;
		}
		if ( 'term' === $f['object'] ) {
			$tax = get_taxonomy( (string) $f['type'] );
			return $tax instanceof \WP_Taxonomy && $tax->public && current_user_can( $tax->cap->edit_terms );
		}
		$type = get_post_type_object( (string) $f['type'] );

		return $type instanceof \WP_Post_Type && ( $type->public || is_post_type_viewable( $type ) ) && current_user_can( $type->cap->edit_posts );
	}

	/**
	 * Read a list cursor: a post's `"<post_date>|<ID>"` (newest first, since 0.15.2), a legacy
	 * integer ("IDs below this", what a list or a job stored before 0.15.2 — honoured as before),
	 * or nothing (the first page).
	 *
	 * @param mixed $raw Cursor as sent or stored.
	 * @return array{mode: string, date: string, id: int} `mode` is start, date or id.
	 */
	public static function cursor( $raw ): array {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $raw, $m ) ) {
			return array(
				'mode' => 'date',
				'date' => $m[1],
				'id'   => (int) $m[2],
			);
		}
		$id = 1 === preg_match( '/^\d+$/', $raw ) ? (int) $raw : 0;

		return array(
			'mode' => $id > 0 ? 'id' : 'start',
			'date' => '',
			'id'   => $id,
		);
	}

	/**
	 * The cursor that continues a list after this object.
	 *
	 * @param array<string, mixed> $f  Filter.
	 * @param int                  $id The last object shown or changed.
	 * @param array<string, mixed> $at The cursor it was read under (a legacy one keeps its order).
	 * @return int|string
	 */
	public static function cursor_after( array $f, int $id, array $at ) {
		if ( 'term' === $f['object'] || 'id' === $at['mode'] ) {
			return $id;
		}
		$post = get_post( $id );

		return $post instanceof \WP_Post ? $post->post_date . '|' . $id : $id;
	}

	/**
	 * One keyset page of IDs matching the SQL-expressible part of a filter.
	 *
	 * ⛔ Posts page by `(post_date, ID)` newest first, each status on its own, merged here
	 * (0.15.2). Measured 2026-10-12 (stage T1, 1,000,000 posts and 2,000,000 newer images): the
	 * old `ORDER BY ID DESC` made MariaDB walk the primary key down through every newer row that
	 * is not one of the listed posts — the FIRST page took 3,456 ms (2.89 s in the query alone).
	 * `post_type = … AND post_status = … ORDER BY post_date DESC, ID DESC` is the order of WordPress
	 * core's own type_status_date index, so a page reads its rows and no others, whatever else
	 * the table holds; an `IN` of statuses would lose that order, hence one query per status.
	 * Terms keep `term_id`, and a legacy integer cursor keeps `ID DESC` (a job started before the
	 * change finishes in the order it began).
	 *
	 * @param array<string, mixed> $f      Filter.
	 * @param mixed                $before Cursor (see `cursor()`; 0 or '' from the top).
	 * @param int                  $limit  How many.
	 * @return array<int, int> IDs, in list order.
	 */
	public static function ids( array $f, $before, int $limit ): array {
		$at = self::cursor( $before );
		if ( 'term' !== $f['object'] && 'id' !== $at['mode'] ) {
			return self::post_ids_by_date( $f, $at, $limit );
		}
		$before = $at['id'];
		global $wpdb;
		$term       = 'term' === $f['object'];
		$id_col     = $term ? 't.term_id' : 'p.ID';
		$join       = array();
		$join_args  = array();
		$where      = array();
		$where_args = array();
		if ( $term ) {
			$from         = "{$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id";
			$where[]      = 'tt.taxonomy = %s';
			$where_args[] = (string) $f['type'];
			$title_col    = 't.name';
		} else {
			$from = "{$wpdb->posts} p";
			if ( 'attachment' === $f['type'] ) {
				$where[] = "p.post_type = 'attachment' AND p.post_status = 'inherit'";
			} else {
				$where[]      = 'p.post_type = %s';
				$where_args[] = (string) $f['type'];
				if ( 'any' === $f['status'] ) {
					$where[] = "p.post_status IN ('publish','draft','pending','future','private')";
				} else {
					$where[]      = 'p.post_status = %s';
					$where_args[] = (string) $f['status'];
				}
			}
			$title_col = 'p.post_title';
			if ( $f['term'] > 0 ) {
				$join[]      = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} ftt ON ftt.term_taxonomy_id = tr.term_taxonomy_id AND ftt.term_id = %d";
				$join_args[] = (int) $f['term'];
			}
		}
		if ( $before > 0 ) {
			$where[]      = "{$id_col} < %d";
			$where_args[] = $before;
		}
		if ( '' !== $f['search'] ) {
			$where[]      = "{$title_col} LIKE %s";
			$where_args[] = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
		}
		$meta_table = $term ? $wpdb->termmeta : $wpdb->postmeta;
		$meta_col   = $term ? 'term_id' : 'post_id';
		if ( '' !== $f['missing'] ) {
			$join[]      = "LEFT JOIN {$meta_table} mm ON mm.{$meta_col} = {$id_col} AND mm.meta_key = %s";
			$join_args[] = Meta::key( (string) $f['missing'] );
			$where[]     = "( mm.meta_value IS NULL OR mm.meta_value = '' )";
		}
		if ( '' !== $f['robots'] ) {
			$join[]      = "LEFT JOIN {$meta_table} mr ON mr.{$meta_col} = {$id_col} AND mr.meta_key = %s";
			$join_args[] = Meta::key( 'robots' );
			$key         = $term ? 'tax:' . $f['type'] : 'post:' . $f['type'];
			// Hidden = its own noindex, or its type is hidden and it did not ask to be indexed.
			if ( 'noindex' === Templates::effective( $key )['index'] ) {
				$hidden = '( mr.meta_value IS NULL OR mr.meta_value NOT LIKE %s )';
				$like   = '%' . $wpdb->esc_like( '"index"' ) . '%';
			} else {
				$hidden = '( mr.meta_value IS NOT NULL AND mr.meta_value LIKE %s )';
				$like   = '%' . $wpdb->esc_like( '"noindex"' ) . '%';
			}
			$where[]      = 'noindex' === $f['robots'] ? $hidden : "NOT {$hidden}";
			$where_args[] = $like;
		}
		$sql  = "SELECT {$id_col} FROM {$from} " . implode( ' ', $join ) . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY {$id_col} DESC LIMIT %d";
		$args = array_merge( $join_args, $where_args, array( $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a keyset page (no cache can hold a cursor); every value is a placeholder of this prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Posts newest first by `(post_date, ID)`: one index-ordered query per status, merged.
	 *
	 * @param array<string, mixed>                       $f     Filter (a post one).
	 * @param array{mode: string, date: string, id: int} $at    Cursor (start or date).
	 * @param int                                        $limit How many.
	 * @return array<int, int> IDs.
	 */
	private static function post_ids_by_date( array $f, array $at, int $limit ): array {
		global $wpdb;
		if ( 'attachment' === $f['type'] ) {
			$statuses = array( 'inherit' );
		} elseif ( 'any' === $f['status'] ) {
			$statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
		} else {
			$statuses = array( (string) $f['status'] );
		}
		$join      = array();
		$join_args = array();
		$where     = array( 'p.post_type = %s', 'p.post_status = %s' );
		$tail      = array();
		if ( $f['term'] > 0 ) {
			$join[]      = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} ftt ON ftt.term_taxonomy_id = tr.term_taxonomy_id AND ftt.term_id = %d";
			$join_args[] = (int) $f['term'];
		}
		if ( 'date' === $at['mode'] ) {
			$where[] = '( p.post_date < %s OR ( p.post_date = %s AND p.ID < %d ) )';
			array_push( $tail, $at['date'], $at['date'], $at['id'] );
		}
		if ( '' !== $f['search'] ) {
			$where[] = 'p.post_title LIKE %s';
			$tail[]  = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
		}
		if ( '' !== $f['missing'] ) {
			$join[]      = "LEFT JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID AND mm.meta_key = %s";
			$join_args[] = Meta::key( (string) $f['missing'] );
			$where[]     = "( mm.meta_value IS NULL OR mm.meta_value = '' )";
		}
		if ( '' !== $f['robots'] ) {
			$join[]      = "LEFT JOIN {$wpdb->postmeta} mr ON mr.post_id = p.ID AND mr.meta_key = %s";
			$join_args[] = Meta::key( 'robots' );
			if ( 'noindex' === Templates::effective( 'post:' . $f['type'] )['index'] ) {
				$hidden = '( mr.meta_value IS NULL OR mr.meta_value NOT LIKE %s )';
				$like   = '%' . $wpdb->esc_like( '"index"' ) . '%';
			} else {
				$hidden = '( mr.meta_value IS NOT NULL AND mr.meta_value LIKE %s )';
				$like   = '%' . $wpdb->esc_like( '"noindex"' ) . '%';
			}
			$where[] = 'noindex' === $f['robots'] ? $hidden : "NOT {$hidden}";
			$tail[]  = $like;
		}
		$sql  = "SELECT p.ID, p.post_date FROM {$wpdb->posts} p " . implode( ' ', $join ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d';
		$rows = array();
		foreach ( $statuses as $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- a keyset page (no cache can hold a cursor); $sql is fixed SQL and %s/%d placeholders only, every value is passed to this prepare().
			$got = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $join_args, array( (string) $f['type'], $status ), $tail, array( $limit ) ) ), ARRAY_N );
			foreach ( (array) $got as $row ) {
				$rows[] = array( (int) $row[0], (string) $row[1] );
			}
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $b[1], $b[0] ) <=> array( $a[1], $a[0] );
			}
		);

		return array_map( static fn( array $r ): int => $r[0], array_slice( $rows, 0, $limit ) );
	}

	/**
	 * A page of rows for the screen.
	 *
	 * @param array<string, mixed> $f        Filter.
	 * @param mixed                $before   Cursor (see `cursor()`; 0 or '': first page).
	 * @param int                  $per_page Rows wanted.
	 * @return array{rows: array<int, array<string, mixed>>, next: int|string|null, total: int|null}
	 */
	public static function page( array $f, $before, int $per_page ): array {
		$at      = self::cursor( $before );
		$rows    = array();
		$cursor  = $before;
		$scanned = 0;
		$next    = null;
		$have    = 0;
		while ( $have < $per_page ) {
			$want = $f['discover'] ? max( $per_page, 100 ) : $per_page - $have;
			$ids  = self::ids( $f, $cursor, $want );
			if ( array() === $ids ) {
				$next = null;
				break;
			}
			if ( 'post' === $f['object'] ) {
				_prime_post_caches( $ids, true, true );
				Primer::prime( $ids );
			} else {
				update_termmeta_cache( $ids );
			}
			foreach ( $ids as $id ) {
				$cursor = self::cursor_after( $f, $id, $at );
				$row    = self::row( (string) $f['object'], $id, (bool) $f['discover'] );
				if ( null === $row || ( $f['discover'] && ! empty( $row['discover_ready'] ) ) ) {
					continue;
				}
				$rows[] = $row;
				$have   = count( $rows );
				if ( $have >= $per_page ) {
					break;
				}
			}
			$next     = $cursor;
			$scanned += count( $ids );
			if ( count( $ids ) < $want || $scanned >= self::SCAN_LIMIT ) {
				$next = count( $ids ) < $want && count( $rows ) < $per_page ? null : $cursor;
				break;
			}
		}
		if ( null !== $next && array() === self::ids( $f, $next, 1 ) ) {
			$next = null;
		}

		return array(
			'rows'  => $rows,
			'next'  => $next,
			'total' => self::total( $f ),
		);
	}

	/**
	 * How many rows the filter matches, when that is cheap to know (no search and no meta filter);
	 * null otherwise (the screen says "more" instead of a number).
	 *
	 * @param array<string, mixed> $f Filter.
	 * @return int|null
	 */
	public static function total( array $f ): ?int {
		if ( '' !== $f['search'] || '' !== $f['missing'] || '' !== $f['robots'] || $f['discover'] || $f['term'] > 0 ) {
			return null;
		}
		if ( 'term' === $f['object'] ) {
			$n = wp_count_terms( array( 'taxonomy' => (string) $f['type'] ) );
			return is_numeric( $n ) ? (int) $n : null;
		}
		$counts = (array) wp_count_posts( (string) $f['type'] );
		if ( 'any' !== $f['status'] ) {
			return (int) ( $counts[ $f['status'] ] ?? 0 );
		}
		if ( 'attachment' === $f['type'] ) {
			return (int) ( $counts['inherit'] ?? 0 );
		}
		$sum = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $status ) {
			$sum += (int) ( $counts[ $status ] ?? 0 );
		}

		return $sum;
	}

	/**
	 * One row: the object, its fields and what the templates give.
	 *
	 * @param string $kind   post|term.
	 * @param int    $id       ID.
	 * @param bool   $discover Include Discover readiness.
	 * @return array<string, mixed>|null
	 */
	public static function row( string $kind, int $id, bool $discover = false ): ?array {
		$fields = array();
		$schema = null;
		if ( 'term' === $kind ) {
			$term = get_term( $id );
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
			foreach ( self::fields() as $name ) {
				$fields[ $name ] = Meta::get_term( $id, $name );
			}
			$c    = Context::for_term( $term );
			$link = get_term_link( $term );
			$row  = array(
				'id'       => $id,
				'label'    => $term->name,
				'url'      => is_string( $link ) ? $link : '',
				'edit_url' => (string) get_edit_term_link( $term ),
				'status'   => '',
				'can_edit' => Capabilities::can_edit_term( $id ),
			);
		} else {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
			foreach ( self::fields() as $name ) {
				$fields[ $name ] = Meta::get_post( $id, $name );
			}
			$c   = Context::for_post( $post );
			$row = array(
				'id'       => $id,
				'label'    => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'zinn-seo' ),
				'url'      => (string) get_permalink( $post ),
				'edit_url' => (string) get_edit_post_link( $post, 'raw' ),
				'status'   => $post->post_status,
				'can_edit' => Capabilities::can_edit_post( $id ),
			);
			if ( null !== self::schema_choices() ) {
				// zs-764: what the structured data column shows when the page has no type of its own.
				$schema = \ZinnDigital\ZinnSEO\Schema\Schema::type_defaults( $post->post_type );
			}
		}
		$row['fields'] = $fields;
		$row['auto']   = array(
			'title'     => Resolver::auto_title( $c ),
			'desc'      => Resolver::auto_description( $c ),
			'canonical' => Resolver::address( $c ),
		);
		$row['hidden'] = isset( Resolver::robots( $c )['noindex'] );
		if ( null !== $schema ) {
			$row['auto']['schema'] = $schema;
		}
		if ( $discover && class_exists( '\ZinnDigital\ZinnSEO\Social\Discover' ) ) {
			$row['discover_ready'] = \ZinnDigital\ZinnSEO\Social\Discover::check( $c )['ready'];
		}

		return $row;
	}

	/**
	 * Check a set of field changes for one object; null when every value is acceptable.
	 *
	 * A structured data record (zs-764) is also checked against schema.org and Google's rules as
	 * the page would print it; one with an error is refused, so a bulk save never stores markup
	 * the check would call wrong.
	 *
	 * @param string               $kind   post|term.
	 * @param array<string, mixed> $fields Field => value.
	 * @param int                  $id     The object (0: a change to every matching row).
	 * @return string|null The problem.
	 */
	public static function invalid( string $kind, array $fields, int $id = 0 ): ?string {
		$defined = Meta::fields();
		foreach ( $fields as $name => $value ) {
			$def = $defined[ (string) $name ] ?? null;
			if ( null === $def || ! in_array( (string) $name, self::fields(), true ) || ! in_array( $kind, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
				/* translators: %s: a field name. */
				return sprintf( __( 'There is no SEO field called %s here.', 'zinn-seo' ), (string) $name );
			}
			$clean = Meta::sanitize( $def, $value );
			if ( is_wp_error( $clean ) ) {
				/* translators: %s: a field name. */
				return sprintf( __( 'The value for %s is not allowed.', 'zinn-seo' ), (string) $name );
			}
			if ( 'json' === (string) $def['type'] ) {
				$post = $id > 0 ? get_post( $id ) : null;
				if ( ! $post instanceof \WP_Post ) {
					/* translators: %s: a field name. */
					return sprintf( __( '%s is set page by page: open the page\'s row, or let AI fill it.', 'zinn-seo' ), (string) $name );
				}
				$problem = self::schema_problem( (string) $name, (string) $clean, $post );
				if ( null !== $problem ) {
					return $problem;
				}
			}
		}

		return null;
	}

	/**
	 * What is wrong with a structured data record as the page would print it, or null.
	 *
	 * @param string   $name  Field (`schema_faq` …).
	 * @param string   $json  Its cleaned JSON ('' = none).
	 * @param \WP_Post $post  The page.
	 * @return string|null
	 */
	public static function schema_problem( string $name, string $json, \WP_Post $post ): ?string {
		if ( '' === $json || ! class_exists( '\ZinnDigital\ZinnSEO\Schema\Fill' ) ) {
			return null;
		}
		$kind  = \ZinnDigital\ZinnSEO\Schema\Fill::kind_of( $name );
		$check = \ZinnDigital\ZinnSEO\Schema\Fill::check( $kind, (array) json_decode( $json, true ), $post );
		if ( $check['ok'] ) {
			return null;
		}

		/* translators: 1: what kind of structured data, e.g. "FAQ", 2: what is wrong. */
		return sprintf( __( '%1$s: %2$s', 'zinn-seo' ), \ZinnDigital\ZinnSEO\Schema\Fill::label( $kind ), implode( ' ', $check['errors'] ) );
	}

	/**
	 * Save edited rows as one batch. All rows are checked first; nothing is stored when one fails.
	 *
	 * @param string                           $kind post|term.
	 * @param array<int, array<string, mixed>> $rows   [{id, fields}].
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save( string $kind, array $rows ) {
		$clean = array();
		foreach ( $rows as $i => $row ) {
			$id     = (int) ( $row['id'] ?? 0 );
			$fields = (array) ( $row['fields'] ?? array() );
			$exists = 'term' === $kind ? get_term( $id ) instanceof \WP_Term : get_post( $id ) instanceof \WP_Post;
			if ( ! $exists ) {
				/* translators: %d: a row number. */
				return new \WP_Error( 'zinn_seo_not_found', sprintf( __( 'Row %d: nothing with that ID exists.', 'zinn-seo' ), $i + 1 ), array( 'status' => 404 ) );
			}
			if ( ! ( 'term' === $kind ? Capabilities::can_edit_term( $id ) : Capabilities::can_edit_post( $id ) ) ) {
				/* translators: %d: a row number. */
				return new \WP_Error( 'zinn_seo_forbidden', sprintf( __( 'Row %d: you may not edit this one.', 'zinn-seo' ), $i + 1 ), array( 'status' => 403 ) );
			}
			$problem = self::invalid( $kind, $fields, $id );
			if ( null !== $problem ) {
				/* translators: 1: a row number, 2: what is wrong. */
				return new \WP_Error( 'zinn_seo_invalid_value', sprintf( __( 'Row %1$d: %2$s', 'zinn-seo' ), $i + 1, $problem ), array( 'status' => 400 ) );
			}
			$clean[ $id ] = $fields;
		}
		/* translators: %d: how many rows. */
		$batch = History::open( $kind, sprintf( _n( 'Edited %d row', 'Edited %d rows', count( $clean ), 'zinn-seo' ), count( $clean ) ), 'bulk' );
		foreach ( $clean as $id => $fields ) {
			foreach ( $fields as $name => $value ) {
				Meta::set( $kind, (int) $id, (string) $name, $value, 'bulk' );
			}
		}
		$summary = History::close();
		$out     = array();
		foreach ( array_keys( $clean ) as $id ) {
			$row = self::row( $kind, (int) $id );
			if ( null !== $row ) {
				$out[] = $row;
			}
		}

		return array(
			'batch'   => (int) ( $summary['count'] ?? 0 ) > 0 ? $batch : '',
			'changes' => (int) ( $summary['count'] ?? 0 ),
			'rows'    => $out,
		);
	}

	/**
	 * Start a change on every row matching a filter (background job).
	 *
	 * @param array<string, mixed> $f      Filter.
	 * @param array<string, mixed> $fields Field => value.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start_apply( array $f, array $fields ) {
		if ( array() === $fields ) {
			return new \WP_Error( 'zinn_seo_nothing', __( 'Choose at least one field to change.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$problem = self::invalid( (string) $f['object'], $fields );
		if ( null !== $problem ) {
			return new \WP_Error( 'zinn_seo_invalid_value', $problem, array( 'status' => 400 ) );
		}
		foreach ( array( self::JOB_APPLY, self::JOB_UNDO ) as $job ) {
			if ( in_array( Jobs::get( $job )['status'], array( 'pending', 'paused' ), true ) ) {
				return new \WP_Error( 'zinn_seo_busy', __( 'A bulk change is already running. Wait for it to finish, or cancel it, then try again.', 'zinn-seo' ), array( 'status' => 409 ) );
			}
		}
		$label = sprintf(
			/* translators: 1: field names, 2: the kind of content, e.g. "Posts". */
			__( 'Changed %1$s on every matching %2$s', 'zinn-seo' ),
			implode( ', ', array_keys( $fields ) ),
			self::type_label( $f )
		);
		$batch   = History::open( (string) $f['object'], $label, 'bulk' );
		$open    = History::suspend();
		$state   = array(
			'filter' => $f,
			'fields' => $fields,
			'cursor' => '',
			'batch'  => $open,
			'done'   => 0,
		);
		$started = Jobs::start( self::JOB_APPLY, $state );
		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return array(
			'batch' => $batch,
			'job'   => self::JOB_APPLY,
		);
	}

	/**
	 * One step of the mass change.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function apply_step( array $state, float $deadline ): array {
		$f = (array) $state['filter'];
		History::resume( (array) $state['batch'] );
		$finished = false;
		// A job started before 0.15.2 stored an integer cursor: it finishes in the ID order it began.
		$at = self::cursor( $state['cursor'] );
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$ids = self::ids( $f, $state['cursor'], 200 );
			if ( array() === $ids ) {
				$finished = true;
				break;
			}
			if ( 'post' === $f['object'] ) {
				_prime_post_caches( $ids, false, false );
				Primer::prime( $ids );
			} else {
				update_termmeta_cache( $ids );
			}
			foreach ( $ids as $id ) {
				if ( $f['discover'] && 'post' === $f['object'] ) {
					$post = get_post( $id );
					if ( $post instanceof \WP_Post && class_exists( '\ZinnDigital\ZinnSEO\Social\Discover' ) && \ZinnDigital\ZinnSEO\Social\Discover::check( Context::for_post( $post ) )['ready'] ) {
						$state['cursor'] = self::cursor_after( $f, $id, $at );
						continue;
					}
				}
				foreach ( (array) $state['fields'] as $name => $value ) {
					Meta::set( (string) $f['object'], $id, (string) $name, $value, 'bulk' );
				}
				$state['cursor'] = self::cursor_after( $f, $id, $at );
				$state['done']   = (int) $state['done'] + 1;
				if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
					break 2;
				}
			}
			History::release( array( (string) $f['object'] => $ids ) );
		}
		if ( $finished ) {
			$summary        = History::close();
			$state['batch'] = $summary;
		} else {
			$open = History::suspend();
			if ( null !== $open ) {
				History::index_put( $open );
			}
			$state['batch'] = $open;
		}

		return array(
			'state'    => $state,
			'done'     => $finished,
			'progress' => array(
				'done'  => (int) $state['done'],
				'total' => self::total( $f ),
			),
		);
	}

	/**
	 * Undo a batch: at once when small, in the background when large.
	 *
	 * @param string $id Batch.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function undo( string $id ) {
		$batch = History::find( $id );
		if ( null === $batch ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'That change is no longer in the history.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( '' !== (string) $batch['undone'] ) {
			return new \WP_Error( 'zinn_seo_undone', __( 'That change was already undone.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		foreach ( array( self::JOB_APPLY, self::JOB_UNDO ) as $job ) {
			if ( in_array( Jobs::get( $job )['status'], array( 'pending', 'paused' ), true ) ) {
				return new \WP_Error( 'zinn_seo_busy', __( 'A bulk change is already running. Wait for it to finish, or cancel it, then try again.', 'zinn-seo' ), array( 'status' => 409 ) );
			}
		}
		/* translators: %s: what the undone change was. */
		$label = sprintf( __( 'Undid: %s', 'zinn-seo' ), (string) $batch['label'] );
		$undo  = History::open( (string) $batch['object'], $label, 'undo' );
		if ( (int) $batch['count'] <= 500 ) {
			$result  = History::undo_step( $id, 0, microtime( true ) + 25 );
			$summary = History::close();
			if ( $result['done'] ) {
				History::mark_undone( $id, $undo );
				return array(
					'batch'    => (int) ( $summary['count'] ?? 0 ) > 0 ? $undo : '',
					'restored' => $result['restored'],
					'kept'     => $result['kept'],
					'job'      => '',
				);
			}
			// Ran out of time: carry on in the background from where it stopped.
			History::resume( (array) $summary );
			$state = array(
				'undo'     => $id,
				'cursor'   => $result['cursor'],
				'restored' => $result['restored'],
				'kept'     => $result['kept'],
				'batch'    => History::suspend(),
			);
		} else {
			$state = array(
				'undo'     => $id,
				'cursor'   => 0,
				'restored' => 0,
				'kept'     => 0,
				'batch'    => History::suspend(),
			);
		}
		$started = Jobs::start( self::JOB_UNDO, $state );

		return is_wp_error( $started ) ? $started : array(
			'batch'    => $undo,
			'restored' => (int) $state['restored'],
			'kept'     => (int) $state['kept'],
			'job'      => self::JOB_UNDO,
		);
	}

	/**
	 * One step of a background undo.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function undo_step( array $state, float $deadline ): array {
		History::resume( (array) $state['batch'] );
		$result            = History::undo_step( (string) $state['undo'], (int) $state['cursor'], $deadline );
		$state['cursor']   = $result['cursor'];
		$state['restored'] = (int) $state['restored'] + $result['restored'];
		$state['kept']     = (int) $state['kept'] + $result['kept'];
		if ( $result['done'] ) {
			$summary = History::close();
			History::mark_undone( (string) $state['undo'], (string) ( $summary['id'] ?? '' ) );
			$state['batch'] = $summary;
		} else {
			$state['batch'] = History::suspend();
		}
		$total = History::find( (string) $state['undo'] );

		return array(
			'state'    => $state,
			'done'     => $result['done'],
			'progress' => array(
				'done'  => (int) $state['restored'] + (int) $state['kept'],
				'total' => null !== $total ? (int) $total['count'] : null,
			),
		);
	}

	/**
	 * The plural name of a filter's content type.
	 *
	 * @param array<string, mixed> $f Filter.
	 * @return string
	 */
	public static function type_label( array $f ): string {
		$kind = 'term' === $f['object'] ? get_taxonomy( (string) $f['type'] ) : get_post_type_object( (string) $f['type'] );

		return is_object( $kind ) && isset( $kind->labels->name ) ? (string) $kind->labels->name : (string) $f['type'];
	}

	/**
	 * What the screen's filters can choose from: public post types and taxonomies.
	 *
	 * @return array<string, array<int, array{value: string, label: string}>>
	 */
	public static function choices(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name && ! Templates::attachment_pages() ) {
				continue;
			}
			// A hidden type (no page of its own, or hidden from search and scores, Q277) has no
			// title or description worth editing; its thousands of items would only bury the rest.
			if ( 'attachment' !== $type->name && \ZinnDigital\ZinnSEO\Templates\Content_Types::hidden( (string) $type->name ) ) {
				continue;
			}
			if ( current_user_can( $type->cap->edit_posts ) ) {
				$types[] = array(
					'value' => $type->name,
					'label' => (string) $type->labels->name,
				);
			}
		}
		$taxes = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' !== $tax->name && current_user_can( $tax->cap->edit_terms ) ) {
				$taxes[] = array(
					'value' => $tax->name,
					'label' => (string) $tax->labels->name,
				);
			}
		}

		return array(
			'post' => $types,
			'term' => $taxes,
		);
	}

	/**
	 * The structured data column's choices (zs-764): page and article types.
	 *
	 * @return array{page: array<int, string>, article: array<int, string>}|null Null when the
	 *         structured data feature is switched off.
	 */
	public static function schema_choices(): ?array {
		if ( ! class_exists( '\ZinnDigital\ZinnSEO\Schema\Schema' ) || ! \ZinnDigital\ZinnSEO\Modules::enabled( 'schema' ) ) {
			return null;
		}

		return array(
			'page'    => \ZinnDigital\ZinnSEO\Schema\Schema::PAGE_TYPES,
			'article' => array_values( array_filter( \ZinnDigital\ZinnSEO\Schema\Schema::ARTICLE_TYPES ) ),
		);
	}

	/**
	 * The REST namespace (for the screen).
	 *
	 * @return string
	 */
	public static function ns(): string {
		return Plugin::REST_NS;
	}
}
