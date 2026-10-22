<?php
/**
 * Images and videos a page shows, read from its stored text (zs-042, zs-045).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions over a post's stored HTML: no database, no shortcode or block rendering (a
 * sitemap of a million posts cannot run `the_content` a million times).
 *
 * ⛔⛔ Never `wp_get_attachment_*` per image (catalogue A4.2: the reason other SEO plugins crash
 * on large media libraries). The builder collects the attachment IDs of a whole page of posts
 * from these functions and reads their files in ONE query.
 */
final class Media {

	/**
	 * Attachment IDs and direct image addresses in a post's text.
	 *
	 * IDs come from the classes and attributes the editors write: `wp-image-123` (classic and
	 * block images), `"id":123` in an image or gallery block's comment, and `ids="1,2"` of a
	 * `[gallery]` shortcode. Addresses come from `<img src>` that are not one of those.
	 *
	 * @param string $html Post content as stored.
	 * @return array{ids: array<int, int>, urls: array<int, string>}
	 */
	public static function images( string $html ): array {
		$ids  = array();
		$urls = array();
		if ( '' === $html || ( false === stripos( $html, 'img' ) && false === strpos( $html, 'gallery' ) && false === strpos( $html, 'wp:' ) ) ) {
			return array(
				'ids'  => array(),
				'urls' => array(),
			);
		}
		if ( preg_match_all( '/wp-image-(\d+)/', $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = (int) $id;
			}
		}
		if ( preg_match_all( '/<!--\s+wp:(?:image|gallery|media-text|cover)\s+(\{.*?\})\s+\/?-->/s', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) ) {
					foreach ( array( 'id', 'mediaId' ) as $key ) {
						if ( isset( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) ) {
							$ids[] = (int) $attrs[ $key ];
						}
					}
					foreach ( (array) ( $attrs['ids'] ?? array() ) as $id ) {
						if ( is_numeric( $id ) ) {
							$ids[] = (int) $id;
						}
					}
				}
			}
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids\s*=\s*["\']?([\d,\s]+)/', $html, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( explode( ',', $list ) as $id ) {
					if ( '' !== trim( $id ) ) {
						$ids[] = (int) trim( $id );
					}
				}
			}
		}
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( 1 === preg_match( '/wp-image-\d+/', $tag ) ) {
					continue; // Known by its ID; its address is read with the others.
				}
				if ( 1 === preg_match( '/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $src ) && 0 !== strpos( $src[1], 'data:' ) ) {
					$urls[] = html_entity_decode( $src[1], ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		return array(
			'ids'  => array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) ),
			'urls' => array_values( array_unique( $urls ) ),
		);
	}

	/**
	 * Videos a post embeds: YouTube and Vimeo (by address, as the embed block and a bare link
	 * store them) and the site's own video files (`<video src>`, `[video]`, the video block).
	 *
	 * @param string $html Post content as stored.
	 * @return array<int, array{kind: string, id: string, player: string, content: string, poster: string}>
	 */
	public static function videos( string $html ): array {
		$out = array();
		if ( '' === $html ) {
			return $out;
		}
		if ( preg_match_all( '#(?:youtube\.com/(?:watch\?(?:[^"\'\s<]*&(?:amp;)?)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $id ) {
				$out[ 'yt:' . $id ] = array(
					'kind'    => 'youtube',
					'id'      => $id,
					'player'  => 'https://www.youtube.com/embed/' . $id,
					'content' => '',
					'poster'  => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
				);
			}
		}
		if ( preg_match_all( '#(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})#', $html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $id ) {
				$out[ 'vm:' . $id ] = array(
					'kind'    => 'vimeo',
					'id'      => $id,
					'player'  => 'https://player.vimeo.com/video/' . $id,
					'content' => '',
					'poster'  => '',
				);
			}
		}
		if ( preg_match_all( '/<video\b[^>]*>|\[video\b[^\]]*\]/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$src = '';
				if ( 1 === preg_match( '/\s(?:src|mp4|webm|ogv)\s*=\s*["\']([^"\']+\.(?:mp4|m4v|webm|ogv|mov)(?:\?[^"\']*)?)["\']/i', $tag, $s ) ) {
					$src = html_entity_decode( $s[1], ENT_QUOTES, 'UTF-8' );
				}
				if ( '' === $src ) {
					continue;
				}
				$poster             = 1 === preg_match( '/\sposter\s*=\s*["\']([^"\']+)["\']/i', $tag, $p ) ? html_entity_decode( $p[1], ENT_QUOTES, 'UTF-8' ) : '';
				$out[ 'f:' . $src ] = array(
					'kind'    => 'file',
					'id'      => '',
					'player'  => '',
					'content' => $src,
					'poster'  => $poster,
				);
			}
		}

		return array_values( $out );
	}

	/**
	 * An image address from an attachment's stored file name (`_wp_attached_file`).
	 *
	 * @param string $file    `2026/03/picture.jpg`, or an absolute address some offload plugins store.
	 * @param string $baseurl The uploads base address.
	 * @return string
	 */
	public static function file_url( string $file, string $baseurl ): string {
		if ( '' === $file ) {
			return '';
		}
		if ( 1 === preg_match( '#^https?://#i', $file ) ) {
			return $file;
		}

		return rtrim( $baseurl, '/' ) . '/' . ltrim( $file, '/' );
	}
}
