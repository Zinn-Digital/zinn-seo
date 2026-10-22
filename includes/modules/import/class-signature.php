<?php
/**
 * What a page's head says, reduced to what a search result or a shared link shows (zs-260).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ THE EQUALITY CONTRACT of a move. Two heads are equal when all of these are:
 *
 * - `title`, `description`: the same text (HTML entities decoded, spaces collapsed); "none" is a
 *   value, so a description appearing where there was none is a difference.
 * - `canonical`: the same address. A page with no canonical tag and a page that names its own
 *   address say the same thing (index this address), so those two are equal.
 * - `robots`: the same set of directives. Left out, because each only states what holds without
 *   it: `index`, `follow`, `all`; `max-snippet:-1` and `max-video-preview:-1` ("no limit", a
 *   crawler's default); and `max-image-preview:large`, which WordPress itself prints on every
 *   public site, so its absence is one plugin's habit and not a choice the site made. A real
 *   limit (`max-snippet:50`, `max-image-preview:none`) counts.
 * - `og_title`, `og_description`: what a shared link shows — the Open Graph tag or, without one,
 *   the page's title and description, which is what Facebook, LinkedIn and the rest read then.
 *   A plugin that prints no Open Graph tags on a category and one that prints the page's own
 *   title there show the same card. `og_image`: the tag only (no page-wide fallback is defined).
 * - `x_title`, `x_description`, `x_image`: what X shows, i.e. its own tag or, without one, the
 *   shared value X falls back to.
 * - `types`: every KEY structured-data type the other plugin printed (the page, the article, the
 *   organisation or person, breadcrumbs, product, FAQ, …) is in our graph. We may print more.
 *
 * Left out on purpose: `og:type`, `og:url`, `og:locale`, `twitter:card` and the supporting nodes
 * of a graph (ImageObject, SearchAction, …). They change nothing a person sees in a result or a
 * shared card, each plugin has its own habits there, and ours are the ones this plugin tests.
 *
 * ⛔ FOUR NARROW EXCEPTIONS, each because the two values are not a difference to anyone reading
 * them (CLAUDE.md §2.36: a carve-out names the fact it rests on, and when to look again):
 *
 * 1. A page that asks not to be indexed: `canonical` and `types` are not compared. A canonical
 *    address is a hint about which page to INDEX and structured data earns rich results only
 *    for indexed pages; on a page kept out of the index neither is acted on. (Look again if
 *    search engines start using either for noindex pages.)
 * 2. A not-found page: only `title` and `robots` are compared. It has no address of its own to
 *    share or index; the sharing tags some plugins print there describe nothing.
 * 3. The home page: a `BreadcrumbList` is not asked for. A trail of the one item "Home" is not a
 *    breadcrumb to a search engine (Google's guidance: at least two items).
 * 4. `WebPage` is met by any of its kinds (AboutPage, CollectionPage, ProfilePage, …): a plugin
 *    that prints `["WebPage","AboutPage"]` and one that prints `AboutPage` say the same thing.
 *    On an author archive `CollectionPage` is met by `ProfilePage`, the same page described
 *    more precisely (and the type Google reads for it). And an author picture hosted by an avatar service (Gravatar) is not asked for as the shared
 *    image of an author archive: this plugin does not put a third party's address, derived from
 *    an e-mail hash, in the head. (Look again if avatars become local files in WordPress.)
 * 5. An image in the uploads folder is compared by its path there: the other plugin may have
 *    stored it with the site's old address; ours prints the address the site has now.
 */
final class Signature {

	/** The compared fields, in the order differences are shown. */
	public const FIELDS = array( 'title', 'description', 'canonical', 'robots', 'og_title', 'og_description', 'og_image', 'x_title', 'x_description', 'x_image', 'types' );

	/** Structured-data types that say what a page IS (compared); everything else supports them. */
	public const KEY_TYPES = array(
		'WebSite',
		'Organization',
		'Person',
		'BreadcrumbList',
		'WebPage',
		'AboutPage',
		'ContactPage',
		'CheckoutPage',
		'CollectionPage',
		'ItemPage',
		'MedicalWebPage',
		'ProfilePage',
		'QAPage',
		'FAQPage',
		'RealEstateListing',
		'SearchResultsPage',
		'Article',
		'BlogPosting',
		'NewsArticle',
		'TechArticle',
		'ScholarlyArticle',
		'Report',
		'OpinionNewsArticle',
		'AnalysisNewsArticle',
		'Product',
		'ProductGroup',
		'HowTo',
		'Recipe',
		'Event',
		'LocalBusiness',
		'JobPosting',
		'Course',
		'Book',
		'Movie',
		'SoftwareApplication',
		'VideoObject',
		'Review',
		'Service',
		'ClaimReview',
		'Restaurant',
		'Store',
		'MusicEvent',
		'MusicAlbum',
		'MusicRecording',
		'MusicGroup',
		'PodcastEpisode',
		'VideoGame',
		'MobileApplication',
		'WebApplication',
		'Accommodation',
		'Dataset',
	);

	/** Kinds of Article among the key types. */
	public const ARTICLE_KINDS = array( 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'OpinionNewsArticle', 'AnalysisNewsArticle' );

	/** Kinds of Organization whose names do not end in "Organization". */
	public const ORG_KINDS = array( 'Airline', 'Consortium', 'Corporation', 'FundingScheme', 'LibrarySystem', 'NGO', 'OnlineBusiness', 'OnlineStore', 'PerformingGroup', 'Project', 'ResearchProject', 'FundingAgency', 'SportsTeam', 'WorkersUnion', 'DanceGroup', 'TheaterGroup', 'Cooperative' );

	/** Robots directives that only state what holds without them. */
	public const IMPLIED = array( 'index', 'follow', 'all', 'max-snippet:-1', 'max-video-preview:-1', 'max-image-preview:large' );

	/** The kinds of WebPage (exception 4). */
	public const PAGE_KINDS = array( 'AboutPage', 'ContactPage', 'CheckoutPage', 'CollectionPage', 'ItemPage', 'MedicalWebPage', 'ProfilePage', 'QAPage', 'FAQPage', 'RealEstateListing', 'SearchResultsPage' );

	/**
	 * An empty signature.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		return array(
			'title'          => '',
			'description'    => '',
			'canonical'      => '',
			'robots'         => array(),
			'og_title'       => '',
			'og_description' => '',
			'og_image'       => '',
			'x_title'        => '',
			'x_description'  => '',
			'x_image'        => '',
			'types'          => array(),
		);
	}

	/**
	 * Text as a person reads it: entities decoded, spaces collapsed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Robots directives as a sorted set, the implied ones left out.
	 *
	 * @param array<int, string> $contents The content of each robots tag.
	 * @return array<int, string>
	 */
	public static function robots( array $contents ): array {
		$out = array();
		foreach ( $contents as $content ) {
			foreach ( explode( ',', strtolower( (string) $content ) ) as $part ) {
				$part = (string) preg_replace( '/\s+/', '', $part );
				if ( '' !== $part && ! in_array( $part, self::IMPLIED, true ) ) {
					$out[ $part ] = true;
				}
			}
		}
		// `none` is both.
		if ( isset( $out['none'] ) ) {
			unset( $out['none'] );
			$out['noindex']  = true;
			$out['nofollow'] = true;
		}
		// Preview limits say nothing on a page kept out of search (ours leaves them out there;
		// The SEO Framework printed them beside noindex — queue Q254).
		if ( isset( $out['noindex'] ) ) {
			foreach ( array_keys( $out ) as $part ) {
				if ( 0 === strpos( (string) $part, 'max-' ) ) {
					unset( $out[ $part ] );
				}
			}
		}
		$out = array_keys( $out );
		sort( $out );

		return $out;
	}

	/**
	 * The attributes of a tag as written in HTML: name => decoded value.
	 *
	 * @param string $tag One tag.
	 * @return array<string, string>
	 */
	public static function attributes( string $tag ): array {
		$out = array();
		if ( preg_match_all( '/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $tag, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$value = '' !== $a[2] ? $a[2] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : (string) ( $a[4] ?? '' ) );
				$name  = strtolower( $a[1] );
				if ( ! isset( $out[ $name ] ) ) {
					$out[ $name ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
		}

		return $out;
	}

	/**
	 * The key types of a JSON-LD document, at any depth: an author written inside the article
	 * and an author written as its own node are the same statement.
	 *
	 * @param mixed              $doc   Decoded JSON.
	 * @param array<string,bool> $out   Collected.
	 * @param int                $depth Recursion guard.
	 * @return void
	 */
	public static function types_of( $doc, array &$out, int $depth = 0 ): void {
		if ( ! is_array( $doc ) || $depth > 12 ) {
			return;
		}
		foreach ( (array) ( $doc['@type'] ?? array() ) as $type ) {
			if ( is_string( $type ) && in_array( $type, self::KEY_TYPES, true ) ) {
				$out[ $type ] = true;
			} elseif ( is_string( $type ) && ( 1 === preg_match( '/^[A-Z][A-Za-z]*Organization$/', $type ) || in_array( $type, self::ORG_KINDS, true ) ) ) {
				// A kind of Organization (NewsMediaOrganization, Corporation…) is the site's
				// organisation all the same: SmartCrawl printed "Organization" on most pages and
				// the chosen kind on its home page (queue Q254).
				$out['Organization'] = true;
			}
		}
		foreach ( $doc as $value ) {
			if ( is_array( $value ) ) {
				self::types_of( $value, $out, $depth + 1 );
			}
		}
	}

	/**
	 * The signature of a rendered page.
	 *
	 * @param string $html The whole document.
	 * @return array<string, mixed> blank() filled in, plus `doubled`: tags printed more than once.
	 */
	public static function from_html( string $html ): array {
		$sig  = self::blank();
		$end  = stripos( $html, '</head>' );
		$head = false === $end ? $html : substr( $html, 0, $end );
		// Scripts and styles can hold text that looks like tags.
		$plain = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $head );
		if ( 1 === preg_match( '#<title\b[^>]*>(.*?)</title>#is', $plain, $m ) ) {
			$sig['title'] = self::text( $m[1] );
		}
		$meta   = array();
		$counts = array();
		if ( preg_match_all( '#<meta\b[^>]*>#i', $plain, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$a   = self::attributes( $tag );
				$key = strtolower( (string) ( $a['name'] ?? $a['property'] ?? '' ) );
				if ( '' === $key || ! isset( $a['content'] ) ) {
					continue;
				}
				$meta[ $key ][] = (string) $a['content'];
				$counts[ $key ] = (int) ( $counts[ $key ] ?? 0 ) + 1;
			}
		}
		if ( preg_match_all( '#<link\b[^>]*>#i', $plain, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$a = self::attributes( $tag );
				if ( 'canonical' === strtolower( (string) ( $a['rel'] ?? '' ) ) ) {
					$counts['canonical'] = (int) ( $counts['canonical'] ?? 0 ) + 1;
					if ( '' === $sig['canonical'] ) {
						$sig['canonical'] = trim( (string) ( $a['href'] ?? '' ) );
					}
				}
			}
		}
		$first                 = static fn( string $key ): string => self::text( (string) ( $meta[ $key ][0] ?? '' ) );
		$sig['description']    = $first( 'description' );
		$sig['robots']         = self::robots( (array) ( $meta['robots'] ?? array() ) );
		$sig['og_title']       = '' !== $first( 'og:title' ) ? $first( 'og:title' ) : $sig['title'];
		$sig['og_description'] = '' !== $first( 'og:description' ) ? $first( 'og:description' ) : $sig['description'];
		$sig['og_image']       = trim( (string) ( $meta['og:image'][0] ?? '' ) );
		$sig['x_title']        = '' !== $first( 'twitter:title' ) ? $first( 'twitter:title' ) : $sig['og_title'];
		$sig['x_description']  = '' !== $first( 'twitter:description' ) ? $first( 'twitter:description' ) : $sig['og_description'];
		$sig['x_image']        = '' !== trim( (string) ( $meta['twitter:image'][0] ?? '' ) ) ? trim( (string) $meta['twitter:image'][0] ) : $sig['og_image'];

		// Structured data may sit anywhere in the document (some plugins print it in the footer).
		$types = array();
		if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				self::types_of( json_decode( trim( $json ), true ), $types );
			}
		}
		$types = array_keys( $types );
		sort( $types );
		$sig['types'] = $types;

		$doubled = array();
		foreach ( array( 'description', 'canonical', 'og:title', 'og:description', 'twitter:title' ) as $key ) {
			if ( (int) ( $counts[ $key ] ?? 0 ) > 1 ) {
				$doubled[] = $key;
			}
		}
		$sig['doubled'] = $doubled;

		return $sig;
	}

	/**
	 * The signature of what THIS plugin prints for a page, worked out without rendering it.
	 *
	 * @param Context $c The page.
	 * @return array<string, mixed>
	 */
	public static function ours( Context $c ): array {
		$sig                = self::blank();
		$sig['title']       = self::text( Resolver::title( $c ) );
		$sig['description'] = self::text( Resolver::description( $c ) );
		$sig['canonical']   = Resolver::canonical( $c );

		// WordPress's own robots tag carries max-image-preview:large on a public site and noindex
		// on a private one; ours is added to it exactly as on a visitor's page.
		$base          = '0' === (string) get_option( 'blog_public', '1' ) ? array( 'noindex' => true ) : array( 'max-image-preview' => 'large' );
		$sig['robots'] = self::robots( self::robots_for( $c, $base ) );

		// Without a tag of its own a network reads the page's title and description.
		$sig['og_title']       = $sig['title'];
		$sig['og_description'] = $sig['description'];
		if ( class_exists( '\ZinnDigital\ZinnSEO\Social\Social' ) && '404' !== $c->kind ) {
			$f     = \ZinnDigital\ZinnSEO\Social\Social::facts( $c );
			$image = is_array( $f['image'] ?? null ) ? (string) $f['image']['url'] : '';
			$og    = ! empty( $f['og'] );
			$x     = ! empty( $f['x'] );
			if ( $og ) {
				$sig['og_title']       = '' !== self::text( (string) $f['title'] ) ? self::text( (string) $f['title'] ) : $sig['title'];
				$sig['og_description'] = '' !== self::text( (string) $f['desc'] ) ? self::text( (string) $f['desc'] ) : $sig['description'];
				$sig['og_image']       = $image;
			}
			// X: its own tag; with Open Graph off we print the shared values under X's names.
			$x_image              = is_array( $f['x_image'] ?? null ) ? (string) $f['x_image']['url'] : '';
			$sig['x_title']       = $x && '' !== (string) $f['x_title'] ? self::text( (string) $f['x_title'] ) : ( $x && ! $og ? self::text( (string) $f['title'] ) : '' );
			$sig['x_description'] = $x && '' !== (string) $f['x_desc'] ? self::text( (string) $f['x_desc'] ) : ( $x && ! $og ? self::text( (string) $f['desc'] ) : '' );
			$sig['x_image']       = $x && '' !== $x_image ? $x_image : ( $x && ! $og ? $image : '' );
		}
		$sig['x_title']       = '' !== $sig['x_title'] ? $sig['x_title'] : $sig['og_title'];
		$sig['x_description'] = '' !== $sig['x_description'] ? $sig['x_description'] : $sig['og_description'];
		$sig['x_image']       = '' !== $sig['x_image'] ? $sig['x_image'] : $sig['og_image'];

		$types = array();
		foreach ( \ZinnDigital\ZinnSEO\Head\Graph::nodes( $c ) as $node ) {
			self::types_of( $node, $types );
		}
		// What the page's own Structured data panel adds (Zinn® SEO Pro; the types carried over
		// from another plugin among them): outside a page view nothing has scanned the page.
		$library = '\ZinnDigital\ZinnSEO\Blocks\Library';
		if ( 'singular' === $c->kind && $c->post() && class_exists( $library ) ) {
			foreach ( $library::page_nodes( $c->post(), (string) get_permalink( $c->post() ) ) as $key => $node ) {
				if ( '@page' !== $key && is_array( $node ) ) {
					self::types_of( $node, $types );
				}
			}
		}
		// What the page prints beyond the graph worked out here: a WooCommerce product's Product
		// (added by the shop integration as the page is shown), and the FAQ and How-to blocks of
		// another SEO plugin read from the page (Blocks\Foreign). Missing, they were listed as
		// lost on every such page (queue Q254).
		$post = 'singular' === $c->kind ? $c->post() : null;
		if ( $post instanceof \WP_Post ) {
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) && is_object( wc_get_product( $post->ID ) ) ) {
				$types['Product'] = true;
			}
			if ( class_exists( '\ZinnDigital\ZinnSEO\Blocks\Foreign' ) && false !== strpos( (string) $post->post_content, '<!-- wp:' ) ) {
				foreach ( \ZinnDigital\ZinnSEO\Blocks\Foreign::nodes( parse_blocks( (string) $post->post_content ), (string) get_permalink( $post ), '' ) as $node ) {
					self::types_of( $node, $types );
				}
			}
		}
		$types = array_keys( $types );
		sort( $types );
		$sig['types'] = $types;

		return $sig;
	}

	/**
	 * The content of our robots tag for a page, as WordPress prints it.
	 *
	 * @param Context                    $c    The page.
	 * @param array<string, bool|string> $base What WordPress itself starts from.
	 * @return array<int, string>
	 */
	public static function robots_for( Context $c, array $base ): array {
		$robots = $base;
		foreach ( Resolver::robots( $c ) as $directive => $value ) {
			$robots[ $directive ] = $value;
		}
		if ( ! empty( $robots['noindex'] ) ) {
			unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
		}
		if ( isset( $robots['nosnippet'] ) ) {
			unset( $robots['max-snippet'] );
		}
		$out = array();
		foreach ( $robots as $directive => $value ) {
			if ( true === $value ) {
				$out[] = (string) $directive;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$out[] = $directive . ':' . $value;
			}
		}

		return array( implode( ',', $out ) );
	}

	/**
	 * Does a title begin with a full timestamp that is not the first day of the year archive it
	 * stands on?
	 *
	 * @param string $title   Their title.
	 * @param string $address The page's own address.
	 * @return bool
	 */
	public static function stray_timestamp( string $title, string $address ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}/', $title, $t ) ) {
			return false;
		}
		$path  = (string) wp_parse_url( $address, PHP_URL_PATH );
		$query = (string) wp_parse_url( $address, PHP_URL_QUERY );
		$year  = 1 === preg_match( '#/(\d{4})/?$#', $path, $y ) ? $y[1] : ( 1 === preg_match( '/(?:^|&)year=(\d{4})(?:&|$)/', $query, $y ) && false === strpos( $query, 'monthnum=' ) ? $y[1] : '' );

		return '' !== $year && $t[1] . '-' . $t[2] . '-' . $t[3] !== $year . '-01-01';
	}

	/**
	 * Where two signatures differ: field => [theirs, ours]. For `types` only what THEY printed
	 * and we do not.
	 *
	 * @param array<string, mixed> $theirs The other plugin's.
	 * @param array<string, mixed> $ours   Ours.
	 * @param string               $page   The kind of page (Context::$kind): `home`, `404`, `author`, … or ''.
	 * @param string               $own    The page's own address ('' when not known).
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function diff( array $theirs, array $ours, string $page = '', string $own = '' ): array {
		$out     = array();
		$noindex = in_array( 'noindex', (array) ( $theirs['robots'] ?? array() ), true );
		foreach ( self::FIELDS as $field ) {
			// Exception 2: a not-found page.
			if ( '404' === $page && ! in_array( $field, array( 'title', 'robots' ), true ) ) {
				continue;
			}
			$a = $theirs[ $field ] ?? ( 'robots' === $field || 'types' === $field ? array() : '' );
			$b = $ours[ $field ] ?? ( 'robots' === $field || 'types' === $field ? array() : '' );
			// Exception 1: a page that asks not to be indexed.
			if ( $noindex && in_array( $field, array( 'canonical', 'types' ), true ) ) {
				continue;
			}
			// Exception 6: a year archive whose title THEY began with a full timestamp of some
			// other day (Squirrly writes today's date there). Nobody chose that: ours names the
			// year. Look again if a plugin is found that means a timestamp on a year archive.
			if ( in_array( $field, array( 'title', 'og_title', 'x_title' ), true ) && self::stray_timestamp( (string) $a, $own ) ) {
				continue;
			}
			if ( 'types' === $field ) {
				$missing = array_values( array_diff( (array) $a, (array) $b ) );
				// Exception 4: WebPage is met by any of its kinds.
				if ( in_array( 'WebPage', $missing, true ) && array() !== array_intersect( (array) $b, self::PAGE_KINDS ) ) {
					$missing = array_values( array_diff( $missing, array( 'WebPage' ) ) );
				}
				// Exception 8: theirs named the article twice, as Article and as a kind of it
				// (Yoast SEO: "@type": ["Article", "BlogPosting"]); ours names the same kind.
				// A plain Article of theirs against our BlogPosting stays a difference (the
				// copy keeps theirs on the page).
				if ( in_array( 'Article', $missing, true ) && array() !== array_intersect( (array) $a, (array) $b, self::ARTICLE_KINDS ) ) {
					$missing = array_values( array_diff( $missing, array( 'Article' ) ) );
				}
				if ( 'author' === $page && in_array( 'ProfilePage', (array) $b, true ) ) {
					$missing = array_values( array_diff( $missing, array( 'CollectionPage' ) ) );
				}
				// Exception 3: a one-item trail on the home page.
				if ( 'home' === $page ) {
					$missing = array_values( array_diff( $missing, array( 'BreadcrumbList' ) ) );
				}
				if ( array() !== $missing ) {
					$out[ $field ] = array( $missing, array_values( (array) $b ) );
				}
				continue;
			}
			if ( 'robots' === $field ) {
				$a = array_values( (array) $a );
				$b = array_values( (array) $b );
				sort( $a );
				sort( $b );
				if ( $a !== $b ) {
					$out[ $field ] = array( $a, $b );
				}
				continue;
			}
			if ( 'canonical' === $field || 'og_image' === $field || 'x_image' === $field ) {
				// Exception 4: an avatar service's picture on an author archive.
				if ( 'author' === $page && 'canonical' !== $field && 1 === preg_match( '#^https?://([a-z0-9-]+\.)*gravatar\.com/#i', (string) $a ) ) {
					continue;
				}
				// Exception 7: a shared picture where the other plugin gave none (All in One SEO
				// on lists): something added, nothing a visitor or a crawler saw changed (queue
				// Q254 — listed as a difference, it held every such site's switch-off).
				if ( 'canonical' !== $field && '' === (string) $a ) {
					continue;
				}
				// No canonical tag and the page's own address are the same statement.
				$x = 'canonical' === $field && '' === (string) $a ? $own : (string) $a;
				$y = 'canonical' === $field && '' === (string) $b ? $own : (string) $b;
				// Exception 5: an image from the media library is the same file whatever host
				// address the other plugin stored with it (a site moved, or staging): compared
				// by its path inside the uploads folder. (Look again if images are served from
				// several hosts with different files under one path).
				if ( 'canonical' !== $field ) {
					$x = self::upload_path( $x );
					$y = self::upload_path( $y );
				}
				if ( self::address( $x ) !== self::address( $y ) ) {
					$out[ $field ] = array( (string) $a, (string) $b );
				}
				continue;
			}
			if ( (string) $a !== (string) $b ) {
				$out[ $field ] = array( (string) $a, (string) $b );
			}
		}

		return $out;
	}

	/**
	 * An uploads address reduced to its path from `/wp-content/uploads/` on; anything else as is.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function upload_path( string $url ): string {
		$at = strpos( $url, '/wp-content/uploads/' );

		return false === $at || 1 !== preg_match( '#^https?://#i', $url ) ? $url : substr( $url, $at );
	}

	/**
	 * An address for comparison: the same address written with another case of its percent
	 * escapes or host is the same address.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function address( string $url ): string {
		$url = trim( $url );
		// A site's root with and without its slash is one address.
		if ( 1 === preg_match( '#^https?://[^/?\#]+$#i', $url ) ) {
			$url .= '/';
		}
		$url = (string) preg_replace_callback( '/%[0-9a-f]{2}/i', static fn( array $m ): string => strtoupper( $m[0] ), $url );

		return (string) preg_replace_callback( '#^(https?://[^/]+)#i', static fn( array $m ): string => strtolower( $m[1] ), $url );
	}
}
