<?php
/**
 * Text kept in custom fields counts as part of the page: one implementation for every
 * custom-fields plugin.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A page built from custom fields (a text field for the intro, a rich-text field for the body, a
 * repeater of sections) has almost nothing in the editor's main box, so a content check that
 * reads only that box calls a 2,000-word page "too short". Each custom-fields plugin's
 * integration hands this class a READER (the text of a post's text-like fields) and the analysis
 * reads the page with that text added after its content.
 *
 * Only for the analysis (an editor's request), never on a visitor's page. Field values can be
 * used in titles and descriptions with `%cf_<field name>%` already (Search appearance).
 */
final class Fields {

	/**
	 * Readers: `function ( int $post_id ): array<int, string>` (HTML or plain text per field).
	 *
	 * @var array<string, callable>
	 */
	private static array $readers = array();

	/**
	 * Add a plugin's reader.
	 *
	 * @param string   $id     Plugin id.
	 * @param callable $reader Reader.
	 * @return void
	 */
	public static function boot( string $id, callable $reader ): void {
		$first                = array() === self::$readers;
		self::$readers[ $id ] = $reader;
		if ( $first ) {
			add_filter( 'zinn_seo_rendered_content', array( self::class, 'rendered' ), 99, 2 );
		}
	}

	/**
	 * The text of a post's text-like custom fields, as HTML paragraphs.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function html( int $post_id ): string {
		$out  = '';
		$seen = array();
		foreach ( self::$readers as $reader ) {
			try {
				$texts = (array) $reader( $post_id );
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( $texts as $text ) {
				$text = is_scalar( $text ) ? trim( (string) $text ) : '';
				// A value that is a number, an address or a short code is not prose.
				if ( '' === $text || isset( $seen[ $text ] ) || is_numeric( $text ) || 1 === preg_match( '#^(https?://|/|\#)\S*$#', $text ) ) {
					continue;
				}
				$seen[ $text ] = true;
				$out          .= false !== strpos( $text, '<' ) ? "\n" . wp_kses_post( $text ) : "\n<p>" . esc_html( $text ) . '</p>';
			}
		}

		return $out;
	}

	/**
	 * Every text in a value that may be nested (a repeater's rows, a group's sub-fields).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Nesting.
	 * @return array<int, string>
	 */
	public static function strings( $value, int $depth = 0 ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}
		$out = array();
		if ( is_array( $value ) && $depth < 6 ) {
			foreach ( array_slice( $value, 0, 200, true ) as $key => $item ) {
				// Keys that name a file, an id or a layout are not prose.
				if ( is_string( $key ) && 1 === preg_match( '/(^|_)(id|ID|url|link|src|file|image|icon|class|layout|key|color|colour|date|time|mime|type|width|height)$/', $key ) ) {
					continue;
				}
				foreach ( self::strings( $item, $depth + 1 ) as $text ) {
					$out[] = $text;
				}
			}
		}

		return $out;
	}

	/**
	 * The page as visitors read it, with its custom fields' text after its content.
	 *
	 * @param mixed $content The content so far (a page builder may already have rendered it).
	 * @param mixed $post    The post (a copy carrying the editor's unsaved values).
	 * @return string
	 */
	public static function rendered( $content, $post ): string {
		$content = (string) $content;
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		$extra = self::html( (int) $post->ID );
		if ( '' === $extra ) {
			return $content;
		}
		// Nobody rendered it yet: WordPress's own filters, as the analysis would have run them.
		if ( $content === (string) $post->post_content ) {
			$content = (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter: the page as visitors get it.
		}

		return $content . $extra;
	}

	/**
	 * Forget the per-request state (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$readers = array();
	}
}
