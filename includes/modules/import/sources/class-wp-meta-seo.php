<?php
/**
 * WP Meta SEO as an import source (zs-725).
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
 * Where WP Meta SEO keeps things (read from its own code, trunk at its closure): per post the
 * meta rows `_metaseo_meta<field>` — `title`, `desc`, `keywords`, `index` (`noindex`), `follow`
 * (`nofollow`), `metaseo_canonical`, `opengraph-title` / `-desc` / `-image`, `twitter-title` /
 * `-desc` / `-image`; per term `wpms_category_metatitle` and `wpms_category_metadesc`. Its titles
 * are plain text (no variables).
 */
final class Wp_Meta_Seo extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wpmetaseo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'WP Meta SEO';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'wp-meta-seo/wp-meta-seo.php', 'wp-meta-seo-addon/wp-meta-seo-addon.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return false !== get_option( '_metaseo_settings', false );
	}

	/**
	 * Its text kept as written (it has no variables; a percent sign is literal).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function template( string $text ): string {
		return trim( $text );
	}

	/**
	 * A post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$g = static fn( string $f ): string => self::pm( $post_id, '_metaseo_meta' . $f );

		return self::filled(
			array(
				'title'     => $g( 'title' ),
				'desc'      => $g( 'desc' ),
				'canonical' => $g( 'metaseo_canonical' ),
				'robots'    => self::robots(
					array(
						'noindex'  => 'noindex' === $g( 'index' ),
						'nofollow' => 'nofollow' === $g( 'follow' ),
					)
				),
				'og_title'  => $g( 'opengraph-title' ),
				'og_desc'   => $g( 'opengraph-desc' ),
				'og_image'  => self::image_id( $g( 'opengraph-image' ) ),
				'x_title'   => $g( 'twitter-title' ),
				'x_desc'    => $g( 'twitter-desc' ),
				'x_image'   => self::image_id( $g( 'twitter-image' ) ),
				'focus'     => implode( "\n", array_filter( array_map( 'trim', explode( ',', $g( 'keywords' ) ) ) ) ),
			)
		);
	}

	/**
	 * Site-wide settings: its X card type (`summary` unless changed), and no previous/next
	 * links (it prints none, nor does WordPress).
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$o    = get_option( '_metaseo_settings', array() );
		$o    = is_array( $o ) ? $o : array();
		$card = is_scalar( $o['metaseo_twitter_card'] ?? null ) ? (string) $o['metaseo_twitter_card'] : 'summary';

		// It leaves search results to WordPress's own title ("Search Results for “fox” – Site").
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment -- WordPress core's own string, as the site showed it.
		$search = html_entity_decode( sprintf( (string) __( 'Search Results for &#8220;%s&#8221;', 'default' ), '%search%' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'x_card'         => 'summary_large_image' === $card ? 'summary_large_image' : 'summary',
			'adjacent_links' => false,
			'templates'      => array( 'search' => array( 'title' => $search . ' %sep% %sitename%' ) ),
		);
	}

	/**
	 * A term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array {
		return self::filled(
			array(
				'title' => self::tm( (int) $term->term_id, 'wpms_category_metatitle' ),
				'desc'  => self::tm( (int) $term->term_id, 'wpms_category_metadesc' ),
			)
		);
	}
}
