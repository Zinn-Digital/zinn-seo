<?php
/**
 * WooCommerce: what every shop gets.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations\Woo;

use ZinnDigital\ZinnSEO\Integrations\Kit;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - The product node WooCommerce builds is already in the page's one graph (schema module). Here
 *   it gains what WooCommerce leaves out: the brand (WooCommerce's own Brands, a "Brand"
 *   attribute, or the shop's one brand from Settings) and a seller that points at the site's own
 *   organisation instead of repeating its name.
 * - A shop, category or tag listing that is filtered or sorted (`?orderby=`, `?min_price=`,
 *   `?filter_colour=`, a rating filter) shows the same products in another order: kept out of
 *   search results, links still followed. Its canonical address is the listing's own.
 * - Basket, checkout and account pages: out of search results and out of the sitemap.
 *
 * Nothing here runs a query: every answer is read from what WooCommerce has already loaded for
 * the page.
 */
final class Woo {

	/** Query arguments that filter or sort a product listing. */
	private const FILTERS = array( 'orderby', 'min_price', 'max_price', 'rating_filter', 'product_count', 'per_page', 'on_sale', 'in_stock' );

	/**
	 * Hooks.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the integration contract.
		Kit::graph( array( self::class, 'graph' ) );
		Kit::noindex( array( self::class, 'filtered' ) );
		Kit::noindex( array( self::class, 'private_page' ) );
		// The shop page is a page: what its owner typed in its SEO title and description fields
		// is what the shop listing says (the listing itself has no editor).
		foreach ( array( 'title', 'desc' ) as $field ) {
			Kit::answer(
				$field,
				static function ( $value, Context $c ) use ( $field ) {
					$own = self::shop_field( $c, $field );
					return '' !== $own ? $own : $value;
				}
			);
		}
		Kit::sitemap_skip( static fn( \WP_Post $post ): bool => 'page' === $post->post_type && in_array( (int) $post->ID, self::private_pages(), true ) );
	}

	/**
	 * The ids of the basket, checkout and account pages.
	 *
	 * @return array<int, int>
	 */
	public static function private_pages(): array {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array();
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : 0;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * What the shop page's own SEO field gives for the shop listing ('' when it is empty, or the
	 * page is not the shop listing).
	 *
	 * @param Context $c     The page.
	 * @param string  $field title or desc.
	 * @return string
	 */
	public static function shop_field( Context $c, string $field ): string {
		if ( 'archive' !== $c->kind || ! $c->object instanceof \WP_Post_Type || 'product' !== $c->object->name || ! function_exists( 'wc_get_page_id' ) ) {
			return '';
		}
		$shop = (int) wc_get_page_id( 'shop' );
		$own  = $shop > 0 ? (string) \ZinnDigital\ZinnSEO\Meta::get_post( $shop, $field ) : '';
		if ( '' === $own ) {
			return '';
		}
		$post = get_post( $shop );
		$text = $post instanceof \WP_Post ? \ZinnDigital\ZinnSEO\Templates\Vars::render( $own, \ZinnDigital\ZinnSEO\Templates\Resolver::lookup( Context::for_post( $post ) ), \ZinnDigital\ZinnSEO\Templates\Resolver::sep() ) : '';

		return 'title' === $field && $c->page > 1 ? trim( $text ) : $text;
	}

	/**
	 * Is the page the basket, the checkout or the account area?
	 *
	 * @param Context $c The page.
	 * @return bool
	 */
	public static function private_page( Context $c ): bool {
		$post = 'singular' === $c->kind ? $c->post() : null;

		return $post instanceof \WP_Post && 'page' === $post->post_type && in_array( (int) $post->ID, self::private_pages(), true );
	}

	/**
	 * Is this a product listing that a visitor filtered or sorted?
	 *
	 * @param Context $c The page.
	 * @return bool
	 */
	public static function filtered( Context $c ): bool {
		if ( ! (bool) Settings::get( 'shop_noindex_filters', true ) || ! self::listing( $c ) ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which filters a public listing address carries; nothing is changed.
		foreach ( array_keys( $_GET ) as $arg ) {
			$arg = (string) $arg;
			if ( in_array( $arg, self::FILTERS, true ) || 0 === strpos( $arg, 'filter_' ) || 0 === strpos( $arg, 'query_type_' ) ) {
				return true;
			}
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Is the page a product listing: the shop page, a product category, tag, brand or attribute?
	 *
	 * @param Context $c The page.
	 * @return bool
	 */
	public static function listing( Context $c ): bool {
		if ( 'archive' === $c->kind && $c->object instanceof \WP_Post_Type ) {
			return 'product' === $c->object->name;
		}
		if ( 'tax' === $c->kind && $c->term() ) {
			return in_array( 'product', (array) ( get_taxonomy( $c->term()->taxonomy )->object_type ?? array() ), true );
		}

		return false;
	}

	/**
	 * A product's brand: WooCommerce's Brands, else a "Brand" attribute, else the shop's own.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function brand( $product ): string {
		$id = (int) ( $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id() );
		foreach ( array( 'product_brand', 'pa_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $id, $taxonomy );
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				return Kit::text( $terms[0]->name );
			}
		}
		$typed = $product->get_attribute( 'brand' );

		return '' !== (string) $typed ? Kit::text( $typed ) : Kit::text( (string) Settings::get( 'shop_brand', '' ) );
	}

	/**
	 * The product node, completed.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @param Context                             $c     The page.
	 * @return array<string, array<string, mixed>>
	 */
	public static function graph( array $nodes, Context $c ): array {
		if ( ! isset( $nodes['product'] ) || ! is_array( $nodes['product'] ) || ! function_exists( 'wc_get_product' ) ) {
			return $nodes;
		}
		$post    = Kit::post_of( $c, array( 'product' ) );
		$product = $post ? wc_get_product( (int) $post->ID ) : null;
		if ( ! $product ) {
			return $nodes;
		}
		$node  = $nodes['product'];
		$brand = self::brand( $product );
		if ( '' !== $brand && ! isset( $node['brand'] ) ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}
		// The seller is this site: one node, referred to (WooCommerce repeats the name and address
		// in every offer).
		if ( isset( $nodes['identity'] ) || isset( $nodes['organization'] ) || isset( $nodes['publisher'] ) ) {
			$node['offers'] = self::each_offer(
				$node['offers'] ?? array(),
				static function ( array $offer ): array {
					$offer['seller'] = array( '@id' => Kit::site_id() );
					return $offer;
				}
			);
		}

		/**
		 * Filters a product's node before it is printed (Zinn® SEO Pro adds sizes and colours as
		 * variants, delivery and returns).
		 *
		 * @param array<string, mixed> $node    The node.
		 * @param \WC_Product          $product The product.
		 * @param Context              $c       The page.
		 */
		$nodes['product'] = (array) apply_filters( 'zinn_seo_product_node', $node, $product, $c );

		return $nodes;
	}

	/**
	 * Apply a change to every offer of a node (`offers` is one offer or a list).
	 *
	 * @param mixed    $offers The `offers` value.
	 * @param callable $change `function ( array $offer ): array`.
	 * @return mixed
	 */
	public static function each_offer( $offers, callable $change ) {
		if ( ! is_array( $offers ) || array() === $offers ) {
			return $offers;
		}
		if ( isset( $offers['@type'] ) ) {
			return $change( $offers );
		}
		foreach ( $offers as $i => $offer ) {
			if ( is_array( $offer ) ) {
				$offers[ $i ] = $change( $offer );
			}
		}

		return $offers;
	}
}
