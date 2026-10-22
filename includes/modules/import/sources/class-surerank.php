<?php
/**
 * SureRank as an import source (zs-725).
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
 * Where SureRank keeps things (read from its own code, release 1.10.2): one meta row per field
 * and object, `surerank_settings_<field>` on posts, terms and users — `page_title`,
 * `page_description`, `canonical_url`, `post_no_index` / `post_no_follow` / `post_no_archive`
 * (`yes`), `focus_keyword`, `facebook_title` / `_description` / `_image_url`, `twitter_title` /
 * `_description` / `_image_url`; everything site-wide in the option `surerank_settings`, with
 * `%name%` variables. Its crawl and oEmbed switches (feeds removed, category base removed, link
 * previews with the SEO title and image, author removed) have the same switches here and come
 * across too.
 */
final class Surerank extends Source {

	/** Its meta prefix. */
	private const PREFIX = 'surerank_settings_';

	/** Its grouped per-object rows (release 1.x). */
	private const GROUPS = array( 'general', 'social', 'schemas' );

	/**
	 * Its option, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $option = null;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'surerank';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'SureRank';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'surerank/surerank.php', 'surerank-pro/surerank-pro.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return false !== get_option( 'surerank_settings', false );
	}

	/**
	 * Its option.
	 *
	 * @return array<string, mixed>
	 */
	private function option(): array {
		if ( null === $this->option ) {
			$stored       = get_option( 'surerank_settings', array() );
			$this->option = is_array( $stored ) ? $stored : array();
		}

		return $this->option;
	}

	/**
	 * A string from its option.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function opt( string $key ): string {
		$v = $this->option()[ $key ] ?? '';

		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/**
	 * One of its templates in our variables.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		return $this->convert(
			$text,
			'/%([a-z_]+)%/i',
			array(
				'title'              => '%title%',
				'site_name'          => '%sitename%',
				'org_name'           => '%sitename%',
				'tagline'            => '%tagline%',
				'separator'          => '%sep%',
				'sep'                => '%sep%',
				'excerpt'            => '%excerpt%',
				'content'            => '%excerpt%',
				'published'          => '%date%',
				'modified'           => '%modified%',
				'archive_title'      => '%posttype_plural%',
				'author_name'        => '%author%',
				'author_description' => '%excerpt%',
				'term_title'         => '%term%',
				'term_description'   => '%term_description%',
				'search_query'       => '%search%',
				'page'               => '%page%',
				'currentyear'        => '%currentyear%',
				'currentmonth'       => '%currentmonth%',
				'currentdate'        => '',
				'currentday'         => '',
				'currenttime'        => '',
				'permalink'          => '',
				'site_url'           => '',
				'org_url'            => '',
				'org_logo'           => '',
				'slug'               => '',
				'author_nicename'    => '%author%',
			)
		);
	}

	/**
	 * Our fields from a reader of its per-object rows.
	 *
	 * @param callable $get `function ( string $field ): string`.
	 * @return array<string, mixed>
	 */
	private function fields( callable $get ): array {
		$focus = $get( 'focus_keyword' );

		return self::filled(
			array(
				'title'     => $this->template( $get( 'page_title' ) ),
				'desc'      => $this->template( $get( 'page_description' ) ),
				'canonical' => $get( 'canonical_url' ),
				'robots'    => self::robots(
					array(
						'noindex'   => 'yes' === $get( 'post_no_index' ),
						'nofollow'  => 'yes' === $get( 'post_no_follow' ),
						'noarchive' => 'yes' === $get( 'post_no_archive' ),
					)
				),
				'og_title'  => $this->template( $get( 'facebook_title' ) ),
				'og_desc'   => $this->template( $get( 'facebook_description' ) ),
				'og_image'  => self::image_id( $get( 'facebook_image_url' ) ),
				'x_title'   => $this->template( $get( 'twitter_title' ) ),
				'x_desc'    => $this->template( $get( 'twitter_description' ) ),
				'x_image'   => self::image_id( $get( 'twitter_image_url' ) ),
				'focus'     => '' !== $focus ? implode( "\n", array_filter( array_map( 'trim', explode( ',', $focus ) ) ) ) : '',
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
		return $this->fields( self::reader( static fn( string $key ) => get_post_meta( $post_id, $key, true ) ) );
	}

	/**
	 * Its grouped rows (`surerank_settings_general` holds `page_title`, `page_description`,
	 * `canonical_url`, `focus_keyword`…; `_social` the sharing fields; the robots switches have a
	 * row each), read for one object; older releases' one-row-per-field form is read too.
	 *
	 * @param callable $raw Key => stored value, for one post, term or user.
	 * @return callable Field => its value as text.
	 */
	public static function reader( callable $raw ): callable {
		$groups = null;

		return static function ( string $field ) use ( $raw, &$groups ): string {
			$flat = $raw( self::PREFIX . $field );
			if ( is_scalar( $flat ) && '' !== trim( (string) $flat ) ) {
				return trim( (string) $flat );
			}
			if ( null === $groups ) {
				$groups = array();
				foreach ( self::GROUPS as $group ) {
					$rows = maybe_unserialize( $raw( self::PREFIX . $group ) );
					if ( is_array( $rows ) ) {
						$groups += $rows;
					}
				}
			}
			$value = $groups[ $field ] ?? '';

			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		$id = (int) $term->term_id;

		return $this->fields( self::reader( static fn( string $key ) => get_term_meta( $id, $key, true ) ) );
	}

	/**
	 * An author.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array {
		$id     = (int) $user->ID;
		$fields = $this->fields( self::reader( static fn( string $key ) => get_user_meta( $id, $key, true ) ) );
		unset( $fields['focus'] );

		return $fields;
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o         = $this->option();
		$sep       = $this->opt( 'separator' );
		$title     = '' !== $this->opt( 'page_title' ) ? $this->template( $this->opt( 'page_title' ) ) : '%title% %sep% %sitename%';
		$desc      = '' !== $this->opt( 'page_description' ) ? $this->template( $this->opt( 'page_description' ) ) : '%excerpt%';
		$noindex   = array_map( 'strval', (array) ( $o['no_index'] ?? array() ) );
		$templates = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$templates[ 'post:' . $type ] = array(
				'title' => $title,
				'desc'  => $desc,
				'index' => in_array( (string) $type, $noindex, true ) ? 'noindex' : 'index',
			);
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			$templates[ 'tax:' . $tax ] = array(
				'title' => '%term% %sep% %sitename%',
				'desc'  => '%term_description%',
				'index' => in_array( (string) $tax, $noindex, true ) ? 'noindex' : 'index',
			);
		}
		$templates['home'] = array(
			'title' => '' !== $this->opt( 'home_page_title' ) ? $this->template( $this->opt( 'home_page_title' ) ) : '%sitename% %sep% %tagline%',
			'desc'  => $this->template( $this->opt( 'home_page_description' ) ),
		);
		if ( empty( $o['author_archive'] ) && isset( $o['author_archive'] ) ) {
			$templates['author'] = array( 'index' => 'noindex' );
		}
		if ( empty( $o['date_archive'] ) && isset( $o['date_archive'] ) ) {
			$templates['date'] = array( 'index' => 'noindex' );
		}
		$out = array(
			'sep'       => '' !== $sep ? $sep : '-',
			'templates' => $templates,
		);
		// The archives it switched off, it sent to the home page: so do we.
		$off = array();
		if ( empty( $o['author_archive'] ) && isset( $o['author_archive'] ) ) {
			$off[] = 'author';
		}
		if ( empty( $o['date_archive'] ) && isset( $o['date_archive'] ) ) {
			$off[] = 'date';
		}
		if ( array() !== $off ) {
			$out['archives_off'] = $off;
		}
		// Its crawl clean-up and link-preview switches (zs-701, zs-714, zs-727).
		$map = array(
			'remove_global_comments_feed'  => 'clean_feed_comments',
			'remove_post_authors_feed'     => 'clean_feed_author',
			'remove_post_types_feed'       => 'clean_feed_types',
			'remove_category_feed'         => 'clean_feed_terms',
			'remove_search_results_feed'   => 'clean_feed_search',
			'remove_category_base'         => 'url_category_base',
			'remove_product_category_base' => 'url_product_base',
			'oembeded_og_title'            => 'oembed_title',
			'oembeded_social_images'       => 'oembed_image',
		);
		foreach ( $map as $theirs => $ours ) {
			if ( array_key_exists( $theirs, $o ) ) {
				$out[ $ours ] = ! empty( $o[ $theirs ] );
			}
		}
		if ( ! empty( $o['oembeded_remove_author_name'] ) ) {
			$out['oembed_author'] = 'hide';
		}
		$handle = ltrim( $this->opt( 'twitter_profile_username' ), '@' );
		if ( '' !== $handle ) {
			$out['x_site'] = self::x_handle( $handle );
		}

		return self::more_settings( $out, $o );
	}

	/**
	 * What the full-settings certification found missing (queue Q254): " - Page 2 of 3" after
	 * the title of a list's page 2 and on, no robots preview limits (it prints none), the extra
	 * sharing tags it prints (the Facebook page as publisher on every page, a product's price and
	 * stock as tags and as X labels), its social profiles, page-2 robots. Pure, for the tests.
	 *
	 * @param array<string, mixed> $out Settings so far.
	 * @param array<string, mixed> $o   `surerank_settings`.
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $out, array $o ): array {
		foreach ( (array) ( $out['templates'] ?? array() ) as $key => $record ) {
			$title = is_array( $record ) ? (string) ( $record['title'] ?? '' ) : '';
			if ( '' !== $title && ( in_array( $key, array( 'home', 'author', 'date', 'search' ), true ) || 0 === strpos( (string) $key, 'tax:' ) || 0 === strpos( (string) $key, 'archive:' ) ) ) {
				$out['templates'][ $key ]['title'] = $title . ' %page(-)%';
			}
		}
		// Its search results title is fixed, not a setting: "Search Results for: fox - Site",
		// the page number before its fixed " - " and the site name.
		if ( '' === (string) ( $out['templates']['search']['title'] ?? '' ) ) {
			// Its English words (another plugin's text domain is not ours to call).
			$out['templates']['search']['title'] = 'Search Results for: %search% %page(-)% - %sitename%';
		}
		$out['robots_max_snippet'] = 'off';
		$out['robots_max_image']   = 'off';
		$out['robots_max_video']   = 'off';
		$out['social_extras']      = array( 'publisher_all', 'product', 'product_labels' );
		// Its "fallback image" is what a page with no picture of its own is shared with (the
		// search results page included): the site's sharing image here (IMP2).
		$fallback = self::image_id( is_scalar( $o['fallback_image'] ?? null ) ? (string) $o['fallback_image'] : '' );
		if ( $fallback > 0 ) {
			$out['og_default_image'] = $fallback;
		}
		// It prints previous/next links on pages kept out of search too.
		$out['adjacent_noindex'] = true;
		if ( ! empty( $o['noindex_paginated_pages'] ) ) {
			$out['robots_noindex_archive_pages'] = true;
		}
		$same = array();
		foreach ( (array) ( $o['social_profiles'] ?? array() ) as $url ) {
			$same[] = is_scalar( $url ) ? trim( (string) $url ) : '';
		}
		$same = array_values( array_unique( array_filter( $same ) ) );
		if ( array() !== $same ) {
			$out['identity_same_as'] = $same;
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
			__( 'SureRank\'s structured data rules and its AI content settings are not copied: Zinn® SEO builds structured data for every page from its own settings.', 'zinn-seo' ),
		);
	}
}
