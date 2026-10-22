<?php
/**
 * Key takeaways / TL;DR (zs-302).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The few points a reader (or an AI answer) should leave with. Written by hand in every edition;
 * drafted from the post by AI where an AI connection is set up (the "Draft" button in the block
 * editor, `zinn_seo_ai_draft`), and always editable.
 */
final class Takeaways extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'takeaways';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Key takeaways', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'The main points of the page in a short list: a summary readers and AI answers can quote.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'yes-alt';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( 'TL;DR', __( 'summary', 'zinn-seo' ), __( 'key points', 'zinn-seo' ) );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'title' => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Title', 'zinn-seo' ),
				'default' => __( 'Key takeaways', 'zinn-seo' ),
				'content' => true,
				'max'     => 120,
			),
			'items' => array(
				'type'    => 'list',
				'label'   => static fn() => __( 'Points (one per line)', 'zinn-seo' ),
				'content' => true,
				'max'     => 400,
			),
			'style' => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Look', 'zinn-seo' ),
				'default' => 'box',
				'choices' => array(
					'box'   => static fn() => __( 'Box', 'zinn-seo' ),
					'plain' => static fn() => __( 'Plain list', 'zinn-seo' ),
				),
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array( 'items' => array( 'Back up before you start.', 'Test on a copy first.', 'Switch the domain last.' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	public function render( array $v, Render_Context $ctx ): string {
		return self::html( (string) $v['title'], (array) $v['items'], 'box' === $v['style'] );
	}

	/**
	 * The list's HTML (shared with the article index).
	 *
	 * @param string             $title Title.
	 * @param array<int, string> $items Points.
	 * @param bool               $box   Boxed.
	 * @return string
	 */
	public static function html( string $title, array $items, bool $box ): string {
		if ( array() === $items ) {
			return '';
		}
		$out = Html::open( 'div', Html::cls( 'tldr', $box ? 'tldr--box' : '' ) );
		if ( '' !== $title ) {
			$out .= Html::open( 'p', Html::cls( 'tldr__title' ) ) . esc_html( $title ) . '</p>';
		}
		$out .= '<ul>';
		foreach ( $items as $item ) {
			$out .= '<li>' . esc_html( $item ) . '</li>';
		}

		return $out . '</ul></div>';
	}
}
