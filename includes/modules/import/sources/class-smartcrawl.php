<?php
/**
 * SmartCrawl as an import source.
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
 * Where SmartCrawl keeps things (read from its own code, release 3.16.4): per post in postmeta
 * `_wds_*` (the social tags as arrays in `_wds_opengraph` and `_wds_twitter`); per term in ONE
 * option, `wds_taxonomy_meta[taxonomy][term_id]`; templates in `wds_onpage_options`; social and
 * site details in `wds_social_options`; verification tags (whole tags, not codes) in
 * `wds_sitemap_options`.
 */
final class Smartcrawl extends Source {

	/** Its separators: preset => character. */
	private const SEPARATORS = array(
		'dot'           => '·',
		'dot-l'         => '•',
		'dash'          => '-',
		'dash-l'        => '—',
		'pipe'          => '|',
		'forward-slash' => '/',
		'back-slash'    => '\\',
		'tilde'         => '~',
		'greater-than'  => '>',
		'less-than'     => '<',
		'caret-right'   => '›',
		'caret-left'    => '‹',
		'arrow-right'   => '→',
		'arrow-left'    => '←',
	);

	/**
	 * Its on-page option, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $onpage = null;

	/**
	 * Its term option, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $terms = null;

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
		return 'smartcrawl';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'SmartCrawl';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'smartcrawl-seo/wpmu-dev-seo.php', 'wpmu-dev-seo/wpmu-dev-seo.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return is_array( get_option( 'wds_onpage_options', null ) );
	}

	/**
	 * Its URL Redirection tool's rules, through the redirects module.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'smartcrawl';
	}

	/**
	 * Its on-page option.
	 *
	 * @return array<string, mixed>
	 */
	private function onpage(): array {
		if ( null === $this->onpage ) {
			$stored       = get_option( 'wds_onpage_options', array() );
			$this->onpage = is_array( $stored ) ? $stored : array();
		}

		return $this->onpage;
	}

	/**
	 * Its separator character.
	 *
	 * @return string
	 */
	private function separator_char(): string {
		$o      = $this->onpage();
		$custom = is_scalar( $o['separator'] ?? null ) ? trim( (string) $o['separator'] ) : '';
		if ( '' !== $custom ) {
			return $custom;
		}

		return self::SEPARATORS[ (string) ( $o['preset-separator'] ?? 'pipe' ) ] ?? '|';
	}

	/**
	 * One of its templates in our variables.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		if ( null === $this->sep ) {
			$this->sep = '' !== self::separator( $this->separator_char() ) ? '%sep%' : $this->separator_char();
		}

		return $this->convert(
			$text,
			'/%%([a-z0-9_-]+)%%/i',
			array(
				'title'                => '%title%',
				'sitename'             => '%sitename%',
				'sitedesc'             => '%tagline%',
				'sep'                  => $this->sep,
				'page'                 => '%page%',
				'spell_page'           => '%page%',
				'pagenumber'           => '%pagenumber%',
				'spell_pagenumber'     => '%pagenumber%',
				'pagetotal'            => '%pagetotal%',
				'spell_pagetotal'      => '%pagetotal%',
				'excerpt'              => '%excerpt%',
				'excerpt_only'         => '%excerpt%',
				'caption'              => '%excerpt%',
				'category'             => '%term%',
				'tag'                  => '%term%',
				'term_title'           => '%term%',
				'term_description'     => '%term_description%',
				'category_description' => '%term_description%',
				'tag_description'      => '%term_description%',
				'date'                 => '%date%',
				'modified'             => '%modified%',
				'name'                 => '%author%',
				'user_description'     => '%excerpt%',
				'searchphrase'         => '%search%',
				'currentyear'          => '%currentyear%',
				'currentmonth'         => '%currentmonth%',
				'pt_single'            => '%posttype%',
				'pt_plural'            => '%posttype_plural%',
				'id'                   => '%id%',
			),
			array(
				'cf_' => static fn( string $rest ): string => self::named( 'cf', $rest ),
				'ct_' => static fn( string $rest ): string => self::named( 'ct', preg_replace( '/^desc_/', '', $rest ) ),
			)
		);
	}

	/**
	 * Title, text and image of one of its social arrays.
	 *
	 * @param mixed $row `_wds_opengraph` or `_wds_twitter`.
	 * @return array{title: string, desc: string, image: int}
	 */
	private function social( $row ): array {
		$row   = is_array( $row ) && empty( $row['disabled'] ) ? $row : array();
		$first = is_array( $row['images'] ?? null ) ? reset( $row['images'] ) : '';

		return array(
			'title' => $this->template( is_scalar( $row['title'] ?? null ) ? trim( (string) $row['title'] ) : '' ),
			'desc'  => $this->template( is_scalar( $row['description'] ?? null ) ? trim( (string) $row['description'] ) : '' ),
			'image' => is_scalar( $first ) ? self::image( $first, (string) $first ) : 0,
		);
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$adv = array_map( 'trim', explode( ',', strtolower( self::pm( $post_id, '_wds_meta-robots-adv' ) ) ) );
		$og  = $this->social( get_post_meta( $post_id, '_wds_opengraph', true ) );
		$x   = $this->social( get_post_meta( $post_id, '_wds_twitter', true ) );
		$out = array(
			'title'     => $this->template( self::pm( $post_id, '_wds_title' ) ),
			'desc'      => $this->template( self::pm( $post_id, '_wds_metadesc' ) ),
			'canonical' => self::pm( $post_id, '_wds_canonical' ),
			'robots'    => self::robots(
				array(
					'noindex'   => '1' === self::pm( $post_id, '_wds_meta-robots-noindex' ),
					'index'     => '1' === self::pm( $post_id, '_wds_meta-robots-index' ),
					'nofollow'  => '1' === self::pm( $post_id, '_wds_meta-robots-nofollow' ),
					'follow'    => '1' === self::pm( $post_id, '_wds_meta-robots-follow' ),
					'noarchive' => in_array( 'noarchive', $adv, true ),
					'nosnippet' => in_array( 'nosnippet', $adv, true ),
				)
			),
			'focus'     => implode( "\n", array_values( array_filter( array_map( 'trim', explode( ',', self::pm( $post_id, '_wds_focus-keywords' ) ) ) ) ) ),
			'og_title'  => $og['title'],
			'og_desc'   => $og['desc'],
			'og_image'  => $og['image'],
			'x_title'   => $x['title'],
			'x_desc'    => $x['desc'],
			'x_image'   => $x['image'],
		);

		// `_wds_redirect`: a redirect set on the page itself. Its redirects module acts on it
		// (301) while that module is switched on — measured on 3.16.4 by the importer
		// certification, queue Q254 (the earlier note here, "no longer acts on that row", was
		// measured with the module off).
		$to = self::pm( $post_id, '_wds_redirect' );
		if ( '' !== $to && self::redirects_on() ) {
			$out['redirect'] = array(
				'target' => $to,
				'code'   => 301,
			);
		}

		return self::filled( $out );
	}

	/**
	 * Is its redirects module switched on (Advanced Tools → URL Redirection)?
	 *
	 * @return bool
	 */
	private static function redirects_on(): bool {
		$advanced = get_option( 'wds-advanced', array() );

		return is_array( $advanced ) && ! empty( $advanced['active'] ) && ! empty( $advanced['redirects']['active'] );
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		if ( null === $this->terms ) {
			$stored      = get_option( 'wds_taxonomy_meta', array() );
			$this->terms = is_array( $stored ) ? $stored : array();
		}
		$row = $this->terms[ $term->taxonomy ][ (int) $term->term_id ] ?? null;
		if ( ! is_array( $row ) ) {
			return array();
		}
		$s  = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? trim( (string) $row[ $key ] ) : '';
		$og = $this->social( $row['opengraph'] ?? array() );
		$x  = $this->social( $row['twitter'] ?? array() );

		return self::filled(
			array(
				'title'     => $this->template( $s( 'wds_title' ) ),
				'desc'      => $this->template( $s( 'wds_desc' ) ),
				'canonical' => $s( 'wds_canonical' ),
				'robots'    => self::robots(
					array(
						'noindex'  => '' !== $s( 'wds_noindex' ) && '0' !== $s( 'wds_noindex' ),
						'index'    => '' !== $s( 'wds_override_noindex' ) && '0' !== $s( 'wds_override_noindex' ),
						'nofollow' => '' !== $s( 'wds_nofollow' ) && '0' !== $s( 'wds_nofollow' ),
						'follow'   => '' !== $s( 'wds_override_nofollow' ) && '0' !== $s( 'wds_override_nofollow' ),
					)
				),
				'og_title'  => $og['title'],
				'og_desc'   => $og['desc'],
				'og_image'  => $og['image'],
				'x_title'   => $x['title'],
				'x_desc'    => $x['desc'],
				'x_image'   => $x['image'],
			)
		);
	}

	/**
	 * A template record for one of its kinds (`post`, `category`, `author`, …).
	 *
	 * @param string $kind    Its suffix.
	 * @param bool   $noindex Forced out of the index (an archive it switched off).
	 * @return array<string, mixed>
	 */
	private function record( string $kind, bool $noindex = false ): array {
		$o = $this->onpage();
		$s = static fn( string $key ): string => is_scalar( $o[ $key ] ?? null ) ? trim( (string) $o[ $key ] ) : '';

		return array(
			'title'  => $this->template( $s( 'title-' . $kind ) ),
			'desc'   => '' !== $s( 'metadesc-' . $kind ) ? $this->template( $s( 'metadesc-' . $kind ) ) : '%none%',
			'index'  => $noindex || ! empty( $o[ 'meta_robots-noindex-' . $kind ] ) ? 'noindex' : 'index',
			'follow' => ! empty( $o[ 'meta_robots-nofollow-' . $kind ] ) ? 'nofollow' : 'follow',
		);
	}

	/**
	 * A user: the author's page (user meta `wds_title`, `wds_metadesc`).
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array {
		return self::filled(
			array(
				'title' => $this->template( self::um( (int) $user->ID, 'wds_title' ) ),
				'desc'  => $this->template( self::um( (int) $user->ID, 'wds_metadesc' ) ),
			)
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o      = $this->onpage();
		$social = (array) get_option( 'wds_social_options', array() );
		$map    = (array) get_option( 'wds_sitemap_options', array() );
		$s      = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$out    = array();
		if ( '' !== self::separator( $this->separator_char() ) ) {
			$out['sep'] = self::separator( $this->separator_char() );
		}
		$templates = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( array_key_exists( 'title-' . $type, $o ) ) {
				$templates[ 'post:' . $type ] = $this->record( (string) $type );
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			if ( array_key_exists( 'title-' . $tax, $o ) ) {
				$templates[ 'tax:' . $tax ] = $this->record( (string) $tax );
			}
		}
		$templates['home'] = array(
			'title' => $this->template( $s( $o, 'title-home' ) ),
			'desc'  => '' !== $s( $o, 'metadesc-home' ) ? $this->template( $s( $o, 'metadesc-home' ) ) : '%none%',
		);
		// An archive it switched off (it is redirected or marked noindex) stays out of search here.
		$templates['author'] = $this->record( 'author', empty( $o['enable-author-archive'] ) );
		$templates['date']   = $this->record( 'date', empty( $o['enable-date-archive'] ) );
		$templates['search'] = array(
			'title' => $this->template( $s( $o, 'title-search' ) ),
			'index' => ! empty( $o['meta_robots-noindex-search'] ) ? 'noindex' : 'index',
		);
		// It prints no robots tag on the not-found page (the 404 status already says it all).
		$templates['404'] = array(
			'title' => $this->template( $s( $o, 'title-404' ) ),
			'index' => 'index',
		);
		$out['templates'] = $templates;
		// Its posts are articles; nothing else is, until someone adds a type.
		$out['schema_types'] = array( 'post:post' => array( 'article' => 'Article' ) );

		$person = 'Person' === $s( $social, 'schema_type' );
		$handle = ltrim( $s( $social, 'twitter_username' ), '@' );
		$out   += array(
			// Its switch is two: the site-wide one, and one per kind of page (posts decide here).
			'og'               => ! empty( $social['og-enable'] ) && ! empty( $o['og-active-post'] ),
			'x_cards'          => ! empty( $social['twitter-card-enable'] ) && ! empty( $o['twitter-active-post'] ),
			'x_card'           => 'summary' === $s( $social, 'twitter-card-type' ) ? 'summary' : 'summary_large_image',
			'x_site'           => $handle,
			'fb_app_id'        => $s( $social, 'fb-app-id' ),
			'identity_type'    => $person ? 'person' : 'organization',
			'identity_name'    => $person ? $s( $social, 'override_name' ) : $s( $social, 'organization_name' ),
			'identity_logo'    => self::image( 0, $s( $social, 'organization_logo' ) ),
			'verify_google'    => \ZinnDigital\ZinnSEO\Head\Head::verification_code( $s( $map, 'verification-google-meta' ) ),
			'verify_bing'      => \ZinnDigital\ZinnSEO\Head\Head::verification_code( $s( $map, 'verification-bing-meta' ) ),
			'verify_pinterest' => \ZinnDigital\ZinnSEO\Head\Head::verification_code( $s( $social, 'pinterest-verify' ) ),
		);
		$same   = array();
		foreach ( array( 'facebook_url', 'instagram_url', 'linkedin_url', 'pinterest_url', 'youtube_url' ) as $key ) {
			$same[] = $s( $social, $key );
		}
		if ( '' !== $handle ) {
			$same[] = 'https://x.com/' . $handle;
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );

		return $out + self::more_settings( $social, (array) get_option( 'wds_schema_options', array() ), $map, (array) get_option( 'wds-advanced', array() ), array_map( 'strval', array_values( get_post_types( array( 'public' => true ) ) ) ), array_map( 'strval', array_values( get_taxonomies( array( 'public' => true ) ) ) ) );
	}

	/**
	 * What the full-settings certification found missing (queue Q254): its breadcrumbs and their
	 * wording, the robots preview limits it prints (only the image one), previous/next links (it
	 * prints them on pages kept out of search too), the site's own name and description, the
	 * sitemap's choices. Pure, for the tests.
	 *
	 * @param array<string, mixed> $social     `wds_social_options`.
	 * @param array<string, mixed> $schema     `wds_schema_options`.
	 * @param array<string, mixed> $map        `wds_sitemap_options`.
	 * @param array<string, mixed> $advanced   `wds-advanced`.
	 * @param array<int, string>   $post_types Public content types.
	 * @param array<int, string>   $taxonomies Public taxonomies.
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $social, array $schema, array $map, array $advanced, array $post_types, array $taxonomies ): array {
		$s   = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$out = array(
			// It prints max-image-preview only (no snippet or video limit).
			'robots_max_snippet' => 'off',
			'robots_max_video'   => 'off',
			'adjacent_noindex'   => true,
			// With no X text of its own, X shows the page's title and description, never the
			// Open Graph ones.
			'x_page_text'        => true,
		);
		if ( '' !== $s( $schema, 'organization_description' ) ) {
			$out['identity_description'] = $s( $schema, 'organization_description' );
		}
		// Its kind of organisation, its telephone as a contact point of the kind chosen.
		if ( 1 === preg_match( '/^[A-Z][A-Za-z]{2,59}$/', $s( $schema, 'organization_type' ) ) ) {
			$out['identity_org_type'] = $s( $schema, 'organization_type' );
		}
		if ( '' !== $s( $schema, 'organization_phone_number' ) ) {
			$kind                     = sanitize_title( $s( $schema, 'organization_contact_type' ) );
			$kind                     = isset( \ZinnDigital\ZinnSEO\Schema\Schema::CONTACT_TYPES[ $kind ] ) ? $kind : 'customer-support';
			$out['identity_contacts'] = array( $kind => array( 'telephone' => $s( $schema, 'organization_phone_number' ) ) );
		}
		$crumbs = is_array( $advanced['breadcrumbs'] ?? null ) ? $advanced['breadcrumbs'] : array();
		if ( ! empty( $advanced['active'] ) && ! empty( $crumbs['active'] ) ) {
			$out['crumb_compat'] = array( 'smartcrawl' );
			$sep                 = self::SEPARATORS[ $s( $crumbs, 'separator' ) ] ?? '';
			$sep                 = '' !== $s( $crumbs, 'custom_sep' ) ? $s( $crumbs, 'custom_sep' ) : $sep;
			if ( '' !== $sep ) {
				$out['crumb_sep'] = $sep;
			}
			if ( ! empty( $crumbs['home_trail'] ) && '' !== $s( $crumbs, 'home_label' ) ) {
				$out['crumb_home'] = $s( $crumbs, 'home_label' );
			}
			if ( ! empty( $crumbs['add_prefix'] ) ) {
				$out['crumb_prefix'] = $s( $crumbs, 'prefix' );
			}
			$labels = is_array( $crumbs['labels'] ?? null ) ? $crumbs['labels'] : array();
			foreach ( array(
				'search'  => array( 'crumb_search', '%%searchphrase%%' ),
				'archive' => array( 'crumb_archive', '%%original-title%%' ),
			) as $kind => $pair ) {
				$label = $s( $labels, $kind );
				if ( '' !== $label && false !== strpos( $label, $pair[1] ) ) {
					$out[ $pair[0] ] = str_replace( $pair[1], '%s', $label );
					// Its archive wording names term and content type archives too.
					if ( 'archive' === $kind ) {
						$out['crumb_term']      = $out[ $pair[0] ];
						$out['crumb_ptarchive'] = $out[ $pair[0] ];
					}
				}
			}
			if ( '' !== $s( $labels, '404' ) ) {
				$out['crumb_404'] = $s( $labels, '404' );
			}
			// Its trail goes straight from home to an item's term (Start › Mystery › The River
			// Book: no content type archive), and names WooCommerce's terms plainly.
			$out['crumb_singular_archive'] = false;
			if ( isset( $out['crumb_term'] ) ) {
				$out['crumb_term_plain'] = array( 'product_cat', 'product_tag' );
			}
			// It adds a "Page 2" step to page 2 and on of a list.
			/* translators: %d: page number. */
			$out['crumb_paged'] = __( 'Page %d', 'zinn-seo' );
		}
		$out['sitemap_exclude_types']      = array_values( array_filter( $post_types, static fn( string $t ): bool => 'attachment' !== $t && ! empty( $map[ 'post_types-' . $t . '-not_in_sitemap' ] ) ) );
		$out['sitemap_exclude_taxonomies'] = array_values( array_filter( $taxonomies, static fn( string $t ): bool => 'post_format' !== $t && ! empty( $map[ 'taxonomies-' . $t . '-not_in_sitemap' ] ) ) );
		$out['sitemap_images']             = ! empty( $map['sitemap-images'] );
		// The posts it leaves out: its own option (wds-sitemap-ignore_post_ids), and the addresses
		// it leaves out (wds-sitemap-ignore_urls) where they are this site's posts.
		$ids = array_map( 'intval', (array) get_option( 'wds-sitemap-ignore_post_ids', array() ) );
		foreach ( (array) get_option( 'wds-sitemap-ignore_urls', array() ) as $url ) {
			$ids[] = is_string( $url ) ? \ZinnDigital\ZinnSEO\Url_Post::id( (string) $url ) : 0;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( array() !== $ids ) {
			$out['sitemap_exclude_ids'] = implode( ',', $ids );
		}

		return $out;
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'SEO and readability analysis results are worked out again by Zinn® SEO rather than copied.', 'zinn-seo' ),
		);
	}
}
