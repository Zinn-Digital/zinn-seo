<?php
/**
 * How-to block (zs-072).
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
 * Numbered steps with the time, cost, supplies and tools, as HowTo structured data. Each step has
 * its own anchor (`#step-2`), so a link can open the page at that step. Steps Page Builder
 * Sandwich already offers (its Steps block) win while it is active.
 */
final class Howto extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'howto';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'How-to', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Step-by-step instructions with time, cost, supplies and tools, as How-to structured data.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'list-view';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'steps', 'zinn-seo' ), __( 'guide', 'zinn-seo' ), __( 'instructions', 'zinn-seo' ), 'HowTo' );
	}

	/** {@inheritDoc} */
	public function offered(): bool {
		return ! function_exists( 'pbsw_register_block' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'name'        => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'What it shows how to do', 'zinn-seo' ),
				'content' => true,
				'max'     => 200,
			),
			'description' => array(
				'type'    => 'textarea',
				'label'   => static fn() => __( 'Introduction', 'zinn-seo' ),
				'content' => true,
				'max'     => 2000,
			),
			'minutes'     => array(
				'type'  => 'number',
				'label' => static fn() => __( 'Total time (minutes)', 'zinn-seo' ),
				'min'   => 0,
				'max'   => 525600,
			),
			'cost'        => array(
				'type'  => 'number',
				'label' => static fn() => __( 'Estimated cost', 'zinn-seo' ),
				'min'   => 0,
				'step'  => 0.01,
			),
			'currency'    => array(
				'type'        => 'text',
				'label'       => static fn() => __( 'Currency (three letters, e.g. USD)', 'zinn-seo' ),
				'max'         => 3,
				'placeholder' => 'USD',
			),
			'supplies'    => array(
				'type'    => 'list',
				'label'   => static fn() => __( 'Supplies (one per line)', 'zinn-seo' ),
				'content' => true,
			),
			'tools'       => array(
				'type'    => 'list',
				'label'   => static fn() => __( 'Tools (one per line)', 'zinn-seo' ),
				'content' => true,
			),
			'steps'       => array(
				'type'   => 'repeater',
				'label'  => static fn() => __( 'Steps', 'zinn-seo' ),
				'item'   => static fn() => __( 'Step', 'zinn-seo' ),
				'fields' => array(
					'name'  => array(
						'type'    => 'text',
						'label'   => static fn() => __( 'Step title', 'zinn-seo' ),
						'content' => true,
						'max'     => 200,
					),
					'text'  => array(
						'type'    => 'textarea',
						'label'   => static fn() => __( 'What to do', 'zinn-seo' ),
						'content' => true,
						'max'     => 5000,
					),
					'image' => array(
						'type'  => 'image',
						'label' => static fn() => __( 'Picture', 'zinn-seo' ),
					),
				),
			),
			'schema'      => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'How-to structured data', 'zinn-seo' ),
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array(
			'name'    => 'How to repot a houseplant',
			'minutes' => 20,
			'tools'   => array( 'Trowel' ),
			'steps'   => array(
				array(
					'name' => 'Water the plant',
					'text' => 'Water it the day before so the roots slide out.',
				),
				array(
					'name' => 'Move it',
					'text' => 'Lift the plant into a pot one size larger and fill around it with fresh soil.',
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
		if ( array() === $v['steps'] ) {
			return '';
		}
		$out = Html::open( 'div', Html::cls( 'howto' ) );
		if ( '' !== $v['description'] ) {
			$out .= Html::open( 'div', Html::cls( 'howto__intro' ) ) . Html::paragraphs( $v['description'] ) . '</div>';
		}
		$facts = array();
		if ( $v['minutes'] > 0 ) {
			$facts[] = array( __( 'Time', 'zinn-seo' ), self::duration_text( (int) $v['minutes'] ) );
		}
		$currency = self::currency( $v['currency'] );
		if ( $v['cost'] > 0 && '' !== $currency ) {
			$facts[] = array( __( 'Cost', 'zinn-seo' ), number_format_i18n( (float) $v['cost'], floor( (float) $v['cost'] ) === (float) $v['cost'] ? 0 : 2 ) . ' ' . $currency );
		}
		if ( array() !== $facts ) {
			$out .= Html::open( 'dl', Html::cls( 'howto__facts' ) );
			foreach ( $facts as $f ) {
				$out .= '<div><dt>' . esc_html( $f[0] ) . '</dt><dd>' . esc_html( $f[1] ) . '</dd></div>';
			}
			$out .= '</dl>';
		}
		foreach ( array(
			'supplies' => __( 'You need', 'zinn-seo' ),
			'tools'    => __( 'Tools', 'zinn-seo' ),
		) as $key => $label ) {
			if ( array() !== $v[ $key ] ) {
				$out .= Html::open( 'div', Html::cls( 'howto__needs' ) ) . Html::open( 'p', Html::cls( 'howto__label' ) ) . esc_html( $label ) . '</p><ul>';
				foreach ( $v[ $key ] as $item ) {
					$out .= '<li>' . esc_html( $item ) . '</li>';
				}
				$out .= '</ul></div>';
			}
		}
		$out .= Html::open( 'ol', Html::cls( 'howto__steps' ) );
		foreach ( $v['steps'] as $i => $step ) {
			$out .= Html::open( 'li', Html::cls( 'howto__step' ), array( 'id' => self::anchor( $ctx, $i ) ) );
			if ( '' !== $step['name'] ) {
				$out .= Html::open( 'p', Html::cls( 'howto__name' ) ) . esc_html( $step['name'] ) . '</p>';
			}
			$out .= Html::paragraphs( $step['text'] );
			if ( $step['image'] > 0 ) {
				$out .= (string) wp_get_attachment_image(
					(int) $step['image'],
					'large',
					false,
					array(
						'loading'  => 'lazy',
						'decoding' => 'async',
					)
				);
			}
			$out .= '</li>';
		}

		return $out . '</ol></div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return array<string, mixed>
	 */
	public function schema( array $v, Render_Context $ctx ): array {
		if ( ! $v['schema'] || array() === $v['steps'] ) {
			return array();
		}
		$node = array(
			'@type' => 'HowTo',
			'@id'   => $ctx->id( 'howto-' . $ctx->instance ),
			'name'  => '' !== $v['name'] ? $v['name'] : $ctx->title(),
		);
		if ( '' !== $v['description'] ) {
			$node['description'] = Html::plain( $v['description'] );
		}
		if ( $v['minutes'] > 0 ) {
			$node['totalTime'] = self::iso_duration( (int) $v['minutes'] );
		}
		$currency = self::currency( $v['currency'] );
		if ( $v['cost'] > 0 && '' !== $currency ) {
			$node['estimatedCost'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $currency,
				'value'    => $v['cost'],
			);
		}
		foreach ( array(
			'supplies' => array( 'supply', 'HowToSupply' ),
			'tools'    => array( 'tool', 'HowToTool' ),
		) as $key => $map ) {
			foreach ( $v[ $key ] as $item ) {
				$node[ $map[0] ][] = array(
					'@type' => $map[1],
					'name'  => $item,
				);
			}
		}
		foreach ( $v['steps'] as $i => $step ) {
			$text = Html::plain( $step['text'] );
			$s    = array(
				'@type'    => 'HowToStep',
				'position' => $i + 1,
				'text'     => '' !== $text ? $text : $step['name'],
			);
			if ( '' !== $step['name'] ) {
				$s['name'] = $step['name'];
			}
			if ( '' !== $ctx->url ) {
				$s['url'] = $ctx->id( self::anchor( $ctx, $i ) );
			}
			if ( $step['image'] > 0 ) {
				$img = wp_get_attachment_image_url( (int) $step['image'], 'large' );
				if ( is_string( $img ) ) {
					$s['image'] = $img;
				}
			}
			$node['step'][] = $s;
		}

		return array( 'howto' => $node );
	}

	/**
	 * A step's anchor: `step-3`, or `step-2-3` for the second how-to on a page.
	 *
	 * @param Render_Context $ctx Where.
	 * @param int            $i   Step index.
	 * @return string
	 */
	private static function anchor( Render_Context $ctx, int $i ): string {
		return 'step-' . ( $ctx->instance > 1 ? $ctx->instance . '-' : '' ) . ( $i + 1 );
	}

	/**
	 * An ISO 8601 duration from minutes (PT1H30M).
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public static function iso_duration( int $minutes ): string {
		$d = intdiv( $minutes, 1440 );
		$h = intdiv( $minutes % 1440, 60 );
		$m = $minutes % 60;

		return 'P' . ( $d > 0 ? $d . 'D' : '' ) . ( $h > 0 || $m > 0 ? 'T' . ( $h > 0 ? $h . 'H' : '' ) . ( $m > 0 ? $m . 'M' : '' ) : ( $d > 0 ? '' : 'T0M' ) );
	}

	/**
	 * Minutes for a reader: "1 hour 30 minutes".
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public static function duration_text( int $minutes ): string {
		$h = intdiv( $minutes, 60 );
		$m = $minutes % 60;
		/* translators: %s: a number of hours. */
		$hours = $h > 0 ? sprintf( _n( '%s hour', '%s hours', $h, 'zinn-seo' ), number_format_i18n( $h ) ) : '';
		/* translators: %s: a number of minutes. */
		$mins = $m > 0 || 0 === $h ? sprintf( _n( '%s minute', '%s minutes', $m, 'zinn-seo' ), number_format_i18n( $m ) ) : '';

		return trim( $hours . ' ' . $mins );
	}

	/**
	 * A currency code, or '' when it is not three letters.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function currency( string $code ): string {
		$code = strtoupper( trim( $code ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
	}
}
