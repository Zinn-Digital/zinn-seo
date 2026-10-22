<?php
/**
 * Schema & Structured Data for WP (SASWP) as an import source (zs-725).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import\Sources;

use ZinnDigital\ZinnSEO\Import\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where Schema & Structured Data for WP keeps things (read from its own code, release 1.68): each
 * structured-data type the site uses is a post of type `saswp` with its type in `schema_type` and
 * where it applies in `data_group_array` (groups of rules: any group whose rules all hold — "show
 * everywhere", a content type, one post, a category); a post that changed a type's answers holds
 * `saswp_modify_this_schema_<id>` and one row per answer, `saswp_<type>_<field>_<id>`.
 *
 * Each type that applies to a post becomes the node search engines read; answers the post changed
 * become that node's properties (`prep_time` → `prepTime`, `author_name` → the author's name…),
 * and the importer turns the node into our own form for that type, or keeps it whole.
 */
final class Saswp extends Source {

	/**
	 * Its type posts: id => [type, groups], read once.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>|null
	 */
	private ?array $types = null;

	/** Answers that are a property of a nested thing: its field => [property, nested type, its property]. */
	private const NESTED = array(
		'author_name'            => array( 'author', 'Person', 'name' ),
		'author_url'             => array( 'author', 'Person', 'url' ),
		'organization_name'      => array( 'publisher', 'Organization', 'name' ),
		'brand_name'             => array( 'brand', 'Brand', 'name' ),
		'brand'                  => array( 'brand', 'Brand', 'name' ),
		'location_name'          => array( 'location', 'Place', 'name' ),
		'location_streetaddress' => array( 'address', 'PostalAddress', 'streetAddress' ),
		'location_locality'      => array( 'address', 'PostalAddress', 'addressLocality' ),
		'location_region'        => array( 'address', 'PostalAddress', 'addressRegion' ),
		'location_postalcode'    => array( 'address', 'PostalAddress', 'postalCode' ),
		'location_country'       => array( 'address', 'PostalAddress', 'addressCountry' ),
		'price'                  => array( 'offers', 'Offer', 'price' ),
		'price_currency'         => array( 'offers', 'Offer', 'priceCurrency' ),
		'currency'               => array( 'offers', 'Offer', 'priceCurrency' ),
		'offer_price'            => array( 'offers', 'Offer', 'price' ),
		'offer_currency'         => array( 'offers', 'Offer', 'priceCurrency' ),
		'availability'           => array( 'offers', 'Offer', 'availability' ),
		'provider_name'          => array( 'provider', 'Organization', 'name' ),
		'organizer_name'         => array( 'organizer', 'Organization', 'name' ),
		'performer_name'         => array( 'performer', 'Person', 'name' ),
		'rating'                 => array( 'aggregateRating', 'AggregateRating', 'ratingValue' ),
		'review_count'           => array( 'aggregateRating', 'AggregateRating', 'reviewCount' ),
	);

	/** Answers that are plain properties under another name. */
	private const RENAME = array(
		'date_published' => 'datePublished',
		'date_modified'  => 'dateModified',
		'start_date'     => 'startDate',
		'end_date'       => 'endDate',
		'prep_time'      => 'prepTime',
		'cook_time'      => 'cookTime',
		'total_time'     => 'totalTime',
		'ingredient'     => 'recipeIngredient',
		'instructions'   => 'recipeInstructions',
		'yield'          => 'recipeYield',
		'category'       => 'recipeCategory',
		'cuisine'        => 'recipeCuisine',
		'body'           => 'articleBody',
		'section'        => 'articleSection',
		'upload_date'    => 'uploadDate',
		'thumbnail_url'  => 'thumbnailUrl',
		'content_url'    => 'contentUrl',
		'embed_url'      => 'embedUrl',
		'telephone'      => 'telephone',
		'phone'          => 'telephone',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'saswp';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Schema & Structured Data for WP';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'schema-and-structured-data-for-wp/structured-data-for-wp.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return array() !== $this->types();
	}

	/**
	 * Its type posts.
	 *
	 * @return array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private function types(): array {
		if ( null === $this->types ) {
			$this->types = array();
			$ids         = get_posts(
				array(
					'post_type'      => 'saswp',
					'post_status'    => 'publish',
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- its structured-data TYPES (a handful per site, never posts), read once per import.
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$type   = (string) get_post_meta( (int) $id, 'schema_type', true );
				$groups = get_post_meta( (int) $id, 'data_group_array', true );
				if ( '' !== $type ) {
					$this->types[ (int) $id ] = array( $type, is_array( $groups ) ? array_values( $groups ) : array() );
				}
			}
		}

		return $this->types;
	}

	/**
	 * Does one placement rule hold for a post?
	 *
	 * @param array<string, mixed> $rule `key_1` what, `key_2` equal|not_equal, `key_3` value.
	 * @param \WP_Post             $post Post.
	 * @return bool
	 */
	public static function rule_holds( array $rule, \WP_Post $post ): bool {
		$what  = (string) ( $rule['key_1'] ?? '' );
		$equal = 'not_equal' !== (string) ( $rule['key_2'] ?? 'equal' );
		$value = (string) ( $rule['key_3'] ?? '' );
		switch ( $what ) {
			case 'show_globally':
				return true;
			case 'post_type':
				$hit = $post->post_type === $value;
				break;
			case 'post':
			case 'page':
				$hit = (int) $value === (int) $post->ID;
				break;
			case 'post_category':
				$hit = has_category( (int) $value, $post );
				break;
			default:
				return false;
		}

		return $equal ? $hit : ! $hit;
	}

	/**
	 * Which of its types apply to a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, string> Type post id => type.
	 */
	public function applying( \WP_Post $post ): array {
		$out = array();
		foreach ( $this->types() as $id => list( $type, $groups ) ) {
			if ( array() === $groups ) {
				$groups = array(
					array(
						'data_array' => array(
							array(
								'key_1' => 'post_type',
								'key_2' => 'equal',
								'key_3' => 'post',
							),
						),
					),
				);
			}
			foreach ( $groups as $group ) {
				$rules = is_array( $group ) ? (array) ( $group['data_array'] ?? array() ) : array();
				if ( array() === $rules ) {
					continue;
				}
				$all = true;
				foreach ( $rules as $rule ) {
					if ( ! is_array( $rule ) || ! self::rule_holds( $rule, $post ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					$out[ $id ] = $type;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Its type name as schema.org's.
	 *
	 * @param string $type Its type.
	 * @return string
	 */
	public static function schema_type( string $type ): string {
		$map = array(
			'local_business'      => 'LocalBusiness',
			'qanda'               => 'QAPage',
			'faq'                 => 'FAQPage',
			'howto'               => 'HowTo',
			'videoobject'         => 'VideoObject',
			'video_object'        => 'VideoObject',
			'softwareapplication' => 'SoftwareApplication',
			'software_app'        => 'SoftwareApplication',
			'jobposting'          => 'JobPosting',
		);

		return $map[ strtolower( $type ) ] ?? str_replace( ' ', '', ucwords( str_replace( '_', ' ', $type ) ) );
	}

	/**
	 * A post's answers for one type as a node (the type's own field prefix taken off).
	 *
	 * @param array<string, string> $answers Field => value, prefix already removed.
	 * @param string                $type    schema.org type.
	 * @return array<string, mixed>
	 */
	public static function node_from( array $answers, string $type ): array {
		$node = array( '@type' => $type );
		foreach ( $answers as $field => $value ) {
			if ( '' === $value || 'id' === $field || false !== strpos( $field, 'global_mapping' ) ) {
				continue;
			}
			if ( isset( self::NESTED[ $field ] ) ) {
				list( $prop, $kind, $inner ) = self::NESTED[ $field ];
				if ( ! isset( $node[ $prop ] ) || ! is_array( $node[ $prop ] ) ) {
					$node[ $prop ] = array( '@type' => $kind );
				}
				$node[ $prop ][ $inner ] = $value;
				continue;
			}
			$prop          = self::RENAME[ $field ] ?? lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $field ) ) ) );
			$node[ $prop ] = in_array( $prop, array( 'recipeIngredient', 'recipeInstructions' ), true )
				? array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ) ) )
				: $value;
		}

		return $node;
	}

	/**
	 * Site-wide: an article type it put on every page of a content type ("Article on all posts")
	 * becomes that content type's article type here — the posts were all left as Zinn® SEO's
	 * BlogPosting after a move (importer certification, queue Q254).
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$types = array();
		foreach ( $this->types() as $id => list( $type, $groups ) ) {
			unset( $id );
			$schema = self::schema_type( $type );
			if ( ! in_array( $schema, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' ), true ) ) {
				continue;
			}
			$groups = array() === $groups ? array(
				array(
					'data_array' => array(
						array(
							'key_1' => 'post_type',
							'key_2' => 'equal',
							'key_3' => 'post',
						),
					),
				),
			) : $groups;
			foreach ( $groups as $group ) {
				$rules = is_array( $group ) ? array_values( (array) ( $group['data_array'] ?? array() ) ) : array();
				// One rule naming one content type: the whole type.
				if ( 1 === count( $rules ) && is_array( $rules[0] ) && 'post_type' === (string) ( $rules[0]['key_1'] ?? '' ) && 'not_equal' !== (string) ( $rules[0]['key_2'] ?? 'equal' ) && post_type_exists( (string) ( $rules[0]['key_3'] ?? '' ) ) ) {
					$types[ 'post:' . (string) $rules[0]['key_3'] ] = array( 'article' => $schema );
				}
			}
		}

		$sd = get_option( 'sd_data', array() );

		return self::more_settings( array() === $types ? array() : array( 'schema_types' => $types ), is_array( $sd ) ? $sd : array() );
	}

	/**
	 * Its picture and description rules for articles (IMP2): with its image resizing, several
	 * sizes and the text's images (each on unless switched off), an article lists its picture in
	 * Google's shapes and its text's images; it always gives an article its excerpt. Pure, for
	 * the tests.
	 *
	 * @param array<string, mixed> $out Settings so far.
	 * @param array<string, mixed> $sd  Its options (`sd_data`).
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $out, array $sd ): array {
		$on = static fn( string $key ): bool => ! array_key_exists( $key, $sd ) || 1 === (int) $sd[ $key ];
		if ( $on( 'saswp-image-resizing' ) && $on( 'saswp-multiple-size-image' ) && $on( 'saswp-other-images' ) ) {
			$out['schema_article_images'] = true;
		}
		$out['schema_article_desc'] = true;

		return $out;
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$nodes = array();
		$meta  = get_post_meta( $post_id );
		foreach ( $this->applying( $post ) as $id => $type ) {
			$schema  = self::schema_type( $type );
			$answers = array();
			if ( ! empty( $meta[ 'saswp_modify_this_schema_' . $id ][0] ) ) {
				$suffix = '_' . $id;
				$prefix = 'saswp_' . strtolower( str_replace( ' ', '', (string) $type ) ) . '_';
				foreach ( $meta as $key => $values ) {
					$key = (string) $key;
					if ( 0 !== strpos( $key, 'saswp_' ) || substr( $key, -strlen( $suffix ) ) !== $suffix ) {
						continue;
					}
					$body = substr( $key, 0, -strlen( $suffix ) );
					// Its own prefix for the type (`saswp_recipe_`, `saswp_event_schema_`, …).
					$field = 0 === strpos( $body, $prefix ) ? substr( $body, strlen( $prefix ) ) : (string) preg_replace( '/^saswp_[a-z]+_(schema_)?/', '', $body );
					$value = is_scalar( $values[0] ?? null ) ? trim( (string) maybe_unserialize( $values[0] ) ) : '';
					if ( '' !== $field && '' !== $value ) {
						$answers[ (string) preg_replace( '/^schema_/', '', $field ) ] = $value;
					}
				}
			}
			// A type the post left as it was is filled from the post itself, which Zinn® SEO's own
			// structured data does too: only answers the post changed are carried over.
			if ( array() !== $answers ) {
				$nodes[] = self::node_from( $answers, $schema );
			}
		}
		if ( array() === $nodes ) {
			return array();
		}

		return self::filled( array( 'schema_import' => $this->schema_field( $nodes, $post_id ) ) );
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'Where a type applies by an advanced rule (a date, a user role, a page template), it is not brought over: those rules have no counterpart here. The pages it applies to get Zinn® SEO\'s own structured data.', 'zinn-seo' ),
		);
	}

	/**
	 * It does not write robots.txt.
	 *
	 * @return bool
	 */
	protected function writes_robots(): bool {
		return false;
	}
}
