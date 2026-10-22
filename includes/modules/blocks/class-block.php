<?php
/**
 * One SEO block: written once, shown by every builder (zs-300).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract (architecture §1.8): `fields()` is the typed description, `render()` is PURE
 * server-side HTML from cleaned values (escaped, footprint-free, no script), and `schema()`
 * returns the nodes the block adds to the page's ONE JSON-LD graph — a block never prints
 * structured data itself. The adapters (Gutenberg, Page Builder Sandwich, Elementor, shortcode)
 * only translate their own storage into the cleaned values and call these.
 */
abstract class Block {

	/**
	 * The short name (`faq`); the block editor's name is `zinn-seo/<name>`.
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * The title shown in every builder's inserter.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * One sentence for the inserter.
	 *
	 * @return string
	 */
	abstract public function description(): string;

	/**
	 * The fields (see Fields).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract public function fields(): array;

	/**
	 * The HTML. Values are already cleaned by Fields::clean().
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	abstract public function render( array $v, Render_Context $ctx ): string;

	/**
	 * Structured-data nodes: local key => node. FAQ questions from several blocks are merged
	 * into one FAQPage by the collector; other keys are made unique per instance.
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return array<string, array<string, mixed>>
	 */
	public function schema( array $v, Render_Context $ctx ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the default has no nodes.
		return array();
	}

	/**
	 * The edition the block needs: free or pro.
	 *
	 * @return string
	 */
	public function tier(): string {
		return 'free';
	}

	/**
	 * Search words for the inserters.
	 *
	 * @return array<int, string>
	 */
	public function keywords(): array {
		return array();
	}

	/**
	 * A Dashicons name for the inserters (block editor icons are drawn by the editor script).
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'search';
	}

	/**
	 * Is the block offered in the inserters? (Existing copies always render.) The FAQ and How-to
	 * blocks step aside when Page Builder Sandwich, which has its own, is active (zs-072).
	 *
	 * @return bool
	 */
	public function offered(): bool {
		return true;
	}

	/**
	 * Does the block have its own stylesheet (`blocks/<name>/style.css`)?
	 *
	 * @return bool
	 */
	public function has_style(): bool {
		return is_readable( $this->style_file() );
	}

	/**
	 * The stylesheet's path.
	 *
	 * @return string
	 */
	public function style_file(): string {
		return ZINN_SEO_DIR . 'blocks/' . $this->name() . '/style.css';
	}

	/**
	 * Example values, shown in the inserter preview and used by the tests.
	 *
	 * @return array<string, mixed>
	 */
	public function example(): array {
		return array();
	}

	/**
	 * The block editor's full name.
	 *
	 * @return string
	 */
	public function block_name(): string {
		return 'zinn-seo/' . $this->name();
	}
}
