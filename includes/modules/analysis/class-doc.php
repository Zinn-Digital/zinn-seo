<?php
/**
 * A rendered page, read the way a visitor and a search engine read it (zs-002).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure: HTML in, structure out — paragraphs (in reading order, headings between them), headings,
 * images, links, lists, tables and frames. Every check reads THIS, never the saved markup, so a
 * block binding, a query loop, a shortcode or an Elementor widget is analysed as what it shows.
 *
 * Builders wrap text in `<div>`s as often as in `<p>`s, so a "paragraph" is any run of inline
 * text between two block-level boundaries, not only a `<p>`. Code, scripts, styles, forms and
 * hidden elements are not prose and are skipped.
 */
final class Doc {

	/** Elements whose start or end ends a run of text. */
	private const BLOCK = array( 'address', 'article', 'aside', 'blockquote', 'details', 'dialog', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'header', 'hgroup', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul', 'br' );

	/** Elements that are never prose. */
	private const SKIP = array( 'script', 'style', 'noscript', 'template', 'svg', 'math', 'code', 'pre', 'kbd', 'samp', 'select', 'textarea', 'button', 'input', 'iframe', 'object', 'canvas', 'video', 'audio', 'head' );

	/**
	 * Text blocks in order: `type` (p|h|li|quote|cell|caption), `text`, and `level` for headings.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $blocks = array();

	/**
	 * Headings: `level` (1-6), `text`, `id`, `block` (its index in $blocks).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $headings = array();

	/**
	 * Images: `src`, `alt` (null when the attribute is missing), `linked` (inside a link), `role`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $images = array();

	/**
	 * Links: `href`, `text`, `label` (aria-label or title), `rel` (list), `target`, `image_alt`
	 * (alt of an image inside, '' for an image without one, null when no image), `in_heading`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $links = array();

	/**
	 * Lists in the page.
	 *
	 * @var int
	 */
	public int $lists = 0;

	/**
	 * List items in the page.
	 *
	 * @var int
	 */
	public int $items = 0;

	/**
	 * Tables in the page.
	 *
	 * @var int
	 */
	public int $tables = 0;

	/**
	 * Tables with no header cell.
	 *
	 * @var int
	 */
	public int $tables_without_headers = 0;

	/**
	 * Frames: `src`, `title`.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $frames = array();

	/**
	 * Block quotes and `<cite>` elements (sources quoted).
	 *
	 * @var int
	 */
	public int $quotes = 0;

	/**
	 * `<time>` elements and machine-readable dates.
	 *
	 * @var int
	 */
	public int $times = 0;

	/**
	 * The current run of text while walking.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Type of the block the current run belongs to.
	 *
	 * @var string
	 */
	private string $buffer_type = 'p';

	/**
	 * Read a rendered page.
	 *
	 * @param string $html Rendered HTML (a fragment or a whole page body).
	 * @return Doc
	 */
	public static function parse( string $html ): Doc {
		$doc = new self();
		if ( '' === trim( $html ) ) {
			return $doc;
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			// Hosts without PHP's DOM extension still get the words, sentences and paragraphs.
			$doc->fallback( $html );
			return $doc;
		}
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( $body instanceof \DOMNode ) {
			$doc->walk( $body, array() );
			$doc->flush();
		}

		return $doc;
	}

	/**
	 * Walk a node.
	 *
	 * @param \DOMNode             $node  Node.
	 * @param array<string, mixed> $state `link` (index of the open link), `heading` (bool).
	 * @return void
	 */
	private function walk( \DOMNode $node, array $state ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own property names.
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$text          = (string) $child->nodeValue;
				$this->buffer .= $text;
				if ( isset( $state['link'] ) ) {
					$this->links[ $state['link'] ]['text'] .= $text;
				}
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( $this->hidden( $child ) ) {
				continue;
			}
			if ( 'iframe' === $tag ) {
				$this->frames[] = array(
					'src'   => (string) $child->getAttribute( 'src' ),
					'title' => trim( (string) $child->getAttribute( 'title' ) ),
				);
				continue;
			}
			if ( in_array( $tag, self::SKIP, true ) ) {
				// Code is still text a reader sees between words; keep a space so words do not join.
				$this->buffer .= ' ';
				continue;
			}
			if ( 'img' === $tag ) {
				$alt            = $child->hasAttribute( 'alt' ) ? trim( (string) $child->getAttribute( 'alt' ) ) : null;
				$this->images[] = array(
					'src'    => (string) ( $child->getAttribute( 'src' ) ? $child->getAttribute( 'src' ) : $child->getAttribute( 'data-src' ) ),
					'alt'    => $alt,
					'linked' => isset( $state['link'] ),
					'role'   => (string) $child->getAttribute( 'role' ),
				);
				if ( isset( $state['link'] ) && null === $this->links[ $state['link'] ]['image_alt'] ) {
					$this->links[ $state['link'] ]['image_alt'] = (string) $alt;
				}
				continue;
			}
			if ( 'a' === $tag && $child->hasAttribute( 'href' ) ) {
				$label         = trim( (string) ( $child->getAttribute( 'aria-label' ) ? $child->getAttribute( 'aria-label' ) : $child->getAttribute( 'title' ) ) );
				$this->links[] = array(
					'href'       => trim( (string) $child->getAttribute( 'href' ) ),
					'text'       => '',
					'label'      => $label,
					'rel'        => array_values( array_filter( Text::split( '/\s+/', strtolower( (string) $child->getAttribute( 'rel' ) ) ) ) ),
					'target'     => (string) $child->getAttribute( 'target' ),
					'image_alt'  => null,
					'in_heading' => ! empty( $state['heading'] ),
				);
				$inner         = $state;
				$inner['link'] = count( $this->links ) - 1;
				$this->walk( $child, $inner );
				$this->links[ $inner['link'] ]['text'] = self::squash( (string) $this->links[ $inner['link'] ]['text'] );
				continue;
			}
			if ( 1 === preg_match( '/^h([1-6])$/', $tag, $m ) ) {
				$this->flush();
				$before           = $this->buffer;
				$this->buffer     = '';
				$inner            = $state;
				$inner['heading'] = true;
				$this->walk( $child, $inner );
				$text             = self::squash( $this->buffer );
				$this->buffer     = $before;
				$this->blocks[]   = array(
					'type'  => 'h',
					'text'  => $text,
					'level' => (int) $m[1],
				);
				$this->headings[] = array(
					'level' => (int) $m[1],
					'text'  => $text,
					'id'    => (string) $child->getAttribute( 'id' ),
					'block' => count( $this->blocks ) - 1,
				);
				continue;
			}
			if ( in_array( $tag, self::BLOCK, true ) ) {
				$this->flush();
				if ( 'ul' === $tag || 'ol' === $tag ) {
					++$this->lists;
				} elseif ( 'li' === $tag ) {
					++$this->items;
				} elseif ( 'table' === $tag ) {
					++$this->tables;
					if ( 0 === $child->getElementsByTagName( 'th' )->length ) {
						++$this->tables_without_headers;
					}
				} elseif ( 'blockquote' === $tag ) {
					++$this->quotes;
				}
				$type              = $this->type_of( $tag );
				$outer             = $this->buffer_type;
				$this->buffer_type = $type;
				$this->walk( $child, $state );
				$this->flush();
				$this->buffer_type = $outer;
				continue;
			}
			if ( 'time' === $tag ) {
				++$this->times;
			} elseif ( 'cite' === $tag || 'q' === $tag ) {
				++$this->quotes;
			}
			$this->walk( $child, $state );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * The kind of text a block element holds.
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	private function type_of( string $tag ): string {
		switch ( $tag ) {
			case 'li':
			case 'dd':
			case 'dt':
				return 'li';
			case 'blockquote':
				return 'quote';
			case 'td':
			case 'th':
				return 'cell';
			case 'figcaption':
			case 'summary':
				return 'caption';
		}

		return 'p' === $this->buffer_type || '' === $this->buffer_type ? 'p' : $this->buffer_type;
	}

	/**
	 * Is an element hidden from readers (and so from search engines' reading of the page)?
	 *
	 * @param \DOMElement $el Element.
	 * @return bool
	 */
	private function hidden( \DOMElement $el ): bool {
		if ( $el->hasAttribute( 'hidden' ) || ( 'true' === $el->getAttribute( 'aria-hidden' ) && 'img' !== strtolower( $el->tagName ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the DOM's own property name.
			return true;
		}
		$style = strtolower( (string) $el->getAttribute( 'style' ) );

		return '' !== $style && 1 === preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/', $style );
	}

	/**
	 * End the current run of text.
	 *
	 * @return void
	 */
	private function flush(): void {
		$text = self::squash( $this->buffer );
		if ( '' !== $text ) {
			$this->blocks[] = array(
				'type' => $this->buffer_type,
				'text' => $text,
			);
		}
		$this->buffer = '';
	}

	/**
	 * Without the DOM extension: paragraphs and headings by pattern.
	 *
	 * @param string $html HTML.
	 * @return void
	 */
	private function fallback( string $html ): void {
		$html  = (string) preg_replace( '#<(script|style|noscript|template|svg|code|pre)\b.*?</\1>#is', ' ', $html );
		$parts = preg_split( '#(<h[1-6][^>]*>.*?</h[1-6]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( 1 === preg_match( '#^<h([1-6])[^>]*>(.*)</h\1>$#is', $part, $m ) ) {
				$text             = self::squash( html_entity_decode( (string) preg_replace( '#<[^>]+>#', ' ', $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$this->blocks[]   = array(
					'type'  => 'h',
					'text'  => $text,
					'level' => (int) $m[1],
				);
				$this->headings[] = array(
					'level' => (int) $m[1],
					'text'  => $text,
					'id'    => '',
					'block' => count( $this->blocks ) - 1,
				);
				continue;
			}
			foreach ( Text::split( '#</?(?:p|div|li|br|blockquote|td|th|section|article)\b[^>]*>#i', $part ) as $chunk ) {
				$text = self::squash( html_entity_decode( (string) preg_replace( '#<[^>]+>#', ' ', $chunk ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' !== $text ) {
					$this->blocks[] = array(
						'type' => 'p',
						'text' => $text,
					);
				}
			}
		}
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $imgs ) ) {
			foreach ( $imgs[0] as $tag ) {
				$alt            = preg_match( '#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $a ) ? trim( html_entity_decode( $a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : null;
				$this->images[] = array(
					'src'    => preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $s ) ? $s[2] : '',
					'alt'    => $alt,
					'linked' => false,
					'role'   => '',
				);
			}
		}
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $as, PREG_SET_ORDER ) ) {
			foreach ( $as as $a ) {
				if ( ! preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#is', $a[1], $h ) ) {
					continue;
				}
				$this->links[] = array(
					'href'       => html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'text'       => self::squash( html_entity_decode( (string) preg_replace( '#<[^>]+>#', ' ', $a[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
					'label'      => preg_match( '#\baria-label\s*=\s*(["\'])(.*?)\1#is', $a[1], $l ) ? $l[2] : '',
					'rel'        => preg_match( '#\brel\s*=\s*(["\'])(.*?)\1#is', $a[1], $r ) ? array_values( array_filter( explode( ' ', strtolower( $r[2] ) ) ) ) : array(),
					'target'     => preg_match( '#\btarget\s*=\s*(["\'])(.*?)\1#is', $a[1], $t ) ? $t[2] : '',
					'image_alt'  => null,
					'in_heading' => false,
				);
			}
		}
	}

	/**
	 * Collapse whitespace (every kind, incl. no-break spaces) to single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function squash( string $text ): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}\x{200B}\x{202F}\x{2007}]+/u', ' ', $text ) );
	}

	/**
	 * The prose: every block's text that is not a heading.
	 *
	 * @return array<int, string>
	 */
	public function paragraphs(): array {
		$out = array();
		foreach ( $this->blocks as $block ) {
			if ( 'h' !== $block['type'] && 'cell' !== $block['type'] ) {
				$out[] = (string) $block['text'];
			}
		}

		return $out;
	}

	/**
	 * All the text, headings included, one block per line.
	 *
	 * @return string
	 */
	public function text(): string {
		return implode( "\n", array_map( static fn( $b ) => (string) $b['text'], $this->blocks ) );
	}

	/**
	 * The text of the first paragraph (the introduction).
	 *
	 * @return string
	 */
	public function intro(): string {
		foreach ( $this->blocks as $block ) {
			if ( 'p' === $block['type'] && '' !== $block['text'] ) {
				return (string) $block['text'];
			}
		}

		return '';
	}
}
