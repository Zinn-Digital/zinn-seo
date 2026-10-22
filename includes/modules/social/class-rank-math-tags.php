<?php
/**
 * The sharing tags Rank Math printed beyond ours, for a site that moved from it.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Social;

use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rank Math prints a fuller set of sharing tags than this plugin does by default: the X title,
 * text and image even where Open Graph says the same, the image's type and secure address, the
 * "updated" time, a product's `product:*` tags (price, currency, stock, brand, SKU), and two
 * label/value pairs X and Slack show under a shared link ("Written by", "Time to read"; "Price",
 * "Availability"). On zinnhub.com (2026-10-14) the move dropped them from about 295 of 364
 * sampled pages. With `social_rank_math` on (the importer sets it) the same tags are printed.
 *
 * Only reads here (facts); the tags are written by Social::build(), which is pure.
 */
final class Rank_Math_Tags {

	/**
	 * The extra facts for a page.
	 *
	 * @param Context              $c     The page.
	 * @param array<string, mixed> $facts The page's facts so far.
	 * @return array<string, mixed>
	 */
	public static function facts( Context $c, array $facts ): array {
		$post    = $c->post();
		$out     = array(
			'type'       => '',
			'updated'    => '',
			'published'  => '',
			'modified'   => '',
			'section'    => '',
			'author_fb'  => '',
			'publisher'  => '',
			'image_type' => '',
			'product'    => array(),
			'labels'     => array(),
			'creator'    => '',
			'tags'       => array(),
		);
		$image   = is_array( $facts['image'] ?? null ) ? $facts['image'] : null;
		$product = null;
		if ( $image && '' !== (string) ( $image['url'] ?? '' ) ) {
			$type              = wp_check_filetype( (string) wp_parse_url( (string) $image['url'], PHP_URL_PATH ) );
			$out['image_type'] = is_string( $type['type'] ?? null ) ? $type['type'] : '';
		}
		if ( $post && 'singular' === $c->kind ) {
			$pub = (string) mysql2date( DATE_W3C, $post->post_date, false );
			$mod = (string) mysql2date( DATE_W3C, $post->post_modified, false );
			if ( strtotime( $mod ) > strtotime( $pub ) ) {
				$out['updated'] = $mod;
			}
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post->ID );
			}
			if ( is_object( $product ) ) {
				$out['type'] = 'product';
			} else {
				// A post's tags, one article:tag each (GATE1: lost on posts after the move).
				$tags = is_object_in_taxonomy( $post->post_type, 'post_tag' ) ? get_the_terms( $post, 'post_tag' ) : array();
				foreach ( is_array( $tags ) ? $tags : array() as $tag ) {
					$out['tags'][] = wp_strip_all_tags( (string) $tag->name );
				}
				$out['published'] = $pub;
				$out['modified']  = $mod;
				$out['section']   = (string) ( $facts['section'] ?? '' );
				$out['author_fb'] = (string) ( $facts['author_fb'] ?? '' );
				$out['publisher'] = self::facebook_page();
			}
		}
		if ( is_object( $product ) ) {
			$out['product'] = self::product_tags( $product, (int) $post->ID );
			$price          = function_exists( 'wc_price' ) && '' !== (string) $product->get_price() ? html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			$out['labels']  = array(
				array( __( 'Price', 'zinn-seo' ), $price ),
				array( __( 'Availability', 'zinn-seo' ), $product->is_in_stock() ? __( 'In stock', 'zinn-seo' ) : __( 'Out of stock', 'zinn-seo' ) ),
			);
		} elseif ( $post && 'singular' === $c->kind && ! self::slack_sharing( (string) $post->post_type, $c->front ) ) {
			// Its "Slack Enhanced Sharing" off for this type (by default every type but posts,
			// pages and products): no label lines (GATE1: added on a custom type after the move).
			$out['labels'] = array();
		} elseif ( $post && 'singular' === $c->kind ) {
			$minutes = self::time_to_read( (string) $post->post_content );
			$read    = $minutes > 0
				/* translators: %d: minutes. */
				? sprintf( _n( '%d minute', '%d minutes', $minutes, 'zinn-seo' ), $minutes )
				: __( 'Less than a minute', 'zinn-seo' );
			$out['labels'] = 'page' === $post->post_type && ! $c->front
				? array( array( __( 'Time to read', 'zinn-seo' ), $read ) )
				: array(
					array( __( 'Written by', 'zinn-seo' ), (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
					array( __( 'Time to read', 'zinn-seo' ), $read ),
				);
		} elseif ( 'tax' === $c->kind && $c->term() ) {
			// An archive was an "article" to it, with the site's Facebook page as publisher, and
			// its count labelled with the kind of content listed ("Posts", "Products").
			$term             = $c->term();
			$out['type']      = 'article';
			$out['publisher'] = self::facebook_page();
			$listed           = get_post_type_object( (string) get_post_type() );
			$label            = $listed && isset( $listed->labels->name ) ? (string) $listed->labels->name : __( 'Items', 'zinn-seo' );
			$out['labels']    = array( array( $label, (string) (int) $term->count ) );
		} elseif ( in_array( $c->kind, array( 'date', 'search', 'archive' ), true ) ) {
			// Every other list was an "article" to it too, with the site's Facebook page as
			// publisher (GATE1: lost on date, search, content type and shop archives).
			$out['type']      = 'article';
			$out['publisher'] = self::facebook_page();
		} elseif ( 'author' === $c->kind && is_object( $c->object ) && isset( $c->object->ID ) ) {
			$out['labels'] = array(
				array( __( 'Name', 'zinn-seo' ), (string) $c->object->display_name ),
				array( __( 'Posts', 'zinn-seo' ), (string) count_user_posts( (int) $c->object->ID ) ),
			);
		}
		if ( $post && 'singular' === $c->kind && ! $c->front ) {
			$handle         = (string) get_user_meta( (int) $post->post_author, 'twitter', true );
			$out['creator'] = '' !== self::handle( $handle ) ? self::handle( $handle ) : (string) ( $facts['x_site'] ?? '' );
		}

		return $out;
	}

	/**
	 * A product's `product:*` tags, as Rank Math printed them.
	 *
	 * @param object $product The WooCommerce product.
	 * @param int    $id      Its ID.
	 * @return array<string, string>
	 */
	private static function product_tags( $product, int $id ): array {
		$tags  = array();
		$brand = '';
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_the_terms( $id, 'product_brand' );
			$brand = is_array( $terms ) && isset( $terms[0]->name ) ? (string) $terms[0]->name : '';
		}
		if ( '' !== $brand ) {
			$tags['product:brand'] = $brand;
		}
		if ( ! $product->is_type( 'variable' ) && '' !== (string) $product->get_price() ) {
			$tags['product:price:amount']   = (string) $product->get_price();
			$tags['product:price:currency'] = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		}
		if ( $product->is_in_stock() ) {
			$tags['product:availability'] = 'instock';
		}
		if ( '' !== (string) $product->get_sku() ) {
			$tags['product:retailer_item_id'] = (string) $product->get_sku();
		}

		return $tags;
	}

	/**
	 * Did Rank Math print the "Written by" / "Time to read" lines for this content type? Its
	 * per-type "Slack Enhanced Sharing" switch, kept in its settings after the move; on by
	 * default for posts, pages, products and downloads only (as it installs them).
	 *
	 * @param string $type  Post type.
	 * @param bool   $front The front page.
	 * @return bool
	 */
	public static function slack_sharing( string $type, bool $front = false ): bool {
		if ( $front ) {
			return true;
		}
		$titles = get_option( 'rank-math-options-titles', array() );
		$key    = 'pt_' . $type . '_slack_enhanced_sharing';
		if ( is_array( $titles ) && isset( $titles[ $key ] ) && is_scalar( $titles[ $key ] ) ) {
			return 'on' === (string) $titles[ $key ];
		}

		return in_array( $type, array( 'post', 'page', 'product', 'download' ), true );
	}

	/**
	 * Minutes to read a text at 200 words a minute, rounded down, as Rank Math counts. Pure.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	public static function time_to_read( string $content ): int {
		$words = (int) preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $content ) );

		return (int) floor( $words / 200 );
	}

	/**
	 * An X handle from what a profile holds (`@name`, `name` or an x.com/twitter.com address). Pure.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function handle( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '#^https?://(www\.)?(twitter|x)\.com/([A-Za-z0-9_]{1,15})#i', $value, $m ) ) {
			return $m[3];
		}
		$value = ltrim( $value, '@' );

		return 1 === preg_match( '/^[A-Za-z0-9_]{1,15}$/', $value ) ? $value : '';
	}

	/**
	 * The site's Facebook page (from the identity's profiles), for `article:publisher`.
	 *
	 * @return string
	 */
	private static function facebook_page(): string {
		foreach ( (array) Settings::get( 'identity_same_as', array() ) as $url ) {
			if ( 1 === preg_match( '#^https://([a-z0-9-]+\.)?facebook\.com/#i', (string) $url ) ) {
				return (string) $url;
			}
		}

		return '';
	}
}
