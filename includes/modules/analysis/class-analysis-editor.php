<?php
/**
 * The analysis in the editors: block editor sidebar, classic editor box, Elementor's panel.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Head\Editor;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - Block editor (and Page Builder Sandwich, which builds inside it): the "Zinn® SEO" sidebar —
 *   focus phrases, the search-result preview (desktop and mobile), the scores and every check,
 *   internal link suggestions — from the editor bundle, which the head module already loads.
 * - Classic editor: the same panel in an "SEO analysis" box, its focus phrases and cornerstone
 *   saved with the post.
 * - Elementor: Page settings → SEO gets the same checks (the Elementor bundle draws them), run on
 *   what Elementor renders.
 */
final class Analysis_Editor {

	/** Nonce action of the classic box. */
	private const NONCE = 'zseo_analysis_box';

	/**
	 * Hooks (admin).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'zinn_seo_editor_data', array( self::class, 'editor_data' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 15, 2 );
		add_action( 'save_post', array( self::class, 'save' ), 20, 2 );
	}

	/**
	 * Boot data for the editors' panels.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	public static function editor_data( $data ): array {
		$data             = (array) $data;
		$data['analysis'] = array(
			'phrasesMax'  => Analysis::phrase_limit(),
			'readability' => \ZinnDigital\ZinnSEO\Modules::enabled( 'readability' ),
			'siteName'    => (string) get_bloginfo( 'name' ),
			'siteIcon'    => (string) get_site_icon_url( 32 ),
			'home'        => (string) home_url( '/' ),
			'types'       => Analysis::types(),
		);

		return $data;
	}

	/**
	 * Classic editor: the analysis box (where the block editor is not used).
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 * @return void
	 */
	public static function add( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! $post instanceof \WP_Post || ! in_array( $post_type, Analysis::types(), true ) ) {
			return;
		}
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}
		if ( ! Capabilities::can_edit_post( (int) $post->ID ) || ! is_readable( ZINN_SEO_DIR . 'build/classic.asset.php' ) ) {
			return;
		}
		add_meta_box( 'zinn-seo-analysis', __( 'SEO analysis', 'zinn-seo' ), array( self::class, 'render' ), $post_type, 'normal', 'high' );
		self::enqueue_classic();
	}

	/**
	 * The classic editor bundle (shared with the structured-data box).
	 *
	 * @return void
	 */
	public static function enqueue_classic(): void {
		if ( wp_script_is( 'zinn-seo-classic', 'enqueued' ) ) {
			return;
		}
		$asset = require ZINN_SEO_DIR . 'build/classic.asset.php';
		\ZinnDigital\ZinnSEO\Admin\Admin::register_jsx_runtime();
		$deps = array_unique( array_merge( (array) ( $asset['dependencies'] ?? array() ), array( 'wp-core-data', 'wp-block-editor', 'wp-hooks', 'wp-media-utils' ) ) );
		wp_enqueue_media();
		wp_enqueue_script( 'zinn-seo-classic', ZINN_SEO_URL . 'build/classic.js', $deps, (string) ( $asset['version'] ?? ZINN_SEO_VERSION ), true );
		wp_set_script_translations( 'zinn-seo-classic', 'zinn-seo', ZINN_SEO_DIR . 'languages' );
		wp_add_inline_script( 'zinn-seo-classic', 'window.zinnSeoEditor = ' . wp_json_encode( Editor::editor_data() ) . ';', 'before' );
		wp_enqueue_style( 'wp-components' );
		if ( is_readable( ZINN_SEO_DIR . 'build/classic.css' ) ) {
			wp_enqueue_style( 'zinn-seo-classic', ZINN_SEO_URL . 'build/classic.css', array( 'wp-components' ), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
			wp_style_add_data( 'zinn-seo-classic', 'rtl', 'replace' );
		}
	}

	/**
	 * The box: a root for the panel and the fields it saves with the post.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_nonce_field( self::NONCE, 'zseo_analysis_nonce' );
		$focus  = (string) Meta::get_post( (int) $post->ID, 'focus' );
		$rel    = (string) Meta::get_post( (int) $post->ID, 'related' );
		$corner = (bool) Meta::get_post( (int) $post->ID, 'cornerstone' );
		echo '<input type="hidden" name="zseo_analysis[focus]" id="zseo-analysis-focus" value="' . esc_attr( $focus ) . '">';
		echo '<input type="hidden" name="zseo_analysis[related]" id="zseo-analysis-related" value="' . esc_attr( $rel ) . '">';
		echo '<input type="hidden" name="zseo_analysis[cornerstone]" id="zseo-analysis-cornerstone" value="' . ( $corner ? '1' : '' ) . '">';
		echo '<div id="zinn-seo-analysis-root" data-post="' . esc_attr( (string) $post->ID ) . '"><p>' . esc_html__( 'Loading the analysis…', 'zinn-seo' ) . '</p></div>';
	}

	/**
	 * Store the classic box's fields with the post.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function save( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the action's signature.
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['zseo_analysis_nonce'], $_POST['zseo_analysis'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['zseo_analysis_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! Capabilities::can_edit_post( $post_id ) ) {
			return;
		}
		$values = wp_unslash( (array) $_POST['zseo_analysis'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by Meta::set() against its field.
		foreach ( array( 'focus', 'related' ) as $field ) {
			if ( isset( $values[ $field ] ) && is_string( $values[ $field ] ) ) {
				Meta::set( 'post', $post_id, $field, $values[ $field ], 'user' );
			}
		}
		if ( isset( $values['cornerstone'] ) ) {
			Meta::set( 'post', $post_id, 'cornerstone', '1' === (string) $values['cornerstone'], 'user' );
		}
	}
}
