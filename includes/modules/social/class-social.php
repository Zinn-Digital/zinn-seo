<?php
/**
 * Open Graph, X cards, fediverse, Pinterest and LinkedIn tags (zs-022, zs-026).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Social;

use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;
use ZinnDigital\ZinnSEO\Templates\Templates;
use ZinnDigital\ZinnSEO\Templates\Vars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a shared link looks like on Facebook, LinkedIn, X, Mastodon, WhatsApp, Slack, Pinterest.
 *
 * Precedence for each value, the same as the head: the page's own social field, then its content
 * type's social template, then what the page already says to search engines (its SEO title and
 * description). The image is picked in this order: the page's own social image, its featured
 * image, the first image in its text, its content type's default, the site default — and with
 * Pro a generated card (social-cards module) where the site wants one.
 *
 * ⭐ X reads Open Graph for anything it does not find under `twitter:`, so the X tags are printed
 * only where they differ (the card type, the accounts, a separate X title, text or image): the
 * same result in fewer bytes of every page's head.
 */
final class Social {

	/** Usermeta keys of the author profile fields (cleaned by uninstall with the `_zseo_` family). */
	public const USER_X         = '_zseo_x';
	public const USER_FEDIVERSE = '_zseo_fediverse';
	public const USER_FACEBOOK  = '_zseo_facebook';
	public const USER_TIKTOK    = '_zseo_tiktok';

	/**
	 * The module's settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * Per-page social fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		return require __DIR__ . '/meta.php';
	}

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		add_filter( 'zinn_seo_head_tags', array( self::class, 'head_tags' ), 10, 2 );
		add_filter( 'zinn_seo_graph', array( self::class, 'graph' ), 10, 2 );
	}

	/**
	 * Admin, REST and WP-CLI hooks.
	 *
	 * @return void
	 */
	public static function boot_admin(): void {
		add_filter( 'user_contactmethods', array( self::class, 'contact_methods' ) );
		add_filter( 'update_user_metadata', array( self::class, 'no_empty_contact_rows' ), 10, 4 );
		add_action( 'zinn_seo_settings_saved', array( self::class, 'refresh_default_image' ), 10, 3 );
		add_action( 'attachment_updated', array( self::class, 'attachment_changed' ) );
		add_action( 'delete_attachment', array( self::class, 'attachment_changed' ) );
		add_filter( 'zinn_seo_form_fields', array( self::class, 'form_fields' ) );
		add_filter( 'zinn_seo_field_placeholders', array( self::class, 'placeholders' ), 10, 2 );
		add_filter( 'zinn_seo_resolved', array( self::class, 'resolved' ), 10, 2 );
	}

	/**
	 * No empty rows for our profile fields.
	 *
	 * WordPress stores EVERY contact method on every save of a user (`wp_insert_user()` writes
	 * each one, filled in or not) — an empty row each for a person who filled none in, so three
	 * rows per member on a site with 100,000 of them. An empty value is not stored, and a row
	 * that was emptied is removed.
	 *
	 * @param mixed $check   Null to let WordPress store it.
	 * @param mixed $user_id The user.
	 * @param mixed $key     The meta key.
	 * @param mixed $value   The value.
	 * @return mixed Null, or true when nothing is to be stored.
	 */
	public static function no_empty_contact_rows( $check, $user_id, $key, $value ) {
		if ( ! in_array( $key, array( self::USER_X, self::USER_FEDIVERSE, self::USER_FACEBOOK, self::USER_TIKTOK ), true ) || ! is_string( $value ) || '' !== trim( $value ) ) {
			return $check;
		}
		if ( metadata_exists( 'user', (int) $user_id, (string) $key ) ) {
			delete_user_meta( (int) $user_id, (string) $key );
		}

		return true;
	}

	/**
	 * Author profile fields (Users → Profile).
	 *
	 * @param array<string, string> $methods Contact methods.
	 * @return array<string, string>
	 */
	public static function contact_methods( $methods ): array {
		$methods                         = (array) $methods;
		$methods[ self::USER_X ]         = __( 'X (Twitter) username', 'zinn-seo' );
		$methods[ self::USER_FEDIVERSE ] = __( 'Fediverse or Mastodon handle (@name@server)', 'zinn-seo' );
		$methods[ self::USER_FACEBOOK ]  = __( 'Facebook profile address', 'zinn-seo' );
		$methods[ self::USER_TIKTOK ]    = __( 'TikTok username', 'zinn-seo' );

		return $methods;
	}

	/**
	 * Keep the default image's address and size beside its ID.
	 *
	 * @param array<string, mixed> $after  Settings now.
	 * @param array<string, mixed> $before Settings before.
	 * @param string               $actor  Who.
	 * @return void
	 */
	public static function refresh_default_image( $after, $before = array(), $actor = '' ): void {
		$after  = (array) $after;
		$before = (array) $before;
		if ( 'system' === $actor ) {
			return;
		}
		$id = (int) ( $after['og_default_image'] ?? 0 );
		if ( (int) ( $before['og_default_image'] ?? 0 ) === $id && ( 0 === $id || '' !== (string) ( $after['og_default_image_url'] ?? '' ) ) ) {
			return;
		}
		self::store_default_image( $id );
	}

	/**
	 * An attachment changed: when it is the default image, refresh the stored copy.
	 *
	 * @param int $attachment_id Attachment.
	 * @return void
	 */
	public static function attachment_changed( $attachment_id ): void {
		if ( (int) $attachment_id > 0 && (int) Settings::get( 'og_default_image', 0 ) === (int) $attachment_id ) {
			self::store_default_image( 'delete_attachment' === current_action() ? 0 : (int) $attachment_id );
		}
	}

	/**
	 * Store the default image's facts.
	 *
	 * @param int $id Attachment (0: none).
	 * @return void
	 */
	public static function store_default_image( int $id ): void {
		$image = $id > 0 ? self::attachment( $id ) : null;
		Settings::save(
			array(
				'og_default_image'     => null === $image ? 0 : $id,
				'og_default_image_url' => null === $image ? '' : $image['url'],
				'og_default_image_w'   => null === $image ? 0 : $image['width'],
				'og_default_image_h'   => null === $image ? 0 : $image['height'],
				'og_default_image_alt' => null === $image ? '' : mb_substr( $image['alt'], 0, 300 ),
			),
			'system'
		);
	}

	/**
	 * An image from the media library.
	 *
	 * @param int $id Attachment ID.
	 * @return array{id: int, url: string, width: int, height: int, alt: string}|null
	 */
	public static function attachment( int $id ): ?array {
		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}

		return array(
			'id'     => $id,
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => Vars::plain( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		);
	}

	/**
	 * The image a page is shared with, and where it came from.
	 *
	 * @param Context $c Context.
	 * @return array{id: int, url: string, width: int, height: int, alt: string, source: string}|null
	 */
	public static function image( Context $c ): ?array {
		$found  = null;
		$source = '';
		$own    = (int) Resolver::own( $c, 'og_image' );
		if ( $own > 0 ) {
			$found  = self::attachment( $own );
			$source = 'own';
		}
		$post = $c->post();
		if ( null === $found && $post && (bool) Settings::get( 'og_featured_image', true ) && has_post_thumbnail( $post ) ) {
			$found  = self::attachment( (int) get_post_thumbnail_id( $post ) );
			$source = 'featured';
		}
		if ( null === $found && $post && (bool) Settings::get( 'og_content_image', true ) ) {
			$first = self::first_content_image( (string) $post->post_content );
			if ( $first > 0 ) {
				$found  = self::attachment( $first );
				$source = 'content';
			}
		}
		if ( null === $found ) {
			$type = (int) Templates::effective( $c->key )['og_image'];
			if ( $type > 0 ) {
				$found  = self::attachment( $type );
				$source = 'type';
			}
		}
		if ( null === $found && '' !== (string) Settings::get( 'og_default_image_url', '' ) ) {
			$found  = array(
				'id'     => (int) Settings::get( 'og_default_image', 0 ),
				'url'    => (string) Settings::get( 'og_default_image_url', '' ),
				'width'  => (int) Settings::get( 'og_default_image_w', 0 ),
				'height' => (int) Settings::get( 'og_default_image_h', 0 ),
				'alt'    => self::default_alt(),
			);
			$source = 'site';
		}
		if ( null !== $found ) {
			$found['source'] = $source;
		}

		/**
		 * Filters the image a page is shared with (Pro's generated cards answer here).
		 *
		 * @param array<string, mixed>|null $found  Image: id, url, width, height, alt, source.
		 * @param Context                   $c      The page.
		 * @param string                    $source Where it came from: own, featured, content, type, site, or ''.
		 */
		$found = apply_filters( 'zinn_seo_social_image', $found, $c, $source );

		return is_array( $found ) && ! empty( $found['url'] ) ? $found : null;
	}

	/**
	 * The media library ID of the first image in a post's text (`wp-image-123`), or 0.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	public static function first_content_image( string $content ): int {
		if ( 1 === preg_match( '/<img\b[^>]*\bclass=["\'][^"\']*\bwp-image-(\d+)\b/i', $content, $m ) ) {
			return (int) $m[1];
		}
		if ( 1 === preg_match( '/<!--\s*wp:image\s*\{[^}]*"id"\s*:\s*(\d+)/', $content, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * The shared title.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function title( Context $c ): string {
		$own = (string) Resolver::own( $c, 'og_title' );
		$tpl = (string) Templates::effective( $c->key )['og_title'];
		foreach ( array( $own, $tpl ) as $template ) {
			if ( '' !== $template ) {
				$text = Vars::render( $template, Resolver::lookup( $c ), Resolver::sep() );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		return Resolver::title( $c );
	}

	/**
	 * The shared text.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	public static function description( Context $c ): string {
		$own = (string) Resolver::own( $c, 'og_desc' );
		$tpl = (string) Templates::effective( $c->key )['og_desc'];
		foreach ( array( $own, $tpl ) as $template ) {
			if ( Vars::NONE === trim( $template ) ) {
				return '';
			}
			if ( '' !== $template ) {
				$text = Vars::render( $template, Resolver::lookup( $c ), Resolver::sep() );
				if ( '' !== $text ) {
					// What a person wrote for this page is printed as written (the field holds
					// 1,000 characters); only text a template builds is cut to a card's length.
					return $template === $own && array() === Vars::used( $own ) ? $text : Vars::trim_text( $text, 300 );
				}
			}
		}

		return Resolver::base_description( $c );
	}

	/**
	 * Open Graph's sale tags for a product on sale: its regular and its sale price.
	 *
	 * @param string $regular  Regular price.
	 * @param string $sale     Sale price.
	 * @param string $currency ISO currency code.
	 * @return array<string, string>
	 */
	public static function sale_tags( string $regular, string $sale, string $currency ): array {
		if ( '' === $regular || '' === $sale || $regular === $sale ) {
			return array();
		}

		return array(
			'product:original_price:amount'   => $regular,
			'product:original_price:currency' => $currency,
			'product:sale_price:amount'       => $sale,
			'product:sale_price:currency'     => $currency,
		);
	}

	/**
	 * Whether the site's default X picture stands in for a page shared with this picture.
	 *
	 * @param array<string, mixed>|null $image The page's shared picture (image()).
	 * @return bool
	 */
	public static function x_default_applies( ?array $image ): bool {
		return null === $image || 'site' === (string) ( $image['source'] ?? '' );
	}

	/**
	 * The X card's size on a page: its own choice, else the site's (IMP2: The SEO Framework's
	 * per-post card is kept after a move).
	 *
	 * @param string $own The page's own `x_card` field.
	 * @return string `summary` or `summary_large_image`.
	 */
	public static function card_size( string $own ): string {
		if ( in_array( $own, array( 'summary', 'summary_large_image' ), true ) ) {
			return $own;
		}

		return 'summary' === (string) Settings::get( 'x_card', 'summary_large_image' ) ? 'summary' : 'summary_large_image';
	}

	/**
	 * A page's own X title or description; with none, the page's search title or description
	 * when the site chose X to show the page's text (`x_page_text`, SmartCrawl's rule).
	 *
	 * @param Context $c     The page.
	 * @param string  $field `x_title` or `x_desc`.
	 * @return string
	 */
	public static function x_text( Context $c, string $field ): string {
		$own = Vars::plain( (string) Resolver::own( $c, $field ) );
		if ( '' !== $own || ! (bool) Settings::get( 'x_page_text', false ) ) {
			return $own;
		}

		return 'x_title' === $field ? Resolver::title( $c ) : Resolver::base_description( $c );
	}

	/**
	 * The site's name on shared links: the `og_site_name` template, else the site title.
	 *
	 * @param Context $c The page.
	 * @return string
	 */
	public static function site_name( Context $c ): string {
		$tpl = (string) Settings::get( 'og_site_name', '' );
		if ( '' !== trim( $tpl ) ) {
			$text = Vars::render( $tpl, Resolver::lookup( $c ), Resolver::sep() );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return Vars::plain( (string) get_bloginfo( 'name' ) );
	}

	/**
	 * The site's Facebook page (from its profiles elsewhere), or ''.
	 *
	 * @return string
	 */
	public static function facebook_page(): string {
		foreach ( (array) Settings::get( 'identity_same_as', array() ) as $url ) {
			if ( 1 === preg_match( '#^https://([a-z0-9-]+\.)?facebook\.com/#i', (string) $url ) ) {
				return (string) $url;
			}
		}

		return '';
	}

	/**
	 * The extra tags chosen in `social_extras`, as facts.
	 *
	 * @param Context              $c      The page.
	 * @param array<string, mixed> $facts  The page's facts so far.
	 * @param array<int, string>   $chosen The groups switched on.
	 * @return array<string, mixed>
	 */
	public static function extras( Context $c, array $facts, array $chosen ): array {
		$x        = Rank_Math_Tags::facts( $c, $facts );
		$post     = $c->post();
		$on       = static fn( string $group ): bool => in_array( $group, $chosen, true );
		$shop     = 'archive' === $c->kind && $c->object instanceof \WP_Post_Type && 'product' === $c->object->name && function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) > 0;
		$page     = ( $post instanceof \WP_Post && 'singular' === $c->kind && ! $c->front ) || $shop;
		$out      = array(
			// The site's Facebook page as the publisher of every page with content of its own
			// (Yoast SEO's choice: posts, pages, products and the shop page; not lists).
			'publisher'  => ( $on( 'publisher' ) && $page ) || $on( 'publisher_all' ) ? self::facebook_page() : '',
			'image_type' => $on( 'image_type' ) ? (string) $x['image_type'] : '',
			'updated'    => '',
			'tags'       => array(),
			'type'       => '',
			'product'    => array(),
			'labels'     => array(),
		);
		$singular = $post instanceof \WP_Post && 'singular' === $c->kind && ! $c->front;
		if ( $singular && $on( 'updated_time' ) && 'product' !== $post->post_type ) {
			$out['updated'] = (string) $facts['modified'];
		}
		if ( $singular && $on( 'article_tags' ) && is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
			$tags = get_the_terms( $post, 'post_tag' );
			foreach ( is_array( $tags ) ? $tags : array() as $tag ) {
				$out['tags'][] = Vars::plain( (string) $tag->name );
			}
		}
		if ( $on( 'product' ) && 'product' === (string) $x['type'] ) {
			$out['type']    = 'product';
			$out['product'] = (array) $x['product'];
			// Out of stock is said too (Rank Math's set says only "in stock").
			if ( ! isset( $out['product']['product:availability'] ) ) {
				$out['product']['product:availability'] = 'outofstock';
			}
			// A product on sale: its regular and sale prices too (Premium SEO Pack and
			// Squirrly print them; Open Graph's product properties).
			$item = $post instanceof \WP_Post && function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
			if ( is_object( $item ) && method_exists( $item, 'is_on_sale' ) && $item->is_on_sale() ) {
				$out['product'] += self::sale_tags( (string) $item->get_regular_price(), (string) $item->get_sale_price(), function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '' );
			}
		}
		if ( $on( 'product_labels' ) && 'product' === (string) $x['type'] ) {
			$out['labels'] = (array) $x['labels'];
		}
		if ( $singular && ( ( $on( 'written_by' ) && 'post' === $post->post_type ) || $on( 'written_by_pages' ) ) && '' !== (string) $facts['author_name'] ) {
			$out['labels'][] = array( __( 'Written by', 'zinn-seo' ), (string) $facts['author_name'] );
		}

		return $out;
	}

	/**
	 * Whose accounts (X, fediverse) a page names as its creator: a post's own author, or, on an
	 * author's page, the person it is about (The SEO Framework 5.2 names the queried author's
	 * X account there; queue Q324). 0: nobody's.
	 *
	 * @param Context $c      Context.
	 * @param int     $author The post's author, 0 when none.
	 * @return int
	 */
	public static function profile_owner( Context $c, int $author ): int {
		if ( $author > 0 ) {
			return $author;
		}

		return 'author' === $c->kind && $c->object instanceof \WP_User ? (int) $c->object->ID : 0;
	}

	/**
	 * The facts a page's social tags are built from.
	 *
	 * @param Context $c Context.
	 * @return array<string, mixed>
	 */
	public static function facts( Context $c ): array {
		$post   = $c->post();
		$url    = Resolver::canonical( $c );
		$author = $post && post_type_supports( $post->post_type, 'author' ) ? (int) $post->post_author : 0;
		$image  = self::image( $c );
		$owner  = self::profile_owner( $c, $author );
		$x_img  = (int) Resolver::own( $c, 'x_image' );
		// The site's default X picture stands in only where the page has no picture of its own
		// (the shared one is the site default or none): a product's or a featured picture
		// stays (Slim SEO's rule, queue Q254).
		$x_img = $x_img > 0 || ! self::x_default_applies( $image ) ? $x_img : (int) Settings::get( 'x_default_image', 0 );
		$facts = array(
			'og'          => (bool) Settings::get( 'og', true ),
			'x'           => (bool) Settings::get( 'x_cards', true ),
			'type'        => $post && ! $c->front ? 'article' : ( 'author' === $c->kind ? 'profile' : 'website' ),
			'locale'      => self::og_locale( (string) get_locale() ),
			'site_name'   => self::site_name( $c ),
			'title'       => self::title( $c ),
			'desc'        => self::description( $c ),
			'url'         => '' !== $url ? $url : Resolver::address( $c ),
			'image'       => $image,
			'x_card'      => self::card_size( (string) Resolver::own( $c, 'x_card' ) ),
			'x_site'      => self::handle( (string) Settings::get( 'x_site', '' ) ),
			'x_creator'   => $owner > 0 ? self::handle( (string) get_user_meta( $owner, self::USER_X, true ) ) : '',
			'x_title'     => self::x_text( $c, 'x_title' ),
			'x_desc'      => self::x_text( $c, 'x_desc' ),
			'x_image'     => $x_img > 0 ? self::attachment( $x_img ) : null,
			'fb_app_id'   => (string) Settings::get( 'fb_app_id', '' ),
			'published'   => '',
			'modified'    => '',
			'section'     => '',
			'author_name' => '',
			'author_fb'   => '',
			'fediverse'   => '',
			'nopin'       => (bool) Settings::get( 'nopin', false ) || (bool) Resolver::own( $c, 'nopin' ),
		);
		if ( $post && ! $c->front ) {
			$facts['published'] = (string) get_post_time( 'c', true, $post );
			$facts['modified']  = (string) get_post_modified_time( 'c', true, $post );
			$facts['section']   = Resolver::first_term( $post, 'category' );
		}
		if ( $author > 0 && $post && ! $c->front ) {
			$facts['author_name'] = Vars::plain( (string) get_the_author_meta( 'display_name', $author ) );
			$fb                   = (string) get_user_meta( $author, self::USER_FACEBOOK, true );
			$facts['author_fb']   = 1 === preg_match( '#^https://([a-z0-9-]+\.)?facebook\.com/#i', $fb ) ? $fb : '';
			$facts['fediverse']   = self::fediverse( (string) get_user_meta( $author, self::USER_FEDIVERSE, true ) );
		}
		if ( '' === $facts['fediverse'] && $owner > 0 && $owner !== $author ) {
			// An author's page: the person it is about (queue Q324).
			$facts['fediverse'] = self::fediverse( (string) get_user_meta( $owner, self::USER_FEDIVERSE, true ) );
		}
		if ( '' === $facts['fediverse'] ) {
			$facts['fediverse'] = self::fediverse( (string) Settings::get( 'fediverse_creator', '' ) );
		}

		if ( (bool) Settings::get( 'social_rank_math', false ) ) {
			require_once __DIR__ . '/class-rank-math-tags.php';
			$facts['rm'] = Rank_Math_Tags::facts( $c, $facts );
		} elseif ( array() !== (array) Settings::get( 'social_extras', array() ) ) {
			require_once __DIR__ . '/class-rank-math-tags.php';
			$facts['extra'] = self::extras( $c, $facts, (array) Settings::get( 'social_extras', array() ) );
		}

		/**
		 * Filters the facts a page's sharing tags are built from (`title`, `desc`, `url`,
		 * `site_name`, `image`, `x_title`, `x_desc`, …), before any tag is printed.
		 *
		 * @param array<string, mixed> $facts Facts.
		 * @param Context              $c     The page.
		 */
		return (array) apply_filters( 'zinn_seo_social_facts', $facts, $c );
	}

	/**
	 * Build the tags from facts (no WordPress reads: unit-tested on its own).
	 *
	 * @param array<string, mixed> $f Facts from facts().
	 * @return array<int, string> Complete, escaped tags.
	 */
	public static function build( array $f ): array {
		$out     = array();
		$printed = array();
		$prop    = static function ( string $property, string $content ) use ( &$out, &$printed ): void {
			if ( '' !== $content ) {
				$out[]                = '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '">';
				$printed[ $property ] = true;
			}
		};
		// The sharing extras add to what is printed, never repeat it (Rank Math's own set can
		// print the same property first).
		$once  = static function ( string $property, string $content ) use ( $prop, &$printed ): void {
			if ( ! isset( $printed[ $property ] ) ) {
				$prop( $property, $content );
			}
		};
		$name  = static function ( string $meta, string $content ) use ( &$out ): void {
			if ( '' !== $content ) {
				$out[] = '<meta name="' . esc_attr( $meta ) . '" content="' . esc_attr( $content ) . '">';
			}
		};
		$image = is_array( $f['image'] ?? null ) ? $f['image'] : null;
		if ( ! empty( $f['og'] ) ) {
			$prop( 'og:locale', (string) $f['locale'] );
			// The other languages this page is published in (a translation plugin fills these).
			foreach ( (array) ( $f['locale_alternate'] ?? array() ) as $alternate ) {
				$prop( 'og:locale:alternate', (string) $alternate );
			}
			$rm    = is_array( $f['rm'] ?? null ) ? $f['rm'] : null;
			$extra = is_array( $f['extra'] ?? null ) ? $f['extra'] : null;
			$type  = $rm && '' !== (string) ( $rm['type'] ?? '' ) ? (string) $rm['type'] : (string) $f['type'];
			$prop( 'og:type', $extra && '' !== (string) $extra['type'] ? (string) $extra['type'] : $type );
			$prop( 'og:title', (string) $f['title'] );
			$prop( 'og:description', (string) $f['desc'] );
			$prop( 'og:url', (string) $f['url'] );
			$prop( 'og:site_name', (string) $f['site_name'] );
			if ( $rm ) {
				$prop( 'og:updated_time', (string) ( $rm['updated'] ?? '' ) );
			}
			if ( $image ) {
				$prop( 'og:image', (string) $image['url'] );
				if ( $rm && 0 === strpos( (string) $image['url'], 'https://' ) ) {
					$prop( 'og:image:secure_url', (string) $image['url'] );
				}
				if ( (int) $image['width'] > 0 && (int) $image['height'] > 0 ) {
					$prop( 'og:image:width', (string) (int) $image['width'] );
					$prop( 'og:image:height', (string) (int) $image['height'] );
				}
				$prop( 'og:image:alt', (string) $image['alt'] );
				if ( $rm ) {
					$prop( 'og:image:type', (string) ( $rm['image_type'] ?? '' ) );
				} elseif ( $extra ) {
					$prop( 'og:image:type', (string) $extra['image_type'] );
				}
			}
			if ( $rm ) {
				// As Rank Math printed them: the dates of an article or page (not of a product),
				// and a product's price, stock and brand.
				$prop( 'article:published_time', (string) ( $rm['published'] ?? '' ) );
				$prop( 'article:modified_time', (string) ( $rm['modified'] ?? '' ) );
				$prop( 'article:author', (string) ( $rm['author_fb'] ?? '' ) );
				$prop( 'article:publisher', (string) ( $rm['publisher'] ?? '' ) );
				$prop( 'article:section', (string) ( $rm['section'] ?? '' ) );
				foreach ( (array) ( $rm['tags'] ?? array() ) as $tag ) {
					$prop( 'article:tag', (string) $tag );
				}
				foreach ( (array) ( $rm['product'] ?? array() ) as $property => $content ) {
					$prop( (string) $property, (string) $content );
				}
			} elseif ( 'article' === $f['type'] && ! ( $extra && 'product' === (string) $extra['type'] ) ) {
				$prop( 'article:published_time', (string) $f['published'] );
				$prop( 'article:modified_time', (string) $f['modified'] );
				$prop( 'article:author', (string) $f['author_fb'] );
				$prop( 'article:section', (string) $f['section'] );
			}
			if ( $extra ) {
				$once( 'article:publisher', (string) $extra['publisher'] );
				if ( ! isset( $printed['article:tag'] ) ) {
					foreach ( (array) $extra['tags'] as $tag ) {
						$prop( 'article:tag', (string) $tag );
					}
				}
				$once( 'og:updated_time', (string) $extra['updated'] );
				foreach ( (array) $extra['product'] as $property => $content ) {
					$once( (string) $property, (string) $content );
				}
			}
			$prop( 'fb:app_id', (string) $f['fb_app_id'] );
		}
		// LinkedIn and many readers show this as the byline.
		$name( 'author', (string) $f['author_name'] );
		if ( ! empty( $f['x'] ) ) {
			$x_image = is_array( $f['x_image'] ?? null ) ? $f['x_image'] : null;
			$card    = ( $x_image || $image ) ? (string) $f['x_card'] : 'summary';
			$name( 'twitter:card', $card );
			$name( 'twitter:site', '' !== (string) $f['x_site'] ? '@' . $f['x_site'] : '' );
			$creator = '' !== (string) $f['x_creator'] ? (string) $f['x_creator'] : (string) ( $f['rm']['creator'] ?? '' );
			$name( 'twitter:creator', '' !== $creator ? '@' . $creator : '' );
			// X falls back to Open Graph: print only what differs, or everything when OG is off
			// (or when the site keeps Rank Math's full set).
			$og_off = empty( $f['og'] ) || is_array( $f['rm'] ?? null );
			$title  = '' !== (string) $f['x_title'] ? (string) $f['x_title'] : ( $og_off ? (string) $f['title'] : '' );
			$desc   = '' !== (string) $f['x_desc'] ? (string) $f['x_desc'] : ( $og_off ? (string) $f['desc'] : '' );
			$name( 'twitter:title', $title );
			$name( 'twitter:description', $desc );
			$img = $x_image ? $x_image : ( $og_off ? $image : null );
			if ( $img ) {
				$name( 'twitter:image', (string) $img['url'] );
				$name( 'twitter:image:alt', (string) $img['alt'] );
			}
			$n = 0;
			foreach ( (array) ( $f['rm']['labels'] ?? $f['extra']['labels'] ?? array() ) as $pair ) {
				if ( is_array( $pair ) && '' !== (string) ( $pair[1] ?? '' ) ) {
					++$n;
					$name( 'twitter:label' . $n, (string) $pair[0] );
					$name( 'twitter:data' . $n, (string) $pair[1] );
				}
			}
		}
		$name( 'fediverse:creator', '' !== (string) $f['fediverse'] ? '@' . ltrim( (string) $f['fediverse'], '@' ) : '' );
		if ( ! empty( $f['nopin'] ) ) {
			$name( 'pinterest', 'nopin' );
		}

		return $out;
	}

	/**
	 * Head tags (filter of the head module).
	 *
	 * @param array<int, string> $lines Tags so far.
	 * @param Context            $c     The page.
	 * @return array<int, string>
	 */
	public static function head_tags( $lines, $c ): array {
		$lines = (array) $lines;
		if ( ! $c instanceof Context || '404' === $c->kind ) {
			return $lines;
		}

		return array_merge( $lines, self::build( self::facts( $c ) ) );
	}

	/**
	 * The page node's main image (zs-415: Discover reads a large image).
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @param Context                             $c     The page.
	 * @return array<string, array<string, mixed>>
	 */
	public static function graph( $nodes, $c ): array {
		$nodes = (array) $nodes;
		if ( ! $c instanceof Context || ! isset( $nodes['webpage'] ) ) {
			return $nodes;
		}
		// The page's own featured image first, as the other SEO plugins name it (a page shared
		// with another picture still shows its featured image in search: importer
		// certification, queue Q254), else the shared image.
		$image = null;
		$post  = 'singular' === $c->kind ? $c->post() : null;
		$thumb = $post instanceof \WP_Post ? (int) get_post_thumbnail_id( $post ) : 0;
		if ( $thumb > 0 ) {
			$image = self::attachment( $thumb );
		}
		$image = $image ? $image : self::image( $c );
		if ( $image ) {
			$node = array(
				'@type' => 'ImageObject',
				'url'   => $image['url'],
			);
			if ( $image['width'] > 0 ) {
				$node['width']  = $image['width'];
				$node['height'] = $image['height'];
			}
			$nodes['webpage']['primaryImageOfPage'] = $node;
		}

		return $nodes;
	}

	/**
	 * An X username without the @, or '' when it is not one.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function handle( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})#i', $value, $m ) ) {
			$value = $m[1];
		}
		$value = ltrim( $value, '@' );

		return 1 === preg_match( '/^[A-Za-z0-9_]{1,15}$/', $value ) ? $value : '';
	}

	/**
	 * A fediverse handle `name@server.tld` (no leading @), or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function fediverse( string $value ): string {
		$value = ltrim( trim( $value ), '@' );

		return 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}@[A-Za-z0-9.-]{1,190}\.[A-Za-z]{2,}$/', $value ) ? $value : '';
	}

	/**
	 * The words describing the site's default sharing image, in the language being viewed when a
	 * translation plugin keeps them (Tranzly answers `zinn_seo_site_text`).
	 *
	 * @return string
	 */
	private static function default_alt(): string {
		$alt = (string) Settings::get( 'og_default_image_alt', '' );
		if ( '' === trim( $alt ) ) {
			return $alt;
		}
		$given = apply_filters( 'zinn_seo_site_text', $alt, 'og_default_image_alt', '' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in Family::site_text().

		return is_string( $given ) && '' !== trim( $given ) ? $given : $alt;
	}

	/**
	 * Open Graph wants language_TERRITORY: `ar` becomes `ar_AR`, `ja` becomes `ja_JP`.
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function og_locale( string $locale ): string {
		$locale = (string) preg_replace( '/[^A-Za-z_]/', '', $locale );
		if ( 1 === preg_match( '/^[a-z]{2,3}_[A-Z]{2}/', $locale, $m ) ) {
			return substr( $m[0], 0, 5 );
		}
		$map  = array(
			'ja' => 'ja_JP',
			'ko' => 'ko_KR',
			'zh' => 'zh_CN',
			'he' => 'he_IL',
			'el' => 'el_GR',
			'sv' => 'sv_SE',
			'da' => 'da_DK',
			'cs' => 'cs_CZ',
			'et' => 'et_EE',
			'uk' => 'uk_UA',
			'vi' => 'vi_VN',
			'en' => 'en_US',
			'sq' => 'sq_AL',
			'hy' => 'hy_AM',
			'ka' => 'ka_GE',
			'fa' => 'fa_IR',
			'hi' => 'hi_IN',
			'bn' => 'bn_IN',
			'nb' => 'nb_NO',
			'sr' => 'sr_RS',
			'sl' => 'sl_SI',
			'ms' => 'ms_MY',
			'ur' => 'ur_PK',
		);
		$lang = strtolower( substr( $locale, 0, 2 ) );
		if ( isset( $map[ $lang ] ) ) {
			return $map[ $lang ];
		}

		return 1 === preg_match( '/^[a-z]{2}$/', $lang ) ? $lang . '_' . strtoupper( $lang ) : 'en_US';
	}

	/**
	 * The social fields on the classic and term forms.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_fields( $fields ): array {
		$fields             = (array) $fields;
		$fields['og_title'] = array(
			'label' => __( 'Title when shared', 'zinn-seo' ),
			'kind'  => 'text',
		);
		$fields['og_desc']  = array(
			'label' => __( 'Text when shared', 'zinn-seo' ),
			'kind'  => 'textarea',
		);
		$fields['og_image'] = array(
			'label' => __( 'Image when shared', 'zinn-seo' ),
			'kind'  => 'image',
			'help'  => __( 'Best at 1200 × 630 pixels. Empty: the featured image, then the first image in the text, then the site default.', 'zinn-seo' ),
		);
		$fields['x_title']  = array(
			'label' => __( 'Title on X (optional)', 'zinn-seo' ),
			'kind'  => 'text',
		);
		$fields['x_desc']   = array(
			'label' => __( 'Text on X (optional)', 'zinn-seo' ),
			'kind'  => 'textarea',
		);
		$fields['x_image']  = array(
			'label' => __( 'Image on X (optional)', 'zinn-seo' ),
			'kind'  => 'image',
		);
		$fields['x_card']   = array(
			'label'   => __( 'Card size on X', 'zinn-seo' ),
			'kind'    => 'select',
			'choices' => array(
				''                    => __( 'The site’s card size', 'zinn-seo' ),
				'summary_large_image' => __( 'Large picture', 'zinn-seo' ),
				'summary'             => __( 'Small square picture', 'zinn-seo' ),
			),
		);
		$fields['nopin']    = array(
			'label'   => __( 'Pinterest', 'zinn-seo' ),
			'kind'    => 'select',
			'choices' => array(
				'0' => __( 'Visitors may pin this page', 'zinn-seo' ),
				'1' => __( 'Ask Pinterest not to allow pins (nopin)', 'zinn-seo' ),
			),
		);

		return $fields;
	}

	/**
	 * Placeholders of the social fields (what they are when left empty).
	 *
	 * @param array<string, string> $auto Placeholders.
	 * @param Context               $c    The page.
	 * @return array<string, string>
	 */
	public static function placeholders( $auto, $c ): array {
		$auto = (array) $auto;
		if ( ! $c instanceof Context ) {
			return $auto;
		}
		$auto['og_title'] = Resolver::title( $c );
		$auto['og_desc']  = Resolver::base_description( $c );
		$image            = self::image( $c );
		$auto['og_image'] = $image ? self::source_label( (string) $image['source'] ) : __( 'No image yet: shares show a text-only card.', 'zinn-seo' );

		return $auto;
	}

	/**
	 * Where an image comes from, in words.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'own'      => __( 'Using the image chosen here.', 'zinn-seo' ),
			'featured' => __( 'Using the featured image.', 'zinn-seo' ),
			'content'  => __( 'Using the first image in the text.', 'zinn-seo' ),
			'type'     => __( 'Using the default image of this content type.', 'zinn-seo' ),
			'site'     => __( 'Using the site\'s default image.', 'zinn-seo' ),
			'card'     => __( 'Using a generated card.', 'zinn-seo' ),
		);

		return $labels[ $source ] ?? '';
	}

	/**
	 * The social part of `/resolved` (and Discover readiness).
	 *
	 * @param array<string, mixed> $out Description.
	 * @param Context              $c   The page.
	 * @return array<string, mixed>
	 */
	public static function resolved( $out, $c ): array {
		$out = (array) $out;
		if ( ! $c instanceof Context ) {
			return $out;
		}
		$image         = self::image( $c );
		$out['social'] = array(
			'title'        => self::title( $c ),
			'desc'         => self::description( $c ),
			'image'        => $image,
			'image_source' => $image ? self::source_label( (string) $image['source'] ) : '',
		);
		if ( $c->post() ) {
			$out['discover'] = Discover::check( $c );
		}

		return $out;
	}

	/**
	 * Meta field names this module owns (for the bulk editor and CSV).
	 *
	 * @return array<int, string>
	 */
	public static function field_names(): array {
		return array_keys( self::meta() );
	}
}
