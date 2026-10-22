<?php
/**
 * A business with a place: address, opening hours, phone, map position.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations\Local;

use ZinnDigital\ZinnSEO\Integrations\Kit;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the site says it is a business with premises (Local → "This site is a local business"),
 * the node that says who runs the site becomes that business: a `LocalBusiness` (or the more
 * exact kind chosen — Restaurant, Dentist, Store…) with its postal address, map position, phone,
 * opening hours, price range and the area it serves. Same `@id`, so everything that pointed at
 * the organisation still does.
 *
 * The map link opens OpenStreetMap in the visitor's browser when they choose to follow it: no map
 * script is ever loaded on the site.
 *
 * `business()` builds the node from plain values and is shared with the locations of a business
 * that has several (Zinn® SEO Pro).
 */
final class Local {

	/** Day codes, as opening hours are typed, and their schema.org names. */
	public const DAYS = array(
		'mo' => 'Monday',
		'tu' => 'Tuesday',
		'we' => 'Wednesday',
		'th' => 'Thursday',
		'fr' => 'Friday',
		'sa' => 'Saturday',
		'su' => 'Sunday',
	);

	/** The settings a business is described with: setting suffix => value key. */
	public const FIELDS = array( 'type', 'name', 'phone', 'email', 'street', 'locality', 'region', 'postcode', 'country', 'lat', 'lng', 'price_range', 'area', 'hours' );

	/**
	 * Hooks.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the integration contract.
		if ( (bool) Settings::get( 'local_on', false ) ) {
			Kit::graph( array( self::class, 'graph' ), 20 );
		}
	}

	/**
	 * The site's own business details.
	 *
	 * @return array<string, string>
	 */
	public static function site(): array {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = (string) Settings::get( 'local_' . $field, '' );
		}

		return $out;
	}

	/**
	 * The site's identity node as the business it is.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @param Context                             $c     The page.
	 * @return array<string, array<string, mixed>>
	 */
	public static function graph( array $nodes, Context $c ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the graph filter's signature.
		if ( ! isset( $nodes['identity'] ) || ! is_array( $nodes['identity'] ) ) {
			return $nodes;
		}
		$types = (array) ( $nodes['identity']['@type'] ?? array() );
		if ( in_array( 'Person', $types, true ) ) {
			return $nodes; // A person's site: a business belongs to a location or a block.
		}
		$v = self::site();
		if ( '' === $v['name'] ) {
			$v['name'] = (string) ( $nodes['identity']['name'] ?? '' );
		}
		$business = self::business( $v );
		if ( count( $business ) < 3 ) {
			return $nodes; // Nothing said beyond a name: stay an organisation.
		}
		// What the site already says about itself (logo, profiles, contact points) stays; the
		// business adds its place and replaces the type and the name.
		$nodes['identity'] = array_merge( $nodes['identity'], $business );

		return $nodes;
	}

	/**
	 * A business as a node (no `@id`: the caller places it).
	 *
	 * @param array<string, mixed> $v `type`, `name`, `phone`, `email`, `street`, `locality`, `region`, `postcode`, `country`, `lat`, `lng`, `price_range`, `area`, `hours`, and optionally `url`, `image`.
	 * @return array<string, mixed>
	 */
	public static function business( array $v ): array {
		$s    = static fn( string $key ): string => Kit::text( $v[ $key ] ?? '' );
		$type = (string) preg_replace( '/[^A-Za-z]/', '', $s( 'type' ) );
		$node = array(
			'@type'      => '' !== $type ? $type : 'LocalBusiness',
			'name'       => $s( 'name' ),
			'url'        => esc_url_raw( (string) ( $v['url'] ?? '' ), array( 'http', 'https' ) ),
			'image'      => esc_url_raw( (string) ( $v['image'] ?? '' ), array( 'http', 'https' ) ),
			'telephone'  => $s( 'phone' ),
			'email'      => is_email( $s( 'email' ) ) ? $s( 'email' ) : '',
			'priceRange' => $s( 'price_range' ),
			'areaServed' => $s( 'area' ),
			'address'    => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $s( 'street' ),
				'addressLocality' => $s( 'locality' ),
				'addressRegion'   => $s( 'region' ),
				'postalCode'      => $s( 'postcode' ),
				'addressCountry'  => strtoupper( $s( 'country' ) ),
			),
		);
		$map  = self::map_url( $s( 'lat' ), $s( 'lng' ) );
		if ( '' !== $map ) {
			$node['geo']    = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $s( 'lat' ),
				'longitude' => (float) $s( 'lng' ),
			);
			$node['hasMap'] = $map;
		}
		$hours = self::hours( (string) ( $v['hours'] ?? '' ) );
		if ( array() !== $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}

		return Kit::prune( $node );
	}

	/**
	 * A link that opens the place on OpenStreetMap ('' without a valid position).
	 *
	 * @param string $lat Latitude.
	 * @param string $lng Longitude.
	 * @return string
	 */
	public static function map_url( string $lat, string $lng ): string {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || abs( (float) $lat ) > 90 || abs( (float) $lng ) > 180 ) {
			return '';
		}
		$lat = rtrim( rtrim( number_format( (float) $lat, 6, '.', '' ), '0' ), '.' );
		$lng = rtrim( rtrim( number_format( (float) $lng, 6, '.', '' ), '0' ), '.' );

		return 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=17/' . $lat . '/' . $lng;
	}

	/**
	 * Opening hours as typed, one line per group of days, as schema.org specifications.
	 *
	 *     Mo-Fr 09:00-17:30
	 *     Sa 10:00-14:00
	 *     Su closed
	 *     24/7
	 *
	 * Days: two letters (Mo Tu We Th Fr Sa Su), a range (Mo-Fr) or a list (Mo,We,Fr). Several
	 * times on a line (09:00-12:00 13:00-17:00) are several openings. A line that cannot be read
	 * is left out rather than guessed.
	 *
	 * @param string $text The lines.
	 * @return array<int, array<string, mixed>>
	 */
	public static function hours( string $text ): array {
		$out   = array();
		$codes = array_keys( self::DAYS );
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = strtolower( trim( (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( '24/7' === $line ) {
				return array(
					array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => array_map( static fn( string $d ): string => 'https://schema.org/' . $d, array_values( self::DAYS ) ),
						'opens'     => '00:00',
						'closes'    => '23:59',
					),
				);
			}
			if ( 1 !== preg_match( '/^([a-z,\- ]+?)\s+(closed|[0-9:.\- ]+)$/', $line, $m ) ) {
				continue;
			}
			$days = array();
			foreach ( explode( ',', str_replace( ' ', '', $m[1] ) ) as $part ) {
				$ends = explode( '-', $part );
				$from = array_search( substr( $ends[0], 0, 2 ), $codes, true );
				$to   = array_search( substr( (string) end( $ends ), 0, 2 ), $codes, true );
				if ( false === $from || false === $to ) {
					continue 2;
				}
				for ( $i = $from; ; $i = ( $i + 1 ) % 7 ) { // phpcs:ignore Squiz.Operators.IncrementDecrementUsage.Found -- round the week: Sunday is followed by Monday.
					$days[ $codes[ $i ] ] = 'https://schema.org/' . self::DAYS[ $codes[ $i ] ];
					if ( $i === $to ) {
						break;
					}
				}
			}
			if ( 'closed' === $m[2] ) {
				// Closed all day: opens and closes at the same minute (Google's convention).
				$out[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array_values( $days ),
					'opens'     => '00:00',
					'closes'    => '00:00',
				);
				continue;
			}
			if ( preg_match_all( '/(\d{1,2})[:.](\d{2})\s*-\s*(\d{1,2})[:.](\d{2})/', $m[2], $times, PREG_SET_ORDER ) < 1 ) {
				continue;
			}
			foreach ( $times as $t ) {
				if ( (int) $t[1] > 24 || (int) $t[3] > 24 || (int) $t[2] > 59 || (int) $t[4] > 59 ) {
					continue;
				}
				$out[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array_values( $days ),
					'opens'     => sprintf( '%02d:%02d', (int) $t[1], (int) $t[2] ),
					'closes'    => sprintf( '%02d:%02d', (int) $t[3], (int) $t[4] ),
				);
			}
		}

		return $out;
	}
}
