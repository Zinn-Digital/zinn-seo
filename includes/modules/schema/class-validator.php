<?php
/**
 * The offline structured-data validator (zs-035, zs-inn-08).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a graph without leaving the site: every type and property against the schema.org
 * vocabulary (pinned release, see Vocab), the shape of values (dates, durations, addresses,
 * currencies, numbers), `@id` references that point nowhere, and Google's rich-result rules with
 * their CURRENT status (Rich_Rules). Pure: it reads only what it is given and the bundled files.
 *
 * Result: `[ 'nodes' => [ [ 'type', 'id', 'feature', 'status', 'note', 'errors', 'warnings' ] ],
 * 'errors' => n, 'warnings' => n, 'vocabulary' => '30.1', 'rules' => '2026-10-09' ]`.
 */
final class Validator {

	/** Properties whose value is a date or a date-time. */
	private const DATES = array( 'datePublished', 'dateModified', 'dateCreated', 'uploadDate', 'startDate', 'endDate', 'validFrom', 'validThrough', 'datePosted', 'lastReviewed', 'priceValidUntil', 'foundingDate', 'birthDate', 'expires', 'temporalCoverage' );

	/** Properties whose value is an ISO 8601 duration. */
	private const DURATIONS = array( 'totalTime', 'cookTime', 'prepTime', 'performTime', 'duration', 'timeRequired' );

	/** Properties whose text value must be an absolute address. */
	private const URLS = array( 'url', 'sameAs', 'contentUrl', 'embedUrl', 'thumbnailUrl', 'logo', 'image', 'item', 'hasMap', 'significantLink', 'target', 'mainEntityOfPage', 'license', 'acquireLicensePage' );

	/** Properties whose value is a number. */
	private const NUMBERS = array( 'ratingValue', 'bestRating', 'worstRating', 'ratingCount', 'reviewCount', 'price', 'lowPrice', 'highPrice', 'position', 'answerCount', 'upvoteCount', 'latitude', 'longitude', 'wordCount', 'startOffset', 'endOffset' );

	/**
	 * Every `@id` in the graph => node.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $index = array();

	/**
	 * Names the site goes by (for the self-serving review check).
	 *
	 * @var array<int, string>
	 */
	private array $own_names = array();

	/**
	 * Apply Google's rich-result rules.
	 *
	 * @var bool
	 */
	private bool $rules = true;

	/**
	 * Validate.
	 *
	 * @param array<mixed>       $graph     A JSON-LD document, a `@graph`, a list of nodes or one node.
	 * @param array<int, string> $own_names The site's own names.
	 * @param bool               $rules     Apply Google's rich-result rules (false: schema.org
	 *                                      only — for details that join a node others complete,
	 *                                      such as a shop's product, zs-764).
	 * @return array<string, mixed>
	 */
	public static function check( array $graph, array $own_names = array(), bool $rules = true ): array {
		$v            = new self();
		$v->rules     = $rules;
		$v->own_names = array_values( array_filter( array_map( static fn( $n ): string => strtolower( trim( (string) $n ) ), $own_names ) ) );
		$nodes        = self::top_nodes( $graph );
		foreach ( $nodes as $node ) {
			$v->index_node( $node );
		}
		$out    = array();
		$errors = 0;
		$warns  = 0;
		foreach ( $nodes as $node ) {
			$row     = $v->check_node( $node );
			$errors += count( $row['errors'] );
			$warns  += count( $row['warnings'] );
			$out[]   = $row;
		}

		return array(
			'nodes'      => $out,
			'errors'     => $errors,
			'warnings'   => $warns,
			'vocabulary' => Vocab::version(),
			'rules'      => Rich_Rules::CHECKED,
		);
	}

	/**
	 * The top-level nodes of whatever was given.
	 *
	 * @param array<mixed> $graph Input.
	 * @return array<int, array<string, mixed>>
	 */
	public static function top_nodes( array $graph ): array {
		if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
			$graph = $graph['@graph'];
		} elseif ( isset( $graph['@type'] ) ) {
			$graph = array( $graph );
		}
		$out = array();
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && isset( $node['@graph'] ) ) {
				$out = array_merge( $out, self::top_nodes( $node ) );
			} elseif ( is_array( $node ) && isset( $node['@type'] ) ) {
				$out[] = $node;
			}
		}

		return $out;
	}

	/**
	 * Remember every node with an `@id` (nested ones too).
	 *
	 * @param mixed $node Node.
	 * @return void
	 */
	private function index_node( $node ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['@id'] ) && isset( $node['@type'] ) && is_string( $node['@id'] ) ) {
			$this->index[ $node['@id'] ] = isset( $this->index[ $node['@id'] ] ) ? array_merge( $this->index[ $node['@id'] ], $node ) : $node;
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->index_node( $value );
			}
		}
	}

	/**
	 * A node's types.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<int, string>
	 */
	private static function types( array $node ): array {
		$t = $node['@type'] ?? array();

		return array_values( array_filter( array_map( 'strval', is_array( $t ) ? $t : array( $t ) ) ) );
	}

	/**
	 * Check one top-level node.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>
	 */
	private function check_node( array $node ): array {
		$types = self::types( $node );
		$row   = array(
			'type'     => implode( ', ', $types ),
			'id'       => (string) ( $node['@id'] ?? '' ),
			'name'     => is_scalar( $node['name'] ?? $node['headline'] ?? null ) ? (string) ( $node['name'] ?? $node['headline'] ) : '',
			'feature'  => '',
			'status'   => 'none',
			'note'     => '',
			'errors'   => array(),
			'warnings' => array(),
		);
		$this->vocabulary( $node, '', $row );
		$rule = $this->rules ? Rich_Rules::for_types( $types ) : null;
		if ( null !== $rule ) {
			$row['feature'] = (string) $rule['feature'];
			$row['status']  = (string) $rule['status'];
			$row['note']    = (string) ( $rule['note'] ?? '' );
			foreach ( (array) $rule['required'] as $path ) {
				if ( ! $this->has_path( $node, (string) $path ) ) {
					/* translators: %s: a property path such as author.name. */
					$row['errors'][] = sprintf( __( 'Missing %s, which Google requires for this result.', 'zinn-seo' ), self::readable( (string) $path ) );
				}
			}
			foreach ( (array) $rule['recommended'] as $path ) {
				if ( ! $this->has_path( $node, (string) $path ) ) {
					/* translators: %s: a property path such as dateModified. */
					$row['warnings'][] = sprintf( __( 'Add %s: Google recommends it.', 'zinn-seo' ), self::readable( (string) $path ) );
				}
			}
			if ( isset( $rule['eligible'] ) ) {
				$this->review_checks( $node, (array) $rule['eligible'], $row );
			}
		}
		// A reviewed product written INSIDE its review is still read by Google as a product
		// snippet of its own, with that type's requirements — the Rich Results
		// Test calls a bare reviewed Product "invalid" (measured 2026-10-09).
		$inline = $node['itemReviewed'] ?? null;
		if ( is_array( $inline ) && isset( $inline['@type'] ) ) {
			$inner = Rich_Rules::for_types( self::types( $inline ) );
			// Measured for Product only; other reviewed types are not claimed without a measurement.
			if ( null !== $inner && 'Product' === $inner['type'] ) {
				foreach ( (array) $inner['required'] as $path ) {
					if ( ! $this->has_path( $inline, (string) $path ) ) {
						/* translators: 1: a type, 2: a property path. */
						$row['errors'][] = sprintf( __( 'The reviewed %1$s needs %2$s: Google reads it as a rich result of its own. Give it its own node that carries the review.', 'zinn-seo' ), (string) $inner['type'], self::readable( (string) $path ) );
					}
				}
			}
		}
		// Reviews inside other nodes (a product's review) get the review checks too.
		foreach ( array( 'review', 'reviews' ) as $prop ) {
			foreach ( self::as_list( $node[ $prop ] ?? null ) as $review ) {
				$review = $this->resolve( $review );
				if ( is_array( $review ) && in_array( 'Review', self::types( $review ), true ) && ! $this->has_path( $review, 'reviewRating.ratingValue' ) ) {
					$row['errors'][] = __( 'A review in this item has no rating value.', 'zinn-seo' );
				}
			}
		}
		$row['errors']   = array_values( array_unique( $row['errors'] ) );
		$row['warnings'] = array_values( array_unique( $row['warnings'] ) );

		return $row;
	}

	/**
	 * The review rules: an eligible item, and not about the site's own business.
	 *
	 * @param array<string, mixed> $node     Review node.
	 * @param array<int, string>   $eligible Eligible item types.
	 * @param array<string, mixed> $row      Result row.
	 * @return void
	 */
	private function review_checks( array $node, array $eligible, array &$row ): void {
		$item = $this->resolve( $node['itemReviewed'] ?? null );
		if ( ! is_array( $item ) ) {
			return;
		}
		$ok = false;
		foreach ( self::types( $item ) as $t ) {
			foreach ( $eligible as $e ) {
				if ( Vocab::is_a( $t, $e ) ) {
					$ok = true;
				}
			}
		}
		if ( ! $ok ) {
			$row['warnings'][] = __( 'Google does not show review snippets for this kind of item (it does for books, recipes, films, products, software and local businesses).', 'zinn-seo' );
		}
		$is_business = false;
		foreach ( self::types( $item ) as $t ) {
			$is_business = $is_business || Vocab::is_a( $t, 'Organization' ) || Vocab::is_a( $t, 'LocalBusiness' );
		}
		$name = strtolower( trim( (string) ( $item['name'] ?? '' ) ) );
		if ( $is_business && '' !== $name && in_array( $name, $this->own_names, true ) ) {
			$row['errors'][] = __( 'This reviews the site\'s own business: Google ignores reviews a business writes about itself and may act against them. Remove the review structured data.', 'zinn-seo' );
		}
	}

	/**
	 * Vocabulary and value checks, recursively.
	 *
	 * @param array<string, mixed> $node Node.
	 * @param string               $at   Where (for messages).
	 * @param array<string, mixed> $row  Result row.
	 * @return void
	 */
	private function vocabulary( array $node, string $at, array &$row ): void {
		$types = self::types( $node );
		$known = array();
		foreach ( $types as $t ) {
			if ( 0 === strpos( $t, 'http' ) ) {
				continue; // A full IRI from another vocabulary: not ours to judge.
			}
			if ( Vocab::is_type( $t ) ) {
				$known[] = $t;
			} else {
				/* translators: 1: a type name, 2: where it is. */
				$row['errors'][] = sprintf( __( '"%1$s" is not a schema.org type%2$s.', 'zinn-seo' ), $t, '' === $at ? '' : ' (' . $at . ')' );
			}
		}
		foreach ( $node as $prop => $value ) {
			$prop = (string) $prop;
			// `query-input` / `…-output`: schema.org's action annotations, not properties.
			if ( '' === $prop || '@' === $prop[0] || 0 === strpos( $prop, 'http' ) || 1 === preg_match( '/-(input|output)$/', $prop ) ) {
				continue;
			}
			$where = '' === $at ? $prop : $at . '.' . $prop;
			if ( array() !== $known ) {
				$fits = null;
				foreach ( $known as $t ) {
					$has  = Vocab::has_property( $t, $prop );
					$fits = null === $has ? $fits : ( true === $fits || $has );
				}
				if ( null === $fits ) {
					/* translators: %s: a property path. */
					$row['errors'][] = sprintf( __( '"%s" is not a schema.org property.', 'zinn-seo' ), $where );
				} elseif ( false === $fits ) {
					/* translators: 1: a property path, 2: a type. */
					$row['warnings'][] = sprintf( __( '"%1$s" is not a property of %2$s.', 'zinn-seo' ), $where, implode( ', ', $known ) );
				}
				$new = Vocab::superseded_by( $prop );
				if ( array() !== $new ) {
					/* translators: 1: a property path, 2: its replacement. */
					$row['warnings'][] = sprintf( __( '"%1$s" was replaced by "%2$s" in schema.org.', 'zinn-seo' ), $where, implode( '", "', $new ) );
				}
			}
			foreach ( self::as_list( $value ) as $item ) {
				if ( is_array( $item ) ) {
					if ( isset( $item['@id'] ) && 1 === count( $item ) ) {
						if ( ! isset( $this->index[ (string) $item['@id'] ] ) ) {
							/* translators: 1: a property path, 2: an @id. */
							$row['warnings'][] = sprintf( __( '"%1$s" points to %2$s, which is not in this page\'s data.', 'zinn-seo' ), $where, (string) $item['@id'] );
						}
						continue;
					}
					if ( isset( $item['@type'] ) ) {
						$this->vocabulary( $item, $where, $row );
					}
					continue;
				}
				$this->value_check( $prop, $where, $item, $row );
			}
		}
	}

	/**
	 * The shape of one plain value.
	 *
	 * @param string               $prop  Property.
	 * @param string               $where Path.
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $row   Result row.
	 * @return void
	 */
	private function value_check( string $prop, string $where, $value, array &$row ): void {
		if ( is_string( $value ) && '' === trim( $value ) ) {
			/* translators: %s: a property path. */
			$row['warnings'][] = sprintf( __( '"%s" is empty.', 'zinn-seo' ), $where );
			return;
		}
		if ( in_array( $prop, self::DATES, true ) && is_string( $value ) && ! self::is_date( $value ) ) {
			/* translators: 1: a property path, 2: its value. */
			$row['errors'][] = sprintf( __( '"%1$s" should be a date like 2026-10-09 or 2026-10-09T14:30:00+01:00, not "%2$s".', 'zinn-seo' ), $where, $value );
		}
		if ( in_array( $prop, self::DURATIONS, true ) && is_string( $value ) && 1 !== preg_match( '/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?$/', $value ) ) {
			/* translators: 1: a property path, 2: its value. */
			$row['errors'][] = sprintf( __( '"%1$s" should be a duration like PT1H30M, not "%2$s".', 'zinn-seo' ), $where, $value );
		}
		if ( in_array( $prop, self::URLS, true ) && is_string( $value ) && 1 !== preg_match( '#^https?://[^\s/]+#i', $value ) ) {
			/* translators: 1: a property path, 2: its value. */
			$row['errors'][] = sprintf( __( '"%1$s" should be a full address starting with https://, not "%2$s".', 'zinn-seo' ), $where, $value );
		}
		if ( in_array( $prop, self::NUMBERS, true ) && ! is_numeric( $value ) ) {
			/* translators: 1: a property path, 2: its value. */
			$row['errors'][] = sprintf( __( '"%1$s" should be a number, not "%2$s".', 'zinn-seo' ), $where, is_scalar( $value ) ? (string) $value : '…' );
		}
		if ( in_array( $prop, array( 'priceCurrency', 'currency' ), true ) && ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Z]{3}$/', $value ) ) ) {
			/* translators: 1: a property path, 2: its value. */
			$row['errors'][] = sprintf( __( '"%1$s" should be a three-letter currency code such as USD, not "%2$s".', 'zinn-seo' ), $where, is_scalar( $value ) ? (string) $value : '…' );
		}
	}

	/**
	 * Is this an ISO 8601 date or date-time?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_date( string $value ): bool {
		return 1 === preg_match( '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?)?)?$/', $value );
	}

	/**
	 * Does a node have a value at a path (`a.b`, alternatives `a|b`)? References are followed.
	 *
	 * @param array<string, mixed> $node Node.
	 * @param string               $path Path.
	 * @return bool
	 */
	public function has_path( array $node, string $path ): bool {
		foreach ( explode( '|', $path ) as $alt ) {
			if ( $this->walk( $node, explode( '.', $alt ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walk a path.
	 *
	 * @param mixed              $value Value.
	 * @param array<int, string> $parts Remaining parts.
	 * @return bool
	 */
	private function walk( $value, array $parts ): bool {
		$value = $this->resolve( $value );
		if ( array() === $parts ) {
			return null !== $value && '' !== $value && array() !== $value;
		}
		$part = array_shift( $parts );
		foreach ( self::as_list( $value ) as $item ) {
			$item = $this->resolve( $item );
			if ( is_array( $item ) && array_key_exists( $part, $item ) && $this->walk( $item[ $part ], $parts ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Follow an `@id` reference to the node it names.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function resolve( $value ) {
		if ( is_array( $value ) && isset( $value['@id'] ) && 1 === count( $value ) && isset( $this->index[ (string) $value['@id'] ] ) ) {
			return $this->index[ (string) $value['@id'] ];
		}

		return $value;
	}

	/**
	 * A value as a list (a JSON-LD property may hold one value or many).
	 *
	 * @param mixed $value Value.
	 * @return array<int, mixed>
	 */
	private static function as_list( $value ): array {
		if ( null === $value || array() === $value ) {
			return array();
		}
		if ( is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			return $value;
		}

		return array( $value );
	}

	/**
	 * A path for people: `author.name` → "author → name", alternatives joined by "or".
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function readable( string $path ): string {
		return implode(
			' ' . __( 'or', 'zinn-seo' ) . ' ',
			array_map( static fn( string $p ): string => str_replace( '.', ' → ', $p ), explode( '|', $path ) )
		);
	}
}
