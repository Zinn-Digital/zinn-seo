<?php
/**
 * One primed meta read per request for the objects a page shows (zs-153).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress already primes postmeta for the posts of the MAIN query (`update_post_meta_cache` is
 * on by default), so a single post or an archive page costs no extra query for our fields. The
 * gaps are a term archive (termmeta is primed only when a theme asks) and secondary queries a
 * theme or builder runs without meta priming; this closes the first and offers prime() for the
 * second, so a module never reads meta one object at a time.
 *
 * ⭐ A warm persistent object cache answers every one of these from memory: zero queries.
 */
final class Primer {

	/**
	 * Hooks (front end only).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp', array( self::class, 'prime_queried_term' ), 1 );
	}

	/**
	 * Prime the termmeta of the queried term on a term archive.
	 *
	 * @return void
	 */
	public static function prime_queried_term(): void {
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		// Queue it with the terms WordPress already queued for lazy loading (the loop's posts'
		// categories and tags), so the first term meta read loads them all in ONE query. Priming
		// it on its own cost a second query as soon as anything read a queued term's meta
		// (measured 2026-10-09, zinn_seo_p1.sh: +2 on a tag archive, +1 with this).
		if ( function_exists( 'wp_metadata_lazyloader' ) ) {
			wp_metadata_lazyloader()->queue_objects( 'term', array( (int) $term->term_id ) );
			return;
		}
		update_termmeta_cache( array( (int) $term->term_id ) );
	}

	/**
	 * Prime the postmeta of many posts at once (one query for all of them).
	 *
	 * @param array<int, int|\WP_Post> $posts Posts or IDs.
	 * @return void
	 */
	public static function prime( array $posts ): void {
		$ids = array();
		foreach ( $posts as $post ) {
			$id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( array() !== $ids ) {
			update_meta_cache( 'post', array_values( array_unique( $ids ) ) );
		}
	}
}
