<?php
/**
 * Image licence details for Google Images' "Licensable" badge (zs-739).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SchemaExtra;

use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each image in the media library may carry who made it, a credit line, a copyright notice, the
 * licence it is offered under and where to license it; the library has defaults for all five
 * (Structured data → Image licences), and an image's own value wins.
 *
 * On a post's page every image the post shows (its featured image and each library image in its
 * text, read from the `wp-image-N` class WordPress puts on them — the saved text, no query per
 * image beyond WordPress's own cached reads) becomes an `ImageObject` with `contentUrl`,
 * `creator`, `creditText`, `copyrightNotice`, `license` and `acquireLicensePage` — what Google
 * Images reads for the badge. An image with none of the five says nothing new.
 */
final class Image_Licence {

	/** The five properties: our field => [setting, schema.org property]. */
	private const FIELDS = array(
		'lic_creator'   => array( 'img_lic_creator', 'creator' ),
		'lic_credit'    => array( 'img_lic_credit', 'creditText' ),
		'lic_copyright' => array( 'img_lic_copyright', 'copyrightNotice' ),
		'lic_license'   => array( 'img_lic_license', 'license' ),
		'lic_acquire'   => array( 'img_lic_acquire', 'acquireLicensePage' ),
	);

	/** Most images described on one page (a page's graph is sent to every visitor). */
	private const PER_PAGE = 50;

	/**
	 * The licence properties of one image (its own, else the library's), schema.org names.
	 *
	 * @param int $attachment Image.
	 * @return array<string, mixed>
	 */
	public static function props( int $attachment ): array {
		$out = array();
		foreach ( self::FIELDS as $field => $pair ) {
			$own   = (string) Meta::get_post( $attachment, $field );
			$value = '' !== $own ? $own : (string) Settings::get( $pair[0], '' );
			if ( '' === $value ) {
				continue;
			}
			if ( 'creator' === $pair[1] ) {
				$org            = (string) Settings::get( 'identity_name', '' );
				$out['creator'] = array(
					'@type' => '' !== $org && strtolower( $org ) === strtolower( $value ) ? 'Organization' : 'Person',
					'name'  => $value,
				);
				continue;
			}
			$out[ $pair[1] ] = $value;
		}

		return $out;
	}

	/**
	 * The library images a post shows: featured first, then those in its text, once each.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, int>
	 */
	public static function images_of( \WP_Post $post ): array {
		$ids   = array();
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb > 0 ) {
			$ids[ $thumb ] = true;
		}
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', (string) $post->post_content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		return array_slice( array_keys( $ids ), 0, self::PER_PAGE );
	}

	/**
	 * Add the images to a post's graph.
	 *
	 * @param mixed $nodes Nodes.
	 * @param mixed $c     The page.
	 * @return array<string, mixed>
	 */
	public static function graph( $nodes, $c ): array {
		$nodes = (array) $nodes;
		$post  = $c instanceof Context ? $c->post() : null;
		if ( ! $post instanceof \WP_Post || ! isset( $nodes['webpage'] ) ) {
			return $nodes;
		}
		$url = (string) $nodes['webpage']['url'];
		$n   = 0;
		foreach ( self::images_of( $post ) as $id ) {
			$props = self::props( $id );
			$src   = (string) wp_get_attachment_url( $id );
			if ( array() === $props || '' === $src || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			// The page's main image is already a node: it gains the licence instead of a twin.
			$main = $nodes['primaryimage'] ?? null;
			if ( is_array( $main ) && in_array( $src, array( (string) ( $main['url'] ?? '' ), (string) ( $main['contentUrl'] ?? '' ) ), true ) ) {
				$nodes['primaryimage'] = array_merge( $main, array( 'contentUrl' => $src ), $props );
				continue;
			}
			++$n;
			$node = array(
				'@type'      => 'ImageObject',
				'@id'        => $url . '#image-' . $id,
				'contentUrl' => $src,
				'url'        => $src,
			) + $props;
			$alt  = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			if ( '' !== $alt ) {
				$node['caption'] = $alt;
			}
			$nodes[ 'licimage' . $n ] = $node;
		}

		return $nodes;
	}

	/**
	 * The five fields on an image's details (media library and edit screen).
	 *
	 * @param mixed $fields Fields.
	 * @param mixed $post   The attachment.
	 * @return array<string, mixed>
	 */
	public static function fields( $fields, $post ): array {
		$fields = (array) $fields;
		if ( ! $post instanceof \WP_Post || ! wp_attachment_is_image( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$labels = array(
			'lic_creator'   => __( 'Image creator', 'zinn-seo' ),
			'lic_credit'    => __( 'Credit line', 'zinn-seo' ),
			'lic_copyright' => __( 'Copyright notice', 'zinn-seo' ),
			'lic_license'   => __( 'Licence address', 'zinn-seo' ),
			'lic_acquire'   => __( 'Where to license it', 'zinn-seo' ),
		);
		foreach ( $labels as $field => $label ) {
			$default                    = (string) Settings::get( self::FIELDS[ $field ][0], '' );
			$fields[ 'zseo_' . $field ] = array(
				'label' => $label,
				'input' => 'text',
				'value' => (string) Meta::get_post( (int) $post->ID, $field ),
				'helps' => '' !== $default
					/* translators: %s: the library default. */
					? sprintf( __( 'Empty: the library default (%s).', 'zinn-seo' ), $default )
					: ( in_array( $field, array( 'lic_license', 'lic_acquire' ), true ) ? __( 'An address starting with https://', 'zinn-seo' ) : '' ),
			);
		}

		return $fields;
	}

	/**
	 * Save the five fields.
	 *
	 * @param mixed $post       The attachment as an array.
	 * @param mixed $attachment The submitted fields.
	 * @return array<string, mixed>
	 */
	public static function save( $post, $attachment ): array {
		$post = (array) $post;
		$id   = (int) ( $post['ID'] ?? 0 );
		if ( $id <= 0 || ! is_array( $attachment ) || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( array_key_exists( 'zseo_' . $field, $attachment ) ) {
				$value = Meta::sanitize( Meta::fields()[ $field ] ?? array( 'type' => 'string' ), (string) $attachment[ 'zseo_' . $field ] );
				if ( is_wp_error( $value ) ) {
					continue; // Not an address (or too long): the value stored stays.
				}
				if ( '' === $value || null === $value ) {
					delete_post_meta( $id, Meta::key( $field ) );
				} else {
					update_post_meta( $id, Meta::key( $field ), $value );
				}
			}
		}

		return $post;
	}
}
