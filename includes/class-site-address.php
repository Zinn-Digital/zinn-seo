<?php
/**
 * The site's own address keeps the scheme the site was set up with (https stays https) when
 * WordPress cannot tell from the request.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress builds `site_url()` from the stored address and then re-picks the scheme from the
 * REQUEST (`set_url_scheme()` with no scheme asks `is_ssl()`). With no request to ask — WP-CLI, a
 * scheduled task — or behind a proxy that ends HTTPS before the server (Cloudflare, a load
 * balancer) `is_ssl()` says no, so an https site reports itself as http://. The licensing service
 * records the address it is given that way, and its e-mails then link to http:// (seen on
 * zinnhub.com, 2026-10-14: the licence e-mail opened "this site doesn't support a secure
 * connection").
 *
 * `home_url()` already keeps the stored scheme in that case; this does the same for the site's own
 * address and nothing else: only when the caller asked for no particular scheme, the address is
 * this site's stored https address written as http://, and the request cannot say otherwise
 * (WP-CLI, a scheduled task, or a proxy that says the visitor used https). A real http:// visit
 * in a browser is left exactly as WordPress builds it.
 */
final class Site_Address {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'set_url_scheme', array( self::class, 'keep_scheme' ), 10, 3 );
	}

	/**
	 * `set_url_scheme`.
	 *
	 * @param mixed       $url         The address WordPress built.
	 * @param string      $scheme      The scheme WordPress chose.
	 * @param string|null $orig_scheme The scheme the caller asked for (null: none).
	 * @return mixed
	 */
	public static function keep_scheme( $url, $scheme, $orig_scheme ) {
		if ( ! is_string( $url ) || 'http' !== $scheme || ( null !== $orig_scheme && '' !== $orig_scheme ) ) {
			return $url;
		}
		if ( ! self::request_cannot_tell() ) {
			return $url;
		}

		return self::upgrade( $url, array( (string) get_option( 'siteurl' ), (string) get_option( 'home' ) ) );
	}

	/**
	 * `$url` as https when it is one of `$stored` (https addresses) written as http://. Pure.
	 *
	 * @param string             $url    Address.
	 * @param array<int, string> $stored The site's stored addresses.
	 * @return string
	 */
	public static function upgrade( string $url, array $stored ): string {
		if ( 0 !== strpos( strtolower( $url ), 'http://' ) ) {
			return $url;
		}
		$rest = substr( $url, 7 );
		foreach ( $stored as $base ) {
			if ( 0 !== strpos( strtolower( $base ), 'https://' ) ) {
				continue;
			}
			$host = strtolower( untrailingslashit( substr( $base, 8 ) ) );
			if ( '' === $host ) {
				continue;
			}
			$head = strtolower( substr( $rest, 0, strlen( $host ) ) );
			$next = (string) substr( $rest, strlen( $host ), 1 );
			if ( $head === $host && in_array( $next, array( '', '/', '?', '#' ), true ) ) {
				return 'https://' . $rest;
			}
		}

		return $url;
	}

	/**
	 * Is there no browser request that says which scheme the visitor used?
	 *
	 * @return bool
	 */
	public static function request_cannot_tell(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		// A proxy that ended HTTPS before this server says so in a header.
		$proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) : '';
		if ( 'https' === trim( explode( ',', $proto )[0] ) ) {
			return true;
		}
		$visitor = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ) ) : '';

		return false !== strpos( $visitor, 'https' );
	}
}
