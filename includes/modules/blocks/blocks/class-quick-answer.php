<?php
/**
 * Quick Answer: a short, citable answer at the top of a post (zs-070, zs-inn-02).
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
 * The question the page answers and the answer in 40-60 words — the shape AI answers and featured
 * snippets quote. It joins the page's FAQ (or describes a Q&A page) and marks the answer as the
 * part a voice assistant should read (`speakable`). Pure HTML and CSS.
 */
final class Quick_Answer extends Block {

	/** The answer length search engines and AI answers quote best, in words. */
	public const IDEAL_MIN = 40;
	public const IDEAL_MAX = 60;

	/** {@inheritDoc} */
	public function name(): string {
		return 'quick-answer';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Quick Answer', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'The question this page answers, answered in 40 to 60 words: the shape search engines and AI assistants quote.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'format-chat';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'answer', 'zinn-seo' ), __( 'featured snippet', 'zinn-seo' ), 'AI', 'FAQ' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'question'  => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Question', 'zinn-seo' ),
				'content' => true,
				'max'     => 300,
			),
			'answer'    => array(
				'type'    => 'textarea',
				'label'   => static fn() => __( 'Answer', 'zinn-seo' ),
				'help'    => static fn() => __( 'Answer in the first sentence; 40 to 60 words in all.', 'zinn-seo' ),
				'content' => true,
				'max'     => 2000,
				'advise'  => array(
					'words' => array( self::IDEAL_MIN, self::IDEAL_MAX ),
				),
			),
			'level'     => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Question shown as', 'zinn-seo' ),
				'default' => 'h2',
				'choices' => array(
					'h2' => static fn() => __( 'Heading 2', 'zinn-seo' ),
					'h3' => static fn() => __( 'Heading 3', 'zinn-seo' ),
					'p'  => static fn() => __( 'Bold text', 'zinn-seo' ),
				),
			),
			'schema'    => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Structured data', 'zinn-seo' ),
				'default' => 'faq',
				'choices' => array(
					'faq'  => static fn() => __( 'Part of the page\'s FAQ', 'zinn-seo' ),
					'qa'   => static fn() => __( 'A question-and-answer page', 'zinn-seo' ),
					'none' => static fn() => __( 'None', 'zinn-seo' ),
				),
			),
			'speakable' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Let voice assistants read the answer aloud', 'zinn-seo' ),
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array(
			'question' => 'How long does a website migration take?',
			'answer'   => 'A typical website migration takes one to three days. Small sites move in an afternoon, while large shops with many orders need a planned cut-over at a quiet hour. Copy the files and database first, test the copy on a temporary address, then switch the domain when everything works.',
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
		if ( '' === $v['question'] && '' === $v['answer'] ) {
			return '';
		}
		$tag = in_array( $v['level'], array( 'h2', 'h3' ), true ) ? $v['level'] : 'p';
		$out = Html::open( 'section', Html::cls( 'qa' ) );
		if ( '' !== $v['question'] ) {
			$out .= Html::open( $tag, Html::cls( 'qa__q' ) ) . esc_html( $v['question'] ) . '</' . $tag . '>';
		}
		if ( '' !== $v['answer'] ) {
			$out .= Html::open( 'div', Html::cls( 'qa__a' ) ) . Html::paragraphs( $v['answer'] ) . '</div>';
		}

		return $out . '</section>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return array<string, mixed>
	 */
	public function schema( array $v, Render_Context $ctx ): array {
		$q = $v['question'];
		$a = Html::plain( $v['answer'] );
		if ( '' === $q || '' === $a ) {
			return array();
		}
		$out = array();
		if ( 'faq' === $v['schema'] ) {
			$out['faq'] = array(
				'@type'      => 'FAQPage',
				'@id'        => $ctx->id( 'faq' ),
				'mainEntity' => array( Faq::question( $q, $a ) ),
			);
		} elseif ( 'qa' === $v['schema'] ) {
			$answer = array(
				'@type' => 'Answer',
				'text'  => $a,
				'url'   => $ctx->id( 'answer-' . $ctx->instance ),
			);
			$author = $ctx->post instanceof \WP_Post ? (int) $ctx->post->post_author : 0;
			if ( $author > 0 ) {
				$answer['author'] = array( '@id' => \ZinnDigital\ZinnSEO\Blocks\People::id( $author ) );
			}
			$out['qa'] = array(
				'@type'      => 'QAPage',
				'@id'        => $ctx->id( 'qa-' . $ctx->instance ),
				'mainEntity' => array(
					'@type'          => 'Question',
					'name'           => $q,
					'text'           => $q,
					'answerCount'    => 1,
					'acceptedAnswer' => $answer,
				),
			);
		}
		if ( $v['speakable'] ) {
			$out['@page'] = array(
				'speakable' => array(
					'@type'       => 'SpeakableSpecification',
					'cssSelector' => array( '.' . Html::prefix() . '-qa__a' ),
				),
			);
		}

		return $out;
	}

	/**
	 * How the answer's length compares with what is quoted best (the editors show this).
	 *
	 * @param string $answer Answer.
	 * @return string short, ideal or long
	 */
	public static function length_verdict( string $answer ): string {
		$n = Html::words( $answer );
		if ( $n < self::IDEAL_MIN ) {
			return 'short';
		}

		return $n > self::IDEAL_MAX ? 'long' : 'ideal';
	}
}
