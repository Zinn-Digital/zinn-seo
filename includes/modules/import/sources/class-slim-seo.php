<?php
/**
 * Slim SEO as an import source.
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
 * Where Slim SEO keeps things (read from its own code, release 4.11.0): per post and per term in
 * ONE meta row each, `slim_seo` (`title`, `description`, `canonical`, `noindex`,
 * `facebook_image`, `twitter_image`); everything site-wide in the option `slim_seo`, keyed by
 * what it applies to (`home`, a post type, `<type>_archive`, a taxonomy, `author`).
 *
 * It is built to need no settings: where nothing is configured the title is WordPress's own
 * ("Page – Site", with WordPress's dash) and the description is cut from the text. A template a
 * person DID write uses a plain hyphen as its separator. Both are carried over as they are.
 */
final class Slim_Seo extends Source {

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
		return 'slimseo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Slim SEO';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'slim-seo/slim-seo.php', 'slim-seo-pro/slim-seo-pro.php' );
	}

	/**
	 * Data on this site? Its option exists from the first activation, even when empty.
	 *
	 * @return bool
	 */
	public function present(): bool {
		return false !== get_option( 'slim_seo', false ) || false !== get_option( 'slim_seo_db_version', false );
	}

	/**
	 * Its option.
	 *
	 * @return array<string, mixed>
	 */
	private function option(): array {
		if ( null === $this->option ) {
			$stored       = get_option( 'slim_seo', array() );
			$this->option = is_array( $stored ) ? $stored : array();
		}

		return $this->option;
	}

	/**
	 * A string at `group.key` of its option.
	 *
	 * @param string $group Group (`home`, a post type, …).
	 * @param string $key   Key.
	 * @return string
	 */
	private function opt( string $group, string $key ): string {
		$value = $this->option()[ $group ][ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Did a person write any title template? Then `{{ sep }}` (a hyphen) is the site's separator.
	 *
	 * @return bool
	 */
	private function has_templates(): bool {
		foreach ( $this->option() as $group ) {
			if ( is_array( $group ) && '' !== trim( (string) ( is_scalar( $group['title'] ?? '' ) ? $group['title'] : '' ) ) ) {
				return true;
			}
		}

		return false;
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
			'/\{\{\s*([a-z_.0-9-]+)\s*\}\}/i',
			array(
				'post.title'                => '%title%',
				'post.excerpt'              => '%excerpt%',
				'post.auto_description'     => '%excerpt%',
				'post.content'              => '%excerpt%',
				'post.date'                 => '%date%',
				'post.modified_date'        => '%modified%',
				'post.categories'           => '%category%',
				'post.tags'                 => '%ct_post_tag%',
				'term.name'                 => '%term%',
				'term.description'          => '%term_description%',
				'term.auto_description'     => '%term_description%',
				'author.display_name'       => '%author%',
				'author.description'        => '%excerpt%',
				'author.auto_description'   => '%excerpt%',
				'site.title'                => '%sitename%',
				'site.description'          => '%tagline%',
				'sep'                       => '%sep%',
				'page'                      => '%page%',
				'post_type.labels.plural'   => '%posttype_plural%',
				'post_type.labels.singular' => '%posttype%',
				'current.year'              => '%currentyear%',
				'current.month'             => '%currentmonth%',
			),
			array(
				'post.custom_field.' => static fn( string $rest ): string => self::named( 'cf', $rest ),
				'post.tax.'          => static fn( string $rest ): string => self::named( 'ct', $rest ),
			)
		);
	}

	/**
	 * Our fields from one of its `slim_seo` rows.
	 *
	 * @param mixed $row Row.
	 * @return array<string, mixed>
	 */
	private function fields( $row ): array {
		if ( ! is_array( $row ) ) {
			return array();
		}
		$s = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? trim( (string) $row[ $key ] ) : '';

		return self::filled(
			array(
				'title'     => $this->template( $s( 'title' ) ),
				'desc'      => $this->template( $s( 'description' ) ),
				'canonical' => $s( 'canonical' ),
				'robots'    => ! empty( $row['noindex'] ) ? array( 'noindex' ) : array(),
				'og_image'  => self::image_id( $s( 'facebook_image' ) ),
				'x_image'   => $s( 'twitter_image' ) !== $s( 'facebook_image' ) ? self::image_id( $s( 'twitter_image' ) ) : 0,
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
		return $this->fields( get_post_meta( $post_id, 'slim_seo', true ) );
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		return $this->fields( get_term_meta( (int) $term->term_id, 'slim_seo', true ) );
	}

	/**
	 * A template record for one of its groups.
	 *
	 * @param string $group   Group in its option.
	 * @param string $fallback WordPress's own title for this kind of page (used when none is configured and a hyphen became the separator).
	 * @param string $desc    The description when none is configured.
	 * @return array<string, mixed>
	 */
	private function record( string $group, string $fallback, string $desc ): array {
		$title  = $this->opt( $group, 'title' );
		$text   = $this->opt( $group, 'description' );
		$record = array(
			'title' => '' !== $title ? $this->template( $title ) : ( $this->has_templates() ? $fallback : '' ),
			'desc'  => '' !== $text ? $this->template( $text ) : $desc,
			'index' => ! empty( $this->option()[ $group ]['noindex'] ) ? 'noindex' : 'index',
		);
		$image  = self::image_id( $this->opt( $group, 'facebook_image' ) );
		if ( $image > 0 ) {
			$record['og_image'] = $image;
		}

		return $record;
	}

	/**
	 * Site-wide settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o = $this->option();
		// With no template of its own the titles are WordPress's, which joins with an en dash;
		// a template a person wrote joins with a plain hyphen.
		$out       = array( 'sep' => $this->has_templates() ? '-' : '–' );
		$templates = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$templates[ 'post:' . $type ] = $this->record( (string) $type, '%title% – %sitename%', '%excerpt%' );
			$object                       = get_post_type_object( $type );
			if ( $object && $object->has_archive ) {
				$templates[ 'archive:' . $type ] = $this->record( $type . '_archive', '%posttype_plural% – %sitename%', '%none%' );
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			$templates[ 'tax:' . $tax ] = $this->record( (string) $tax, '%term% – %sitename%', '%term_description%' );
		}
		$templates['home']   = $this->record( 'home', '%sitename% – %tagline%', '' );
		$templates['author'] = $this->record( 'author', '%author% – %sitename%', '%excerpt%' );
		// It redirects the author archive of a site with one user to the home page.
		if ( false !== ( $o['auto_redirection'] ?? true ) && 1 === count(
			get_users(
				array(
					'number' => 2,
					'fields' => 'ID',
				)
			)
		) ) {
			$templates['author']['index'] = 'noindex';
		}
		// It leaves these to WordPress: its words, in the site's language at the time of the import.
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own strings.
		$search = html_entity_decode( sprintf( (string) __( 'Search Results for &#8220;%s&#8221;', 'default' ), '%search%' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lost   = (string) __( 'Page not found', 'default' );
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment
		$templates['date']   = array(
			'title' => '%date% – %sitename%',
			'desc'  => '%none%',
		);
		$templates['search'] = array( 'title' => $search . ' – %sitename%' );
		$templates['404']    = array( 'title' => $lost . ' – %sitename%' );
		$out['templates']    = $templates;
		// Its automatic redirects send an author archive to the home page on a one-author site
		// or when the author has no posts.
		if ( false !== ( $o['auto_redirection'] ?? true ) ) {
			$out['archives_off'] = array( 'author_single' );
		}

		$handle = is_scalar( $o['twitter_site'] ?? null ) ? ltrim( trim( (string) $o['twitter_site'] ), '@' ) : '';
		$out   += array(
			'x_site'           => $handle,
			'fb_app_id'        => is_scalar( $o['facebook_app_id'] ?? null ) ? trim( (string) $o['facebook_app_id'] ) : '',
			'og_default_image' => self::image_id( is_scalar( $o['default_facebook_image'] ?? null ) ? (string) $o['default_facebook_image'] : '' ),
			// It shows the featured image and never looks inside the text.
			'og_content_image' => false,
		);

		return $out + self::more_settings( $o );
	}

	/**
	 * What the full-settings certification found missing (queue Q254): its breadcrumbs (»,
	 * dates as year › month › day), the sharing tags it prints beyond the usual (last change,
	 * a post's tags, a product's price and stock), its own X picture, the category base. Pure,
	 * for the tests.
	 *
	 * @param array<string, mixed> $o Its option.
	 * @return array<string, mixed>
	 */
	public static function more_settings( array $o ): array {
		$out = array(
			'crumb_sep'         => '»',
			'crumb_date'        => 'tree',
			// A term runs through its content type's archive (Home › Books › Mystery).
			'crumb_tax_archive' => self::single_type_taxonomies(),
			'crumb_compat'      => array( 'slimseo' ),
			// Its trail's words for search results and a missing page (WordPress's own).
			// phpcs:disable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own strings, which Slim SEO prints.
			'crumb_search'      => html_entity_decode( (string) __( 'Search Results for &#8220;%s&#8221;', 'default' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'crumb_404'         => (string) __( 'Page not found', 'default' ),
			// phpcs:enable WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment
			'social_extras'     => array( 'updated_time', 'article_tags', 'product' ),
		);
		// Its trail runs an item through the `category` taxonomy only, whatever its type (its
		// shortcode's default), and a WooCommerce product through its product category: a book
		// shows no term at all.
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( in_array( $type, array( 'page', 'attachment' ), true ) ) {
				continue;
			}
			$tax                         = 'product' === $type ? 'product_cat' : 'category';
			$out['crumb_types'][ $type ] = array( 'tax' => is_object_in_taxonomy( $type, $tax ) ? $tax : 'none' );
		}
		$x = self::image_id( is_scalar( $o['default_twitter_image'] ?? null ) ? (string) $o['default_twitter_image'] : '' );
		if ( $x > 0 ) {
			$out['x_default_image'] = $x;
		}
		if ( is_array( $o['features'] ?? null ) && in_array( 'no_category_base', $o['features'], true ) ) {
			$out['url_category_base'] = true;
		}

		return $out;
	}

	/**
	 * Public taxonomies that belong to one content type with an archive.
	 *
	 * @return array<int, string>
	 */
	private static function single_type_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( ! $tax instanceof \WP_Taxonomy ) {
				continue;
			}
			$types = array_values( (array) $tax->object_type );
			$type  = 1 === count( $types ) ? get_post_type_object( (string) $types[0] ) : null;
			if ( $type && ! in_array( $type->name, array( 'post', 'page' ), true ) && $type->has_archive ) {
				$out[] = (string) $tax->name;
			}
		}

		return $out;
	}

	/**
	 * Its redirect manager's rules, through the redirects module.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'slimseo';
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		$notes = array(
			__( 'Slim SEO writes each description from the page\'s text as the page is shown. "Keep exactly what it showed" stores those words for every page; without it Zinn® SEO writes its own from the same text.', 'zinn-seo' ),
		);
		if ( 1 === count(
			get_users(
				array(
					'number' => 2,
					'fields' => 'ID',
				)
			)
		) ) {
			$notes[] = __( 'Slim SEO redirects the author archive of a site with one user to the home page. Here it stays reachable but is kept out of search results (noindex).', 'zinn-seo' );
		}

		return $notes;
	}
}
