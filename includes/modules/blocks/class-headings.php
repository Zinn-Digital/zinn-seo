<?php
/**
 * Headings, anchors and the lists of contents built from them (zs-074, zs-301).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A table of contents is only right when it is built from the page AS RENDERED — after
 * shortcodes, page builders (Elementor renders inside `the_content`) and translation (Tranzly
 * translates at render time). So a contents block rendered inside `the_content` prints a
 * placeholder, and one pass at the very end of `the_content` reads the finished HTML, gives every
 * heading an `id` (keeping one it already has), builds the list, and puts it where the
 * placeholder was. The anchors therefore always match the headings the reader sees, in every
 * language (zs-301: "jump links survive Tranzly translated slugs").
 *
 * Outside `the_content` (an editor preview, a widget) the list is built from the post's stored
 * content with the same rules, so it names the same anchors.
 */
final class Headings {

	/** Placeholder delimiters: private-use characters no editor can type. */
	private const OPEN  = "\u{E000}zdtoc";
	private const CLOSE = "\u{E001}";

	/**
	 * Placeholders waiting for the pass: number => [callable(list, html): string].
	 *
	 * @var array<int, callable>
	 */
	private static array $pending = array();

	/**
	 * Hook the pass.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'finish' ), 1000 );
	}

	/**
	 * A placeholder whose HTML is made by `$build( $headings, $html )` once the page is rendered.
	 *
	 * @param callable $build Builder: (array $headings, string $html) => string.
	 * @return string
	 */
	public static function placeholder( callable $build ): string {
		$n                   = count( self::$pending ) + 1;
		self::$pending[ $n ] = $build;

		return self::OPEN . $n . self::CLOSE;
	}

	/**
	 * Is a list being rendered inside the content, where the pass will run?
	 *
	 * @return bool
	 */
	public static function in_content(): bool {
		return doing_filter( 'the_content' ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * The pass at the end of `the_content`.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public static function finish( $html ): string {
		$html = (string) $html;
		if ( array() === self::$pending || false === strpos( $html, self::OPEN ) ) {
			return $html;
		}
		$done     = self::anchor( $html );
		$html     = $done['html'];
		$headings = $done['headings'];
		$html     = (string) preg_replace_callback(
			'/' . self::OPEN . '(\d+)' . self::CLOSE . '/u',
			static function ( array $m ) use ( $headings, $html ): string {
				$build = self::$pending[ (int) $m[1] ] ?? null;
				unset( self::$pending[ (int) $m[1] ] );
				return is_callable( $build ) ? (string) call_user_func( $build, $headings, $html ) : '';
			},
			$html
		);

		return $html;
	}

	/**
	 * Give every heading (h2-h6) an id, keeping ids already there, and list them.
	 *
	 * @param string $html HTML.
	 * @return array{html: string, headings: array<int, array{level: int, text: string, id: string}>}
	 */
	public static function anchor( string $html ): array {
		$used     = array();
		$headings = array();
		// Ids already on the page are taken first, so a new one never repeats them.
		if ( preg_match_all( '/\sid=(["\'])([^"\']+)\1/i', $html, $ids ) ) {
			foreach ( $ids[2] as $id ) {
				$used[ $id ] = true;
			}
		}
		$out = (string) preg_replace_callback(
			'#<h([2-6])(\s[^>]*)?>(.*?)</h\1>#is',
			static function ( array $m ) use ( &$used, &$headings ): string {
				$attrs = (string) ( $m[2] ?? '' );
				$text  = Html::plain( $m[3] );
				// FAQ questions are headings for their meaning, not sections of the page: the
				// contents list leaves them out (they are listed by the FAQ itself).
				if ( '' === $text || 1 === preg_match( '/class=["\'][^"\']*\b[a-z][a-z0-9]{0,7}-faq__q/', $attrs ) ) {
					return $m[0];
				}
				if ( preg_match( '/\sid=(["\'])([^"\']+)\1/i', $attrs, $has ) ) {
					$id = $has[2];
				} else {
					$id          = self::slug( $text, $used );
					$used[ $id ] = true;
					$attrs      .= ' id="' . esc_attr( $id ) . '"';
				}
				$headings[] = array(
					'level' => (int) $m[1],
					'text'  => $text,
					'id'    => $id,
				);
				return '<h' . $m[1] . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
			},
			$html
		);

		return array(
			'html'     => $out,
			'headings' => $headings,
		);
	}

	/**
	 * An anchor from heading text: lower case, letters and digits of ANY script kept (an Arabic
	 * heading gets an Arabic anchor, which browsers and search engines handle), the rest joined
	 * by hyphens, unique on the page.
	 *
	 * @param string              $text Heading text.
	 * @param array<string, bool> $used Ids already used.
	 * @return string
	 */
	public static function slug( string $text, array $used ): string {
		$slug = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$slug = (string) preg_replace( '/[^\p{L}\p{N}\p{M}]+/u', '-', $slug );
		$slug = trim( $slug, '-' );
		if ( '' === $slug ) {
			$slug = 'section';
		}
		if ( mb_strlen( $slug ) > 60 ) {
			$slug = rtrim( mb_substr( $slug, 0, 60 ), '-' );
		}
		// An id may not start with a digit in a CSS selector; a letter keeps it usable everywhere.
		if ( 1 === preg_match( '/^\p{N}/u', $slug ) ) {
			$slug = 's-' . $slug;
		}
		$base = $slug;
		$n    = 1;
		while ( isset( $used[ $slug ] ) ) {
			++$n;
			$slug = $base . '-' . $n;
		}

		return $slug;
	}

	/**
	 * The headings of a post's stored content (outside `the_content`).
	 *
	 * @param \WP_Post|null $post Post.
	 * @return array<int, array{level: int, text: string, id: string}>
	 */
	public static function of_post( ?\WP_Post $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return self::anchor( (string) $post->post_content )['headings'];
	}

	/**
	 * Forget placeholders (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$pending = array();
	}

	/**
	 * The words a reader reads in some HTML (scripts, styles and our own lists left out).
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	public static function words( string $html ): int {
		$html = (string) preg_replace( '#<(script|style|nav)\b.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/' . self::OPEN . '\d+' . self::CLOSE . '/u', ' ', $html );

		return Html::words( str_replace( '<', ' <', $html ) );
	}
}
