<?php
/**
 * Link counts in the post lists, and the "orphans" filter (zs-102).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The counts of every row on the screen come from two grouped queries the first time a row
 * asks (never one query per row).
 */
final class Lists {

	/**
	 * Counts of the posts on this screen.
	 *
	 * @var array<int, array{in: int, out: int}>|null
	 */
	private static ?array $counts = null;

	/**
	 * Hooks (admin).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'zinn_seo_list_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'zinn_seo_list_filters', array( self::class, 'filters' ) );
		add_action( 'zinn_seo_list_filter', array( self::class, 'apply' ), 10, 2 );
	}

	/**
	 * Add "links in / out" under the scores.
	 *
	 * @param string $extra   HTML so far.
	 * @param int    $post_id Post.
	 * @return string
	 */
	public static function column( $extra, $post_id ): string {
		if ( ! Index::built() ) {
			return (string) $extra;
		}
		if ( null === self::$counts ) {
			global $wp_query;
			$ids          = $wp_query instanceof \WP_Query ? array_map( static fn( $p ) => is_object( $p ) ? (int) $p->ID : (int) $p, (array) $wp_query->posts ) : array();
			self::$counts = Index::counts( $ids );
		}
		$c = self::$counts[ (int) $post_id ] ?? Index::counts( array( (int) $post_id ) )[ (int) $post_id ];
		/* translators: 1: links pointing here from other pages, 2: links from this page to others. */
		$text = sprintf( __( 'Links: %1$d in, %2$d out', 'zinn-seo' ), $c['in'], $c['out'] );

		return (string) $extra . '<span class="zseo-col-links">' . esc_html( $text ) . '</span>';
	}

	/**
	 * The filter's choices.
	 *
	 * @param array<string, string> $choices Choices.
	 * @return array<string, string>
	 */
	public static function filters( $choices ): array {
		$choices = (array) $choices;
		if ( Index::built() ) {
			$choices['orphan']  = __( 'Orphans: nothing links here', 'zinn-seo' );
			$choices['deadend'] = __( 'Dead ends: links nowhere', 'zinn-seo' );
		}

		return $choices;
	}

	/**
	 * Apply the orphan / dead-end filter.
	 *
	 * @param string    $filter Filter.
	 * @param \WP_Query $query  Query.
	 * @return void
	 */
	public static function apply( $filter, $query ): void {
		if ( ! in_array( $filter, array( 'orphan', 'deadend' ), true ) || ! $query instanceof \WP_Query || ! Index::ready() ) {
			return;
		}
		$col = 'deadend' === $filter ? 'source_id' : 'target_id';
		add_filter(
			'posts_where',
			static function ( $where, $q ) use ( $query, $col ) {
				if ( $q !== $query ) {
					return $where;
				}
				global $wpdb;
				$table = Index::table();
				return $where . " AND NOT EXISTS (SELECT 1 FROM {$table} zl WHERE zl.{$col} = {$wpdb->posts}.ID)";
			},
			10,
			2
		);
	}
}
