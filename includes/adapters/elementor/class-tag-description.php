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
 * The page's meta description.
 */
final class Tag_Description extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * The tag's name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'zinn-seo-description';
	}

	/**
	 * The tag's title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Meta description', 'zinn-seo' );
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
		echo esc_html( Resolver::description( Context::current() ) );
	}
}
