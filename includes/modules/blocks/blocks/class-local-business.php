<?php
/**
 * Local business: name, address, phone, opening hours, map link (zs-311).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The details a customer looks for — and the same details as LocalBusiness structured data — with
 * a link that opens a map (no map script, no third-party request until the visitor asks).
 */
final class Local_Business extends Block {

	/** Day codes in order, with their schema.org day names. */
	public const DAYS = array(
		'Mo' => 'Monday',
		'Tu' => 'Tuesday',
		'We' => 'Wednesday',
		'Th' => 'Thursday',
		'Fr' => 'Friday',
		'Sa' => 'Saturday',
		'Su' => 'Sunday',
	);

	/**
	 * Business types (schema.org LocalBusiness and the subtypes searched for most).
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'LocalBusiness'               => __( 'Local business', 'zinn-seo' ),
			'Store'                       => __( 'Shop', 'zinn-seo' ),
			'Restaurant'                  => __( 'Restaurant', 'zinn-seo' ),
			'CafeOrCoffeeShop'            => __( 'Café', 'zinn-seo' ),
			'BarOrPub'                    => __( 'Bar or pub', 'zinn-seo' ),
			'Bakery'                      => __( 'Bakery', 'zinn-seo' ),
			'ProfessionalService'         => __( 'Professional service', 'zinn-seo' ),
			'LegalService'                => __( 'Lawyer or legal service', 'zinn-seo' ),
			'AccountingService'           => __( 'Accountant', 'zinn-seo' ),
			'FinancialService'            => __( 'Financial service', 'zinn-seo' ),
			'RealEstateAgent'             => __( 'Estate agent', 'zinn-seo' ),
			'Dentist'                     => __( 'Dentist', 'zinn-seo' ),
			'MedicalClinic'               => __( 'Medical clinic', 'zinn-seo' ),
			'Physician'                   => __( 'Doctor', 'zinn-seo' ),
			'HealthAndBeautyBusiness'     => __( 'Health and beauty', 'zinn-seo' ),
			'HairSalon'                   => __( 'Hair salon', 'zinn-seo' ),
			'HomeAndConstructionBusiness' => __( 'Builder or trades', 'zinn-seo' ),
			'Plumber'                     => __( 'Plumber', 'zinn-seo' ),
			'Electrician'                 => __( 'Electrician', 'zinn-seo' ),
			'AutomotiveBusiness'          => __( 'Car services', 'zinn-seo' ),
			'LodgingBusiness'             => __( 'Hotel or lodging', 'zinn-seo' ),
			'SportsActivityLocation'      => __( 'Gym or sports', 'zinn-seo' ),
			'EducationalOrganization'     => __( 'School or training', 'zinn-seo' ),
			'TravelAgency'                => __( 'Travel agency', 'zinn-seo' ),
			'EntertainmentBusiness'       => __( 'Entertainment', 'zinn-seo' ),
		);
	}

	/** {@inheritDoc} */
	public function name(): string {
		return 'local-business';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Local business', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Address, phone, opening hours and a map link, as local business structured data, with no map script.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'location';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'address', 'zinn-seo' ), __( 'opening hours', 'zinn-seo' ), 'NAP', __( 'map', 'zinn-seo' ) );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		$days = array(
			'Mo-Fr' => __( 'Monday to Friday', 'zinn-seo' ),
			'Mo-Sa' => __( 'Monday to Saturday', 'zinn-seo' ),
			'Mo-Su' => __( 'Every day', 'zinn-seo' ),
			'Sa-Su' => __( 'Weekends', 'zinn-seo' ),
		);
		foreach ( self::DAYS as $code => $day ) {
			$days[ $code ] = self::day_name( $code );
		}

		return array(
			'name'        => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Business name', 'zinn-seo' ),
				'max'   => 200,
			),
			'type'        => array(
				'type'    => 'select',
				'label'   => static fn() => __( 'Type of business', 'zinn-seo' ),
				'default' => 'LocalBusiness',
				'choices' => self::types(),
			),
			'street'      => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Street address', 'zinn-seo' ),
				'max'   => 200,
			),
			'locality'    => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Town or city', 'zinn-seo' ),
				'max'   => 120,
			),
			'region'      => array(
				'type'  => 'text',
				'label' => static fn() => __( 'County, state or region', 'zinn-seo' ),
				'max'   => 120,
			),
			'postcode'    => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Postcode', 'zinn-seo' ),
				'max'   => 20,
			),
			'country'     => array(
				'type'        => 'text',
				'label'       => static fn() => __( 'Country (two letters, e.g. GB)', 'zinn-seo' ),
				'max'         => 2,
				'placeholder' => 'GB',
			),
			'phone'       => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Phone', 'zinn-seo' ),
				'max'   => 40,
			),
			'email'       => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Email', 'zinn-seo' ),
				'max'   => 120,
			),
			'url'         => array(
				'type'  => 'url',
				'label' => static fn() => __( 'Website (empty: this site)', 'zinn-seo' ),
			),
			'price_range' => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Price range (e.g. £££)', 'zinn-seo' ),
				'max'   => 20,
			),
			'lat'         => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Latitude', 'zinn-seo' ),
				'max'   => 20,
			),
			'lng'         => array(
				'type'  => 'text',
				'label' => static fn() => __( 'Longitude', 'zinn-seo' ),
				'max'   => 20,
			),
			'image'       => array(
				'type'  => 'image',
				'label' => static fn() => __( 'Photo of the premises or logo', 'zinn-seo' ),
			),
			'hours'       => array(
				'type'   => 'repeater',
				'label'  => static fn() => __( 'Opening hours', 'zinn-seo' ),
				'item'   => static fn() => __( 'Hours', 'zinn-seo' ),
				'fields' => array(
					'days'   => array(
						'type'    => 'select',
						'label'   => static fn() => __( 'Days', 'zinn-seo' ),
						'default' => 'Mo-Fr',
						'choices' => $days,
					),
					'opens'  => array(
						'type'        => 'text',
						'label'       => static fn() => __( 'Opens (HH:MM)', 'zinn-seo' ),
						'max'         => 5,
						'placeholder' => '09:00',
					),
					'closes' => array(
						'type'        => 'text',
						'label'       => static fn() => __( 'Closes (HH:MM)', 'zinn-seo' ),
						'max'         => 5,
						'placeholder' => '17:30',
					),
					'closed' => array(
						'type'  => 'bool',
						'label' => static fn() => __( 'Closed', 'zinn-seo' ),
					),
				),
			),
			'map_link'    => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Show a link that opens a map', 'zinn-seo' ),
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	public function example(): array {
		return array(
			'name'     => 'Corner Bakery',
			'type'     => 'Bakery',
			'street'   => '1 High Street',
			'locality' => 'Bristol',
			'postcode' => 'BS1 1AA',
			'country'  => 'GB',
			'phone'    => '+44 117 000 0000',
			'hours'    => array(
				array(
					'days'   => 'Mo-Fr',
					'opens'  => '07:00',
					'closes' => '17:00',
				),
			),
		);
	}

	/**
	 * A day's name in the site's language.
	 *
	 * @param string $code Two-letter code.
	 * @return string
	 */
	public static function day_name( string $code ): string {
		global $wp_locale;
		$index = array_search( $code, array_keys( self::DAYS ), true );
		if ( false === $index ) {
			return $code;
		}
		$weekday = ( (int) $index + 1 ) % 7; // WP_Locale counts from Sunday = 0.

		return is_object( $wp_locale ) ? (string) $wp_locale->get_weekday( $weekday ) : self::DAYS[ $code ];
	}

	/**
	 * The days a row covers, as schema.org day names.
	 *
	 * @param string $days `Mo-Fr` or `Sa`.
	 * @return array<int, string>
	 */
	public static function expand( string $days ): array {
		$codes = array_keys( self::DAYS );
		if ( 1 === preg_match( '/^([A-Z][a-z])-([A-Z][a-z])$/', $days, $m ) ) {
			$from = array_search( $m[1], $codes, true );
			$to   = array_search( $m[2], $codes, true );
			if ( false !== $from && false !== $to && $from <= $to ) {
				return array_values( array_map( static fn( string $c ): string => self::DAYS[ $c ], array_slice( $codes, (int) $from, (int) $to - (int) $from + 1 ) ) );
			}
		}

		return isset( self::DAYS[ $days ] ) ? array( self::DAYS[ $days ] ) : array();
	}

	/**
	 * A time `HH:MM`, or '' when it is not one.
	 *
	 * @param string $t Time.
	 * @return string
	 */
	public static function time( string $t ): string {
		return 1 === preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $t ), $m ) ? sprintf( '%02d:%s', (int) $m[1], $m[2] ) : '';
	}

	/**
	 * The map link (OpenStreetMap from coordinates, else an address search).
	 *
	 * @param array<string, mixed> $v Values.
	 * @return string
	 */
	public static function map_url( array $v ): string {
		if ( is_numeric( $v['lat'] ) && is_numeric( $v['lng'] ) ) {
			return 'https://www.openstreetmap.org/?mlat=' . rawurlencode( (string) $v['lat'] ) . '&mlon=' . rawurlencode( (string) $v['lng'] ) . '#map=17/' . rawurlencode( (string) $v['lat'] ) . '/' . rawurlencode( (string) $v['lng'] );
		}
		$address = trim( implode( ', ', array_filter( array( $v['name'], $v['street'], $v['locality'], $v['postcode'], $v['country'] ) ) ) );

		return '' === $address ? '' : 'https://www.openstreetmap.org/search?query=' . rawurlencode( $address );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	public function render( array $v, Render_Context $ctx ): string {
		if ( '' === $v['name'] && '' === $v['street'] && '' === $v['phone'] ) {
			return '';
		}
		$out = Html::open( 'div', Html::cls( 'local' ) );
		if ( '' !== $v['name'] ) {
			$out .= Html::open( 'p', Html::cls( 'local__name' ) ) . esc_html( $v['name'] ) . '</p>';
		}
		$lines = array_filter( array( $v['street'], trim( $v['locality'] . ' ' . $v['postcode'] ), $v['region'] ) );
		if ( array() !== $lines ) {
			$out .= Html::open( 'address', Html::cls( 'local__address' ) ) . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</address>';
		}
		$contact = '';
		if ( '' !== $v['phone'] ) {
			$tel      = (string) preg_replace( '/[^0-9+]/', '', $v['phone'] );
			$contact .= '<li>' . Html::open( 'a', '', array( 'href' => 'tel:' . $tel ) ) . esc_html( $v['phone'] ) . '</a></li>';
		}
		if ( is_email( $v['email'] ) ) {
			$contact .= '<li>' . Html::open( 'a', '', array( 'href' => 'mailto:' . antispambot( $v['email'] ) ) ) . esc_html( antispambot( $v['email'] ) ) . '</a></li>';
		}
		$map = $v['map_link'] ? self::map_url( $v ) : '';
		if ( '' !== $map ) {
			$contact .= '<li>' . Html::open(
				'a',
				'',
				array(
					'href' => $map,
					'rel'  => 'noopener',
				)
			) . esc_html__( 'Open in a map', 'zinn-seo' ) . '</a></li>';
		}
		if ( '' !== $contact ) {
			$out .= Html::open( 'ul', Html::cls( 'local__contact' ) ) . $contact . '</ul>';
		}
		if ( array() !== $v['hours'] ) {
			$out   .= Html::open( 'table', Html::cls( 'local__hours' ) ) . '<caption>' . esc_html__( 'Opening hours', 'zinn-seo' ) . '</caption><tbody>';
			$labels = array();
			foreach ( $this->fields()['hours']['fields']['days']['choices'] as $code => $label ) {
				$labels[ $code ] = $label;
			}
			foreach ( $v['hours'] as $row ) {
				$opens  = self::time( $row['opens'] );
				$closes = self::time( $row['closes'] );
				$when   = $row['closed'] || '' === $opens || '' === $closes ? __( 'Closed', 'zinn-seo' ) : $opens . '–' . $closes;
				$out   .= '<tr><th scope="row">' . esc_html( (string) ( $labels[ $row['days'] ] ?? $row['days'] ) ) . '</th><td>' . esc_html( $when ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}

		return $out . '</div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return array<string, mixed>
	 */
	public function schema( array $v, Render_Context $ctx ): array {
		if ( '' === $v['name'] ) {
			return array();
		}
		$node    = array(
			'@type' => $v['type'],
			'@id'   => $ctx->id( 'local-' . $ctx->instance ),
			'name'  => $v['name'],
			'url'   => '' !== $v['url'] ? $v['url'] : home_url( '/' ),
		);
		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $v['street'],
				'addressLocality' => $v['locality'],
				'addressRegion'   => $v['region'],
				'postalCode'      => $v['postcode'],
				'addressCountry'  => strtoupper( $v['country'] ),
			)
		);
		if ( count( $address ) > 1 ) {
			$node['address'] = $address;
		}
		if ( '' !== $v['phone'] ) {
			$node['telephone'] = $v['phone'];
		}
		if ( is_email( $v['email'] ) ) {
			$node['email'] = $v['email'];
		}
		if ( '' !== $v['price_range'] ) {
			$node['priceRange'] = $v['price_range'];
		}
		if ( is_numeric( $v['lat'] ) && is_numeric( $v['lng'] ) ) {
			$node['geo']    = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $v['lat'],
				'longitude' => (float) $v['lng'],
			);
			$node['hasMap'] = self::map_url( $v );
		}
		if ( $v['image'] > 0 ) {
			$img = wp_get_attachment_image_url( (int) $v['image'], 'large' );
			if ( is_string( $img ) ) {
				$node['image'] = $img;
			}
		}
		foreach ( $v['hours'] as $row ) {
			$opens  = self::time( $row['opens'] );
			$closes = self::time( $row['closes'] );
			$days   = self::expand( $row['days'] );
			if ( array() === $days ) {
				continue;
			}
			$spec = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $days,
			);
			if ( $row['closed'] || '' === $opens || '' === $closes ) {
				// Google's way of saying "closed all day".
				$spec['opens']  = '00:00';
				$spec['closes'] = '00:00';
			} else {
				$spec['opens']  = $opens;
				$spec['closes'] = $closes;
			}
			$node['openingHoursSpecification'][] = $spec;
		}

		return array( 'local' => $node );
	}
}
