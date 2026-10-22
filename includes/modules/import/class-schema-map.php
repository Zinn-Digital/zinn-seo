<?php
/**
 * Structured-data types another SEO plugin held for a page, in the shape of our own forms.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every plugin that lets a page be "a recipe", "an event", "a product" keeps the answers in its
 * own layout. Each source turns its layout into the node search engines read (schema.org names:
 * `prepTime`, `startDate`, `offers.price`…) and this class turns that node into ONE of ours:
 *
 * - a type we have a form for (recipe, event, job, course, software, book, film, review, service,
 *   video, FAQ, how-to, question and answers) becomes that form's answers, so the page's owner
 *   edits it afterwards like one they made here;
 * - any other type (a product on a site without a shop, a restaurant, a person, an album…) is
 *   kept whole as `thing`: its type, name, description, picture and address, and every other
 *   property as it was. Nothing a page said about itself is dropped because we have no form.
 *
 * A subtype (MusicEvent, MobileApplication) is kept as `thing` rather than flattened to the form
 * of its parent, which would print the parent's name.
 *
 * Pure: no database read apart from matching a picture's address to the media library.
 */
final class Schema_Map {

	/** Properties that describe the node's place in the other plugin's graph, not the thing. */
	private const DROP = array( '@context', '@id', 'metadata', 'mainEntityOfPage', 'isPartOf', 'publisher', 'inLanguage' );

	/** Properties whose value is one of schema.org's own choices. */
	private const CHOICES = array( 'availability', 'eventStatus', 'eventAttendanceMode', 'itemCondition', 'bookFormat' );

	/** Longest JSON kept for one page (the field's limit, with room to spare). */
	private const MAX = 50000;

	/**
	 * The page's types as our list, encoded for the `schema_import` field ('' when none).
	 *
	 * @param array<int, mixed> $nodes   Nodes (schema.org shape, the other plugin's variables resolved or not).
	 * @param callable|null     $resolve `function ( string $text ): string` for text holding the other plugin's variables.
	 * @return string
	 */
	public static function encode( array $nodes, ?callable $resolve = null ): string {
		$items = self::items( $nodes, $resolve );
		while ( array() !== $items ) {
			$json = (string) wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( strlen( $json ) <= self::MAX ) {
				return $json;
			}
			array_pop( $items );
		}

		return '';
	}

	/**
	 * A stored list back as items (what Zinn® SEO Pro reads).
	 *
	 * @param mixed $json The field's value.
	 * @return array<int, array{type: string, values: array<string, mixed>}>
	 */
	public static function decode( $json ): array {
		$list = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && is_string( $item['type'] ?? null ) && 1 === preg_match( '/^[a-z]{2,20}$/', $item['type'] ) ) {
				$out[] = array(
					'type'   => $item['type'],
					'values' => (array) ( $item['values'] ?? array() ),
				);
			}
		}

		return $out;
	}

	/**
	 * Nodes as our items.
	 *
	 * @param array<int, mixed> $nodes   Nodes.
	 * @param callable|null     $resolve Resolver for variables.
	 * @return array<int, array{type: string, values: array<string, mixed>}>
	 */
	public static function items( array $nodes, ?callable $resolve = null ): array {
		$out = array();
		foreach ( array_slice( array_values( $nodes ), 0, 20 ) as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node = self::resolved( $node, $resolve ?? static fn( string $t ): string => $t );
			$item = self::item( $node );
			if ( null !== $item ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Every string of a node with the other plugin's variables resolved, empty values removed.
	 *
	 * @param array<mixed, mixed> $node    Node.
	 * @param callable            $resolve Resolver.
	 * @param int                 $depth   Nesting.
	 * @return array<mixed, mixed>
	 */
	private static function resolved( array $node, callable $resolve, int $depth = 0 ): array {
		$out = array();
		foreach ( $node as $key => $value ) {
			if ( 0 === $depth && in_array( $key, self::DROP, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = $depth < 6 ? self::resolved( $value, $resolve, $depth + 1 ) : array();
				if ( array() === $value ) {
					continue;
				}
			} elseif ( is_string( $value ) ) {
				$value = trim( '@type' === $key ? $value : (string) $resolve( $value ) );
				if ( '' === $value ) {
					continue;
				}
				// A choice from schema.org's lists is written as its address (plugins store the
				// bare word and add the address when they print).
				if ( in_array( $key, self::CHOICES, true ) && 1 === preg_match( '/^[A-Z][A-Za-z]{2,60}$/', $value ) ) {
					$value = 'https://schema.org/' . $value;
				}
			} elseif ( null === $value || false === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * One node as one of our items (null when it says nothing).
	 *
	 * @param array<mixed, mixed> $n Node, resolved.
	 * @return array{type: string, values: array<string, mixed>}|null
	 */
	public static function item( array $n ): ?array {
		$type = $n['@type'] ?? '';
		$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
		$type = (string) preg_replace( '#^https?://schema\.org/#', '', $type );
		if ( 1 !== preg_match( '/^[A-Z][A-Za-z0-9]{1,60}$/', $type ) ) {
			return null;
		}
		$v = array();
		switch ( $type ) {
			case 'Recipe':
				$steps = array();
				foreach ( self::rows( $n['recipeInstructions'] ?? array() ) as $step ) {
					foreach ( is_array( $step ) && isset( $step['itemListElement'] ) ? self::rows( $step['itemListElement'] ) : array( $step ) as $one ) {
						$text = is_array( $one ) ? self::s( $one, 'text', self::s( $one, 'name' ) ) : ( is_scalar( $one ) ? trim( (string) $one ) : '' );
						if ( '' !== $text ) {
							$steps[] = array( 'text' => $text );
						}
					}
				}
				$v = array(
					'name'         => self::s( $n, 'name' ),
					'description'  => self::s( $n, 'description' ),
					'image'        => self::picture( $n['image'] ?? '' ),
					'prep'         => self::minutes( self::s( $n, 'prepTime' ) ),
					'cook'         => self::minutes( self::s( $n, 'cookTime' ) ),
					'yield'        => self::s( $n, 'recipeYield' ),
					'category'     => self::s( $n, 'recipeCategory' ),
					'cuisine'      => self::s( $n, 'recipeCuisine' ),
					'keywords'     => self::s( $n, 'keywords' ),
					'calories'     => self::s( (array) ( $n['nutrition'] ?? array() ), 'calories' ),
					'ingredients'  => self::strings( $n['recipeIngredient'] ?? array() ),
					'instructions' => $steps,
				);
				return self::out( 'recipe', $v );
			case 'Event':
				$place  = self::first( $n['location'] ?? array(), 'Place' );
				$online = self::first( $n['location'] ?? array(), 'VirtualLocation' );
				$offer  = self::offer( $n );
				$mode   = self::s( $n, 'eventAttendanceMode' );
				$v      = array(
					'name'        => self::s( $n, 'name' ),
					'description' => self::s( $n, 'description' ),
					'image'       => self::picture( $n['image'] ?? '' ),
					'start'       => self::moment( self::s( $n, 'startDate' ) ),
					'end'         => self::moment( self::s( $n, 'endDate' ) ),
					'mode'        => false !== stripos( $mode, 'Mixed' ) ? 'Mixed' : ( false !== stripos( $mode, 'Online' ) ? 'Online' : 'Offline' ),
					'status'      => self::tail( self::s( $n, 'eventStatus' ), array( 'EventScheduled', 'EventCancelled', 'EventPostponed', 'EventRescheduled', 'EventMovedOnline' ), 'EventScheduled' ),
					'online_url'  => self::s( $online, 'url' ),
					'tickets'     => $offer['url'],
					'organizer'   => self::name( $n['organizer'] ?? '' ),
					'performer'   => self::name( $n['performer'] ?? '' ),
				) + self::place( $place ) + array(
					'price'    => $offer['price'],
					'currency' => $offer['currency'],
				);
				return self::out( 'event', $v );
			case 'JobPosting':
				$org    = is_array( $n['hiringOrganization'] ?? null ) ? $n['hiringOrganization'] : array();
				$salary = is_array( $n['baseSalary'] ?? null ) ? $n['baseSalary'] : array();
				$amount = is_array( $salary['value'] ?? null ) ? $salary['value'] : array( 'value' => $salary['value'] ?? '' );
				$kind   = self::strings( $n['employmentType'] ?? array() );
				$v      = array(
					'title'       => self::s( $n, 'title', self::s( $n, 'name' ) ),
					'description' => self::s( $n, 'description' ),
					'posted'      => self::day( self::s( $n, 'datePosted' ) ),
					'until'       => self::day( self::s( $n, 'validThrough' ) ),
					'employment'  => self::tail( (string) ( $kind[0] ?? '' ), array( 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' ), 'FULL_TIME' ),
					'company'     => self::name( $org ),
					'company_url' => self::s( $org, 'sameAs', self::s( $org, 'url' ) ),
					'logo'        => self::picture( $org['logo'] ?? '' ),
					'remote'      => 'TELECOMMUTE' === strtoupper( self::s( $n, 'jobLocationType' ) ),
					'remote_area' => self::name( $n['applicantLocationRequirements'] ?? '' ),
					'salary_min'  => self::number( $amount['minValue'] ?? ( $amount['value'] ?? '' ) ),
					'salary_max'  => self::number( $amount['maxValue'] ?? '' ),
					'currency'    => strtoupper( self::s( $salary, 'currency' ) ),
					'salary_unit' => self::tail( self::s( $amount, 'unitText' ), array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ), 'YEAR' ),
					'direct'      => ! empty( $n['directApply'] ) && 'false' !== $n['directApply'],
				) + self::place( self::first( $n['jobLocation'] ?? array(), 'Place' ) );
				return self::out( 'job', $v );
			case 'Course':
				$org   = is_array( $n['provider'] ?? null ) ? $n['provider'] : array( 'name' => $n['provider'] ?? '' );
				$offer = self::offer( $n );
				$inst  = self::first( $n['hasCourseInstance'] ?? array(), 'CourseInstance' );
				$v     = array(
					'name'         => self::s( $n, 'name' ),
					'description'  => self::s( $n, 'description' ),
					'provider'     => self::name( $org ),
					'provider_url' => self::s( $org, 'sameAs', self::s( $org, 'url' ) ),
					'mode'         => self::tail( self::s( $inst, 'courseMode' ), array( 'Online', 'Onsite', 'Blended' ), 'Online' ),
					'language'     => self::s( $n, 'inLanguage' ),
					'price'        => $offer['price'],
					'currency'     => $offer['currency'],
				);
				return self::out( 'course', $v );
			case 'SoftwareApplication':
				$offer = self::offer( $n );
				$stars = is_array( $n['aggregateRating'] ?? null ) ? $n['aggregateRating'] : array();
				$v     = array(
					'name'     => self::s( $n, 'name' ),
					'category' => self::tail( self::s( $n, 'applicationCategory' ), array( 'BusinessApplication', 'DeveloperApplication', 'GameApplication', 'MultimediaApplication', 'EducationalApplication', 'LifestyleApplication', 'UtilitiesApplication', 'SecurityApplication', 'HealthApplication', 'FinanceApplication', 'CommunicationApplication' ), 'BusinessApplication' ),
					'os'       => self::s( $n, 'operatingSystem' ),
					'version'  => self::s( $n, 'softwareVersion' ),
					'url'      => self::s( $n, 'url', $offer['url'] ),
					'rating'   => self::number( $stars['ratingValue'] ?? '' ),
					'ratings'  => self::number( $stars['ratingCount'] ?? ( $stars['reviewCount'] ?? '' ) ),
					'price'    => $offer['price'],
					'currency' => $offer['currency'],
				);
				// A free app has a real price of zero (kept as text so it is not taken for "no answer").
				if ( 0.0 === $v['price'] && '' !== $v['currency'] && array() !== self::first( $n['offers'] ?? array(), 'Offer' ) ) {
					$v['price'] = '0';
				}
				return self::out( 'software', $v );
			case 'Book':
				$edition = self::first( $n['hasPart'] ?? ( $n['workExample'] ?? array() ), 'Book' );
				$v       = array(
					'name'      => self::s( $n, 'name' ),
					'author'    => self::name( $n['author'] ?? '' ),
					'isbn'      => self::s( $n, 'isbn', self::s( $edition, 'isbn' ) ),
					'pages'     => self::number( $n['numberOfPages'] ?? ( $edition['numberOfPages'] ?? '' ) ),
					'publisher' => self::name( $n['publisher'] ?? ( $edition['publisher'] ?? '' ) ),
					'published' => self::day( self::s( $n, 'datePublished', self::s( $edition, 'datePublished' ) ) ),
					'format'    => self::tail( self::s( $n, 'bookFormat', self::s( $edition, 'bookFormat' ) ), array( 'Hardcover', 'Paperback', 'EBook', 'AudiobookFormat' ), 'Hardcover' ),
					'image'     => self::picture( $n['image'] ?? '' ),
				);
				return self::out( 'book', $v );
			case 'Movie':
				$stars = is_array( $n['aggregateRating'] ?? null ) ? $n['aggregateRating'] : array();
				$v     = array(
					'name'     => self::s( $n, 'name' ),
					'image'    => self::picture( $n['image'] ?? '' ),
					'director' => self::name( $n['director'] ?? '' ),
					'released' => self::day( self::s( $n, 'dateCreated', self::s( $n, 'datePublished' ) ) ),
					'minutes'  => self::minutes( self::s( $n, 'duration' ) ),
					'rating'   => self::number( $stars['ratingValue'] ?? '' ),
					'ratings'  => self::number( $stars['ratingCount'] ?? ( $stars['reviewCount'] ?? '' ) ),
				);
				return self::out( 'movie', $v );
			case 'Review':
				$thing = is_array( $n['itemReviewed'] ?? null ) ? $n['itemReviewed'] : array( 'name' => $n['itemReviewed'] ?? '' );
				$stars = is_array( $n['reviewRating'] ?? null ) ? $n['reviewRating'] : array();
				$kind  = is_array( $thing['@type'] ?? null ) ? (string) reset( $thing['@type'] ) : (string) ( $thing['@type'] ?? '' );
				$v     = array(
					'item'      => self::name( $thing ),
					'item_type' => self::tail( $kind, array( 'Product', 'SoftwareApplication', 'Book', 'Movie', 'Recipe', 'LocalBusiness' ), 'Product' ),
					'rating'    => self::number( $stars['ratingValue'] ?? '' ),
					'best'      => max( 1, (int) self::number( $stars['bestRating'] ?? 5 ) ),
					'body'      => self::s( $n, 'reviewBody', self::s( $n, 'description' ) ),
				);
				return '' === $v['item'] ? null : self::out( 'review', $v );
			case 'Service':
				$offer = self::offer( $n );
				$v     = array(
					'name'        => self::s( $n, 'name' ),
					'kind'        => self::s( $n, 'serviceType' ),
					'description' => self::s( $n, 'description' ),
					'area'        => self::name( $n['areaServed'] ?? '' ),
					'url'         => self::s( $n, 'url', $offer['url'] ),
					'price'       => $offer['price'],
					'currency'    => $offer['currency'],
				);
				return self::out( 'service', $v );
			case 'VideoObject':
				$secs = self::seconds( self::s( $n, 'duration' ) );
				$v    = array(
					'name'        => self::s( $n, 'name' ),
					'description' => self::s( $n, 'description' ),
					'thumbnail'   => self::picture( $n['thumbnailUrl'] ?? ( $n['image'] ?? '' ) ),
					'uploaded'    => self::day( self::s( $n, 'uploadDate' ) ),
					'duration'    => $secs > 0 ? ( $secs >= 3600 ? intdiv( $secs, 3600 ) . ':' . str_pad( (string) intdiv( $secs % 3600, 60 ), 2, '0', STR_PAD_LEFT ) : (string) intdiv( $secs, 60 ) ) . ':' . str_pad( (string) ( $secs % 60 ), 2, '0', STR_PAD_LEFT ) : '',
					'embed'       => self::s( $n, 'embedUrl' ),
					'file'        => self::s( $n, 'contentUrl' ),
				);
				return self::out( 'video', $v );
			case 'FAQPage':
				$items = array();
				foreach ( self::rows( $n['mainEntity'] ?? array() ) as $q ) {
					$answer = is_array( $q ) && is_array( $q['acceptedAnswer'] ?? null ) ? self::s( $q['acceptedAnswer'], 'text' ) : '';
					if ( is_array( $q ) && '' !== self::s( $q, 'name' ) && '' !== $answer ) {
						$items[] = array(
							'question' => self::s( $q, 'name' ),
							'answer'   => $answer,
						);
					}
				}
				return array() === $items ? null : self::out( 'faq', array( 'items' => $items ) );
			case 'HowTo':
				$steps = array();
				foreach ( self::rows( $n['step'] ?? array() ) as $step ) {
					if ( is_array( $step ) && ( '' !== self::s( $step, 'text' ) || '' !== self::s( $step, 'name' ) ) ) {
						$steps[] = array(
							'name' => self::s( $step, 'name' ),
							'text' => self::s( $step, 'text' ),
						);
					}
				}
				$v = array(
					'name'        => self::s( $n, 'name' ),
					'description' => self::s( $n, 'description' ),
					'minutes'     => self::minutes( self::s( $n, 'totalTime' ) ),
					'steps'       => $steps,
				);
				return array() === $steps ? null : self::out( 'howto', $v );
		}

		// No form of ours: the thing itself, whole.
		$more = $n;
		unset( $more['@type'], $more['name'], $more['description'], $more['url'] );
		$image = self::picture( $n['image'] ?? '' );
		if ( $image > 0 ) {
			unset( $more['image'] );
		}
		$v = array(
			'kind'        => $type,
			'name'        => self::s( $n, 'name' ),
			'description' => self::s( $n, 'description' ),
			'image'       => $image,
			'url'         => self::s( $n, 'url' ),
			'more'        => array() === $more ? '' : (string) wp_json_encode( $more, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);

		return self::out( 'thing', $v );
	}

	/**
	 * An item, its empty answers left out (null when nothing is left but defaults).
	 *
	 * @param string               $type   Our form.
	 * @param array<string, mixed> $values Answers.
	 * @return array{type: string, values: array<string, mixed>}|null
	 */
	private static function out( string $type, array $values ): ?array {
		foreach ( $values as $key => $value ) {
			if ( '' === $value || array() === $value || 0 === $value || 0.0 === $value || false === $value || null === $value ) {
				unset( $values[ $key ] );
			}
		}
		$said = $values;
		unset( $said['mode'], $said['status'], $said['employment'], $said['salary_unit'], $said['category'], $said['format'], $said['item_type'], $said['best'], $said['kind'] );

		return array() === $said && 'thing' !== $type ? null : array(
			'type'   => $type,
			'values' => $values,
		);
	}

	/**
	 * A text property ('' when it is not text).
	 *
	 * @param array<mixed, mixed> $n        Node.
	 * @param string              $key      Property.
	 * @param string              $fallback When empty.
	 * @return string
	 */
	private static function s( array $n, string $key, string $fallback = '' ): string {
		$value = $n[ $key ] ?? '';
		if ( is_array( $value ) && isset( $value[0] ) && is_scalar( $value[0] ) ) {
			$value = $value[0];
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' !== $value ? $value : $fallback;
	}

	/**
	 * The name of a thing given as text or as a node (or a list of either: the first).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function name( $value ): string {
		if ( is_array( $value ) && isset( $value[0] ) ) {
			$value = $value[0];
		}
		if ( is_array( $value ) ) {
			return self::s( $value, 'name' );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A value as a list of rows (a single node becomes a one-row list).
	 *
	 * @param mixed $value Value.
	 * @return array<int, mixed>
	 */
	private static function rows( $value ): array {
		if ( ! is_array( $value ) ) {
			return '' === $value || null === $value ? array() : array( $value );
		}

		return isset( $value['@type'] ) || ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ? array( $value ) : array_values( $value );
	}

	/**
	 * A list of non-empty strings.
	 *
	 * @param mixed $value Value (a list, or text with one entry per line).
	 * @return array<int, string>
	 */
	private static function strings( $value ): array {
		$list = is_array( $value ) ? $value : (array) preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$out  = array();
		foreach ( $list as $one ) {
			$one = is_scalar( $one ) ? trim( (string) $one ) : '';
			if ( '' !== $one ) {
				$out[] = $one;
			}
		}

		return $out;
	}

	/**
	 * The first node of a kind in a value that is one node or a list (a node with no type counts).
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Wanted type.
	 * @return array<mixed, mixed>
	 */
	private static function first( $value, string $type ): array {
		foreach ( self::rows( $value ) as $row ) {
			if ( is_array( $row ) && ( ! isset( $row['@type'] ) || $type === $row['@type'] || ( 'Place' === $type && 'VirtualLocation' !== $row['@type'] ) ) ) {
				return $row;
			}
		}

		return array();
	}

	/**
	 * The place fields of our forms from a Place node.
	 *
	 * @param array<mixed, mixed> $place Place.
	 * @return array<string, string>
	 */
	private static function place( array $place ): array {
		$address = is_array( $place['address'] ?? null ) ? $place['address'] : array( 'streetAddress' => $place['address'] ?? '' );
		$country = $address['addressCountry'] ?? '';

		return array(
			'venue'    => self::s( $place, 'name' ),
			'street'   => self::s( $address, 'streetAddress' ),
			'locality' => self::s( $address, 'addressLocality' ),
			'region'   => self::s( $address, 'addressRegion' ),
			'postcode' => self::s( $address, 'postalCode' ),
			'country'  => is_array( $country ) ? self::s( $country, 'name' ) : ( is_scalar( $country ) ? trim( (string) $country ) : '' ),
		);
	}

	/**
	 * Price, currency and address of a node's first offer.
	 *
	 * @param array<mixed, mixed> $n Node.
	 * @return array{price: float, currency: string, url: string}
	 */
	private static function offer( array $n ): array {
		$offer = self::first( $n['offers'] ?? array(), 'Offer' );

		return array(
			'price'    => self::number( $offer['price'] ?? ( $offer['lowPrice'] ?? '' ) ),
			'currency' => strtoupper( self::s( $offer, 'priceCurrency' ) ),
			'url'      => self::s( $offer, 'url' ),
		);
	}

	/**
	 * A number from text ("19.99", "1,200").
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private static function number( $value ): float {
		$value = is_scalar( $value ) ? str_replace( array( ',', ' ' ), '', (string) $value ) : '';

		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Seconds of an ISO 8601 duration (PT1H20M30S), or of plain minutes.
	 *
	 * @param string $value Duration.
	 * @return int
	 */
	private static function seconds( string $value ): int {
		if ( 1 === preg_match( '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', trim( $value ), $m ) && strlen( trim( $value ) ) > 1 ) {
			return ( (int) ( $m[1] ?? 0 ) ) * 86400 + ( (int) ( $m[2] ?? 0 ) ) * 3600 + ( (int) ( $m[3] ?? 0 ) ) * 60 + (int) ( $m[4] ?? 0 );
		}

		return is_numeric( $value ) ? (int) round( (float) $value * 60 ) : 0;
	}

	/**
	 * Whole minutes of a duration.
	 *
	 * @param string $value Duration.
	 * @return int
	 */
	private static function minutes( string $value ): int {
		return (int) round( self::seconds( $value ) / 60 );
	}

	/**
	 * A date as YYYY-MM-DD ('' when it is not one).
	 *
	 * @param string $value Date, any common form.
	 * @return string
	 */
	private static function day( string $value ): string {
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) ) {
			return $m[1];
		}
		$time = '' !== $value ? ( is_numeric( $value ) ? (int) $value : strtotime( $value ) ) : false;

		return false !== $time && $time > 0 ? gmdate( 'Y-m-d', $time ) : '';
	}

	/**
	 * A date and time as "YYYY-MM-DD HH:MM" (the clock time as written, whatever its zone).
	 *
	 * @param string $value Date and time.
	 * @return string
	 */
	private static function moment( string $value ): string {
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $value, $m ) ) {
			return $m[1] . ( isset( $m[2] ) ? ' ' . $m[2] : '' );
		}
		$time = '' !== $value ? ( is_numeric( $value ) ? (int) $value : strtotime( $value ) ) : false;

		return false !== $time && $time > 0 ? gmdate( 'Y-m-d H:i', $time ) : '';
	}

	/**
	 * One of our choices from a value that may be an address ending in it, in any case.
	 *
	 * @param string             $value    Value (`https://schema.org/EventCancelled`, `part_time`).
	 * @param array<int, string> $choices  Our choices.
	 * @param string             $fallback When none matches.
	 * @return string
	 */
	private static function tail( string $value, array $choices, string $fallback ): string {
		$value = (string) preg_replace( '#^.*/#', '', trim( $value ) );
		foreach ( $choices as $choice ) {
			if ( 0 === strcasecmp( $choice, $value ) || 0 === strcasecmp( $choice, $value . 'EventAttendanceMode' ) ) {
				return $choice;
			}
		}

		return $fallback;
	}

	/**
	 * The media library ID of a picture given as an address, a node or a list of either (0 when
	 * it is not in the library).
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function picture( $value ): int {
		if ( is_array( $value ) && isset( $value[0] ) ) {
			$value = $value[0];
		}
		if ( is_array( $value ) ) {
			$value = $value['url'] ?? ( $value['contentUrl'] ?? '' );
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return 'attachment' === get_post_type( (int) $value ) ? (int) $value : 0;
		}

		return is_string( $value ) && '' !== $value ? Source::image_id( $value ) : 0;
	}
}
