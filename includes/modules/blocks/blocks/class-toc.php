<?php
/**
 * Table of contents (zs-074).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Headings;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links to the page's headings, built from the page as rendered (see Headings), in plain HTML: no
 * script, no smooth-scroll library. Collapsible with `<details>` when asked.
 */
final class Toc extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'toc';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Table of contents', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Links to the headings of the page, kept up to date as you edit, with no script.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'list-view';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'contents', 'zinn-seo' ), __( 'headings', 'zinn-seo' ), __( 'jump links', 'zinn-seo' ), 'TOC' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return self::list_fields( __( 'Contents', 'zinn-seo' ) );
	}

	/**
	 * The fields every list of contents shares (this block and the article index).
	 *
	 * @param string $title Default title.
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_fields( string $title ): array {
		$levels = array();
		foreach ( array( 2, 3, 4, 5, 6 ) as $l ) {
			/* translators: %d: a heading level, 2 to 6. */
			$levels[ (string) $l ] = sprintf( __( 'Heading %d', 'zinn-seo' ), $l );
		}

		return array(
			'title'       => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Title', 'zinn-seo' ),
				'default' => $title,
				'content' => true,
				'max'     => 120,
			),
			'min_level'   => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'From', 'zinn-seo' ),
				'default' => '2',
				'choices' => $levels,
			),
			'max_level'   => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'To', 'zinn-seo' ),
				'default' => '3',
				'choices' => $levels,
			),
			'numbered'    => array(
				'type'  => 'bool',
				'label' => static fn() => __( 'Numbered', 'zinn-seo' ),
			),
			'collapsible' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Opens and closes', 'zinn-seo' ),
				'default' => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	public function render( array $v, Render_Context $ctx ): string {
		$build = static function ( array $headings ) use ( $v ): string {
			return self::html( $headings, $v, 'toc' );
		};
		if ( Headings::in_content() && ! $ctx->preview ) {
			return Headings::placeholder( $build );
		}
		$html = $build( Headings::of_post( $ctx->post ) );

		// In an editor's preview the page may have no saved headings yet (an Elementor page keeps
		// its text in Elementor's data): say what will be here rather than show nothing.
		return '' === $html && $ctx->preview ? self::preview_note( (string) $v['title'] ) : $html;
	}

	/**
	 * What an editor shows for a list of contents it cannot build yet.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function preview_note( string $title ): string {
		return Html::open( 'nav', Html::cls( 'toc' ), array( 'aria-label' => $title ) )
			. Html::open( 'p', Html::cls( 'toc__title' ) ) . esc_html( '' !== $title ? $title : __( 'Contents', 'zinn-seo' ) ) . '</p>'
			. '<p>' . esc_html__( 'The links to this page\'s headings appear here on the published page.', 'zinn-seo' ) . '</p></nav>';
	}

	/**
	 * The list's HTML.
	 *
	 * @param array<int, array{level: int, text: string, id: string}> $headings Headings.
	 * @param array<string, mixed>                                    $v        Values.
	 * @param string                                                  $base     Class base.
	 * @return string
	 */
	public static function html( array $headings, array $v, string $base ): string {
		$min   = (int) $v['min_level'];
		$max   = max( $min, (int) $v['max_level'] );
		$items = array_values( array_filter( $headings, static fn( array $h ): bool => $h['level'] >= $min && $h['level'] <= $max ) );
		if ( array() === $items ) {
			return '';
		}
		$tag   = $v['numbered'] ? 'ol' : 'ul';
		$inner = self::nested( $items, $tag, $base );
		$label = '' !== $v['title'] ? $v['title'] : __( 'Contents', 'zinn-seo' );
		if ( $v['collapsible'] ) {
			return Html::open( 'nav', Html::cls( $base ), array( 'aria-label' => $label ) )
				. Html::open( 'details', Html::cls( $base . '__box' ), array( 'open' => 'open' ) )
				. Html::open( 'summary', Html::cls( $base . '__title' ) ) . esc_html( $label ) . '</summary>'
				. $inner . '</details></nav>';
		}

		return Html::open( 'nav', Html::cls( $base ), array( 'aria-label' => $label ) )
			. Html::open( 'p', Html::cls( $base . '__title' ) ) . esc_html( $label ) . '</p>'
			. $inner . '</nav>';
	}

	/**
	 * Nested lists following the heading levels.
	 *
	 * @param array<int, array{level: int, text: string, id: string}> $items Headings.
	 * @param string                                                  $tag   ul or ol.
	 * @param string                                                  $base  Class base.
	 * @return string
	 */
	private static function nested( array $items, string $tag, string $base ): string {
		$out   = Html::open( $tag, Html::cls( $base . '__list' ) );
		$stack = array( $items[0]['level'] );
		$open  = false;
		foreach ( $items as $h ) {
			$level = $h['level'];
			if ( $open && $level > end( $stack ) ) {
				$out    .= '<' . $tag . '>';
				$stack[] = $level;
			} else {
				while ( isset( $stack[1] ) && $level < end( $stack ) ) {
					array_pop( $stack );
					$out .= '</li></' . $tag . '>';
				}
				if ( $open ) {
					$out .= '</li>';
				}
			}
			$out .= '<li><a href="#' . esc_attr( $h['id'] ) . '">' . esc_html( $h['text'] ) . '</a>';
			$open = true;
		}
		while ( isset( $stack[1] ) ) {
			array_pop( $stack );
			$out .= '</li></' . $tag . '>';
		}

		return $out . '</li></' . $tag . '>';
	}
}
