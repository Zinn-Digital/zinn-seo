<?php
/**
 * The text of llms.txt and llms-full.txt (llmstxt.org), from data (zs-061, zs-062).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Llms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure: an H1 with the site's name, a quoted summary, an optional paragraph, then `##` sections
 * of `- [Title](address): description` lines; a section called "Optional" (localised) holds what
 * an agent may skip. llms-full.txt is each listed page's text, in the same order.
 */
final class Llms_Text {

	/**
	 * The text of llms.txt.
	 *
	 * @param array{name: string, summary: string, details?: string}                                                $site     The site.
	 * @param array<int, array{title: string, items: array<int, array{title: string, url: string, desc?: string}>}> $sections Sections.
	 * @return string
	 */
	public static function index( array $site, array $sections ): string {
		$out = '# ' . self::line( (string) $site['name'] ) . "\n";
		if ( '' !== trim( (string) $site['summary'] ) ) {
			$out .= "\n> " . self::line( (string) $site['summary'] ) . "\n";
		}
		if ( '' !== trim( (string) ( $site['details'] ?? '' ) ) ) {
			$out .= "\n" . trim( (string) $site['details'] ) . "\n";
		}
		foreach ( $sections as $section ) {
			$items = (array) $section['items'];
			if ( array() === $items ) {
				continue;
			}
			$out .= "\n## " . self::line( (string) $section['title'] ) . "\n\n";
			foreach ( $items as $item ) {
				$desc = self::line( (string) ( $item['desc'] ?? '' ) );
				$out .= '- [' . self::label( (string) $item['title'] ) . '](' . self::url( (string) $item['url'] ) . ')' . ( '' !== $desc ? ': ' . $desc : '' ) . "\n";
			}
		}

		return $out;
	}

	/**
	 * One page's block in llms-full.txt.
	 *
	 * @param string $title    Title.
	 * @param string $url      Address.
	 * @param string $markdown The page's text as Markdown.
	 * @return string
	 */
	public static function full_entry( string $title, string $url, string $markdown ): string {
		return '# ' . self::line( $title ) . "\n\nURL: " . self::url( $url ) . "\n\n" . trim( $markdown ) . "\n\n---\n\n";
	}

	/**
	 * Text on one line (no line breaks, collapsed spaces).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * A link's text, with brackets escaped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function label( string $text ): string {
		return str_replace( array( '[', ']' ), array( '\[', '\]' ), self::line( $text ) );
	}

	/**
	 * An address safe inside `( )`.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function url( string $url ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), trim( $url ) );
	}
}
