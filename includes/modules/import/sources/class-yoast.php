<?php
/**
 * Yoast SEO (and Premium) as an import source.
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
 * Where Yoast keeps things (read from its own code, release 28.6): per post in postmeta
 * `_yoast_wpseo_*`; per term in ONE option, `wpseo_taxonomy_meta[taxonomy][term_id]`; templates
 * in `wpseo_titles`; social settings in `wpseo_social`; verification codes in `wpseo`. Its
 * "indexables" table is a cache of those and is not read.
 */
final class Yoast extends Source {

	/** Its separators: option value => character. */
	private const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/**
	 * The term option, read once.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private ?array $terms = null;

	/**
	 * The separator as a template writes it: our variable, or the character itself.
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
		return 'yoast';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Yoast SEO';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return is_array( get_option( 'wpseo_titles', null ) );
	}

	/**
	 * Redirects (Premium) through the redirects module.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'yoast';
	}

	/**
	 * One of Yoast's templates in our variables.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		if ( null === $this->sep ) {
			$titles    = (array) get_option( 'wpseo_titles', array() );
			$char      = self::SEPARATORS[ (string) ( $titles['separator'] ?? 'sc-dash' ) ] ?? '-';
			$this->sep = '' !== self::separator( $char ) ? '%sep%' : $char;
		}

		return $this->convert(
			$text,
			'/%%([a-z0-9_-]+)%%/i',
			array(
				'title'                => '%title%',
				'archive_title'        => '%title%',
				'sitename'             => '%sitename%',
				'sitedesc'             => '%tagline%',
				'sep'                  => $this->sep,
				// Yoast's %%page%% carries its own separator: "» Page 2 of 3" (ours is beside it, and
				// a separator beside an empty variable disappears on page 1).
				'page'                 => $this->sep . ' %page%',
				'pagenumber'           => '%pagenumber%',
				'pagetotal'            => '%pagetotal%',
				'excerpt'              => '%excerpt%',
				'excerpt_only'         => '%excerpt%',
				'category'             => '%category%',
				'primary_category'     => '%category%',
				'tag'                  => '%ct_post_tag%',
				'term_title'           => '%term%',
				'term_hierarchy'       => '%term%',
				'term_description'     => '%term_description%',
				'category_description' => '%term_description%',
				'tag_description'      => '%term_description%',
				'date'                 => '%date%',
				'modified'             => '%modified%',
				'post_year'            => '%year%',
				'name'                 => '%author%',
				'user_description'     => '%excerpt%',
				'searchphrase'         => '%search%',
				'currentyear'          => '%currentyear%',
				'currentmonth'         => '%currentmonth%',
				'pt_single'            => '%posttype%',
				'pt_plural'            => '%posttype_plural%',
				'parent_title'         => '%parent_title%',
				'id'                   => '%id%',
			),
			array(
				'cf_' => static fn( string $rest ): string => self::named( 'cf', $rest ),
				'ct_' => static fn( string $rest ): string => self::named( 'ct', preg_replace( '/^desc_/', '', $rest ) ),
			)
		);
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$p       = '_yoast_wpseo_';
		$noindex = self::pm( $post_id, $p . 'meta-robots-noindex' );
		$adv     = array_map( 'trim', explode( ',', strtolower( self::pm( $post_id, $p . 'meta-robots-adv' ) ) ) );
		$focus   = self::pm( $post_id, $p . 'focuskw' );
		$page    = self::pm( $post_id, $p . 'schema_page_type' );
		$article = self::pm( $post_id, $p . 'schema_article_type' );

		return self::filled(
			array(
				'title'          => $this->template( self::pm( $post_id, $p . 'title' ) ),
				'desc'           => $this->template( self::pm( $post_id, $p . 'metadesc' ) ),
				'canonical'      => self::pm( $post_id, $p . 'canonical' ),
				'robots'         => self::robots(
					array(
						'noindex'      => '1' === $noindex,
						'index'        => '2' === $noindex,
						'nofollow'     => '1' === self::pm( $post_id, $p . 'meta-robots-nofollow' ),
						'noarchive'    => in_array( 'noarchive', $adv, true ),
						'nosnippet'    => in_array( 'nosnippet', $adv, true ),
						'noimageindex' => in_array( 'noimageindex', $adv, true ),
					)
				),
				'focus'          => self::focus( $focus, self::pm( $post_id, $p . 'focuskeywords' ), self::pm( $post_id, $p . 'keywordsynonyms' ) ),
				'cornerstone'    => '1' === self::pm( $post_id, $p . 'is_cornerstone' ),
				'og_title'       => $this->template( self::pm( $post_id, $p . 'opengraph-title' ) ),
				'og_desc'        => $this->template( self::pm( $post_id, $p . 'opengraph-description' ) ),
				'og_image'       => self::image( self::pm( $post_id, $p . 'opengraph-image-id' ), self::pm( $post_id, $p . 'opengraph-image' ) ),
				'x_title'        => $this->template( self::pm( $post_id, $p . 'twitter-title' ) ),
				'x_desc'         => $this->template( self::pm( $post_id, $p . 'twitter-description' ) ),
				'x_image'        => self::image( self::pm( $post_id, $p . 'twitter-image-id' ), self::pm( $post_id, $p . 'twitter-image' ) ),
				'schema_page'    => $page,
				'schema_article' => 'None' === $article ? 'none' : $article,
				'primary_term'   => (int) self::pm( $post_id, $p . 'primary_category' ),
				'crumb'          => self::pm( $post_id, $p . 'bctitle' ),
			)
		);
	}

	/**
	 * Focus phrases in our shape: one per line, `phrase | synonym, synonym`.
	 *
	 * @param string $main     The focus keyphrase.
	 * @param string $more     Premium: JSON list of `{keyword}`.
	 * @param string $synonyms Premium: JSON list of comma-separated synonyms, the main phrase first.
	 * @return string
	 */
	public static function focus( string $main, string $more, string $synonyms ): string {
		$phrases = '' !== $main ? array( $main ) : array();
		foreach ( (array) json_decode( $more, true ) as $row ) {
			if ( is_array( $row ) && '' !== trim( (string) ( $row['keyword'] ?? '' ) ) ) {
				$phrases[] = trim( (string) $row['keyword'] );
			}
		}
		$syn   = array_values( array_map( 'strval', (array) json_decode( $synonyms, true ) ) );
		$lines = array();
		foreach ( array_values( array_unique( $phrases ) ) as $i => $phrase ) {
			$extra   = trim( (string) ( $syn[ $i ] ?? '' ) );
			$lines[] = '' !== $extra ? $phrase . ' | ' . $extra : $phrase;
		}

		return implode( "\n", $lines );
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		if ( null === $this->terms ) {
			$stored      = get_option( 'wpseo_taxonomy_meta', array() );
			$this->terms = is_array( $stored ) ? $stored : array();
		}
		$row = $this->terms[ $term->taxonomy ][ (int) $term->term_id ] ?? null;
		if ( ! is_array( $row ) ) {
			return array();
		}
		$s       = static fn( string $key ): string => is_scalar( $row[ 'wpseo_' . $key ] ?? null ) ? trim( (string) $row[ 'wpseo_' . $key ] ) : '';
		$noindex = $s( 'noindex' );

		return self::filled(
			array(
				'title'     => $this->template( $s( 'title' ) ),
				'desc'      => $this->template( $s( 'desc' ) ),
				'canonical' => $s( 'canonical' ),
				'robots'    => self::robots(
					array(
						'noindex' => 'noindex' === $noindex,
						'index'   => 'index' === $noindex,
					)
				),
				'og_title'  => $this->template( $s( 'opengraph-title' ) ),
				'og_desc'   => $this->template( $s( 'opengraph-description' ) ),
				'og_image'  => self::image( $s( 'opengraph-image-id' ), $s( 'opengraph-image' ) ),
				'x_title'   => $this->template( $s( 'twitter-title' ) ),
				'x_desc'    => $this->template( $s( 'twitter-description' ) ),
				'x_image'   => self::image( $s( 'twitter-image-id' ), $s( 'twitter-image' ) ),
				'crumb'     => $s( 'bctitle' ),
			)
		);
	}

	/**
	 * A user: the author's page (user meta `wpseo_title`, `wpseo_metadesc`, `wpseo_noindex_author`)
	 * and the profiles Yoast adds to the user record (contact methods: `twitter` is a username,
	 * the others are addresses).
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array {
		$id   = (int) $user->ID;
		$more = array();
		foreach ( array( 'instagram', 'linkedin', 'myspace', 'pinterest', 'soundcloud', 'tumblr', 'wikipedia', 'youtube' ) as $key ) {
			$more[] = self::um( $id, $key );
		}

		return self::filled(
			array(
				'title'     => $this->template( self::um( $id, 'wpseo_title' ) ),
				'desc'      => $this->template( self::um( $id, 'wpseo_metadesc' ) ),
				'robots'    => self::robots( array( 'noindex' => 'on' === self::um( $id, 'wpseo_noindex_author' ) ) ),
				'x'         => self::x_handle( self::um( $id, 'twitter' ) ),
				'facebook'  => self::facebook_url( self::um( $id, 'facebook' ) ),
				'same_as'   => self::profile_lines( $more ),
				'job_title' => self::um( $id, 'job_title' ),
			)
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$t      = (array) get_option( 'wpseo_titles', array() );
		$social = (array) get_option( 'wpseo_social', array() );
		$main   = (array) get_option( 'wpseo', array() );
		$s      = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$out    = array();
		$char   = self::SEPARATORS[ $s( $t, 'separator' ) ] ?? '-';
		if ( '' !== self::separator( $char ) ) {
			$out['sep'] = self::separator( $char );
		}

		// Yoast Premium alone reads the social templates; the free plugin shares the SEO title.
		$premium = file_exists( WP_PLUGIN_DIR . '/wordpress-seo-premium/wp-seo-premium.php' );
		$record  = function ( string $suffix, bool $noindex_known = true ) use ( $t, $s, $premium ): array {
			$rec = array(
				'title' => $this->template( $s( $t, 'title-' . $suffix ) ),
				// Yoast prints no description where its template is empty; ours would write one.
				'desc'  => '' !== $s( $t, 'metadesc-' . $suffix ) ? $this->template( $s( $t, 'metadesc-' . $suffix ) ) : '%none%',
			);
			if ( $noindex_known && array_key_exists( 'noindex-' . $suffix, $t ) ) {
				$rec['index'] = ! empty( $t[ 'noindex-' . $suffix ] ) ? 'noindex' : 'index';
			}
			if ( $premium ) {
				$rec['og_title'] = $this->template( $s( $t, 'social-title-' . $suffix ) );
				$rec['og_desc']  = $this->template( $s( $t, 'social-description-' . $suffix ) );
				$rec['og_image'] = self::image( $t[ 'social-image-id-' . $suffix ] ?? 0, $s( $t, 'social-image-url-' . $suffix ) );
			}
			return $rec;
		};

		$templates = array();
		$types     = array();
		$scores    = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			// Its "Show SEO settings" switch off for a type: it never scored that type (Q277).
			if ( array_key_exists( 'display-metabox-pt-' . $type, $t ) && empty( $t[ 'display-metabox-pt-' . $type ] ) ) {
				$scores[ 'post:' . $type ] = array( 'scores' => 'off' );
			}
			if ( ! array_key_exists( 'title-' . $type, $t ) ) {
				continue;
			}
			$templates[ 'post:' . $type ] = $record( $type );
			if ( array_key_exists( 'title-ptarchive-' . $type, $t ) ) {
				$templates[ 'archive:' . $type ] = $record( 'ptarchive-' . $type );
			}
			$page    = $s( $t, 'schema-page-type-' . $type );
			$article = $s( $t, 'schema-article-type-' . $type );
			if ( '' !== $page || '' !== $article ) {
				$types[ 'post:' . $type ] = array(
					'page'    => 'WebPage' === $page ? '' : $page,
					'article' => 'None' === $article ? 'none' : $article,
				);
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			if ( array_key_exists( 'title-tax-' . $tax, $t ) ) {
				$templates[ 'tax:' . $tax ] = $record( 'tax-' . $tax );
			}
		}
		// The home page: Yoast falls back to the tagline for the description, as we do.
		$templates['home']   = array(
			'title'    => $this->template( $s( $t, 'title-home-wpseo' ) ),
			'desc'     => $this->template( $s( $t, 'metadesc-home-wpseo' ) ),
			'og_title' => $this->template( $s( $t, 'open_graph_frontpage_title' ) ),
			'og_desc'  => $this->template( $s( $t, 'open_graph_frontpage_desc' ) ),
			'og_image' => self::image( $t['open_graph_frontpage_image_id'] ?? 0, $s( $t, 'open_graph_frontpage_image' ) ),
		);
		$templates['author'] = $record( 'author-wpseo' );
		$templates['date']   = $record( 'archive-wpseo' );
		$templates['search'] = array( 'title' => $this->template( $s( $t, 'title-search-wpseo' ) ) );
		$templates['404']    = array( 'title' => $this->template( $s( $t, 'title-404-wpseo' ) ) );
		$out['templates']    = $templates;
		if ( array() !== $types ) {
			$out['schema_types'] = $types;
		}
		if ( array() !== $scores ) {
			$out['content_types'] = $scores;
		}

		$person = 'person' === $s( $t, 'company_or_person' );
		$out   += array(
			'identity_type'        => $person ? 'person' : 'organization',
			'identity_name'        => $person ? $s( $t, 'person_name' ) : $s( $t, 'company_name' ),
			'identity_alt_name'    => $s( $t, 'company_alternate_name' ),
			'identity_logo'        => self::image( $t[ $person ? 'person_logo_id' : 'company_logo_id' ] ?? 0, $s( $t, $person ? 'person_logo' : 'company_logo' ) ),
			'identity_person'      => $person ? (int) ( $t['company_or_person_user_id'] ?? 0 ) : 0,
			'identity_description' => $s( $t, 'org-description' ),
			'identity_founded'     => $s( $t, 'org-founding-date' ),
			'og'                   => ! array_key_exists( 'opengraph', $social ) || ! empty( $social['opengraph'] ),
			'x_cards'              => ! array_key_exists( 'twitter', $social ) || ! empty( $social['twitter'] ),
			'x_card'               => 'summary' === $s( $social, 'twitter_card_type' ) ? 'summary' : 'summary_large_image',
			'x_site'               => ltrim( $s( $social, 'twitter_site' ), '@' ),
			'fb_app_id'            => $s( $social, 'fbadminapp' ),
			'og_default_image'     => self::image( $social['og_default_image_id'] ?? 0, $s( $social, 'og_default_image' ) ),
			'verify_google'        => $s( $main, 'googleverify' ),
			'verify_bing'          => $s( $main, 'msverify' ),
			'verify_yandex'        => $s( $main, 'yandexverify' ),
			'verify_baidu'         => $s( $main, 'baiduverify' ),
			'verify_pinterest'     => $s( $social, 'pinterestverify' ),
		);
		$same   = array();
		foreach ( array( 'facebook_site', 'instagram_url', 'linkedin_url', 'pinterest_url', 'youtube_url', 'wikipedia_url', 'mastodon_url', 'myspace_url' ) as $key ) {
			$same[] = $s( $social, $key );
		}
		foreach ( (array) ( $social['other_social_urls'] ?? array() ) as $url ) {
			$same[] = is_scalar( $url ) ? trim( (string) $url ) : '';
		}
		if ( '' !== $out['x_site'] ) {
			$same[] = 'https://x.com/' . $out['x_site'];
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );

		return $out + self::more_settings( $t, $main, (array) get_option( 'wpseo_llmstxt', array() ), $s( $t, 'metadesc-home-wpseo' ) );
	}

	/**
	 * What the full-settings certification found missing (queue Q254): the site's own name in
	 * search results, the sitemap's address, the category base, breadcrumbs and their wording,
	 * the extra sharing tags Yoast prints, llms.txt. Pure, for the tests.
	 *
	 * @param array<string, mixed> $t       `wpseo_titles`.
	 * @param array<string, mixed> $main    `wpseo`.
	 * @param array<string, mixed> $llms    `wpseo_llmstxt`.
	 * @param string               $home    The home page's description.
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $t, array $main, array $llms, string $home = '' ): array {
		$s   = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$on  = static fn( array $from, string $key ): bool => ! empty( $from[ $key ] ) && 'off' !== $from[ $key ];
		$out = array(
			// Its sitemap lives at /sitemap_index.xml: keep the address search engines know.
			'sitemap_index_name' => 'sitemap_index.xml',
			'website_name'       => $s( $t, 'website_name' ),
			'website_alt_name'   => $s( $t, 'alternate_website_name' ),
			// Yoast prints the Facebook page as publisher, the image's type and "Written by".
			'social_extras'      => array( 'publisher', 'image_type', 'written_by' ),
		);
		if ( $on( $t, 'stripcategorybase' ) ) {
			$out['url_category_base'] = true;
		}
		// Attachment pages sent to the file itself.
		if ( $on( $t, 'disable-attachment' ) ) {
			$out['url_attachment'] = 'file';
		}
		// Author and date archives it switched off: sent to the home page, as it did.
		$off = array_values( array_filter( array( $on( $t, 'disable-author' ) ? 'author' : '', $on( $t, 'disable-date' ) ? 'date' : '' ) ) );
		if ( array() !== $off ) {
			$out['archives_off'] = $off;
		}
		// Breadcrumbs: the wording, the archive names, which taxonomy a type's trail runs through.
		// A theme's yoast_breadcrumb() keeps printing the trail (Pro), when Yoast printed one.
		$out['crumb_compat'] = $on( $t, 'breadcrumbs-enable' ) ? array( 'yoast' ) : array();
		$out['crumb_home']   = $s( $t, 'breadcrumbs-home' );
		$out['crumb_sep']    = html_entity_decode( $s( $t, 'breadcrumbs-sep' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out['crumb_prefix'] = $s( $t, 'breadcrumbs-prefix' );
		if ( '' !== $s( $t, 'breadcrumbs-archiveprefix' ) ) {
			$out['crumb_archive'] = $s( $t, 'breadcrumbs-archiveprefix' ) . ' %s';
		}
		if ( '' !== $s( $t, 'breadcrumbs-searchprefix' ) ) {
			$out['crumb_search'] = $s( $t, 'breadcrumbs-searchprefix' ) . ' %s';
		}
		$out['crumb_404'] = $s( $t, 'breadcrumbs-404crumb' );
		// Yoast adds a "Page 2" step to page 2 and on of a list (its own words, translated).
		/* translators: %d: page number. */
		$out['crumb_paged'] = __( 'Page %d', 'zinn-seo' );
		$types              = array();
		foreach ( $t as $key => $value ) {
			if ( 1 === preg_match( '/^bctitle-ptarchive-([a-z0-9_-]+)$/', (string) $key, $m ) && '' !== $s( $t, (string) $key ) ) {
				$types[ $m[1] ]['label'] = $s( $t, (string) $key );
			}
			if ( 1 === preg_match( '/^post_types-([a-z0-9_-]+)-maintax$/', (string) $key, $m ) && in_array( $m[1], array( 'post', 'page', 'attachment' ), true ) === false ) {
				$tax                   = $s( $t, (string) $key );
				$types[ $m[1] ]['tax'] = '' === $tax || '0' === $tax ? 'none' : $tax;
			}
		}
		if ( array() !== $types ) {
			$out['crumb_types'] = $types;
		}
		// llms.txt (Yoast 25.3+): the pages it lists by hand, or by itself.
		if ( $on( $main, 'enable_llms_txt' ) ) {
			$out['llms_full'] = false;
			if ( '' !== $home ) {
				$out['llms_summary'] = $home;
			}
			$taxes = array();
			foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
				if ( 'post_format' !== $tax && empty( $t[ 'noindex-tax-' . $tax ] ) ) {
					$taxes[] = (string) $tax;
				}
			}
			$out['llms_taxonomies'] = $taxes;
			if ( 'manual' === $s( $llms, 'llms_txt_selection_mode' ) ) {
				$ids = array();
				foreach ( array( 'about_us_page', 'contact_page', 'terms_page', 'privacy_policy_page', 'shop_page' ) as $key ) {
					$ids[] = (int) ( $llms[ $key ] ?? 0 );
				}
				foreach ( (array) ( $llms['other_included_pages'] ?? array() ) as $id ) {
					$ids[] = (int) $id;
				}
				$out['llms_pages'] = implode( ',', array_values( array_unique( array_filter( $ids ) ) ) );
				// Only the pages chosen: no list of the newest of each type.
				$out['llms_types'] = array( 'none' );
			}
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
			__( 'Readability and SEO scores are worked out again by Zinn® SEO rather than copied.', 'zinn-seo' ),
		);
	}
}
