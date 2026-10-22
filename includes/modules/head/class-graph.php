<?php
/**
 * The page's ONE JSON-LD graph (architecture §1.4: exactly one `application/ld+json` per page).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Head;

use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every node any part of the plugin knows about the page goes into one `@graph`, joined by `@id`
 * references, and is printed once. This stage contributes the two nodes every page has — the
 * WebSite and the page itself, with its dates and author (the signals Google Discover reads,
 * zs-415) — and the schema stage adds the rest through `zinn_seo_graph`.
 *
 * ⭐ The `@id` fragments are neutral (`#website`, `#webpage`): nothing in the page names the
 * plugin, in either no-trace setting.
 */
final class Graph {

	/**
	 * The nodes of a page.
	 *
	 * @param Context $c Context.
	 * @return array<int, array<string, mixed>>
	 */
	public static function nodes( Context $c ): array {
		if ( '404' === $c->kind ) {
			return array();
		}
		$home    = home_url( '/' );
		$nodes   = array(
			'website' => array(
				'@type'      => 'WebSite',
				'@id'        => $home . '#website',
				'url'        => Resolver::home(),
				'name'       => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'inLanguage' => self::language(),
			),
		);
		$tagline = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$nodes['website']['description'] = $tagline;
		}
		$url = Resolver::canonical( $c );
		if ( '' === $url ) {
			$url = Resolver::address( $c );
		}
		if ( '' !== $url ) {
			$page = array(
				'@type'      => self::page_type( $c ),
				'@id'        => $url . '#webpage',
				'url'        => $url,
				'name'       => Resolver::title( $c ),
				'isPartOf'   => array( '@id' => $home . '#website' ),
				'inLanguage' => self::language(),
			);
			$desc = Resolver::description( $c );
			if ( '' !== $desc ) {
				$page['description'] = $desc;
			}
			$post = $c->post();
			if ( $post && ! $c->front ) {
				// zs-415: the dates and the author Google Discover and AI answers read.
				$page['datePublished'] = (string) get_post_time( 'c', true, $post );
				$page['dateModified']  = (string) get_post_modified_time( 'c', true, $post );
				if ( post_type_supports( $post->post_type, 'author' ) && (int) $post->post_author > 0 ) {
					$name = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
					if ( '' !== $name ) {
						$page['author'] = array(
							'@type' => 'Person',
							'name'  => $name,
							'url'   => get_author_posts_url( (int) $post->post_author ),
						);
					}
				}
			}
			$nodes['webpage'] = $page;
		}

		/**
		 * Filters the page's JSON-LD nodes (one graph per page). Keys are local names; values
		 * are schema.org nodes with an `@id` when anything refers to them.
		 *
		 * @param array<string, array<string, mixed>> $nodes Nodes.
		 * @param Context                             $c     What the page is about.
		 */
		$nodes = (array) apply_filters( 'zinn_seo_graph', $nodes, $c );

		return array_values( array_filter( $nodes, 'is_array' ) );
	}

	/**
	 * The script tag, or '' when there is nothing to say.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function render( Context $c ): string {
		$nodes = self::nodes( $c );
		if ( array() === $nodes ) {
			return '';
		}
		return self::script(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			)
		);
	}

	/**
	 * A JSON-LD script tag.
	 *
	 * WordPress's own tag builder (WP 5.7+), so a Content-Security-Policy plugin can add its
	 * nonce through `wp_inline_script_attributes` and no tag is hand-printed. On a theme that
	 * does not declare HTML5 script support, WordPress before 6.5 wraps EVERY inline script in a
	 * CDATA comment — also one that is data, not JavaScript — and a JSON-LD document that begins
	 * with a comment is not JSON: that wrapper is taken off again.
	 *
	 * The JSON is encoded HERE, at the sink, with `<`, `>` and `&` escaped (JSON_HEX_TAG |
	 * JSON_HEX_AMP) and none of WordPress's escaping switched off: WordPress.org refuses a script
	 * tag built from a variable, and a printed wp_json_encode() with JSON_UNESCAPED_* (review
	 * P0TDX382477HGN, Q200). Slashes and non-ASCII letters come out escaped (`\/`, `\u00e9`);
	 * every JSON reader, Google's included, reads them back as the same text.
	 *
	 * @param array<string, mixed> $data The document.
	 * @return string
	 */
	public static function script( array $data ): string {
		$tag = trim( wp_get_inline_script_tag( (string) wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ), array( 'type' => 'application/ld+json' ) ) );
		if ( false !== strpos( $tag, '<![CDATA[' ) ) {
			$tag = (string) preg_replace( array( '#(<script\b[^>]*>)\s*/\*\s*<!\[CDATA\[\s*\*/\s*#', '#\s*/\*\s*\]\]>\s*\*/\s*(</script>)$#' ), array( '$1' . "\n", "\n" . '$1' ), $tag );
		}

		return $tag;
	}

	/**
	 * The schema.org type of the page node.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function page_type( Context $c ): string {
		switch ( $c->kind ) {
			case 'search':
				return 'SearchResultsPage';
			case 'author':
				return 'ProfilePage';
			case 'tax':
			case 'archive':
			case 'date':
				return 'CollectionPage';
			case 'home':
				return 'CollectionPage';
		}

		return 'WebPage';
	}

	/**
	 * The page language as a BCP 47 tag (en-GB, ar).
	 *
	 * @return string
	 */
	public static function language(): string {
		return str_replace( '_', '-', (string) get_bloginfo( 'language' ) );
	}
}
