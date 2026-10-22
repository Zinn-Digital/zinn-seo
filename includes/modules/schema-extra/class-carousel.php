<?php
/**
 * Carousels: an archive or category page of recipes, courses, films, restaurants or products as
 * an `ItemList` in the page's one graph (zs-736).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SchemaExtra;

use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google shows a host carousel for a "summary page" — a page listing entries that each have their
 * own page with that entry's structured data — when the list is an `ItemList` of `ListItem`s with
 * `position` and `url`, and every entry is the same kind: Recipe, Course, Movie, Restaurant (and
 * products in shopping results). This adds that list to an archive, a category or the blog's
 * listing page, from the posts the page ALREADY shows (the main query's own rows: no query of its
 * own), numbered across pages.
 *
 * What each entry is: the content type's choice in Structured data → Carousels, else `auto` —
 * a product is a Product, an LMS course a Course, a post with a recipe in it (WP Recipe Maker,
 * Tasty Recipes) a Recipe. Entries of other kinds are left out; a page with fewer than two
 * entries of one kind gets no list.
 */
final class Carousel {

	/** The kinds Google shows a carousel for. */
	public const KINDS = array( 'Recipe', 'Course', 'Movie', 'Restaurant', 'Product' );

	/** Course content types of the learning plugins we work with. */
	private const COURSES = array( 'sfwd-courses', 'courses', 'course', 'lp_course', 'stm-courses', 'llms_course' );

	/**
	 * What one post is in a carousel, or ''.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function kind_of( \WP_Post $post ): string {
		$set  = (array) Settings::get( 'carousel_types', array() );
		$item = (string) ( $set[ 'post:' . $post->post_type ]['item'] ?? 'auto' );
		if ( 'off' === $item ) {
			return '';
		}
		if ( in_array( $item, self::KINDS, true ) ) {
			$kind = $item;
		} elseif ( 'product' === $post->post_type ) {
			$kind = 'Product';
		} elseif ( in_array( $post->post_type, self::COURSES, true ) ) {
			$kind = 'Course';
		} elseif ( self::has_recipe( (string) $post->post_content ) ) {
			$kind = 'Recipe';
		} else {
			$kind = '';
		}

		/**
		 * Filters what a post is in an archive's carousel ('' = not in it).
		 *
		 * @param string   $kind One of Recipe, Course, Movie, Restaurant, Product, or ''.
		 * @param \WP_Post $post The post.
		 */
		$kind = (string) apply_filters( 'zinn_seo_carousel_kind', $kind, $post );

		return in_array( $kind, self::KINDS, true ) ? $kind : '';
	}

	/**
	 * Does saved content hold a recipe card (WP Recipe Maker, Tasty Recipes)?
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function has_recipe( string $content ): bool {
		foreach ( array( '<!-- wp:wp-recipe-maker/recipe', '[wprm-recipe', '<!-- wp:wp-tasty/tasty-recipe', '[tasty-recipe', 'wprm-recipe-container' ) as $mark ) {
			if ( false !== strpos( $content, $mark ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The list for a page's posts: the most common kind, its entries in the page's order.
	 *
	 * @param array<int, \WP_Post> $posts  The page's posts.
	 * @param int                  $offset Entries on earlier pages.
	 * @return array{kind: string, items: array<int, array<string, mixed>>}
	 */
	public static function build( array $posts, int $offset = 0 ): array {
		$by = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				$kind = self::kind_of( $post );
				if ( '' !== $kind ) {
					$by[ $kind ][] = $post;
				}
			}
		}
		if ( array() === $by ) {
			return array(
				'kind'  => '',
				'items' => array(),
			);
		}
		uasort( $by, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
		$kind  = (string) array_key_first( $by );
		$items = array();
		foreach ( $by[ $kind ] as $i => $post ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $offset + $i + 1,
				'url'      => (string) get_permalink( $post ),
			);
		}

		return array(
			'kind'  => $kind,
			'items' => $items,
		);
	}

	/**
	 * Add the list to an archive's graph.
	 *
	 * @param mixed $nodes Nodes.
	 * @param mixed $c     The page.
	 * @return array<string, mixed>
	 */
	public static function graph( $nodes, $c ): array {
		$nodes = (array) $nodes;
		if ( ! $c instanceof Context || ! isset( $nodes['webpage'] ) || ! in_array( $c->kind, array( 'tax', 'archive', 'home' ), true ) ) {
			return $nodes;
		}
		global $wp_query;
		$posts = $wp_query instanceof \WP_Query ? (array) $wp_query->posts : array();
		$per   = $wp_query instanceof \WP_Query ? max( 1, (int) $wp_query->get( 'posts_per_page' ) ) : count( $posts );
		$list  = self::build( $posts, ( max( 1, $c->page ) - 1 ) * $per );
		if ( count( $list['items'] ) < 2 ) {
			return $nodes;
		}
		$url                            = (string) $nodes['webpage']['url'];
		$nodes['itemlist']              = array(
			'@type'           => 'ItemList',
			'@id'             => $url . '#itemlist',
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'numberOfItems'   => count( $list['items'] ),
			'itemListElement' => $list['items'],
		);
		$nodes['webpage']['mainEntity'] = array( '@id' => $url . '#itemlist' );
		if ( 'WebPage' === ( $nodes['webpage']['@type'] ?? '' ) ) {
			$nodes['webpage']['@type'] = 'CollectionPage';
		}

		return $nodes;
	}

	/**
	 * The screen's list of content types with their carousel choice.
	 *
	 * @param mixed $data Admin data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data  = (array) $data;
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$types[] = array(
				'name'  => (string) $type->name,
				'label' => (string) $type->labels->name,
				'auto'  => 'product' === $type->name ? 'Product' : ( in_array( $type->name, self::COURSES, true ) ? 'Course' : '' ),
			);
		}
		$data['carousel'] = array(
			'types' => $types,
			'kinds' => self::KINDS,
		);

		return $data;
	}
}
