<?php
/**
 * Footprint-free markup helpers for the SEO blocks (docs/adr/0033).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every class a block prints comes from {@see Html::cls()}: a neutral prefix (`zd` unless a site
 * chooses another), never the plugin's name. The rendered-page gate fails a page that names the
 * plugin, and the unit suite asserts the same on every block's HTML.
 */
final class Html {

	/**
	 * The class prefix of this request.
	 *
	 * @var string|null
	 */
	private static ?string $prefix = null;

	/**
	 * The neutral class prefix: 1-8 lowercase letters or digits starting with a letter, never a
	 * word that names a plugin; anything else falls back to `zd`.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		if ( null !== self::$prefix ) {
			return self::$prefix;
		}

		/**
		 * Filters the class prefix the SEO blocks print (default `zd`, shared with the other Zinn®
		 * plugins so a page carries one neutral prefix).
		 *
		 * @param string $prefix Prefix.
		 */
		$prefix       = (string) apply_filters( 'zinn_seo_frontend_prefix', 'zd' );
		self::$prefix = self::valid_prefix( $prefix ) ? $prefix : 'zd';

		return self::$prefix;
	}

	/**
	 * Is this an acceptable prefix?
	 *
	 * @param string $prefix Candidate.
	 * @return bool
	 */
	public static function valid_prefix( string $prefix ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9]{0,7}$/', $prefix )
			&& 1 !== preg_match( '/seo|zinn|pbs|sandwich|tranzly|builder|yoast|rank/', $prefix );
	}

	/**
	 * Forget the per-request prefix (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$prefix = null;
	}

	/**
	 * Prefixed class names: `cls( 'faq', 'faq__q' )` gives `zd-faq zd-faq__q`.
	 *
	 * @param string ...$parts Class suffixes.
	 * @return string
	 */
	public static function cls( string ...$parts ): string {
		$out = array();
		foreach ( $parts as $part ) {
			$part = (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $part ) );
			if ( '' !== $part ) {
				$out[] = self::prefix() . '-' . $part;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * An opening tag with a class (and optional attributes), escaped.
	 *
	 * @param string                $tag   Tag name.
	 * @param string                $css_class Class string (already from cls()).
	 * @param array<string, string> $attrs Other attributes.
	 * @return string
	 */
	public static function open( string $tag, string $css_class, array $attrs = array() ): string {
		$out = '<' . $tag;
		if ( '' !== $css_class ) {
			$out .= ' class="' . esc_attr( $css_class ) . '"';
		}
		foreach ( $attrs as $name => $value ) {
			if ( '' === (string) $value && 'alt' !== $name ) {
				continue;
			}
			$out .= ' ' . $name . '="' . ( in_array( $name, array( 'href', 'src', 'cite' ), true ) ? esc_url( (string) $value ) : esc_attr( (string) $value ) ) . '"';
		}

		return $out . '>';
	}

	/**
	 * Plain text, one line: tags and repeated white space removed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function plain( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
	}

	/**
	 * A text split by a pattern (an empty list when the pattern fails).
	 *
	 * @param string $pattern Pattern.
	 * @param string $text    Text.
	 * @return array<int, string>
	 */
	public static function split( string $pattern, string $text ): array {
		$parts = preg_split( $pattern, $text );

		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Multi-line text as escaped paragraphs (blank line = new paragraph, line break kept).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function paragraphs( string $text ): string {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		if ( '' === $text ) {
			return '';
		}
		$out = '';
		foreach ( self::split( '/\n{2,}/', $text ) as $para ) {
			$lines = array_map( 'esc_html', explode( "\n", trim( $para ) ) );
			$out  .= '<p>' . implode( '<br>', $lines ) . '</p>';
		}

		return $out;
	}

	/**
	 * The words in a text (Unicode-aware; CJK characters count as one word each, as readers of
	 * those scripts count them).
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	public static function words( string $text ): int {
		$text = self::plain( $text );
		if ( '' === $text ) {
			return 0;
		}
		$cjk  = (int) preg_match_all( '/[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u', $text );
		$rest = (string) preg_replace( '/[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u', ' ', $text );

		return $cjk + (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’-]*/u', $rest );
	}
}
