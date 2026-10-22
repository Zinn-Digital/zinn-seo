<?php
/**
 * Dublin Core metadata (zs-726), for libraries, archives and catalogues that read it.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\DublinCore;

use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Off on every site until switched on (search engines do not read it, so it would only make the
 * head bigger). When on, the head carries the page's title, description, language, publisher and
 * address in Dublin Core terms — and on a post or page, its author, date and main topic — from the
 * same values the rest of the head uses, so they can never disagree.
 */
final class Dublin_Core {

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		add_filter( 'zinn_seo_head_tags', array( self::class, 'tags' ), 30, 2 );
	}

	/**
	 * Add the tags.
	 *
	 * @param array<int, string> $lines Tags so far.
	 * @param Context|mixed      $c     The page.
	 * @return array<int, string>
	 */
	public static function tags( $lines, $c ): array {
		$lines = (array) $lines;
		if ( ! $c instanceof Context || in_array( $c->kind, array( '404', 'search' ), true ) ) {
			return $lines;
		}
		$fields = array(
			'DC.title'       => Resolver::title( $c ),
			'DC.description' => Resolver::description( $c ),
			'DC.language'    => (string) get_bloginfo( 'language' ),
			'DC.publisher'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'DC.identifier'  => Resolver::canonical( $c ),
		);
		$post   = $c->post();
		if ( $post instanceof \WP_Post ) {
			$author                = get_userdata( (int) $post->post_author );
			$fields['DC.creator']  = $author instanceof \WP_User ? $author->display_name : '';
			$fields['DC.date']     = (string) get_the_date( 'Y-m-d', $post );
			$fields['DC.modified'] = (string) get_the_modified_date( 'Y-m-d', $post );
			$fields['DC.type']     = 'Text';
			$fields['DC.subject']  = self::subject( $post );
		}
		$tags = self::render( $fields );
		if ( array() !== $tags ) {
			array_unshift( $tags, '<link rel="schema.DC" href="http://purl.org/dc/elements/1.1/">' );
		}

		return array_merge( $lines, $tags );
	}

	/**
	 * A post's main topic: its main category, else its first category or tag.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function subject( \WP_Post $post ): string {
		$primary = (int) get_post_meta( $post->ID, '_zseo_primary_term', true );
		if ( $primary > 0 ) {
			$term = get_term( $primary );
			if ( $term instanceof \WP_Term ) {
				return $term->name;
			}
		}
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_array( $terms ) && isset( $terms[0] ) && 'uncategorized' !== $terms[0]->slug ) {
				return $terms[0]->name;
			}
		}

		return '';
	}

	/**
	 * Name => value as meta tags (empty values left out), escaped.
	 *
	 * @param array<string, string> $fields Fields.
	 * @return array<int, string>
	 */
	public static function render( array $fields ): array {
		$out = array();
		foreach ( $fields as $name => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$out[] = '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $value ) . '">';
			}
		}

		return $out;
	}
}
