<?php
/**
 * The HTML sitemap: a page of links for people, from a shortcode (zs-045, free part).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page (a cursor cannot be cached); every value is a placeholder of its prepare().

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[zinn_seo_sitemap]` prints, per content type, the newest pages as a list of links, and a link
 * to the next page of that type (a keyset cursor in the address, never a page number, so page 900
 * of a large site costs what page 1 does). Plain HTML: no stylesheet, no script; the theme styles
 * the lists. Pages asking not to be indexed are left out, as in the XML sitemap.
 *
 * Attributes: `types` (comma-separated post types; default every type in the XML sitemap),
 * `per_type` (links per type, 1–500, default 50), `taxonomies` (comma-separated; default none).
 * The block library (stage P3) wraps the same renderer as a block for every builder.
 */
final class Html {

	/** Query argument carrying the type being paged. */
	public const ARG_TYPE = 'zsm';

	/** Query argument carrying the cursor. */
	public const ARG_AFTER = 'zsa';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'zinn_seo_sitemap', array( self::class, 'shortcode' ) );
	}

	/**
	 * The shortcode.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts  = shortcode_atts(
			array(
				'types'      => '',
				'per_type'   => '50',
				'taxonomies' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'zinn_seo_sitemap'
		);
		$per   = max( 1, min( 500, (int) $atts['per_type'] ) );
		$types = self::types( (string) $atts['types'] );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public page's pagination; nothing changes.
		$only  = isset( $_GET[ self::ARG_TYPE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::ARG_TYPE ] ) ) : '';
		$after = isset( $_GET[ self::ARG_AFTER ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::ARG_AFTER ] ) ) : '';
		// phpcs:enable
		if ( '' !== $only && in_array( $only, $types, true ) ) {
			$types = array( $only );
		} elseif ( 0 === strpos( $only, 'tax-' ) ) {
			$types = array();
		} else {
			$after = '';
			$only  = '';
		}
		$out = '';
		foreach ( $types as $type ) {
			$out .= self::section( $type, $per, self::cursor( $after ) );
		}
		foreach ( array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['taxonomies'] ) ) ) as $tax ) {
			if ( '' === $only ) {
				$out .= self::terms( $tax, $per, 0 );
			} elseif ( 'tax-' . $tax === $only ) {
				$out = self::terms( $tax, $per, (int) $after );
			}
		}

		return '' === $out ? '' : '<nav class="zd-sitemap" aria-label="' . esc_attr__( 'Site map', 'zinn-seo' ) . '">' . $out . '</nav>';
	}

	/**
	 * The post types to list.
	 *
	 * @param string $wanted Comma-separated list from the shortcode.
	 * @return array<int, string>
	 */
	private static function types( string $wanted ): array {
		$in_sitemap = array();
		foreach ( Builder::units() as $unit ) {
			if ( 0 === strpos( $unit, 'post:' ) && 'post:attachment' !== $unit ) {
				$in_sitemap[] = substr( $unit, 5 );
			}
		}
		if ( '' === trim( $wanted ) ) {
			return $in_sitemap;
		}

		return array_values( array_intersect( array_map( 'sanitize_key', explode( ',', $wanted ) ), $in_sitemap ) );
	}

	/**
	 * Parse a cursor `<unix time>-<ID>`.
	 *
	 * @param string $raw Raw.
	 * @return array{0: string, 1: int}|null
	 */
	public static function cursor( string $raw ): ?array {
		if ( 1 !== preg_match( '/^(\d{1,12})-(\d{1,20})$/', $raw, $m ) ) {
			return null;
		}

		return array( gmdate( 'Y-m-d H:i:s', (int) $m[1] ), (int) $m[2] );
	}

	/**
	 * One type's list.
	 *
	 * @param string                        $type  Post type.
	 * @param int                           $per   Links.
	 * @param array{0: string, 1: int}|null $after Cursor.
	 * @return string
	 */
	private static function section( string $type, int $per, ?array $after ): string {
		global $wpdb;
		$object = get_post_type_object( $type );
		if ( ! $object instanceof \WP_Post_Type ) {
			return '';
		}
		$sql  = "SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = ''";
		$args = array( $type );
		if ( null !== $after ) {
			$sql .= ' AND post_date <= %s AND ( post_date < %s OR ID < %d )';
			array_push( $args, $after[0], $after[0], $after[1] );
		}
		$sql   .= ' ORDER BY post_date DESC, ID DESC LIMIT %d';
		$args[] = $per + 1;
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed fragments; every value is a placeholder.
		$more   = count( $rows ) > $per;
		$rows   = array_slice( $rows, 0, $per );
		$posts  = array_map( static fn( $r ) => new \WP_Post( $r ), $rows );
		update_post_cache( $posts );
		update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
		$items = '';
		foreach ( $posts as $post ) {
			if ( isset( Resolver::robots( Context::for_post( $post ) )['noindex'] ) ) {
				continue;
			}
			$items .= '<li><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></li>';
		}
		if ( '' === $items ) {
			return '';
		}
		$html = '<h2>' . esc_html( (string) $object->labels->name ) . '</h2><ul>' . $items . '</ul>';
		if ( $more ) {
			$last  = end( $posts );
			$next  = add_query_arg(
				array(
					self::ARG_TYPE  => $type,
					self::ARG_AFTER => strtotime( $last->post_date . ' UTC' ) . '-' . (int) $last->ID,
				)
			);
			$html .= '<p><a href="' . esc_url( $next ) . '">' . esc_html(
				sprintf(
					/* translators: %s: the plural name of a content type or taxonomy, e.g. "Posts" or "Categories". */
					__( 'More %s', 'zinn-seo' ),
					(string) $object->labels->name
				)
			) . '</a></p>';
		}

		return $html;
	}

	/**
	 * A taxonomy's terms that have posts, in pages (keyset on the term ID).
	 *
	 * @param string $tax   Taxonomy.
	 * @param int    $per   Links per page.
	 * @param int    $after Last term ID of the previous page.
	 * @return string
	 */
	private static function terms( string $tax, int $per, int $after ): string {
		global $wpdb;
		$object = get_taxonomy( $tax );
		if ( ! $object instanceof \WP_Taxonomy || ! $object->public ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the code above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.*, tt.* FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND tt.count > 0 AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d", $tax, $after, $per + 1 ) );
		$more = count( $rows ) > $per;
		$rows = array_slice( $rows, 0, $per );
		if ( array() === $rows ) {
			return '';
		}
		update_term_cache( $rows );
		$items = '';
		$last  = 0;
		foreach ( $rows as $row ) {
			$term = get_term( (int) $row->term_id );
			$last = (int) $row->term_id;
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$items .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a></li>';
			}
		}
		$html = '<h2>' . esc_html( (string) $object->labels->name ) . '</h2><ul>' . $items . '</ul>';
		if ( $more ) {
			$next  = add_query_arg(
				array(
					self::ARG_TYPE  => 'tax-' . $tax,
					self::ARG_AFTER => (string) $last,
				)
			);
			$html .= '<p><a href="' . esc_url( $next ) . '">' . esc_html(
				sprintf(
					/* translators: %s: the plural name of a content type or taxonomy, e.g. "Posts" or "Categories". */
					__( 'More %s', 'zinn-seo' ),
					(string) $object->labels->name
				)
			) . '</a></p>';
		}

		return $html;
	}
}
