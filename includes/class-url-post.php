<?php
/**
 * The post an address of this site leads to, without running the archive or search it names.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ Q352 (PROOF3, zinnhub 0.17.52): `url_to_postid()` matches the address against the rewrite
 * rules and then RUNS the query they describe, only to answer 0 when it is not a single post. For
 * `/search/instagram+marketing/?post_type=product` that is the site's whole search
 * (`SQL_CALC_FOUND_ROWS ... LIKE '%instagram%' ...`), about a second each on zinnhub, and a
 * links-fix chunk with 28 such links spent 28 s before it read a page — every step, for twenty
 * steps. An archive (search, category, tag, author, date, a post type's list, a feed) is never a
 * single post, so here that one query is told to find nothing (`post__in = [0]`, no row count):
 * the answer is the same 0, at once. A single post's query is left exactly as WordPress builds it,
 * except that it does not count rows it never shows.
 */
final class Url_Post {

	/**
	 * `url_to_postid()`, without the archive or search query.
	 *
	 * @param string $url Address.
	 * @return int The post's ID, 0 when the address is not one post.
	 */
	public static function id( string $url ): int {
		$first = true;
		$guard = static function ( $query ) use ( &$first ): void {
			// Only the query url_to_postid() itself runs: anything a plugin starts meanwhile is its own.
			if ( ! $first || ! $query instanceof \WP_Query ) {
				return;
			}
			$first = false;
			$query->set( 'no_found_rows', true );
			if ( ! $query->is_singular() ) {
				$query->set( 'post__in', array( 0 ) );
			}
		};
		add_action( 'pre_get_posts', $guard, PHP_INT_MAX );
		try {
			return (int) url_to_postid( $url );
		} finally {
			remove_action( 'pre_get_posts', $guard, PHP_INT_MAX );
		}
	}
}
