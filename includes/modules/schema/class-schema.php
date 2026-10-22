<?php
/**
 * The site's knowledge graph and the page's own nodes (zs-030, zs-031, zs-032).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\People;
use ZinnDigital\ZinnSEO\Blocks\Trail;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds to the page's ONE graph (printed by the head module):
 *
 * - WHO runs the site: an Organization (name, logo, sameAs, contact points, description) or a
 *   Person, as `publisher` of the WebSite and of every article (zs-030).
 * - The WebSite with its search action (zs-030).
 * - The page itself, typed for what it is (AboutPage, ContactPage…), its main image as its own
 *   node, its breadcrumb trail, its language and its translations with Tranzly (zs-031, zs-032).
 * - The article on posts — Article, BlogPosting, NewsArticle… per content type or per post — with
 *   its author as ONE Person node carrying their expertise (E-E-A-T) (zs-032).
 * - The WooCommerce product of a product page, taken from WooCommerce and printed here instead of
 *   in WooCommerce's own second script (zs-031).
 *
 * ⛔ Cost: everything comes from data already loaded for the page (the post, its primed meta, the
 * autoloaded settings — the logo's address and size are kept in the settings for that reason).
 */
final class Schema {

	/** Page types a page may be. */
	public const PAGE_TYPES = array( 'WebPage', 'AboutPage', 'ContactPage', 'CheckoutPage', 'CollectionPage', 'ItemPage', 'MedicalWebPage', 'ProfilePage', 'QAPage', 'FAQPage', 'RealEstateListing', 'SearchResultsPage' );

	/** Article types a post may be ('' = no article node). */
	public const ARTICLE_TYPES = array( '', 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'OpinionNewsArticle', 'AnalysisNewsArticle' );

	/** Contact point kinds Google lists: setting key => schema.org `contactType`. */
	public const CONTACT_TYPES = array(
		'customer-support'    => 'customer support',
		'technical-support'   => 'technical support',
		'billing-support'     => 'billing support',
		'bill-payment'        => 'bill payment',
		'sales'               => 'sales',
		'reservations'        => 'reservations',
		'credit-card-support' => 'credit card support',
		'emergency'           => 'emergency',
		'baggage-tracking'    => 'baggage tracking',
		'roadside-assistance' => 'roadside assistance',
		'package-tracking'    => 'package tracking',
	);

	/**
	 * The module's settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array(
			// The site's own name and other name in search results (WebSite), apart from the
			// organisation's: Yoast SEO, All in One SEO and SmartCrawl keep them apart, and a move
			// lost them (importer certification, queue Q254). Empty: the site title and the
			// organisation's other name, as before.
			// The kind of organisation (a schema.org type below Organization: NewsMediaOrganization,
			// Corporation, NGO…); empty: Organization. SmartCrawl lets a site choose it (Q254).
			'identity_org_type'     => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 60,
				'pattern' => '/^([A-Z][A-Za-z]{2,59})?$/',
				'group'   => 'site',
			),
			'website_name'          => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 200,
				'group'   => 'site',
			),
			'website_alt_name'      => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 200,
				'group'   => 'site',
			),
			'identity_alt_name'     => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 200,
				'group'   => 'site',
			),
			'identity_description'  => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 500,
				'group'   => 'site',
			),
			'identity_same_as'      => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'site',
			),
			// The site's TikTok username, a profile in its Organization or Person node (zs-760).
			'identity_tiktok'       => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 100,
				'group'   => 'site',
			),
			'identity_contacts'     => array(
				'type'     => 'dict',
				'default'  => array(),
				'max_keys' => 11,
				'fields'   => array(
					'telephone' => array(
						'type' => 'string',
						'max'  => 40,
					),
					'email'     => array(
						'type' => 'string',
						'max'  => 120,
					),
					'url'       => array( 'type' => 'url' ),
					'area'      => array(
						'type' => 'string',
						'max'  => 120,
					),
					'languages' => array(
						'type' => 'string',
						'max'  => 120,
					),
				),
				'group'    => 'site',
			),
			'identity_founded'      => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 10,
				'pattern' => '/^(\d{4}(-\d{2}(-\d{2})?)?)?$/',
				'group'   => 'site',
			),
			// The breadcrumb trail in the page's structured data. Off for a site that moved from
			// a plugin that had breadcrumbs switched off (none were in its pages).
			'schema_breadcrumbs'    => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'site',
			),
			// The products a shop page lists, as an ItemList (Rank Math Pro printed one; the
			// importer switches it on for a site moving from it).
			'schema_product_lists'  => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'site',
			),
			// The organisation's registered details (legal name, tax and company numbers, size,
			// e-mail) in one record, `org`, printed on its node when set (a site moving from Rank
			// Math brings its own).
			'identity_legal'        => array(
				'type'    => 'dict',
				'default' => array(),
				'fields'  => array(
					'legalName'         => array(
						'type' => 'string',
						'max'  => 200,
					),
					'taxID'             => array(
						'type' => 'string',
						'max'  => 60,
					),
					'vatID'             => array(
						'type' => 'string',
						'max'  => 60,
					),
					'iso6523Code'       => array(
						'type' => 'string',
						'max'  => 60,
					),
					'duns'              => array(
						'type' => 'string',
						'max'  => 20,
					),
					'numberOfEmployees' => array(
						'type' => 'string',
						'max'  => 20,
					),
					// The organisation's main telephone number (All in One SEO and SEOPress print it
					// on the Organization; queue Q254).
					'telephone'         => array(
						'type' => 'string',
						'max'  => 40,
					),
					// Its postal address (Squirrly and SEOPress print one on the Organization; a
					// local business keeps its own address under Local).
					'streetAddress'     => array(
						'type' => 'string',
						'max'  => 200,
					),
					'addressLocality'   => array(
						'type' => 'string',
						'max'  => 100,
					),
					'addressRegion'     => array(
						'type' => 'string',
						'max'  => 100,
					),
					'postalCode'        => array(
						'type' => 'string',
						'max'  => 20,
					),
					'addressCountry'    => array(
						'type' => 'string',
						'max'  => 2,
					),
					'email'             => array(
						'type' => 'string',
						'max'  => 120,
					),
				),
				'group'   => 'site',
			),
			'identity_logo_url'     => array(
				'type'    => 'url',
				'default' => '',
				'group'   => 'site',
			),
			'identity_logo_w'       => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'group'   => 'site',
			),
			'identity_logo_h'       => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'group'   => 'site',
			),
			// An article's picture as its featured image cut to 16:9, 4:3 and square (Google's
			// article guide) plus the images in its text, and its excerpt on the article node:
			// off by default; on for a site that moved from Schema & Structured Data for WP,
			// which printed both (IMP2).
			'schema_article_images' => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'schema',
			),
			'schema_article_desc'   => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'schema',
			),
			'search_action'         => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'schema',
			),
			'schema_types'          => array(
				'type'     => 'dict',
				'default'  => array(),
				'max_keys' => 200,
				'fields'   => array(
					'page'    => array(
						'type'    => 'enum',
						'choices' => array_merge( array( '' ), self::PAGE_TYPES ),
					),
					'article' => array(
						'type'    => 'enum',
						'choices' => array_merge( self::ARTICLE_TYPES, array( 'none' ) ),
					),
				),
				'group'    => 'schema',
			),
			'schema_feed'           => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'schema',
			),
			'schema_absorb'         => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'schema',
			),
		);
	}

	/**
	 * The module's per-post fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		return array(
			'schema_page'    => array(
				'type'        => 'enum',
				'objects'     => array( 'post' ),
				'choices'     => array_merge( array( '' ), self::PAGE_TYPES ),
				'description' => static fn() => __( 'What kind of page this is for search engines. Empty: the default for its content type.', 'zinn-seo' ),
			),
			'schema_article' => array(
				'type'        => 'enum',
				'objects'     => array( 'post' ),
				'choices'     => array_merge( self::ARTICLE_TYPES, array( 'none' ) ),
				'description' => static fn() => __( 'The kind of article this is (BlogPosting, NewsArticle…). Empty: the default for its content type.', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		add_filter( 'zinn_seo_graph', array( self::class, 'graph' ), 15, 2 );
		if ( (bool) Settings::get( 'schema_product_lists', false ) ) {
			require_once __DIR__ . '/class-product-list.php';
			Product_List::boot();
		}
		add_action( 'wp', array( self::class, 'absorb_woo_output' ), 30 );
	}

	/**
	 * The defaults per content type: posts are blog posts (news articles on a news site), other
	 * types have no article node until someone chooses one.
	 *
	 * @param string $post_type Post type.
	 * @return array{page: string, article: string}
	 */
	public static function type_defaults( string $post_type ): array {
		$set     = (array) Settings::get( 'schema_types', array() );
		$own     = (array) ( $set[ 'post:' . $post_type ] ?? array() );
		$article = '';
		if ( 'post' === $post_type ) {
			$article = 'news' === Settings::get( 'site_type', '' ) ? 'NewsArticle' : 'BlogPosting';
		}
		$article = (string) ( $own['article'] ?? $article );

		return array(
			'page'    => (string) ( $own['page'] ?? '' ),
			'article' => 'none' === $article ? '' : $article,
		);
	}

	/**
	 * The page type and article type of a post (its own choice, else its type's).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{page: string, article: string}
	 */
	public static function post_types( \WP_Post $post ): array {
		$def     = self::type_defaults( $post->post_type );
		$page    = (string) get_post_meta( $post->ID, '_zseo_schema_page', true );
		$article = (string) get_post_meta( $post->ID, '_zseo_schema_article', true );

		return array(
			'page'    => in_array( $page, self::PAGE_TYPES, true ) ? $page : $def['page'],
			'article' => 'none' === $article ? '' : ( in_array( $article, self::ARTICLE_TYPES, true ) && '' !== $article ? $article : $def['article'] ),
		);
	}

	/**
	 * The `@id` of whoever runs the site.
	 *
	 * @return string
	 */
	public static function publisher_id(): string {
		if ( 'person' === Settings::get( 'identity_type', 'organization' ) ) {
			return (int) Settings::get( 'identity_person', 0 ) > 0 ? People::id( (int) Settings::get( 'identity_person', 0 ) ) : home_url( '/' ) . '#person';
		}

		return home_url( '/' ) . '#organization';
	}

	/**
	 * Who runs the site: the Organization, or the Person.
	 *
	 * @return array<string, mixed>
	 */
	public static function identity(): array {
		$home = home_url( '/' );
		if ( 'person' === Settings::get( 'identity_type', 'organization' ) && (int) Settings::get( 'identity_person', 0 ) > 0 ) {
			$node = People::node( (int) Settings::get( 'identity_person', 0 ) );
			if ( array() !== $node ) {
				return $node;
			}
		}
		$name = Html::plain( (string) Settings::get( 'identity_name', '' ) );
		// A site run by a person who is not one of its users (or with no user chosen): still a
		// Person, by name — it used to be printed as an Organization (found by the importer's
		// comparison with Rank Math, whose default is a person named after the site, 2026-10-10).
		$person = 'person' === Settings::get( 'identity_type', 'organization' );
		$kind   = (string) Settings::get( 'identity_org_type', '' );
		$node   = array(
			'@type' => $person ? 'Person' : ( 1 === preg_match( '/^[A-Z][A-Za-z]{2,59}$/', $kind ) ? $kind : 'Organization' ),
			'@id'   => $home . ( $person ? '#person' : '#organization' ),
			'name'  => '' !== $name ? $name : Html::plain( (string) get_bloginfo( 'name' ) ),
			'url'   => \ZinnDigital\ZinnSEO\Templates\Resolver::home(),
		);
		$alt    = Html::plain( (string) Settings::get( 'identity_alt_name', '' ) );
		if ( '' !== $alt ) {
			$node['alternateName'] = $alt;
		}
		$desc = Html::plain( (string) Settings::get( 'identity_description', '' ) );
		if ( '' !== $desc ) {
			// In the language being viewed, when a translation plugin keeps one (Tranzly).
			$given               = apply_filters( 'zinn_seo_site_text', $desc, 'identity_description', '' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in Family::site_text().
			$node['description'] = is_string( $given ) && '' !== trim( $given ) ? Html::plain( $given ) : $desc;
		}
		$logo = (string) Settings::get( 'identity_logo_url', '' );
		if ( '' !== $logo ) {
			$img = array(
				'@type'      => 'ImageObject',
				'@id'        => $home . '#logo',
				'url'        => $logo,
				'contentUrl' => $logo,
				'caption'    => $node['name'],
			);
			if ( (int) Settings::get( 'identity_logo_w', 0 ) > 0 ) {
				$img['width']  = (int) Settings::get( 'identity_logo_w', 0 );
				$img['height'] = (int) Settings::get( 'identity_logo_h', 0 );
			}
			if ( $person ) {
				$node['image'] = $img;
			} else {
				$node['logo']  = $img;
				$node['image'] = array( '@id' => $home . '#logo' );
			}
		}
		$same   = self::urls( (array) Settings::get( 'identity_same_as', array() ) );
		$tiktok = People::tiktok_url( (string) Settings::get( 'identity_tiktok', '' ) );
		if ( '' !== $tiktok && ! in_array( $tiktok, $same, true ) ) {
			$same[] = $tiktok;
		}
		if ( array() !== $same ) {
			$node['sameAs'] = $same;
		}
		if ( $person ) {
			return $node;
		}
		$founded = (string) Settings::get( 'identity_founded', '' );
		if ( '' !== $founded ) {
			$node['foundingDate'] = $founded;
		}
		$legal = (array) Settings::get( 'identity_legal', array() );
		$node += self::legal_details( is_array( $legal['org'] ?? null ) ? $legal['org'] : array() );
		foreach ( (array) Settings::get( 'identity_contacts', array() ) as $kind => $c ) {
			if ( ! isset( self::CONTACT_TYPES[ (string) $kind ] ) || ! is_array( $c ) ) {
				continue;
			}
			$point = array_filter(
				array(
					'@type'             => 'ContactPoint',
					'contactType'       => self::CONTACT_TYPES[ (string) $kind ],
					'telephone'         => (string) ( $c['telephone'] ?? '' ),
					'email'             => is_email( (string) ( $c['email'] ?? '' ) ) ? (string) $c['email'] : '',
					'url'               => (string) ( $c['url'] ?? '' ),
					'areaServed'        => (string) ( $c['area'] ?? '' ),
					'availableLanguage' => (string) ( $c['languages'] ?? '' ),
				)
			);
			if ( count( $point ) > 2 ) {
				$node['contactPoint'][] = $point;
			}
		}

		return $node;
	}

	/**
	 * The organisation's registered details as schema.org properties. Pure, for the tests.
	 *
	 * @param array<string, mixed> $legal `identity_legal`.
	 * @return array<string, mixed>
	 */
	public static function legal_details( array $legal ): array {
		$out = array();
		foreach ( array( 'legalName', 'taxID', 'vatID', 'iso6523Code', 'duns' ) as $key ) {
			$v = isset( $legal[ $key ] ) && is_scalar( $legal[ $key ] ) ? Html::plain( (string) $legal[ $key ] ) : '';
			if ( '' !== $v ) {
				$out[ $key ] = $v;
			}
		}
		$size = isset( $legal['numberOfEmployees'] ) && is_scalar( $legal['numberOfEmployees'] ) ? trim( (string) $legal['numberOfEmployees'] ) : '';
		if ( 1 === preg_match( '/^(\d+)\s*-\s*(\d+)$/', $size, $m ) ) {
			$out['numberOfEmployees'] = array(
				'@type'    => 'QuantitativeValue',
				'minValue' => (int) $m[1],
				'maxValue' => (int) $m[2],
			);
		} elseif ( 1 === preg_match( '/^\d+$/', $size ) ) {
			$out['numberOfEmployees'] = array(
				'@type' => 'QuantitativeValue',
				'value' => (int) $size,
			);
		}
		$phone = isset( $legal['telephone'] ) && is_scalar( $legal['telephone'] ) ? Html::plain( (string) $legal['telephone'] ) : '';
		if ( 1 === preg_match( '/^\+?[0-9 ()\-.]{5,40}$/', $phone ) ) {
			$out['telephone'] = $phone;
		}
		$address = array( '@type' => 'PostalAddress' );
		foreach ( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) as $key ) {
			$v = isset( $legal[ $key ] ) && is_scalar( $legal[ $key ] ) ? Html::plain( (string) $legal[ $key ] ) : '';
			if ( '' !== $v ) {
				$address[ $key ] = 'addressCountry' === $key ? strtoupper( $v ) : $v;
			}
		}
		if ( count( $address ) > 1 ) {
			$out['address'] = $address;
		}
		$email = isset( $legal['email'] ) && is_scalar( $legal['email'] ) ? trim( (string) $legal['email'] ) : '';
		if ( '' !== $email && is_email( $email ) ) {
			$out['email'] = $email;
		}

		return $out;
	}

	/**
	 * Absolute http(s) addresses only.
	 *
	 * @param array<int, mixed> $addresses Addresses.
	 * @return array<int, string>
	 */
	public static function urls( array $addresses ): array {
		$out = array();
		foreach ( $addresses as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Add the site, page and article nodes to the graph.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes so far (the head module's WebSite and page).
	 * @param Context                             $c     The page.
	 * @return array<string, array<string, mixed>>
	 */
	public static function graph( $nodes, $c ): array {
		$nodes = (array) $nodes;
		if ( ! $c instanceof Context || ! isset( $nodes['website'] ) ) {
			return $nodes;
		}
		$identity  = self::identity();
		$publisher = array( '@id' => (string) $identity['@id'] );

		// zs-030: who runs the site, and the site's search.
		$nodes['identity']             = $identity;
		$nodes['website']['publisher'] = $publisher;
		$site_name                     = Html::plain( (string) Settings::get( 'website_name', '' ) );
		if ( '' !== $site_name ) {
			$nodes['website']['name'] = $site_name;
		}
		$alt = Html::plain( (string) Settings::get( 'website_alt_name', '' ) );
		$alt = '' !== $alt ? $alt : Html::plain( (string) Settings::get( 'identity_alt_name', '' ) );
		if ( '' !== $alt ) {
			$nodes['website']['alternateName'] = $alt;
		}
		if ( (bool) Settings::get( 'search_action', true ) ) {
			$nodes['website']['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					// The search of the language shown (/fr/?s=…), as the site's own search box sends it.
					'urlTemplate' => add_query_arg( 's', '{search_term_string}', \ZinnDigital\ZinnSEO\Templates\Resolver::home() ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}
		$nodes['website']['inLanguage'] = self::language();
		if ( ! isset( $nodes['webpage'] ) ) {
			return $nodes;
		}

		// zs-032: the page, typed for what it is.
		$page               = $nodes['webpage'];
		$url                = (string) $page['url'];
		$page['inLanguage'] = self::language();
		$post               = $c->post();
		$kinds              = $post instanceof \WP_Post ? self::post_types( $post ) : array(
			'page'    => '',
			'article' => '',
		);
		if ( '' !== $kinds['page'] ) {
			$page['@type'] = $kinds['page'];
		}
		if ( $c->front ) {
			$page['about'] = $publisher;
		}

		// The page's main image as its own node, shared by the page and the article.
		if ( isset( $page['primaryImageOfPage'] ) && is_array( $page['primaryImageOfPage'] ) && ! isset( $page['primaryImageOfPage']['@id'] ) ) {
			$img                        = $page['primaryImageOfPage'];
			$img['@id']                 = $url . '#primaryimage';
			$nodes['primaryimage']      = $img;
			$page['primaryImageOfPage'] = array( '@id' => $img['@id'] );
			$page['image']              = array( '@id' => $img['@id'] );
			// The picture's address as a thumbnail (Yoast SEO and Rank Math print it; queue Q254).
			if ( isset( $img['url'] ) && is_string( $img['url'] ) ) {
				$page['thumbnailUrl'] = $img['url'];
			}
		}

		// The author as ONE Person node with their expertise.
		if ( $post instanceof \WP_Post && isset( $page['author'] ) && (int) $post->post_author > 0 ) {
			$person = People::node( (int) $post->post_author );
			if ( array() !== $person ) {
				$nodes['author'] = $person;
				$page['author']  = array( '@id' => $person['@id'] );
			}
		}

		// An author's archive is a profile page ABOUT that person: Google reads a ProfilePage's
		// `mainEntity` (found missing by the importer's page-by-page comparison, 2026-10-10).
		if ( 'author' === $c->kind && $c->object instanceof \WP_User ) {
			$person = People::node( (int) $c->object->ID );
			if ( array() !== $person ) {
				$nodes['author']    = $person;
				$page['mainEntity'] = array( '@id' => $person['@id'] );
			}
		}

		// The breadcrumb trail.
		$trail = (bool) Settings::get( 'schema_breadcrumbs', true ) ? Trail::items( $c ) : array();
		if ( count( $trail ) > 1 ) {
			$nodes['breadcrumb'] = Trail::node( $trail, $url . '#breadcrumb' );
			$page['breadcrumb']  = array( '@id' => $url . '#breadcrumb' );
		}

		// Languages with Tranzly (zs-031): which translation this is, and of what.
		if ( $post instanceof \WP_Post ) {
			$page = self::translations( $page, $post );
		}

		// zs-032: the article.
		if ( $post instanceof \WP_Post && '' !== $kinds['article'] && ! $c->front ) {
			$nodes['article'] = self::article( $post, $kinds['article'], $page, $publisher, $c );
		}

		$nodes['webpage'] = $page;

		// zs-031: WooCommerce's product joins this graph instead of printing its own.
		$product = self::woo_product( $url );
		if ( array() !== $product ) {
			// zs-764/765: brand, model, material, colour and size where the shop gives none.
			$nodes['product'] = Fill::product_props( $product, (int) get_queried_object_id() );
		}

		return $nodes;
	}

	/**
	 * The article node.
	 *
	 * @param \WP_Post             $post      Post.
	 * @param string               $type      Article type.
	 * @param array<string, mixed> $page      The page node.
	 * @param array<string, mixed> $publisher Publisher reference.
	 * @param Context              $c         The page.
	 * @return array<string, mixed>
	 */
	public static function article( \WP_Post $post, string $type, array $page, array $publisher, Context $c ): array {
		$url   = (string) $page['url'];
		$title = Resolver::title( $c );
		$node  = array(
			'@type'            => $type,
			'@id'              => $url . '#article',
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'headline'         => self::headline( Html::plain( get_the_title( $post ) ), $title ),
			'datePublished'    => (string) get_post_time( 'c', true, $post ),
			'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
			'publisher'        => $publisher,
		);
		// Language, description and page links live on the page node it names; repeating them only adds weight.
		if ( isset( $page['author'] ) ) {
			$node['author'] = $page['author'];
		}
		if ( isset( $page['primaryImageOfPage'] ) ) {
			$node['image'] = $page['primaryImageOfPage'];
			if ( isset( $page['thumbnailUrl'] ) ) {
				$node['thumbnailUrl'] = $page['thumbnailUrl'];
			}
		}
		// The picture in Google's three shapes and the text's images, and the excerpt on the
		// article itself: a site's choice (a move from Schema & Structured Data for WP keeps
		// what it printed; IMP2).
		if ( (bool) Settings::get( 'schema_article_images', false ) ) {
			require_once __DIR__ . '/class-article-images.php';
			$images = Article_Images::for_post( $post );
			if ( array() !== $images ) {
				$node['image'] = $images;
			}
		}
		if ( (bool) Settings::get( 'schema_article_desc', false ) && ! post_password_required( $post ) ) {
			require_once __DIR__ . '/class-article-images.php';
			$excerpt = Article_Images::excerpt( $post );
			if ( '' !== $excerpt ) {
				$node['description'] = $excerpt;
			}
		}
		$words = self::word_count( $post );
		if ( $words > 0 ) {
			$node['wordCount'] = $words;
		}
		$main = Trail::main_term( $post );
		if ( $main instanceof \WP_Term ) {
			$node['articleSection'] = Html::plain( $main->name );
		}
		$tags = get_the_terms( $post, 'post_tag' );
		if ( is_array( $tags ) && array() !== $tags ) {
			$node['keywords'] = array_values( array_map( static fn( \WP_Term $t ): string => Html::plain( $t->name ), array_slice( $tags, 0, 20 ) ) );
		}
		if ( (int) $post->comment_count > 0 ) {
			$node['commentCount'] = (int) $post->comment_count;
		}

		// zs-764/765: the summary, keywords and topics set in bulk or filled by AI, where the page gives none.
		return Fill::article_props( $node, $post );
	}

	/**
	 * A headline: the post title, or the SEO title when the post has none; at most 110 characters
	 * (the length Google's article documentation once set, and what headlines are cut to).
	 *
	 * @param string $title     Post title.
	 * @param string $seo_title SEO title.
	 * @return string
	 */
	public static function headline( string $title, string $seo_title ): string {
		$h = '' !== $title ? $title : $seo_title;

		return mb_strlen( $h ) > 110 ? rtrim( mb_substr( $h, 0, 109 ) ) . '…' : $h;
	}

	/**
	 * The words of a post (kept with the post when it is saved; counted once otherwise).
	 *
	 * @param \WP_Post $post Post.
	 * @return int
	 */
	public static function word_count( \WP_Post $post ): int {
		$kept = get_post_meta( $post->ID, '_zseo_words', true );
		if ( is_array( $kept ) && (int) ( $kept['m'] ?? 0 ) === (int) strtotime( (string) $post->post_modified_gmt ) ) {
			return (int) $kept['n'];
		}

		return Html::words( (string) preg_replace( '/<!--.*?-->/s', ' ', str_replace( '<', ' <', (string) $post->post_content ) ) );
	}

	/**
	 * Keep a post's word count with it when it is saved (so a page view never counts).
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function store_word_count( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$n = Html::words( (string) preg_replace( '/<!--.*?-->/s', ' ', str_replace( '<', ' <', (string) $post->post_content ) ) );
		update_post_meta(
			(int) $post_id,
			'_zseo_words',
			array(
				'n' => $n,
				'm' => (int) strtotime( (string) $post->post_modified_gmt ),
			)
		);
	}

	/**
	 * The page's language (BCP 47): Tranzly's current language, else the site's.
	 *
	 * @return string
	 */
	public static function language(): string {
		if ( function_exists( 'tranzly_current_language' ) ) {
			$lang = (string) call_user_func( 'tranzly_current_language' );
			if ( '' !== $lang ) {
				return str_replace( '_', '-', $lang );
			}
		}

		return str_replace( '_', '-', (string) get_bloginfo( 'language' ) );
	}

	/**
	 * The translations of a page (Tranzly's public API, ADR 0033 §B).
	 *
	 * @param array<string, mixed> $page Page node.
	 * @param \WP_Post             $post Post.
	 * @return array<string, mixed>
	 */
	public static function translations( array $page, \WP_Post $post ): array {
		foreach ( array( 'tranzly_languages', 'tranzly_current_language', 'tranzly_default_language', 'tranzly_get_translation', 'tranzly_language_url' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return $page;
			}
		}
		$current = (string) call_user_func( 'tranzly_current_language' );
		$default = (string) call_user_func( 'tranzly_default_language' );
		if ( $current !== $default ) {
			$source = call_user_func( 'tranzly_get_translation', (int) $post->ID, $default );
			if ( null !== $source ) {
				$page['translationOfWork'] = array(
					'@type'      => 'WebPage',
					'url'        => (string) call_user_func( 'tranzly_language_url', $default ),
					'inLanguage' => str_replace( '_', '-', $default ),
				);
			}
			return $page;
		}
		foreach ( (array) call_user_func( 'tranzly_languages' ) as $lang ) {
			$code = (string) ( $lang['code'] ?? '' );
			if ( '' === $code || $code === $current || null === call_user_func( 'tranzly_get_translation', (int) $post->ID, $code ) ) {
				continue;
			}
			$page['workTranslation'][] = array(
				'@type'      => 'WebPage',
				'url'        => (string) call_user_func( 'tranzly_language_url', $code ),
				'inLanguage' => str_replace( '_', '-', $code ),
			);
		}

		return $page;
	}

	/**
	 * WooCommerce's product node for a product page (WooCommerce builds it; this graph prints it).
	 *
	 * @param string $url Page address.
	 * @return array<string, mixed>
	 */
	public static function woo_product( string $url ): array {
		if ( ! (bool) Settings::get( 'schema_absorb', true ) || ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$sd      = WC()->structured_data ?? null;
		$product = wc_get_product( (int) get_queried_object_id() );
		if ( ! is_object( $sd ) || ! method_exists( $sd, 'generate_product_data' ) || ! method_exists( $sd, 'get_data' ) || ! $product ) {
			return array();
		}
		$sd->generate_product_data( $product );
		foreach ( (array) $sd->get_data() as $node ) {
			if ( is_array( $node ) && 'Product' === ( $node['@type'] ?? '' ) ) {
				unset( $node['@context'] );
				$node['@id']              = $url . '#product';
				$node['mainEntityOfPage'] = array( '@id' => $url . '#webpage' );
				// WooCommerce before 9.3 gives an offer's price specification without saying
				// what it is (`@type`): a node with no type is ignored by search engines.
				$offers = $node['offers'] ?? null;
				if ( is_array( $offers ) ) {
					$list = isset( $offers['@type'] ) ? array( &$offers ) : array();
					if ( array() === $list ) {
						foreach ( $offers as &$one ) {
							$list[] = &$one;
						}
						unset( $one );
					}
					foreach ( $list as &$offer ) {
						if ( is_array( $offer ) && is_array( $offer['priceSpecification'] ?? null ) && isset( $offer['priceSpecification']['price'] ) && ! isset( $offer['priceSpecification']['@type'] ) ) {
							$offer['priceSpecification'] = array( '@type' => 'UnitPriceSpecification' ) + $offer['priceSpecification'];
						}
					}
					unset( $offer, $list );
					$node['offers'] = $offers;
				}
				return $node;
			}
		}

		return array();
	}

	/**
	 * While this graph is printed, WooCommerce's own second script is not (its product, its
	 * breadcrumbs and its site search are all in this graph).
	 *
	 * @return void
	 */
	public static function absorb_woo_output(): void {
		if ( ! (bool) Settings::get( 'schema_absorb', true ) || ! function_exists( 'WC' ) || ! \ZinnDigital\ZinnSEO\Blocks\Library::we_print_the_graph() ) {
			return;
		}
		$sd = WC()->structured_data ?? null;
		if ( is_object( $sd ) ) {
			remove_action( 'wp_footer', array( $sd, 'output_structured_data' ), 10 );
		}
	}

	/**
	 * What the Structured data screen needs to draw its choices.
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data  = (array) $data;
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$types[] = array(
				'name'     => $type->name,
				'label'    => Html::plain( $type->labels->name ?? $type->label ),
				'defaults' => self::type_defaults( $type->name ),
			);
		}
		$data['schema'] = array(
			'pageTypes'    => self::PAGE_TYPES,
			'articleTypes' => array_values( array_filter( self::ARTICLE_TYPES ) ),
			'contactTypes' => self::CONTACT_TYPES,
			'postTypes'    => $types,
			'feedUrl'      => home_url( '/' . Feed::PATH ),
			'vocabulary'   => Vocab::version(),
			'rulesChecked' => Rich_Rules::CHECKED,
		);

		return $data;
	}

	/**
	 * The editor panel's choices.
	 *
	 * @param array<string, mixed> $data Editor data.
	 * @return array<string, mixed>
	 */
	public static function editor_data( $data ): array {
		$data           = (array) $data;
		$data['schema'] = array(
			'pageTypes'    => self::PAGE_TYPES,
			'articleTypes' => array_values( array_filter( self::ARTICLE_TYPES ) ),
		);

		return $data;
	}

	/**
	 * Keep the logo's address and size in the settings (a visitor's page then reads no attachment).
	 *
	 * @param array<string, mixed> $after  Settings now.
	 * @param array<string, mixed> $before Settings before.
	 * @return void
	 */
	public static function refresh_logo( $after, $before = array() ): void {
		$after  = (array) $after;
		$before = (array) $before;
		$id     = (int) ( $after['identity_logo'] ?? 0 );
		if ( (int) ( $before['identity_logo'] ?? 0 ) === $id && ( 0 === $id || '' !== (string) ( $after['identity_logo_url'] ?? '' ) ) ) {
			return;
		}
		$img = $id > 0 ? wp_get_attachment_image_src( $id, 'full' ) : false;
		Settings::save(
			array(
				'identity_logo_url' => is_array( $img ) ? (string) $img[0] : '',
				'identity_logo_w'   => is_array( $img ) ? (int) $img[1] : 0,
				'identity_logo_h'   => is_array( $img ) ? (int) $img[2] : 0,
			),
			'system'
		);
	}
}
