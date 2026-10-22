<?php
/**
 * FAQ block (zs-072).
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
 * Questions and answers, open/close with no script (`<details>`), joined into the page's ONE
 * FAQPage. When Page Builder Sandwich is active its own FAQ (accordion) is offered instead and
 * this block leaves the inserters; FAQ blocks already on pages keep rendering.
 */
final class Faq extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'faq';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'FAQ', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Frequently asked questions that open and close without any script, with FAQ structured data.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'editor-help';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'questions', 'zinn-seo' ), __( 'accordion', 'zinn-seo' ), 'FAQPage' );
	}

	/** {@inheritDoc} */
	public function offered(): bool {
		return ! function_exists( 'pbsw_register_block' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'items'       => array(
				'type'   => 'repeater',
				'label'  => static fn() => __( 'Questions', 'zinn-seo' ),
				'item'   => static fn() => __( 'Question', 'zinn-seo' ),
				'fields' => array(
					'question' => array(
						'type'    => 'text',
						'label'   => static fn() => __( 'Question', 'zinn-seo' ),
						'content' => true,
						'max'     => 300,
					),
					'answer'   => array(
						'type'    => 'textarea',
						'label'   => static fn() => __( 'Answer', 'zinn-seo' ),
						'content' => true,
						'max'     => 5000,
					),
				),
			),
			'collapsible' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Answers open and close', 'zinn-seo' ),
				'default' => true,
			),
			'open_first'  => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'First answer open', 'zinn-seo' ),
				'default' => false,
			),
			'level'       => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Questions shown as', 'zinn-seo' ),
				'default' => 'h3',
				'choices' => array(
					'h2' => static fn() => __( 'Heading 2', 'zinn-seo' ),
					'h3' => static fn() => __( 'Heading 3', 'zinn-seo' ),
					'h4' => static fn() => __( 'Heading 4', 'zinn-seo' ),
					'p'  => static fn() => __( 'Bold text', 'zinn-seo' ),
				),
			),
			'schema'      => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'FAQ structured data', 'zinn-seo' ),
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array(
			'items' => array(
				array(
					'question' => 'Do you offer refunds?',
					'answer'   => 'Yes. Ask within 14 days of buying and we refund the full price.',
				),
				array(
					'question' => 'Can I change plans later?',
					'answer'   => 'Yes, at any time. The difference is worked out to the day.',
				),
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
		if ( array() === $v['items'] ) {
			return '';
		}
		$tag = in_array( $v['level'], array( 'h2', 'h3', 'h4' ), true ) ? $v['level'] : 'p';
		$out = Html::open( 'div', Html::cls( 'faq' ) );
		foreach ( $v['items'] as $i => $item ) {
			$q = '' !== $item['question'] ? esc_html( $item['question'] ) : '';
			$a = Html::paragraphs( $item['answer'] );
			if ( $v['collapsible'] ) {
				$out .= Html::open( 'details', Html::cls( 'faq__item' ), ( 0 === $i && $v['open_first'] ) ? array( 'open' => 'open' ) : array() )
					. Html::open( 'summary', Html::cls( 'faq__q' ) ) . Html::open( $tag, Html::cls( 'faq__qh' ) ) . $q . '</' . $tag . '></summary>'
					. Html::open( 'div', Html::cls( 'faq__a' ) ) . $a . '</div></details>';
			} else {
				$out .= Html::open( 'div', Html::cls( 'faq__item' ) )
					. Html::open( $tag, Html::cls( 'faq__q' ) ) . $q . '</' . $tag . '>'
					. Html::open( 'div', Html::cls( 'faq__a' ) ) . $a . '</div></div>';
			}
		}

		return $out . '</div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return array<string, mixed>
	 */
	public function schema( array $v, Render_Context $ctx ): array {
		if ( ! $v['schema'] ) {
			return array();
		}
		$questions = array();
		foreach ( $v['items'] as $item ) {
			$a = Html::plain( $item['answer'] );
			if ( '' !== $item['question'] && '' !== $a ) {
				$questions[] = self::question( $item['question'], $a );
			}
		}

		return array() === $questions ? array() : array(
			'faq' => array(
				'@type'      => 'FAQPage',
				'@id'        => $ctx->id( 'faq' ),
				'mainEntity' => $questions,
			),
		);
	}

	/**
	 * A Question node with its accepted answer.
	 *
	 * @param string $question Question.
	 * @param string $answer   Answer (plain text).
	 * @return array<string, mixed>
	 */
	public static function question( string $question, string $answer ): array {
		return array(
			'@type'          => 'Question',
			'name'           => $question,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $answer,
			),
		);
	}
}
