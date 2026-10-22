<?php
/**
 * All in One SEO (and Pro) as an import source.
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
 * Where All in One SEO keeps things (read from its own code, release 5.0.3): per post in its own
 * table `{prefix}aioseo_posts` (one row a post; Pro adds `{prefix}aioseo_terms`); settings as
 * JSON in the options `aioseo_options` (site-wide) and `aioseo_options_dynamic` (per content type
 * and taxonomy); redirects in its own table (read by the redirects module).
 *
 * Its variables are `#words`, which ordinary text uses too ("#1 plumber"), so only the names it
 * defines are converted and anything else is left as written.
 */
final class Aioseo extends Source {

	/**
	 * Rows read ahead: post id => row.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * Term rows read ahead (Pro).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $term_rows = array();

	/**
	 * Does a table exist: name => bool.
	 *
	 * @var array<string, bool>
	 */
	private array $tables = array();

	/**
	 * The decoded options: [site-wide, dynamic].
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $options = null;

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
		return 'aioseo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'All in One SEO';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return is_string( get_option( 'aioseo_options', null ) ) || $this->has_table( 'aioseo_posts' );
	}

	/**
	 * Redirects through the redirects module.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'aioseo';
	}

	/**
	 * Does one of its tables exist?
	 *
	 * @param string $name Name without the prefix.
	 * @return bool
	 */
	private function has_table( string $name ): bool {
		if ( ! isset( $this->tables[ $name ] ) ) {
			global $wpdb;
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table check, once per request.
			$this->tables[ $name ] = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}

		return $this->tables[ $name ];
	}

	/**
	 * The options: [site-wide, dynamic].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function options(): array {
		if ( null === $this->options ) {
			$this->options = array();
			foreach ( array( 'aioseo_options', 'aioseo_options_dynamic' ) as $name ) {
				$stored          = get_option( $name, '' );
				$decoded         = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
				$this->options[] = is_array( $decoded ) ? $decoded : array();
			}
		}

		return $this->options;
	}

	/**
	 * A value at a path of an options tree.
	 *
	 * @param array<string, mixed> $tree Tree.
	 * @param string               $path `a.b.c`.
	 * @return mixed
	 */
	private static function at( array $tree, string $path ) {
		$node = $tree;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}
			$node = $node[ $key ];
		}

		return $node;
	}

	/**
	 * A string at a path.
	 *
	 * @param array<string, mixed> $tree Tree.
	 * @param string               $path Path.
	 * @return string
	 */
	private static function str( array $tree, string $path ): string {
		$value = self::at( $tree, $path );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * One of its templates in our variables.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		if ( null === $this->sep ) {
			$char      = html_entity_decode( self::str( $this->options()[0], 'searchAppearance.global.separator' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$char      = '' !== $char ? $char : '-';
			$this->sep = '' !== self::separator( $char ) ? '%sep%' : $char;
		}

		return $this->convert(
			$text,
			'/#([a-z_]+)(?:-([A-Za-z0-9_-]+))?/',
			array(
				'post_title'           => '%title%',
				'archive_title'        => '%title%',
				'blog_title'           => '%sitename%',
				'site_title'           => '%sitename%',
				'tagline'              => '%tagline%',
				'site_description'     => '%tagline%',
				'separator_sa'         => $this->sep,
				'post_excerpt'         => '%excerpt%',
				'post_excerpt_only'    => '%excerpt%',
				'post_content'         => '%excerpt%',
				'description'          => '%excerpt%',
				'taxonomy_title'       => '%term%',
				'taxonomy_description' => '%term_description%',
				'author_name'          => '%author%',
				'author_bio'           => '%excerpt%',
				// It writes a month archive as "March, 2026".
				'archive_date'         => '%date(F, Y)%',
				'post_date'            => '%date%',
				'post_modified_date'   => '%modified%',
				'post_year'            => '%year%',
				'search_term'          => '%search%',
				'current_year'         => '%currentyear%',
				'current_month'        => '%currentmonth%',
				'page_number'          => '%pagenumber%',
				'categories'           => '%category%',
				'category'             => '%category%',
				'parent_title'         => '%parent_title%',
			),
			array(
				'custom_field' => static fn( string $rest, array $m ): string => self::named( 'cf', (string) ( $m[2] ?? '' ) ),
				'tax_name'     => static fn( string $rest, array $m ): string => self::named( 'ct', (string) ( $m[2] ?? '' ) ),
			),
			true
		);
	}

	/**
	 * Read the rows of the next posts in one query.
	 *
	 * @param array<int, int> $post_ids Posts.
	 * @return void
	 */
	public function prime_posts( array $post_ids ): void {
		$post_ids = array_values( array_diff( array_map( 'intval', $post_ids ), array_keys( $this->rows ) ) );
		if ( array() === $post_ids || ! $this->has_table( 'aioseo_posts' ) ) {
			return;
		}
		if ( count( $this->rows ) > 2000 ) {
			$this->rows = array();
		}
		foreach ( $post_ids as $id ) {
			$this->rows[ $id ] = array();
		}
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $post_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- its own table, by its post index; $in is a list of integers.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE post_id IN ({$in})", $wpdb->prefix . 'aioseo_posts' ), ARRAY_A ) as $row ) {
			$this->rows[ (int) $row['post_id'] ] = $row;
		}
	}

	/**
	 * Read the rows of the next terms in one query (Pro).
	 *
	 * @param array<int, int> $term_ids Terms.
	 * @return void
	 */
	public function prime_terms( array $term_ids ): void {
		$term_ids = array_values( array_diff( array_map( 'intval', $term_ids ), array_keys( $this->term_rows ) ) );
		if ( array() === $term_ids || ! $this->has_table( 'aioseo_terms' ) ) {
			return;
		}
		if ( count( $this->term_rows ) > 2000 ) {
			$this->term_rows = array();
		}
		foreach ( $term_ids as $id ) {
			$this->term_rows[ $id ] = array();
		}
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $term_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- its own table; $in is a list of integers.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE term_id IN ({$in})", $wpdb->prefix . 'aioseo_terms' ), ARRAY_A ) as $row ) {
			$this->term_rows[ (int) $row['term_id'] ] = $row;
		}
	}

	/**
	 * Our fields from one of its rows.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function fields( array $row ): array {
		$s   = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? trim( (string) $row[ $key ] ) : '';
		$on  = static fn( string $key ): bool => '1' === $s( $key );
		$out = array(
			'title'     => $this->template( $s( 'title' ) ),
			'desc'      => $this->template( $s( 'description' ) ),
			'canonical' => $s( 'canonical_url' ),
			'og_title'  => $this->template( $s( 'og_title' ) ),
			'og_desc'   => $this->template( $s( 'og_description' ) ),
		);
		if ( 'custom_image' === $s( 'og_image_type' ) ) {
			$out['og_image'] = self::image_id( $s( 'og_image_custom_url' ) );
		}
		if ( ! $on( 'twitter_use_og' ) ) {
			$out['x_title'] = $this->template( $s( 'twitter_title' ) );
			$out['x_desc']  = $this->template( $s( 'twitter_description' ) );
			if ( 'custom_image' === $s( 'twitter_image_type' ) ) {
				$out['x_image'] = self::image_id( $s( 'twitter_image_custom_url' ) );
			}
		}
		// `robots_default` = 1 means "as the content type says".
		if ( '0' === $s( 'robots_default' ) ) {
			$out['robots']      = self::robots(
				array(
					'noindex'      => $on( 'robots_noindex' ),
					'index'        => ! $on( 'robots_noindex' ),
					'nofollow'     => $on( 'robots_nofollow' ),
					'follow'       => ! $on( 'robots_nofollow' ),
					'noarchive'    => $on( 'robots_noarchive' ),
					'nosnippet'    => $on( 'robots_nosnippet' ),
					'noimageindex' => $on( 'robots_noimageindex' ),
				)
			);
			$out['max_snippet'] = $s( 'robots_max_snippet' );
			$out['max_video']   = $s( 'robots_max_videopreview' );
			$out['max_image']   = $s( 'robots_max_imagepreview' );
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
		if ( ! isset( $this->rows[ $post_id ] ) ) {
			$this->prime_posts( array( $post_id ) );
		}
		$row = $this->rows[ $post_id ] ?? array();
		if ( array() === $row ) {
			return array();
		}
		$out     = $this->fields( $row );
		$phrases = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
		$lines   = array();
		if ( is_array( $phrases ) ) {
			$lines[] = trim( (string) ( $phrases['focus']['keyphrase'] ?? '' ) );
			foreach ( (array) ( $phrases['additional'] ?? array() ) as $more ) {
				$lines[] = trim( (string) ( $more['keyphrase'] ?? '' ) );
			}
		}
		$out['focus']       = implode( "\n", array_values( array_unique( array_filter( $lines ) ) ) );
		$out['cornerstone'] = '1' === (string) ( $row['pillar_content'] ?? '' );
		$primary            = json_decode( (string) ( $row['primary_term'] ?? '' ), true );
		if ( is_array( $primary ) && isset( $primary['category'] ) ) {
			$out['primary_term'] = (int) $primary['category'];
		}
		$nodes = self::graphs( $row );
		if ( array() !== $nodes ) {
			$out['schema_import'] = $this->schema_field( $nodes, $post_id );
		}

		return self::filled( $out );
	}

	/**
	 * The types All in One SEO Pro stored for a post, each as the node search engines read.
	 *
	 * Two layouts exist in its table: since 4.2.5 the `schema` column (JSON) holds `graphs`, a
	 * list of `{ graphName, properties }`; before that `schema_type` named one type and
	 * `schema_type_options` held its answers under the type's key. Lists are JSON text of
	 * `{ label, value }` rows in both. Article and page types are the page's own type and are
	 * read elsewhere.
	 *
	 * @param array<string, mixed> $row Its row for the post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function graphs( array $row ): array {
		$nodes  = array();
		$schema = json_decode( (string) ( $row['schema'] ?? '' ), true );
		foreach ( is_array( $schema ) ? (array) ( $schema['graphs'] ?? array() ) : array() as $graph ) {
			if ( is_array( $graph ) && is_string( $graph['graphName'] ?? null ) ) {
				$node = self::graph( $graph['graphName'], (array) ( $graph['properties'] ?? array() ) );
				if ( array() !== $node ) {
					$nodes[] = $node;
				}
			}
		}
		if ( array() === $nodes && in_array( (string) ( $row['schema_type'] ?? '' ), array( 'SoftwareApplication', 'Product', 'Recipe', 'Course' ), true ) ) {
			$type    = (string) $row['schema_type'];
			$options = json_decode( (string) ( $row['schema_type_options'] ?? '' ), true );
			$key     = 'SoftwareApplication' === $type ? 'software' : strtolower( $type );
			$node    = self::graph( $type, is_array( $options ) ? (array) ( $options[ $key ] ?? array() ) : array() );
			if ( array() !== $node ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * One of its types and answers as a node.
	 *
	 * @param string               $type Its graph name (a schema.org type).
	 * @param array<string, mixed> $p    Its answers.
	 * @return array<string, mixed>
	 */
	private static function graph( string $type, array $p ): array {
		if ( 1 !== preg_match( '/^[A-Z][A-Za-z]{1,40}$/', $type ) || in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'FAQPage', 'ItemPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ProfilePage', 'RealEstateListing', 'SearchResultsPage', 'MedicalWebPage', 'CheckoutPage' ), true ) ) {
			return array();
		}
		$s      = static fn( string $key ): string => is_scalar( $p[ $key ] ?? null ) ? trim( (string) $p[ $key ] ) : '';
		$in     = static fn( string $a, string $b ): string => is_array( $p[ $a ] ?? null ) && is_scalar( $p[ $a ][ $b ] ?? null ) ? trim( (string) $p[ $a ][ $b ] ) : '';
		$values = static function ( $json ): array {
			$list = is_string( $json ) ? json_decode( $json, true ) : $json;
			$out  = array();
			foreach ( is_array( $list ) ? $list : array() as $item ) {
				$item = is_string( $item ) && '{' === substr( $item, 0, 1 ) ? json_decode( $item, true ) : $item;
				$text = is_array( $item ) ? ( $item['value'] ?? ( $item['text'] ?? ( $item['content'] ?? ( $item['name'] ?? '' ) ) ) ) : $item;
				if ( is_scalar( $text ) && '' !== trim( (string) $text ) ) {
					$out[] = trim( (string) $text );
				}
			}
			return $out;
		};
		$price  = '' !== $s( 'price' ) ? $s( 'price' ) : $in( 'offer', 'price' );
		$node   = array(
			'@type'       => $type,
			'name'        => $s( 'name' ),
			'description' => $s( 'description' ),
			'image'       => $s( 'image' ),
			'offers'      => '' === $price ? '' : array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => '' !== $s( 'currency' ) ? $s( 'currency' ) : $in( 'offer', 'currency' ),
			),
		);
		switch ( $type ) {
			case 'Recipe':
				$minutes = static fn( string $m ): string => '' !== $m ? 'PT' . (int) $m . 'M' : '';
				$node   += array(
					'recipeCategory'     => $s( 'dishType' ),
					'recipeCuisine'      => $s( 'cuisineType' ),
					'prepTime'           => $minutes( '' !== $s( 'preparationTime' ) ? $s( 'preparationTime' ) : $in( 'timeRequired', 'preparation' ) ),
					'cookTime'           => $minutes( '' !== $s( 'cookingTime' ) ? $s( 'cookingTime' ) : $in( 'timeRequired', 'cooking' ) ),
					'recipeYield'        => '' !== $s( 'servings' ) ? $s( 'servings' ) : $in( 'nutrition', 'servings' ),
					'nutrition'          => array( 'calories' => '' !== $s( 'calories' ) ? $s( 'calories' ) : $in( 'nutrition', 'calories' ) ),
					'keywords'           => implode( ', ', $values( $p['keywords'] ?? '' ) ),
					'recipeIngredient'   => $values( $p['ingredients'] ?? '' ),
					'recipeInstructions' => $values( $p['instructions'] ?? array() ),
				);
				break;
			case 'Course':
				$node['provider'] = array( 'name' => $s( 'provider' ) );
				break;
			case 'SoftwareApplication':
				$node += array(
					'applicationCategory' => $s( 'category' ),
					'operatingSystem'     => '' !== $s( 'operatingSystem' ) ? $s( 'operatingSystem' ) : implode( ', ', $values( $p['operatingSystems'] ?? '' ) ),
				);
				break;
			case 'Product':
				$node += array(
					'sku'   => $s( 'sku' ),
					'gtin'  => '' !== $s( 'gtin' ) ? $s( 'gtin' ) : $s( 'identifier' ),
					'brand' => '' !== $s( 'brand' ) ? array(
						'@type' => 'Brand',
						'name'  => $s( 'brand' ),
					) : '',
				);
				break;
			case 'Service':
				$node += array(
					'serviceType' => $s( 'serviceType' ),
					'areaServed'  => $s( 'area' ),
				);
				break;
			case 'Book':
				$node += array(
					'author' => $s( 'author' ),
					'isbn'   => $s( 'isbn' ),
				);
				break;
			case 'Movie':
				$node += array(
					'director'    => $s( 'director' ),
					'dateCreated' => $s( 'releaseDate' ),
				);
				break;
			case 'Person':
				$node += array(
					'email'    => $s( 'email' ),
					'jobTitle' => $s( 'jobTitle' ),
				);
				break;
		}

		return $node;
	}

	/**
	 * A term (Pro).
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		$id = (int) $term->term_id;
		if ( ! isset( $this->term_rows[ $id ] ) ) {
			$this->prime_terms( array( $id ) );
		}
		$row = $this->term_rows[ $id ] ?? array();

		return array() === $row ? array() : self::filled( $this->fields( $row ) );
	}

	/**
	 * A template record from one of its settings nodes (`title`, `metaDescription`, `advanced.robotsMeta`, `show`).
	 *
	 * @param mixed $node   The node.
	 * @param array $site Its site-wide robots choice.
	 * @return array<string, mixed>
	 */
	private function record( $node, array $site ): array {
		$node   = is_array( $node ) ? $node : array();
		$robots = (array) self::at( $node, 'advanced.robotsMeta' );
		if ( array() === $robots || ! empty( $robots['default'] ) ) {
			$robots = $site;
		}
		$desc   = self::str( $node, 'metaDescription' );
		$record = array(
			'title'  => $this->template( self::str( $node, 'title' ) ),
			'desc'   => '' !== $desc ? $this->template( $desc ) : '%none%',
			'index'  => ! empty( $robots['noindex'] ) || ( array_key_exists( 'show', $node ) && false === $node['show'] ) ? 'noindex' : 'index',
			'follow' => ! empty( $robots['nofollow'] ) ? 'nofollow' : 'follow',
		);
		$extra  = array();
		foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $flag ) {
			if ( ! empty( $robots[ $flag ] ) ) {
				$extra[] = $flag;
			}
		}
		$record['extra'] = $extra;

		return $record;
	}

	/**
	 * A name that may hold its `#site_title` and `#tagline`.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function name_of( string $text ): string {
		return trim( str_replace( array( '#site_title', '#tagline' ), array( (string) get_bloginfo( 'name' ), (string) get_bloginfo( 'description' ) ), $text ) );
	}

	/**
	 * A user: the profiles All in One SEO adds to the user record (user meta `aioseo_*_url`, and
	 * `aioseo_profiles_additional_urls`, one address per line). It keeps no title or description
	 * per author: author pages follow its author template.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array {
		$id   = (int) $user->ID;
		$more = array();
		foreach ( array( 'instagram', 'linkedin', 'myspace', 'pinterest', 'sound_cloud', 'tumblr', 'wikipedia', 'youtube', 'bluesky', 'threads' ) as $key ) {
			$more[] = self::um( $id, 'aioseo_' . $key . '_url' );
		}
		foreach ( (array) preg_split( '/\s+/', self::um( $id, 'aioseo_profiles_additional_urls' ) ) as $url ) {
			$more[] = $url;
		}

		return self::filled(
			array(
				'x'        => self::x_handle( self::um( $id, 'aioseo_twitter_url' ) ),
				'facebook' => self::facebook_url( self::um( $id, 'aioseo_facebook_page_url' ) ),
				'same_as'  => self::profile_lines( $more ),
			)
		);
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		list( $o, $d ) = $this->options();
		if ( array() === $o ) {
			return array( 'templates' => array() );
		}
		$out  = array();
		$char = html_entity_decode( self::str( $o, 'searchAppearance.global.separator' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' !== self::separator( $char ) ) {
			$out['sep'] = self::separator( $char );
		}
		$site = (array) self::at( $o, 'searchAppearance.advanced.globalRobotsMeta' );
		if ( ! empty( $site['default'] ) ) {
			$site = array();
		}
		$templates = array();
		$types     = array();
		$scores    = array();
		foreach ( (array) self::at( $d, 'searchAppearance.postTypes' ) as $type => $node ) {
			if ( ! post_type_exists( (string) $type ) ) {
				continue;
			}
			// Its "Show AIOSEO Meta Box" off for a type: it never scored that type (Q277).
			if ( false === self::at( (array) $node, 'advanced.showMetaBox' ) ) {
				$scores[ 'post:' . $type ] = array( 'scores' => 'off' );
			}
			$templates[ 'post:' . $type ] = $this->record( $node, $site );
			$schema                       = self::str( (array) $node, 'schemaType' );
			$article                      = self::str( (array) $node, 'articleType' );
			$page                         = self::str( (array) $node, 'webPageType' );
			$types[ 'post:' . $type ]     = array(
				'article' => 'Article' === $schema && '' !== $article ? $article : 'none',
				'page'    => 'WebPage' === $page ? '' : $page,
			);
		}
		foreach ( (array) self::at( $d, 'searchAppearance.taxonomies' ) as $tax => $node ) {
			if ( taxonomy_exists( (string) $tax ) ) {
				$templates[ 'tax:' . $tax ] = $this->record( $node, $site );
			}
		}
		foreach ( (array) self::at( $d, 'searchAppearance.archives' ) as $type => $node ) {
			if ( post_type_exists( (string) $type ) ) {
				$templates[ 'archive:' . $type ] = $this->record( $node, $site );
			}
		}
		$home              = array(
			'title'    => $this->template( self::str( $o, 'searchAppearance.global.siteTitle' ) ),
			'desc'     => '' !== self::str( $o, 'searchAppearance.global.metaDescription' ) ? $this->template( self::str( $o, 'searchAppearance.global.metaDescription' ) ) : '%none%',
			'og_title' => $this->template( self::str( $o, 'social.facebook.homePage.title' ) ),
			'og_desc'  => $this->template( self::str( $o, 'social.facebook.homePage.description' ) ),
			'og_image' => self::image_id( self::str( $o, 'social.facebook.homePage.image' ) ),
		);
		$templates['home'] = $home;
		foreach ( array( 'author', 'date', 'search' ) as $kind ) {
			$templates[ $kind ] = $this->record( self::at( $o, 'searchAppearance.archives.' . $kind ), $site );
		}
		unset( $templates['search']['desc'], $templates['search']['follow'], $templates['search']['extra'] );
		// It leaves the not-found page's title to WordPress: "Page not found – Site".
		$templates['404']    = array( 'title' => '%title% – %sitename%' );
		$out['templates']    = $templates;
		$out['schema_types'] = $types;
		if ( array() !== $scores ) {
			$out['content_types'] = $scores;
		}

		if ( array() !== $site ) {
			$out['robots_max_snippet'] = is_scalar( $site['maxSnippet'] ?? null ) ? (string) $site['maxSnippet'] : '';
			$out['robots_max_video']   = is_scalar( $site['maxVideoPreview'] ?? null ) ? (string) $site['maxVideoPreview'] : '';
			if ( in_array( $site['maxImagePreview'] ?? '', array( 'none', 'standard', 'large' ), true ) ) {
				$out['robots_max_image'] = (string) $site['maxImagePreview'];
			}
		}

		// Which picture a shared post shows when it has none of its own.
		$source = self::str( $o, 'social.facebook.general.defaultImageSourcePosts' );
		$person = 'person' === self::str( $o, 'searchAppearance.global.schema.siteRepresents' );
		$x_url  = self::str( $o, 'social.profiles.urls.twitterUrl' );
		$handle = 1 === preg_match( '#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})#i', $x_url, $m ) ? $m[1] : '';
		$out   += array(
			'og'                   => false !== self::at( $o, 'social.facebook.general.enable' ),
			'x_cards'              => false !== self::at( $o, 'social.twitter.general.enable' ),
			'x_card'               => 'summary' === self::str( $o, 'social.twitter.general.defaultCardType' ) ? 'summary' : 'summary_large_image',
			'x_site'               => $handle,
			'fb_app_id'            => self::str( $o, 'social.facebook.advanced.appId' ),
			'og_featured_image'    => in_array( $source, array( 'featured', 'auto' ), true ),
			'og_content_image'     => in_array( $source, array( 'content', 'attach', 'auto' ), true ),
			'og_default_image'     => self::image_id( self::str( $o, 'social.facebook.general.defaultImagePosts' ) ),
			'identity_type'        => $person ? 'person' : 'organization',
			'identity_name'        => self::name_of( self::str( $o, $person ? 'searchAppearance.global.schema.personName' : 'searchAppearance.global.schema.organizationName' ) ),
			'identity_person'      => $person && is_numeric( self::at( $o, 'searchAppearance.global.schema.person' ) ) ? (int) self::at( $o, 'searchAppearance.global.schema.person' ) : 0,
			'identity_logo'        => self::image_id( self::str( $o, $person ? 'searchAppearance.global.schema.personLogo' : 'searchAppearance.global.schema.organizationLogo' ) ),
			'identity_description' => $person ? '' : self::name_of( self::str( $o, 'searchAppearance.global.schema.organizationDescription' ) ),
			'identity_founded'     => self::str( $o, 'searchAppearance.global.schema.foundingDate' ),
			'verify_google'        => self::str( $o, 'webmasterTools.google' ),
			'verify_bing'          => self::str( $o, 'webmasterTools.bing' ),
			'verify_yandex'        => self::str( $o, 'webmasterTools.yandex' ),
			'verify_baidu'         => self::str( $o, 'webmasterTools.baidu' ),
			'verify_pinterest'     => self::str( $o, 'webmasterTools.pinterest' ),
		);
		$same   = array();
		foreach ( (array) self::at( $o, 'social.profiles.urls' ) as $url ) {
			$same[] = is_scalar( $url ) ? trim( (string) $url ) : '';
		}
		// Its extra profiles: one address a line.
		foreach ( preg_split( '/\s+/', self::str( $o, 'social.profiles.additionalUrls' ) ) as $url ) {
			$same[] = (string) $url;
		}
		$out['identity_same_as'] = array_values( array_unique( array_filter( $same ) ) );
		// It adds " | Page 2" to the title and description of page 2 and on of every list.
		foreach ( $out['templates'] as $key => $record ) {
			if ( in_array( $key, array( 'home', 'author', 'date', 'search' ), true ) || 0 === strpos( (string) $key, 'tax:' ) || 0 === strpos( (string) $key, 'archive:' ) ) {
				foreach ( array( 'title', 'desc', 'og_title', 'og_desc' ) as $field ) {
					$text = (string) ( $record[ $field ] ?? '' );
					if ( '' !== $text && '%none%' !== $text ) {
						$out['templates'][ $key ][ $field ] = $text . ' %sep% %page(n)%';
					}
				}
			}
		}
		$site_name = self::str( $o, 'social.facebook.general.siteName' );
		if ( '' !== $site_name ) {
			$out['og_site_name'] = $this->template( $site_name );
		}

		return $out + self::more_settings( $o, $d, array_map( 'strval', array_values( get_post_types( array( 'public' => true ) ) ) ), array_map( 'strval', array_values( get_taxonomies( array( 'public' => true ) ) ) ) );
	}

	/**
	 * What the full-settings certification found missing (queue Q254): the site's own name, the
	 * category base, paginated robots, breadcrumbs and their wording, the sitemap's choices, the
	 * extra sharing tags it prints, llms.txt. Pure apart from get_bloginfo(), for the tests.
	 *
	 * @param array<string, mixed> $o          `aioseo_options`.
	 * @param array<string, mixed> $d          `aioseo_options_dynamic`.
	 * @param array<int, string>   $post_types Public content types.
	 * @param array<int, string>   $taxonomies Public taxonomies.
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $o, array $d, array $post_types, array $taxonomies ): array {
		unset( $d );
		$out = array(
			'website_name'     => self::name_of( self::str( $o, 'searchAppearance.global.schema.websiteName' ) ),
			'website_alt_name' => self::name_of( self::str( $o, 'searchAppearance.global.schema.websiteAlternateName' ) ),
		);
		if ( true === self::at( $o, 'searchAppearance.advanced.removeCategoryBase' ) ) {
			$out['url_category_base'] = true;
		}
		$site = (array) self::at( $o, 'searchAppearance.advanced.globalRobotsMeta' );
		if ( array() !== $site && empty( $site['default'] ) && ! empty( $site['noindexPaginated'] ) ) {
			$out['robots_noindex_archive_pages'] = true;
			if ( ! empty( $site['nofollowPaginated'] ) ) {
				$out['robots_nofollow_archive_pages'] = true;
			}
		}
		// Breadcrumbs: its wording ("#breadcrumb_…" tags become our %s), the prefix printed
		// with a separator after it, as it prints it.
		$sep                 = html_entity_decode( self::str( $o, 'breadcrumbs.separator' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out['crumb_sep']    = $sep;
		$out['crumb_home']   = self::str( $o, 'breadcrumbs.homepageLabel' );
		$prefix              = self::str( $o, 'breadcrumbs.breadcrumbPrefix' );
		$out['crumb_prefix'] = '' !== $prefix ? trim( $prefix . ' ' . $sep ) : '';
		$tag                 = static fn( string $text ): string => trim( (string) preg_replace( '/#breadcrumb_[a-z_]+/', '%s', $text ) );
		$archive             = $tag( self::str( $o, 'breadcrumbs.archiveFormat' ) );
		// Its "archive" wording names a content type's archive page ("Archives for Books"); its
		// date trail is year › month › day in numbers, and shop categories run through the shop.
		if ( '' !== $archive && false !== strpos( $archive, '%s' ) ) {
			$out['crumb_ptarchive'] = $archive;
		}
		$out['crumb_date']        = 'tree-numbers';
		$out['crumb_tax_archive'] = array( 'product_cat', 'product_tag' );
		$search                   = $tag( self::str( $o, 'breadcrumbs.searchResultFormat' ) );
		if ( '' !== $search && false !== strpos( $search, '%s' ) ) {
			$out['crumb_search'] = $search;
		}
		$out['crumb_404']    = self::str( $o, 'breadcrumbs.errorFormat404' );
		$out['crumb_compat'] = array( 'aioseo' );
		// It adds "Page 2" as the last step of a list's page 2 and on (its own words).
		/* translators: %d: page number. */
		$out['crumb_paged'] = __( 'Page %d', 'zinn-seo' );
		// It prints previous/next links on pages kept out of search too (page 2 and on).
		$out['adjacent_noindex'] = true;
		// The organisation's registered details it prints: e-mail, telephone, size.
		$legal = array(
			'email'     => self::str( $o, 'searchAppearance.global.schema.email' ),
			'telephone' => self::str( $o, 'searchAppearance.global.schema.phone' ),
		);
		$size  = (array) self::at( $o, 'searchAppearance.global.schema.numberOfEmployees' );
		if ( ! empty( $size['isRange'] ) && is_numeric( $size['from'] ?? null ) && is_numeric( $size['to'] ?? null ) ) {
			$legal['numberOfEmployees'] = (int) $size['from'] . '-' . (int) $size['to'];
		} elseif ( is_numeric( $size['number'] ?? null ) ) {
			$legal['numberOfEmployees'] = (string) (int) $size['number'];
		}
		$legal = array_filter( $legal, static fn( string $v ): bool => '' !== $v );
		if ( array() !== $legal && 'person' !== self::str( $o, 'searchAppearance.global.schema.siteRepresents' ) ) {
			$out['identity_legal'] = array( 'org' => $legal );
		}
		// Sitemaps: the types and taxonomies it listed, the pages and terms it left out, authors.
		$general = (array) self::at( $o, 'sitemap.general' );
		if ( array() !== $general ) {
			if ( false === self::at( $general, 'postTypes.all' ) ) {
				$in                           = array_map( 'strval', (array) self::at( $general, 'postTypes.included' ) );
				$out['sitemap_exclude_types'] = array_values( array_diff( $post_types, $in, array( 'attachment' ) ) );
			}
			if ( false === self::at( $general, 'taxonomies.all' ) ) {
				$in                                = array_map( 'strval', (array) self::at( $general, 'taxonomies.included' ) );
				$out['sitemap_exclude_taxonomies'] = array_values( array_diff( $taxonomies, $in, array( 'post_format' ) ) );
			}
			$out['sitemap_authors'] = true === self::at( $general, 'author' );
			if ( true === self::at( $general, 'advancedSettings.enable' ) ) {
				$ids = array();
				foreach ( (array) self::at( $general, 'advancedSettings.excludePosts' ) as $row ) {
					$row   = is_string( $row ) ? json_decode( $row, true ) : $row;
					$ids[] = is_array( $row ) && is_numeric( $row['value'] ?? null ) ? (string) (int) $row['value'] : '';
				}
				foreach ( (array) self::at( $general, 'advancedSettings.excludeTerms' ) as $row ) {
					$row   = is_string( $row ) ? json_decode( $row, true ) : $row;
					$ids[] = is_array( $row ) && is_numeric( $row['value'] ?? null ) ? 't' . (int) $row['value'] : '';
				}
				$ids = array_values( array_filter( $ids, static fn( string $i ): bool => '' !== $i && 't0' !== $i && '0' !== $i ) );
				if ( array() !== $ids ) {
					$out['sitemap_exclude_ids'] = implode( ',', $ids );
				}
				if ( true === self::at( $general, 'advancedSettings.excludeImages' ) ) {
					$out['sitemap_images'] = false;
				}
			}
		}
		// It prints the Facebook page as publisher and, with "additional data", who wrote a post.
		$extras = array( 'publisher' );
		if ( true === self::at( $o, 'social.twitter.general.additionalData' ) ) {
			// "Written by" on every post and page with an author.
			$extras[] = 'written_by_pages';
		}
		$out['social_extras'] = $extras;
		if ( true === self::at( $o, 'sitemap.llms.enable' ) ) {
			$out['llms_full'] = false;
		}

		return array_filter( $out, static fn( $v ): bool => '' !== $v );
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'TruSEO scores are worked out again by Zinn® SEO rather than copied.', 'zinn-seo' ),
		);
	}
}
