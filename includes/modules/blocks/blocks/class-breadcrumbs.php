<?php
/**
 * Breadcrumbs (the visible half of the graph's BreadcrumbList, zs-032).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;
use ZinnDigital\ZinnSEO\Blocks\Trail;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The trail a visitor sees, from the same function as the BreadcrumbList in the page's graph, so
 * the two always agree. It adds no structured data of its own (the graph already has the trail).
 * Placed in a theme template, a widget or a post; also `zinn_seo_breadcrumbs()` for theme files.
 */
final class Breadcrumbs extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'breadcrumbs';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Breadcrumbs', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'The path from the home page to this page, matching the breadcrumbs search engines read.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'arrow-right-alt2';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'trail', 'zinn-seo' ), __( 'navigation', 'zinn-seo' ) );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'home'      => array(
				'type'    => 'text',
				'label'   => static fn() => __( 'First item', 'zinn-seo' ),
				'default' => __( 'Home', 'zinn-seo' ),
				'content' => true,
				'max'     => 60,
			),
			'separator' => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Separator', 'zinn-seo' ),
				'default' => '›',
				'choices' => array(
					'›' => '›',
					'/' => '/',
					'»' => '»',
					'·' => '·',
				),
			),
			'show_last' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Show the current page', 'zinn-seo' ),
				'default' => true,
			),
			'show_home' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Show the home page', 'zinn-seo' ),
				'default' => true,
			),
			// The most steps shown (0: all). A longer trail keeps its first and last steps and
			// folds the middle into "…"; never fewer than 3 (zs-759).
			'max'       => array(
				'type'    => 'number',
				'label'   => static fn() => __( 'Most steps shown (0: all)', 'zinn-seo' ),
				'default' => 0,
				'min'     => 0,
				'max'     => 20,
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
		$c = ( did_action( 'wp' ) && ! $ctx->preview ) || ! $ctx->post instanceof \WP_Post ? Context::current() : Context::for_post( $ctx->post );

		$items = Trail::items( $c, (string) $v['home'] );
		if ( count( $items ) < 2 ) {
			return '';
		}
		if ( isset( $v['show_home'] ) && ! (bool) $v['show_home'] ) {
			array_shift( $items );
		}

		return self::html( self::shorten( $items, (int) ( $v['max'] ?? 0 ) ), (string) $v['separator'], (bool) $v['show_last'], (string) \ZinnDigital\ZinnSEO\Settings::get( 'crumb_prefix', '' ), 1 );
	}

	/**
	 * A trail of at most `$max` steps: the first step, "…", then the last steps (zs-759). `0`
	 * (or anything below 3, the least that still shows where a page sits) keeps every step.
	 * Pure, for the tests.
	 *
	 * @param array<int, array{name: string, url: string}> $items Trail.
	 * @param int                                          $max   Most steps.
	 * @return array<int, array<string, mixed>>
	 */
	public static function shorten( array $items, int $max ): array {
		$items = array_values( $items );
		if ( $max <= 0 || count( $items ) <= max( 3, $max ) ) {
			return $items;
		}
		$max = max( 3, $max );

		return array_merge(
			array( $items[0] ),
			array(
				array(
					'name' => '…',
					'url'  => '',
					'gap'  => true,
				),
			),
			array_slice( $items, -( $max - 2 ) )
		);
	}

	/**
	 * The trail's HTML.
	 *
	 * @param array<int, array{name: string, url: string}> $items     Trail.
	 * @param string                                       $separator Separator.
	 * @param bool                                         $show_last Show the current page.
	 * @param string                                       $prefix    Text before the trail ("You are here:").
	 * @param int                                          $least     The fewest steps worth showing (1 once Home is left out).
	 * @return string
	 */
	public static function html( array $items, string $separator, bool $show_last, string $prefix = '', int $least = 2 ): string {
		if ( count( $items ) < $least || array() === $items ) {
			return '';
		}
		if ( ! $show_last ) {
			array_pop( $items );
		}
		$out  = Html::open( 'nav', Html::cls( 'crumbs' ), array( 'aria-label' => __( 'Breadcrumb', 'zinn-seo' ) ) );
		$out .= '' !== trim( $prefix ) ? Html::open( 'span', Html::cls( 'crumbs__prefix' ) ) . esc_html( trim( $prefix ) ) . '</span> ' : '';
		$out .= '<ol>';
		$last = count( $items ) - 1;
		foreach ( $items as $i => $item ) {
			$out .= '<li>';
			if ( $i > 0 ) {
				$out .= Html::open( 'span', Html::cls( 'crumbs__sep' ), array( 'aria-hidden' => 'true' ) ) . esc_html( $separator ) . '</span>';
			}
			if ( ! empty( $item['gap'] ) ) {
				$out .= Html::open( 'span', Html::cls( 'crumbs__gap' ) ) . esc_html( (string) $item['name'] ) . '</span>';
			} elseif ( $i === $last && $show_last ) {
				$out .= Html::open( 'span', '', array( 'aria-current' => 'page' ) ) . esc_html( $item['name'] ) . '</span>';
			} elseif ( '' !== $item['url'] ) {
				$out .= Html::open( 'a', '', array( 'href' => $item['url'] ) ) . esc_html( $item['name'] ) . '</a>';
			} else {
				$out .= esc_html( $item['name'] );
			}
			$out .= '</li>';
		}

		return $out . '</ol></nav>';
	}
}
