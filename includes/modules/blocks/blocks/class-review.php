<?php
/**
 * Review box (zs-304).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\People;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A scored review of a product or service: the score, the criteria behind it and the verdict, as a
 * Review with its rating.
 *
 * ⛔ Self-serving reviews. Google ignores review stars a business puts on its OWN business or
 * organisation (and may act against them). When the reviewed item is a business or organisation
 * named like this site's own, or the writer ticks "this is about us", the box still shows but no
 * review structured data is printed, and the editors say why (zs-304).
 */
final class Review extends Block {

	/** Types Google shows review snippets for: schema.org type => label. */
	public static function item_types(): array {
		return array(
			'Product'             => __( 'Product', 'zinn-seo' ),
			'SoftwareApplication' => __( 'Software or app', 'zinn-seo' ),
			'Book'                => __( 'Book', 'zinn-seo' ),
			'Course'              => __( 'Course', 'zinn-seo' ),
			'Movie'               => __( 'Film', 'zinn-seo' ),
			'Game'                => __( 'Game', 'zinn-seo' ),
			'Recipe'              => __( 'Recipe', 'zinn-seo' ),
			'Event'               => __( 'Event', 'zinn-seo' ),
			'CreativeWorkSeason'  => __( 'TV or podcast season', 'zinn-seo' ),
			'MusicRecording'      => __( 'Music', 'zinn-seo' ),
			'LocalBusiness'       => __( 'Local business', 'zinn-seo' ),
			'Organization'        => __( 'Organisation', 'zinn-seo' ),
		);
	}

	/** {@inheritDoc} */
	public function name(): string {
		return 'review';
	}

	/**
	 * The `@id` of whoever runs the site (the schema module's, or the default organisation).
	 *
	 * @return string
	 */
	public static function publisher(): string {
		return class_exists( '\ZinnDigital\ZinnSEO\Schema\Schema' ) ? \ZinnDigital\ZinnSEO\Schema\Schema::publisher_id() : home_url( '/' ) . '#organization';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Review box', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'A scored review with criteria and a verdict, as review structured data, guarded against self-serving reviews.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'star-filled';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'rating', 'zinn-seo' ), __( 'score', 'zinn-seo' ), __( 'stars', 'zinn-seo' ) );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		$types = array();
		foreach ( self::item_types() as $type => $label ) {
			$types[ $type ] = $label;
		}

		return array(
			'item'      => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'What is reviewed', 'zinn-seo' ),
				'content' => true,
				'max'     => 200,
			),
			'item_type' => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'It is a', 'zinn-seo' ),
				'default' => 'Product',
				'choices' => $types,
			),
			'brand'     => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Brand or maker', 'zinn-seo' ),
				'max'   => 120,
			),
			'image'     => array(
				'type'  => 'image',
				'label' => static fn() => __( 'Picture', 'zinn-seo' ),
			),
			'url'       => array(
				'type'  => 'url',
				'label' => static fn() => __( 'Its official page', 'zinn-seo' ),
			),
			'scale'     => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Scored out of', 'zinn-seo' ),
				'default' => '5',
				'choices' => array(
					'5'   => '5',
					'10'  => '10',
					'100' => '100',
				),
			),
			'criteria'  => array(
				'type'   => 'repeater',
				'label'  => static fn() => __( 'Criteria', 'zinn-seo' ),
				'item'   => static fn() => __( 'Criterion', 'zinn-seo' ),
				'fields' => array(
					'label' => array(
						'type'    => 'text',
						'label'   => static fn() => __( 'Criterion', 'zinn-seo' ),
						'content' => true,
						'max'     => 80,
					),
					'score' => array(
						'type'  => 'number',
						'label' => static fn() => __( 'Score', 'zinn-seo' ),
						'min'   => 0,
						'max'   => 100,
						'step'  => 0.1,
					),
				),
			),
			'score'     => array(
				'type'  => 'number',
				'label' => static fn() => __( 'Overall score (empty: the average of the criteria)', 'zinn-seo' ),
				'min'   => 0,
				'max'   => 100,
				'step'  => 0.1,
			),
			'summary'   => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'Headline verdict', 'zinn-seo' ),
				'content' => true,
				'max'     => 200,
			),
			'verdict'   => array(
				'type'    => 'textarea',
				'label'   => static fn() => __( 'Verdict', 'zinn-seo' ),
				'content' => true,
				'max'     => 5000,
			),
			'reviewer'  => array(
				'type'  => 'user',
				'label' => static fn() => __( 'Reviewed by (empty: the author)', 'zinn-seo' ),
			),
			'about_us'  => array(
				'type'  => 'bool',
				'label' => static fn() => __( 'This reviews our own business', 'zinn-seo' ),
			),
			'agg_value' => array(
				'type'  => 'number',
				'label' => static fn() => __( 'Average rating from customers (optional)', 'zinn-seo' ),
				'min'   => 0,
				'max'   => 100,
				'step'  => 0.1,
			),
			'agg_count' => array(
				'type'  => 'number',
				'label' => static fn() => __( 'Number of customer ratings', 'zinn-seo' ),
				'min'   => 0,
			),
			'schema'    => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Review structured data', 'zinn-seo' ),
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array(
			'item'     => 'Acme Kettle',
			'criteria' => array(
				array(
					'label' => 'Speed',
					'score' => 4.5,
				),
				array(
					'label' => 'Build',
					'score' => 4,
				),
			),
			'summary'  => 'Fast and quiet, with one small flaw.',
		);
	}

	/**
	 * The overall score: the one given, or the criteria's average, within the scale.
	 *
	 * @param array<string, mixed> $v Values.
	 * @return float
	 */
	public static function overall( array $v ): float {
		$scale = (float) $v['scale'];
		$score = (float) $v['score'];
		if ( $score <= 0 && array() !== $v['criteria'] ) {
			$sum = 0.0;
			foreach ( $v['criteria'] as $c ) {
				$sum += (float) $c['score'];
			}
			$score = round( $sum / count( $v['criteria'] ), 1 );
		}

		return max( 0.0, min( $scale, $score ) );
	}

	/**
	 * Is this a review of the site's own business (which Google ignores)?
	 *
	 * @param array<string, mixed> $v Values.
	 * @return bool
	 */
	public static function self_serving( array $v ): bool {
		if ( $v['about_us'] ) {
			return true;
		}
		if ( ! in_array( $v['item_type'], array( 'LocalBusiness', 'Organization' ), true ) ) {
			return false;
		}
		$ours = array_filter(
			array(
				strtolower( Html::plain( (string) Settings::get( 'identity_name', '' ) ) ),
				strtolower( Html::plain( (string) get_bloginfo( 'name' ) ) ),
			)
		);
		$item = strtolower( Html::plain( $v['item'] ) );

		return '' === $item || in_array( $item, $ours, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	public function render( array $v, Render_Context $ctx ): string {
		$scale   = (float) $v['scale'];
		$overall = self::overall( $v );
		if ( '' === $v['item'] && $overall <= 0 && '' === $v['verdict'] ) {
			return '';
		}
		$fmt  = static fn( float $n ): string => number_format_i18n( $n, floor( $n ) === $n ? 0 : 1 );
		$out  = Html::open( 'div', Html::cls( 'review' ) );
		$out .= Html::open( 'div', Html::cls( 'review__head' ) );
		if ( $v['image'] > 0 ) {
			$out .= (string) wp_get_attachment_image(
				(int) $v['image'],
				'thumbnail',
				false,
				array(
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
		}
		$out .= '<div>';
		if ( '' !== $v['item'] ) {
			$out .= Html::open( 'p', Html::cls( 'review__item' ) ) . esc_html( $v['item'] ) . '</p>';
		}
		if ( '' !== $v['summary'] ) {
			$out .= Html::open( 'p', Html::cls( 'review__summary' ) ) . esc_html( $v['summary'] ) . '</p>';
		}
		$out .= '</div>';
		if ( $overall > 0 ) {
			/* translators: 1: a score, 2: the most it could be. */
			$label = sprintf( __( '%1$s out of %2$s', 'zinn-seo' ), $fmt( $overall ), $fmt( $scale ) );
			$out  .= Html::open( 'p', Html::cls( 'review__score' ), array( 'aria-label' => $label ) ) . '<strong>' . esc_html( $fmt( $overall ) ) . '</strong><span>/' . esc_html( $fmt( $scale ) ) . '</span></p>';
		}
		$out .= '</div>';
		if ( array() !== $v['criteria'] ) {
			$out .= Html::open( 'ul', Html::cls( 'review__criteria' ) );
			foreach ( $v['criteria'] as $c ) {
				$pct  = $scale > 0 ? (int) round( min( $scale, (float) $c['score'] ) / $scale * 100 ) : 0;
				$out .= '<li><span>' . esc_html( $c['label'] ) . '</span>'
					. Html::open( 'span', Html::cls( 'review__bar' ), array( 'style' => '--zd-w:' . $pct . '%' ) ) . '</span>'
					. '<span>' . esc_html( $fmt( (float) $c['score'] ) ) . '</span></li>';
			}
			$out .= '</ul>';
		}
		if ( '' !== $v['verdict'] ) {
			$out .= Html::open( 'div', Html::cls( 'review__verdict' ) ) . Html::paragraphs( $v['verdict'] ) . '</div>';
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
		$overall = self::overall( $v );
		if ( ! $v['schema'] || self::self_serving( $v ) || $overall <= 0 ) {
			return array();
		}
		$name = '' !== $v['item'] ? $v['item'] : $ctx->title();
		$item = array(
			'@type' => $v['item_type'],
			'name'  => $name,
		);
		if ( '' !== $v['brand'] && 'Product' === $v['item_type'] ) {
			$item['brand'] = array(
				'@type' => 'Brand',
				'name'  => $v['brand'],
			);
		}
		if ( $v['image'] > 0 ) {
			$img = wp_get_attachment_image_url( (int) $v['image'], 'large' );
			if ( is_string( $img ) ) {
				$item['image'] = $img;
			}
		}
		if ( '' !== $v['url'] ) {
			$item['url'] = $v['url'];
		}
		if ( $v['agg_count'] > 0 && $v['agg_value'] > 0 ) {
			$item['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => min( (float) $v['scale'], (float) $v['agg_value'] ),
				'bestRating'  => (float) $v['scale'],
				'worstRating' => 0,
				'ratingCount' => (int) $v['agg_count'],
			);
		}
		// The reviewed thing is its own node that carries its review: Google reads a Product
		// (or software, book…) as a product snippet, which needs a review ON the product —
		// found by Google's Rich Results Test 2026-10-09 ("Product snippets: invalid item").
		$item['@id']    = $ctx->id( 'item-' . $ctx->instance );
		$item['review'] = array( '@id' => $ctx->id( 'review-' . $ctx->instance ) );
		$review         = array(
			'@type'        => 'Review',
			'@id'          => $ctx->id( 'review-' . $ctx->instance ),
			'name'         => '' !== $v['summary'] ? $v['summary'] : $name,
			'itemReviewed' => array( '@id' => $item['@id'] ),
			'reviewRating' => array(
				'@type'       => 'Rating',
				'ratingValue' => $overall,
				'bestRating'  => (float) $v['scale'],
				'worstRating' => 0,
			),
		);
		$out            = array();
		$by             = $v['reviewer'] > 0 ? (int) $v['reviewer'] : ( $ctx->post instanceof \WP_Post ? (int) $ctx->post->post_author : 0 );
		$who            = $by > 0 ? People::node( $by ) : array();
		if ( array() !== $who ) {
			// One Person node per person in the graph; the review points at it.
			$review['author'] = array( '@id' => $who['@id'] );
			$out['person']    = $who;
		} else {
			// No person: the site wrote it (Google requires an author).
			$review['author'] = array( '@id' => self::publisher() );
		}
		if ( '' !== $v['verdict'] ) {
			$review['reviewBody'] = Html::plain( $v['verdict'] );
		}
		if ( $ctx->post instanceof \WP_Post ) {
			$review['datePublished'] = (string) get_post_time( 'c', true, $ctx->post );
		}

		$out['review'] = $review;
		$out['item']   = $item;

		return $out;
	}
}
