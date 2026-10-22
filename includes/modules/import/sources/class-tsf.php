<?php
/**
 * The SEO Framework as an import source.
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
 * Where The SEO Framework keeps things (read from its own code, release 5.1.4): per post in
 * postmeta under the keys it inherited from Genesis (`_genesis_title`, `_genesis_noindex`, …)
 * plus `_open_graph_*`, `_twitter_*`, `_social_image_*` and `redirect`; per term in ONE termmeta
 * row, `autodescription-term-settings`; everything site-wide in the option
 * `autodescription-site-settings`.
 *
 * It has no templates. A title is "page title, separator, site name" (or the other way round),
 * archives carry WordPress's own prefix ("Category: News"), and the site name is added to a
 * title a person typed too — unless that page says not to. Those rules are written out here as
 * templates, so a page nobody touched keeps the title it had.
 */
final class Tsf extends Source {

	/** Its separators: option value => character. */
	private const SEPARATORS = array(
		'hyphen' => '-',
		'pipe'   => '|',
		'ndash'  => '–',
		'mdash'  => '—',
		'bull'   => '•',
		'middot' => '·',
		'lsaquo' => '‹',
		'rsaquo' => '›',
		'frasl'  => '⁄',
		'laquo'  => '«',
		'raquo'  => '»',
		'le'     => '≤',
		'ge'     => '≥',
		'lt'     => '<',
		'gt'     => '>',
	);

	/**
	 * Its settings, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $options = null;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'tsf';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'The SEO Framework';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'autodescription/autodescription.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return is_array( get_option( 'autodescription-site-settings', null ) );
	}

	/**
	 * Its settings.
	 *
	 * @return array<string, mixed>
	 */
	private function options(): array {
		if ( null === $this->options ) {
			$stored        = get_option( 'autodescription-site-settings', array() );
			$this->options = is_array( $stored ) ? $stored : array();
		}

		return $this->options;
	}

	/**
	 * One of its settings as a string.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function opt( string $key ): string {
		$value = $this->options()[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The separator as a template writes it.
	 *
	 * @return string
	 */
	private function sep(): string {
		$char = self::SEPARATORS[ $this->opt( 'title_separator' ) ] ?? '-';

		return '' !== self::separator( $char ) ? '%sep%' : $char;
	}

	/**
	 * A title with the site name where The SEO Framework puts it.
	 *
	 * @param string $title   The title part (text or variables).
	 * @param bool   $no_site This page asked for no site name.
	 * @return string
	 */
	public function with_site( string $title, bool $no_site = false ): string {
		if ( '' === $title || $no_site || '1' === $this->opt( 'title_rem_additions' ) ) {
			return $title;
		}

		// Page 2 and on: "Title | Page 2 | Site" (it puts the number after the title part).
		$title .= ' ' . $this->sep() . ' %page(n)%';

		return 'left' === $this->opt( 'title_location' ) ? '%sitename% ' . $this->sep() . ' ' . $title : $title . ' ' . $this->sep() . ' %sitename%';
	}

	/**
	 * Robots flags from its three-way values (1: set, -1: forced off, 0: as the type says).
	 *
	 * @param string $noindex   Its noindex value.
	 * @param string $nofollow  Its nofollow value.
	 * @param string $noarchive Its noarchive value.
	 * @return array<int, string>
	 */
	private static function three_way( string $noindex, string $nofollow, string $noarchive ): array {
		return self::robots(
			array(
				'noindex'   => (int) $noindex > 0,
				'index'     => (int) $noindex < 0,
				'nofollow'  => (int) $nofollow > 0,
				'follow'    => (int) $nofollow < 0,
				'noarchive' => (int) $noarchive > 0,
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
		$title = self::pm( $post_id, '_genesis_title' );
		$og    = self::pm( $post_id, '_open_graph_title' );
		if ( '' === $og && '1' === $this->opt( 'social_title_rem_additions' ) ) {
			// A shared title is the typed title without the site name.
			$og = $title;
		}
		$bare = '1' === self::pm( $post_id, '_tsf_title_no_blogname' );
		$out  = array(
			'title'     => $this->with_site( $title, $bare ),
			'desc'      => self::pm( $post_id, '_genesis_description' ),
			'canonical' => self::pm( $post_id, '_genesis_canonical_uri' ),
			'robots'    => self::three_way( self::pm( $post_id, '_genesis_noindex' ), self::pm( $post_id, '_genesis_nofollow' ), self::pm( $post_id, '_genesis_noarchive' ) ),
			'og_title'  => $og,
			'og_desc'   => self::pm( $post_id, '_open_graph_description' ),
			'og_image'  => self::image( self::pm( $post_id, '_social_image_id' ), self::pm( $post_id, '_social_image_url' ) ),
			'x_title'   => self::pm( $post_id, '_twitter_title' ),
			'x_desc'    => self::pm( $post_id, '_twitter_description' ),
			// Its per-post card size (empty: the site's).
			'x_card'    => in_array( self::pm( $post_id, '_tsf_twitter_card_type' ), array( 'summary', 'summary_large_image' ), true ) ? self::pm( $post_id, '_tsf_twitter_card_type' ) : '',
		);
		$to   = self::pm( $post_id, 'redirect' );
		if ( '' !== $to && 1 === preg_match( '#^https?://#i', $to ) ) {
			$out['redirect'] = array(
				'target' => $to,
				'code'   => 301,
			);
		}

		return self::filled( $out );
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		$row = get_term_meta( (int) $term->term_id, 'autodescription-term-settings', true );
		if ( ! is_array( $row ) ) {
			return array();
		}
		$s     = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? trim( (string) $row[ $key ] ) : '';
		$title = $s( 'doctitle' );
		$og    = $s( 'og_title' );
		$bare  = '1' === $s( 'title_no_blog_name' );
		if ( '' === $og && '1' === $this->opt( 'social_title_rem_additions' ) ) {
			$og = $title;
		}

		return self::filled(
			array(
				'title'     => $this->with_site( $title, $bare ),
				'desc'      => $s( 'description' ),
				'canonical' => $s( 'canonical' ),
				'robots'    => self::three_way( $s( 'noindex' ), $s( 'nofollow' ), $s( 'noarchive' ) ),
				'og_title'  => $og,
				'og_desc'   => $s( 'og_description' ),
				'og_image'  => self::image( $s( 'social_image_id' ), $s( 'social_image_url' ) ),
				'x_title'   => $s( 'tw_title' ),
				'x_desc'    => $s( 'tw_description' ),
			)
		);
	}

	/**
	 * A template record for a kind of page.
	 *
	 * @param string $title    The title part.
	 * @param string $desc     The description template.
	 * @param bool   $noindex  Not indexed.
	 * @param bool   $nofollow Not followed.
	 * @param bool   $archive  No cached copy.
	 * @return array<string, mixed>
	 */
	private function record( string $title, string $desc, bool $noindex, bool $nofollow = false, bool $archive = false ): array {
		return array(
			'title'    => $this->with_site( $title ),
			'desc'     => $desc,
			'index'    => $noindex ? 'noindex' : 'index',
			'follow'   => $nofollow ? 'nofollow' : 'follow',
			'extra'    => $archive ? array( 'noarchive' ) : array(),
			'og_title' => '1' === $this->opt( 'social_title_rem_additions' ) ? $title : '',
		);
	}

	/**
	 * A user (user meta `autodescription-user-settings`): the profiles The SEO Framework keeps
	 * (`facebook_page` an address, `twitter_page` a username, `fediverse_page` a handle since
	 * 5.2) and, since 5.2, the author page's own title, description, canonical, robots, sharing
	 * texts and image, and redirect — the same keys as a term's (queue Q324). A 5.1 record holds
	 * the two profiles only.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array {
		$meta = get_user_meta( (int) $user->ID, 'autodescription-user-settings', true );
		$meta = is_array( $meta ) ? $meta : array();
		$s    = static fn( string $key ): string => is_scalar( $meta[ $key ] ?? null ) ? trim( (string) $meta[ $key ] ) : '';
		$og   = $s( 'og_title' );
		if ( '' === $og && '1' === $this->opt( 'social_title_rem_additions' ) ) {
			$og = $s( 'doctitle' );
		}

		return self::filled(
			array(
				'x'         => self::x_handle( $s( 'twitter_page' ) ),
				'facebook'  => self::facebook_url( $s( 'facebook_page' ) ),
				'fediverse' => ltrim( $s( 'fediverse_page' ), '@' ),
				'title'     => $this->with_site( $s( 'doctitle' ), '1' === $s( 'title_no_blog_name' ) ),
				'desc'      => $s( 'description' ),
				'canonical' => $s( 'canonical' ),
				'robots'    => self::three_way( $s( 'noindex' ), $s( 'nofollow' ), $s( 'noarchive' ) ),
				'og_title'  => $og,
				'og_desc'   => $s( 'og_description' ),
				'og_image'  => self::image( $s( 'social_image_id' ), $s( 'social_image_url' ) ),
				'x_title'   => $s( 'tw_title' ),
				'x_desc'    => $s( 'tw_description' ),
				'redirect'  => $s( 'redirect' ),
			)
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o    = $this->options();
		$flag = static fn( string $key, string $name ): bool => ! empty( $o[ $key ][ $name ] );
		$on   = fn( string $key ): bool => '1' === $this->opt( $key );
		$out  = array();
		$char = self::SEPARATORS[ $this->opt( 'title_separator' ) ] ?? '-';
		if ( '' !== self::separator( $char ) ) {
			$out['sep'] = self::separator( $char );
		}
		// It writes a description from the page's text itself when none is typed (unless that
		// is switched off); the comparison keeps the exact words it showed for each page.
		$auto      = $on( 'auto_description' ) ? '%excerpt%' : '%none%';
		$site      = array( $on( 'site_noindex' ), $on( 'site_nofollow' ), $on( 'site_noarchive' ) );
		$templates = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$templates[ 'post:' . $type ] = $this->record( '%title%', $auto, $site[0] || $flag( 'noindex_post_types', $type ), $site[1] || $flag( 'nofollow_post_types', $type ), $site[2] || $flag( 'noarchive_post_types', $type ) );
			$object                       = get_post_type_object( $type );
			if ( $object && $object->has_archive ) {
				// WooCommerce's shop is a page to it: the page type's robots, not the products'
				// (importer certification, queue Q254: /shop/ gained the products' noarchive).
				$as                              = 'product' === $type && function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) > 0 ? 'page' : (string) $type;
				$templates[ 'archive:' . $type ] = $this->record( '%archive_prefix% %posttype_plural%', '%none%', $site[0] || $flag( 'noindex_post_types', $as ), $site[1] || $flag( 'nofollow_post_types', $as ), $site[2] || $flag( 'noarchive_post_types', $as ) );
			}
		}
		$prefix = $on( 'title_rem_prefixes' ) ? '' : '%archive_prefix% ';
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			$templates[ 'tax:' . $tax ] = $this->record( $prefix . '%term%', $on( 'auto_description' ) ? '%term_description%' : '%none%', $site[0] || $flag( 'noindex_taxonomies', $tax ), $site[1] || $flag( 'nofollow_taxonomies', $tax ), $site[2] || $flag( 'noarchive_taxonomies', $tax ) );
		}
		$templates['author'] = $this->record( $prefix . '%author%', $on( 'auto_description' ) ? '%excerpt%' : '%none%', $site[0] || $on( 'author_noindex' ), $site[1] || $on( 'author_nofollow' ), $site[2] || $on( 'author_noarchive' ) );
		$templates['date']   = $this->record( $prefix . '%date%', '%none%', $site[0] || $on( 'date_noindex' ), $site[1] || $on( 'date_nofollow' ), $site[2] || $on( 'date_noarchive' ) );
		// WordPress's own words for these two, in the site's language at the time of the import.
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own strings, which The SEO Framework prints.
		$search = html_entity_decode( sprintf( (string) __( 'Search Results for &#8220;%s&#8221;', 'default' ), '%search%' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lost   = (string) __( 'Page not found', 'default' );
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment
		$templates['search'] = array(
			'title'    => $this->with_site( $search ),
			'index'    => $site[0] || $on( 'search_noindex' ) ? 'noindex' : 'index',
			'og_title' => '1' === $this->opt( 'social_title_rem_additions' ) ? $search : '',
		);
		$templates['404']    = array( 'title' => $this->with_site( $lost ) );

		// The home page: its own title, the tagline beside it when asked, on the side chosen.
		$home_title = '' !== $this->opt( 'homepage_title' ) ? $this->opt( 'homepage_title' ) : '%sitename%';
		$tagline    = '' !== $this->opt( 'homepage_title_tagline' ) ? $this->opt( 'homepage_title_tagline' ) : '%tagline%';
		$full       = $home_title;
		if ( $on( 'homepage_tagline' ) ) {
			$full = 'left' === $this->opt( 'home_title_location' ) ? $tagline . ' ' . $this->sep() . ' ' . $home_title . ' ' . $this->sep() . ' %page(n)%' : $home_title . ' ' . $this->sep() . ' %page(n)% ' . $this->sep() . ' ' . $tagline;
		} else {
			$full .= ' ' . $this->sep() . ' %page(n)%';
		}
		$templates['home'] = array(
			'title'    => $full,
			'desc'     => '' !== $this->opt( 'homepage_description' ) ? $this->opt( 'homepage_description' ) : '%none%',
			'index'    => $site[0] || $on( 'homepage_noindex' ) ? 'noindex' : 'index',
			'follow'   => $site[1] || $on( 'homepage_nofollow' ) ? 'nofollow' : 'follow',
			'extra'    => $site[2] || $on( 'homepage_noarchive' ) ? array( 'noarchive' ) : array(),
			'og_title' => '' !== $this->opt( 'homepage_og_title' ) ? $this->opt( 'homepage_og_title' ) : $home_title,
			'og_desc'  => $this->opt( 'homepage_og_description' ),
			'og_image' => self::image( $o['homepage_social_image_id'] ?? 0, $this->opt( 'homepage_social_image_url' ) ),
		);
		$out['templates']  = $templates;
		// Page 2 and on of lists kept out of search; previous/next links (it prints them on
		// pages kept out of search too); the Facebook page as publisher (queue Q254).
		if ( $on( 'paged_noindex' ) || $on( 'home_paged_noindex' ) ) {
			$out['robots_noindex_archive_pages'] = $on( 'paged_noindex' );
			$out['robots_noindex_home_pages']    = $on( 'home_paged_noindex' ) ? 'yes' : 'no';
		}
		// It names the organisation as the site's other name (WebSite alternateName).
		if ( '' !== $this->opt( 'knowledge_name' ) && 'organization' === $this->opt( 'knowledge_type' ) ) {
			$out['website_alt_name'] = $this->opt( 'knowledge_name' );
		}
		$out['adjacent_links']   = $on( 'prev_next_archives' ) || $on( 'prev_next_posts' );
		$out['adjacent_noindex'] = true;
		$out['crumb_compat']     = array( 'tsf' );
		// Its trail names a date archive by the date alone ("2026"), an author by name.
		$out['crumb_archive'] = '%s';
		// Its trail names search results with WordPress's own words.
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own string, which The SEO Framework prints.
		$out['crumb_search'] = html_entity_decode( (string) __( 'Search Results for &#8220;%s&#8221;', 'default' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' !== $this->opt( 'facebook_publisher' ) ) {
			$out['social_extras'] = array( 'publisher' );
		}

		if ( $on( 'set_copyright_directives' ) ) {
			$out['robots_max_snippet'] = $this->opt( 'max_snippet_length' );
			$out['robots_max_video']   = $this->opt( 'max_video_preview' );
			if ( in_array( $this->opt( 'max_image_preview' ), array( 'none', 'standard', 'large' ), true ) ) {
				$out['robots_max_image'] = $this->opt( 'max_image_preview' );
			}
		}
		$handle = ltrim( $this->opt( 'twitter_site' ), '@' );
		$out   += array(
			'og'               => $on( 'og_tags' ),
			'x_cards'          => $on( 'twitter_tags' ),
			'x_card'           => 'summary' === $this->opt( 'twitter_card' ) ? 'summary' : 'summary_large_image',
			'x_site'           => $handle,
			'fb_app_id'        => $this->opt( 'facebook_appid' ),
			'og_default_image' => self::image( $o['social_image_fb_id'] ?? 0, $this->opt( 'social_image_fb_url' ) ),
			// It shows the featured image and never looks inside the text.
			'og_content_image' => false,
			'identity_type'    => 'person' === $this->opt( 'knowledge_type' ) ? 'person' : 'organization',
			'identity_name'    => $this->opt( 'knowledge_name' ),
			'identity_logo'    => self::image( $o['knowledge_logo_id'] ?? 0, $this->opt( 'knowledge_logo_url' ) ),
			'verify_google'    => $this->opt( 'google_verification' ),
			'verify_bing'      => $this->opt( 'bing_verification' ),
			'verify_yandex'    => $this->opt( 'yandex_verification' ),
			'verify_baidu'     => $this->opt( 'baidu_verification' ),
			'verify_pinterest' => $this->opt( 'pint_verification' ),
		);
		$same   = array();
		foreach ( array( 'facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'soundcloud', 'tumblr' ) as $network ) {
			$same[] = $this->opt( 'knowledge_' . $network );
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );

		return $out;
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'The SEO Framework writes each description from the page\'s text as the page is shown. "Keep exactly what it showed" stores those words for every page; without it Zinn® SEO writes its own from the same text.', 'zinn-seo' ),
		);
	}
}
