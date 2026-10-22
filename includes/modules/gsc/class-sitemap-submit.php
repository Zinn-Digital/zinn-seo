<?php
/**
 * Sitemaps sent to Google Search Console and Bing on connect, and again whenever their address
 * changes (zs-092).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Gsc;

use ZinnDigital\ZinnSEO\Sitemaps\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When: Google connected (first time or a new property), Bing connected, and the sitemap's
 * address changed — switching "hide that this site uses an SEO plugin" on or off moves it
 * (architecture §1.7: the switch's confirm screen promises the resubmission). The last outcome
 * per search engine is kept in `zseo_sitemap_submits` and shown on the Index screen.
 *
 * ⛔ Only this site's own sitemap index is sent — never an address typed by a person.
 */
final class Sitemap_Submit {

	/** Option: the last outcome per engine. */
	public const OPTION = 'zseo_sitemap_submits';

	/**
	 * The sitemap index address to send, or '' when the sitemap module is off.
	 *
	 * @return string
	 */
	public static function sitemap(): string {
		return class_exists( Sitemaps::class ) && method_exists( Sitemaps::class, 'active' ) && Sitemaps::active() ? Sitemaps::url() : '';
	}

	/**
	 * Send to every connected engine.
	 *
	 * @param string $why connect|notrace|manual.
	 * @return array<string, array<string, mixed>> Engine => outcome.
	 */
	public static function all( string $why ): array {
		$out = array();
		if ( Google::has( 'search_console' ) ) {
			$out['google'] = self::google( $why );
		}
		/**
		 * Lets the Bing module (and any other) send the sitemap too.
		 *
		 * @param array<string, array<string, mixed>> $out     Outcomes so far.
		 * @param string                              $sitemap The sitemap index address.
		 * @param string                              $why     Why it is being sent.
		 */
		$out = (array) apply_filters( 'zinn_seo_sitemap_submit', $out, self::sitemap(), $why );

		return $out;
	}

	/**
	 * Send to Search Console.
	 *
	 * @param string $why Why.
	 * @return array<string, mixed>
	 */
	public static function google( string $why ): array {
		$url = self::sitemap();
		if ( '' === $url ) {
			return self::keep( 'google', false, __( 'The sitemap is switched off, so there was nothing to send.', 'zinn-seo' ), $url, $why );
		}
		$res = Google::call( 'POST', '/sitemaps', array( 'sitemap_url' => $url ) );
		if ( is_wp_error( $res ) ) {
			return self::keep( 'google', false, $res->get_error_message(), $url, $why );
		}

		return self::keep( 'google', true, '', $url, $why );
	}

	/**
	 * Record an outcome.
	 *
	 * @param string $engine  google|bing.
	 * @param bool   $ok      Sent.
	 * @param string $message Why not.
	 * @param string $url     The address sent.
	 * @param string $why     Why it was sent.
	 * @return array<string, mixed>
	 */
	public static function keep( string $engine, bool $ok, string $message, string $url, string $why ): array {
		$all            = (array) get_option( self::OPTION, array() );
		$all[ $engine ] = array(
			'ok'      => $ok,
			'message' => $message,
			'url'     => $url,
			'why'     => sanitize_key( $why ),
			'at'      => time(),
		);
		update_option( self::OPTION, $all, false );

		return $all[ $engine ];
	}

	/**
	 * Hidden-site switch flipped: if the sitemap moved, send the new address.
	 *
	 * @param bool                             $on      Now on.
	 * @param array<int, array<string, mixed>> $changes What moved.
	 * @return void
	 */
	public static function on_notrace( $on, $changes ): void {
		unset( $on );
		foreach ( (array) $changes as $change ) {
			if ( ! empty( $change['resubmit'] ) ) {
				self::all( 'notrace' );
				return;
			}
		}
	}
}
