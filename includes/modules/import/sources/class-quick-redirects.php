<?php
/**
 * Quick Page/Post Redirect as an import source (zs-725).
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
 * Where Quick Page/Post Redirect keeps things (read from its own code, trunk at its closure):
 * a redirect set on a page itself in the meta rows `_pprredirect_active` (1), `_pprredirect_url`
 * and `_pprredirect_type` (301, 302, 307 or `meta`); its "quick redirects" list in the option
 * `quickppr_redirects` (old address => new address), read by the redirects importer (`quickppr`).
 * A "meta refresh" redirect becomes a 301 when it refreshes at once (its default, 0 seconds:
 * Google reads an instant refresh as a permanent redirect) and a 302 when it waits.
 */
final class Quick_Redirects extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'quickppr';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'Quick Page/Post Redirect';
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return array( 'quick-pagepost-redirect-plugin/page_post_redirect_plugin.php' );
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_query_meta_key -- one existence check.
		$pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_pprredirect_url' LIMIT 1" );

		return $pages > 0 || array() !== (array) get_option( 'quickppr_redirects', array() );
	}

	/**
	 * A post's own redirect.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array {
		$url = self::pm( $post_id, '_pprredirect_url' );
		if ( '1' !== self::pm( $post_id, '_pprredirect_active' ) || '' === $url ) {
			return array();
		}
		$type = self::pm( $post_id, '_pprredirect_type' );
		$code = in_array( (int) $type, array( 301, 302, 307, 308 ), true ) ? (int) $type : self::meta_code( $post_id );

		return array(
			'redirect' => array(
				'target' => $url,
				'code'   => $code,
			),
		);
	}

	/**
	 * The redirect a "meta refresh" one is: permanent when it refreshes at once (Google reads an
	 * instant refresh as a permanent redirect), temporary when it waits.
	 *
	 * @param int $post_id Post.
	 * @return int
	 */
	public static function meta_code( int $post_id ): int {
		$own  = self::pm( $post_id, '_pprredirect_meta_secs' );
		$site = (string) get_option( 'qppr_meta_addon_sec', get_option( 'ppr_meta-seconds', '0' ) );
		$secs = '' !== $own ? $own : $site;

		return (int) $secs > 0 ? 302 : 301;
	}

	/**
	 * Its quick redirects list goes through the redirects importer.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return 'quickppr';
	}

	/**
	 * It does not write robots.txt.
	 *
	 * @return bool
	 */
	protected function writes_robots(): bool {
		return false;
	}
}
