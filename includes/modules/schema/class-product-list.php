<?php
/**
 * The products a shop page lists, as an ItemList in its structured data.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On a WooCommerce shop page, category, tag or brand, the products on the page as an ItemList of
 * Products (name, address, images, offer), the way Rank Math Pro printed it. On zinnhub.com
 * (2026-10-14) moving off Rank Math dropped it from every product archive in 46 languages. Off by
 * default (`schema_product_lists`); the importer switches it on for a site that had it.
 */
final class Product_List {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'zinn_seo_graph', array( self::class, 'graph' ), 20, 2 );
	}

	/**
	 * Add the list to a product archive's graph.
	 *
	 * @param array<string, mixed> $nodes Nodes.
	 * @param mixed                $c     The page.
	 * @return array<string, mixed>
	 */
	public static function graph( $nodes, $c ) {
		if ( ! is_array( $nodes ) || ! $c instanceof Context || ! (bool) Settings::get( 'schema_product_lists', false ) || ! self::is_product_archive() ) {
			return $nodes;
		}
		$items = self::items();
		if ( array() !== $items ) {
			$nodes['productlist'] = array(
				'@type'           => 'ItemList',
				'itemListElement' => $items,
			);
		}

		return $nodes;
	}

	/**
	 * Is this a WooCommerce product archive (shop, product category, tag or brand)?
	 *
	 * @return bool
	 */
	private static function is_product_archive(): bool {
		if ( ! function_exists( 'wc_get_product' ) || is_singular() ) {
			return false;
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		return function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
	}

	/**
	 * The listed products of the main query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items(): array {
		global $wp_query;
		$out      = array();
		$position = 0;
		$seller   = self::seller();
		foreach ( (array) ( $wp_query->posts ?? array() ) as $post ) {
			$id      = is_object( $post ) ? (int) $post->ID : (int) $post;
			$product = $id > 0 ? wc_get_product( $id ) : null;
			if ( ! is_object( $product ) || 'grouped' === $product->get_type() ) {
				continue;
			}
			$item   = array(
				'@type' => 'Product',
				'name'  => Resolver::title( Context::for_post( get_post( $id ) ) ),
				'url'   => (string) get_permalink( $id ),
			);
			$images = self::images( $product );
			if ( array() !== $images ) {
				$item['image'] = $images;
			}
			$offer = self::offer( $product, $seller );
			if ( array() !== $offer ) {
				$item['offers'] = $offer;
			}
			$out[] = array(
				'@type'    => 'ListItem',
				'position' => ++$position,
				'item'     => $item,
			);
		}

		return $out;
	}

	/**
	 * The product's main and gallery images.
	 *
	 * @param object $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	private static function images( $product ): array {
		$out = array();
		$ids = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', (array) $product->get_gallery_image_ids() ) );
		foreach ( array_filter( $ids ) as $image_id ) {
			$src = wp_get_attachment_image_src( $image_id, 'single-post-thumbnail' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$out[] = array(
					'@type'  => 'ImageObject',
					'url'    => (string) $src[0],
					'height' => (int) $src[2],
					'width'  => (int) $src[1],
				);
			}
		}

		return $out;
	}

	/**
	 * Who sells: the site's organisation.
	 *
	 * @return array<string, mixed>
	 */
	private static function seller(): array {
		$home = home_url( '/' );
		$out  = array(
			'@type' => 'Organization',
			'@id'   => $home,
			'name'  => '' !== (string) Settings::get( 'identity_name', '' ) ? (string) Settings::get( 'identity_name', '' ) : (string) get_bloginfo( 'name' ),
			'url'   => untrailingslashit( $home ),
		);
		$logo = (string) Settings::get( 'identity_logo_url', '' );
		if ( '' !== $logo ) {
			$out['logo'] = $logo;
		}

		return $out;
	}

	/**
	 * The product's offer (or the range of a variable product's prices).
	 *
	 * @param object               $product Product.
	 * @param array<string, mixed> $seller  Seller.
	 * @return array<string, mixed>
	 */
	public static function offer( $product, array $seller ): array {
		if ( '' === (string) $product->get_price() ) {
			return array();
		}
		$currency = (string) get_woocommerce_currency();
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$until    = gmdate( 'Y-12-31', time() + YEAR_IN_SECONDS );
		if ( $product->is_type( 'variable' ) ) {
			$low  = wc_format_decimal( $product->get_variation_price( 'min', false ), $decimals );
			$high = wc_format_decimal( $product->get_variation_price( 'max', false ), $decimals );
			if ( $low !== $high ) {
				return array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => $low,
					'highPrice'     => $high,
					'offerCount'    => count( (array) $product->get_children() ),
					'priceCurrency' => $currency,
					'availability'  => self::availability( $product ),
					'seller'        => $seller,
					'url'           => (string) $product->get_permalink(),
				);
			}
		}
		$price = (string) wc_format_decimal( $product->get_price(), $decimals );
		if ( $product->get_date_on_sale_to() ) {
			$until = gmdate( 'Y-m-d', $product->get_date_on_sale_to()->getTimestamp() );
		}

		return array(
			'@type'              => 'Offer',
			'price'              => $price,
			'priceCurrency'      => $currency,
			'priceValidUntil'    => $until,
			'availability'       => self::availability( $product ),
			'itemCondition'      => 'NewCondition',
			'url'                => (string) $product->get_permalink(),
			'seller'             => $seller,
			'priceSpecification' => array(
				'price'                 => $price,
				'priceCurrency'         => $currency,
				'valueAddedTaxIncluded' => function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? 'true' : 'false',
			),
		);
	}

	/**
	 * Stock as schema.org reads it.
	 *
	 * @param object $product Product.
	 * @return string
	 */
	public static function availability( $product ): string {
		if ( ! $product->is_in_stock() ) {
			return 'http://schema.org/OutOfStock';
		}

		return 'http://schema.org/' . ( 'onbackorder' === $product->get_stock_status() ? 'BackOrder' : 'InStock' );
	}
}
