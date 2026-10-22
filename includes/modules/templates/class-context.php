<?php
/**
 * What a request is showing, in the terms the templates use.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One value object for "the thing this page is about": its kind, the template key that decides
 * its title (`home`, `post:<type>`, `archive:<type>`, `tax:<taxonomy>`, `author`, `date`,
 * `search`, `404`), the object itself and the page number. Built once per request from the main
 * query, or for any post or term (the screen's previews, the bulk editor), so every surface
 * renders exactly what a visitor would get.
 */
final class Context {

	/**
	 * Kind: home, singular, tax, archive, author, date, search, 404 or other.
	 *
	 * @var string
	 */
	public string $kind = 'other';

	/**
	 * Template key.
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * The object: a post, term, user or post type, or null.
	 *
	 * @var \WP_Post|\WP_Term|\WP_User|\WP_Post_Type|null
	 */
	public $object = null;

	/**
	 * Current page (1 on the first).
	 *
	 * @var int
	 */
	public int $page = 1;

	/**
	 * Number of pages (1 when unknown).
	 *
	 * @var int
	 */
	public int $pages = 1;

	/**
	 * Is this the site's front page?
	 *
	 * @var bool
	 */
	public bool $front = false;

	/**
	 * The words searched for (search results only).
	 *
	 * @var string
	 */
	public string $search = '';

	/**
	 * For a date archive: year, month, day (0 when not part of it).
	 *
	 * @var array{0: int, 1: int, 2: int}
	 */
	public array $ymd = array( 0, 0, 0 );

	/**
	 * Per-request cache of current().
	 *
	 * @var Context|null
	 */
	private static ?Context $current = null;

	/**
	 * The context of the current request (after the main query ran).
	 *
	 * @return Context
	 */
	public static function current(): Context {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$c = new self();
		global $wp_query;
		$c->page  = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$c->pages = max( 1, (int) ( $wp_query->max_num_pages ?? 1 ) );
		$c->front = is_front_page();

		if ( is_404() ) {
			$c->kind = '404';
			$c->key  = '404';
		} elseif ( is_search() ) {
			$c->kind   = 'search';
			$c->key    = 'search';
			$c->search = (string) get_search_query( false );
		} elseif ( is_front_page() && is_home() ) {
			$c->kind = 'home';
			$c->key  = 'home';
		} elseif ( is_singular() || is_home() ) {
			// A static front page and the posts page are pages: their own fields first.
			$post = is_home() ? get_post( (int) get_option( 'page_for_posts' ) ) : get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$c->kind   = 'singular';
				$c->key    = $c->front ? 'home' : 'post:' . $post->post_type;
				$c->object = $post;
				if ( is_singular() ) {
					// A post split with <!--nextpage-->: its own page count.
					$c->pages = max( 1, substr_count( (string) $post->post_content, '<!--nextpage-->' ) + 1 );
				}
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$c->kind   = 'tax';
				$c->key    = 'tax:' . $term->taxonomy;
				$c->object = $term;
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_queried_object();
			if ( ! $type instanceof \WP_Post_Type ) {
				// A shop's listing page: WooCommerce answers with the PAGE chosen as the shop, not
				// with the content type, and the listing had no title of its own (found with the
				// real plugin, 2026-10-10). The content type is in the query.
				$name = get_query_var( 'post_type' );
				$name = is_array( $name ) ? (string) reset( $name ) : (string) $name;
				$type = '' !== $name ? get_post_type_object( $name ) : null;
			}
			if ( $type instanceof \WP_Post_Type ) {
				$c->kind   = 'archive';
				$c->key    = 'archive:' . $type->name;
				$c->object = $type;
			}
		} elseif ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				$c->kind   = 'author';
				$c->key    = 'author';
				$c->object = $user;
			}
		} elseif ( is_date() ) {
			$c->kind = 'date';
			$c->key  = 'date';
			$c->ymd  = array( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
			if ( 0 === $c->ymd[0] && '' !== (string) get_query_var( 'm' ) ) {
				$m      = (string) get_query_var( 'm' );
				$c->ymd = array( (int) substr( $m, 0, 4 ), (int) substr( $m, 4, 2 ), (int) substr( $m, 6, 2 ) );
			}
		}
		if ( did_action( 'wp' ) ) {
			self::$current = $c;
		}

		return $c;
	}

	/**
	 * The context of one post, as its own page shows it (first page).
	 *
	 * @param \WP_Post $post Post.
	 * @return Context
	 */
	public static function for_post( \WP_Post $post ): Context {
		$c         = new self();
		$c->kind   = 'singular';
		$front     = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID;
		$c->front  = $front;
		$c->key    = $front ? 'home' : 'post:' . $post->post_type;
		$c->object = $post;

		return $c;
	}

	/**
	 * The context of one term's archive (first page).
	 *
	 * @param \WP_Term $term Term.
	 * @return Context
	 */
	public static function for_term( \WP_Term $term ): Context {
		$c         = new self();
		$c->kind   = 'tax';
		$c->key    = 'tax:' . $term->taxonomy;
		$c->object = $term;

		return $c;
	}

	/**
	 * A context for a template key with no particular object (the screen's previews of the
	 * home page, author, date, search and not-found templates).
	 *
	 * @param string $key Template key.
	 * @return Context
	 */
	public static function for_key( string $key ): Context {
		$c      = new self();
		$c->key = $key;
		if ( 'home' === $key ) {
			$c->kind  = 'home';
			$c->front = true;
		} elseif ( 0 === strpos( $key, 'archive:' ) ) {
			$c->kind   = 'archive';
			$c->object = get_post_type_object( substr( $key, 8 ) );
		} elseif ( in_array( $key, array( 'author', 'date', 'search', '404' ), true ) ) {
			$c->kind = $key;
			if ( 'author' === $key ) {
				$c->object = wp_get_current_user();
			} elseif ( 'date' === $key ) {
				$c->ymd = array( (int) gmdate( 'Y' ), (int) gmdate( 'n' ), 0 );
			} elseif ( 'search' === $key ) {
				$c->search = __( 'example search', 'zinn-seo' );
			}
		}

		return $c;
	}

	/**
	 * Forget the per-request context (tests, and a second main query).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$current = null;
	}

	/**
	 * The post, when this is about one.
	 *
	 * @return \WP_Post|null
	 */
	public function post(): ?\WP_Post {
		return $this->object instanceof \WP_Post ? $this->object : null;
	}

	/**
	 * The term, when this is about one.
	 *
	 * @return \WP_Term|null
	 */
	public function term(): ?\WP_Term {
		return $this->object instanceof \WP_Term ? $this->object : null;
	}

	/**
	 * The template group of the key (home, post, archive, tax, author, date, search, 404).
	 *
	 * @return string
	 */
	public function group(): string {
		$at = strpos( $this->key, ':' );

		return false === $at ? $this->key : substr( $this->key, 0, $at );
	}
}
