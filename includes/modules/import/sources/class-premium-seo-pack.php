<?php
/**
 * Premium SEO Pack as an import source (zs-725).
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
 * Where Premium SEO Pack keeps things (read from its own code, release 1.6.002): per page in its
 * own table `{prefix}psp`, one row an address, found by `url_hash` — the MD5 of the post ID (posts,
 * pages, products, media), of the post type followed by the ID (other content types), of
 * `category` / `tag` / `tax-<taxonomy>` followed by the term ID — with the values serialised in
 * `seo` (`title`, `description`, `keywords`, `canonical`, `noindex`, `nofollow`, `og_title`,
 * `og_description`, `og_media`, `tw_title`, `tw_description`, `tw_media`); its switches as JSON in
 * the option `_psp_options` (`psp_title_opt`, `psp_description_opt`, …), with `{{name}}`
 * variables. Each kind of value is brought over only while its switch was on: what is stored but
 * switched off is not what the site showed.
 */
final class Premium_Seo_Pack extends Source {

	/**
	 * Its options.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $options = null;

	/**
	 * Rows read: our key (`post:12`) => its values.
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
		return 'psp';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Premium SEO Pack';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'premium-seo-pack/index.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return false !== get_option( '_psp_options', false );
	}

	/**
	 * Its options.
	 *
	 * @return array<string, mixed>
	 */
	private function options(): array {
		if ( null === $this->options ) {
			$stored        = get_option( '_psp_options', '' );
			$decoded       = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
			$this->options = is_array( $decoded ) ? $decoded : array();
		}

		return $this->options;
	}

	/**
	 * Is a switch on (its default: on)?
	 *
	 * @param string $key Option.
	 * @return bool
	 */
	private function on( string $key ): bool {
		return ! array_key_exists( $key, $this->options() ) || ! empty( $this->options()[ $key ] );
	}

	/**
	 * One of its templates in our variables.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		return $this->convert( $text, '/\{\{([a-z_]+)\}\}/i', self::variables() );
	}

	/**
	 * One of its description patterns in our variables. It prints a description's separators
	 * as they stand, even with nothing before them ("| Site" when the excerpt is empty), and its
	 * page number after its own separator ("| Page 2 of 3 | Site"): so do these (IMP2).
	 *
	 * @param string $text Pattern.
	 * @return string
	 */
	public function description_template( string $text ): string {
		$vars         = self::variables();
		$vars['sep']  = '%sep(raw)%';
		$vars['page'] = '%page(sep)%';

		return $this->convert( $text, '/\{\{([a-z_]+)\}\}/i', $vars );
	}

	/**
	 * Its variables and ours.
	 *
	 * @return array<string, string>
	 */
	private static function variables(): array {
		return array(
			'title'                => '%title%',
			'sitename'             => '%sitename%',
			'sitedesc'             => '%tagline%',
			'sep'                  => '%sep%',
			// Its page number carries its own separator ("| Page 2 of 3").
			'page'                 => '%sep% %page%',
			'excerpt'              => '%excerpt%',
			'category'             => '%term%',
			'tag'                  => '%term%',
			'term_title'           => '%term%',
			'category_description' => '%term_description%',
			'searchphrase'         => '%search%',
			'name'                 => '%author%',
			'date'                 => '%date%',
		);
	}

	/**
	 * Read rows by hash, in one query.
	 *
	 * @param array<string, string> $want Our key => its `url_hash`.
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
		global $wpdb;
		$name = $wpdb->prefix . 'psp';
		if ( null === $this->table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table check, once per request.
			$this->table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name;
		}
		if ( ! $this->table ) {
			return;
		}
		$by_hash = array_flip( $want );
		$marks   = implode( ',', array_fill( 0, count( $want ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- its own table, by its hash index; $marks is a list of %s placeholders.
		$found = (array) $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, seo FROM %i WHERE blog_id = %d AND url_hash IN ({$marks})", array_merge( array( $name, get_current_blog_id() ), array_values( $want ) ) ) );
		foreach ( $found as $row ) {
			$raw = stripslashes( (string) $row->seo );
			// Its own serialised array of scalars; never an object.
			// nosemgrep: php.lang.security.unserialize-use.unserialize-use -- the value is another plugin's own row of scalars and allowed_classes => false makes object injection impossible; re-check if allowed_classes is ever dropped or this reads a request.
			$seo = is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reading another plugin's row; classes are refused.
			if ( is_array( $seo ) && isset( $by_hash[ (string) $row->url_hash ] ) ) {
				$this->rows[ $by_hash[ (string) $row->url_hash ] ] = $seo;
			}
		}
	}

	/**
	 * The hash of a post's row.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function post_hash( int $post_id ): string {
		$type = (string) get_post_type( $post_id );

		return in_array( $type, array( 'post', 'page', 'product', 'attachment' ), true ) ? md5( (string) $post_id ) : md5( $type . $post_id );
	}

	/**
	 * The hash of a term's row.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	public static function term_hash( \WP_Term $term ): string {
		$kind = 'category' === $term->taxonomy ? 'category' : ( 'post_tag' === $term->taxonomy ? 'tag' : 'tax-' . $term->taxonomy );

		return md5( $kind . (int) $term->term_id );
	}

	/**
	 * Read the rows of the next posts.
	 *
	 * @param array<int, int> $post_ids Posts.
	 * @return void
	 */
	public function prime_posts( array $post_ids ): void {
		$want = array();
		foreach ( $post_ids as $id ) {
			$want[ 'post:' . (int) $id ] = self::post_hash( (int) $id );
		}
		$this->read( $want );
	}

	/**
	 * Our fields from one of its rows.
	 *
	 * @param array<string, mixed> $seo Its values.
	 * @return array<string, mixed>
	 */
	private function fields( array $seo ): array {
		if ( array() === $seo || ( array_key_exists( 'doseo', $seo ) && ! $seo['doseo'] ) ) {
			return array();
		}
		$s = static fn( string $key ): string => is_scalar( $seo[ $key ] ?? null ) ? trim( (string) $seo[ $key ] ) : '';

		return self::filled(
			array(
				'title'     => $this->on( 'psp_title_opt' ) ? $this->template( $s( 'title' ) ) : '',
				'desc'      => $this->on( 'psp_description_opt' ) ? $this->template( $s( 'description' ) ) : '',
				'canonical' => $this->on( 'psp_canonical_opt' ) ? $s( 'canonical' ) : '',
				'robots'    => $this->on( 'psp_noindex_opt' ) ? self::robots(
					array(
						'noindex'  => '1' === $s( 'noindex' ),
						'nofollow' => '1' === $s( 'nofollow' ),
					)
				) : array(),
				'og_title'  => $this->on( 'psp_og_opt' ) ? $this->template( $s( 'og_title' ) ) : '',
				'og_desc'   => $this->on( 'psp_og_opt' ) ? $this->template( $s( 'og_description' ) ) : '',
				'og_image'  => $this->on( 'psp_og_opt' ) ? self::image_id( $s( 'og_media' ) ) : 0,
				'x_title'   => $this->on( 'psp_tw_opt' ) ? $this->template( $s( 'tw_title' ) ) : '',
				'x_desc'    => $this->on( 'psp_tw_opt' ) ? $this->template( $s( 'tw_description' ) ) : '',
				'x_image'   => $this->on( 'psp_tw_opt' ) ? self::image_id( $s( 'tw_media' ) ) : 0,
				'focus'     => implode( "\n", array_filter( array_map( 'trim', explode( ',', $s( 'keywords' ) ) ) ) ),
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
		if ( ! isset( $this->rows[ 'post:' . $post_id ] ) ) {
			$this->prime_posts( array( $post_id ) );
		}

		return $this->fields( $this->rows[ 'post:' . $post_id ] ?? array() );
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		$key = 'term:' . (int) $term->term_id;
		$this->read( array( $key => self::term_hash( $term ) ) );

		return $this->fields( $this->rows[ $key ] ?? array() );
	}

	/**
	 * Its title and description patterns: what it stores over the defaults it ships (read from
	 * its own code, PSP_Classes_Tools::getOptions(), at its closure). Its separator is the
	 * pattern's own; ours is the site's.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function patterns(): array {
		// Its own defaults in the English it ships (another plugin's text domain is not ours to
		// call: WordPress.org's rule; a site that translated them keeps its stored patterns).
		$defaults = array(
			'home'     => array( '{{sitename}} {{page}} {{sep}} {{sitedesc}}', '{{excerpt}} {{page}} {{sep}} {{sitename}}', 0 ),
			'post'     => array( '{{title}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
			'page'     => array( '{{title}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
			'category' => array( '{{category}} {{page}} {{sep}} {{sitename}}', '{{category_description}}', 0 ),
			'tag'      => array( '{{tag}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
			'profile'  => array( '{{name}}, Author at {{sitename}} {{page}}', '{{excerpt}}', 0 ),
			'shop'     => array( '{{title}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
			'product'  => array( '{{title}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
			'archive'  => array( '{{date}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
			'search'   => array( 'You searched for {{searchphrase}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 1 ),
			'404'      => array( 'Page not found {{sep}} {{sitename}}', '{{excerpt}}', 1, 1 ),
			'custom'   => array( '{{title}} {{page}} {{sep}} {{sitename}}', '{{excerpt}}', 0 ),
		);
		$stored   = is_array( $this->options()['patterns'] ?? null ) ? $this->options()['patterns'] : array();
		$out      = array();
		foreach ( $defaults as $kind => $d ) {
			$out[ $kind ] = array(
				'title'       => $d[0],
				'description' => $d[1],
				'noindex'     => $d[2],
				'nofollow'    => $d[3] ?? 0,
			);
		}
		foreach ( $stored as $kind => $p ) {
			if ( is_array( $p ) ) {
				$out[ (string) $kind ] = $p + ( $out[ (string) $kind ] ?? array() );
			}
		}

		return $out;
	}

	/**
	 * One of its patterns as our template record.
	 *
	 * @param array<string, mixed> $p Pattern.
	 * @return array<string, mixed>
	 */
	private function record( array $p ): array {
		$s   = static fn( string $key ): string => is_scalar( $p[ $key ] ?? null ) ? trim( (string) $p[ $key ] ) : '';
		$out = array(
			'index'  => ! empty( $p['noindex'] ) ? 'noindex' : 'index',
			'follow' => ! empty( $p['nofollow'] ) ? 'nofollow' : 'follow',
		);
		if ( $this->on( 'psp_title_opt' ) && '' !== $s( 'title' ) ) {
			$out['title'] = $this->template( $s( 'title' ) );
		}
		if ( $this->on( 'psp_description_opt' ) && '' !== $s( 'description' ) ) {
			$out['desc'] = $this->description_template( $s( 'description' ) );
		}

		return $out;
	}

	/**
	 * Every kind of page's template from its patterns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function templates(): array {
		$p   = $this->patterns();
		$out = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$out[ 'post:' . $type ] = $this->record( $p[ (string) $type ] ?? $p['custom'] );
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			$kind = 'category' === $tax ? 'category' : ( 'post_tag' === $tax ? 'tag' : 'tax-' . $tax );
			if ( isset( $p[ $kind ] ) ) {
				$out[ 'tax:' . $tax ] = $this->record( $p[ $kind ] );
			}
		}
		if ( isset( $p['shop'] ) ) {
			$out['archive:product'] = $this->record( $p['shop'] );
		}
		$out['home']   = $this->record( $p['home'] );
		$out['author'] = $this->record( $p['profile'] );
		$out['date']   = $this->record( $p['archive'] );
		$out['search'] = $this->record( $p['search'] );
		$out['404']    = $this->record( $p['404'] );

		return $out;
	}

	/**
	 * Site-wide settings: its separator and X account.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o   = $this->options();
		$out = array();
		if ( $this->on( 'psp_title_opt' ) || $this->on( 'psp_description_opt' ) ) {
			$out['templates'] = $this->templates();
		}
		// It prints a product's price and stock for sharing, and the image's type.
		if ( $this->on( 'psp_og_opt' ) ) {
			$out['social_extras'] = array( 'product' );
		}
		// No previous/next links on the home page's list (it prints them on other lists).
		$out['adjacent_links'] = $this->on( 'psp_prevnext_opt' );
		$sep                   = is_scalar( $o['psp_separator'] ?? null ) ? trim( (string) $o['psp_separator'] ) : '';
		if ( '' !== $sep ) {
			$out['sep'] = $sep;
		}
		$handle = is_scalar( $o['psp_tw_account'] ?? null ) ? self::x_handle( (string) $o['psp_tw_account'] ) : '';
		if ( '' !== $handle ) {
			$out['x_site'] = $handle;
		}

		return $out;
	}
}
