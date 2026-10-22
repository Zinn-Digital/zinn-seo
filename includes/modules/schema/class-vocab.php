<?php
/**
 * The schema.org vocabulary, offline (zs-035, zs-inn-08).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `data/vocab.json` is generated from a pinned schema.org release by wp/bin/zseo-schema-vocab.py:
 * every type with its parents, every property with the types it belongs to (`d`), the types its
 * value may have (`r`) and what replaced it (`s`). Read only in wp-admin, REST and WP-CLI (the
 * validator and the schema builder), never on a visitor's page.
 */
final class Vocab {

	/**
	 * The loaded vocabulary.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * Ancestors per type (memo).
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $ancestors = array();

	/**
	 * The vocabulary.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		if ( null === self::$data ) {
			$raw        = file_get_contents( __DIR__ . '/data/vocab.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.
			$data       = is_string( $raw ) ? json_decode( $raw, true ) : null;
			self::$data = is_array( $data ) ? $data : array(
				'version' => '',
				'types'   => array(),
				'props'   => array(),
			);
		}

		return self::$data;
	}

	/**
	 * The release the vocabulary was built from.
	 *
	 * @return string
	 */
	public static function version(): string {
		return (string) ( self::data()['version'] ?? '' );
	}

	/**
	 * Is this a schema.org type?
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_type( string $type ): bool {
		return isset( self::data()['types'][ $type ] );
	}

	/**
	 * A type and every type it descends from.
	 *
	 * @param string $type Type.
	 * @return array<int, string>
	 */
	public static function ancestors( string $type ): array {
		if ( isset( self::$ancestors[ $type ] ) ) {
			return self::$ancestors[ $type ];
		}
		$types = self::data()['types'];
		$out   = array();
		$queue = array( $type );
		while ( array() !== $queue ) {
			$t = (string) array_shift( $queue );
			if ( isset( $out[ $t ] ) ) {
				continue;
			}
			$out[ $t ] = true;
			foreach ( (array) ( $types[ $t ] ?? array() ) as $parent ) {
				$queue[] = (string) $parent;
			}
		}
		self::$ancestors[ $type ] = array_keys( $out );

		return self::$ancestors[ $type ];
	}

	/**
	 * Is a type the same as, or a kind of, another?
	 *
	 * @param string $type   Type.
	 * @param string $ancestor Possible ancestor.
	 * @return bool
	 */
	public static function is_a( string $type, string $ancestor ): bool {
		return in_array( $ancestor, self::ancestors( $type ), true );
	}

	/**
	 * Does a property belong to a type (directly or through its ancestors)?
	 *
	 * @param string $type Type.
	 * @param string $prop Property.
	 * @return bool|null Null when the property does not exist at all.
	 */
	public static function has_property( string $type, string $prop ): ?bool {
		$def = self::data()['props'][ $prop ] ?? null;
		if ( ! is_array( $def ) ) {
			return null;
		}

		return array() !== array_intersect( (array) $def['d'], self::ancestors( $type ) );
	}

	/**
	 * What replaced a property, when it was replaced.
	 *
	 * @param string $prop Property.
	 * @return array<int, string>
	 */
	public static function superseded_by( string $prop ): array {
		return (array) ( self::data()['props'][ $prop ]['s'] ?? array() );
	}

	/**
	 * The types a property's value may have.
	 *
	 * @param string $prop Property.
	 * @return array<int, string>
	 */
	public static function ranges( string $prop ): array {
		return (array) ( self::data()['props'][ $prop ]['r'] ?? array() );
	}

	/**
	 * Types whose name starts with or contains a text (the builder's search).
	 *
	 * @param string $q     Text.
	 * @param int    $limit At most.
	 * @return array<int, string>
	 */
	public static function search_types( string $q, int $limit = 20 ): array {
		$q     = strtolower( trim( $q ) );
		$first = array();
		$then  = array();
		foreach ( array_keys( self::data()['types'] ) as $type ) {
			$l = strtolower( (string) $type );
			if ( '' === $q || 0 === strpos( $l, $q ) ) {
				$first[] = (string) $type;
			} elseif ( false !== strpos( $l, $q ) ) {
				$then[] = (string) $type;
			}
		}

		return array_slice( array_merge( $first, $then ), 0, $limit );
	}

	/**
	 * The properties of a type (its own and inherited), sorted.
	 *
	 * @param string $type Type.
	 * @return array<int, array{name: string, ranges: array<int, string>}>
	 */
	public static function properties_of( string $type ): array {
		$anc = self::ancestors( $type );
		$out = array();
		foreach ( self::data()['props'] as $name => $def ) {
			if ( isset( $def['s'] ) ) {
				continue; // Replaced properties are not offered.
			}
			if ( array() !== array_intersect( (array) $def['d'], $anc ) ) {
				$out[] = array(
					'name'   => (string) $name,
					'ranges' => (array) $def['r'],
				);
			}
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );

		return $out;
	}
}
