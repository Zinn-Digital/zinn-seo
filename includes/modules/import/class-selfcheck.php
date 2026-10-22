<?php
/**
 * The key pages and files read before and after the switch-off (queue Q263, point 4).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ What the page-by-page comparison does not read, read here: robots.txt, the sitemap index,
 * llms.txt, the language links (hreflang) of every language's home page, and a product's
 * structured data. These are what a move from Rank Math lost on zinnhub (2026-10-14: robots.txt
 * rules incl. the AI opt-outs, Product/Offer schema on 126 products, llms.txt).
 *
 * The same addresses are read twice: just before the other plugin is switched off (it is
 * serving them) and just after (this plugin is). Only a LOSS fails — a rule gone from robots.txt,
 * a language link gone, a product's structured data gone from a product's own page, the sitemap
 * index emptied, a page or file that answered and no longer does. Title, description, canonical
 * and robots are the comparison's business (it read every page and holds the person's accepted
 * differences), and so is checked there, not twice.
 *
 * Any loss switches the other plugin straight back on and undoes the move (Switcher).
 */
final class SelfCheck {

	/** Non-autoloaded: the readings taken before the switch-off. */
	public const BEFORE = 'zseo_import_selfcheck';

	/** Language home pages read at most (a site has one per language). */
	public const LANGUAGES = 80;

	/**
	 * The addresses to read: home, robots.txt, the sitemap index (both usual addresses), llms.txt,
	 * the newest post, page and product, the fullest category and product category.
	 *
	 * @return array<int, array{key: string, url: string, kind: string}>
	 */
	public static function targets(): array {
		$out   = array(
			array(
				'key'  => 'home',
				'url'  => home_url( '/' ),
				'kind' => 'page',
			),
			array(
				'key'  => 'robots',
				'url'  => home_url( '/robots.txt' ),
				'kind' => 'robots',
			),
			array(
				'key'  => 'sitemap_index',
				'url'  => home_url( '/sitemap_index.xml' ),
				'kind' => 'sitemap',
			),
			array(
				'key'  => 'sitemap',
				'url'  => home_url( '/sitemap.xml' ),
				'kind' => 'sitemap',
			),
			array(
				'key'  => 'llms',
				'url'  => home_url( '/llms.txt' ),
				'kind' => 'file',
			),
		);
		$front = (int) get_option( 'page_on_front' );
		foreach ( array( 'post', 'page', 'product' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			// The newest two, so the front page can be passed over without an exclusion query
			// (Plugin Check refuses `exclude` on get_posts()).
			$newest = get_posts(
				array(
					'numberposts'      => 2,
					'post_type'        => $type,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- the newest published one, as stored.
					'suppress_filters' => true,
				)
			);
			$newest = array_values( array_diff( array_map( 'intval', $newest ), array( $front ) ) );
			if ( isset( $newest[0] ) ) {
				$link = get_permalink( (int) $newest[0] );
				if ( is_string( $link ) && '' !== $link ) {
					$out[] = array(
						'key'  => $type,
						'url'  => $link,
						'kind' => 'page',
					);
				}
			}
		}
		foreach ( array( 'category', 'product_cat' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'hide_empty' => true,
				)
			);
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				$link = get_term_link( $terms[0] );
				if ( is_string( $link ) ) {
					$out[] = array(
						'key'  => $taxonomy,
						'url'  => $link,
						'kind' => 'page',
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Fetch an address, following up to two redirects for a file (a sitemap address may answer
	 * with a redirect to the other one — that is fine) and none for a page.
	 *
	 * @param string $url  Address.
	 * @param string $kind page|robots|sitemap|file.
	 * @return array{code: int, html: string, error: string, location: string}
	 */
	public static function fetch( string $url, string $kind ): array {
		$got = Check::fetch( $url );
		$hop = 0;
		while ( 'page' !== $kind && $hop < 2 && in_array( $got['code'], array( 301, 302, 307, 308 ), true ) && '' !== $got['location'] ) {
			$next = $got['location'];
			if ( 0 === strpos( $next, '/' ) ) {
				$next = home_url( $next );
			}
			// Only to this site.
			if ( wp_parse_url( $next, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
				break;
			}
			$got = Check::fetch( $next );
			++$hop;
		}

		return $got;
	}

	/**
	 * Read one address into what this check compares.
	 *
	 * @param array{code: int, html: string, error: string, location: string} $got  Response.
	 * @param string                                                          $kind page|robots|sitemap|file.
	 * @return array<string, mixed>
	 */
	public static function read( array $got, string $kind ): array {
		$out = array( 'code' => (int) $got['code'] );
		if ( 200 !== $out['code'] ) {
			$out['location'] = (string) $got['location'];
			return $out;
		}
		$body = (string) $got['html'];
		if ( 'page' === $kind ) {
			$sig                = Signature::from_html( $body );
			$out['title']       = (string) $sig['title'];
			$out['description'] = (string) $sig['description'];
			$out['canonical']   = (string) $sig['canonical'];
			$out['robots']      = (array) $sig['robots'];
			$out['hreflang']    = self::hreflang( $body );
			$product            = self::product( $body );
			$out['product']     = $product['product'];
			$out['offers']      = $product['offers'];
		} elseif ( 'robots' === $kind ) {
			$out['rules']    = self::rules( $body );
			$out['sitemaps'] = preg_match_all( '/^\s*sitemap\s*:/mi', $body );
		} elseif ( 'sitemap' === $kind ) {
			$out['xml']      = 1 === preg_match( '/<(sitemapindex|urlset)\b/i', $body );
			$out['children'] = substr_count( strtolower( $body ), '<loc>' );
		}

		return $out;
	}

	/**
	 * The language links of a page: hreflang => address.
	 *
	 * @param string $html Document.
	 * @return array<string, string> Sorted by code.
	 */
	public static function hreflang( string $html ): array {
		$end  = stripos( $html, '</head>' );
		$head = false === $end ? $html : substr( $html, 0, $end );
		$out  = array();
		if ( preg_match_all( '#<link\b[^>]*>#i', $head, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$a = Signature::attributes( $tag );
				if ( 'alternate' === strtolower( (string) ( $a['rel'] ?? '' ) ) && '' !== (string) ( $a['hreflang'] ?? '' ) ) {
					$out[ strtolower( (string) $a['hreflang'] ) ] = trim( (string) ( $a['href'] ?? '' ) );
				}
			}
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Does a page's structured data describe a product, with an offer?
	 *
	 * @param string $html Document.
	 * @return array{product: bool, offers: bool}
	 */
	public static function product( string $html ): array {
		$out = array(
			'product' => false,
			'offers'  => false,
		);
		if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				self::walk( json_decode( trim( $json ), true ), $out, 0 );
			}
		}

		return $out;
	}

	/**
	 * Walk a JSON-LD document for Product nodes.
	 *
	 * @param mixed                              $doc   Decoded.
	 * @param array{product: bool, offers: bool} $out   Found.
	 * @param int                                $depth Guard.
	 * @return void
	 */
	private static function walk( $doc, array &$out, int $depth ): void {
		if ( ! is_array( $doc ) || $depth > 12 ) {
			return;
		}
		$types = array_map( 'strval', (array) ( $doc['@type'] ?? array() ) );
		if ( array() !== array_intersect( $types, array( 'Product', 'ProductGroup' ) ) ) {
			$out['product'] = true;
			if ( ! empty( $doc['offers'] ) ) {
				$out['offers'] = true;
			}
		}
		foreach ( $doc as $value ) {
			if ( is_array( $value ) ) {
				self::walk( $value, $out, $depth + 1 );
			}
		}
	}

	/**
	 * The rules of a robots.txt, one entry per crawler and rule: `agent|allow|/path`.
	 *
	 * @param string $text The file.
	 * @return array<int, string> Sorted, unique.
	 */
	public static function rules( string $text ): array {
		$out    = array();
		$agents = array();
		$open   = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$name                 = strtolower( $name );
			if ( 'user-agent' === $name ) {
				if ( ! $open ) {
					$agents = array();
				}
				$agents[] = strtolower( $value );
				$open     = true;
				continue;
			}
			$open = false;
			if ( in_array( $name, array( 'allow', 'disallow' ), true ) && '' !== $value ) {
				foreach ( $agents as $agent ) {
					$out[ $agent . '|' . $name . '|' . $value ] = true;
				}
			}
		}
		$out = array_keys( $out );
		sort( $out );

		return $out;
	}

	/**
	 * Read every target as it is now. Language home pages found on the home page are added
	 * (once, from the first reading).
	 *
	 * @param array<int, array{key: string, url: string, kind: string}> $targets Targets.
	 * @return array<string, array<string, mixed>> key => [url, kind, reading].
	 */
	public static function capture( array $targets ): array {
		$out = array();
		foreach ( $targets as $t ) {
			$reading          = self::read( self::fetch( $t['url'], $t['kind'] ), $t['kind'] );
			$out[ $t['key'] ] = array(
				'url'  => $t['url'],
				'kind' => $t['kind'],
				'got'  => $reading,
			);
		}

		return $out;
	}

	/**
	 * Language home pages listed on the home page (same host only).
	 *
	 * @param array<string, mixed> $home The home page's reading.
	 * @return array<int, array{key: string, url: string, kind: string}>
	 */
	public static function language_targets( array $home ): array {
		$out  = array();
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$self = untrailingslashit( home_url( '/' ) );
		foreach ( (array) ( $home['hreflang'] ?? array() ) as $code => $url ) {
			if ( count( $out ) >= self::LANGUAGES ) {
				break;
			}
			$url = (string) $url;
			if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host || untrailingslashit( $url ) === $self ) {
				continue;
			}
			$out[ $url ] = array(
				'key'  => 'lang:' . $code,
				'url'  => $url,
				'kind' => 'page',
			);
		}

		return array_values( $out );
	}

	/**
	 * Everything that was there before and is gone after.
	 *
	 * @param array<string, array<string, mixed>> $before Readings before the switch-off.
	 * @param array<string, array<string, mixed>> $after  Readings after it.
	 * @return array<int, array{url: string, fields: array<string, array{0: mixed, 1: mixed}>}> Failures, in the shape of a rollback's list.
	 */
	public static function losses( array $before, array $after ): array {
		$out = array();
		foreach ( $before as $key => $was ) {
			$b = (array) $was['got'];
			if ( 200 !== (int) ( $b['code'] ?? 0 ) ) {
				// It did not answer before either: nothing to lose.
				continue;
			}
			$a      = (array) ( $after[ $key ]['got'] ?? array( 'code' => 0 ) );
			$fields = array();
			if ( 200 !== (int) ( $a['code'] ?? 0 ) ) {
				$fields['page'] = array( '200', 'HTTP ' . (int) ( $a['code'] ?? 0 ) );
			} elseif ( 'page' === $was['kind'] ) {
				// Title, description, canonical and robots are the comparison's (Check): it read
				// every page, and a person may have accepted a difference there (a WooCommerce
				// account page kept out of search, say). Measured 2026-10-15: checking them here
				// rolled back seven certified moves on exactly such accepted differences.
				$lost = array_diff( array_keys( (array) ( $b['hreflang'] ?? array() ) ), array_keys( (array) ( $a['hreflang'] ?? array() ) ) );
				if ( array() !== $lost ) {
					$fields['hreflang'] = array( array_values( $lost ), array_keys( (array) ( $a['hreflang'] ?? array() ) ) );
				}
				// A product is checked on a single page only: a category page listing its products as
				// full Products is what Google's guidelines ask not to do (certified, doc 1028), so
				// not printing that there is no loss.
				$single = in_array( (string) $key, array( 'post', 'page', 'product' ), true );
				if ( $single && ! empty( $b['product'] ) && empty( $a['product'] ) ) {
					$fields['product'] = array( 'Product', '' );
				} elseif ( $single && ! empty( $b['offers'] ) && empty( $a['offers'] ) ) {
					$fields['product'] = array( 'Product + Offer', 'Product' );
				}
			} elseif ( 'robots' === $was['kind'] ) {
				$lost = array_values( array_diff( (array) ( $b['rules'] ?? array() ), (array) ( $a['rules'] ?? array() ) ) );
				if ( array() !== $lost ) {
					$fields['robots_txt'] = array( $lost, array() );
				}
				if ( (int) ( $b['sitemaps'] ?? 0 ) > 0 && 0 === (int) ( $a['sitemaps'] ?? 0 ) ) {
					$fields['robots_sitemap'] = array( 'Sitemap', '' );
				}
			} elseif ( 'sitemap' === $was['kind'] ) {
				if ( ! empty( $b['xml'] ) && empty( $a['xml'] ) ) {
					$fields['sitemap'] = array( 'XML', '' );
				} elseif ( (int) ( $b['children'] ?? 0 ) > 0 && 0 === (int) ( $a['children'] ?? 0 ) ) {
					$fields['sitemap'] = array( (string) (int) $b['children'], '0' );
				}
			}
			if ( array() !== $fields ) {
				$out[] = array(
					'url'    => (string) $was['url'],
					'fields' => $fields,
				);
			}
		}

		return $out;
	}
}
