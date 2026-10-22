<?php
/**
 * Schema Pro as an import source (zs-725).
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
 * Where Schema Pro keeps things (a paid plugin: its layout read from the importers that other SEO
 * plugins ship for it, and checked against a stand-in site built the same way): each type the
 * site uses is a post of type `aiosrs-schema` with its type in `bsf-aiosrs-schema-type`, where it
 * applies in `bsf-aiosrs-schema-location` (`basic-global`, `basic-singulars`, `<type>|all`,
 * `post-<id>`), and how each property is filled in `bsf-aiosrs-<type>` — from the post's title,
 * excerpt, featured image, author or date, from a fixed text, from another custom field, or from a
 * value typed on the post itself (`<type>-<id>-<property>`).
 *
 * The properties become the node search engines read, which the importer turns into our own form
 * for that type, or keeps whole.
 */
final class Schema_Pro extends Source {

	/** Its property names that are not schema.org's. */
	private const RENAME = array(
		'orgnization-name'  => array( 'publisher', 'Organization', 'name' ),
		'organization-name' => array( 'publisher', 'Organization', 'name' ),
		'site-logo'         => array( 'publisher', 'Organization', 'logo' ),
		'brand-name'        => array( 'brand', 'Brand', 'name' ),
		'price'             => array( 'offers', 'Offer', 'price' ),
		'currency'          => array( 'offers', 'Offer', 'priceCurrency' ),
		'avail'             => array( 'offers', 'Offer', 'availability' ),
		'rating'            => array( 'aggregateRating', 'AggregateRating', 'ratingValue' ),
		'review-count'      => array( 'aggregateRating', 'AggregateRating', 'reviewCount' ),
		'location'          => array( 'location', 'Place', 'name' ),
		'location-street'   => array( 'address', 'PostalAddress', 'streetAddress' ),
		'location-locality' => array( 'address', 'PostalAddress', 'addressLocality' ),
		'location-region'   => array( 'address', 'PostalAddress', 'addressRegion' ),
		'location-postal'   => array( 'address', 'PostalAddress', 'postalCode' ),
		'location-country'  => array( 'address', 'PostalAddress', 'addressCountry' ),
		'author'            => array( 'author', 'Person', 'name' ),
		'performer'         => array( 'performer', 'Person', 'name' ),
		'orgnization'       => array( 'hiringOrganization', 'Organization', 'name' ),
	);

	/** Plain properties under another name. */
	private const PLAIN = array(
		'published-date'   => 'datePublished',
		'modified-date'    => 'dateModified',
		'start-date'       => 'startDate',
		'end-date'         => 'endDate',
		'preperation-time' => 'prepTime',
		'preparation-time' => 'prepTime',
		'cook-time'        => 'cookTime',
		'recipe-yield'     => 'recipeYield',
		'ingredients'      => 'recipeIngredient',
		'recipe-category'  => 'recipeCategory',
		'recipe-cuisine'   => 'recipeCuisine',
		'main-entity'      => '',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'schemapro';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Schema Pro';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'wp-schema-pro/wp-schema-pro.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return array() !== get_posts(
			array(
				'post_type'      => 'aiosrs-schema',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * The snippet that applies to a post (the newest that matches, as Schema Pro does).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{id: int, type: string, details: array<string, mixed>}|null
	 */
	public static function snippet_for( \WP_Post $post ): ?array {
		static $snippets = null;
		if ( null === $snippets ) {
			$snippets = array();
			$ids      = get_posts(
				array(
					'post_type'      => 'aiosrs-schema',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$where   = get_post_meta( (int) $id, 'bsf-aiosrs-schema-location', true );
				$rules   = is_array( $where ) ? (array) ( $where['rule'] ?? $where ) : array();
				$type    = (string) get_post_meta( (int) $id, 'bsf-aiosrs-schema-type', true );
				$details = get_post_meta( (int) $id, 'bsf-aiosrs-' . $type, true );
				if ( '' !== $type ) {
					$snippets[] = array(
						'id'      => (int) $id,
						'type'    => $type,
						'details' => is_array( $details ) ? $details : array(),
						'rules'   => array_map( 'strval', array_values( array_filter( $rules, 'is_scalar' ) ) ),
					);
				}
			}
		}
		foreach ( $snippets as $snippet ) {
			if ( self::location_holds( $snippet['rules'], $post ) ) {
				return $snippet;
			}
		}

		return null;
	}

	/**
	 * Does a location list hold for a post?
	 *
	 * @param array<int, string> $rules Rules.
	 * @param \WP_Post           $post  Post.
	 * @return bool
	 */
	public static function location_holds( array $rules, \WP_Post $post ): bool {
		foreach ( $rules as $rule ) {
			if ( in_array( $rule, array( 'basic-global', 'basic-singulars' ), true ) || $post->post_type . '|all' === $rule || 'post-' . $post->ID === $rule ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The schema.org name for its type.
	 *
	 * @param string $type Its type.
	 * @return string
	 */
	public static function schema_type( string $type ): string {
		$map = array(
			'job-posting'          => 'JobPosting',
			'video-object'         => 'VideoObject',
			'software-application' => 'SoftwareApplication',
			'faq'                  => 'FAQPage',
			'how-to'               => 'HowTo',
			'local-business'       => 'LocalBusiness',
		);

		return $map[ $type ] ?? ucfirst( $type );
	}

	/**
	 * One property's value for a post.
	 *
	 * @param array<string, mixed> $details The snippet's mapping.
	 * @param string               $key     Property.
	 * @param array<string, mixed> $snippet The snippet.
	 * @param \WP_Post             $post    Post.
	 * @return string
	 */
	public static function value( array $details, string $key, array $snippet, \WP_Post $post ): string {
		$source = is_scalar( $details[ $key ] ?? null ) ? (string) $details[ $key ] : '';
		switch ( $source ) {
			case 'custom-text':
				return is_scalar( $details[ $key . '-custom-text' ] ?? null ) ? trim( (string) $details[ $key . '-custom-text' ] ) : '';
			case 'create-field':
				return self::pm( (int) $post->ID, $snippet['type'] . '-' . $snippet['id'] . '-' . $key );
			case 'specific-field':
				$field = is_scalar( $details[ $key . '-specific-field' ] ?? null ) ? (string) $details[ $key . '-specific-field' ] : '';
				return '' !== $field ? self::pm( (int) $post->ID, $field ) : '';
			case 'post_title':
				return wp_strip_all_tags( (string) get_the_title( $post ) );
			case 'post_excerpt':
				return wp_strip_all_tags( (string) $post->post_excerpt );
			case 'featured_img':
				$src = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post ), 'full' );
				return is_string( $src ) ? $src : '';
			case 'author_name':
				$user = get_userdata( (int) $post->post_author );
				return $user instanceof \WP_User ? $user->display_name : '';
			case 'post_date':
				return (string) get_the_date( 'c', $post );
			case 'post_modified':
				return (string) get_the_modified_date( 'c', $post );
			case 'blogname':
				return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			case 'blogdescription':
				return wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );
			case 'site_logo':
				$src = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' );
				return is_string( $src ) ? $src : '';
		}

		return '';
	}

	/**
	 * The node a post's snippet describes.
	 *
	 * @param array<string, mixed> $snippet Snippet.
	 * @param \WP_Post             $post    Post.
	 * @return array<string, mixed>
	 */
	public static function node( array $snippet, \WP_Post $post ): array {
		$node = array( '@type' => self::schema_type( (string) $snippet['type'] ) );
		foreach ( array_keys( (array) $snippet['details'] ) as $key ) {
			$key = (string) $key;
			if ( 1 === preg_match( '/-(custom-text|specific-field|fieldtype)$/', $key ) ) {
				continue;
			}
			$value = self::value( (array) $snippet['details'], $key, $snippet, $post );
			if ( '' === $value ) {
				continue;
			}
			if ( isset( self::RENAME[ $key ] ) ) {
				list( $prop, $kind, $inner ) = self::RENAME[ $key ];
				if ( ! isset( $node[ $prop ] ) || ! is_array( $node[ $prop ] ) ) {
					$node[ $prop ] = array( '@type' => $kind );
				}
				$node[ $prop ][ $inner ] = $value;
				continue;
			}
			$prop = self::PLAIN[ $key ] ?? lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', $key ) ) ) );
			if ( '' === $prop ) {
				continue;
			}
			$node[ $prop ] = 'recipeIngredient' === $prop ? array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ) ) ) : $value;
		}

		return $node;
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
		$snippet = self::snippet_for( $post );
		if ( null === $snippet ) {
			return array();
		}
		$node = self::node( $snippet, $post );
		if ( count( $node ) < 2 ) {
			return array();
		}

		return self::filled( array( 'schema_import' => $this->schema_field( array( $node ), $post_id ) ) );
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
