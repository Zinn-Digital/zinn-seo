<?php
/**
 * Elementor dynamic tags: SEO title, meta description, breadcrumbs (zs-313).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Adapters\Elementor;

use ZinnDigital\ZinnSEO\Adapters\Elementor as Adapter;
use ZinnDigital\ZinnSEO\Blocks\Trail;
use ZinnDigital\ZinnSEO\Blocks\Types\Breadcrumbs;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The breadcrumb trail (the same trail as the graph's BreadcrumbList).
 */
final class Tag_Breadcrumbs extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * The tag's name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'zinn-seo-breadcrumbs';
	}

	/**
	 * The tag's title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Breadcrumbs', 'zinn-seo' );
	}

	/**
	 * The group it is listed under.
	 *
	 * @return string
	 */
	public function get_group() {
		return Adapter::CATEGORY;
	}

	/**
	 * The kinds of field it fills.
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}

	/**
	 * Print the value.
	 *
	 * @return void
	 */
	public function render() {
		$block = \ZinnDigital\ZinnSEO\Blocks\Library::get( 'breadcrumbs' );
		if ( null !== $block ) {
			\ZinnDigital\ZinnSEO\Blocks\Assets::enqueue( $block );
		}
		echo Breadcrumbs::html( Trail::items( Context::current() ), '›', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html/esc_url per item.
	}
}
