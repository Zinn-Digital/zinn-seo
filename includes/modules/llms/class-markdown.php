<?php
/**
 * HTML to Markdown, for llms-full.txt and the Markdown version of each page (zs-061, zs-065).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Llms;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the property names of PHP's own DOM classes.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small converter for the HTML a post's content renders to: headings, paragraphs, emphasis,
 * links, images, lists (nested), quotes, code, tables, rules. Scripts, styles, forms, embedded
 * frames and SVG are dropped: an agent wants the text. Pure (PHP's DOM extension only), so it is
 * tested without WordPress; without the DOM extension the text is returned with tags stripped.
 */
final class Markdown {

	/** Elements whose content is dropped. */
	private const DROP = array( 'script', 'style', 'noscript', 'template', 'iframe', 'svg', 'form', 'button', 'select', 'input', 'textarea', 'canvas', 'object', 'embed', 'audio', 'video', 'map' );

	/**
	 * Convert.
	 *
	 * @param string $html HTML fragment.
	 * @param string $base Base address for relative links.
	 * @return string Markdown.
	 */
	public static function from_html( string $html, string $base = '' ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			return trim( (string) preg_replace( '/\n{3,}/', "\n\n", html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure fallback without WordPress loaded.
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"?><div id="zseo-md-root">' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementById( 'zseo-md-root' );
		if ( null === $root ) {
			return '';
		}
		$out = self::children( $root, $base, 0 );
		$out = (string) preg_replace( "/[ \t]+\n/", "\n", $out );
		$out = (string) preg_replace( "/\n{3,}/", "\n\n", $out );

		return trim( $out );
	}

	/**
	 * Convert a node's children.
	 *
	 * @param \DOMNode $node  Node.
	 * @param string   $base  Base address.
	 * @param int      $depth List depth.
	 * @return string
	 */
	private static function children( \DOMNode $node, string $base, int $depth ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node( $child, $base, $depth );
		}

		return $out;
	}

	/**
	 * Convert one node.
	 *
	 * @param \DOMNode $node  Node.
	 * @param string   $base  Base address.
	 * @param int      $depth List depth.
	 * @return string
	 */
	private static function node( \DOMNode $node, string $base, int $depth ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = (string) preg_replace( '/\s+/u', ' ', (string) $node->nodeValue );
			return self::escape( $text );
		}
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}
		$tag = strtolower( $node->tagName );
		if ( in_array( $tag, self::DROP, true ) ) {
			return '';
		}
		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = trim( self::inline( $node, $base ) );
				return '' === $text ? '' : "\n\n" . str_repeat( '#', (int) $tag[1] ) . ' ' . $text . "\n\n";
			case 'p':
				$text = trim( self::children( $node, $base, $depth ) );
				return '' === $text ? '' : "\n\n" . $text . "\n\n";
			case 'br':
				return "  \n";
			case 'hr':
				return "\n\n---\n\n";
			case 'strong':
			case 'b':
				$text = trim( self::children( $node, $base, $depth ) );
				return '' === $text ? '' : '**' . $text . '**';
			case 'em':
			case 'i':
				$text = trim( self::children( $node, $base, $depth ) );
				return '' === $text ? '' : '*' . $text . '*';
			case 'code':
				if ( $node->parentNode instanceof \DOMElement && 'pre' === strtolower( $node->parentNode->tagName ) ) {
					return (string) $node->textContent;
				}
				return '`' . str_replace( '`', '\`', (string) $node->textContent ) . '`';
			case 'pre':
				return "\n\n```\n" . rtrim( (string) $node->textContent ) . "\n```\n\n";
			case 'a':
				$text = trim( self::children( $node, $base, $depth ) );
				$href = self::absolute( (string) $node->getAttribute( 'href' ), $base );
				if ( '' === $href || 0 === strpos( $href, 'javascript:' ) ) {
					return $text;
				}
				return '' === $text ? '<' . $href . '>' : '[' . $text . '](' . self::link( $href ) . ')';
			case 'img':
				$src = self::absolute( (string) $node->getAttribute( 'src' ), $base );
				if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
					return '';
				}
				return '![' . self::escape( trim( (string) $node->getAttribute( 'alt' ) ) ) . '](' . self::link( $src ) . ')';
			case 'blockquote':
				$text = trim( self::children( $node, $base, $depth ) );
				return '' === $text ? '' : "\n\n" . implode( "\n", array_map( static fn( string $l ): string => '' === $l ? '>' : '> ' . $l, explode( "\n", $text ) ) ) . "\n\n";
			case 'ul':
			case 'ol':
				return "\n\n" . self::list_items( $node, $base, $depth, 'ol' === $tag ) . "\n\n";
			case 'table':
				return "\n\n" . self::table( $node, $base ) . "\n\n";
			case 'figcaption':
				$text = trim( self::children( $node, $base, $depth ) );
				return '' === $text ? '' : "\n\n*" . $text . "*\n\n";
			case 'div':
			case 'section':
			case 'article':
			case 'figure':
			case 'header':
			case 'footer':
			case 'main':
			case 'aside':
			case 'details':
			case 'summary':
				return "\n\n" . self::children( $node, $base, $depth ) . "\n\n";
		}

		return self::children( $node, $base, $depth );
	}

	/**
	 * A heading's or cell's text: inline Markdown on one line.
	 *
	 * @param \DOMElement $node Node.
	 * @param string      $base Base address.
	 * @return string
	 */
	private static function inline( \DOMElement $node, string $base ): string {
		return trim( (string) preg_replace( '/\s*\n+\s*/', ' ', self::children( $node, $base, 0 ) ) );
	}

	/**
	 * A list.
	 *
	 * @param \DOMElement $element The list.
	 * @param string      $base    Base address.
	 * @param int         $depth   Nesting depth.
	 * @param bool        $ordered Numbered.
	 * @return string
	 */
	private static function list_items( \DOMElement $element, string $base, int $depth, bool $ordered ): string {
		$lines = array();
		$n     = 0;
		foreach ( $element->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}
			++$n;
			$marker  = $ordered ? $n . '.' : '-';
			$text    = trim( (string) preg_replace( "/\n{2,}/", "\n", self::children( $child, $base, $depth + 1 ) ) );
			$indent  = str_repeat( '  ', $depth );
			$parts   = explode( "\n", $text );
			$first   = array_shift( $parts );
			$lines[] = $indent . $marker . ' ' . trim( (string) $first );
			foreach ( $parts as $part ) {
				if ( '' !== trim( $part ) ) {
					$lines[] = ( 1 === preg_match( '/^\s*(-|\d+\.)\s/', $part ) ? '' : $indent . '  ' ) . rtrim( $part );
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * A table as a GitHub-style Markdown table (first row is the header).
	 *
	 * @param \DOMElement $table Table.
	 * @param string      $base  Base address.
	 * @return string
	 */
	private static function table( \DOMElement $table, string $base ): string {
		$rows = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
					$cells[] = str_replace( '|', '\|', self::inline( $cell, $base ) );
				}
			}
			if ( array() !== $cells ) {
				$rows[] = $cells;
			}
		}
		if ( array() === $rows ) {
			return '';
		}
		$width = max( array_map( 'count', $rows ) );
		$out   = array();
		foreach ( $rows as $i => $cells ) {
			$cells = array_pad( $cells, $width, '' );
			$out[] = '| ' . implode( ' | ', $cells ) . ' |';
			if ( 0 === $i ) {
				$out[] = '|' . str_repeat( ' --- |', $width );
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Escape the characters that would start Markdown syntax in running text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( string $text ): string {
		return (string) preg_replace( '/([\\\\`*_\[\]])/', '\\\\$1', $text );
	}

	/**
	 * An address safe inside `( )`.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function link( string $url ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url );
	}

	/**
	 * Make a relative address absolute.
	 *
	 * @param string $url  Address.
	 * @param string $base Base (`https://example.com`).
	 * @return string
	 */
	private static function absolute( string $url, string $base ): string {
		$url = trim( $url );
		if ( '' === $url || 1 === preg_match( '#^([a-z][a-z0-9+.-]*:|//|\#)#i', $url ) || '' === $base ) {
			return 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
		}

		return rtrim( $base, '/' ) . '/' . ltrim( $url, '/' );
	}
}
