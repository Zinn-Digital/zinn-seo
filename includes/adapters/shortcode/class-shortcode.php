<?php
/**
 * Shortcode adapter: every SEO block anywhere text is accepted (zs-300).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Adapters;

use ZinnDigital\ZinnSEO\Blocks\Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[zinn_seo block="quick-answer" question="…" answer="…"]` — for the classic editor, builders
 * without a native adapter, widgets and theme files (`do_shortcode`). Simple fields are attributes;
 * a list takes `|` between items; anything structured (FAQ questions, how-to steps, criteria) goes
 * between the tags as JSON:
 *
 *     [zinn_seo block="faq"]{"items":[{"question":"…","answer":"…"}]}[/zinn_seo]
 *
 * The same one renderer draws it, and the page scan finds it before the head prints.
 */
final class Shortcode {

	/** The tag. */
	public const TAG = 'zinn_seo';

	/**
	 * Register.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
		add_filter( 'zinn_seo_block_scan', array( self::class, 'scan' ), 20, 2 );
	}

	/**
	 * The shortcode.
	 *
	 * @param array<string, string>|string $atts    Attributes.
	 * @param string|null                  $content Enclosed text.
	 * @return string
	 */
	public static function render( $atts, $content = null ): string {
		$item = self::values( is_array( $atts ) ? $atts : array(), (string) $content );
		if ( null === $item ) {
			return '';
		}
		$ctx = Gutenberg::context( null, 'shortcode' );

		return Library::render( $item['name'], $item['values'], $ctx );
	}

	/**
	 * A shortcode's block and raw values.
	 *
	 * @param array<string, string> $atts    Attributes.
	 * @param string                $content Enclosed text.
	 * @return array{name: string, values: array<string, mixed>}|null
	 */
	public static function values( array $atts, string $content ): ?array {
		$name  = sanitize_key( (string) ( $atts['block'] ?? '' ) );
		$block = Library::get( $name );
		if ( null === $block ) {
			return null;
		}
		$fields = $block->fields();
		$values = array();
		$json   = trim( html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		// Editors turn quotes curly; JSON needs straight ones.
		$json = str_replace( array( '“', '”', '″', '″' ), '"', $json );
		if ( '' !== $json ) {
			$data = json_decode( $json, true );
			if ( is_array( $data ) ) {
				$values = $data;
			}
		}
		foreach ( $atts as $key => $value ) {
			$key = (string) $key;
			if ( 'block' === $key || ! isset( $fields[ $key ] ) ) {
				continue;
			}
			$type           = (string) $fields[ $key ]['type'];
			$values[ $key ] = 'list' === $type ? array_map( 'trim', explode( '|', (string) $value ) ) : (string) $value;
		}

		return array(
			'name'   => $block->name(),
			'values' => $values,
		);
	}

	/**
	 * Find the shortcodes in a post.
	 *
	 * @param array<int, array<string, mixed>> $found Found so far.
	 * @param \WP_Post                         $post  Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( $found, $post ): array {
		$found = (array) $found;
		if ( ! $post instanceof \WP_Post || false === strpos( (string) $post->post_content, '[' . self::TAG ) ) {
			return $found;
		}
		if ( preg_match_all( '/' . get_shortcode_regex( array( self::TAG ) ) . '/s', (string) $post->post_content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $sc ) {
				if ( '[' === $sc[1] && ']' === $sc[6] ) {
					continue; // An escaped [[zinn_seo]] prints itself.
				}
				$atts = shortcode_parse_atts( (string) $sc[3] );
				$item = self::values( is_array( $atts ) ? $atts : array(), (string) ( $sc[5] ?? '' ) );
				if ( null !== $item ) {
					$found[] = array(
						'name'    => $item['name'],
						'values'  => $item['values'],
						'builder' => 'shortcode',
					);
				}
			}
		}

		return $found;
	}
}
