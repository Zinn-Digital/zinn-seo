<?php
/**
 * Link Whisper's auto-link keywords as an import source (zs-725).
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
 * Where Link Whisper keeps its auto-link keywords (its premium edition; read from the published
 * code of releases that have the feature): the table `{prefix}wpil_keywords` — `keyword`, `link`,
 * `link_once`, `restrict_to_live` … — one row per keyword. Each becomes an auto-link rule of Zinn®
 * SEO Pro (the phrase, its address — the post itself when the address is one of this site's
 * pages — and once per page when it linked once). Links Link Whisper already wrote into posts
 * stay as they are: they are ordinary links in the text.
 *
 * Without Zinn® SEO Pro the keywords are counted and reported, and nothing is written.
 */
final class Link_Whisper extends Source {

	/** Prefix of the rule ids this import writes (what an undo removes). */
	private const MARK = 'lw';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'linkwhisper';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Link Whisper';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'link-whisper/link-whisper.php', 'link-whisper-premium/link-whisper.php' );
	}

	/**
	 * No page data: only keywords.
	 *
	 * @return bool
	 */
	public function has_pages(): bool {
		return false;
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		return array() !== self::keywords();
	}

	/**
	 * Nothing per post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- keywords are not per post.
		return array();
	}

	/**
	 * Its keywords.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function keywords(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpil_keywords';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table check, once.
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- its own table, read once (a few hundred rows).
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT 5000', $table ), ARRAY_A );

		return array_values( array_filter( $rows, static fn( $r ): bool => is_array( $r ) && '' !== trim( (string) ( $r['keyword'] ?? '' ) ) && '' !== trim( (string) ( $r['link'] ?? '' ) ) ) );
	}

	/**
	 * Its keywords as our rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rules(): array {
		$out = array();
		foreach ( self::keywords() as $row ) {
			$link  = trim( (string) $row['link'] );
			$post  = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $link );
			$out[] = array(
				'id'      => self::MARK . substr( md5( (string) ( $row['id'] ?? $row['keyword'] ) . '|' . $link ), 0, 6 ),
				'phrases' => array( trim( (string) $row['keyword'] ) ),
				'post'    => $post,
				'url'     => $post > 0 ? '' : $link,
				'max'     => 1,
			);
		}

		return $out;
	}

	/**
	 * Count (dry) or copy the keywords into the Pro auto-link rules.
	 *
	 * @param bool $dry Count only.
	 * @return array<string, int>
	 */
	public function extras( bool $dry ): array {
		$rules = self::rules();
		if ( array() === $rules ) {
			return array();
		}
		$class = '\ZinnDigital\ZinnSEO\Pro\Links\Autolinks';
		// The auto-link rules are Zinn® SEO Pro's (its links module); a background step may run
		// where that module did not boot, so its code is loaded here when it is switched on.
		if ( \ZinnDigital\ZinnSEO\Modules::enabled( 'links-pro' ) ) {
			\ZinnDigital\ZinnSEO\Modules::load( 'links-pro' );
		}
		if ( ! class_exists( $class ) ) {
			return array(
				'keywords'  => count( $rules ),
				'needs_pro' => 1,
			);
		}
		$have = array();
		foreach ( $class::rules() as $rule ) {
			foreach ( (array) ( $rule['phrases'] ?? array() ) as $phrase ) {
				$have[ strtolower( (string) $phrase ) ] = true;
			}
		}
		$new = array_values( array_filter( $rules, static fn( $r ): bool => ! isset( $have[ strtolower( (string) $r['phrases'][0] ) ] ) ) );
		if ( ! $dry && array() !== $new ) {
			$class::save( array_merge( $class::rules(), $new ) );
		}

		return array(
			'keywords' => count( $rules ),
			'new'      => count( $new ),
			'same'     => count( $rules ) - count( $new ),
		);
	}

	/**
	 * Remove the rules this import wrote.
	 *
	 * @return int
	 */
	public function undo_extras(): int {
		$class = '\ZinnDigital\ZinnSEO\Pro\Links\Autolinks';
		if ( \ZinnDigital\ZinnSEO\Modules::enabled( 'links-pro' ) ) {
			\ZinnDigital\ZinnSEO\Modules::load( 'links-pro' );
		}
		if ( ! class_exists( $class ) ) {
			return 0;
		}
		$all  = $class::rules();
		$keep = array_values( array_filter( $all, static fn( $r ): bool => 0 !== strpos( (string) ( $r['id'] ?? '' ), self::MARK ) || 8 !== strlen( (string) $r['id'] ) ) );
		if ( count( $keep ) !== count( $all ) ) {
			$class::save( $keep );
		}

		return count( $all ) - count( $keep );
	}

	/**
	 * What has no place here.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array(
			__( 'Link Whisper\'s link suggestions and reports are worked out again here from your own pages; only its auto-link keywords are copied, as Zinn® SEO Pro auto-link rules.', 'zinn-seo' ),
		);
	}
}
