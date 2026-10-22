<?php
/**
 * All In One Schema Rich Snippets as an import source (zs-725).
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
 * Where All In One Schema Rich Snippets keeps things (read from its own code, release 1.7.9): what
 * a post is about in `_bsf_post_type` — 1 a review, 2 an event, 5 a person, 6 a product, 7 a
 * recipe, 8 a software application, 9 a video, 10 an article, 11 a service — and that type's
 * answers in `_bsf_<type>_<field>` rows. Each becomes the node search engines read, which the
 * importer turns into our own form for that type (or keeps whole when we have no form).
 */
final class Aio_Schema extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'aiosrs';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'All In One Schema Rich Snippets';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'all-in-one-schemaorg-rich-snippets/index.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_query_meta_key -- one existence check.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bsf_post_type' AND meta_value <> '0' LIMIT 1" ) > 0;
	}

	/**
	 * A place from street, town, region and postcode answers.
	 *
	 * @param string ...$parts Street, locality, region, postcode.
	 * @return array<string, mixed>
	 */
	private static function address( string ...$parts ): array {
		list( $street, $local, $region, $postal ) = array_pad( $parts, 4, '' );

		return array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $street,
				'addressLocality' => $local,
				'addressRegion'   => $region,
				'postalCode'      => $postal,
			)
		);
	}

	/**
	 * The node a post described, in schema.org's own shape.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>|null
	 */
	public static function node( int $post_id ): ?array {
		$g = static fn( string $f ): string => self::pm( $post_id, '_bsf_' . $f );
		switch ( $g( 'post_type' ) ) {
			case '1':
				return array_filter(
					array(
						'@type'        => 'Review',
						'itemReviewed' => array(
							'@type' => 'Thing',
							'name'  => $g( 'item_name' ),
						),
						'reviewRating' => '' !== $g( 'rating' ) ? array(
							'@type'       => 'Rating',
							'ratingValue' => $g( 'rating' ),
							'bestRating'  => '5',
						) : null,
						'author'       => '' !== $g( 'item_reviewer' ) ? array(
							'@type' => 'Person',
							'name'  => $g( 'item_reviewer' ),
						) : null,
					)
				);
			case '2':
				return array_filter(
					array(
						'@type'       => 'Event',
						'name'        => $g( 'event_title' ),
						'description' => $g( 'event_desc' ),
						'startDate'   => $g( 'event_start_date' ),
						'endDate'     => $g( 'event_end_date' ),
						'image'       => $g( 'event_image' ),
						'location'    => array_filter(
							array(
								'@type'   => 'Place',
								'name'    => $g( 'event_organization' ),
								'address' => self::address( $g( 'event_street' ), $g( 'event_local' ), $g( 'event_region' ), $g( 'event_postal_code' ) ),
							)
						),
						'offers'      => '' !== $g( 'event_price' ) ? array_filter(
							array(
								'@type'         => 'Offer',
								'price'         => $g( 'event_price' ),
								'priceCurrency' => $g( 'event_cur' ),
								'url'           => $g( 'event_ticket_url' ),
							)
						) : null,
					)
				);
			case '5':
				return array_filter(
					array(
						'@type'         => 'Person',
						'name'          => $g( 'people_fn' ),
						'alternateName' => $g( 'people_nickname' ),
						'jobTitle'      => $g( 'people_job_title' ),
						'worksFor'      => '' !== $g( 'people_company' ) ? array(
							'@type' => 'Organization',
							'name'  => $g( 'people_company' ),
						) : null,
						'url'           => $g( 'people_website' ),
						'image'         => $g( 'people_photo' ),
						'address'       => self::address( $g( 'people_street' ), $g( 'people_local' ), $g( 'people_region' ), $g( 'people_postal' ) ),
					)
				);
			case '6':
				return array_filter(
					array(
						'@type'    => 'Product',
						'name'     => $g( 'product_name' ),
						'brand'    => '' !== $g( 'product_brand' ) ? array(
							'@type' => 'Brand',
							'name'  => $g( 'product_brand' ),
						) : null,
						'category' => $g( 'product_cat' ),
						'image'    => $g( 'product_image' ),
						'offers'   => '' !== $g( 'product_price' ) ? array_filter(
							array(
								'@type'         => 'Offer',
								'price'         => $g( 'product_price' ),
								'priceCurrency' => $g( 'product_cur' ),
								'availability'  => 'out_of_stock' === $g( 'product_status' ) ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
							)
						) : null,
					)
				);
			case '7':
				return array_filter(
					array(
						'@type'            => 'Recipe',
						'name'             => $g( 'recipes_name' ),
						'description'      => $g( 'recipes_desc' ),
						'image'            => $g( 'recipes_photo' ),
						'prepTime'         => $g( 'recipes_preptime' ),
						'cookTime'         => $g( 'recipes_cooktime' ),
						'totalTime'        => $g( 'recipes_totaltime' ),
						'recipeIngredient' => array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', $g( 'recipes_ingredient' ) ) ) ) ),
						'nutrition'        => '' !== $g( 'recipes_nutrition' ) ? array(
							'@type'    => 'NutritionInformation',
							'calories' => $g( 'recipes_nutrition' ),
						) : null,
					)
				);
			case '8':
				return array_filter(
					array(
						'@type'               => 'SoftwareApplication',
						'name'                => $g( 'software_name' ),
						'description'         => $g( 'software_desc' ),
						'operatingSystem'     => $g( 'software_os' ),
						'applicationCategory' => $g( 'software_cat' ),
						'image'               => $g( 'software_image' ),
						'url'                 => $g( 'software_landing' ),
						'offers'              => array_filter(
							array(
								'@type'         => 'Offer',
								'price'         => '' !== $g( 'software_price' ) ? $g( 'software_price' ) : '0',
								'priceCurrency' => $g( 'software_cur' ),
							)
						),
					)
				);
			case '9':
				return array_filter(
					array(
						'@type'        => 'VideoObject',
						'name'         => $g( 'video_title' ),
						'description'  => $g( 'video_desc' ),
						'thumbnailUrl' => $g( 'video_thumb' ),
						'contentUrl'   => $g( 'video_url' ),
						'embedUrl'     => $g( 'video_emb_url' ),
						'uploadDate'   => $g( 'video_date' ),
						'duration'     => $g( 'video_duration' ),
					)
				);
			case '10':
				return array_filter(
					array(
						'@type'       => 'Article',
						'headline'    => $g( 'article_title' ),
						'name'        => $g( 'article_name' ),
						'description' => $g( 'article_desc' ),
						'image'       => $g( 'article_image' ),
						'author'      => '' !== $g( 'article_author' ) ? array(
							'@type' => 'Person',
							'name'  => $g( 'article_author' ),
						) : null,
					)
				);
			case '11':
				return array_filter(
					array(
						'@type'       => 'Service',
						'serviceType' => $g( 'service_type' ),
						'description' => $g( 'service_desc' ),
						'image'       => $g( 'service_image' ),
						'areaServed'  => $g( 'service_area' ),
						'provider'    => array_filter(
							array(
								'@type'     => 'LocalBusiness',
								'name'      => $g( 'service_provider' ),
								'telephone' => $g( 'service_telephone' ),
								'address'   => self::address( $g( 'service_street' ), $g( 'service_local' ), $g( 'service_region' ), $g( 'service_postal_code' ) ),
							)
						),
						'offers'      => '' !== $g( 'service_price' ) ? array(
							'@type'         => 'Offer',
							'price'         => $g( 'service_price' ),
							'priceCurrency' => $g( 'service_cur' ),
						) : null,
					)
				);
		}

		return null;
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$node = self::node( $post_id );
		if ( null === $node || count( $node ) < 2 ) {
			return array();
		}

		return self::filled( array( 'schema_import' => $this->schema_field( array( $node ), $post_id ) ) );
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'Its visible "rich snippet" box on each post is not copied: the same facts reach search engines as structured data, and you can show them with the matching Zinn® SEO block if you want a visible box.', 'zinn-seo' ),
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
