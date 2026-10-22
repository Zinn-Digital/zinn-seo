<?php
/**
 * The classic editor's "Structured data" box (zs-032, zs-033, zs-039, zs-301).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Head\Editor;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The block editor's Structured data panel, in a box on the classic editor: the same React panel
 * (build/classic.js), whose changes travel with the post form in one hidden field and are saved
 * with the post — so the classic editor has every field the block editor has.
 */
final class Classic {

	/** Nonce action. */
	private const NONCE = 'zseo_schema_box';

	/** The fields the box may change. */
	public const FIELDS = array( 'schema_page', 'schema_article', 'takeaways', 'reviewed', 'about', 'mentions', 'speakable', 'significant' );

	/**
	 * Hook.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 20, 2 );
		add_action( 'save_post', array( self::class, 'save' ), 20, 2 );
	}

	/**
	 * Add the box (classic editor, public types, people who may edit the post's SEO).
	 *
	 * @param string        $post_type Post type.
	 * @param \WP_Post|null $post      Post.
	 * @return void
	 */
	public static function add( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! $post instanceof \WP_Post || ! Editor::public_type( $post_type ) ) {
			return;
		}
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}
		if ( ! Capabilities::can_edit_post( (int) $post->ID ) || ! is_readable( ZINN_SEO_DIR . 'build/classic.asset.php' ) ) {
			return;
		}
		add_meta_box( 'zinn-seo-schema', __( 'Structured data', 'zinn-seo' ), array( self::class, 'render' ), $post_type, 'side', 'default' );
		self::enqueue();
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_nonce_field( self::NONCE, 'zseo_schema_nonce' );
		echo '<div id="zinn-seo-schema-root" data-post="' . esc_attr( (string) $post->ID ) . '"></div>';
	}

	/**
	 * The box's script.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		$asset = require ZINN_SEO_DIR . 'build/classic.asset.php';
		\ZinnDigital\ZinnSEO\Admin\Admin::register_jsx_runtime();
		wp_enqueue_media();
		// The fields read people and pictures from WordPress's data store (`core`).
		$deps = array_unique( array_merge( (array) ( $asset['dependencies'] ?? array() ), array( 'wp-core-data', 'wp-block-editor', 'wp-hooks', 'wp-media-utils' ) ) );
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
	 * Save what the box changed, with the post.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function save( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the action's signature.
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['zseo_schema_nonce'], $_POST['zseo_schema_state'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['zseo_schema_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! Capabilities::can_edit_post( $post_id ) ) {
			return;
		}
		$state = json_decode( wp_unslash( (string) $_POST['zseo_schema_state'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every value is checked below against its field.
		if ( ! is_array( $state ) ) {
			return;
		}
		$known = Meta::fields();
		foreach ( (array) ( $state['fields'] ?? array() ) as $name => $value ) {
			if ( in_array( $name, self::FIELDS, true ) && isset( $known[ $name ] ) && is_scalar( $value ) ) {
				Meta::set( 'post', $post_id, (string) $name, (string) $value, 'user' );
			}
		}
		if ( is_array( $state['types'] ?? null ) && class_exists( '\ZinnDigital\ZinnSEO\Pro\Schema\Schema_Pro' ) && \ZinnDigital\ZinnSEO\Modules::enabled( 'schema-pro' ) ) {
			\ZinnDigital\ZinnSEO\Pro\Schema\Schema_Pro::store( $post_id, (array) $state['types'] );
		}
	}
}
