<?php
/**
 * Squirrly SEO as an import source.
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
 * Where Squirrly keeps things (read from its own code, release 14.2.7): per page in its own
 * table `{prefix}qss`, one row an address, found by `url_hash` — the MD5 of the post ID for a
 * post, of `category` / `tag` / `tax-<taxonomy>` followed by the term ID for a term — with the
 * values serialised in `seo`; everything site-wide as JSON in the option `sq_options`, where
 * `patterns[<kind>]` holds each kind of page's title and description templates.
 *
 * ⛔ Squirrly prints its tags only while its "METAs" feature is on (`sq_auto_metas`). With it
 * off, what is stored is not what the site shows, so nothing page-level is brought over: an
 * import must not make a title appear that visitors and search engines never saw.
 */
final class Squirrly extends Source {

	/** Its page types (lower case, as stored) => ours. */
	private const PAGE_KINDS = array(
		'about page'      => 'AboutPage',
		'contact page'    => 'ContactPage',
		'collection page' => 'CollectionPage',
		'faq page'        => 'FAQPage',
		'profile'         => 'ProfilePage',
		'question'        => 'QAPage',
		'real estate'     => 'RealEstateListing',
	);

	/** Its other per-page types => the schema.org type each prints. */
	private const THINGS = array(
		'accommodation'        => 'Accommodation',
		'book'                 => 'Book',
		'course'               => 'Course',
		'event'                => 'Event',
		'how to'               => 'HowTo',
		'job posting'          => 'JobPosting',
		'local restaurant'     => 'Restaurant',
		'local store'          => 'Store',
		'movie'                => 'Movie',
		'music'                => 'MusicRecording',
		'organization'         => 'Organization',
		'person'               => 'Person',
		'podcast'              => 'PodcastEpisode',
		'product'              => 'Product',
		'recipe'               => 'Recipe',
		'review'               => 'Review',
		'service'              => 'Service',
		'software application' => 'SoftwareApplication',
		'video'                => 'VideoObject',
		'video game'           => 'VideoGame',
	);

	/**
	 * Its options, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $options = null;

	/**
	 * Rows read ahead: `post:12` / `term:7` => the `seo` values.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * Does its table exist?
	 *
	 * @var bool|null
	 */
	private ?bool $table = null;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'squirrly';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Squirrly SEO';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'squirrly-seo/squirrly.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return false !== get_option( 'sq_options', false );
	}

	/**
	 * Its options.
	 *
	 * @return array<string, mixed>
	 */
	private function options(): array {
		if ( null === $this->options ) {
			$stored        = get_option( 'sq_options', '' );
			$decoded       = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
			$this->options = is_array( $decoded ) ? $decoded : array();
		}

		return $this->options;
	}

	/**
	 * Is one of its switches on?
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private function on( string $key ): bool {
		return ! empty( $this->options()[ $key ] );
	}

	/**
	 * Does its table exist?
	 *
	 * @return bool
	 */
	private function has_table(): bool {
		if ( null === $this->table ) {
			global $wpdb;
			$name = $wpdb->prefix . 'qss';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table check, once per request.
			$this->table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name;
		}

		return $this->table;
	}

	/**
	 * The separator of one of its patterns as a character.
	 *
	 * @param string $kind Pattern.
	 * @return string
	 */
	private function sep_char( string $kind ): string {
		$named = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
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
		$sep   = $this->options()['patterns'][ $kind ]['sep'] ?? '|';
		$sep   = is_scalar( $sep ) ? trim( (string) $sep ) : '|';

		return $named[ $sep ] ?? ( '' !== $sep ? html_entity_decode( $sep, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '|' );
	}

	/**
	 * One of its templates in our variables.
	 *
	 * @param string $text Template.
	 * @param string $kind The pattern it belongs to (`post`, `category`, `home`, …).
	 * @return string
	 */
	public function template( string $text, string $kind = 'post' ): string {
		// Its site separator is the home pattern's; a pattern with another one writes it out.
		$sep  = $this->sep_char( $kind ) === $this->sep_char( 'home' ) && '' !== self::separator( $this->sep_char( 'home' ) ) ? '%sep%' : $this->sep_char( $kind );
		$term = in_array( $kind, array( 'category', 'tag' ), true ) || 0 === strpos( $kind, 'tax-' );

		return $this->convert(
			$text,
			'/\{\{([a-z_]+)\}\}/i',
			array(
				'title'                => '%title%',
				'sitename'             => '%sitename%',
				'sitedesc'             => '%tagline%',
				'sep'                  => $sep,
				'page'                 => '%page%',
				'pagenumber'           => '%pagenumber%',
				'pagetotal'            => '%pagetotal%',
				// The home page's "excerpt" is the tagline.
				'excerpt'              => 'home' === $kind ? '%tagline%' : '%excerpt%',
				'excerpt_only'         => '%excerpt%',
				'post_description'     => '%excerpt%',
				'category'             => $term ? '%term%' : '%category%',
				'primary_category'     => '%category%',
				'tag'                  => $term ? '%term%' : '%ct_post_tag%',
				'term_title'           => '%term%',
				'term'                 => '%term%',
				'category_description' => '%term_description%',
				'tag_description'      => '%term_description%',
				'term_description'     => '%term_description%',
				'searchphrase'         => '%search%',
				'name'                 => '%author%',
				'user_description'     => '%excerpt%',
				// It writes dates in the machine form (2026-03-01T00:00:00+00:00).
				// A full timestamp of the archive's first day (month | day); a year archive keeps
				// the year: there Squirrly prints today's date, which is nobody's intent.
				'date'                 => '%date(c | c)%',
				'modified'             => '%modified(c)%',
				'post_year'            => '%year%',
				'currentyear'          => '%currentyear%',
				'currentmonth'         => '%currentmonth%',
				'pt_single'            => '%posttype%',
				'single'               => '%posttype%',
				'pt_plural'            => '%posttype_plural%',
				'plural'               => '%posttype_plural%',
				'parent_title'         => '%parent_title%',
				'id'                   => '%id%',
			)
		);
	}

	/**
	 * Read rows of its table by hash.
	 *
	 * @param array<string, string> $want Our key (`post:12`) => its `url_hash`.
	 * @return void
	 */
	private function read( array $want ): void {
		$want = array_diff_key( $want, $this->rows );
		if ( array() === $want ) {
			return;
		}
		if ( count( $this->rows ) > 2000 ) {
			$this->rows = array();
		}
		foreach ( array_keys( $want ) as $key ) {
			$this->rows[ $key ] = array();
		}
		if ( ! $this->has_table() || ! $this->on( 'sq_auto_metas' ) ) {
			return;
		}
		global $wpdb;
		$by_hash = array_flip( $want );
		$marks   = implode( ',', array_fill( 0, count( $want ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- its own table, by its hash index; $marks is a list of %s placeholders.
		$found = (array) $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, seo FROM %i WHERE blog_id = %d AND url_hash IN ({$marks})", array_merge( array( $wpdb->prefix . 'qss', get_current_blog_id() ), array_values( $want ) ) ) );
		foreach ( $found as $row ) {
			// Its own serialised array of scalars; never an object.
			// nosemgrep: php.lang.security.unserialize-use.unserialize-use -- the value is another plugin's own row of scalars and allowed_classes => false makes object injection impossible; re-check if allowed_classes is ever dropped or this reads a request.
			$seo = is_serialized( (string) $row->seo ) ? unserialize( (string) $row->seo, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reading another plugin's row; classes are refused.
			if ( is_array( $seo ) && isset( $by_hash[ (string) $row->url_hash ] ) ) {
				$this->rows[ $by_hash[ (string) $row->url_hash ] ] = $seo;
			}
		}
	}

	/**
	 * Read the rows of the next posts in one query.
	 *
	 * @param array<int, int> $post_ids Posts.
	 * @return void
	 */
	public function prime_posts( array $post_ids ): void {
		$want = array();
		foreach ( $post_ids as $id ) {
			$want[ 'post:' . (int) $id ] = md5( (string) (int) $id );
		}
		$this->read( $want );
	}

	/**
	 * The hash of a term's row.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	private static function term_hash( \WP_Term $term ): string {
		$kind = 'category' === $term->taxonomy ? 'category' : ( 'post_tag' === $term->taxonomy ? 'tag' : 'tax-' . $term->taxonomy );

		return md5( $kind . (int) $term->term_id );
	}

	/**
	 * Our fields from one of its rows.
	 *
	 * @param array<string, mixed> $seo  Its values.
	 * @param string               $kind The pattern the page belongs to.
	 * @return array<string, mixed>
	 */
	private function fields( array $seo, string $kind ): array {
		// A page it was told to leave alone prints nothing of its own.
		if ( array() === $seo || ( array_key_exists( 'doseo', $seo ) && ! $seo['doseo'] ) ) {
			return array();
		}
		$s   = static fn( string $key ): string => is_scalar( $seo[ $key ] ?? null ) ? trim( (string) $seo[ $key ] ) : '';
		$out = array(
			'title'     => $this->on( 'sq_auto_title' ) ? $this->template( $s( 'title' ), $kind ) : '',
			'desc'      => $this->on( 'sq_auto_description' ) ? $this->template( $s( 'description' ), $kind ) : '',
			'canonical' => $this->on( 'sq_auto_canonical' ) ? $s( 'canonical' ) : '',
			'robots'    => $this->on( 'sq_auto_noindex' ) ? self::robots(
				array(
					'noindex'  => '1' === $s( 'noindex' ),
					'nofollow' => '1' === $s( 'nofollow' ),
				)
			) : array(),
			'og_title'  => $this->template( $s( 'og_title' ), $kind ),
			'og_desc'   => $this->template( $s( 'og_description' ), $kind ),
			'og_image'  => self::image_id( $s( 'og_media' ) ),
			'x_title'   => $this->template( $s( 'tw_title' ), $kind ),
			'x_desc'    => $this->template( $s( 'tw_description' ), $kind ),
			'x_image'   => self::image_id( $s( 'tw_media' ) ),
		);
		if ( '' !== $s( 'redirect' ) && 1 === preg_match( '#^https?://#i', $s( 'redirect' ) ) && $this->on( 'sq_auto_redirects' ) ) {
			$out['redirect'] = array(
				'target' => $s( 'redirect' ),
				'code'   => in_array( (int) $s( 'redirect_type' ), array( 301, 302, 307, 308 ), true ) ? (int) $s( 'redirect_type' ) : 301,
			);
		}

		return self::filled( $out );
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		if ( ! isset( $this->rows[ 'post:' . $post_id ] ) ) {
			$this->prime_posts( array( $post_id ) );
		}
		$type = (string) get_post_type( $post_id );
		$row  = $this->rows[ 'post:' . $post_id ] ?? array();
		$out  = $this->fields( $row, isset( $this->options()['patterns'][ $type ] ) ? $type : 'custom' );
		// The types chosen for this page ("JSON-LD types" in its snippet; none = its automation).
		$alive  = array() !== $row && ( ! array_key_exists( 'doseo', $row ) || $row['doseo'] );
		$chosen = $alive && $this->on( 'sq_auto_jsonld' ) ? array_filter( array_map( 'strval', (array) ( $row['jsonld_types'] ?? array() ) ) ) : array();
		$nodes  = array();
		foreach ( $chosen as $name ) {
			$name = strtolower( trim( $name ) );
			if ( isset( self::PAGE_KINDS[ $name ] ) ) {
				$out['schema_page'] = self::PAGE_KINDS[ $name ];
			} elseif ( 'article' === $name || 'newsarticle' === $name ) {
				$out['schema_article'] = 'article' === $name ? 'Article' : 'NewsArticle';
			} elseif ( isset( self::THINGS[ $name ] ) ) {
				// Squirrly fills these from the page itself: its name and its address.
				$nodes[] = array(
					'@type' => self::THINGS[ $name ],
					'name'  => '%title%',
					'url'   => (string) get_permalink( $post_id ),
				);
			}
		}
		if ( array() !== $chosen && ! isset( $out['schema_article'] ) && array_intersect( array( 'article', 'newsarticle' ), array_map( 'strtolower', $chosen ) ) === array() ) {
			// It was told what this page is, and an article was not among it.
			$out['schema_article'] = 'none';
		}
		if ( array() !== $nodes ) {
			$out['schema_import'] = $this->schema_field( $nodes, $post_id );
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
		$key = 'term:' . $term->taxonomy . ':' . (int) $term->term_id;
		$this->read( array( $key => self::term_hash( $term ) ) );
		$kind = 'category' === $term->taxonomy ? 'category' : ( 'post_tag' === $term->taxonomy ? 'tag' : 'tax-' . $term->taxonomy );

		return $this->fields( $this->rows[ $key ] ?? array(), $kind );
	}

	/**
	 * A template record from one of its patterns.
	 *
	 * @param string $kind Pattern.
	 * @return array<string, mixed>
	 */
	private function record( string $kind ): array {
		$p = $this->options()['patterns'][ $kind ] ?? $this->options()['patterns']['custom'] ?? array();
		$p = is_array( $p ) ? $p : array();
		$s = static fn( string $key ): string => is_scalar( $p[ $key ] ?? null ) ? trim( (string) $p[ $key ] ) : '';

		$desc = $this->on( 'sq_auto_description' ) && '' !== $s( 'description' ) ? $this->template( $s( 'description' ), $kind ) : '%none%';

		return array(
			'title'  => $this->on( 'sq_auto_title' ) ? $this->template( $s( 'title' ), $kind ) : '',
			'desc'   => '' !== $desc ? $desc : '%none%',
			'index'  => $this->on( 'sq_auto_noindex' ) && ! empty( $p['noindex'] ) ? 'noindex' : 'index',
			'follow' => $this->on( 'sq_auto_noindex' ) && ! empty( $p['nofollow'] ) ? 'nofollow' : 'follow',
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o = $this->options();
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own strings.
		$search = html_entity_decode( sprintf( (string) __( 'Search Results for &#8220;%s&#8221;', 'default' ), '%search%' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lost   = (string) __( 'Page not found', 'default' );
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment
		if ( ! $this->on( 'sq_auto_metas' ) || ! is_array( $o['patterns'] ?? null ) ) {
			// It prints nothing: the site shows WordPress's own titles and no descriptions.
			$bare = array(
				'title' => '',
				'desc'  => '%none%',
			);
			$out  = array(
				'sep'       => '–',
				'templates' => array(
					'home'   => array( 'desc' => '%none%' ),
					'author' => $bare,
					'date'   => $bare,
					'search' => array( 'title' => $search . ' – %sitename%' ),
					'404'    => array( 'title' => $lost . ' – %sitename%' ),
				),
				'og'        => false,
				'x_cards'   => false,
			);
			foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
				$out['templates'][ 'post:' . $type ] = $bare;
			}
			foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
				$out['templates'][ 'tax:' . $tax ] = $bare;
			}
			return $out;
		}
		$out = array();
		if ( '' !== self::separator( $this->sep_char( 'home' ) ) ) {
			$out['sep'] = self::separator( $this->sep_char( 'home' ) );
		}
		$templates = array();
		$types     = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$kind                         = isset( $o['patterns'][ $type ] ) ? (string) $type : 'custom';
			$templates[ 'post:' . $type ] = $this->record( $kind );
			$json                         = array_map( 'strval', (array) ( $o['patterns'][ $kind ]['jsonld_types'] ?? array() ) );
			if ( $this->on( 'sq_auto_jsonld' ) ) {
				$types[ 'post:' . $type ] = array( 'article' => in_array( 'newsarticle', $json, true ) ? 'NewsArticle' : ( in_array( 'article', $json, true ) ? 'Article' : 'none' ) );
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			$kind = 'category' === $tax ? 'category' : ( 'post_tag' === $tax ? 'tag' : 'tax-' . $tax );
			if ( isset( $o['patterns'][ $kind ] ) ) {
				$templates[ 'tax:' . $tax ] = $this->record( $kind );
			}
		}
		// A content type's archive follows its "archive" pattern (the shop its "shop" one).
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $object ) {
			if ( $object->has_archive && 'post' !== $object->name ) {
				$kind = 'product' === $object->name && isset( $o['patterns']['shop'] ) ? 'shop' : 'archive';
				if ( isset( $o['patterns'][ $kind ] ) ) {
					$templates[ 'archive:' . $object->name ] = $this->record( $kind );
				}
			}
		}
		$templates['home']   = array_diff_key( $this->record( 'home' ), array( 'follow' => 1 ) );
		$templates['author'] = $this->record( 'profile' );
		$templates['date']   = $this->record( 'archive' );
		$templates['search'] = array_diff_key( $this->record( 'search' ), array( 'follow' => 1 ) );
		$templates['404']    = $this->record( '404' );
		$out['templates']    = $templates;
		if ( array() !== $types ) {
			$out['schema_types'] = $types;
		}

		$json   = (array) ( $o['sq_jsonld'] ?? array() );
		$person = 'Person' === (string) ( $o['sq_jsonld_type'] ?? '' );
		$who    = (array) ( $json[ $person ? 'Person' : 'Organization' ] ?? array() );
		$social = (array) ( $o['socials'] ?? array() );
		$codes  = $this->on( 'sq_auto_webmasters' ) ? (array) ( $o['codes'] ?? array() ) : array();
		$s      = static fn( array $from, string $key ): string => is_scalar( $from[ $key ] ?? null ) ? trim( (string) $from[ $key ] ) : '';
		// Its default for the site's X account is X's own ("twitter"): that is no account.
		$handle = 1 === preg_match( '#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})#i', $s( $social, 'twitter_site' ), $m ) && 'twitter' !== strtolower( $m[1] ) ? $m[1] : '';
		$logo   = $person ? ( $who['image']['url'] ?? '' ) : ( $who['logo']['url'] ?? '' );
		$out   += array(
			'og'               => $this->on( 'sq_auto_facebook' ),
			'x_cards'          => $this->on( 'sq_auto_twitter' ),
			'x_card'           => 'summary' === $s( $social, 'twitter_card_type' ) ? 'summary' : 'summary_large_image',
			'x_site'           => $handle,
			'fb_app_id'        => $s( $social, 'fbadminapp' ),
			'og_default_image' => self::image_id( is_scalar( $o['sq_og_image'] ?? null ) ? (string) $o['sq_og_image'] : '' ),
			// It shows the featured image and never looks inside the text.
			'og_content_image' => false,
			'identity_type'    => $person ? 'person' : 'organization',
			'identity_name'    => $s( $who, 'name' ),
			'identity_logo'    => self::image_id( is_scalar( $logo ) ? (string) $logo : '' ),
			'verify_google'    => $s( $codes, 'google_wt' ),
			'verify_bing'      => $s( $codes, 'bing_wt' ),
			'verify_yandex'    => $s( $codes, 'yandex_wt' ),
			'verify_baidu'     => $s( $codes, 'baidu_wt' ),
			'verify_pinterest' => $s( $codes, 'pinterest_verify' ),
		);
		$same   = array();
		foreach ( array( 'facebook_site', 'instagram_url', 'linkedin_url', 'pinterest_url', 'youtube_url' ) as $key ) {
			$same[] = $s( $social, $key );
		}
		if ( '' !== $handle ) {
			$same[] = 'https://x.com/' . $handle;
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );
		// The sharing tags it prints beyond the usual (queue Q254): the image's type, the
		// Facebook page as publisher, a product's price and stock.
		if ( $this->on( 'sq_auto_facebook' ) ) {
			$out['social_extras'] = array( 'image_type', 'publisher', 'product' );
		}
		// It prints previous/next links on pages kept out of search too.
		$out['adjacent_noindex'] = true;
		// The organisation's description, address and telephone it prints (its JSON-LD settings).
		if ( ! $person ) {
			$out['identity_description'] = $s( $who, 'description' );
			$addr                        = is_array( $who['address'] ?? null ) ? $who['address'] : array();
			$legal                       = array_filter(
				array(
					'streetAddress'   => $s( $addr, 'streetAddress' ),
					'addressLocality' => $s( $addr, 'addressLocality' ),
					'addressRegion'   => $s( $addr, 'addressRegion' ),
					'postalCode'      => $s( $addr, 'postalCode' ),
					'addressCountry'  => strtoupper( substr( $s( $addr, 'addressCountry' ), 0, 2 ) ),
				),
				static fn( string $v ): bool => '' !== $v
			);
			if ( array() !== $legal ) {
				$out['identity_legal'] = array( 'org' => $legal );
			}
			$phone = is_array( $who['contactPoint'] ?? null ) ? $s( $who['contactPoint'], 'telephone' ) : '';
			if ( '' !== $phone ) {
				$out['identity_contacts'] = array( 'customer-support' => array( 'telephone' => $phone ) );
			}
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
			__( 'Focus pages, briefcase keywords and audit scores live in your Squirrly account, not on this site, and are not copied.', 'zinn-seo' ),
		);
		if ( ! $this->on( 'sq_auto_metas' ) ) {
			$notes[] = __( 'Squirrly\'s "METAs" feature is switched off, so the titles and descriptions stored in it are not what this site shows. They are not copied: switch the feature on in Squirrly first if you want them.', 'zinn-seo' );
		}

		return $notes;
	}
}
