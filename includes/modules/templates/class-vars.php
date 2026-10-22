<?php
/**
 * Template variables: `%title% %sep% %sitename%` in, a clean title out (zs-020).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The variable language, and nothing that needs WordPress: render() takes a lookup function, so
 * the same code renders a visitor's title, the screen's live preview and a unit test.
 *
 * Rules a template author can rely on:
 * - `%name%` is a variable; an unknown or empty variable becomes nothing, never the literal
 *   `%name%` in a search result.
 * - `%sep%` is the separator. Separators left at the start or end, or next to each other because
 *   the variable between them was empty, disappear: `%title% %sep% %tagline%` on a site with no
 *   tagline is just the title.
 * - `%cf_<key>%` is a custom field of the post and `%ct_<taxonomy>%` its first term in a
 *   taxonomy. A protected field (its key starts with `_`) is never read: a template must not be
 *   able to print a private value into the page.
 * - Whitespace collapses to single spaces; values are plain text.
 */
final class Vars {

	/** Stands in for `%sep%` while the template is cleaned (a control character no value keeps). */
	private const MARK = "\x1F";

	/** The longest variable name. */
	private const NAME = '[a-z][a-z0-9_-]{0,63}';

	/**
	 * An optional argument in brackets after a name: `%date(F Y)%` (a date in that format).
	 * Letters, digits, spaces and the punctuation a date format uses; never a percent sign.
	 */
	private const ARG = '(?:\(([A-Za-z0-9 ,.\/:\-_|\\\\]{1,80})\))?';

	/**
	 * A description template that says "print no description here" (an empty template means
	 * "the default", so leaving a tag out needs a word of its own). An import writes it where
	 * the plugin a site moved from printed none.
	 */
	public const NONE = '%none%';

	/**
	 * The variables, for screens and the docs: name => [label, where it has a value].
	 * Labels are callables so they are translated only when shown.
	 *
	 * @return array<string, array{label: callable, where: array<int, string>}>
	 */
	public static function catalogue(): array {
		$all = array( 'home', 'post', 'archive', 'tax', 'author', 'date', 'search', '404' );

		return array(
			'title'            => array(
				'label' => static fn() => __( 'Title of the page, post, term or archive', 'zinn-seo' ),
				'where' => $all,
			),
			'sitename'         => array(
				'label' => static fn() => __( 'Site title', 'zinn-seo' ),
				'where' => $all,
			),
			'tagline'          => array(
				'label' => static fn() => __( 'Site tagline', 'zinn-seo' ),
				'where' => $all,
			),
			'sep'              => array(
				'label' => static fn() => __( 'Separator', 'zinn-seo' ),
				'where' => $all,
			),
			'page'             => array(
				'label' => static fn() => __( '"Page 2 of 5" on the second and later pages, nothing on the first', 'zinn-seo' ),
				'where' => $all,
			),
			'pagenumber'       => array(
				'label' => static fn() => __( 'Page number', 'zinn-seo' ),
				'where' => $all,
			),
			'pagetotal'        => array(
				'label' => static fn() => __( 'Number of pages', 'zinn-seo' ),
				'where' => $all,
			),
			'excerpt'          => array(
				'label' => static fn() => __( 'Excerpt, or the start of the text when there is none', 'zinn-seo' ),
				'where' => array( 'post', 'tax', 'author' ),
			),
			'date'             => array(
				'label' => static fn() => __( 'Date published (or the archive\'s date). A format of your choice can follow in brackets, as in date(F Y); on archives the format is for month archives', 'zinn-seo' ),
				'where' => array( 'post', 'date' ),
			),
			'modified'         => array(
				'label' => static fn() => __( 'Date last updated', 'zinn-seo' ),
				'where' => array( 'post' ),
			),
			'year'             => array(
				'label' => static fn() => __( 'Year published', 'zinn-seo' ),
				'where' => array( 'post', 'date' ),
			),
			'author'           => array(
				'label' => static fn() => __( 'Author\'s name', 'zinn-seo' ),
				'where' => array( 'post', 'author' ),
			),
			'category'         => array(
				'label' => static fn() => __( 'First category', 'zinn-seo' ),
				'where' => array( 'post' ),
			),
			'term'             => array(
				'label' => static fn() => __( 'Name of the category, tag or term', 'zinn-seo' ),
				'where' => array( 'tax' ),
			),
			'term_description' => array(
				'label' => static fn() => __( 'Description of the term', 'zinn-seo' ),
				'where' => array( 'tax' ),
			),
			'parent_title'     => array(
				'label' => static fn() => __( 'Title of the parent page', 'zinn-seo' ),
				'where' => array( 'post' ),
			),
			'posttype'         => array(
				'label' => static fn() => __( 'Name of the content type (singular)', 'zinn-seo' ),
				'where' => array( 'post', 'archive' ),
			),
			'posttype_plural'  => array(
				'label' => static fn() => __( 'Name of the content type (plural)', 'zinn-seo' ),
				'where' => array( 'post', 'archive' ),
			),
			'search'           => array(
				'label' => static fn() => __( 'The words searched for', 'zinn-seo' ),
				'where' => array( 'search' ),
			),
			'currentyear'      => array(
				'label' => static fn() => __( 'This year', 'zinn-seo' ),
				'where' => $all,
			),
			'currentmonth'     => array(
				'label' => static fn() => __( 'This month', 'zinn-seo' ),
				'where' => $all,
			),
			'id'               => array(
				'label' => static fn() => __( 'ID of the post or term', 'zinn-seo' ),
				'where' => array( 'post', 'tax' ),
			),
			'archive_prefix'   => array(
				'label' => static fn() => __( 'WordPress\'s own label for an archive: "Category:", "Tag:", "Author:", "Month:"', 'zinn-seo' ),
				'where' => array( 'tax', 'author', 'date', 'archive' ),
			),
			'none'             => array(
				'label' => static fn() => __( 'Nothing: alone in a description, it leaves the description tag out', 'zinn-seo' ),
				'where' => $all,
			),
			'cf_'              => array(
				'label' => static fn() => __( 'Any custom field of the post: cf_ followed by the field\'s name, for example cf_price', 'zinn-seo' ),
				'where' => array( 'post' ),
			),
			'ct_'              => array(
				'label' => static fn() => __( 'The post\'s first term in a taxonomy: ct_ followed by the taxonomy\'s name, for example ct_genre', 'zinn-seo' ),
				'where' => array( 'post' ),
			),
		);
	}

	/**
	 * Render a template.
	 *
	 * @param string   $template Template text.
	 * @param callable $lookup   `function ( string $name ): ?string` — a variable's value, null when unknown.
	 * @param string   $sep      The separator.
	 * @return string Plain text (not escaped for HTML).
	 */
	public static function render( string $template, callable $lookup, string $sep ): string {
		$text = str_replace( self::MARK, '', $template );
		$text = (string) preg_replace( '/%sep%/i', ' ' . self::MARK . ' ', $text );
		$text = (string) preg_replace_callback(
			'/%(' . self::NAME . ')' . self::ARG . '%/i',
			static function ( array $m ) use ( $lookup ): string {
				// The argument (a date format, `%date(F Y)%`) keeps its case.
				$value = $lookup( strtolower( $m[1] ), (string) ( $m[2] ?? '' ) );
				return null === $value ? '' : self::plain( (string) $value );
			},
			$text
		);
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		// A separator whose neighbour was empty: collapse runs, then trim both ends.
		$text = (string) preg_replace( '/' . self::MARK . '(?:\s*' . self::MARK . ')+/u', self::MARK, $text );
		$text = trim( $text, ' ' . self::MARK );
		$text = (string) preg_replace( '/\s*' . self::MARK . '\s*/u', ' ' . $sep . ' ', $text );

		return trim( $text );
	}

	/**
	 * The variable names a template uses (for previews and checks).
	 *
	 * @param string $template Template.
	 * @return array<int, string>
	 */
	public static function used( string $template ): array {
		preg_match_all( '/%(' . self::NAME . ')' . self::ARG . '%/i', $template, $m );

		return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	}

	/**
	 * Plain, single-line text from HTML or stored text.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function plain( string $value ): string {
		$value = str_replace( self::MARK, '', $value );
		$value = (string) preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $value );
		// A block-level tag separates words: "<p>Intro.</p><p>More</p>" is "Intro. More".
		$value = (string) preg_replace( '/<\/?(p|div|br|hr|li|ul|ol|h[1-6]|figure|figcaption|blockquote|section|article|header|footer|table|tr|td|th|dt|dd|pre)\b[^>]*>/i', ' ', $value );
		$value = wp_strip_all_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Shorten text as Rank Math does (its `Str::truncate( $text, 160 )`): cut at `$max`
	 * characters, then back to the last space, with nothing added. Pure, for the tests.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function trim_rank_math( string $text, int $max = 160 ): string {
		$text = self::plain( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( trim( $cut ), ' ' );

		return false === $space ? $cut : mb_substr( $text, 0, $space );
	}

	/**
	 * Shorten text for a description: at most `$max` characters, cut at a word boundary when one
	 * is close, never in the middle of a character. Scripts without spaces (Chinese, Japanese,
	 * Thai) are cut at the limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function trim_text( string $text, int $max = 155 ): string {
		$text = self::plain( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space >= (int) ( $max * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, ' ,;:-–—' ) . '…';
	}
}
