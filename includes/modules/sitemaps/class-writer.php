<?php
/**
 * The XML of a sitemap: one entry at a time, never a document in memory (zs-040).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure string builders. The builder writes `open()`, then one `entry()` per address as it reads
 * them, then `close()`, straight to a file: memory holds one batch of rows, never the sitemap.
 *
 * ⭐ Files are stored WITHOUT the stylesheet instruction. The server adds it while sending the
 * file (Sitemaps::send()), so switching the human view on or off, or the no-trace mode, never
 * needs a rebuild.
 *
 * Formats: sitemaps.org 0.9; Google's image (`image:loc` only — Google stopped reading caption,
 * title and licence in 2022), video and news extensions; `xhtml:link` alternates for languages.
 */
final class Writer {

	/** The XML declaration, always the first line of a stored file. */
	public const DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>';

	/** Most addresses one sitemap file may list (sitemaps.org). */
	public const MAX_URLS = 50000;

	/** Largest file, uncompressed, in bytes (sitemaps.org: 50 MB). */
	public const MAX_BYTES = 52428800;

	/**
	 * The opening of a `urlset`, with only the namespaces its entries use.
	 *
	 * @param array<string, bool> $uses `image`, `xhtml`, `video`, `news`.
	 * @return string
	 */
	public static function open( array $uses = array() ): string {
		$ns = ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( ! empty( $uses['image'] ) ) {
			$ns .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}
		if ( ! empty( $uses['xhtml'] ) ) {
			$ns .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
		}
		if ( ! empty( $uses['video'] ) ) {
			$ns .= ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
		}
		if ( ! empty( $uses['news'] ) ) {
			$ns .= ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"';
		}

		return self::DECLARATION . "\n<urlset{$ns}>\n";
	}

	/**
	 * The end of a `urlset`.
	 *
	 * @return string
	 */
	public static function close(): string {
		return "</urlset>\n";
	}

	/**
	 * One `<url>` element.
	 *
	 * @param array<string, mixed> $e `loc`; optional `lastmod` (Unix time), `images` (addresses),
	 *                                `alternates` (hreflang => address), `videos` (records with
	 *                                `thumbnail`, `title`, `description`, `player` or `content`,
	 *                                optional `duration`, `published`), `news` (`name`, `language`,
	 *                                `published`, `title`).
	 * @return string The element, or '' when the address is not one a sitemap may list.
	 */
	public static function entry( array $e ): string {
		$loc = self::url( (string) ( $e['loc'] ?? '' ) );
		if ( '' === $loc ) {
			return '';
		}
		$out = "<url><loc>{$loc}</loc>";
		if ( ! empty( $e['lastmod'] ) ) {
			$out .= '<lastmod>' . self::date( (int) $e['lastmod'] ) . '</lastmod>';
		}
		foreach ( (array) ( $e['alternates'] ?? array() ) as $lang => $href ) {
			$href = self::url( (string) $href );
			if ( '' !== $href && 1 === preg_match( '/^(x-default|[a-z]{2,3}(-[A-Za-z0-9]{2,8})*)$/', (string) $lang ) ) {
				$out .= '<xhtml:link rel="alternate" hreflang="' . self::text( (string) $lang ) . '" href="' . $href . '"/>';
			}
		}
		foreach ( (array) ( $e['images'] ?? array() ) as $image ) {
			$image = self::url( (string) $image );
			if ( '' !== $image ) {
				$out .= "<image:image><image:loc>{$image}</image:loc></image:image>";
			}
		}
		foreach ( (array) ( $e['videos'] ?? array() ) as $video ) {
			$out .= self::video( (array) $video );
		}
		if ( ! empty( $e['news'] ) && is_array( $e['news'] ) ) {
			$n    = $e['news'];
			$out .= '<news:news><news:publication><news:name>' . self::text( (string) ( $n['name'] ?? '' ) ) . '</news:name><news:language>' . self::text( (string) ( $n['language'] ?? 'en' ) ) . '</news:language></news:publication>'
				. '<news:publication_date>' . self::date( (int) ( $n['published'] ?? 0 ) ) . '</news:publication_date><news:title>' . self::text( (string) ( $n['title'] ?? '' ) ) . '</news:title></news:news>';
		}

		return $out . "</url>\n";
	}

	/**
	 * One `<video:video>`; '' when a field Google requires is missing.
	 *
	 * @param array<string, mixed> $v Video record.
	 * @return string
	 */
	public static function video( array $v ): string {
		$thumb   = self::url( (string) ( $v['thumbnail'] ?? '' ) );
		$title   = trim( (string) ( $v['title'] ?? '' ) );
		$desc    = trim( (string) ( $v['description'] ?? '' ) );
		$player  = self::url( (string) ( $v['player'] ?? '' ) );
		$content = self::url( (string) ( $v['content'] ?? '' ) );
		if ( '' === $thumb || '' === $title || ( '' === $player && '' === $content ) ) {
			return '';
		}
		if ( '' === $desc ) {
			$desc = $title;
		}
		$out = "<video:video><video:thumbnail_loc>{$thumb}</video:thumbnail_loc><video:title>" . self::text( self::cut( $title, 100 ) ) . '</video:title><video:description>' . self::text( self::cut( $desc, 2048 ) ) . '</video:description>';
		if ( '' !== $content ) {
			$out .= "<video:content_loc>{$content}</video:content_loc>";
		}
		if ( '' !== $player ) {
			$out .= "<video:player_loc>{$player}</video:player_loc>";
		}
		if ( ! empty( $v['duration'] ) && (int) $v['duration'] > 0 && (int) $v['duration'] <= 28800 ) {
			$out .= '<video:duration>' . (int) $v['duration'] . '</video:duration>';
		}
		if ( ! empty( $v['published'] ) ) {
			$out .= '<video:publication_date>' . self::date( (int) $v['published'] ) . '</video:publication_date>';
		}

		return $out . '</video:video>';
	}

	/**
	 * A sitemap index.
	 *
	 * @param array<int, array{loc: string, lastmod?: int}> $files Child sitemaps.
	 * @return string
	 */
	public static function index( array $files ): string {
		$out = self::DECLARATION . "\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
		foreach ( $files as $file ) {
			$loc = self::url( (string) ( $file['loc'] ?? '' ) );
			if ( '' === $loc ) {
				continue;
			}
			$out .= "<sitemap><loc>{$loc}</loc>";
			if ( ! empty( $file['lastmod'] ) ) {
				$out .= '<lastmod>' . self::date( (int) $file['lastmod'] ) . '</lastmod>';
			}
			$out .= "</sitemap>\n";
		}

		return $out . "</sitemapindex>\n";
	}

	/**
	 * Text content, escaped for XML (and with characters XML 1.0 forbids removed).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text( string $text ): string {
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * An absolute http(s) address, percent-encoded as sitemaps require (a slug in Arabic or a
	 * space in a file name would make the file invalid) and escaped for XML; '' for anything else.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function url( string $url ): string {
		$url = trim( $url );
		if ( 1 !== preg_match( '~^https?://[^/\s?#]+~i', $url ) ) {
			return '';
		}
		// Encode only what is not already allowed: bytes outside ASCII, spaces and the few
		// characters a URL may not carry raw. An existing %XX stays as it is.
		$url = (string) preg_replace_callback(
			'/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]|%(?![0-9A-Fa-f]{2})/',
			static function ( array $m ): string {
				return rawurlencode( $m[0] );
			},
			$url
		);

		return htmlspecialchars( $url, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A W3C date-time in UTC.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function date( int $time ): string {
		return gmdate( 'Y-m-d\TH:i:s\+00:00', max( 0, $time ) );
	}

	/**
	 * Shorten to a number of characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function cut( string $text, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
