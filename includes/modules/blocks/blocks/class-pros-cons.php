<?php
/**
 * Pros and cons (zs-303).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\People;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two lists — good and bad — as Google's "pros and cons" for editorial reviews: `positiveNotes`
 * and `negativeNotes` on a Review. When the page also has a review box, the notes join THAT
 * review (one review of one thing); otherwise the block describes its own Product review.
 */
final class Pros_Cons extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'pros-cons';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Pros and cons', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'What is good and what is not, side by side, as the pros and cons search engines show for reviews.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'thumbs-up';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'review', 'zinn-seo' ), __( 'advantages', 'zinn-seo' ), __( 'disadvantages', 'zinn-seo' ) );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'item'       => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'What is reviewed (empty: the page title)', 'zinn-seo' ),
				'content' => true,
				'max'     => 200,
			),
			'pros_title' => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Pros title', 'zinn-seo' ),
				'default' => __( 'Pros', 'zinn-seo' ),
				'content' => true,
				'max'     => 80,
			),
			'pros'       => array(
				'type'    => 'list',
				'label'   => static fn() => __( 'Pros (one per line)', 'zinn-seo' ),
				'content' => true,
				'max'     => 300,
			),
			'cons_title' => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Cons title', 'zinn-seo' ),
				'default' => __( 'Cons', 'zinn-seo' ),
				'content' => true,
				'max'     => 80,
			),
			'cons'       => array(
				'type'    => 'list',
				'label'   => static fn() => __( 'Cons (one per line)', 'zinn-seo' ),
				'content' => true,
				'max'     => 300,
			),
			'schema'     => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Pros and cons structured data', 'zinn-seo' ),
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array(
			'item' => 'Acme Kettle',
			'pros' => array( 'Boils a litre in two minutes', 'Quiet' ),
			'cons' => array( 'Short cable' ),
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
		if ( array() === $v['pros'] && array() === $v['cons'] ) {
			return '';
		}
		$out = Html::open( 'div', Html::cls( 'pc' ) );
		foreach ( array( 'pros', 'cons' ) as $side ) {
			if ( array() === $v[ $side ] ) {
				continue;
			}
			$out .= Html::open( 'div', Html::cls( 'pc__col', 'pc__col--' . $side ) )
				. Html::open( 'p', Html::cls( 'pc__title' ) ) . esc_html( $v[ $side . '_title' ] ) . '</p><ul>';
			foreach ( $v[ $side ] as $item ) {
				$out .= '<li>' . esc_html( $item ) . '</li>';
			}
			$out .= '</ul></div>';
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
		if ( ! $v['schema'] || ( array() === $v['pros'] && array() === $v['cons'] ) ) {
			return array();
		}
		$notes            = self::notes( $v['pros'], $v['cons'] );
		$name             = '' !== $v['item'] ? $v['item'] : $ctx->title();
		$review           = array_merge(
			array(
				'@type' => 'Review',
				'name'  => $name,
			),
			$notes
		);
		$author           = $ctx->post instanceof \WP_Post ? (int) $ctx->post->post_author : 0;
		$review['author'] = array( '@id' => $author > 0 ? People::id( $author ) : Review::publisher() );

		// `@notes` is read by the graph: with a review box on the page the notes join it and this
		// Product is dropped; on its own it stays (Google's pattern for editorial pros and cons).
		$out = array();
		if ( $author > 0 ) {
			$out['person'] = People::node( $author );
		}

		return $out + array(
			'@notes'   => $notes,
			'proscons' => array(
				'@type'  => 'Product',
				'@id'    => $ctx->id( 'product-' . $ctx->instance ),
				'name'   => $name,
				'review' => $review,
			),
		);
	}

	/**
	 * `positiveNotes` / `negativeNotes` as ItemLists.
	 *
	 * @param array<int, string> $pros Pros.
	 * @param array<int, string> $cons Cons.
	 * @return array<string, mixed>
	 */
	public static function notes( array $pros, array $cons ): array {
		$out = array();
		foreach ( array(
			'positiveNotes' => $pros,
			'negativeNotes' => $cons,
		) as $prop => $items ) {
			if ( array() === $items ) {
				continue;
			}
			$list = array();
			foreach ( array_values( $items ) as $i => $item ) {
				$list[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $item,
				);
			}
			$out[ $prop ] = array(
				'@type'           => 'ItemList',
				'itemListElement' => $list,
			);
		}

		return $out;
	}
}
