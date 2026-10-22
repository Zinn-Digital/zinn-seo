<?php
/**
 * Emptying the page caches around a switch-off (queue Q263, point 5).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ A page cache keeps the head the OLD plugin printed. Without emptying it, visitors and search
 * engines keep reading the old head after the switch, and — worse — the check that re-reads the
 * pages afterwards reads the cache instead of this plugin, and passes on a page it never saw
 * (zinnhub, 2026-10-14: SiteGround's cache and Cloudflare both answered for minutes).
 *
 * So every cache this plugin can empty is emptied straight after the switch-off, BEFORE the pages
 * are read again, and again after a rollback. A cache it cannot reach (a host's server cache, a
 * CDN with no connection here) is named, so the person can empty it by hand.
 */
final class Purge {

	/**
	 * Cache plugins that offer "empty everything": main file => [name, kind, callable or action].
	 * `kind` is `fn` (a function to call) or `action` (a hook the plugin listens to).
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function plugins(): array {
		return array(
			'zinn-cache/zinn-cache.php'                  => array( 'Zinn® Cache', 'action', 'zinn_cache_purge_all' ),
			'wp-rocket/wp-rocket.php'                    => array( 'WP Rocket', 'fn', 'rocket_clean_domain' ),
			'w3-total-cache/w3-total-cache.php'          => array( 'W3 Total Cache', 'fn', 'w3tc_flush_all' ),
			'litespeed-cache/litespeed-cache.php'        => array( 'LiteSpeed Cache', 'action', 'litespeed_purge_all' ),
			'wp-super-cache/wp-cache.php'                => array( 'WP Super Cache', 'fn', 'wp_cache_clear_cache' ),
			'sg-cachepress/sg-cachepress.php'            => array( 'SiteGround Speed Optimizer', 'fn', 'sg_cachepress_purge_cache' ),
			'wp-fastest-cache/wpFastestCache.php'        => array( 'WP Fastest Cache', 'action', 'wpfc_clear_all_cache' ),
			'breeze/breeze.php'                          => array( 'Breeze', 'action', 'breeze_clear_all_cache' ),
			'hummingbird-performance/wp-hummingbird.php' => array( 'Hummingbird', 'action', 'wphb_clear_page_cache' ),
			'cache-enabler/cache-enabler.php'            => array( 'Cache Enabler', 'action', 'cache_enabler_clear_complete_cache' ),
			'wp-optimize/wp-optimize.php'                => array( 'WP-Optimize', 'fn', 'wpo_cache_flush' ),
			'swift-performance-lite/performance.php'     => array( 'Swift Performance', 'action', 'swift_performance_clear_all_cache' ),
			'nitropack/main.php'                         => array( 'NitroPack', 'fn', 'nitropack_purge_all' ),
			'autoptimize/autoptimize.php'                => array( 'Autoptimize', 'action', 'autoptimize_action_cachepurged' ),
		);
	}

	/**
	 * Response headers that show a cache in front of WordPress: header => name. Read from the
	 * preflight's request for the home page.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return array(
			'cf-ray'            => 'Cloudflare',
			'x-sg-cache'        => 'SiteGround',
			'x-litespeed-cache' => 'LiteSpeed',
			'x-kinsta-cache'    => 'Kinsta',
			'x-varnish'         => 'Varnish',
			'x-fastcgi-cache'   => 'NGINX FastCGI cache',
			'x-proxy-cache'     => 'a server cache',
			'x-cache'           => 'a server cache',
			'x-nginx-cache'     => 'NGINX cache',
			'x-wpe-cached'      => 'WP Engine',
			'x-ac'              => 'Pressable',
			'x-zinn-cache'      => 'Zinn® Cache',
		);
	}

	/**
	 * The active plugins (this site and the network).
	 *
	 * @return array<int, string>
	 */
	public static function active(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_map( 'strval', $active );
	}

	/**
	 * Is the edge connected (Zinn® SEO Pro's edge, through the owner's Cloudflare or Zinn® hosting)?
	 *
	 * @return bool
	 */
	public static function edge(): bool {
		$edge = '\ZinnDigital\ZinnSEO\Pro\Edge\Edge';
		if ( ! class_exists( $edge ) ) {
			return false;
		}
		if ( $edge::own() ) {
			return true;
		}

		return ! empty( $edge::hosted()['hosted'] );
	}

	/**
	 * What caches there are, and which this plugin can empty.
	 *
	 * @param array<string, string> $seen Response headers of the home page (lower-case names).
	 * @return array<int, array{name: string, auto: bool, via: string}>
	 */
	public static function detect( array $seen = array() ): array {
		$out    = array();
		$active = self::active();
		foreach ( self::plugins() as $file => $plugin ) {
			if ( in_array( $file, $active, true ) ) {
				$out[ $plugin[0] ] = array(
					'name' => $plugin[0],
					'auto' => self::callable( $plugin ),
					'via'  => 'plugin',
				);
			}
		}
		$edge = self::edge();
		foreach ( self::headers() as $header => $name ) {
			if ( ! array_key_exists( $header, $seen ) ) {
				continue;
			}
			if ( 'x-cache' === $header && 1 !== preg_match( '/hit|miss|stale|bypass|expired/i', (string) $seen[ $header ] ) ) {
				continue;
			}
			if ( isset( $out[ $name ] ) ) {
				continue;
			}
			// A host cache the matching plugin empties (SiteGround's own plugin, LiteSpeed's).
			$covered = ( 'SiteGround' === $name && isset( $out['SiteGround Speed Optimizer'] ) && $out['SiteGround Speed Optimizer']['auto'] )
				|| ( 'LiteSpeed' === $name && isset( $out['LiteSpeed Cache'] ) && $out['LiteSpeed Cache']['auto'] )
				|| ( 'Zinn® Cache' === $name && isset( $out['Zinn® Cache'] ) );
			if ( $covered ) {
				continue;
			}
			$out[ $name ] = array(
				'name' => $name,
				'auto' => 'Cloudflare' === $name && $edge,
				'via'  => 'header',
			);
		}

		return array_values( $out );
	}

	/**
	 * Can a cache plugin's "empty everything" be called here?
	 *
	 * @param array{0: string, 1: string, 2: string} $plugin Entry of plugins().
	 * @return bool
	 */
	public static function callable( array $plugin ): bool {
		return 'fn' === $plugin[1] ? function_exists( $plugin[2] ) : false !== has_action( $plugin[2] );
	}

	/**
	 * Empty every cache this plugin can reach, and say which ones are left to the person.
	 *
	 * @param array<int, string>    $urls Pages to purge at the edge (the edge purges by address).
	 * @param array<string, string> $seen Response headers of the home page from the preflight.
	 * @return array{at: int, purged: array<int, string>, manual: array<int, string>}
	 */
	public static function run( array $urls, array $seen = array() ): array {
		$purged = array();
		$active = self::active();
		foreach ( self::plugins() as $file => $plugin ) {
			if ( ! in_array( $file, $active, true ) || ! self::callable( $plugin ) ) {
				continue;
			}
			if ( 'fn' === $plugin[1] ) {
				call_user_func( $plugin[2] );
			} else {
				do_action( $plugin[2] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the cache plugin's own documented purge hook, from the list above.
			}
			$purged[] = $plugin[0];
		}
		$edge = '\ZinnDigital\ZinnSEO\Pro\Edge\Edge';
		if ( self::edge() && array() !== $urls ) {
			$edge::queue( array_values( array_unique( $urls ) ) );
			$result = $edge::flush();
			if ( ! is_wp_error( $result ) ) {
				$purged[] = 'Cloudflare';
			}
		}
		// What is in WordPress's own object cache came from the database a moment ago and stays
		// right; only the rendered pages are stale.
		$manual = array();
		foreach ( self::detect( $seen ) as $cache ) {
			if ( ! in_array( $cache['name'], $purged, true ) ) {
				$manual[] = $cache['name'];
			}
		}

		/**
		 * Fires after Zinn® SEO emptied the page caches it knows around a switch-off, so a cache
		 * this plugin does not know can empty itself too.
		 *
		 * @param array<int, string> $urls Pages that changed.
		 */
		do_action( 'zinn_seo_import_purge', $urls );

		return array(
			'at'     => time(),
			'purged' => array_values( array_unique( $purged ) ),
			'manual' => array_values( array_unique( $manual ) ),
		);
	}
}
