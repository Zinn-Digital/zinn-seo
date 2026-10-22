<?php
/**
 * Block editor adapter: every SEO block as a dynamic block (zs-300).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Adapters;

use ZinnDigital\ZinnSEO\Blocks\Blocks_Rest;
use ZinnDigital\ZinnSEO\Blocks\Fields;
use ZinnDigital\ZinnSEO\Blocks\Library;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers each block from its PHP description (one source: `fields()` gives the attributes, the
 * library renders), so there is no block.json to drift from the code. The blocks are dynamic: the
 * post stores only the values, and every view renders the current HTML.
 *
 * Footprint (docs/adr/0033): `className` and `customClassName` are off, the render never calls
 * get_block_wrapper_attributes(), and nothing is served from the plugin folder to visitors — the
 * editor script loads in the editor only.
 *
 * With Page Builder Sandwich active, the PBS adapter registers the same blocks through PBS's public
 * API instead (so they sit in its inserter and get its Style panel); this class then only scans.
 */
final class Gutenberg {

	/** The editor script's handle. */
	public const HANDLE = 'zinn-seo-blocks';

	/** The inserter category. */
	public const CATEGORY = 'zinn-seo';

	/** The blocks' own styles inside the editor's canvas (the editor shows what visitors see). */
	public const PREVIEW_STYLE = 'zinn-seo-blocks-preview';

	/**
	 * A visitor's request (no editor assets are registered then).
	 *
	 * @var bool
	 */
	private static bool $front = false;

	/**
	 * Hook in.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		self::$front = 'front' === $context;
		add_action( 'init', array( self::class, 'register' ), 20 );
		add_filter( 'zinn_seo_block_scan', array( self::class, 'scan' ), 10, 2 );
		add_filter( 'pre_render_block', array( self::class, 'render_unregistered' ), 10, 2 );
		if ( 'front' !== $context ) {
			add_filter( 'block_categories_all', array( self::class, 'category' ) );
		}
	}

	/**
	 * Register the editor script and every block (unless PBS registers them).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::$front ) {
			self::register_script();
		}
		if ( Pbs::active() ) {
			return;
		}
		foreach ( Library::all() as $block ) {
			register_block_type( $block->block_name(), self::args( $block ) );
		}
	}

	/**
	 * A custom block (Pro) is not registered on visitors' pages — its definition is read only when
	 * a page uses it — so WordPress would print nothing for it: render it here instead.
	 *
	 * @param string|null          $pre   Earlier answer.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null
	 */
	public static function render_unregistered( $pre, $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( null !== $pre || 0 !== strpos( $name, 'zinn-seo/custom-' ) || \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return $pre;
		}

		return Library::render( $name, (array) ( $block['attrs'] ?? array() ), self::context( null, 'gutenberg' ) );
	}

	/**
	 * The arguments for register_block_type() (also what PBS's API receives).
	 *
	 * @param \ZinnDigital\ZinnSEO\Blocks\Block $block Block.
	 * @return array<string, mixed>
	 */
	public static function args( \ZinnDigital\ZinnSEO\Blocks\Block $block ): array {
		$name = $block->name();
		$args = array(
			'api_version'           => 3,
			'title'                 => $block->title(),
			'description'           => $block->description(),
			'category'              => self::CATEGORY,
			'icon'                  => $block->icon(),
			'keywords'              => $block->keywords(),
			'attributes'            => Fields::attributes( $block->fields() ),
			'supports'              => array(
				'className'       => false,
				'customClassName' => false,
				'html'            => false,
				'inserter'        => $block->offered(),
				'reusable'        => true,
			),
			'uses_context'          => array( 'postId', 'postType' ),
			'render_callback'       => static function ( $attributes, $content = '', $instance = null ) use ( $name ): string {
				return Library::render( $name, (array) $attributes, self::context( $instance, 'gutenberg' ) );
			},
			'editor_script_handles' => array( self::HANDLE ),
			// Loaded into the editor's canvas (an iframe): the form's styles and the blocks' own.
			'editor_style_handles'  => array( self::HANDLE, self::PREVIEW_STYLE ),
		);
		if ( array() !== $block->example() ) {
			$args['example'] = array( 'attributes' => $block->example() );
		}

		return $args;
	}

	/**
	 * The render context of a block instance.
	 *
	 * @param mixed  $instance The WP_Block, when WordPress passes it.
	 * @param string $builder  Builder.
	 * @return Render_Context
	 */
	public static function context( $instance, string $builder ): Render_Context {
		$post_id = 0;
		if ( $instance instanceof \WP_Block && isset( $instance->context['postId'] ) ) {
			$post_id = (int) $instance->context['postId'];
		}
		$post = $post_id > 0 ? get_post( $post_id ) : get_post();
		$ctx  = Render_Context::for_post( $post instanceof \WP_Post ? $post : null, $builder );
		// The editor's live preview (the block renderer endpoint) adds nothing to any page's data.
		$ctx->preview = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin();

		return $ctx;
	}

	/**
	 * The editor script and its data.
	 *
	 * @return void
	 */
	public static function register_script(): void {
		$asset_file = ZINN_SEO_DIR . 'build/blocks.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		// The editor's packages are read from `window.wp`, so they are named here, not bundled.
		$deps = array_unique( array_merge( (array) ( $asset['dependencies'] ?? array() ), array( 'wp-blocks', 'wp-block-editor', 'wp-server-side-render', 'wp-core-data' ) ) );
		wp_register_script( self::HANDLE, ZINN_SEO_URL . 'build/blocks.js', $deps, (string) ( $asset['version'] ?? ZINN_SEO_VERSION ), true );
		wp_set_script_translations( self::HANDLE, 'zinn-seo', ZINN_SEO_DIR . 'languages' );
		if ( is_readable( ZINN_SEO_DIR . 'build/blocks.css' ) ) {
			wp_register_style( self::HANDLE, ZINN_SEO_URL . 'build/blocks.css', array(), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
		// Every block's visitor-side styles, inline, for the previews in the editor.
		wp_register_style( self::PREVIEW_STYLE, false, array(), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
		$css = '';
		foreach ( Library::all() as $block ) {
			$body = $block->has_style() ? \ZinnDigital\ZinnSEO\Blocks\Assets::body( $block->style_file() ) : null;
			$css .= null === $body ? '' : $body[0];
		}
		wp_add_inline_style( self::PREVIEW_STYLE, wp_strip_all_tags( Plugin::css_for_inline( $css ) ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
	}

	/**
	 * The data the editor script reads (only in the block editor).
	 *
	 * @return void
	 */
	public static function editor_data(): void {
		// The sidebar's form is in the editor's own page, outside the canvas.
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}
		$data = array(
			'blocks'   => Blocks_Rest::describe( false ),
			'isPro'    => Plugin::is_pro(),
			'prefix'   => \ZinnDigital\ZinnSEO\Blocks\Html::prefix(),
			'ai'       => (bool) apply_filters( 'zinn_seo_ai_available', false ),
			'upgrade'  => admin_url( 'admin.php?page=zinn-seo#/plans' ),
			'category' => __( 'SEO', 'zinn-seo' ),
		);
		wp_add_inline_script( self::HANDLE, 'window.zinnSeoBlocks = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The inserter category "SEO".
	 *
	 * @param array<int, array<string, mixed>> $categories Categories.
	 * @return array<int, array<string, mixed>>
	 */
	public static function category( $categories ): array {
		$categories = (array) $categories;
		foreach ( $categories as $c ) {
			if ( self::CATEGORY === ( $c['slug'] ?? '' ) ) {
				return $categories;
			}
		}
		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'SEO', 'zinn-seo' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * Find the SEO blocks in a post's block markup (reusable blocks and nested blocks included),
	 * and in the site's block widgets.
	 *
	 * @param array<int, array<string, mixed>> $found Found so far.
	 * @param \WP_Post                         $post  Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( $found, $post ): array {
		$found = (array) $found;
		if ( ! $post instanceof \WP_Post ) {
			return $found;
		}
		$content = (string) $post->post_content;
		// ⛔ Cost: a page with neither our blocks nor a reusable block is never parsed.
		if ( false !== strpos( $content, 'wp:zinn-seo/' ) || false !== strpos( $content, '<!-- wp:block ' ) ) {
			$found = array_merge( $found, self::walk( parse_blocks( $content ), 0 ) );
		}

		return $found;
	}

	/**
	 * Walk parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param int                              $depth  Depth (reusable blocks inside reusable blocks).
	 * @return array<int, array<string, mixed>>
	 */
	public static function walk( array $blocks, int $depth ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			$name = (string) ( $b['blockName'] ?? '' );
			if ( 0 === strpos( $name, 'zinn-seo/' ) ) {
				$out[] = array(
					'name'    => substr( $name, 9 ),
					'values'  => (array) ( $b['attrs'] ?? array() ),
					'builder' => 'gutenberg',
				);
			} elseif ( 'core/block' === $name && $depth < 3 && ! empty( $b['attrs']['ref'] ) ) {
				$ref = get_post( (int) $b['attrs']['ref'] );
				if ( $ref instanceof \WP_Post && 'wp_block' === $ref->post_type && 'publish' === $ref->post_status && false !== strpos( (string) $ref->post_content, 'wp:zinn-seo/' ) ) {
					$out = array_merge( $out, self::walk( parse_blocks( (string) $ref->post_content ), $depth + 1 ) );
				}
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$out = array_merge( $out, self::walk( (array) $b['innerBlocks'], $depth ) );
			}
		}

		return $out;
	}
}
