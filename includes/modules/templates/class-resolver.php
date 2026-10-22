<?php
/**
 * The title, description, robots and canonical address of a page (zs-020, zs-021).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Templates;

use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Precedence, the same for every field: the page's own value (it may use variables too), then
 * its content type's template as the site changed it, then the built-in default.
 *
 * ⭐ Reads only what the request already has: the post or term (primed by the main query and the
 * Primer), the one autoloaded settings option, and the term cache the main query primed. A
 * variable is computed only when a template uses it, so `%excerpt%` costs nothing on a page whose
 * template does not ask for it.
 */
final class Resolver {

	/** Description length when one is made from text (Google shows about this much). */
	public const DESC_LENGTH = 155;

	/**
	 * Cache of resolved values per context and field.
	 *
	 * @var array<string, mixed>
	 */
	private static array $memo = array();

	/** Values kept at most: far more than a page view needs, flat for a whole-site job. */
	private const MEMO_MAX = 2000;

	/**
	 * Forget the cache (tests, and after a save in the same request).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * The separator.
	 *
	 * @return string
	 */
	public static function sep(): string {
		return (string) Settings::get( 'sep', '–' );
	}

	/**
	 * A per-object field (post, term, or the user of an author's page) of the context, or the
	 * empty string.
	 *
	 * @param Context $c    Context.
	 * @param string  $name Field.
	 * @return mixed
	 */
	public static function own( Context $c, string $name ) {
		if ( $c->post() ) {
			return Meta::get_post( (int) $c->post()->ID, $name );
		}
		if ( $c->term() ) {
			return Meta::get_term( (int) $c->term()->term_id, $name );
		}
		if ( 'author' === $c->kind && $c->object instanceof \WP_User && in_array( 'user', (array) ( Meta::fields()[ $name ]['objects'] ?? array() ), true ) ) {
			return Meta::get_user( (int) $c->object->ID, $name );
		}

		return '';
	}

	/**
	 * The SEO title.
	 *
	 * @param Context $c Context.
	 * @return string Plain text.
	 */
	public static function title( Context $c ): string {
		return self::memo(
			$c,
			'title',
			static function () use ( $c ): string {
				$lookup = self::lookup( $c );
				$own    = (string) self::own( $c, 'title' );
				if ( '' !== $own ) {
					$text = Vars::render( $own, $lookup, self::sep() );
					if ( '' !== $text ) {
						return $text;
					}
				}
				return self::auto_title( $c );
			}
		);
	}

	/**
	 * The title the template gives (what the page shows when its own field is empty).
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function auto_title( Context $c ): string {
		$lookup = self::lookup( $c );
		$text   = Vars::render( (string) Templates::effective( $c->key )['title'], $lookup, self::sep() );
		if ( '' === $text ) {
			$text = Vars::render( (string) Templates::defaults( $c->key )['title'], $lookup, self::sep() );
		}

		return '' !== $text ? $text : Vars::plain( (string) get_bloginfo( 'name' ) );
	}

	/**
	 * The meta description.
	 *
	 * @param Context $c Context.
	 * @return string Plain text, possibly empty.
	 */
	public static function description( Context $c ): string {
		return self::paged_description( self::base_description( $c ), $c );
	}

	/**
	 * The meta description without the page text of page 2 and on (`desc_paged`): what a
	 * shared link shows (the plugin a site moved from numbered its meta description only).
	 *
	 * @param Context $c Context.
	 * @return string Plain text, possibly empty.
	 */
	public static function base_description( Context $c ): string {
		return self::memo(
			$c,
			'desc',
			static function () use ( $c ): string {
				$lookup = self::lookup( $c );
				$own    = (string) self::own( $c, 'desc' );
				if ( Vars::NONE === trim( $own ) ) {
					return '';
				}
				if ( '' !== $own ) {
					$text = Vars::render( $own, $lookup, self::sep() );
					if ( '' !== $text ) {
						return $text;
					}
				}
				return self::auto_description( $c );
			}
		);
	}

	/**
	 * A description on page 2 and on, followed by the site's page text (`desc_paged`, e.g.
	 * `%page(-n)%`: "… - Page 2"), as the plugin a site moved from numbered it (IMP2). Empty
	 * setting, page 1 or no description: unchanged.
	 *
	 * @param string  $text The description.
	 * @param Context $c    The page.
	 * @return string
	 */
	public static function paged_description( string $text, Context $c ): string {
		$suffix = (string) Settings::get( 'desc_paged', '' );
		if ( '' === $text || $c->page < 2 || '' === trim( $suffix ) ) {
			return $text;
		}
		$more = Vars::render( $suffix, self::lookup( $c ), self::sep() );

		return '' === $more ? $text : $text . ' ' . $more;
	}

	/**
	 * The description the template gives.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function auto_description( Context $c ): string {
		if ( self::rank_math_rules() ) {
			// Rank Math's order: the page's own excerpt as written, else the template, cut at 160.
			$post = $c->post();
			if ( $post && 'singular' === $c->kind && '' !== trim( (string) $post->post_excerpt ) ) {
				return Vars::plain( (string) $post->post_excerpt );
			}
			$text = Vars::render( (string) Templates::effective( $c->key )['desc'], self::lookup( $c ), self::sep() );

			// It cut only a post's template text; an archive's, the home page's and an author's
			// were printed whole.
			return 'singular' === $c->kind ? Vars::trim_rank_math( $text, self::RANK_MATH_DESC_LENGTH ) : Vars::plain( $text );
		}
		$text = Vars::render( (string) Templates::effective( $c->key )['desc'], self::lookup( $c ), self::sep() );

		return Vars::trim_text( $text, self::DESC_LENGTH );
	}

	/** Rank Math's description length. */
	public const RANK_MATH_DESC_LENGTH = 160;

	/**
	 * Are descriptions shortened as Rank Math did (a site that moved from it)?
	 *
	 * @return bool
	 */
	public static function rank_math_rules(): bool {
		return 'rankmath' === (string) Settings::get( 'desc_rules', 'zinn' );
	}

	/**
	 * Shorten a variable's text: as Rank Math did (`%term_description%` cut at 160, `%excerpt%`
	 * left whole for the template's own cut), else ours.
	 *
	 * @param string $text     Text.
	 * @param bool   $is_whole Rank Math keeps it whole (the template is cut afterwards).
	 * @return string
	 */
	private static function trim_var( string $text, bool $is_whole = false ): string {
		if ( self::rank_math_rules() ) {
			return $is_whole ? Vars::plain( $text ) : Vars::trim_rank_math( $text, self::RANK_MATH_DESC_LENGTH );
		}

		return Vars::trim_text( $text, self::DESC_LENGTH );
	}

	/**
	 * Robots directives: directive => true, or => value for the `max-*` ones. `index` and
	 * `follow` are implied and never returned.
	 *
	 * @param Context $c Context.
	 * @return array<string, bool|string>
	 */
	public static function robots( Context $c ): array {
		return self::memo(
			$c,
			'robots',
			static function () use ( $c ): array {
				$tpl   = Templates::effective( $c->key );
				$own   = (array) self::own( $c, 'robots' );
				$out   = array();
				$index = in_array( 'noindex', $own, true ) ? 'noindex' : ( in_array( 'index', $own, true ) ? 'index' : (string) $tpl['index'] );
				if ( 'noindex' === $index ) {
					$out['noindex'] = true;
				}
				// Page 2 and on of an archive, or of a post split into pages, kept out of search
				// when the site asks (Rank Math's "noindex subpages", carried over by the importer).
				if ( $c->page > 1 && ! in_array( 'index', $own, true ) && self::paged_noindex( $c->kind ) ) {
					$out['noindex'] = true;
					// …and their links not followed either (All in One SEO's "nofollow paginated").
					if ( 'singular' !== $c->kind && (bool) Settings::get( 'robots_nofollow_archive_pages', false ) ) {
						$out['nofollow'] = true;
					}
				}
				// A product hidden from the shop's catalogue, kept out of search when the site asks
				// (Rank Math's "noindex hidden products").
				if ( (bool) Settings::get( 'robots_noindex_hidden_products', false ) && self::hidden_product( $c ) ) {
					$out['noindex']  = true;
					$out['nofollow'] = true;
				}
				$follow = in_array( 'nofollow', $own, true ) ? 'nofollow' : ( in_array( 'follow', $own, true ) ? 'follow' : (string) $tpl['follow'] );
				if ( 'nofollow' === $follow ) {
					$out['nofollow'] = true;
				}
				foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $flag ) {
					if ( in_array( $flag, $own, true ) || in_array( $flag, (array) $tpl['extra'], true ) ) {
						$out[ $flag ] = true;
					}
				}
				$limits = array(
					'max-snippet'       => array( 'max_snippet', 'robots_max_snippet' ),
					'max-image-preview' => array( 'max_image', 'robots_max_image' ),
					'max-video-preview' => array( 'max_video', 'robots_max_video' ),
				);
				foreach ( $limits as $directive => $names ) {
					$value = (string) self::own( $c, $names[0] );
					if ( '' === $value ) {
						$value = (string) Settings::get( $names[1], '' );
					}
					// `off`: none at all, WordPress's own max-image-preview:large included (a
					// plugin that removed it, SureRank — queue Q254). False drops it from the tag.
					if ( 'off' === $value ) {
						$out[ $directive ] = false;
					} elseif ( '' !== $value ) {
						$out[ $directive ] = $value;
					}
				}
				if ( isset( $out['nosnippet'] ) ) {
					unset( $out['max-snippet'] );
				}

				return $out;
			}
		);
	}

	/**
	 * Is this a WooCommerce product hidden from the catalogue?
	 *
	 * @param Context $c Context.
	 * @return bool
	 */
	private static function hidden_product( Context $c ): bool {
		$post = $c->post();
		if ( ! $post || 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $post->ID );

		return is_object( $product ) && 'hidden' === $product->get_catalog_visibility();
	}

	/**
	 * Are page 2 and on of this kind of page kept out of search?
	 *
	 * @param string $kind Context kind.
	 * @return bool
	 */
	private static function paged_noindex( string $kind ): bool {
		if ( 'singular' === $kind ) {
			return (bool) Settings::get( 'robots_noindex_post_pages', false );
		}

		// The home page's own pages may differ (The SEO Framework asks apart).
		$home = (string) Settings::get( 'robots_noindex_home_pages', '' );
		if ( 'home' === $kind && '' !== $home ) {
			return 'yes' === $home;
		}

		return in_array( $kind, array( 'home', 'tax', 'archive', 'author', 'date' ), true ) && (bool) Settings::get( 'robots_noindex_archive_pages', false );
	}

	/**
	 * The canonical address, or '' when the page should not name one (search results, not
	 * found, and a page asking not to be indexed).
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function canonical( Context $c ): string {
		return self::memo(
			$c,
			'canonical',
			static function () use ( $c ): string {
				if ( isset( self::robots( $c )['noindex'] ) ) {
					return '';
				}
				$own = (string) self::own( $c, 'canonical' );
				if ( '' !== $own ) {
					return $own;
				}

				return self::address( $c );
			}
		);
	}

	/**
	 * The page's own address (no override), with its page number.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function address( Context $c ): string {
		$post = $c->post();
		switch ( $c->kind ) {
			case 'home':
				return self::paged( self::home(), $c->page );
			case 'singular':
				if ( null === $post ) {
					return '';
				}
				if ( (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
					return self::paged( (string) get_permalink( $post ), $c->page );
				}
				if ( $c->front ) {
					return self::paged( self::home(), $c->page );
				}
				$url = function_exists( 'wp_get_canonical_url' ) && 'publish' === $post->post_status ? (string) wp_get_canonical_url( $post ) : (string) get_permalink( $post );
				// wp_get_canonical_url() adds the page of a split post only for the queried post.
				if ( $c->page > 1 && false === strpos( $url, 'page=' ) && ! preg_match( '#/' . $c->page . '/?$#', $url ) ) {
					$url = '' !== (string) get_option( 'permalink_structure' ) ? user_trailingslashit( trailingslashit( $url ) . $c->page, 'single_paged' ) : add_query_arg( 'page', $c->page, $url );
				}
				return $url;
			case 'tax':
				$link = $c->term() ? get_term_link( $c->term() ) : '';
				return is_string( $link ) ? self::paged( $link, $c->page ) : '';
			case 'archive':
				$link = $c->object instanceof \WP_Post_Type ? get_post_type_archive_link( $c->object->name ) : '';
				return is_string( $link ) ? self::paged( $link, $c->page ) : '';
			case 'author':
				return $c->object instanceof \WP_User ? self::paged( get_author_posts_url( (int) $c->object->ID ), $c->page ) : '';
			case 'search':
				// A results page is noindex unless the site chose otherwise (canonical() then
				// names nothing); a site that does index them names WordPress's own address.
				return '' !== $c->search ? self::paged( (string) get_search_link( $c->search ), $c->page ) : '';
			case 'date':
				list( $y, $m, $d ) = $c->ymd;
				if ( $d > 0 ) {
					$link = get_day_link( $y, $m, $d );
				} elseif ( $m > 0 ) {
					$link = get_month_link( $y, $m );
				} else {
					$link = get_year_link( $y );
				}
				return self::paged( (string) $link, $c->page );
		}

		return '';
	}

	/**
	 * The home page's address in the language being shown: WordPress's own home address, or a
	 * translation plugin's home for this language (Polylang filters `home_url` only for the
	 * theme's own calls, so a plugin asks it — found by the importer certification, queue Q254:
	 * the French home page named the English one as its canonical).
	 *
	 * @return string
	 */
	public static function home(): string {
		$home = home_url( '/' );
		/**
		 * Filters the address of the home page in the language being shown.
		 *
		 * @param string $home WordPress's home address.
		 */
		$lang = apply_filters( 'zinn_seo_home_url', $home );

		return is_string( $lang ) && '' !== $lang ? $lang : $home;
	}

	/**
	 * An archive address on its N-th page.
	 *
	 * @param string $url  First page.
	 * @param int    $page Page number.
	 * @return string
	 */
	public static function paged( string $url, int $page ): string {
		if ( $page < 2 || '' === $url ) {
			return $url;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'paged', $page, $url );
		}
		global $wp_rewrite;
		$base = $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite->pagination_base : 'page';

		return user_trailingslashit( trailingslashit( $url ) . $base . '/' . $page, 'paged' );
	}

	/**
	 * The value of each variable for a context (computed on first use).
	 *
	 * @param Context $c Context.
	 * @return callable `function ( string $name, string $arg = '' ): ?string`
	 */
	public static function lookup( Context $c ): callable {
		$cache = array();

		return static function ( string $name, string $arg = '' ) use ( $c, &$cache ): ?string {
			$key = '' === $arg ? $name : $name . '(' . $arg;
			if ( ! array_key_exists( $key, $cache ) ) {
				$cache[ $key ] = self::value( $c, $name, $arg );
			}

			return $cache[ $key ];
		};
	}

	/**
	 * One variable's value.
	 *
	 * @param Context $c    Context.
	 * @param string  $name Variable.
	 * @param string  $arg  Its argument: a date format for `date` and `modified`.
	 * @return string|null Null for an unknown variable.
	 */
	public static function value( Context $c, string $name, string $arg = '' ): ?string {
		$post = $c->post();
		$term = $c->term();
		$user = $c->object instanceof \WP_User ? $c->object : null;
		switch ( $name ) {
			case 'none':
				return '';
			case 'archive_prefix':
				return self::archive_prefix( $c );
			case 'sitename':
				return (string) get_bloginfo( 'name' );
			case 'tagline':
				return (string) get_bloginfo( 'description' );
			case 'title':
				if ( $post ) {
					return (string) get_the_title( $post );
				}
				if ( $term ) {
					return (string) $term->name;
				}
				if ( $c->object instanceof \WP_Post_Type ) {
					return (string) $c->object->labels->name;
				}
				if ( $user ) {
					return (string) $user->display_name;
				}
				if ( 'date' === $c->kind ) {
					return self::archive_date( $c );
				}
				if ( 'search' === $c->kind ) {
					return $c->search;
				}
				if ( '404' === $c->kind ) {
					return __( 'Page not found', 'zinn-seo' );
				}
				return (string) get_bloginfo( 'name' );
			case 'page':
				if ( $c->page < 2 ) {
					return '';
				}
				// `%page(n)%`: "Page 2" without the count (All in One SEO's form).
				if ( 'n' === strtolower( $arg ) ) {
					/* translators: %d: page number. */
					return sprintf( __( 'Page %d', 'zinn-seo' ), $c->page );
				}
				// `%page(-)%`: "- Page 2 of 3", the hyphen only when there is a page 2 (SureRank).
				if ( '-' === $arg ) {
					return '- ' . self::value( $c, 'page' );
				}
				// `%page(-n)%`: "- Page 2" (SEO Ultimate's "{title} - Page {num}").
				if ( '-n' === strtolower( $arg ) ) {
					return '- ' . self::value( $c, 'page', 'n' );
				}
				// `%page(sep)%`: "| Page 2 of 3", the site's separator printed as it stands
				// (Premium SEO Pack's page number carries its own separator).
				if ( 'sep' === strtolower( $arg ) ) {
					return self::sep() . ' ' . self::value( $c, 'page' );
				}
				/* translators: 1: current page, 2: number of pages. */
				return $c->pages >= $c->page ? sprintf( __( 'Page %1$d of %2$d', 'zinn-seo' ), $c->page, $c->pages ) : sprintf( __( 'Page %d', 'zinn-seo' ), $c->page ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- the comment above covers both.
			case 'sep':
				// `%sep(raw)%`: the separator printed as it stands, even with nothing before or
				// after it (`%sep%` is dropped there). Premium SEO Pack printed "| Site" for a
				// description whose excerpt was empty (IMP2).
				return 'raw' === strtolower( $arg ) ? self::sep() : null;
			case 'pagenumber':
				return (string) $c->page;
			case 'pagetotal':
				return (string) $c->pages;
			case 'excerpt':
				if ( $post ) {
					$text = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : self::text_of( $post );
					return self::trim_var( $text, true );
				}
				if ( $term ) {
					return self::trim_var( (string) $term->description );
				}
				if ( $user ) {
					return self::trim_var( (string) get_the_author_meta( 'description', (int) $user->ID ) );
				}
				return '';
			case 'date':
				if ( 'date' === $c->kind ) {
					// One format is for a month archive ("March 2026" as "March, 2026" or "3 - 2026").
					// Up to three, separated by "|", are for a month, a day and a year archive in
					// that order: `%date(F Y | j F Y | Y)%`. A kind with no format keeps its
					// natural form (the year; the day in the site's date format).
					if ( '' !== $arg && $c->ymd[0] > 0 ) {
						$formats = array_map( 'trim', explode( '|', $arg ) );
						$format  = (string) ( $formats[ $c->ymd[2] > 0 ? 1 : ( $c->ymd[1] > 0 ? 0 : 2 ) ] ?? '' );
						if ( '' !== $format ) {
							return (string) wp_date( $format, (int) gmmktime( 0, 0, 0, max( 1, $c->ymd[1] ), max( 1, $c->ymd[2] ), $c->ymd[0] ), new \DateTimeZone( 'UTC' ) );
						}
					}
					return self::archive_date( $c );
				}
				return $post ? (string) get_the_date( $arg, $post ) : '';
			case 'modified':
				return $post ? (string) get_the_modified_date( $arg, $post ) : '';
			case 'year':
				if ( 'date' === $c->kind ) {
					return $c->ymd[0] > 0 ? (string) $c->ymd[0] : '';
				}
				return $post ? (string) get_the_date( 'Y', $post ) : '';
			case 'author':
				if ( $user ) {
					return (string) $user->display_name;
				}
				return $post ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
			case 'category':
				return $post ? self::first_term( $post, 'category' ) : '';
			case 'term':
				return $term ? (string) $term->name : '';
			case 'term_description':
				return $term ? self::trim_var( (string) $term->description ) : '';
			case 'parent_title':
				return $post && $post->post_parent > 0 ? (string) get_the_title( (int) $post->post_parent ) : '';
			case 'posttype':
			case 'posttype_plural':
				$type = $post ? get_post_type_object( $post->post_type ) : ( $c->object instanceof \WP_Post_Type ? $c->object : null );
				if ( ! $type ) {
					return '';
				}
				return 'posttype' === $name ? (string) $type->labels->singular_name : (string) $type->labels->name;
			case 'search':
				return $c->search;
			case 'currentyear':
				return (string) wp_date( 'Y' );
			case 'currentmonth':
				return (string) wp_date( 'F' );
			case 'id':
				if ( $post ) {
					return (string) $post->ID;
				}
				return $term ? (string) $term->term_id : '';
		}
		if ( 0 === strpos( $name, 'cf_' ) && $post ) {
			$key = substr( $name, 3 );
			// ⛔ Never a protected field: a template must not print a private value into the page.
			if ( '' === $key || '_' === $key[0] || is_protected_meta( $key, 'post' ) ) {
				return '';
			}
			$value = get_post_meta( (int) $post->ID, $key, true );
			return is_scalar( $value ) ? (string) $value : '';
		}
		if ( 0 === strpos( $name, 'ct_' ) && $post ) {
			return self::first_term( $post, substr( $name, 3 ) );
		}

		return null;
	}

	/**
	 * WordPress's own label for an archive ("Category:", "Month:"), in the site's language.
	 *
	 * ⭐ These are WordPress core's strings, read in core's text domain on purpose: a theme that
	 * shows "Category: News" as the page heading and a title that says the same must use the same
	 * translation, and core ships it for every language WordPress has.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function archive_prefix( Context $c ): string {
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own strings and contexts, in core's domain (see above).
		switch ( $c->kind ) {
			case 'tax':
				$term = $c->term();
				if ( ! $term ) {
					return '';
				}
				if ( 'category' === $term->taxonomy ) {
					return (string) _x( 'Category:', 'category archive title prefix', 'default' );
				}
				if ( 'post_tag' === $term->taxonomy ) {
					return (string) _x( 'Tag:', 'tag archive title prefix', 'default' );
				}
				$tax = get_taxonomy( $term->taxonomy );
				return $tax ? sprintf( (string) _x( '%s:', 'taxonomy term archive title prefix', 'default' ), (string) $tax->labels->singular_name ) : '';
			case 'author':
				return (string) _x( 'Author:', 'author archive title prefix', 'default' );
			case 'archive':
				return (string) _x( 'Archives:', 'post type archive title prefix', 'default' );
			case 'date':
				if ( $c->ymd[2] > 0 ) {
					return (string) _x( 'Day:', 'date archive title prefix', 'default' );
				}
				return $c->ymd[1] > 0 ? (string) _x( 'Month:', 'date archive title prefix', 'default' ) : (string) _x( 'Year:', 'date archive title prefix', 'default' );
		}
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment

		return '';
	}

	/**
	 * A post's text for a description: blocks and shortcodes removed, no content filters run
	 * (they can be slow and can print things).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function text_of( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}
		$content = strip_shortcodes( $content );
		$text    = Vars::plain( (string) preg_replace( '/<!--.*?-->/s', ' ', $content ) );
		if ( '' !== trim( $text ) ) {
			return $text;
		}

		/**
		 * Filters the text of a post whose content is kept elsewhere (a page builder that stores
		 * its layout outside `post_content`, such as Bricks): plain text for its description
		 * when it has no excerpt (zs-761).
		 *
		 * @param string   $text Plain text ('' when the post content has none).
		 * @param \WP_Post $post The post.
		 */
		return Vars::plain( (string) apply_filters( 'zinn_seo_text_of', $text, $post ) );
	}

	/**
	 * The name of a post's first term in a taxonomy (from the term cache the main query primed).
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $taxonomy Taxonomy.
	 * @return string
	 */
	public static function first_term( \WP_Post $post, string $taxonomy ): string {
		if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return '';
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) || ! isset( $terms[0] ) ) {
			return '';
		}
		// The post's main category (zs-745), when it is one of its terms in this taxonomy.
		$primary = (int) get_post_meta( $post->ID, '_zseo_primary_term', true );
		foreach ( $primary > 0 ? $terms : array() as $term ) {
			if ( (int) $term->term_id === $primary ) {
				return (string) $term->name;
			}
		}

		return (string) $terms[0]->name;
	}

	/**
	 * A date archive's date in the site's language: "2026", "October 2026" or "9 October 2026".
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function archive_date( Context $c ): string {
		list( $y, $m, $d ) = $c->ymd;
		if ( $y < 1 ) {
			return '';
		}
		$stamp = (int) gmmktime( 12, 0, 0, max( 1, $m ), max( 1, $d ), $y );
		if ( $d > 0 ) {
			return (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $stamp, new \DateTimeZone( 'UTC' ) );
		}
		if ( $m > 0 ) {
			return (string) wp_date( 'F Y', $stamp, new \DateTimeZone( 'UTC' ) );
		}

		return (string) $y;
	}

	/**
	 * Memoise one field of one context.
	 *
	 * @param Context  $c     Context.
	 * @param string   $field Field.
	 * @param callable $make  Producer.
	 * @return mixed
	 */
	private static function memo( Context $c, string $field, callable $make ) {
		// An author is part of the key too: the authors sitemap resolves one author after another,
		// and a freed context's object id is reused by the next one (zs-756).
		$id  = $c->object instanceof \WP_Post ? 'p' . $c->object->ID : ( $c->object instanceof \WP_Term ? 't' . $c->object->term_id : ( $c->object instanceof \WP_User ? 'u' . $c->object->ID : '' ) );
		$key = $c->key . '|' . $id . '|' . $c->page . '|' . $field . '|' . spl_object_id( $c );
		if ( ! array_key_exists( $key, self::$memo ) ) {
			// ⛔ zs-044 (FIN1, 1M posts): one page view needs a handful of rows, but a whole-site
			// job (scoring every post, the duplicates report) resolves every post in ONE process,
			// and the memo kept them all: scoring 1,000,000 posts peaked at 667 MB. Bounded.
			if ( count( self::$memo ) >= self::MEMO_MAX ) {
				self::$memo = array();
			}
			/**
			 * Filters a value worked out for a page, once per page view: `title` and
			 * `desc` (plain text), `canonical` (an address) and `robots` (directive =>
			 * true or a limit). An integration with another plugin answers here for the pages
			 * that plugin adds (a seller's store, a forum profile) and for its own rules (a
			 * filtered shop listing kept out of search results). Return the same type.
			 *
			 * @param mixed   $value The value.
			 * @param string  $field Which one.
			 * @param Context $c     What the page is about.
			 */
			self::$memo[ $key ] = apply_filters( 'zinn_seo_value', $make(), $field, $c );
		}

		return self::$memo[ $key ];
	}
}
