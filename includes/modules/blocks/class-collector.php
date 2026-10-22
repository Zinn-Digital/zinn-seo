<?php
/**
 * The structured data the page's blocks describe, gathered into the ONE graph (zs-031, zs-inn-07).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks never print JSON-LD. Each block instance hands its nodes here, and the page's graph
 * (printed once, in the head) takes them all:
 *
 * - The page's blocks are known BEFORE the head is printed: the post's content is scanned for
 *   every builder's copies (block markup, shortcodes, Elementor's data, block widgets), and a
 *   block theme renders its templates before the head anyway. An instance found both by the
 *   scan and when it renders is counted once (same block, same values = same instance).
 * - Questions from every FAQ-like block on the page (FAQ, Quick Answer) become ONE `FAQPage`,
 *   as Google requires; other nodes keep one node per instance with their own `@id`.
 * - Other plugins add nodes through `add_node()` (and the `zinn_seo_graph` filter), so they join
 *   the graph instead of printing a second one.
 */
final class Collector {

	/**
	 * Instances seen: identity => true.
	 *
	 * @var array<string, bool>
	 */
	private static array $seen = array();

	/**
	 * Nodes by local key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $nodes = array();

	/**
	 * Instances that arrived after the graph was printed (counted for the diagnostics).
	 *
	 * @var int
	 */
	private static int $late = 0;

	/**
	 * Has the graph been printed (or handed to another SEO plugin)?
	 *
	 * @var bool
	 */
	private static bool $sealed = false;

	/**
	 * Add one block instance's nodes.
	 *
	 * @param string                              $identity Same block + same values = same instance.
	 * @param array<string, array<string, mixed>> $nodes    Local key => node.
	 * @return bool Whether it was added (false: already there, or the graph is printed).
	 */
	public static function add_instance( string $identity, array $nodes ): bool {
		if ( isset( self::$seen[ $identity ] ) ) {
			return false;
		}
		if ( self::$sealed ) {
			++self::$late;
			return false;
		}
		self::$seen[ $identity ] = true;
		foreach ( $nodes as $key => $node ) {
			self::add_node( (string) $key, $node );
		}

		return true;
	}

	/**
	 * Add a node under a local key. `faq` nodes merge their questions; any other key already
	 * taken gets a numbered twin (`review`, `review-2`).
	 *
	 * @param string               $key  Local key.
	 * @param array<string, mixed> $node Node.
	 * @return void
	 */
	public static function add_node( string $key, array $node ): void {
		unset( $node['@context'] );
		if ( '@page' === $key ) {
			self::$nodes['@page'] = self::merge_page( self::$nodes['@page'] ?? array(), $node );
			return;
		}
		if ( 'faq' === $key && isset( self::$nodes['faq'] ) ) {
			self::$nodes['faq']['mainEntity'] = self::merge_questions(
				(array) ( self::$nodes['faq']['mainEntity'] ?? array() ),
				(array) ( $node['mainEntity'] ?? array() )
			);
			return;
		}
		$base = $key;
		$n    = 1;
		while ( isset( self::$nodes[ $key ] ) ) {
			++$n;
			$key = $base . '-' . $n;
		}
		self::$nodes[ $key ] = $node;
	}

	/**
	 * Merge properties meant for the page's own node (WebPage): `speakable` selectors add up,
	 * anything else is replaced.
	 *
	 * @param array<string, mixed> $page  So far.
	 * @param array<string, mixed> $props New.
	 * @return array<string, mixed>
	 */
	public static function merge_page( array $page, array $props ): array {
		foreach ( $props as $prop => $value ) {
			if ( 'speakable' === $prop && isset( $page['speakable']['cssSelector'] ) && is_array( $value ) ) {
				$page['speakable']['cssSelector'] = array_values( array_unique( array_merge( (array) $page['speakable']['cssSelector'], (array) ( $value['cssSelector'] ?? array() ) ) ) );
				continue;
			}
			if ( in_array( $prop, array( 'about', 'mentions', 'significantLink', 'reviewedBy' ), true ) && isset( $page[ $prop ] ) ) {
				$page[ $prop ] = self::union( (array) $page[ $prop ], (array) $value );
				continue;
			}
			$page[ $prop ] = $value;
		}

		return $page;
	}

	/**
	 * Two lists of values or references, without repeats.
	 *
	 * @param array<int|string, mixed> $a List.
	 * @param array<int|string, mixed> $b List.
	 * @return array<int, mixed>
	 */
	private static function union( array $a, array $b ): array {
		$list = array();
		foreach ( array( $a, $b ) as $part ) {
			// A single node or reference is one item, not a list of its properties.
			if ( array() === $part ) {
				continue;
			}
			$items = array_keys( $part ) === range( 0, count( $part ) - 1 ) ? $part : array( $part );
			foreach ( $items as $item ) {
				$list[ md5( (string) wp_json_encode( $item ) ) ] = $item;
			}
		}

		return array_values( $list );
	}

	/**
	 * The graph's share of the nodes: what joins the page node (`page`), and the nodes (`nodes`)
	 * — pros and cons joined to the page's review when there is one, one node per `@id`.
	 *
	 * @param array<string, array<string, mixed>> $nodes Local key => node.
	 * @return array{page: array<string, mixed>, nodes: array<int, array<string, mixed>>}
	 */
	public static function assemble( array $nodes ): array {
		$page  = (array) ( $nodes['@page'] ?? array() );
		$notes = array();
		foreach ( $nodes as $key => $node ) {
			if ( 0 === strpos( (string) $key, '@notes' ) ) {
				$notes[] = $node;
			}
		}
		$review = null;
		foreach ( array_keys( $nodes ) as $key ) {
			if ( 1 === preg_match( '/^review(-\d+)?$/', (string) $key ) ) {
				$review = $key;
				break;
			}
		}
		if ( null !== $review && array() !== $notes ) {
			foreach ( $notes as $n ) {
				foreach ( $n as $prop => $value ) {
					$nodes[ $review ][ $prop ] = $value;
				}
			}
			foreach ( array_keys( $nodes ) as $key ) {
				if ( 1 === preg_match( '/^proscons(-\d+)?$/', (string) $key ) ) {
					unset( $nodes[ $key ] );
				}
			}
		}
		$out = array();
		foreach ( $nodes as $key => $node ) {
			if ( '@' === substr( (string) $key, 0, 1 ) || ! is_array( $node ) || array() === $node ) {
				continue;
			}
			$id = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			if ( '' !== $id && isset( $out[ $id ] ) ) {
				$out[ $id ] = array_merge( $out[ $id ], $node );
				continue;
			}
			$out[ '' !== $id ? $id : 'k:' . $key ] = $node;
		}

		return array(
			'page'  => $page,
			'nodes' => array_values( $out ),
		);
	}

	/**
	 * Merge two lists of questions, dropping a question asked twice.
	 *
	 * @param array<int, mixed> $a Questions.
	 * @param array<int, mixed> $b Questions.
	 * @return array<int, mixed>
	 */
	public static function merge_questions( array $a, array $b ): array {
		$seen = array();
		$out  = array();
		foreach ( array_merge( $a, $b ) as $q ) {
			$name = is_array( $q ) ? strtolower( Html::plain( $q['name'] ?? '' ) ) : '';
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$out[]         = $q;
		}

		return $out;
	}

	/**
	 * The nodes, by local key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function nodes(): array {
		return self::$nodes;
	}

	/**
	 * The graph has been printed: later instances cannot join it.
	 *
	 * @return void
	 */
	public static function seal(): void {
		self::$sealed = true;
	}

	/**
	 * Is the graph printed?
	 *
	 * @return bool
	 */
	public static function sealed(): bool {
		return self::$sealed;
	}

	/**
	 * Instances that arrived too late.
	 *
	 * @return int
	 */
	public static function late(): int {
		return self::$late;
	}

	/**
	 * Start again (tests, and computing another page's graph in wp-admin).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$seen   = array();
		self::$nodes  = array();
		self::$late   = 0;
		self::$sealed = false;
	}
}
