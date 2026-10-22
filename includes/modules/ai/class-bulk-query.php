<?php
/**
 * Which items a bulk AI run does: ONE predicate for the count and for the run.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ THE DRY RUN'S COUNT IS THE RUN'S COUNT. `count_*()` and the keyset `posts()`/`images()` build
 * the same WHERE from the same scope, so "This will write 10,412 suggestions" is exactly what the
 * run then writes — never an estimate.
 *
 * ⛔⛔ A RUN NEVER RE-DOES AN ITEM. An item that already has a suggestion waiting for this task is
 * not selected, so a run killed mid-step (or started twice) pays for nothing twice: the items
 * done before the kill have their suggestion, the cursor's position does not matter.
 *
 * Keyset pagination (`ID > after ORDER BY ID`), never OFFSET: a million posts page in constant time.
 */
final class Bulk_Query {

	/**
	 * Post types a bulk run may cover: the public ones.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		sort( $types );

		return $types;
	}

	/**
	 * The scope, cleaned.
	 *
	 * @param array<string, mixed> $scope `types` (list), `missing` (bool: only items with none of their own).
	 * @return array{types: array<int, string>, missing: bool}
	 */
	public static function scope( array $scope ): array {
		$allowed = self::types();
		$types   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $scope['types'] ?? $allowed ) ), $allowed ) );

		return array(
			'types'   => array() === $types ? $allowed : $types,
			'missing' => ! array_key_exists( 'missing', $scope ) || ! empty( $scope['missing'] ),
		);
	}

	/**
	 * The WHERE for posts.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @param string               $task  Task id (its waiting suggestions are skipped).
	 * @return string SQL, already prepared.
	 */
	private static function post_where( array $scope, string $task ): string {
		global $wpdb;
		$scope  = self::scope( $scope );
		$types  = implode( ',', array_map( static fn( string $t ): string => $wpdb->prepare( '%s', $t ), $scope['types'] ) );
		$where  = "p.post_type IN ({$types}) AND p.post_status IN ('publish','future','draft','pending','private')";
		$where .= $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s )", Suggestions::key( $task ) );
		if ( $scope['missing'] && 'meta' === $task ) {
			// Only pages with no description of their own (the template writes theirs).
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = '_zseo_desc' AND d.meta_value <> '' )";
		}

		return $where;
	}

	/**
	 * The next posts after an id.
	 *
	 * @param int                  $after Last id done.
	 * @param int                  $limit How many.
	 * @param array<string, mixed> $scope Scope.
	 * @param string               $task  Task id.
	 * @return array<int, int>
	 */
	public static function posts( int $after, int $limit, array $scope, string $task ): array {
		global $wpdb;
		$where = self::post_where( $scope, $task );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from prepared parts above; keyset over the primary key.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $after, max( 1, $limit ) ) ) );
	}

	/**
	 * How many posts a run would do.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @param string               $task  Task id.
	 * @return int
	 */
	public static function count_posts( array $scope, string $task ): int {
		global $wpdb;
		$where = self::post_where( $scope, $task );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see posts().
	}

	/**
	 * The WHERE for images.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @return string
	 */
	private static function image_where( array $scope ): string {
		global $wpdb;
		$scope  = self::scope( $scope );
		$where  = "p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'";
		$where .= $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s )", Suggestions::key( 'alt' ) );
		if ( $scope['missing'] ) {
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt' AND a.meta_value <> '' )";
		}

		return $where;
	}

	/**
	 * The next images after an id.
	 *
	 * @param int                  $after Last id done.
	 * @param int                  $limit How many.
	 * @param array<string, mixed> $scope Scope.
	 * @return array<int, int>
	 */
	public static function images( int $after, int $limit, array $scope ): array {
		global $wpdb;
		$where = self::image_where( $scope );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see posts().
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $after, max( 1, $limit ) ) ) );
	}

	/**
	 * How many images a run would do.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @return int
	 */
	public static function count_images( array $scope ): int {
		global $wpdb;
		$where = self::image_where( $scope );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see posts().
	}
}
