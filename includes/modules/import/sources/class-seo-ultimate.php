<?php
/**
 * SEO Ultimate as an import source (zs-725).
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
 * Where SEO Ultimate keeps things (read from its own code, release 7.6.5.9, its last): one meta
 * row per field, `_su_<field>` — `title`, `description`, `keywords`, `meta_robots_noindex`,
 * `meta_robots_nofollow` (1), `og_title`, `og_description`, `og_image`; its title formats in the
 * option `seo_ultimate_module_titles`, with `{name}` variables.
 */
final class Seo_Ultimate extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'seoultimate';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'SEO Ultimate';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'seo-ultimate/seo-ultimate.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return false !== get_option( 'seo_ultimate', false ) || false !== get_option( 'seo_ultimate_module_titles', false );
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
			'/\{([a-z_]+)\}/i',
			array(
				'blog'          => '%sitename%',
				'tagline'       => '%tagline%',
				'post'          => '%title%',
				'page'          => '%title%',
				'title'         => '%title%',
				'category'      => '%term%',
				'tag'           => '%term%',
				'term'          => '%term%',
				'author'        => '%author%',
				'query'         => '%search%',
				'year'          => '%year%',
				'month'         => '',
				'day'           => '',
				'num'           => '%pagenumber%',
				'excerpt'       => '%excerpt%',
				'parent'        => '%parent_title%',
				'category_desc' => '%term_description%',
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
		$g = static fn( string $f ): string => self::pm( $post_id, '_su_' . $f );

		return self::filled(
			array(
				'title'    => $this->template( $g( 'title' ) ),
				'desc'     => $this->template( $g( 'description' ) ),
				'robots'   => self::robots(
					array(
						'noindex'  => '1' === $g( 'meta_robots_noindex' ),
						'nofollow' => '1' === $g( 'meta_robots_nofollow' ),
					)
				),
				'og_title' => $g( 'og_title' ),
				'og_desc'  => $g( 'og_description' ),
				'og_image' => self::image_id( $g( 'og_image' ) ),
				'focus'    => implode( "\n", array_filter( array_map( 'trim', explode( ',', $g( 'keywords' ) ) ) ) ),
			)
		);
	}

	/**
	 * Its title formats when the site never changed them (its own defaults, in English as it
	 * ships them; read from its titles module at its last release).
	 *
	 * @return array<string, string>
	 */
	public static function title_defaults(): array {
		return array(
			'title_home'     => '{blog}',
			'title_single'   => '{post} | {blog}',
			'title_page'     => '{page} | {blog}',
			'title_category' => '{category} | {blog}',
			'title_tag'      => '{tag} | {blog}',
			'title_day'      => 'Archives for {month} {day}, {year} | {blog}',
			'title_month'    => 'Archives for {month} {year} | {blog}',
			'title_year'     => 'Archives for {year} | {blog}',
			'title_author'   => 'Posts by {author} | {blog}',
			'title_search'   => 'Search Results for {query} | {blog}',
			'title_404'      => '404 Not Found | {blog}',
			'title_paged'    => '{title} - Page {num}',
		);
	}

	/**
	 * Site-wide settings: its title formats (its defaults where the site kept them), page 2
	 * and on, and its sharing tags.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o         = get_option( 'seo_ultimate_module_titles', array() );
		$o         = ( is_array( $o ) ? $o : array() ) + self::title_defaults();
		$t         = function ( string $key ) use ( $o ): string {
			return is_scalar( $o[ $key ] ?? null ) ? $this->template( trim( (string) $o[ $key ] ) ) : '';
		};
		$templates = array();
		$pairs     = array(
			'home'         => 'title_home',
			'post:post'    => 'title_single',
			'post:page'    => 'title_page',
			'tax:category' => 'title_category',
			'tax:post_tag' => 'title_tag',
			'author'       => 'title_author',
			'search'       => 'title_search',
			'404'          => 'title_404',
		);
		// Page 2 and on: "{title} - Page {num}" (its default) adds "- Page 2" to every title.
		$paged = is_scalar( $o['title_paged'] ?? null ) && 1 === preg_match( '/^\{title\}\s*-\s*\S+\s*\{num\}$/u', trim( (string) $o['title_paged'] ) ) ? ' %page(-n)%' : '';
		foreach ( $pairs as $ours => $theirs ) {
			$title = $t( $theirs );
			if ( '' !== $title ) {
				$templates[ $ours ] = array( 'title' => $title . ( '404' === (string) $ours ? '' : $paged ) );
			}
		}
		// Date archives: its month, day and year formats as one date variable, each archive
		// written as it wrote it ("Archives for March 1st, 2026": IMP2), else its month format.
		$date = self::date_title( $o );
		if ( '' !== $date ) {
			$templates['date'] = array( 'title' => $this->template( $date ) . $paged );
		} else {
			$month = is_scalar( $o['title_month'] ?? null ) ? trim( (string) $o['title_month'] ) : '';
			if ( false !== strpos( $month, '{month} {year}' ) ) {
				$templates['date'] = array( 'title' => $this->template( str_replace( '{month} {year}', '%date%', $month ) ) . $paged );
			}
		}
		$meta = get_option( 'seo_ultimate_module_meta', array() );
		$desc = self::desc_paged( is_array( $meta ) && array_key_exists( 'description_paged', $meta ) ? ( is_scalar( $meta['description_paged'] ) ? (string) $meta['description_paged'] : '' ) : '{meta_description} - Page {num}' );
		$og   = get_option( 'seo_ultimate_module_opengraph', array() );
		$og   = is_array( $og ) ? $og : array();
		$card = is_scalar( $og['default_post_twitter_card'] ?? null ) ? (string) $og['default_post_twitter_card'] : 'summary';

		$out = array(
			'sep'            => '|',
			'templates'      => $templates,
			// It prints a post's tags as article:tag, a "summary" X card unless changed, and no
			// previous/next links.
			'social_extras'  => array( 'article_tags' ),
			'x_card'         => 'summary_large_image' === $card ? 'summary_large_image' : 'summary',
			'adjacent_links' => false,
		);
		if ( '' !== $desc ) {
			$out['desc_paged'] = $desc;
		}

		return $out;
	}

	/**
	 * Its day, month and year archive formats as one title with `%date(month | day | year)%`,
	 * when the three differ only in the date (its defaults do); '' when they cannot be one.
	 * Pure, for the tests.
	 *
	 * @param array<string, mixed> $o Its title formats.
	 * @return string The title in its own variables, the date as our variable.
	 */
	public static function date_title( array $o ): string {
		$tokens  = array(
			'{month}'    => 'F',
			'{day}'      => 'jS',
			'{year}'     => 'Y',
			'{monthnum}' => 'm',
			'{daynum}'   => 'd',
		);
		$frame   = null;
		$formats = array();
		foreach ( array( 'title_month', 'title_day', 'title_year' ) as $key ) {
			$text = is_scalar( $o[ $key ] ?? null ) ? trim( (string) $o[ $key ] ) : '';
			// The date: from its first date token to its last, with only spaces and date
			// punctuation between them.
			if ( 1 !== preg_match( '/^(.*?)((?:\{(?:month|day|year|monthnum|daynum)\}[ ,.\/:\-]*)*\{(?:month|day|year|monthnum|daynum)\})(.*)$/s', $text, $m ) ) {
				return '';
			}
			if ( null !== $frame && array( $m[1], $m[3] ) !== $frame ) {
				return '';
			}
			$frame     = array( $m[1], $m[3] );
			$formats[] = strtr( $m[2], $tokens );
		}

		return null === $frame ? '' : $frame[0] . '%date(' . implode( ' | ', $formats ) . ')%' . $frame[1];
	}

	/**
	 * Its "Pagination Description Format" as the text after a description on page 2 and on
	 * (`desc_paged`): its default "- Page {num}" in our translated words. Pure, for the tests.
	 *
	 * @param string $format Its format, `{meta_description} - Page {num}`.
	 * @return string '' when it adds nothing.
	 */
	public static function desc_paged( string $format ): string {
		$format = trim( $format );
		if ( 1 !== preg_match( '/^\{meta_description\}(.*)$/s', $format, $m ) || '' === trim( $m[1] ) ) {
			return '';
		}
		$tail = trim( $m[1] );
		if ( 1 === preg_match( '/^-\s*Page\s*\{num\}$/', $tail ) ) {
			return '%page(-n)%';
		}

		return strtr(
			$tail,
			array(
				'{num}' => '%pagenumber%',
				'{max}' => '%pagetotal%',
			)
		);
	}
}
