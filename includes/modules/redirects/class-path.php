<?php
/**
 * How an address becomes the key a redirect is found by (pure; unit-tested).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ ONE normal form for a source typed by a person, a source imported from another plugin and the
 * address a visitor asked for, so they meet exactly:
 *
 * - the scheme and host are dropped (a source may be pasted as a full address of this site);
 * - the site's own sub-folder (`/blog` when WordPress lives at example.com/blog) is dropped, so
 *   rules survive a move of the folder;
 * - percent-encoding is decoded (`/caf%C3%A9` and `/café` are one address), runs of slashes become
 *   one, the trailing slash goes (except for `/` itself);
 * - letters are lower-cased unless the site asked for case-sensitive matching;
 * - a query string is kept only when the SOURCE has one (its parameters sorted), so `/a?x=1&y=2`
 *   matches `/a?y=2&x=1`; a source without a query matches the path whatever the query.
 *
 * The key's MD5 is the indexed column (`path_hash`) and its first three hex digits the index shard
 * (4,096 shards: about 25 rules each at 100,000 rules, 245 at a million).
 */
final class Path {

	/** Shard length in hex digits (16^3 = 4,096 shards). */
	public const SHARD = 3;

	/**
	 * Split an address into its path and query, without the scheme, host and fragment.
	 *
	 * @param string $url Address or path.
	 * @return array{0: string, 1: string} [path, query].
	 */
	public static function split( string $url ): array {
		$url = trim( $url );
		$pos = strpos( $url, '#' );
		if ( false !== $pos ) {
			$url = substr( $url, 0, $pos );
		}
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://[^/?]*(.*)$#i', $url, $m ) ) {
			$url = $m[1];
		}
		$query = '';
		$pos   = strpos( $url, '?' );
		if ( false !== $pos ) {
			$query = substr( $url, $pos + 1 );
			$url   = substr( $url, 0, $pos );
		}

		return array( $url, $query );
	}

	/**
	 * The normal form of a path (no query).
	 *
	 * @param string $path      Path.
	 * @param string $base      The site's own sub-folder (`/blog`), '' at the domain root.
	 * @param bool   $sensitive Case-sensitive.
	 * @return string Starts with `/`.
	 */
	public static function normal_path( string $path, string $base = '', bool $sensitive = false ): string {
		$path = rawurldecode( $path );
		// An invalid UTF-8 sequence would make every later string function misbehave.
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $path, 'UTF-8' ) ) {
			$path = (string) mb_convert_encoding( $path, 'UTF-8', 'ISO-8859-1' );
		}
		$path = '/' . ltrim( (string) preg_replace( '#/{2,}#', '/', str_replace( '\\', '/', $path ) ), '/' );
		$base = '/' . trim( $base, '/' );
		if ( '/' !== $base && ( $path === $base || 0 === strpos( $path, $base . '/' ) ) ) {
			$path = '/' . ltrim( (string) substr( $path, strlen( $base ) ), '/' );
		}
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
			$path = '' === $path ? '/' : $path;
		}
		if ( ! $sensitive ) {
			$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		}

		return $path;
	}

	/**
	 * The normal form of a query string: parameters sorted, empty pairs dropped.
	 *
	 * @param string $query Query (without `?`).
	 * @return string
	 */
	public static function normal_query( string $query ): string {
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts   = explode( '=', $pair, 2 );
			$pairs[] = rawurldecode( str_replace( '+', ' ', $parts[0] ) ) . ( isset( $parts[1] ) ? '=' . rawurldecode( str_replace( '+', ' ', $parts[1] ) ) : '' );
		}
		sort( $pairs, SORT_STRING );

		return implode( '&', $pairs );
	}

	/**
	 * The key of a SOURCE (what a rule is stored under).
	 *
	 * @param string $source    Source as typed or imported.
	 * @param string $base      Site sub-folder.
	 * @param bool   $sensitive Case-sensitive.
	 * @return string
	 */
	public static function key( string $source, string $base = '', bool $sensitive = false ): string {
		list( $path, $query ) = self::split( $source );
		$key                  = self::normal_path( $path, $base, $sensitive );
		$query                = self::normal_query( $query );

		return '' === $query ? $key : $key . '?' . $query;
	}

	/**
	 * The keys a REQUEST is looked up under, most specific first: with its query (when it has
	 * one), then the path alone.
	 *
	 * @param string $uri       REQUEST_URI.
	 * @param string $base      Site sub-folder.
	 * @param bool   $sensitive Case-sensitive.
	 * @return array<int, string>
	 */
	public static function lookups( string $uri, string $base = '', bool $sensitive = false ): array {
		list( $path, $query ) = self::split( $uri );
		$key                  = self::normal_path( $path, $base, $sensitive );
		$query                = self::normal_query( $query );

		return '' === $query ? array( $key ) : array( $key . '?' . $query, $key );
	}

	/**
	 * The indexed hash of a key.
	 *
	 * @param string $key Key.
	 * @return string 32 hex.
	 */
	public static function hash( string $key ): string {
		return md5( $key );
	}

	/**
	 * The shard of a hash.
	 *
	 * @param string $hash Hash.
	 * @return string 3 hex.
	 */
	public static function shard( string $hash ): string {
		return substr( $hash, 0, self::SHARD );
	}

	/**
	 * Is a pattern usable as a regular expression rule? (The pattern is the source without
	 * delimiters, matched against the normal path; `#` is the delimiter used.)
	 *
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public static function valid_regex( string $pattern ): bool {
		if ( '' === $pattern || strlen( $pattern ) > 2000 ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is the answer, not an error to log.
		return false !== @preg_match( self::delimit( $pattern ), '' );
	}

	/**
	 * A stored pattern with its delimiters and flags.
	 *
	 * @param string $pattern   Pattern.
	 * @param bool   $sensitive Case-sensitive.
	 * @return string
	 */
	public static function delimit( string $pattern, bool $sensitive = false ): string {
		// An unescaped # would end the pattern; one already escaped (\#) is left as it is.
		return '#' . preg_replace( '/(?<!\\\\)#/', '\\\\#', $pattern ) . '#u' . ( $sensitive ? '' : 'i' );
	}

	/**
	 * The address to send a visitor to.
	 *
	 * @param string             $target     The rule's target (absolute, or a path on this site).
	 * @param array<int, string> $groups     Regular-expression captures (`$1`… in the target).
	 * @param string             $query      The request's query string.
	 * @param bool               $pass_query Append the request's query to a target that has none.
	 * @param string             $home       The site's home address (for a path target).
	 * @return string
	 */
	public static function target( string $target, array $groups, string $query, bool $pass_query, string $home ): string {
		if ( array() !== $groups ) {
			$target = (string) preg_replace_callback(
				'/\$(\d{1,2})/',
				static function ( array $m ) use ( $groups ): string {
					return isset( $groups[ (int) $m[1] ] ) ? (string) $groups[ (int) $m[1] ] : '';
				},
				$target
			);
		}
		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $target ) ) {
			$target = rtrim( $home, '/' ) . '/' . ltrim( $target, '/' );
		}
		if ( $pass_query && '' !== $query && false === strpos( $target, '?' ) ) {
			$hash = '';
			$pos  = strpos( $target, '#' );
			if ( false !== $pos ) {
				$hash   = substr( $target, $pos );
				$target = substr( $target, 0, $pos );
			}
			$target .= '?' . $query . $hash;
		}

		return $target;
	}
}
