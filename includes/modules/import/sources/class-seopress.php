<?php
/**
 * SEOPress (and Pro) as an import source.
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
 * Where SEOPress keeps things (read from its own code, release 10.3): per post in postmeta and
 * per term in termmeta under the same `_seopress_*` keys ("yes" means the box was ticked, so
 * `_seopress_robots_index = yes` is NOindex); templates in the option
 * `seopress_titles_option_name`; social and site details in `seopress_social_option_name`;
 * verification codes in `seopress_advanced_option_name`. A redirect set on a post is in its
 * `_seopress_redirections_*` meta; the redirects of its Pro manager are read by the redirects
 * module.
 */
final class Seopress extends Source {

	/**
	 * The titles option, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $titles = null;

	/**
	 * The separator as a template writes it.
	 *
	 * @var string|null
	 */
	private ?string $sep = null;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'seopress';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'SEOPress';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return is_array( get_option( 'seopress_titles_option_name', null ) );
	}

	/**
	 * Redirects (Pro) through the redirects module.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'seopress';
	}

	/**
	 * The titles option.
	 *
	 * @return array<string, mixed>
	 */
	private function titles(): array {
		if ( null === $this->titles ) {
			$stored       = get_option( 'seopress_titles_option_name', array() );
			$this->titles = is_array( $stored ) ? $stored : array();
		}

		return $this->titles;
	}

	/**
	 * One of its templates in our variables.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		if ( null === $this->sep ) {
			$char      = html_entity_decode( (string) ( $this->titles()['seopress_titles_sep'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$char      = '' !== trim( $char ) ? trim( $char ) : '-';
			$this->sep = '' !== self::separator( $char ) ? '%sep%' : $char;
		}

		return $this->convert(
			$text,
			'/%%([a-z_0-9]+)%%/i',
			array(
				'post_title'              => '%title%',
				'archive_title'           => '%title%',
				'sitetitle'               => '%sitename%',
				'sitename'                => '%sitename%',
				'tagline'                 => '%tagline%',
				'sitedesc'                => '%tagline%',
				'sep'                     => $this->sep,
				'post_excerpt'            => '%excerpt%',
				'post_content'            => '%excerpt%',
				'post_date'               => '%date%',
				'post_modified_date'      => '%modified%',
				'post_author'             => '%author%',
				'author_bio'              => '%excerpt%',
				'post_category'           => '%category%',
				'post_tag'                => '%ct_post_tag%',
				'_category_title'         => '%term%',
				'tag_title'               => '%term%',
				'term_title'              => '%term%',
				'_category_description'   => '%term_description%',
				'tag_description'         => '%term_description%',
				'term_description'        => '%term_description%',
				// It puts the searched words in quotation marks itself.
				'search_keywords'         => '"%search%"',
				// Its page number is "- Page 2" from the second page on; ours says "Page 2 of 5".
				'current_pagination'      => '%page%',
				'page'                    => '%pagenumber%',
				'cpt_plural'              => '%posttype_plural%',
				// It writes a month archive as "3 - 2026".
				// It writes "month number - year" from the request: a day archive says its month, a
				// year archive "0 - 2026" (month | day | year formats, as it prints each).
				'archive_date'            => '%date(n - Y | n - Y | \\0 - Y)%',
				'archive_date_month'      => '%date(n | n | \\0)%',
				'archive_date_month_name' => '%date(F | F)%',
				'archive_date_year'       => '%year%',
				'currentyear'             => '%currentyear%',
				'currentmonth'            => '%currentmonth%',
			),
			array(
				'_cf_' => static fn( string $rest ): string => self::named( 'cf', $rest ),
				'_ct_' => static fn( string $rest ): string => self::named( 'ct', $rest ),
			)
		);
	}

	/**
	 * Our fields from one object's values.
	 *
	 * @param callable $get `function ( string $key ): string` — the stored value of `_seopress_<key>`.
	 * @return array<string, mixed>
	 */
	private function fields( callable $get ): array {
		$yes = static fn( string $key ): bool => 'yes' === $get( $key );

		return array(
			'title'     => $this->template( $get( 'titles_title' ) ),
			'desc'      => $this->template( $get( 'titles_desc' ) ),
			'canonical' => $get( 'robots_canonical' ),
			'robots'    => self::robots(
				array(
					'noindex'      => $yes( 'robots_index' ),
					'nofollow'     => $yes( 'robots_follow' ),
					'nosnippet'    => $yes( 'robots_snippet' ),
					'noimageindex' => $yes( 'robots_imageindex' ),
					'noarchive'    => $yes( 'robots_archive' ),
				)
			),
			'og_title'  => $this->template( $get( 'social_fb_title' ) ),
			'og_desc'   => $this->template( $get( 'social_fb_desc' ) ),
			'og_image'  => self::image( $get( 'social_fb_img_attachment_id' ), $get( 'social_fb_img' ) ),
			'x_title'   => $this->template( $get( 'social_twitter_title' ) ),
			'x_desc'    => $this->template( $get( 'social_twitter_desc' ) ),
			'x_image'   => self::image( $get( 'social_twitter_img_attachment_id' ), $get( 'social_twitter_img' ) ),
			'crumb'     => $get( 'robots_breadcrumbs' ),
		);
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$get                 = static fn( string $key ): string => self::pm( $post_id, '_seopress_' . $key );
		$out                 = $this->fields( $get );
		$out['focus']        = implode( "\n", array_values( array_filter( array_map( 'trim', explode( ',', $get( 'analysis_target_kw' ) ) ) ) ) );
		$out['primary_term'] = is_numeric( $get( 'robots_primary_cat' ) ) ? (int) $get( 'robots_primary_cat' ) : 0;
		// A redirect set on the post itself (free plugin): only when switched on.
		if ( 'yes' === $get( 'redirections_enabled' ) && '' !== $get( 'redirections_value' ) && 'seopress_404' !== get_post_type( $post_id ) ) {
			$out['redirect'] = array(
				'target' => $get( 'redirections_value' ),
				'code'   => in_array( (int) $get( 'redirections_type' ), array( 301, 302, 307, 308 ), true ) ? (int) $get( 'redirections_type' ) : 301,
			);
		}

		// SEOPress Pro: the type chosen in the page's "Structured data types" tab and its answers
		// (`_seopress_pro_rich_snippets_type` and `_seopress_pro_rich_snippets_<answer>`).
		$node = self::snippet( $post_id );
		if ( array() !== $node ) {
			$out['schema_import'] = $this->schema_field( array( $node ), $post_id );
		}

		return self::filled( $out );
	}

	/**
	 * The node of the type SEOPress Pro stored for a post (empty when none, or an article — that
	 * is the page's article type).
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public static function snippet( int $post_id ): array {
		$type = self::pm( $post_id, '_seopress_pro_rich_snippets_type' );
		if ( '' === $type || 'none' === $type || 'articles' === $type ) {
			return array();
		}
		$g     = static fn( string $key ): string => self::pm( $post_id, '_seopress_pro_rich_snippets_' . $key );
		$offer = static fn( string $price, string $currency, string $url = '' ): array => '' === $price ? array() : array(
			'@type'         => 'Offer',
			'price'         => $price,
			'priceCurrency' => $currency,
			'url'           => $url,
		);
		switch ( $type ) {
			case 'recipes':
				return array(
					'@type'              => 'Recipe',
					'name'               => $g( 'recipes_name' ),
					'description'        => $g( 'recipes_desc' ),
					'recipeCategory'     => $g( 'recipes_cat' ),
					'image'              => $g( 'recipes_img' ),
					'prepTime'           => '' !== $g( 'recipes_prep_time' ) ? 'PT' . (int) $g( 'recipes_prep_time' ) . 'M' : '',
					'cookTime'           => '' !== $g( 'recipes_cook_time' ) ? 'PT' . (int) $g( 'recipes_cook_time' ) . 'M' : '',
					'recipeYield'        => $g( 'recipes_yield' ),
					'keywords'           => $g( 'recipes_keywords' ),
					'recipeCuisine'      => $g( 'recipes_cuisine' ),
					'nutrition'          => '' !== $g( 'recipes_calories' ) ? array( 'calories' => $g( 'recipes_calories' ) ) : '',
					'recipeIngredient'   => $g( 'recipes_ingredient' ),
					'recipeInstructions' => $g( 'recipes_instructions' ),
				);
			case 'courses':
				return array(
					'@type'       => 'Course',
					'name'        => $g( 'courses_title' ),
					'description' => $g( 'courses_desc' ),
					'provider'    => array(
						'name'   => $g( 'courses_school' ),
						'sameAs' => $g( 'courses_website' ),
					),
				);
			case 'videos':
				return array(
					'@type'        => 'VideoObject',
					'name'         => $g( 'videos_name' ),
					'description'  => $g( 'videos_description' ),
					'thumbnailUrl' => $g( 'videos_img' ),
					'contentUrl'   => $g( 'videos_url' ),
					'duration'     => '' !== $g( 'videos_duration' ) ? 'PT' . (int) $g( 'videos_duration' ) . 'M' : '',
				);
			case 'events':
				return array(
					'@type'       => 'Event',
					'name'        => $g( 'events_name' ),
					'description' => $g( 'events_desc' ),
					'image'       => $g( 'events_img' ),
					'startDate'   => trim( $g( 'events_start_date' ) . ' ' . $g( 'events_start_time' ) ),
					'endDate'     => trim( $g( 'events_end_date' ) . ' ' . $g( 'events_end_time' ) ),
					'location'    => array(
						'@type'   => 'Place',
						'name'    => $g( 'events_location_name' ),
						'address' => $g( 'events_location_address' ),
					),
					'offers'      => $offer( $g( 'events_offers_price' ), $g( 'events_offers_price_currency' ), $g( 'events_offers_url' ) ),
					'performer'   => $g( 'events_performer' ),
				);
			case 'products':
				return array(
					'@type'       => 'Product',
					'name'        => $g( 'product_name' ),
					'description' => $g( 'product_description' ),
					'image'       => $g( 'product_img' ),
					'sku'         => $g( 'product_sku' ),
					'brand'       => '' !== $g( 'product_brand' ) ? array(
						'@type' => 'Brand',
						'name'  => $g( 'product_brand' ),
					) : '',
					'offers'      => $offer( $g( 'product_price' ), $g( 'product_price_currency' ) ),
				);
			case 'softwareapp':
				return array(
					'@type'               => 'SoftwareApplication',
					'name'                => $g( 'softwareapp_name' ),
					'operatingSystem'     => $g( 'softwareapp_os' ),
					'applicationCategory' => $g( 'softwareapp_cat' ),
					'offers'              => $offer( $g( 'softwareapp_price' ), $g( 'softwareapp_currency' ) ),
				);
			case 'services':
				return array(
					'@type'       => 'Service',
					'name'        => $g( 'service_name' ),
					'serviceType' => $g( 'service_type' ),
					'description' => $g( 'service_description' ),
					'areaServed'  => $g( 'service_area' ),
				);
			case 'jobs':
				return array(
					'@type'              => 'JobPosting',
					'title'              => $g( 'jobs_name' ),
					'description'        => $g( 'jobs_desc' ),
					'datePosted'         => $g( 'jobs_date_posted' ),
					'validThrough'       => $g( 'jobs_valid_through' ),
					'employmentType'     => $g( 'jobs_employment_type' ),
					'hiringOrganization' => array(
						'name'   => $g( 'jobs_hiring_organization' ),
						'sameAs' => $g( 'jobs_hiring_same_as' ),
						'logo'   => $g( 'jobs_hiring_logo' ),
					),
					'jobLocation'        => array(
						'@type'   => 'Place',
						'address' => array(
							'streetAddress'   => $g( 'jobs_address_street' ),
							'addressLocality' => $g( 'jobs_address_locality' ),
							'addressRegion'   => $g( 'jobs_address_region' ),
							'postalCode'      => $g( 'jobs_postal_code' ),
							'addressCountry'  => $g( 'jobs_country' ),
						),
					),
					'baseSalary'         => '' !== $g( 'jobs_salary' ) ? array(
						'currency' => $g( 'jobs_salary_currency' ),
						'value'    => array(
							'value'    => $g( 'jobs_salary' ),
							'unitText' => $g( 'jobs_salary_unit' ),
						),
					) : '',
				);
			case 'review':
				return array(
					'@type'        => 'Review',
					'itemReviewed' => array( 'name' => $g( 'review_item' ) ),
					'reviewRating' => array( 'ratingValue' => $g( 'review_rating' ) ),
					'reviewBody'   => $g( 'review_body' ),
				);
		}

		return array();
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		$id = (int) $term->term_id;

		return self::filled( $this->fields( static fn( string $key ): string => self::tm( $id, '_seopress_' . $key ) ) );
	}

	/**
	 * A template record from one of its `{title, description, noindex, nofollow}` nodes.
	 *
	 * @param mixed $node   Node.
	 * @param array $site Site-wide flags.
	 * @return array<string, mixed>
	 */
	private function record( $node, array $site ): array {
		$node = is_array( $node ) ? $node : array();
		$s    = static fn( string $key ): string => is_scalar( $node[ $key ] ?? null ) ? trim( (string) $node[ $key ] ) : '';

		return array(
			'title'  => $this->template( $s( 'title' ) ),
			'desc'   => '' !== $s( 'description' ) ? $this->template( $s( 'description' ) ) : '%none%',
			'index'  => '' !== $s( 'noindex' ) || ! empty( $site['noindex'] ) ? 'noindex' : 'index',
			'follow' => '' !== $s( 'nofollow' ) || ! empty( $site['nofollow'] ) ? 'nofollow' : 'follow',
			'extra'  => array_values( array_filter( array( ! empty( $site['nosnippet'] ) ? 'nosnippet' : '', ! empty( $site['noimageindex'] ) ? 'noimageindex' : '' ) ) ),
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$t        = $this->titles();
		$social   = (array) get_option( 'seopress_social_option_name', array() );
		$advanced = (array) get_option( 'seopress_advanced_option_name', array() );
		$s        = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$out      = array();
		$char     = html_entity_decode( $s( $t, 'seopress_titles_sep' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$char     = '' !== $char ? $char : '-';
		if ( '' !== self::separator( $char ) ) {
			$out['sep'] = self::separator( $char );
		}
		$site = array(
			'noindex'      => '' !== $s( $t, 'seopress_titles_noindex' ),
			'nofollow'     => '' !== $s( $t, 'seopress_titles_nofollow' ),
			'nosnippet'    => '' !== $s( $t, 'seopress_titles_nosnippet' ),
			'noimageindex' => '' !== $s( $t, 'seopress_titles_noimageindex' ),
		);

		$templates = array();
		$scores    = array();
		foreach ( (array) ( $t['seopress_titles_single_titles'] ?? array() ) as $type => $node ) {
			if ( post_type_exists( (string) $type ) ) {
				$templates[ 'post:' . $type ] = $this->record( $node, $site );
				// Its "Remove SEO metabox" (`enable`) on for a type: it never scored it (Q277).
				if ( ! empty( ( (array) $node )['enable'] ) ) {
					$scores[ 'post:' . $type ] = array( 'scores' => 'off' );
				}
			}
		}
		foreach ( (array) ( $t['seopress_titles_tax_titles'] ?? array() ) as $tax => $node ) {
			if ( taxonomy_exists( (string) $tax ) ) {
				$templates[ 'tax:' . $tax ] = $this->record( $node, $site );
			}
		}
		foreach ( (array) ( $t['seopress_titles_archive_titles'] ?? array() ) as $type => $node ) {
			$object = get_post_type_object( (string) $type );
			if ( $object && $object->has_archive ) {
				$templates[ 'archive:' . $type ] = $this->record( $node, $site );
			}
		}
		$templates['home'] = array(
			'title' => $this->template( $s( $t, 'seopress_titles_home_site_title' ) ),
			'desc'  => '' !== $s( $t, 'seopress_titles_home_site_desc' ) ? $this->template( $s( $t, 'seopress_titles_home_site_desc' ) ) : '%none%',
		);
		foreach ( array( 'author', 'date' ) as $kind ) {
			$templates[ $kind ] = $this->record(
				array(
					'title'       => $s( $t, 'seopress_titles_archives_' . $kind . '_title' ),
					'description' => $s( $t, 'seopress_titles_archives_' . $kind . '_desc' ),
					// An archive it switched off (it redirects to the home page) stays out of search here.
					'noindex'     => '' !== $s( $t, 'seopress_titles_archives_' . $kind . '_noindex' ) || '' !== $s( $t, 'seopress_titles_archives_' . $kind . '_disable' ) ? '1' : '',
				),
				$site
			);
		}
		$templates['search'] = array(
			'title' => $this->template( $s( $t, 'seopress_titles_archives_search_title' ) ),
			'index' => '' !== $s( $t, 'seopress_titles_archives_search_title_noindex' ) || $site['noindex'] ? 'noindex' : 'index',
		);
		$templates['404']    = array( 'title' => $this->template( $s( $t, 'seopress_titles_archives_404_title' ) ) );
		$out['templates']    = $templates;
		if ( array() !== $scores ) {
			$out['content_types'] = $scores;
		}
		// The free plugin prints no structured data for an article; nothing to carry over there.

		$type   = strtolower( $s( $social, 'seopress_social_knowledge_type' ) );
		$handle = ltrim( $s( $social, 'seopress_social_accounts_twitter' ), '@' );
		$out   += array(
			'og'                   => '' !== $s( $social, 'seopress_social_facebook_og' ),
			'x_cards'              => '' !== $s( $social, 'seopress_social_twitter_card' ),
			'x_card'               => 'default' === $s( $social, 'seopress_social_twitter_card_img_size' ) ? 'summary' : 'summary_large_image',
			'x_site'               => 1 === preg_match( '#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})#i', $handle, $m ) ? $m[1] : $handle,
			'fb_app_id'            => $s( $social, 'seopress_social_facebook_app_id' ),
			'og_default_image'     => self::image_id( $s( $social, 'seopress_social_facebook_img' ) ),
			// It shows the featured image and never looks inside the text.
			'og_content_image'     => false,
			'identity_type'        => 'person' === $type ? 'person' : 'organization',
			'identity_name'        => $s( $social, 'seopress_social_knowledge_name' ),
			'identity_logo'        => self::image_id( $s( $social, 'seopress_social_knowledge_img' ) ),
			'identity_description' => $s( $social, 'seopress_social_knowledge_desc' ),
			'identity_founded'     => $s( $social, 'seopress_social_knowledge_founding_date' ),
			'verify_google'        => $s( $advanced, 'seopress_advanced_advanced_google' ),
			'verify_bing'          => $s( $advanced, 'seopress_advanced_advanced_bing' ),
			'verify_yandex'        => $s( $advanced, 'seopress_advanced_advanced_yandex' ),
			'verify_baidu'         => $s( $advanced, 'seopress_advanced_advanced_baidu' ),
			'verify_pinterest'     => $s( $advanced, 'seopress_advanced_advanced_pinterest' ),
		);
		$same   = array();
		foreach ( array( 'facebook', 'instagram', 'linkedin', 'pinterest', 'youtube' ) as $network ) {
			$same[] = $s( $social, 'seopress_social_accounts_' . $network );
		}
		foreach ( preg_split( '/\s+/', $s( $social, 'seopress_social_accounts_extra' ) ) as $url ) {
			$same[] = (string) $url;
		}
		if ( '' !== $out['x_site'] ) {
			$same[] = 'https://x.com/' . $out['x_site'];
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );

		return $out + self::more_settings( $t, $social, $advanced, (array) get_option( 'seopress_xml_sitemap_option_name', array() ), array_map( 'strval', array_values( get_post_types( array( 'public' => true ) ) ) ), array_map( 'strval', array_values( get_taxonomies( array( 'public' => true ) ) ) ) );
	}

	/**
	 * What the full-settings certification found missing (queue Q254): the category and shop
	 * category bases it removed (every category address changed after a move), the home page's
	 * other name, previous/next links and page-2 robots, the sitemap's choices, the
	 * organisation's registered details. Pure, for the tests.
	 *
	 * @param array<string, mixed> $t          `seopress_titles_option_name`.
	 * @param array<string, mixed> $social     `seopress_social_option_name`.
	 * @param array<string, mixed> $advanced   `seopress_advanced_option_name`.
	 * @param array<string, mixed> $xml        `seopress_xml_sitemap_option_name`.
	 * @param array<int, string>   $post_types Public content types.
	 * @param array<int, string>   $taxonomies Public taxonomies.
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $t, array $social, array $advanced, array $xml, array $post_types, array $taxonomies ): array {
		$s   = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$out = array(
			'website_alt_name'             => $s( $t, 'seopress_titles_home_site_title_alt' ),
			'identity_alt_name'            => $s( $t, 'seopress_titles_home_site_title_alt' ),
			// The sharing tags it prints beyond the usual: the Facebook page as publisher, the
			// date a post last changed, a post's tags. Previous/next links on pages kept out of
			// search too.
			'social_extras'                => array( 'publisher', 'updated_time', 'article_tags' ),
			'adjacent_noindex'             => true,
			'adjacent_links'               => '' !== $s( $t, 'seopress_titles_paged_rel' ),
			'robots_noindex_archive_pages' => '' !== $s( $t, 'seopress_titles_paged_noindex' ),
		);
		if ( '' !== $s( $advanced, 'seopress_advanced_advanced_category_url' ) ) {
			$out['url_category_base'] = true;
		}
		if ( '' !== $s( $advanced, 'seopress_advanced_advanced_product_cat_url' ) ) {
			$out['url_product_base'] = true;
		}
		// Attachment pages sent to the page they belong to.
		if ( '' !== $s( $advanced, 'seopress_advanced_advanced_attachments' ) ) {
			$out['url_attachment'] = 'parent';
		}
		if ( '' !== $s( $xml, 'seopress_xml_sitemap_general_enable' ) ) {
			$types                             = (array) ( $xml['seopress_xml_sitemap_post_types_list'] ?? array() );
			$taxes                             = (array) ( $xml['seopress_xml_sitemap_taxonomies_list'] ?? array() );
			$in                                = static fn( array $rows, string $name ): bool => is_array( $rows[ $name ] ?? null ) && '' !== trim( (string) ( $rows[ $name ]['include'] ?? '' ) );
			$out['sitemap_exclude_types']      = array_values( array_filter( $post_types, static fn( string $type ): bool => 'attachment' !== $type && ! $in( $types, $type ) ) );
			$out['sitemap_exclude_taxonomies'] = array_values( array_filter( $taxonomies, static fn( string $tax ): bool => 'post_format' !== $tax && ! $in( $taxes, $tax ) ) );
			$out['sitemap_images']             = '' !== $s( $xml, 'seopress_xml_sitemap_img_enable' );
			$out['sitemap_authors']            = '' !== $s( $xml, 'seopress_xml_sitemap_author_enable' );
		}
		// Its breadcrumbs are SEOPress PRO's: a theme's seopress_display_breadcrumbs() keeps
		// printing the trail only where they were switched on there.
		$pro = (array) get_option( 'seopress_pro_option_name', array() );
		if ( '' !== $s( $pro, 'seopress_breadcrumbs_enable' ) ) {
			$out['crumb_compat'] = array( 'seopress' );
		}
		$legal = array();
		// Its "tax ID" is printed as the VAT number; its address, telephone and e-mail on the
		// organisation, and one contact point.
		foreach ( array(
			'legalName'         => 'seopress_social_knowledge_legal_name',
			'vatID'             => 'seopress_social_knowledge_tax_id',
			'numberOfEmployees' => 'seopress_social_knowledge_employees',
			'telephone'         => 'seopress_social_knowledge_phone',
			'streetAddress'     => 'seopress_social_knowledge_street',
			'addressLocality'   => 'seopress_social_knowledge_locality',
			'addressRegion'     => 'seopress_social_knowledge_region',
			'postalCode'        => 'seopress_social_knowledge_postal_code',
			'addressCountry'    => 'seopress_social_knowledge_country',
		) as $field => $key ) {
			if ( '' !== $s( $social, $key ) ) {
				$legal[ $field ] = $s( $social, $key );
			}
		}
		if ( array() !== $legal && 'person' !== strtolower( $s( $social, 'seopress_social_knowledge_type' ) ) ) {
			$out['identity_legal'] = array( 'org' => $legal );
		}
		$kind  = sanitize_title( $s( $social, 'seopress_social_knowledge_contact_type' ) );
		$kind  = isset( \ZinnDigital\ZinnSEO\Schema\Schema::CONTACT_TYPES[ $kind ] ) ? $kind : 'customer-support';
		$point = array_filter(
			array(
				'telephone' => $s( $social, 'seopress_social_knowledge_phone' ),
				'email'     => $s( $social, 'seopress_social_knowledge_email' ),
			)
		);
		if ( array() !== $point ) {
			$out['identity_contacts'] = array( $kind => $point );
		}

		return array_filter( $out, static fn( $v ): bool => '' !== $v );
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'Content analysis scores are worked out again by Zinn® SEO rather than copied.', 'zinn-seo' ),
			__( 'Recipes, events, products, courses and the other types set for a page with SEOPress Pro are copied with their details. Zinn® SEO Pro shows them to search engines and lets you edit them; without it they are kept, ready for the day you switch it on.', 'zinn-seo' ),
		);
	}
}
