<?php
/**
 * Rank Math (and Pro) as an import source.
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
 * Where Rank Math keeps things (read from its own code, release 1.0.280): per post in postmeta
 * and per term in termmeta under the same `rank_math_*` keys; templates and site details in the
 * option `rank-math-options-titles`; verification codes in `rank-math-options-general`;
 * redirects in its own table (read by the redirects module).
 *
 * Its robots choice has three levels — the page, the content type (only when "custom robots" is
 * on for it), the site — and so do its preview limits (`advanced_robots`).
 */
final class Rank_Math extends Source {

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
		return 'rankmath';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Rank Math';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return is_array( get_option( 'rank-math-options-titles', null ) );
	}

	/**
	 * Redirects through the redirects module.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'rankmath';
	}

	/** Set when the import switched the IndexNow module on (an undo switches it off again). */
	public const INDEXNOW_ENABLED = 'zseo_import_indexnow_enabled';

	/** Where the robots.txt text this site had before the import is kept, for an undo. */
	public const ROBOTS_BEFORE = 'zseo_import_robots_before';

	/**
	 * The robots.txt rules the site wrote in Rank Math (it serves them as the whole file, then
	 * adds its sitemap line). Without them the move opened what the site had closed: on
	 * zinnhub.com (2026-10-14) the cart, checkout, search and filter rules and the AI-training
	 * opt-outs (GPTBot, CCBot, Google-Extended, Applebot-Extended) disappeared. Pure apart from
	 * the option read.
	 *
	 * @return string
	 */
	public static function robots_text(): string {
		$general = (array) get_option( 'rank-math-options-general', array() );

		return self::robots_from( $general );
	}

	/**
	 * Its robots.txt text from `rank-math-options-general`, without the sitemap lines (ours are
	 * added in the same place). Pure, for the tests.
	 *
	 * @param array<string, mixed> $general `rank-math-options-general`.
	 * @return string
	 */
	public static function robots_from( array $general ): string {
		$text = is_scalar( $general['robots_txt_content'] ?? null ) ? (string) $general['robots_txt_content'] : '';
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$keep = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^\s*sitemap\s*:/i', $line ) ) {
				continue;
			}
			$keep[] = rtrim( $line );
		}

		return trim( implode( "\n", $keep ) );
	}

	/**
	 * Its visible breadcrumb trail, as `crumb_*` settings: a theme calling
	 * rank_math_the_breadcrumbs() printed nothing after the move, and the trail's wording, date
	 * steps, parent categories and taxonomy names differed (GATE1, Q253 (i), importer
	 * certification 2026-10-18: 27 pages).
	 *
	 * @param array<string, mixed> $general `rank-math-options-general`.
	 * @param array<string, mixed> $titles  `rank-math-options-titles`.
	 * @return array<string, mixed>
	 */
	public static function crumb_settings( array $general, array $titles = array() ): array {
		$s = static fn( string $key ): string => is_scalar( $general[ $key ] ?? null ) ? trim( (string) $general[ $key ] ) : '';
		if ( 'on' !== $s( 'breadcrumbs' ) ) {
			// Its trail off: no visible trail to keep, and none in the structured data (zinnhub).
			return array( 'crumb_compat' => array() );
		}
		$out = array(
			// A theme's rank_math_the_breadcrumbs() keeps printing the trail (Pro).
			'crumb_compat'    => array( 'rankmath' ),
			'crumb_sep'       => html_entity_decode( '' !== $s( 'breadcrumbs_separator' ) ? $s( 'breadcrumbs_separator' ) : '-', ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'crumb_prefix'    => $s( 'breadcrumbs_prefix' ),
			'crumb_404'       => '' !== $s( 'breadcrumbs_404_label' ) ? $s( 'breadcrumbs_404_label' ) : '404 Error: page not found',
			// Its date trail: every step in the archive wording (Archives for 2026 › Archives for March).
			'crumb_date'      => 'tree-archive',
			'crumb_ancestors' => 'on' === $s( 'breadcrumbs_ancestor_categories' ),
			/* translators: %d: page number. */
			'crumb_paged'     => __( 'Page %d', 'zinn-seo' ),
		);
		if ( 'off' !== $s( 'breadcrumbs_home' ) && '' !== $s( 'breadcrumbs_home_label' ) ) {
			$out['crumb_home'] = $s( 'breadcrumbs_home_label' );
		}
		$out['crumb_archive'] = '' !== $s( 'breadcrumbs_archive_format' ) ? $s( 'breadcrumbs_archive_format' ) : 'Archives for %s';
		$out['crumb_search']  = '' !== $s( 'breadcrumbs_search_format' ) ? $s( 'breadcrumbs_search_format' ) : 'Results for %s';
		// The taxonomy's name before a term of a taxonomy of the site's own (Home › Genres ›
		// Mystery), unless "Hide Taxonomy Name"; categories, tags and WooCommerce's never.
		$names = array();
		if ( 'on' !== $s( 'breadcrumbs_hide_taxonomy_name' ) && function_exists( 'get_taxonomies' ) ) {
			foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
				if ( ! in_array( (string) $tax, array( 'category', 'post_tag', 'post_format', 'product_cat', 'product_tag', 'product_brand', 'product_shipping_class' ), true ) && 0 !== strpos( (string) $tax, 'pa_' ) ) {
					$names[] = (string) $tax;
				}
			}
		}
		$out['crumb_tax_name'] = $names;
		// An item's trail runs through its type's "Primary Taxonomy" only (none set: Home › Books ›
		// The River Book); a product's never shows the shop (Home › Mugs › Blue Mug).
		$types = array();
		foreach ( function_exists( 'get_post_types' ) ? get_post_types( array( 'public' => true ), 'names' ) : array() as $type ) {
			if ( in_array( (string) $type, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			$tax                            = is_scalar( $titles[ 'pt_' . $type . '_primary_taxonomy' ] ?? null ) ? (string) $titles[ 'pt_' . $type . '_primary_taxonomy' ] : '';
			$types[ (string) $type ]['tax'] = '' === $tax || 'off' === $tax || ! taxonomy_exists( $tax ) ? 'none' : $tax;
			if ( 'product' === $type ) {
				$types['product']['archive'] = 'hide';
			}
		}
		if ( array() !== $types ) {
			$out['crumb_types'] = $types;
		}
		// A product tag's step in WooCommerce's own wording, as its trail printed it.
		if ( function_exists( 'get_taxonomies' ) && taxonomy_exists( 'product_tag' ) ) {
			/* translators: %s: tag name. Same wording as WooCommerce's own trail. */
			$out['crumb_term']       = __( 'Products tagged &ldquo;%s&rdquo;', 'zinn-seo' );
			$out['crumb_term_plain'] = array_values( array_diff( array_map( 'strval', get_taxonomies( array(), 'names' ) ), array( 'product_tag' ) ) );
		}

		return $out;
	}

	/**
	 * Its Instant Indexing (IndexNow): on or off, and its key. Without it the move stopped telling
	 * search engines about new and changed pages, and a new key replaced the one they had
	 * (zinnhub.com, 2026-10-15). Pure, for the tests.
	 *
	 * @param array<int|string, mixed> $modules `rank_math_modules`.
	 * @param array<string, mixed>     $ii      `rank-math-options-instant-indexing`.
	 * @return array{on: bool, key: string}
	 */
	public static function indexnow_from( array $modules, array $ii ): array {
		$key = is_scalar( $ii['indexnow_api_key'] ?? null ) ? trim( (string) $ii['indexnow_api_key'] ) : '';

		return array(
			'on'  => in_array( 'instant-indexing', array_map( 'strval', $modules ), true ),
			'key' => 1 === preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ? $key : '',
		);
	}

	/**
	 * Copy its robots.txt rules (when this site has written none of its own here).
	 *
	 * @param bool $dry Count only.
	 * @return array<string, int>
	 */
	public function extras( bool $dry ): array {
		$out = array();
		$ii  = self::indexnow_from( (array) get_option( 'rank_math_modules', array() ), (array) get_option( 'rank-math-options-instant-indexing', array() ) );
		if ( $ii['on'] ) {
			$out['indexnow'] = 1;
			if ( ! $dry && ! \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ) ) {
				\ZinnDigital\ZinnSEO\Modules::set( 'indexnow', true );
				update_option( self::INDEXNOW_ENABLED, 1, false );
			}
		}
		$text = self::robots_text();
		if ( '' === $text ) {
			return $out;
		}
		$mine = \ZinnDigital\ZinnSEO\Robots\Robots::custom();
		if ( '' !== trim( $mine ) && trim( $mine ) !== $text ) {
			return $out + array( 'robots_rules_kept_yours' => 1 );
		}
		if ( ! $dry && trim( $mine ) !== $text ) {
			update_option( self::ROBOTS_BEFORE, $mine, false );
			update_option( \ZinnDigital\ZinnSEO\Robots\Robots::CUSTOM, \ZinnDigital\ZinnSEO\Robots\Robots::clean( $text ), false );
		}

		return $out + array( 'robots_rules' => count( array_filter( explode( "\n", $text ), static fn( string $l ): bool => '' !== trim( $l ) && '#' !== substr( trim( $l ), 0, 1 ) ) ) );
	}

	/**
	 * Put back the robots.txt rules the import replaced (while they are still the imported ones).
	 *
	 * @return int
	 */
	public function undo_extras(): int {
		if ( get_option( self::INDEXNOW_ENABLED ) ) {
			\ZinnDigital\ZinnSEO\Modules::set( 'indexnow', false );
			delete_option( self::INDEXNOW_ENABLED );
		}
		$before = get_option( self::ROBOTS_BEFORE, null );
		if ( ! is_string( $before ) ) {
			return 0;
		}
		if ( \ZinnDigital\ZinnSEO\Robots\Robots::custom() === \ZinnDigital\ZinnSEO\Robots\Robots::clean( self::robots_text() ) ) {
			update_option( \ZinnDigital\ZinnSEO\Robots\Robots::CUSTOM, $before, false );
		}
		delete_option( self::ROBOTS_BEFORE );

		return 1;
	}

	/**
	 * The titles option.
	 *
	 * @return array<string, mixed>
	 */
	private function titles(): array {
		if ( null === $this->titles ) {
			$stored       = get_option( 'rank-math-options-titles', array() );
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
			$char      = html_entity_decode( (string) ( $this->titles()['title_separator'] ?? '-' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$this->sep = '' !== self::separator( $char ) ? '%sep%' : $char;
		}
		$org = (string) ( $this->titles()['knowledgegraph_name'] ?? '' );

		return $this->convert(
			$text,
			'/%([a-z_]+)(?:\(([^)%]*)\))?%/i',
			array(
				'title'            => '%title%',
				'seo_title'        => '%title%',
				'filename'         => '%title%',
				'sitename'         => '%sitename%',
				'sitedesc'         => '%tagline%',
				'sep'              => $this->sep,
				// Its %page% carries the separator before "Page 2 of 3" (a run left by page 1 collapses).
				'page'             => $this->sep . ' %page%',
				'pagenumber'       => '%pagenumber%',
				'pagetotal'        => '%pagetotal%',
				'excerpt'          => '%excerpt%',
				'excerpt_only'     => '%excerpt%',
				'seo_description'  => '%excerpt%',
				'category'         => '%category%',
				'categories'       => '%category%',
				'primary_category' => '%category%',
				'tag'              => '%ct_post_tag%',
				'tags'             => '%ct_post_tag%',
				'term'             => '%term%',
				'term_description' => '%term_description%',
				'date'             => '%date%',
				'modified'         => '%modified%',
				'name'             => '%author%',
				'post_author'      => '%author%',
				'user_description' => '%excerpt%',
				'search_query'     => '%search%',
				'currentyear'      => '%currentyear%',
				'currentmonth'     => '%currentmonth%',
				'pt_single'        => '%posttype%',
				'pt_plural'        => '%posttype_plural%',
				'parent_title'     => '%parent_title%',
				'id'               => '%id%',
				'org_name'         => $org,
			),
			array(
				'customfield' => static fn( string $rest, array $m ): string => self::named( 'cf', (string) ( $m[2] ?? '' ) ),
				'customterm'  => static fn( string $rest, array $m ): string => self::named( 'ct', (string) ( $m[2] ?? '' ) ),
			)
		);
	}

	/**
	 * Our fields from one object's Rank Math values.
	 *
	 * @param callable $get `function ( string $key )`: the stored value of `rank_math_<key>`.
	 * @return array<string, mixed>
	 */
	private function fields( callable $get ): array {
		$s      = static fn( string $key ): string => is_scalar( $get( $key ) ) ? trim( (string) $get( $key ) ) : '';
		$robots = array_map( 'strval', (array) $get( 'robots' ) );
		$adv    = (array) $get( 'advanced_robots' );
		$out    = array(
			'title'       => $this->template( $s( 'title' ) ),
			'desc'        => $this->template( $s( 'description' ) ),
			'canonical'   => $s( 'canonical_url' ),
			'robots'      => self::robots( array_fill_keys( $robots, true ) ),
			'max_snippet' => isset( $adv['max-snippet'] ) && is_scalar( $adv['max-snippet'] ) ? (string) $adv['max-snippet'] : '',
			'max_video'   => isset( $adv['max-video-preview'] ) && is_scalar( $adv['max-video-preview'] ) ? (string) $adv['max-video-preview'] : '',
			'max_image'   => isset( $adv['max-image-preview'] ) && is_scalar( $adv['max-image-preview'] ) ? (string) $adv['max-image-preview'] : '',
			'og_title'    => $this->template( $s( 'facebook_title' ) ),
			'og_desc'     => $this->template( $s( 'facebook_description' ) ),
			'og_image'    => self::image( $get( 'facebook_image_id' ), $s( 'facebook_image' ) ),
			'crumb'       => $s( 'breadcrumb_title' ),
		);
		// "Use data from the Facebook tab" is its default: X then shows the shared values.
		if ( 'off' === $s( 'twitter_use_facebook' ) ) {
			$out['x_title'] = $this->template( $s( 'twitter_title' ) );
			$out['x_desc']  = $this->template( $s( 'twitter_description' ) );
			$out['x_image'] = self::image( $get( 'twitter_image_id' ), $s( 'twitter_image' ) );
		}

		return $out;
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$out   = $this->fields( static fn( string $key ) => get_post_meta( $post_id, 'rank_math_' . $key, true ) );
		$focus = array_values( array_filter( array_map( 'trim', explode( ',', self::pm( $post_id, 'rank_math_focus_keyword' ) ) ) ) );

		$out['focus']        = implode( "\n", $focus );
		$out['cornerstone']  = 'on' === self::pm( $post_id, 'rank_math_pillar_content' );
		$out['primary_term'] = (int) self::pm( $post_id, 'rank_math_primary_category' );
		// The article type it stored for this post (its schema rows are `rank_math_schema_<Type>`).
		foreach ( array( 'BlogPosting', 'NewsArticle', 'Article' ) as $type ) {
			$row = get_post_meta( $post_id, 'rank_math_schema_' . $type, true );
			if ( is_array( $row ) && isset( $row['@type'] ) && is_string( $row['@type'] ) ) {
				$out['schema_article'] = $row['@type'];
				break;
			}
		}
		if ( 'off' === self::pm( $post_id, 'rank_math_rich_snippet' ) ) {
			$out['schema_article'] = 'none';
		}
		// Every other type it stored for this post (recipe, event, product, course…): each row is
		// the node itself with Rank Math's variables in it and a `metadata` entry of its own.
		$nodes = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, 'rank_math_schema_' ) ) {
				continue;
			}
			foreach ( (array) $values as $raw ) {
				$row  = maybe_unserialize( $raw );
				$type = is_array( $row ) ? ( $row['@type'] ?? '' ) : '';
				$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;
				if ( '' !== $type && ! in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'off' ), true ) ) {
					$nodes[] = $row;
				}
			}
		}
		if ( array() !== $nodes ) {
			// A page that is a recipe (an event, a product…) for Rank Math is not also an article.
			if ( ! isset( $out['schema_article'] ) ) {
				$out['schema_article'] = 'none';
			}
			$post                 = get_post( $post_id );
			$c                    = $post instanceof \WP_Post ? \ZinnDigital\ZinnSEO\Templates\Context::for_post( $post ) : null;
			$render               = static fn( string $own, string $auto ): string => '' !== $own && null !== $c ? \ZinnDigital\ZinnSEO\Templates\Vars::render( $own, \ZinnDigital\ZinnSEO\Templates\Resolver::lookup( $c ), \ZinnDigital\ZinnSEO\Templates\Resolver::sep() ) : $auto;
			$out['schema_import'] = $this->schema_field(
				$nodes,
				$post_id,
				array(
					'%seo_title%'       => $render( (string) ( $out['title'] ?? '' ), null !== $c ? \ZinnDigital\ZinnSEO\Templates\Resolver::auto_title( $c ) : '' ),
					'%seo_description%' => $render( (string) ( $out['desc'] ?? '' ), null !== $c ? \ZinnDigital\ZinnSEO\Templates\Resolver::auto_description( $c ) : '' ),
					'%post_thumbnail%'  => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
					'%url%'             => (string) get_permalink( $post_id ),
				)
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
		return self::filled( $this->fields( static fn( string $key ) => get_term_meta( (int) $term->term_id, 'rank_math_' . $key, true ) ) );
	}

	/**
	 * A template record from the titles option.
	 *
	 * @param string $group Which of its option groups, e.g. `pt_post`.
	 * @param string $title  The title key's suffix (`_title` everywhere but the author archive).
	 * @return array<string, mixed>
	 */
	private function record( string $group, string $title = '_title' ): array {
		$t      = $this->titles();
		$s      = static fn( string $key ): string => is_scalar( $t[ $key ] ?? null ) ? trim( (string) $t[ $key ] ) : '';
		$site   = array_map( 'strval', (array) ( $t['robots_global'] ?? array() ) );
		$custom = in_array( $s( $group . '_custom_robots' ), array( 'on', '1' ), true );
		$robots = $custom ? array_map( 'strval', (array) ( $t[ $group . '_robots' ] ?? array() ) ) : $site;
		$desc   = $s( $group . '_description' );
		$record = array(
			'title'  => $this->template( $s( $group . $title ) ),
			// It prints no description where its template is empty.
			'desc'   => '' !== $desc ? $this->template( $desc ) : '%none%',
			'index'  => in_array( 'noindex', $robots, true ) ? 'noindex' : 'index',
			'follow' => in_array( 'nofollow', $robots, true ) ? 'nofollow' : 'follow',
			'extra'  => array_values( array_intersect( array( 'noarchive', 'nosnippet', 'noimageindex' ), $robots ) ),
		);
		$image  = self::image( $t[ $group . '_facebook_image_id' ] ?? 0, $s( $group . '_facebook_image' ) );
		if ( $image > 0 ) {
			$record['og_image'] = $image;
		}

		return $record;
	}

	/**
	 * A user: the author's page (user meta `rank_math_title`, `rank_math_description`,
	 * `rank_math_robots`, `rank_math_canonical_url`) and the profiles on the user record
	 * (`twitter` a username, `facebook` an address, `additional_profile_urls` separated by spaces).
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array {
		$id     = (int) $user->ID;
		$robots = get_user_meta( $id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? array_map( 'strval', $robots ) : array();
		$flags  = array();
		foreach ( array( 'noindex', 'index', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) as $flag ) {
			$flags[ $flag ] = in_array( $flag, $robots, true );
		}

		return self::filled(
			array(
				'title'     => $this->template( self::um( $id, 'rank_math_title' ) ),
				'desc'      => $this->template( self::um( $id, 'rank_math_description' ) ),
				'canonical' => self::um( $id, 'rank_math_canonical_url' ),
				'robots'    => self::robots( $flags ),
				'x'         => self::x_handle( self::um( $id, 'twitter' ) ),
				'facebook'  => self::facebook_url( self::um( $id, 'facebook' ) ),
				'same_as'   => self::profile_lines( (array) preg_split( '/\s+/', self::um( $id, 'additional_profile_urls' ) ) ),
			)
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$t       = $this->titles();
		$general = (array) get_option( 'rank-math-options-general', array() );
		$s       = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		$on      = static fn( string $key ): bool => in_array( $s( $t, $key ), array( 'on', '1' ), true );
		$out     = array();
		$char    = html_entity_decode( $s( $t, 'title_separator' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' !== self::separator( $char ) ) {
			$out['sep'] = self::separator( $char );
		}
		// Its IndexNow key, so search engines keep the key they already checked.
		$ii = self::indexnow_from( (array) get_option( 'rank_math_modules', array() ), (array) get_option( 'rank-math-options-instant-indexing', array() ) );
		if ( $ii['on'] && '' !== $ii['key'] ) {
			$out['indexnow_key'] = $ii['key'];
		}
		// Descriptions are shortened the way it did, so none of them changes; links are shared
		// with its full set of tags.
		$out['desc_rules']       = 'rankmath';
		$out['social_rank_math'] = true;
		$out                     = array_merge( $out, self::crumb_settings( $general, $t ) );
		// "Redirect Attachments" (on as it installs): an attachment page goes to the post it is in.
		if ( 'off' !== $s( $general, 'attachment_redirect_urls' ) ) {
			$out['url_attachment'] = 'parent';
		}
		$templates = array();
		$types     = array();
		$scores    = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			// Its "Add SEO Meta Box" switch off: it never scored that type, and neither do we
			// (Search appearance › Content types, Q277).
			if ( array_key_exists( 'pt_' . $type . '_add_meta_box', $t ) && ! $on( 'pt_' . $type . '_add_meta_box' ) ) {
				$scores[ 'post:' . $type ] = array( 'scores' => 'off' );
			}
			if ( ! array_key_exists( 'pt_' . $type . '_title', $t ) ) {
				continue;
			}
			$templates[ 'post:' . $type ] = $this->record( 'pt_' . $type );
			if ( array_key_exists( 'pt_' . $type . '_archive_title', $t ) ) {
				$templates[ 'archive:' . $type ] = array(
					'title' => $this->template( $s( $t, 'pt_' . $type . '_archive_title' ) ),
					'desc'  => '' !== $s( $t, 'pt_' . $type . '_archive_description' ) ? $this->template( $s( $t, 'pt_' . $type . '_archive_description' ) ) : '%none%',
				);
			}
			$snippet                  = $s( $t, 'pt_' . $type . '_default_rich_snippet' );
			$article                  = $s( $t, 'pt_' . $type . '_default_article_type' );
			$types[ 'post:' . $type ] = array( 'article' => 'article' === $snippet && '' !== $article ? $article : 'none' );
			// A product's page was an ItemPage to it.
			if ( 'product' === $type && 'product' === $snippet ) {
				$types[ 'post:' . $type ]['page'] = 'ItemPage';
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			if ( array_key_exists( 'tax_' . $tax . '_title', $t ) ) {
				$templates[ 'tax:' . $tax ] = $this->record( 'tax_' . $tax );
			}
		}
		$home = array(
			'title'    => $this->template( $s( $t, 'homepage_title' ) ),
			// Like ours, it falls back to the tagline when the home description is empty.
			'desc'     => $this->template( $s( $t, 'homepage_description' ) ),
			'og_title' => $this->template( $s( $t, 'homepage_facebook_title' ) ),
			'og_desc'  => $this->template( $s( $t, 'homepage_facebook_description' ) ),
			'og_image' => self::image( $t['homepage_facebook_image_id'] ?? 0, $s( $t, 'homepage_facebook_image' ) ),
		);
		if ( $on( 'homepage_custom_robots' ) ) {
			$robots         = array_map( 'strval', (array) ( $t['homepage_robots'] ?? array() ) );
			$home['index']  = in_array( 'noindex', $robots, true ) ? 'noindex' : 'index';
			$home['follow'] = in_array( 'nofollow', $robots, true ) ? 'nofollow' : 'follow';
		}
		$templates['home']           = $home;
		$templates['author']         = $this->record( 'author', '_archive_title' );
		$author_desc                 = $s( $t, 'author_archive_description' );
		$templates['author']['desc'] = '' !== $author_desc ? $this->template( $author_desc ) : '%none%';
		$date_robots                 = array_map( 'strval', (array) ( $t['date_archive_robots'] ?? array() ) );
		$templates['date']           = array(
			'title' => $this->template( $s( $t, 'date_archive_title' ) ),
			'desc'  => '' !== $s( $t, 'date_archive_description' ) ? $this->template( $s( $t, 'date_archive_description' ) ) : '%none%',
			// An archive it switched off (it redirects to the home page) stays out of search here.
			'index' => $on( 'disable_date_archives' ) || in_array( 'noindex', $date_robots, true ) ? 'noindex' : 'index',
		);
		if ( $on( 'disable_author_archives' ) ) {
			$templates['author']['index'] = 'noindex';
		}
		$templates['search'] = array(
			'title' => $this->template( $s( $t, 'search_title' ) ),
			'index' => $on( 'noindex_search' ) ? 'noindex' : 'index',
		);
		$templates['404']    = array( 'title' => $this->template( $s( $t, '404_title' ) ) );
		$out['templates']    = $templates;
		$out['schema_types'] = $types;
		if ( array() !== $scores ) {
			$out['content_types'] = $scores;
		}

		$adv = (array) ( $t['advanced_robots_global'] ?? array() );
		if ( array() !== $adv ) {
			$out['robots_max_snippet'] = isset( $adv['max-snippet'] ) && is_scalar( $adv['max-snippet'] ) ? (string) $adv['max-snippet'] : '';
			$out['robots_max_video']   = isset( $adv['max-video-preview'] ) && is_scalar( $adv['max-video-preview'] ) ? (string) $adv['max-video-preview'] : '';
			if ( isset( $adv['max-image-preview'] ) && in_array( $adv['max-image-preview'], array( 'none', 'standard', 'large' ), true ) ) {
				$out['robots_max_image'] = (string) $adv['max-image-preview'];
			}
		}

		$person = 'person' === $s( $t, 'knowledgegraph_type' );
		$handle = ltrim( $s( $t, 'twitter_author_names' ), '@' );
		$out   += array(
			'identity_type'    => $person ? 'person' : 'organization',
			'identity_name'    => $s( $t, 'knowledgegraph_name' ),
			'identity_logo'    => self::image( $t['knowledgegraph_logo_id'] ?? 0, $s( $t, 'knowledgegraph_logo' ) ),
			'x_card'           => 'summary_card' === $s( $t, 'twitter_card_type' ) ? 'summary' : 'summary_large_image',
			'x_site'           => $handle,
			'fb_app_id'        => $s( $t, 'facebook_app_id' ),
			'og_default_image' => self::image( $t['open_graph_image_id'] ?? 0, $s( $t, 'open_graph_image' ) ),
			// It reads the first image in the text only when nothing else is set, as we do.
			'verify_google'    => $s( $general, 'google_verify' ),
			'verify_bing'      => $s( $general, 'bing_verify' ),
			'verify_yandex'    => $s( $general, 'yandex_verify' ),
			'verify_baidu'     => $s( $general, 'baidu_verify' ),
			'verify_pinterest' => $s( $general, 'pinterest_verify' ),
		);
		$same   = array( $s( $t, 'social_url_facebook' ) );
		foreach ( preg_split( '/\s+/', $s( $t, 'social_additional_profiles' ) ) as $url ) {
			$same[] = (string) $url;
		}
		if ( '' !== $handle ) {
			$same[] = 'https://x.com/' . $handle;
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );
		// Its noindex rules for empty archives, protected posts and page 2 and on.
		$out['robots_def_empty_terms']         = $on( 'noindex_empty_taxonomies' );
		$out['robots_def_password']            = $on( 'noindex_password_protected' );
		$out['robots_noindex_archive_pages']   = $on( 'noindex_archive_subpages' );
		$out['robots_noindex_post_pages']      = $on( 'noindex_paginated_pages' );
		$out['robots_noindex_hidden_products'] = 'on' === $s( $general, 'noindex_hidden_products' );
		// Its llms.txt opened with the organisation's description: the same here.
		if ( array() !== (array) ( $general['llms_post_types'] ?? array() ) && '' !== $s( $t, 'organization_description' ) ) {
			$out['llms_summary'] = mb_substr( $s( $t, 'organization_description' ), 0, 1000 );
		}
		// WC Vendors gave Rank Math nothing on this site (its "SEO compatibility" switch was off):
		// vendor shop titles, vendor product titles and the shops' sitemap stay out here too, so no
		// title changes on the move. The integration can be switched on afterwards.
		if ( class_exists( 'WC_Vendors', false ) || defined( 'WCV_VERSION' ) || function_exists( 'wcv_get_vendor_name' ) ) {
			if ( 'yes' !== (string) get_option( 'wcvendors_load_seo_compatibility', '' ) ) {
				$out['integrations_off'] = array( 'wc-vendors' );
			}
		}
		// Its custom image overlay (a badge over shared images) and where it applied; and no
		// generated cards, which it never made.
		$out['cards'] = false;
		$overlays     = (array) ( $t['custom_image_overlays'] ?? array() );
		$first        = is_array( $overlays[0] ?? null ) ? $overlays[0] : array();
		if ( (int) ( $first['image_id'] ?? 0 ) > 0 ) {
			$slug  = sanitize_title( (string) ( $first['name'] ?? '' ) );
			$where = array();
			if ( $slug === $s( $t, 'default_image_overlay' ) ) {
				$where[] = '*';
			}
			foreach ( $t as $key => $value ) {
				if ( is_string( $value ) && $slug === $value && 1 === preg_match( '/^(pt|tax)_(.+)_image_overlay$/', (string) $key, $m ) ) {
					$where[] = ( 'pt' === $m[1] ? 'post:' : 'tax:' ) . $m[2];
				}
			}
			if ( array() !== $where ) {
				$out['social_overlay_id']       = (int) $first['image_id'];
				$out['social_overlay_position'] = in_array( (string) ( $first['position'] ?? '' ), array( 'top_left', 'top_center', 'top_right', 'middle_left', 'middle_center', 'middle_right', 'bottom_left', 'bottom_center', 'bottom_right' ), true ) ? (string) $first['position'] : 'bottom_center';
				$out['social_overlay_where']    = array_values( array_unique( $where ) );
			}
		}
		// Its WooCommerce module listed a shop page's products in the structured data.
		$modules = (array) get_option( 'rank_math_modules', array() );
		if ( in_array( 'woocommerce', $modules, true ) && function_exists( 'wc_get_product' ) ) {
			$out['schema_product_lists'] = true;
		}
		// Its image SEO filled empty alt (and title) text as pages were shown: the same here.
		if ( in_array( 'image-seo', $modules, true ) ) {
			$out += self::image_settings( $general, html_entity_decode( $s( $t, 'title_separator' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		// Its breadcrumbs (and their structured data) were off: none here either.
		if ( 'off' === $s( $general, 'breadcrumbs' ) ) {
			$out['schema_breadcrumbs'] = false;
		}
		// Its robots.txt was the site's text alone (no defaults added in front): the same here.
		if ( '' !== self::robots_from( $general ) ) {
			$out['robots_defaults'] = false;
		}

		return $out + self::business_settings( $t ) + self::sitemap_settings( (array) get_option( 'rank-math-options-sitemap', array() ), array_map( 'strval', array_values( get_post_types( array( 'public' => true ) ) ) ), array_map( 'strval', array_values( get_taxonomies( array( 'public' => true ) ) ) ) ) + self::llms_settings( $general, array_map( 'strval', array_values( get_post_types( array( 'public' => true ) ) ) ) );
	}

	/**
	 * Its image SEO ("add missing alt / title attributes") as ours: the rule, in our variables,
	 * and the switch that fills empty alt text as the page is shown. Pure, for the tests.
	 *
	 * ⛔ zinnhub.com, 2026-10-15: not carried over, so every image Rank Math had been giving alt
	 * text to printed `alt=""` after the switch.
	 *
	 * @param array<string, mixed> $general `rank-math-options-general`.
	 * @param string               $sep     Its title separator (the character).
	 * @return array<string, mixed>
	 */
	public static function image_settings( array $general, string $sep ): array {
		$on  = static fn( $v ): bool => true === $v || in_array( is_scalar( $v ) ? strtolower( trim( (string) $v ) ) : '', array( 'on', '1', 'true' ), true );
		$out = array();
		foreach ( array( 'alt', 'title' ) as $field ) {
			$format = is_scalar( $general[ 'img_' . $field . '_format' ] ?? null ) ? trim( (string) $general[ 'img_' . $field . '_format' ] ) : '';
			if ( ! $on( $general[ 'add_img_' . $field ] ?? null ) || '' === $format ) {
				continue;
			}
			$rule = self::image_template( $format, $sep );
			if ( '' !== $rule ) {
				$out[ 'img_' . $field ] = $rule;
			}
		}
		if ( isset( $out['img_alt'] ) ) {
			$out['img_alt_output'] = true;
		}

		return $out;
	}

	/**
	 * One of its image templates in the image rules' variables (`%post_title%`, `%filename%`,
	 * `%site_name%`, `%category%`, `%count%`); a variable with no counterpart is dropped. Pure.
	 *
	 * @param string $text Its template.
	 * @param string $sep  Its title separator (the character).
	 * @return string
	 */
	public static function image_template( string $text, string $sep ): string {
		$map  = array(
			'title'            => '%post_title%',
			'seo_title'        => '%post_title%',
			'filename'         => '%filename%',
			'name'             => '%filename%',
			'sitename'         => '%site_name%',
			'category'         => '%category%',
			'categories'       => '%category%',
			'primary_category' => '%category%',
			'count'            => '%count%',
			'sep'              => '' !== trim( $sep ) ? trim( $sep ) : '-',
		);
		$text = (string) preg_replace_callback(
			'/%([a-z_]+)(?:\(([^)%]*)\))?%/i',
			static fn( array $m ): string => $map[ strtolower( $m[1] ) ] ?? '',
			$text
		);

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Its sitemap choices as ours. Rank Math lists a content type or taxonomy only when its own
	 * switch is on (a type it never saw has no switch, and is left out): the same here, so the
	 * sitemap keeps the same addresses (zinnhub.com, 2026-10-14: 27,762 FAQ items and the page
	 * builder's templates appeared in ours). Pure, for the tests.
	 *
	 * @param array<string, mixed> $sm         `rank-math-options-sitemap`.
	 * @param array<int, string>   $post_types Public content types.
	 * @param array<int, string>   $taxonomies Public taxonomies.
	 * @return array<string, mixed>
	 */
	public static function sitemap_settings( array $sm, array $post_types, array $taxonomies ): array {
		if ( array() === $sm ) {
			return array();
		}
		$on  = static fn( $v ): bool => true === $v || in_array( is_scalar( $v ) ? strtolower( trim( (string) $v ) ) : '', array( 'on', '1', 'true' ), true );
		$out = array(
			'sitemap_exclude_types'      => array_values( array_filter( $post_types, static fn( string $t ): bool => ! $on( $sm[ 'pt_' . $t . '_sitemap' ] ?? null ) ) ),
			'sitemap_exclude_taxonomies' => array_values( array_filter( $taxonomies, static fn( string $t ): bool => 'post_format' !== $t && ! $on( $sm[ 'tax_' . $t . '_sitemap' ] ?? null ) ) ),
			'sitemap_authors'            => $on( $sm['authors_sitemap'] ?? null ),
		);
		// Empty terms stay out, whatever its "include empty" switch said: its index was sized for
		// terms with content, so the empty ones it claimed to list mostly never appeared (zinnhub.com,
		// 2026-10-14), and an empty archive is a thin page.
		// The index keeps the address search engines were given (`sitemap_index.xml`).
		$out['sitemap_index_name'] = 'sitemap_index.xml';
		$per                       = (int) ( $sm['items_per_page'] ?? 0 );
		if ( $per > 0 ) {
			$out['sitemap_per_file'] = $per;
		}
		$ids = implode( ',', array_filter( array_map( 'intval', preg_split( '/[\s,]+/', is_scalar( $sm['exclude_posts'] ?? null ) ? (string) $sm['exclude_posts'] : '' ) ) ) );
		if ( '' !== $ids ) {
			$out['sitemap_exclude_ids'] = $ids;
		}

		return $out;
	}

	/**
	 * Its business details (Local SEO and the knowledge graph) as ours: address, map position,
	 * phone, opening hours and the contact points. Without them the site's identity lost its
	 * address, map position and four contact points on every page (zinnhub.com, 2026-10-14).
	 * Pure, for the tests.
	 *
	 * @param array<string, mixed> $t `rank-math-options-titles`.
	 * @return array<string, mixed>
	 */
	public static function business_settings( array $t ): array {
		$s        = static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '';
		$addr     = is_array( $t['local_address'] ?? null ) ? $t['local_address'] : array();
		$out      = array();
		$geo      = array_map( 'trim', explode( ',', $s( $t['geo'] ?? '' ) ) );
		$vals     = array(
			'local_name'        => $s( $t['knowledgegraph_name'] ?? '' ),
			'local_email'       => $s( $t['email'] ?? '' ),
			'local_street'      => $s( $addr['streetAddress'] ?? '' ),
			'local_locality'    => $s( $addr['addressLocality'] ?? '' ),
			'local_region'      => $s( $addr['addressRegion'] ?? '' ),
			'local_postcode'    => $s( $addr['postalCode'] ?? '' ),
			'local_country'     => strtoupper( substr( $s( $addr['addressCountry'] ?? '' ), 0, 2 ) ),
			'local_lat'         => 2 === count( $geo ) && is_numeric( $geo[0] ) ? $geo[0] : '',
			'local_lng'         => 2 === count( $geo ) && is_numeric( $geo[1] ) ? $geo[1] : '',
			'local_price_range' => $s( $t['price_range'] ?? '' ),
		);
		$contacts = array();
		$phone    = '';
		foreach ( (array) ( $t['phone_numbers'] ?? array() ) as $row ) {
			$number = is_array( $row ) ? $s( $row['number'] ?? '' ) : '';
			$kind   = is_array( $row ) ? str_replace( ' ', '-', strtolower( $s( $row['type'] ?? '' ) ) ) : '';
			if ( '' === $number ) {
				continue;
			}
			$phone = '' === $phone ? $number : $phone;
			if ( '' !== $kind && ! isset( $contacts[ $kind ] ) ) {
				$contacts[ $kind ] = array( 'telephone' => $number );
			}
		}
		$vals['local_phone'] = $phone;
		$days                = array(
			'monday'    => 'Mo',
			'tuesday'   => 'Tu',
			'wednesday' => 'We',
			'thursday'  => 'Th',
			'friday'    => 'Fr',
			'saturday'  => 'Sa',
			'sunday'    => 'Su',
		);
		$hours               = array();
		if ( 'on' !== $s( $t['hide_opening_hours'] ?? '' ) ) {
			foreach ( (array) ( $t['opening_hours'] ?? array() ) as $row ) {
				$day  = is_array( $row ) ? ( $days[ strtolower( $s( $row['day'] ?? '' ) ) ] ?? '' ) : '';
				$time = is_array( $row ) ? $s( $row['time'] ?? '' ) : '';
				if ( '' !== $day && 1 === preg_match( '/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $time ) ) {
					$hours[] = $day . ' ' . str_replace( ' ', '', $time );
				}
			}
		}
		$vals['local_hours'] = implode( "\n", $hours );
		$vals                = array_filter( $vals, static fn( string $v ): bool => '' !== $v );
		if ( count( $vals ) > 2 && 'person' !== $s( $t['knowledgegraph_type'] ?? '' ) ) {
			$type            = (string) preg_replace( '/[^A-Za-z]/', '', $s( $t['local_business_type'] ?? '' ) );
			$out['local_on'] = true;
			$out            += $vals;
			if ( '' !== $type ) {
				$out['local_type'] = $type;
			}
		}
		if ( array() !== $contacts ) {
			$out['identity_contacts'] = $contacts;
		}
		// Its "additional organisation info" (legal name, founding date, tax and company numbers,
		// size) and the organisation's e-mail.
		$legal = array();
		foreach ( (array) ( $t['additional_info'] ?? array() ) as $row ) {
			$kind  = is_array( $row ) ? $s( $row['type'] ?? '' ) : '';
			$value = is_array( $row ) ? $s( $row['value'] ?? '' ) : '';
			if ( '' === $value ) {
				continue;
			}
			if ( 'foundingDate' === $kind && 1 === preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $value ) ) {
				$out['identity_founded'] = $value;
			} elseif ( in_array( $kind, array( 'legalName', 'taxID', 'vatID', 'iso6523Code', 'duns', 'numberOfEmployees' ), true ) ) {
				$legal[ $kind ] = $value;
			}
		}
		if ( '' !== $s( $t['email'] ?? '' ) && 'person' !== $s( $t['knowledgegraph_type'] ?? '' ) ) {
			$legal['email'] = $s( $t['email'] );
		}
		if ( array() !== $legal ) {
			$out['identity_legal'] = array( 'org' => $legal );
		}
		if ( '' !== $s( $t['website_alternate_name'] ?? '' ) ) {
			$out['identity_alt_name'] = $s( $t['website_alternate_name'] );
		}
		$about = $s( $t['organization_description'] ?? '' );
		if ( '' !== $about ) {
			$out['identity_description'] = mb_substr( $about, 0, 500 );
		}

		return $out;
	}

	/**
	 * Its llms.txt choices as ours: the content types it lists, how many of each, and the text
	 * it adds. Pure, for the tests.
	 *
	 * @param array<string, mixed> $general    `rank-math-options-general`.
	 * @param array<int, string>   $post_types Public content types.
	 * @return array<string, mixed>
	 */
	public static function llms_settings( array $general, array $post_types ): array {
		$types = array_values( array_intersect( array_map( 'strval', (array) ( $general['llms_post_types'] ?? array() ) ), $post_types ) );
		if ( array() === $types ) {
			return array();
		}
		// It wrote llms.txt only (no llms-full.txt): the same here, until the site switches it on.
		$out   = array(
			'llms_types' => $types,
			'llms_full'  => false,
		);
		$taxes = array_values( array_filter( array_map( 'strval', (array) ( $general['llms_taxonomies'] ?? array() ) ), static fn( string $t ): bool => '' !== $t ) );
		if ( array() !== $taxes ) {
			$out['llms_taxonomies'] = $taxes;
		}
		$limit = (int) ( $general['llms_limit'] ?? 0 );
		if ( $limit > 0 ) {
			$out['llms_per_type'] = min( 5000, $limit );
		}
		$extra = is_scalar( $general['llms_extra_content'] ?? null ) ? trim( wp_strip_all_tags( html_entity_decode( (string) $general['llms_extra_content'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';
		if ( '' !== $extra ) {
			$out['llms_details'] = mb_substr( $extra, 0, 50000 );
		}

		return $out;
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		$notes = array(
			__( 'SEO scores are worked out again by Zinn® SEO rather than copied.', 'zinn-seo' ),
			__( 'Recipes, events, products, courses and the other types Rank Math stored for a page are copied with their details. Zinn® SEO Pro shows them to search engines and lets you edit them; without it they are kept, ready for the day you switch it on.', 'zinn-seo' ),
		);
		$t     = $this->titles();
		if ( in_array( (string) ( $t['disable_date_archives'] ?? '' ), array( 'on', '1' ), true ) ) {
			$notes[] = __( 'Rank Math redirects date archives to the home page. Here they stay reachable but are kept out of search results (noindex).', 'zinn-seo' );
		}

		return $notes;
	}
}
