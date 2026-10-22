<?php
/**
 * One Elementor widget class for every SEO block (zs-300).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Adapters\Elementor;

use ZinnDigital\ZinnSEO\Adapters\Elementor as Adapter;
use ZinnDigital\ZinnSEO\Adapters\Gutenberg;
use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Library;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor needs one registered widget per block; they all share this class. The block a widget
 * stands for comes from the arguments it was registered with (the widget's type) or, for an
 * instance Elementor builds from saved data, from that data's `widgetType` — so custom blocks
 * made on the site appear as widgets with no class of their own. Its controls are generated from
 * the block's fields and its output is the one renderer's.
 */
class Widget extends \Elementor\Widget_Base {

	/**
	 * The block this widget stands for.
	 *
	 * @return Block|null
	 */
	public function block(): ?Block {
		$args = method_exists( $this, 'get_default_args' ) ? (array) $this->get_default_args() : array();
		$name = (string) ( $args['zseo_block'] ?? '' );
		if ( '' === $name ) {
			$type = (string) $this->get_data( 'widgetType' );
			$name = 0 === strpos( $type, Adapter::PREFIX ) ? substr( $type, strlen( Adapter::PREFIX ) ) : '';
		}

		return '' === $name ? null : Library::get( $name );
	}

	/**
	 * The widget's name.
	 *
	 * @return string
	 */
	public function get_name() {
		$block = $this->block();

		return null === $block ? 'zinn-seo-unknown' : Adapter::PREFIX . $block->name();
	}

	/**
	 * The title in Elementor's panel.
	 *
	 * @return string
	 */
	public function get_title() {
		$block = $this->block();

		return null === $block ? '' : $block->title();
	}

	/**
	 * The icon (Elementor's icon font).
	 *
	 * @return string
	 */
	public function get_icon() {
		$block = $this->block();

		return Adapter::icon( null === $block ? '' : $block->icon() );
	}

	/**
	 * The panel category.
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( Adapter::CATEGORY );
	}

	/**
	 * Search words.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords() {
		$block = $this->block();

		return null === $block ? array() : array_merge( array( 'seo' ), $block->keywords() );
	}

	/**
	 * Hidden from the panel when the block is not offered (existing widgets still render).
	 *
	 * @return bool
	 */
	public function show_in_panel() {
		$block = $this->block();

		return null !== $block && $block->offered();
	}

	/**
	 * Controls from the block's fields.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$block = $this->block();
		if ( null === $block ) {
			return;
		}
		$this->start_controls_section(
			'zseo_content',
			array(
				'label' => $block->title(),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		foreach ( $block->fields() as $key => $def ) {
			$this->add_control( (string) $key, Adapter::control( (array) $def ) );
		}
		$this->end_controls_section();
	}

	/**
	 * Print the block.
	 *
	 * @return void
	 */
	protected function render() {
		$block = $this->block();
		if ( null === $block ) {
			return;
		}
		$ctx = Gutenberg::context( null, 'elementor' );
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$ctx->preview = true;
		}
		echo Library::render( $block->name(), Adapter::values( $block, (array) $this->get_settings_for_display() ), $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the one renderer escapes every value it prints.
	}
}
