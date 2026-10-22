<?php
/**
 * The primary term picker (zs-745): one main category per post, used by its address, its
 * breadcrumbs and its structured data.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\PrimaryTerm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A post in three categories has one address with `%category%` in it: WordPress picks the
 * category with the LOWEST id, which is rarely the one the post is really about. The main category
 * chosen here is used instead — in the address (`post_link_category`, and WooCommerce's
 * `wc_product_post_type_link_product_cat`), in the breadcrumb trail and in the article's section.
 *
 * ⛔ A main category the post no longer carries is ignored (the post was moved out of it), so an
 * address can never point at a category the post is not in.
 */
final class Primary_Term {

	/** The meta key (the SEO field `primary_term`). */
	public const META = '_zseo_primary_term';

	/**
	 * Hooks for every request type (addresses are made in wp-admin, feeds, sitemaps and REST too).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'post_link_category', array( self::class, 'post_link_category' ), 10, 3 );
		add_filter( 'wc_product_post_type_link_product_cat', array( self::class, 'product_link_category' ), 10, 3 );
	}

	/**
	 * The taxonomy a post type's main category comes from: categories for posts, shop categories
	 * for products, else the type's first public hierarchical taxonomy.
	 *
	 * @param string $post_type Post type.
	 * @return string '' when it has none.
	 */
	public static function taxonomy_for( string $post_type ): string {
		if ( 'post' === $post_type ) {
			return 'category';
		}
		if ( 'product' === $post_type && taxonomy_exists( 'product_cat' ) ) {
			return 'product_cat';
		}
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
			if ( $tax->public && $tax->hierarchical && 'post_format' !== $tax->name ) {
				return (string) $tax->name;
			}
		}

		return '';
	}

	/**
	 * A post's main category, when it is still one of its terms.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Term|null
	 */
	public static function get( \WP_Post $post ): ?\WP_Term {
		$id = (int) get_post_meta( $post->ID, self::META, true );
		if ( $id < 1 ) {
			return null;
		}
		$taxonomy = self::taxonomy_for( $post->post_type );
		if ( '' === $taxonomy ) {
			return null;
		}

		return self::pick( $id, (array) get_the_terms( $post, $taxonomy ) );
	}

	/**
	 * The term with this id among a post's terms, or null.
	 *
	 * @param int               $id    Wanted term id.
	 * @param array<int, mixed> $terms The post's terms.
	 * @return \WP_Term|null
	 */
	public static function pick( int $id, array $terms ): ?\WP_Term {
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && (int) $term->term_id === $id ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * `%category%` in a post's address: its main category.
	 *
	 * @param \WP_Term|mixed    $category The category WordPress chose.
	 * @param array<int, mixed> $categories The post's categories.
	 * @param \WP_Post|mixed    $post     Post.
	 * @return \WP_Term|mixed
	 */
	public static function post_link_category( $category, $categories, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $category;
		}
		$id = (int) get_post_meta( $post->ID, self::META, true );

		return $id > 0 ? ( self::pick( $id, (array) $categories ) ?? $category ) : $category;
	}

	/**
	 * A product's address (WooCommerce, `%product_cat%`): its main shop category.
	 *
	 * @param \WP_Term|mixed    $term  The category WooCommerce chose.
	 * @param array<int, mixed> $terms The product's categories.
	 * @param \WP_Post|mixed    $post  Product.
	 * @return \WP_Term|mixed
	 */
	public static function product_link_category( $term, $terms, $post ) {
		return self::post_link_category( $term, $terms, $post );
	}

	/**
	 * What the editors need: the taxonomy per post type.
	 *
	 * @param array<string, mixed> $data Editor data.
	 * @return array<string, mixed>
	 */
	public static function editor_data( $data ): array {
		$data = (array) $data;
		$map  = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$tax = self::taxonomy_for( (string) $type );
			if ( '' !== $tax ) {
				$tax_object   = get_taxonomy( $tax );
				$map[ $type ] = array(
					'taxonomy' => $tax,
					'rest'     => $tax_object && ! empty( $tax_object->rest_base ) ? (string) $tax_object->rest_base : $tax,
					'label'    => $tax_object ? (string) $tax_object->labels->singular_name : $tax,
				);
			}
		}
		$data['primaryTerm']            = $map;
		$data['guides']['primary-term'] = \ZinnDigital\ZinnSEO\Guide::url( 'primary-term' );

		return $data;
	}

	/**
	 * The classic editor's field: a choice among the post's own categories (only when it has
	 * more than one).
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_field( $fields ): array {
		$fields = (array) $fields;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post ) {
			return $fields;
		}
		$taxonomy = self::taxonomy_for( $post->post_type );
		$terms    = '' !== $taxonomy ? get_the_terms( $post, $taxonomy ) : false;
		if ( ! is_array( $terms ) || count( $terms ) < 2 ) {
			return $fields;
		}
		$choices = array( '0' => __( 'Chosen by WordPress', 'zinn-seo' ) );
		foreach ( $terms as $term ) {
			$choices[ (string) $term->term_id ] = $term->name;
		}
		$fields['primary_term'] = array(
			'label'   => __( 'Main category', 'zinn-seo' ),
			'kind'    => 'select',
			'choices' => $choices,
			'help'    => __( 'Used in the address, the breadcrumb trail and the article section. Save the post after adding or removing a category to see the new list.', 'zinn-seo' ),
		);

		return $fields;
	}
}
