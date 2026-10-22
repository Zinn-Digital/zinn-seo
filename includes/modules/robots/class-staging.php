<?php
/**
 * Staging awareness: a copy of a site is never indexed, never pings, and is never leaked into
 * the live site's sitemap unnoticed (zs-332).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Robots;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A staging or development copy is recognised by WordPress's own environment type (`staging`,
 * `development`, `local`), by its address — the host patterns of the common hosts and of our own
 * platforms (V2 `stg-<12 hex>.` copies and `zinnpreview.com`, V1
 * temporary addresses `<40 hex>.`) — or by the owner's own switch and host list.
 *
 * On a staging copy: every page answers noindex/nofollow, robots.txt blocks everything, IndexNow
 * refuses to ping, and the screens say so. The live site's sitemap build reports any address on
 * another host (Builder::check, `foreign_host`).
 */
final class Staging {

	/**
	 * Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array_intersect_key( require __DIR__ . '/settings.php', array_flip( array( 'staging_mode', 'staging_hosts' ) ) );
	}

	/**
	 * Why this site is a staging copy, or '' when it is live.
	 *
	 * @return string env|host|setting|''
	 */
	public static function reason(): string {
		$mode = (string) Settings::get( 'staging_mode', 'auto' );
		if ( 'off' === $mode ) {
			return '';
		}
		if ( 'on' === $mode ) {
			return 'setting';
		}
		$env = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';

		return self::detect( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $env, (string) Settings::get( 'staging_hosts', '' ) );
	}

	/**
	 * Is this site a staging copy?
	 *
	 * @return bool
	 */
	public static function is_staging(): bool {
		return '' !== self::reason();
	}

	/**
	 * Detect from a host, an environment type and extra patterns. Pure, for the tests.
	 *
	 * @param string $host  Host.
	 * @param string $env   `wp_get_environment_type()`.
	 * @param string $extra The owner's own patterns, one per line (`*` wildcard).
	 * @return string env|host|''
	 */
	public static function detect( string $host, string $env, string $extra = '' ): string {
		if ( in_array( $env, array( 'staging', 'development', 'local' ), true ) ) {
			return 'env';
		}
		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return '';
		}
		foreach ( preg_split( '/[\r\n,]+/', $extra ) as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );
			if ( '' !== $pattern && 1 === preg_match( '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#', $host ) ) {
				return 'host';
			}
		}
		$patterns = array(
			// Our own platforms: V2 preview hosts (its staging copies are `stg-<12 hex>.` under them,
			// which the `stg-` rule below also catches on any domain), V1 temporary
			// addresses.
			'/\.zinnpreview\.com$/',
			'/^[0-9a-f]{40}\./',
			// Words and suffixes hosts use for copies.
			'/^(staging|stage|stg|dev|development|test|testing|qa|uat|preview|sandbox)[.-]/',
			'/\.(local|test|localhost|invalid|example|staging)$/',
			// Common managed hosts' copies.
			'/\.(wpengine|wpenginepowered)\.com$/',
			'/\.(kinsta\.cloud|kinsta\.com)$/',
			'/\.(flywheelstaging|flywheelsites)\.com$/',
			'/\.mystagingwebsite\.com$/',
			'/\.(instawp\.(co|link|xyz)|tastewp\.com)$/',
			'/\.(pantheonsite\.io)$/',
			'/\.cloudwaysapps\.com$/',
			'/\.(myftpupload|wpsandbox\.pro|tempurl\.host|wpmudev\.host|websitepro-staging)\.com$/',
			'/\.(ngrok\.io|ngrok-free\.app|ngrok\.app|loca\.lt|trycloudflare\.com)$/',
			'/-dev\.10web\.(site|cloud)$/',
		);
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $host ) ) {
				return 'host';
			}
		}

		return '';
	}

	/**
	 * Can search engines reach this host at all? Not `localhost`, a bare IP address or a
	 * reserved name (a ping from there names addresses nobody can fetch). Pure, for the tests.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function public_host( string $host ): bool {
		$host = strtolower( trim( $host ) );

		return '' !== $host && false !== strpos( $host, '.' ) && 1 !== preg_match( '/^(\d{1,3}\.){3}\d{1,3}$|^\[|\.(local|test|localhost|invalid|example|internal|lan|home\.arpa)$/', $host );
	}

	/**
	 * Hooks (only on a staging copy).
	 *
	 * @param string $context Request context.
	 * @return void
	 */
	public static function boot( string $context ): void {
		if ( ! self::is_staging() ) {
			return;
		}
		if ( 'front' === $context ) {
			add_filter( 'wp_robots', array( self::class, 'robots' ), 99 );
			add_action( 'send_headers', array( self::class, 'header' ) );
		}
	}

	/**
	 * The robots directives of every page: noindex, nofollow.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'], $robots['index'], $robots['follow'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	/**
	 * The same as a header, for files and feeds.
	 *
	 * @return void
	 */
	public static function header(): void {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', false );
		}
	}
}
