<?php
/**
 * The HTML sitemap as a block (stage P2's `[zinn_seo_sitemap]`, in every builder).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A page of links for people, paged by keyset ("More" links), listing what the XML sitemap lists.
 * Rendered by the sitemap module; offered only while that module is on.
 */
final class Html_Sitemap extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'html-sitemap';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'HTML sitemap', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'A page of links to everything the site lists for search engines, for people.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'networking';
	}

	/** {@inheritDoc} */
	public function offered(): bool {
		return class_exists( '\ZinnDigital\ZinnSEO\Sitemaps\Html' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'types'      => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Content types (comma-separated names; empty: all in the sitemap)', 'zinn-seo' ),
				'max'   => 300,
			),
			'per_type'   => array(
				'type'    => 'number',
				'label'   => static fn() => __( 'Links per section', 'zinn-seo' ),
				'default' => 50,
				'min'     => 1,
				'max'     => 500,
			),
			'taxonomies' => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Taxonomies to list (comma-separated names)', 'zinn-seo' ),
				'max'   => 300,
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
		if ( ! class_exists( '\ZinnDigital\ZinnSEO\Sitemaps\Html' ) ) {
			return '';
		}

		return \ZinnDigital\ZinnSEO\Sitemaps\Html::shortcode(
			array(
				'types'      => (string) $v['types'],
				'per_type'   => (string) (int) $v['per_type'],
				'taxonomies' => (string) $v['taxonomies'],
			)
		);
	}
}
