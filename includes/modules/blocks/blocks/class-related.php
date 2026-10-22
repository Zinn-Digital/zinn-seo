<?php
/**
 * Related content (zs-309).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links to the posts most related to this one: picked by hand, or chosen by what the posts share
 * (topics, then words in the title). The finished list is kept with the post, so a visitor's page
 * costs no database query (the post's meta is already loaded with it); it is worked out again when
 * the post is saved, and at most once a day after that.
 *
 * The internal-link engine (stage P4) supplies better candidates through
 * `zinn_seo_related_candidates`; this block renders whatever it returns.
 */
final class Related extends Block {

	/** Post meta: the cached list, `[ key => [ 'at' => time, 'html' => … ] ]`. */
	public const META_CACHE = '_zseo_related';

	/** How long a computed list is kept before it is worked out again. */
	public const TTL = DAY_IN_SECONDS;

	/** {@inheritDoc} */
	public function name(): string {
		return 'related';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Related content', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Links to the most related posts, chosen for you or by hand, with no database work on each visit.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'admin-links';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'related posts', 'zinn-seo' ), __( 'internal links', 'zinn-seo' ), __( 'read next', 'zinn-seo' ) );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'title'   => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Title', 'zinn-seo' ),
				'default' => __( 'Related reading', 'zinn-seo' ),
				'content' => true,
				'max'     => 120,
			),
			'count'   => array(
				'type'    => 'number',
				'label'   => static fn() => __( 'How many', 'zinn-seo' ),
				'default' => 4,
				'min'     => 1,
				'max'     => 12,
			),
			'pick'    => array(
				'type'  => 'list',
				'label' => static fn() => __( 'Posts picked by hand (IDs or addresses, one per line; empty: chosen for you)', 'zinn-seo' ),
				'max'   => 300,
			),
			'images'  => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Show pictures', 'zinn-seo' ),
				'default' => false,
			),
			'excerpt' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Show a short summary', 'zinn-seo' ),
				'default' => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	public function render( array $v, Render_Context $ctx ): string {
		$post = $ctx->post;
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$key   = substr( md5( (string) wp_json_encode( $v ) . '|' . get_locale() ), 0, 12 );
		$cache = get_post_meta( $post->ID, self::META_CACHE, true );
		$cache = is_array( $cache ) ? $cache : array();
		$hit   = $cache[ $key ] ?? null;
		if ( ! $ctx->preview && is_array( $hit ) && (int) ( $hit['at'] ?? 0 ) > time() - self::TTL && (int) ( $hit['v'] ?? 0 ) === self::stamp( $post ) ) {
			return (string) $hit['html'];
		}
		$html = self::html( $v, self::choose( $post, $v ) );
		if ( ! $ctx->preview && 'publish' === $post->post_status ) {
			// Keep the newest few variants only (a block with other settings elsewhere on the site).
			$cache[ $key ] = array(
				'at'   => time(),
				'v'    => self::stamp( $post ),
				'html' => $html,
			);
			uasort( $cache, static fn( $a, $b ): int => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
			update_post_meta( $post->ID, self::META_CACHE, array_slice( $cache, 0, 4, true ) );
		}

		return $html;
	}

	/**
	 * A post's version for the cache: its modified time, so a save works the list out again.
	 *
	 * @param \WP_Post $post Post.
	 * @return int
	 */
	private static function stamp( \WP_Post $post ): int {
		return (int) strtotime( (string) $post->post_modified_gmt );
	}

	/**
	 * The posts to link to.
	 *
	 * @param \WP_Post             $post Post.
	 * @param array<string, mixed> $v    Values.
	 * @return array<int, \WP_Post>
	 */
	public static function choose( \WP_Post $post, array $v ): array {
		$count  = max( 1, min( 12, (int) $v['count'] ) );
		$picked = array();
		foreach ( (array) $v['pick'] as $ref ) {
			$id = 1 === preg_match( '/^\d+$/', (string) $ref ) ? (int) $ref : url_to_postid( (string) $ref );
			if ( $id > 0 && $id !== (int) $post->ID ) {
				$picked[] = $id;
			}
		}
		if ( array() !== $picked ) {
			$ids = array_slice( array_values( array_unique( $picked ) ), 0, $count );
		} else {
			$ids = self::candidates( $post, $count );
		}
		if ( array() === $ids ) {
			return array();
		}
		_prime_post_caches( $ids, false, false );
		$out = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( $p instanceof \WP_Post && 'publish' === $p->post_status && '' === $p->post_password ) {
				$out[] = $p;
			}
		}

		return $out;
	}

	/**
	 * Related posts by what they share: the topics (rarer topics count more), then words of the
	 * title; newest first among equals. One query for the candidates, bounded at 200.
	 *
	 * @param \WP_Post $post  Post.
	 * @param int      $count How many.
	 * @return array<int, int>
	 */
	public static function candidates( \WP_Post $post, int $count ): array {
		/**
		 * Filters related posts before they are chosen here (the internal-link engine's answer).
		 * Return post IDs to use them; null to let this block choose.
		 *
		 * @param array<int, int>|null $ids   IDs.
		 * @param \WP_Post             $post  Post.
		 * @param int                  $count How many.
		 */
		$given = apply_filters( 'zinn_seo_related_candidates', null, $post, $count );
		if ( is_array( $given ) ) {
			return array_slice( array_map( 'intval', $given ), 0, $count );
		}
		$weights = array();
		$taxes   = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				// A topic on few posts says more than one on every post.
				$weights[ (int) $term->term_taxonomy_id ] = 1 / log( 2 + max( 1, (int) $term->count ) );
				$taxes[ $tax->name ][]                    = (int) $term->term_id;
			}
		}
		$query = array(
			'post_type'              => $post->post_type,
			'post_status'            => 'publish',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- see the code above.
			'post__not_in'           => array( (int) $post->ID ),
			'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- candidates for ONE list, worked out on save or once a day, never per visit.
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'suppress_filters'       => false,
		);
		if ( array() !== $taxes ) {
			$query['tax_query'] = array( 'relation' => 'OR' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded at 200 rows, run on save or once a day, never per visit.
			foreach ( $taxes as $tax => $ids ) {
				$query['tax_query'][] = array(
					'taxonomy' => $tax,
					'terms'    => $ids,
				);
			}
		}
		$found = get_posts( $query );
		$words = self::title_words( (string) $post->post_title );
		$score = array();
		foreach ( $found as $i => $p ) {
			$s = 0.0;
			foreach ( array_keys( $taxes ) as $tax ) {
				$terms = get_the_terms( $p, $tax );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$s += $weights[ (int) $term->term_taxonomy_id ] ?? 0.0;
				}
			}
			$s += 0.3 * count( array_intersect( $words, self::title_words( (string) $p->post_title ) ) );
			// Newer posts win ties: a small bonus that never beats a shared topic.
			$score[ (int) $p->ID ] = $s + ( 200 - $i ) / 100000;
		}
		arsort( $score );

		return array_slice( array_keys( $score ), 0, $count );
	}

	/**
	 * The meaningful words of a title (three letters or more).
	 *
	 * @param string $title Title.
	 * @return array<int, string>
	 */
	private static function title_words( string $title ): array {
		$title = function_exists( 'mb_strtolower' ) ? mb_strtolower( Html::plain( $title ) ) : strtolower( Html::plain( $title ) );
		preg_match_all( '/[\p{L}\p{N}]{3,}/u', $title, $m );

		return array_values( array_unique( $m[0] ) );
	}

	/**
	 * The list.
	 *
	 * @param array<string, mixed> $v     Values.
	 * @param array<int, \WP_Post> $posts Posts.
	 * @return string
	 */
	public static function html( array $v, array $posts ): string {
		if ( array() === $posts ) {
			return '';
		}
		$out = Html::open( 'nav', Html::cls( 'related', $v['images'] ? 'related--images' : '' ), array( 'aria-label' => (string) $v['title'] ) );
		if ( '' !== $v['title'] ) {
			$out .= Html::open( 'p', Html::cls( 'related__title' ) ) . esc_html( $v['title'] ) . '</p>';
		}
		$out .= '<ul>';
		foreach ( $posts as $p ) {
			$out .= '<li>' . Html::open( 'a', '', array( 'href' => (string) get_permalink( $p ) ) );
			if ( $v['images'] && has_post_thumbnail( $p ) ) {
				$out .= (string) get_the_post_thumbnail(
					$p,
					'medium',
					array(
						'loading'  => 'lazy',
						'decoding' => 'async',
						'alt'      => '',
					)
				);
			}
			$out .= '<span>' . esc_html( Html::plain( get_the_title( $p ) ) ) . '</span></a>';
			if ( $v['excerpt'] ) {
				$excerpt = Html::plain( '' !== $p->post_excerpt ? $p->post_excerpt : wp_trim_words( Html::plain( strip_shortcodes( $p->post_content ) ), 22, '…' ) );
				if ( '' !== $excerpt ) {
					$out .= Html::open( 'p', Html::cls( 'related__excerpt' ) ) . esc_html( $excerpt ) . '</p>';
				}
			}
			$out .= '</li>';
		}

		return $out . '</ul></nav>';
	}
}
