<?php
/**
 * The breadcrumb trail of a page, shared by the graph's BreadcrumbList and the breadcrumbs block.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home → (the type's archive) → the parents or the main category → the page. ONE function, so the
 * trail a visitor sees and the one search engines read are always the same.
 *
 * ⛔ Cost: parents and categories come from WordPress's object cache (a post's terms are primed
 * with the main query); the deepest trail is bounded at 10 levels.
 */
final class Trail {

	/**
	 * The trail: `[ [ 'name' => …, 'url' => … ], … ]`, the last item being the page itself.
	 *
	 * @param Context $c    What the page is about.
	 * @param string  $home The label of the first item.
	 * @return array<int, array{name: string, url: string}>
	 */
	public static function items( Context $c, string $home = '' ): array {
		return self::named( self::build( $c, $home ) );
	}

	/**
	 * Every item with a name, so no trail (visible or in the graph) ever holds a nameless step:
	 * Google rejects a BreadcrumbList whose ListItem has no `name` ("Either 'name' or 'item.name'
	 * should be specified", a critical Search Console error). An empty name (an untitled page or
	 * term, an empty label setting) is taken from the item's address; an item with neither is left
	 * out.
	 *
	 * @param array<int, array<string, mixed>> $items Trail.
	 * @return array<int, array{name: string, url: string}>
	 */
	public static function named( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$url  = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '';
			$name = is_array( $item ) ? trim( (string) ( $item['name'] ?? '' ) ) : '';
			if ( '' === $name ) {
				$name = self::name_from_url( $url );
			}
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name' => $name,
				'url'  => $url,
			);
		}

		return $out;
	}

	/**
	 * A readable name from an address: its last path segment, decoded, with dashes as spaces
	 * (`/store/altijan-data/` → "Altijan data"); the site's root is "Home".
	 *
	 * @param string $url Address.
	 * @return string Empty when the address gives no name.
	 */
	public static function name_from_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return __( 'Home', 'zinn-seo' );
		}
		$parts = explode( '/', $path );
		$name  = trim( (string) preg_replace( '/[\s_-]+/u', ' ', rawurldecode( (string) end( $parts ) ) ) );
		if ( '' === $name ) {
			return '';
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 ) : ucfirst( $name );
	}

	/**
	 * The trail as built, before {@see self::named()}.
	 *
	 * @param Context $c    What the page is about.
	 * @param string  $home The label of the first item.
	 * @return array<int, array{name: string, url: string}>
	 */
	private static function build( Context $c, string $home ): array {
		$own  = Html::plain( (string) Settings::get( 'crumb_home', '' ) );
		$home = '' !== $home ? $home : ( '' !== $own ? $own : __( 'Home', 'zinn-seo' ) );
		if ( '404' === $c->kind ) {
			$label = Html::plain( (string) Settings::get( 'crumb_404', '' ) );
			return '' === $label ? array() : array(
				array(
					'name' => $home,
					'url'  => \ZinnDigital\ZinnSEO\Templates\Resolver::home(),
				),
				array(
					'name' => $label,
					'url'  => '',
				),
			);
		}
		$items = array(
			array(
				'name' => $home,
				'url'  => \ZinnDigital\ZinnSEO\Templates\Resolver::home(),
			),
		);
		if ( $c->front || 'home' === $c->kind ) {
			return $items;
		}
		$post = $c->post();
		$term = $c->term();
		if ( $post instanceof \WP_Post ) {
			$type = get_post_type_object( $post->post_type );
			// The content type's archive before its item (Home › Books › The River Book), unless
			// the site's trail never shows it (SmartCrawl: Home › Mystery › The River Book).
			if ( $type && ! in_array( $post->post_type, array( 'post', 'page' ), true ) && $type->has_archive && self::shows_archive( $post->post_type ) ) {
				$link = get_post_type_archive_link( $post->post_type );
				if ( is_string( $link ) ) {
					$items[] = array(
						'name' => self::archive_name( $type ),
						'url'  => $link,
					);
				}
			}
			if ( 'post' === $post->post_type && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_for_posts' ) > 0 ) {
				$blog = (int) get_option( 'page_for_posts' );
				if ( $blog !== (int) $post->ID ) {
					$items[] = array(
						'name' => Html::plain( get_the_title( $blog ) ),
						'url'  => (string) get_permalink( $blog ),
					);
				}
			}
			if ( is_post_type_hierarchical( $post->post_type ) ) {
				foreach ( array_reverse( array_slice( get_post_ancestors( $post ), 0, 10 ) ) as $parent ) {
					// A parent visitors cannot open (private, draft, trashed, behind a password)
					// is left out: a step that leads to "not found" or a login (zs-758).
					if ( ! self::viewable( (int) $parent ) ) {
						continue;
					}
					$items[] = array(
						'name' => self::post_name( (int) $parent ),
						'url'  => (string) get_permalink( (int) $parent ),
					);
				}
			} else {
				$main = self::main_term( $post );
				if ( $main instanceof \WP_Term ) {
					$items = array_merge( $items, self::term_items( $main, true ) );
				}
			}
			$items[] = array(
				'name' => self::post_name( (int) $post->ID ),
				'url'  => (string) get_permalink( $post ),
			);
		} elseif ( $term instanceof \WP_Term ) {
			// A term of a taxonomy that belongs to one content type with an archive, after that
			// archive, when the site asks (Slim SEO's trail: Home › Books › Mystery).
			if ( in_array( $term->taxonomy, array_map( 'strval', (array) Settings::get( 'crumb_tax_archive', array() ) ), true ) ) {
				$tax   = get_taxonomy( $term->taxonomy );
				$types = $tax ? array_values( (array) $tax->object_type ) : array();
				$type  = 1 === count( $types ) ? get_post_type_object( (string) $types[0] ) : null;
				if ( $type && ! in_array( $type->name, array( 'post', 'page' ), true ) && $type->has_archive ) {
					$link = get_post_type_archive_link( $type->name );
					if ( is_string( $link ) ) {
						$items[] = array(
							'name' => self::archive_name( $type ),
							'url'  => $link,
						);
					}
				}
			}
			// The taxonomy's name as a step of its own, not a link (Rank Math: Home › Genres ›
			// Mystery).
			if ( in_array( $term->taxonomy, array_map( 'strval', (array) Settings::get( 'crumb_tax_name', array() ) ), true ) ) {
				$tax = get_taxonomy( $term->taxonomy );
				if ( $tax && isset( $tax->labels->name ) ) {
					$items[] = array(
						'name' => Html::plain( (string) $tax->labels->name ),
						'url'  => '',
					);
				}
			}
			$items = array_merge( $items, self::term_items( $term, true ) );
			// The term's own archive page may be named with the site's wording ("Archives for
			// News": SmartCrawl).
			$last                   = count( $items ) - 1;
			$items[ $last ]['name'] = self::term_label( $term->taxonomy, $items[ $last ]['name'] );
		} elseif ( 'archive' === $c->kind && $c->object instanceof \WP_Post_Type ) {
			$shop    = 'product' === $c->object->name && function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) > 0;
			$items[] = array(
				'name' => self::ptarchive_label( self::archive_name( $c->object ), $shop ),
				'url'  => (string) get_post_type_archive_link( $c->object->name ),
			);
		} elseif ( 'author' === $c->kind && $c->object instanceof \WP_User ) {
			$items[] = array(
				'name' => self::format( 'crumb_archive', Html::plain( $c->object->display_name ) ),
				'url'  => get_author_posts_url( (int) $c->object->ID ),
			);
		} elseif ( 'search' === $c->kind ) {
			$own     = (string) Settings::get( 'crumb_search', '' );
			$items[] = array(
				/* translators: %s: what was searched for. */
				'name' => '' !== $own ? self::format( 'crumb_search', $c->search ) : sprintf( __( 'Search results for "%s"', 'zinn-seo' ), $c->search ),
				'url'  => get_search_link( $c->search ),
			);
		} elseif ( 'date' === $c->kind && in_array( (string) Settings::get( 'crumb_date', '' ), array( 'tree', 'tree-numbers', 'tree-archive' ), true ) ) {
			$numbers = 'tree-numbers' === (string) Settings::get( 'crumb_date', '' );
			// `tree-archive`: each step in the archive wording (Rank Math: Archives for 2026 ›
			// Archives for March › Archives for 01).
			$name = 'tree-archive' === (string) Settings::get( 'crumb_date', '' ) ? static fn( string $n ): string => self::format( 'crumb_archive', $n ) : static fn( string $n ): string => $n;
			// Year › month › day, each a link (Slim SEO's trail).
			list( $y, $m, $d ) = $c->ymd;
			$time              = (int) mktime( 12, 0, 0, max( 1, (int) $m ), max( 1, (int) $d ), (int) $y );
			$items[]           = array(
				'name' => $name( (string) $y ),
				'url'  => (string) get_year_link( (int) $y ),
			);
			if ( $m > 0 ) {
				$items[] = array(
					'name' => $name( (string) date_i18n( $numbers ? 'm' : 'F', $time ) ),
					'url'  => (string) get_month_link( (int) $y, (int) $m ),
				);
			}
			if ( $d > 0 ) {
				$items[] = array(
					'name' => $name( (string) date_i18n( 'd', $time ) ),
					'url'  => (string) get_day_link( (int) $y, (int) $m, (int) $d ),
				);
			}
		} elseif ( 'date' === $c->kind ) {
			$items[] = array(
				'name' => '' !== (string) Settings::get( 'crumb_archive', '' ) ? self::format( 'crumb_archive', self::date_name( $c ) ) : Html::plain( get_the_archive_title() ),
				'url'  => '',
			);
		}
		// Page 2 and on of a list, as its own last step ("Page 2").
		$paged = (string) Settings::get( 'crumb_paged', '' );
		if ( '' !== $paged && $c->page > 1 && ! ( $post instanceof \WP_Post && 'singular' === $c->kind ) && count( $items ) > 1 ) {
			$last = count( $items ) - 1;
			if ( '' === $items[ $last ]['url'] ) {
				$first                 = clone $c;
				$first->page           = 1;
				$items[ $last ]['url'] = \ZinnDigital\ZinnSEO\Templates\Resolver::address( $first );
			}
			$items[] = array(
				'name' => self::format( 'crumb_paged', (string) $c->page ),
				'url'  => '',
			);
		}

		return array_values( array_filter( $items, static fn( array $i ): bool => '' !== $i['name'] ) );
	}

	/**
	 * Does an item's trail show its content type's archive (Home › Shop › Mug)? The type's own
	 * choice (`crumb_types[type][archive]`: `show` or `hide`), else the site-wide switch (zs-758).
	 *
	 * @param string $post_type Content type.
	 * @return bool
	 */
	public static function shows_archive( string $post_type ): bool {
		$types = (array) Settings::get( 'crumb_types', array() );
		$own   = (string) ( $types[ $post_type ]['archive'] ?? '' );
		if ( 'show' === $own || 'hide' === $own ) {
			return 'show' === $own;
		}

		return (bool) Settings::get( 'crumb_singular_archive', true );
	}

	/**
	 * May a visitor who is not logged in open this post? Published, not behind a password, of a
	 * type and status visitors reach.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function viewable( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || '' !== (string) $post->post_password ) {
			return false;
		}

		return function_exists( 'is_post_publicly_viewable' ) ? is_post_publicly_viewable( $post ) : 'publish' === $post->post_status;
	}

	/**
	 * A setting's wording with the name put in (`%s`, or `%d` for a page number), or the name
	 * after the wording when it has no slot.
	 *
	 * @param string $key  Setting.
	 * @param string $name The name.
	 * @return string
	 */
	public static function format( string $key, string $name ): string {
		$own = Html::plain( (string) Settings::get( $key, '' ) );
		if ( '' === $own ) {
			return $name;
		}

		foreach ( array( '%s', '%d' ) as $slot ) {
			if ( false !== strpos( $own, $slot ) ) {
				return str_replace( $slot, $name, $own );
			}
		}

		return trim( $own . ' ' . $name );
	}

	/**
	 * A term archive's own name in its trail: the site's wording ("Archives for News"), except
	 * for the taxonomies the site names plainly (SmartCrawl: WooCommerce's product categories
	 * and tags read "Mugs", never "Archives for Mugs").
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     The term's name.
	 * @return string
	 */
	public static function term_label( string $taxonomy, string $name ): string {
		$plain = array_map( 'strval', (array) Settings::get( 'crumb_term_plain', array() ) );

		return in_array( $taxonomy, $plain, true ) ? $name : self::format( 'crumb_term', $name );
	}

	/**
	 * The last item of a content type's archive trail. WooCommerce's shop is a page the site
	 * named: it shows as that page, never as "Archives for Shop" (All in One SEO, queue Q254).
	 *
	 * @param string $name    The archive's name.
	 * @param bool   $is_shop Whether it is WooCommerce's shop page.
	 * @return string
	 */
	public static function ptarchive_label( string $name, bool $is_shop ): string {
		return $is_shop ? $name : self::format( 'crumb_ptarchive', $name );
	}

	/**
	 * A content type's archive in a trail: its own breadcrumb name, else WooCommerce's shop
	 * page for products (the name visitors see), else the type's name.
	 *
	 * @param \WP_Post_Type $type Content type.
	 * @return string
	 */
	public static function archive_name( \WP_Post_Type $type ): string {
		$types = (array) Settings::get( 'crumb_types', array() );
		$own   = Html::plain( (string) ( $types[ $type->name ]['label'] ?? '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		if ( 'product' === $type->name && function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) > 0 ) {
			$shop = Html::plain( get_the_title( (int) wc_get_page_id( 'shop' ) ) );
			if ( '' !== $shop ) {
				return $shop;
			}
		}

		return Html::plain( $type->labels->name ?? $type->label );
	}

	/**
	 * A date archive's plain name: "2026", "March 2026", "March 1, 2026" (the site's formats).
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function date_name( Context $c ): string {
		list( $y, $m, $d ) = $c->ymd;
		$time              = (int) mktime( 12, 0, 0, max( 1, (int) $m ), max( 1, (int) $d ), (int) $y );
		if ( $d > 0 ) {
			return (string) date_i18n( (string) get_option( 'date_format', 'F j, Y' ), $time );
		}
		if ( $m > 0 ) {
			return (string) date_i18n( 'F Y', $time );
		}

		return (string) $y;
	}

	/**
	 * A post's name in a trail: its breadcrumb name (`_zseo_crumb`), else its title.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function post_name( int $post_id ): string {
		$own = Html::plain( (string) get_post_meta( $post_id, '_zseo_crumb', true ) );

		return '' !== $own ? $own : Html::plain( get_the_title( $post_id ) );
	}

	/**
	 * A term's name in a trail: its breadcrumb name, else its name.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	public static function term_name( \WP_Term $term ): string {
		$own = Html::plain( (string) get_term_meta( (int) $term->term_id, '_zseo_crumb', true ) );

		return '' !== $own ? $own : Html::plain( $term->name );
	}

	/**
	 * A post's main term: its primary category (the SEO field `primary_term`), else the first
	 * category, else the first term of its first public hierarchical taxonomy.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Term|null
	 */
	public static function main_term( \WP_Post $post ): ?\WP_Term {
		$types = (array) Settings::get( 'crumb_types', array() );
		$tax   = (string) ( $types[ $post->post_type ]['tax'] ?? '' );
		if ( 'none' === $tax ) {
			return null;
		}
		if ( '' !== $tax && taxonomy_exists( $tax ) ) {
			$terms = get_the_terms( $post, $tax );
			$own   = (int) get_post_meta( $post->ID, '_zseo_primary_term', true );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( (int) $term->term_id === $own ) {
					return $term;
				}
			}
			return is_array( $terms ) && array() !== $terms ? $terms[0] : null;
		}
		$primary = (int) get_post_meta( $post->ID, '_zseo_primary_term', true );
		if ( $primary > 0 ) {
			$term = get_term( $primary );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		// A content type's categories before its other hierarchical taxonomies: a product's trail
		// runs through its category, not its brand (found with WooCommerce's own Brands, which
		// is registered first).
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		uasort( $taxonomies, static fn( $a, $b ): int => (int) ( 1 !== preg_match( '/(^|[_-])(cat|category|categories)$/', (string) $a->name ) ) <=> (int) ( 1 !== preg_match( '/(^|[_-])(cat|category|categories)$/', (string) $b->name ) ) );
		foreach ( $taxonomies as $tax ) {
			if ( ! $tax->public || ! $tax->hierarchical ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( is_array( $terms ) && array() !== $terms ) {
				return $terms[0];
			}
		}

		return null;
	}

	/**
	 * A term and its parents, top first.
	 *
	 * @param \WP_Term $term     Term.
	 * @param bool     $with_own Include the term itself.
	 * @return array<int, array{name: string, url: string}>
	 */
	private static function term_items( \WP_Term $term, bool $with_own ): array {
		$out = array();
		// Rank Math's "Show category(s) ancestors" off: the term alone (Home › Local, not
		// Home › News › Local).
		$parents = (bool) Settings::get( 'crumb_ancestors', true ) ? get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) : array();
		foreach ( array_reverse( array_slice( $parents, 0, 10 ) ) as $parent ) {
			$p = get_term( (int) $parent, $term->taxonomy );
			if ( $p instanceof \WP_Term ) {
				$link  = get_term_link( $p );
				$out[] = array(
					'name' => self::term_name( $p ),
					'url'  => is_string( $link ) ? $link : '',
				);
			}
		}
		if ( $with_own ) {
			$link  = get_term_link( $term );
			$out[] = array(
				'name' => self::term_name( $term ),
				'url'  => is_string( $link ) ? $link : '',
			);
		}

		return $out;
	}

	/**
	 * The BreadcrumbList node for a trail.
	 *
	 * @param array<int, array{name: string, url: string}> $items Trail.
	 * @param string                                       $id    `@id`.
	 * @return array<string, mixed>
	 */
	public static function node( array $items, string $id ): array {
		$items = self::named( $items );
		$list  = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
			);
			if ( '' !== $item['url'] && $i < count( $items ) - 1 ) {
				$entry['item'] = $item['url'];
			}
			$list[] = $entry;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $id,
			'itemListElement' => $list,
		);
	}
}
