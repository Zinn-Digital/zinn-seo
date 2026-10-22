<?php
/**
 * Snippet controls (zs-741): keep any block, or any passage, out of the text search results show.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Snippets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google shows a passage of a page under its title. `data-nosnippet` on an element keeps that
 * element's text out of the passage (a price that changes, a disclaimer, a spoiler) while the page
 * stays indexed. Every block gets a "Hide from search result snippets" switch in the editor; the
 * classic editor gets `[nosnippet]…[/nosnippet]`. The page-wide limits (no snippet, longest
 * snippet, longest video preview) are the page's own SEO fields, shown in the search preview.
 *
 * ⭐ The switch is stored in the block's own comment (`{"zseoNosnippet":true}`), which WordPress
 * removes when it draws the page: the visitor's HTML gets `data-nosnippet` and nothing else.
 */
final class Snippets {

	/** The block attribute. */
	public const ATTRIBUTE = 'zseoNosnippet';

	/**
	 * Hooks for every request type (block types are registered everywhere, and the REST block
	 * renderer checks attributes).
	 *
	 * @return void
	 */
	public static function boot(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_block_type_args( array( self::class, 'block_args' ) );
		add_filter( 'render_block', array( self::class, 'render' ), 10, 2 );
		if ( ! shortcode_exists( 'nosnippet' ) ) {
			add_shortcode( 'nosnippet', array( self::class, 'shortcode' ) );
		}
	}

	/**
	 * Every block type accepts the attribute.
	 *
	 * @param array<string, mixed> $args Block type arguments.
	 * @return array<string, mixed>
	 */
	public static function block_args( $args ): array {
		$args = (array) $args;
		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}
		$args['attributes'][ self::ATTRIBUTE ] = array(
			'type'    => 'boolean',
			'default' => false,
		);

		return $args;
	}

	/**
	 * Add `data-nosnippet` to the block's outer element.
	 *
	 * @param string               $html  The block's HTML.
	 * @param array<string, mixed> $block The block.
	 * @return string
	 */
	public static function render( $html, $block ): string {
		$html = (string) $html;
		if ( empty( $block['attrs'][ self::ATTRIBUTE ] ) ) {
			return $html;
		}

		return self::mark( $html );
	}

	/**
	 * `data-nosnippet` on the first element (wrapped in a `div` when the HTML does not start with
	 * one element, so nothing is left outside).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function mark( string $html ): string {
		$trimmed = ltrim( $html );
		if ( '' === $trimmed ) {
			return $html;
		}
		if ( 1 === preg_match( '/^<([a-zA-Z][a-zA-Z0-9-]*)(\s[^>]*)?>/', $trimmed, $m ) ) {
			if ( false !== stripos( $m[0], 'data-nosnippet' ) ) {
				return $html;
			}
			$tag = $m[1];
			// Only when this first element is the only outer one (it closes at the very end).
			if ( self::single_root( $trimmed, $tag ) ) {
				return substr( $html, 0, strlen( $html ) - strlen( $trimmed ) ) . '<' . $tag . ' data-nosnippet' . substr( $trimmed, strlen( $tag ) + 1 );
			}
		}

		return '<div data-nosnippet>' . $html . '</div>';
	}

	/**
	 * Is the HTML one element of this tag, opened first and closed last?
	 *
	 * @param string $html HTML, trimmed at the start.
	 * @param string $tag  The first element's tag.
	 * @return bool
	 */
	public static function single_root( string $html, string $tag ): bool {
		if ( in_array( strtolower( $tag ), array( 'img', 'br', 'hr', 'input', 'source', 'meta', 'link' ), true ) ) {
			return false;
		}
		$depth = 0;
		$end   = rtrim( $html );
		if ( ! preg_match_all( '#<(/?)' . preg_quote( $tag, '#' ) . '\b[^>]*>#i', $end, $all, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		foreach ( $all[0] as $i => $match ) {
			$depth += '/' === $all[1][ $i ][0] ? -1 : 1;
			if ( 0 === $depth ) {
				// The first element closes here: it must be the end of the HTML.
				return $match[1] + strlen( $match[0] ) === strlen( $end );
			}
		}

		return false;
	}

	/**
	 * `[nosnippet]text[/nosnippet]` for the classic editor.
	 *
	 * @param array<string, string>|string $atts    Attributes (none).
	 * @param string|null                  $content The passage.
	 * @return string
	 */
	public static function shortcode( $atts, $content = null ): string {
		unset( $atts );
		$inner = do_shortcode( (string) $content );
		$tag   = 1 === preg_match( '/<(p|div|ul|ol|table|h[1-6]|blockquote|figure)\b/i', $inner ) ? 'div' : 'span';

		return '<' . $tag . ' data-nosnippet>' . $inner . '</' . $tag . '>';
	}
}
