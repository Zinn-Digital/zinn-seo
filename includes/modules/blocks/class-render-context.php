<?php
/**
 * Where a block is being rendered.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page a block belongs to, the builder that asked, and whether this is an editor preview
 * (a preview prints the same HTML but adds nothing to the page's structured data).
 */
final class Render_Context {

	/**
	 * The post the block sits in (or the page being viewed), when there is one.
	 *
	 * @var \WP_Post|null
	 */
	public ?\WP_Post $post = null;

	/**
	 * The page's canonical address ('' when unknown, e.g. in an editor preview of a draft).
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * The builder: gutenberg, pbs, elementor, shortcode or scan.
	 *
	 * @var string
	 */
	public string $builder = 'gutenberg';

	/**
	 * An editor preview (no structured data collected).
	 *
	 * @var bool
	 */
	public bool $preview = false;

	/**
	 * The instance number of this block type on the page (1, 2, …): unique `@id`s and anchors.
	 *
	 * @var int
	 */
	public int $instance = 1;

	/**
	 * A context for a post.
	 *
	 * @param \WP_Post|null $post    Post.
	 * @param string        $builder Builder.
	 * @return Render_Context
	 */
	public static function for_post( ?\WP_Post $post, string $builder = 'gutenberg' ): Render_Context {
		$ctx          = new self();
		$ctx->post    = $post;
		$ctx->builder = $builder;
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$link     = get_permalink( $post );
			$ctx->url = is_string( $link ) ? $link : '';
		}

		return $ctx;
	}

	/**
	 * The page address with a fragment: `<url>#<id>`, or just `#<id>` when the address is unknown.
	 *
	 * @param string $fragment Fragment.
	 * @return string
	 */
	public function id( string $fragment ): string {
		return $this->url . '#' . $fragment;
	}

	/**
	 * The page title (what a review or a how-to is about when the block does not say).
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->post instanceof \WP_Post ? Html::plain( get_the_title( $this->post ) ) : '';
	}
}
