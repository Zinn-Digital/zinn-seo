<?php
/**
 * The robots.txt file read the way crawlers read it: parse, check, and test an address (zs-060).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Robots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions, following RFC 9309 (the Robots Exclusion Protocol) and Google's documented
 * reading of it:
 * - a group is one or more `User-agent` lines followed by its rules; groups naming the same
 *   crawler are merged;
 * - a crawler obeys the group naming it most specifically, else the `*` group;
 * - the matching rule with the LONGEST path wins; on a tie, `Allow` wins; `*` matches any run of
 *   characters and `$` anchors the end; an empty `Disallow:` allows everything.
 */
final class Rules {

	/** Largest file Google reads (500 KiB); the rest is ignored. */
	public const MAX_BYTES = 512000;

	/** Directives this checker knows: name => kind. */
	private const KNOWN = array(
		'user-agent'     => 'agent',
		'allow'          => 'rule',
		'disallow'       => 'rule',
		'sitemap'        => 'sitemap',
		'crawl-delay'    => 'other',
		'host'           => 'other',
		'clean-param'    => 'other',
		'content-signal' => 'other',
		'noindex'        => 'other',
		'request-rate'   => 'other',
		'visit-time'     => 'other',
	);

	/**
	 * Parse a robots.txt into groups.
	 *
	 * @param string $text File.
	 * @return array{groups: array<int, array{agents: array<int, string>, rules: array<int, array{type: string, path: string, line: int}>, line: int}>, sitemaps: array<int, string>}
	 */
	public static function parse( string $text ): array {
		$groups   = array();
		$sitemaps = array();
		$current  = null;
		$in_rules = false;
		foreach ( self::lines( $text ) as $n => $line ) {
			$parsed = self::directive( $line );
			if ( null === $parsed ) {
				continue;
			}
			list( $name, $value ) = $parsed;
			if ( 'user-agent' === $name ) {
				if ( null === $current || $in_rules ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
						'line'   => $n,
					);
					$current  = count( $groups ) - 1;
					$in_rules = false;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
				continue;
			}
			if ( 'sitemap' === $name ) {
				$sitemaps[] = $value;
				continue;
			}
			if ( ( 'allow' === $name || 'disallow' === $name ) && null !== $current ) {
				$in_rules                      = true;
				$groups[ $current ]['rules'][] = array(
					'type' => $name,
					'path' => $value,
					'line' => $n,
				);
				continue;
			}
			if ( null !== $current ) {
				$in_rules = true;
			}
		}

		return array(
			'groups'   => $groups,
			'sitemaps' => $sitemaps,
		);
	}

	/**
	 * The file's lines (1-based keys), without comments and the byte-order mark.
	 *
	 * @param string $text File.
	 * @return array<int, string>
	 */
	private static function lines( string $text ): array {
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$out  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $i => $line ) {
			$hash = strpos( (string) $line, '#' );
			if ( false !== $hash ) {
				$line = substr( (string) $line, 0, $hash );
			}
			$out[ $i + 1 ] = trim( (string) $line );
		}

		return $out;
	}

	/**
	 * A line's directive name (lower case) and value, or null for a blank line.
	 *
	 * @param string $line Line without its comment.
	 * @return array{0: string, 1: string}|null
	 */
	private static function directive( string $line ): ?array {
		if ( '' === $line ) {
			return null;
		}
		$colon = strpos( $line, ':' );
		if ( false === $colon ) {
			return array( '', $line );
		}

		return array( strtolower( trim( substr( $line, 0, $colon ) ) ), trim( substr( $line, $colon + 1 ) ) );
	}

	/**
	 * Problems in a file, worst first: `[level, line, code, detail]`, level error|warning|info.
	 *
	 * @param string $text File.
	 * @return array<int, array{level: string, line: int, code: string, detail: string}>
	 */
	public static function check( string $text ): array {
		$out = array();
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$out[] = self::issue( 'error', 0, 'too_big', (string) strlen( $text ) );
		}
		$seen_agent = false;
		$agents     = array();
		$group_open = false;
		foreach ( self::lines( $text ) as $n => $line ) {
			$parsed = self::directive( $line );
			if ( null === $parsed ) {
				continue;
			}
			list( $name, $value ) = $parsed;
			if ( '' === $name || ! isset( self::KNOWN[ $name ] ) ) {
				$out[] = self::issue( 'error', $n, 'unknown', '' === $name ? $line : $name );
				continue;
			}
			$kind = self::KNOWN[ $name ];
			if ( 'agent' === $kind ) {
				if ( '' === $value ) {
					$out[] = self::issue( 'error', $n, 'empty_agent', '' );
				}
				$key = strtolower( $value );
				if ( $group_open && isset( $agents[ $key ] ) ) {
					$out[] = self::issue( 'info', $n, 'repeated_agent', $value );
				}
				$agents[ $key ] = true;
				$seen_agent     = true;
				continue;
			}
			if ( 'rule' === $kind ) {
				$group_open = true;
				if ( ! $seen_agent ) {
					$out[] = self::issue( 'error', $n, 'no_agent', $name );
					continue;
				}
				if ( '' !== $value && '/' !== $value[0] && '*' !== $value[0] ) {
					$out[] = self::issue( 'warning', $n, 'relative_path', $value );
				}
				if ( 'disallow' === $name && ( '/' === $value || '/*' === $value ) && isset( $agents['*'] ) ) {
					$out[] = self::issue( 'warning', $n, 'blocks_all', '' );
				}
				continue;
			}
			if ( 'sitemap' === $kind ) {
				if ( 1 !== preg_match( '#^https?://#i', $value ) ) {
					$out[] = self::issue( 'error', $n, 'sitemap_relative', $value );
				}
				continue;
			}
			if ( 'crawl-delay' === $name ) {
				$out[] = self::issue( 'info', $n, 'crawl_delay', $value );
			} elseif ( 'noindex' === $name ) {
				$out[] = self::issue( 'warning', $n, 'noindex_ignored', '' );
			} elseif ( 'host' === $name || 'clean-param' === $name ) {
				$out[] = self::issue( 'info', $n, 'yandex_only', $name );
			}
		}
		$order = array(
			'error'   => 0,
			'warning' => 1,
			'info'    => 2,
		);
		usort(
			$out,
			static fn( array $a, array $b ): int => array( $order[ $a['level'] ], $a['line'] ) <=> array( $order[ $b['level'] ], $b['line'] )
		);

		return $out;
	}

	/**
	 * One problem.
	 *
	 * @param string $level  Level.
	 * @param int    $line   Line.
	 * @param string $code   Code.
	 * @param string $detail Detail.
	 * @return array{level: string, line: int, code: string, detail: string}
	 */
	private static function issue( string $level, int $line, string $code, string $detail ): array {
		return array(
			'level'  => $level,
			'line'   => $line,
			'code'   => $code,
			'detail' => $detail,
		);
	}

	/**
	 * May this crawler fetch this address?
	 *
	 * @param string $text  The robots.txt.
	 * @param string $agent The crawler's product token (`Googlebot`, `GPTBot`).
	 * @param string $url   An address or path.
	 * @return array{allowed: bool, group: string, rule: string, line: int}
	 */
	public static function test( string $text, string $agent, string $url ): array {
		$parsed = self::parse( $text );
		$path   = self::path_of( $url );
		$agent  = strtolower( trim( $agent ) );
		$rules  = array();
		$group  = '';
		// Most specific group: the crawler's exact name, else the longest name it starts with
		// (Googlebot-Image obeys a Googlebot group), else `*`.
		$best = '';
		foreach ( $parsed['groups'] as $g ) {
			foreach ( $g['agents'] as $name ) {
				if ( '*' !== $name && ( $name === $agent || 0 === strpos( $agent, $name ) ) && strlen( $name ) > strlen( $best ) ) {
					$best = $name;
				}
			}
		}
		$wanted = '' === $best ? '*' : $best;
		foreach ( $parsed['groups'] as $g ) {
			if ( in_array( $wanted, $g['agents'], true ) ) {
				$rules = array_merge( $rules, $g['rules'] );
				$group = $wanted;
			}
		}
		$winner = null;
		$length = -1;
		foreach ( $rules as $rule ) {
			if ( '' === $rule['path'] ) {
				continue; // An empty Disallow allows everything; an empty Allow says nothing.
			}
			if ( ! self::matches( $rule['path'], $path ) ) {
				continue;
			}
			$len = strlen( $rule['path'] );
			if ( $len > $length || ( $len === $length && 'allow' === $rule['type'] ) ) {
				$winner = $rule;
				$length = $len;
			}
		}

		return array(
			'allowed' => null === $winner || 'allow' === $winner['type'],
			'group'   => $group,
			'rule'    => null === $winner ? '' : ucfirst( $winner['type'] ) . ': ' . $winner['path'],
			'line'    => null === $winner ? 0 : (int) $winner['line'],
		);
	}

	/**
	 * The path and query of an address (`/a/b?c`), as robots.txt rules see it.
	 *
	 * @param string $url Address or path.
	 * @return string
	 */
	public static function path_of( string $url ): string {
		$url = trim( $url );
		if ( 1 === preg_match( '~^https?://[^/?#]*(.*)$~i', $url, $m ) ) {
			$url = $m[1];
		}
		$url = (string) preg_replace( '/#.*$/', '', $url );
		if ( '' === $url || '/' !== $url[0] ) {
			$url = '/' . $url;
		}

		return $url;
	}

	/**
	 * Does a rule's path pattern match a path? `*` is any run of characters, a final `$` anchors.
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    Path.
	 * @return bool
	 */
	public static function matches( string $pattern, string $path ): bool {
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $part ) {
			$regex .= ( $i > 0 ? '.*' : '' ) . preg_quote( self::normalise( $part ), '#' );
		}

		return 1 === preg_match( '#^' . $regex . ( $anchored ? '$' : '' ) . '#s', self::normalise( $path ) );
	}

	/**
	 * Percent-encode the same way on both sides (RFC 9309 §2.2.2): `/ä` and `/%C3%A4` are equal.
	 *
	 * @param string $text Path or pattern part.
	 * @return string
	 */
	private static function normalise( string $text ): string {
		return (string) preg_replace_callback(
			'/%[0-9a-fA-F]{2}|[^\x21-\x7E]/',
			static fn( array $m ): string => '%' === $m[0][0] ? strtoupper( $m[0] ) : strtoupper( rawurlencode( $m[0] ) ),
			$text
		);
	}
}
