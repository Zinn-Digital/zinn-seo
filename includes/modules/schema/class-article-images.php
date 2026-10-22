<?php
/**
 * An article's picture in several shapes, for the structured data (IMP2, the importer
 * certification's follow-up to queue Q254).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google's article guide asks for the picture at several aspect ratios (16:9, 4:3 and 1:1), at
 * least 1,200 pixels wide for the best results. With `schema_article_images` on, an article's
 * `image` lists its featured image cut to those shapes, then the images in its text — the sizes
 * and file names Schema & Structured Data for WP gives (its `saswp_get_featured_image()`), so a
 * site that moved from it shows search engines the same pictures.
 *
 * A page view never edits an image: it lists the copies that exist (recorded once on the
 * attachment, `_zseo_crops`, so a view reads one row it already has) and asks WP-Cron, once, to
 * cut the ones that are missing.
 */
final class Article_Images {

	/** WP-Cron hook that cuts the missing copies of one attachment. */
	public const CRON = 'zseo_article_crops';

	/** The attachment's recorded copies: `WxH` => true. */
	public const META = '_zseo_crops';

	/**
	 * The sizes an image is listed in, first to last (Schema & Structured Data for WP's rule).
	 * Pure, for the tests.
	 *
	 * @param int $w The image's width.
	 * @param int $h Its height.
	 * @return array<int, array{0: int, 1: int}> Width and height of each copy; the image's own
	 *                                           size stands for the image itself.
	 */
	public static function sizes( int $w, int $h ): array {
		if ( $w < 1 || $h < 1 ) {
			return array();
		}
		if ( $w < 1200 || $h < 675 ) {
			$target = (int) round( 1200 / ( $w / $h ) );
			$min    = min( $w, $target );
			$tall   = $target < 675 ? array( 900, 720, 675 ) : array( $target, 900, 675 );

			return array( array( 1200, $tall[0] ), array( 1200, $tall[1] ), array( 1200, $tall[2] ), array( $min, $min ) );
		}
		$min = min( $w, $h );
		if ( 1200 === $w && in_array( $h, array( 900, 675 ), true ) ) {
			return 900 === $h
				? array( array( 1200, 900 ), array( 1200, 675 ), array( $min, $min ) )
				: array( array( 1200, 675 ), array( 1200, 900 ), array( $min, $min ) );
		}

		return array( array( $w, $h ), array( 1200, 900 ), array( 1200, 675 ), array( $min, $min ) );
	}

	/**
	 * The image list of a post's article, or an empty list (the article keeps the page's image).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array<string, mixed>> ImageObjects.
	 */
	public static function for_post( \WP_Post $post ): array {
		$out   = array();
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb > 0 ) {
			$out = self::copies( $thumb );
		}
		$text = self::text_images( (string) $post->post_content );
		if ( array() === $out && array() !== $text ) {
			// No featured image: the first picture of the text stands in, cut to 16:9 when it is
			// narrower than 1,200 pixels.
			$first = array_shift( $text );
			$id    = (int) attachment_url_to_postid( $first );
			$src   = $id > 0 ? wp_get_attachment_image_src( $id, 'full' ) : false;
			$wide  = is_array( $src ) && $src[0] === $first && (int) $src[1] < 1200;
			$crop  = $wide ? self::copy( $id, (string) $src[0], 1200, 675 ) : null;
			$out[] = null !== $crop ? $crop : array(
				'@type' => 'ImageObject',
				'url'   => $first,
			);
		}
		foreach ( $text as $url ) {
			$out[] = array(
				'@type' => 'ImageObject',
				'url'   => $url,
			);
		}

		return $out;
	}

	/**
	 * An attachment's copies in the listed sizes (those that exist).
	 *
	 * @param int $id Attachment.
	 * @return array<int, array<string, mixed>>
	 */
	public static function copies( int $id ): array {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || '' === (string) $src[0] ) {
			return array();
		}
		$w   = (int) $src[1];
		$h   = (int) $src[2];
		$out = array();
		foreach ( self::sizes( $w, $h ) as $size ) {
			if ( $size[0] === $w && $size[1] === $h ) {
				$out[] = self::node( (string) $src[0], $w, $h );
				continue;
			}
			$copy = self::copy( $id, (string) $src[0], $size[0], $size[1] );
			if ( null !== $copy ) {
				$out[] = $copy;
			}
		}

		return $out;
	}

	/**
	 * One copy, when its file exists; a missing one is cut by WP-Cron (once).
	 *
	 * @param int    $id  Attachment.
	 * @param string $url The image's address.
	 * @param int    $w   Width.
	 * @param int    $h   Height.
	 * @return array<string, mixed>|null
	 */
	private static function copy( int $id, string $url, int $w, int $h ): ?array {
		$made = get_post_meta( $id, self::META, true );
		$made = is_array( $made ) ? $made : null;
		$name = $w . 'x' . $h;
		if ( null !== $made && empty( $made[ $name ] ) ) {
			// Recorded as impossible to cut (the image could not be read): not listed.
			return null;
		}
		if ( null === $made ) {
			$file = (string) get_attached_file( $id );
			if ( '' === $file || ! file_exists( self::name( $file, $w, $h ) ) ) {
				self::schedule( $id );
				return null;
			}
		}

		return self::node( self::name( $url, $w, $h ), $w, $h );
	}

	/**
	 * A copy's name: `photo.jpg` cut to 1200 × 675 is `photo-1200x675.jpg` (WordPress's own form).
	 * Pure, for the tests.
	 *
	 * @param string $path The image's path or address.
	 * @param int    $w    Width.
	 * @param int    $h    Height.
	 * @return string
	 */
	public static function name( string $path, int $w, int $h ): string {
		return (string) preg_replace( '/(\.[A-Za-z0-9]+)$/', '-' . $w . 'x' . $h . '$1', $path, 1 );
	}

	/**
	 * An ImageObject.
	 *
	 * @param string $url Address.
	 * @param int    $w   Width.
	 * @param int    $h   Height.
	 * @return array<string, mixed>
	 */
	private static function node( string $url, int $w, int $h ): array {
		return array(
			'@type'  => 'ImageObject',
			'url'    => $url,
			'width'  => $w,
			'height' => $h,
		);
	}

	/**
	 * The addresses of the images in a post's text that are files of this site.
	 *
	 * @param string $content Post content.
	 * @return array<int, string>
	 */
	public static function text_images( string $content ): array {
		if ( false === stripos( $content, '<img' ) || ! preg_match_all( '/<img\b[^>]*?\bsrc="([^"]+)"/i', $content, $m ) ) {
			return array();
		}
		$uploads = wp_get_upload_dir();
		$base    = (string) ( $uploads['baseurl'] ?? '' );
		$dir     = (string) ( $uploads['basedir'] ?? '' );
		$out     = array();
		foreach ( $m[1] as $url ) {
			$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
			if ( '' === $base || 0 !== strpos( $url, $base . '/' ) || false !== strpos( $url, '..' ) ) {
				continue;
			}
			if ( file_exists( $dir . substr( $url, strlen( $base ) ) ) ) {
				$out[] = $url;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Ask WP-Cron to cut an attachment's missing copies (one event per attachment).
	 *
	 * @param int $id Attachment.
	 * @return void
	 */
	private static function schedule( int $id ): void {
		if ( false === wp_next_scheduled( self::CRON, array( $id ) ) ) {
			wp_schedule_single_event( time(), self::CRON, array( $id ) );
		}
	}

	/**
	 * Cut an attachment's missing copies and record which exist (WP-Cron).
	 *
	 * @param mixed $id Attachment.
	 * @return void
	 */
	public static function make( $id ): void {
		$id   = (int) $id;
		$file = $id > 0 ? (string) get_attached_file( $id ) : '';
		$src  = $id > 0 ? wp_get_attachment_image_src( $id, 'full' ) : false;
		if ( '' === $file || ! is_array( $src ) || ! file_exists( $file ) ) {
			return;
		}
		$made = array();
		$up   = static function ( $value, $orig_w, $orig_h, $new_w, $new_h, $crop ) {
			// The copy is cut to the exact size even from a smaller image (as the other plugin
			// did): scale up, then crop the middle.
			if ( ! $crop ) {
				return $value;
			}
			$ratio  = max( $new_w / $orig_w, $new_h / $orig_h );
			$crop_w = (int) round( $new_w / $ratio );
			$crop_h = (int) round( $new_h / $ratio );

			return array( 0, 0, (int) floor( ( $orig_w - $crop_w ) / 2 ), (int) floor( ( $orig_h - $crop_h ) / 2 ), (int) $new_w, (int) $new_h, $crop_w, $crop_h );
		};
		add_filter( 'image_resize_dimensions', $up, 10, 6 );
		foreach ( self::sizes( (int) $src[1], (int) $src[2] ) as $size ) {
			if ( $size[0] === (int) $src[1] && $size[1] === (int) $src[2] ) {
				continue;
			}
			$name = $size[0] . 'x' . $size[1];
			$dest = self::name( $file, $size[0], $size[1] );
			if ( ! file_exists( $dest ) ) {
				$editor = wp_get_image_editor( $file );
				if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( $size[0], $size[1], true ) ) || is_wp_error( $editor->save( $dest ) ) ) {
					$made[ $name ] = false;
					continue;
				}
			}
			$made[ $name ] = true;
		}
		remove_filter( 'image_resize_dimensions', $up, 10 );
		update_post_meta( $id, self::META, $made );
	}

	/**
	 * Forget the recorded copies when the image file changes (edited, replaced).
	 *
	 * @param mixed $data The attachment's metadata.
	 * @param mixed $id   Attachment.
	 * @return mixed
	 */
	public static function forget( $data, $id ) {
		delete_post_meta( (int) $id, self::META );

		return $data;
	}

	/**
	 * The text of a post's article description: its excerpt as written, else the first 55
	 * words of its text with the tags taken out (WordPress's excerpt length; the words
	 * Schema & Structured Data for WP gives, so a site that moved from it keeps them).
	 * Pure but for WordPress's text helpers, for the tests.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function excerpt( \WP_Post $post ): string {
		$excerpt = trim( (string) $post->post_excerpt );
		if ( '' === $excerpt ) {
			$text    = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
			$text    = (string) preg_replace( '/\[.*?\]/', '', $text );
			$excerpt = wp_trim_words( $text, (int) apply_filters( 'excerpt_length', 55 ), '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter.
		}

		return trim( $excerpt );
	}
}
