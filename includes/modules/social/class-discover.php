<?php
/**
 * Google Discover readiness (zs-415).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Social;

use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Google's Discover guidance asks of a page, checked against what this page actually prints:
 * a large image (at least 1200 pixels wide) with `max-image-preview:large`, an indexable page,
 * a publication and update date, and a named author. Each check says how to fix it.
 *
 * The print side is the head and social modules (`max-image-preview:large` is the site default,
 * the dates and author go into Open Graph and the page's JSON-LD node). The Discover clicks from
 * Search Console are shown by the Search Console module once the site is connected.
 */
final class Discover {

	/** The width Google asks for. */
	public const MIN_WIDTH = 1200;

	/**
	 * Check a post.
	 *
	 * @param Context $c The post's context.
	 * @return array{ready: bool, checks: array<int, array{id: string, ok: bool, label: string}>}
	 */
	public static function check( Context $c ): array {
		$post   = $c->post();
		$robots = Resolver::robots( $c );
		$image  = Social::image( $c );
		$checks = array();

		$indexable = ! isset( $robots['noindex'] );
		$checks[]  = array(
			'id'    => 'indexable',
			'ok'    => $indexable,
			'label' => $indexable ? __( 'Search engines may index this page.', 'zinn-seo' ) : __( 'This page asks search engines not to index it, so Discover cannot show it.', 'zinn-seo' ),
		);
		$wide      = $image && (int) $image['width'] >= self::MIN_WIDTH;
		$checks[]  = array(
			'id'    => 'image',
			'ok'    => (bool) $wide,
			'label' => $wide
				/* translators: %d: a width in pixels. */
				? sprintf( __( 'Has a large image (%d pixels wide).', 'zinn-seo' ), (int) $image['width'] )
				: sprintf(
					/* translators: %d: the width Google asks for, in pixels. */
					__( 'Add an image at least %d pixels wide (a featured image or an image when shared).', 'zinn-seo' ),
					self::MIN_WIDTH
				),
		);
		$large     = isset( $robots['max-image-preview'] ) && 'large' === $robots['max-image-preview'];
		$checks[]  = array(
			'id'    => 'preview',
			'ok'    => $large,
			'label' => $large ? __( 'Large image previews are allowed.', 'zinn-seo' ) : __( 'Allow large image previews (Settings: Search appearance, image preview "large").', 'zinn-seo' ),
		);
		$author    = $post && (int) $post->post_author > 0 && '' !== (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		$checks[]  = array(
			'id'    => 'author',
			'ok'    => $author,
			'label' => $author ? __( 'Names its author.', 'zinn-seo' ) : __( 'Give the post an author with a display name.', 'zinn-seo' ),
		);
		$checks[]  = array(
			'id'    => 'dates',
			'ok'    => (bool) $post,
			'label' => __( 'Tells search engines when it was published and last updated.', 'zinn-seo' ),
		);
		$ready     = true;
		foreach ( $checks as $check ) {
			$ready = $ready && $check['ok'];
		}

		return array(
			'ready'  => $ready,
			'checks' => $checks,
		);
	}
}
