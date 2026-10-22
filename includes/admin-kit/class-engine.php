<?php
/**
 * Talking to the Zinn Digital API, server-side only.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-engine.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnSEO\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only outbound HTTP the kit makes: the plugin-support endpoints of
 * `https://api.zinndigital.com` (docs/plugins-overhaul/x1-x7-contract.md §2). The browser never
 * calls the API; the plugin's own REST routes call it from the server (contract §3).
 *
 * Paths used, each only after the site owner acts on the Get help screen and has agreed on the
 * consent screen: `/v1/plugin-support/connections`, `/v1/plugin-support/connection`,
 * `/v1/plugin-support/tickets`.
 */
final class Engine {

	/**
	 * The API base: https only. The filter exists for a staging API; a plain-http base is
	 * accepted only on a site whose environment type is `local` or `development`, so a
	 * compromised filter on a production site cannot send a support ticket in clear text.
	 *
	 * @return string Absolute URL with no trailing slash, or empty when refused.
	 */
	public static function base(): string {
		$default = (string) ( Data::get( 'products' )['api_base'] ?? 'https://api.zinndigital.com' );

		/**
		 * Filters the Zinn Digital API base the support screens call.
		 *
		 * @param string $base Absolute URL, no trailing slash.
		 */
		$base   = untrailingslashit( (string) apply_filters( 'zseo_kit_api_base', $default ) );
		$scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
		if ( 'https' === $scheme ) {
			return $base;
		}
		if ( 'http' === $scheme && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return $base;
		}

		return '';
	}

	/**
	 * Make one call.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path under the base, starting `/v1/`.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param string                    $token  Bearer connection token, or empty.
	 * @return array{ok: bool, status: int, body: array<string, mixed>, code: string, message: string, retry_after: int}
	 */
	public static function call( string $method, string $path, ?array $body = null, string $token = '' ): array {
		$base = self::base();
		if ( '' === $base ) {
			return self::failure( 0, 'bad_base', __( 'The Zinn Digital® address this site is set to use is not a secure (https) address, so nothing was sent.', 'zinn-seo' ) );
		}
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => Kit::host( 'slug' ) . '/' . Kit::host( 'version' ) . '; ' . home_url(),
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$response = wp_safe_remote_request(
			$base . $path,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => null === $body ? null : wp_json_encode( $body ),
				'timeout'     => 20,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::failure( 0, 'unreachable', __( 'Zinn Digital® could not be reached from this site. Check that the site can make outgoing connections, then try again.', 'zinn-seo' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'          => true,
				'status'      => $status,
				'body'        => $decoded,
				'code'        => '',
				'message'     => '',
				'retry_after' => 0,
			);
		}

		$error   = is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();
		$code    = sanitize_key( (string) ( $error['code'] ?? '' ) );
		$message = self::message_for( $status, $code );
		$result  = self::failure( $status, $code, $message );
		if ( 429 === $status ) {
			$result['retry_after'] = max( 1, (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
		}

		return $result;
	}

	/**
	 * Our own words for an API refusal. The API's message is not shown: it is English only and
	 * written for developers, and this screen is read in 58 languages.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   API error code.
	 * @return string
	 */
	private static function message_for( int $status, string $code ): string {
		if ( 401 === $status && 'connection_revoked' === $code ) {
			return __( 'This site\'s connection to Zinn Digital® was revoked. Connect it again to continue.', 'zinn-seo' );
		}
		if ( 401 === $status ) {
			return __( 'Zinn Digital® no longer recognises this site\'s connection. Connect it again to continue.', 'zinn-seo' );
		}
		if ( 429 === $status ) {
			return __( 'Too many requests were sent from this site or address. Wait a little, then try again.', 'zinn-seo' );
		}
		if ( 422 === $status || 400 === $status ) {
			return __( 'Zinn Digital® could not accept this request. Check the email address and the message, then try again.', 'zinn-seo' );
		}

		return __( 'Zinn Digital® could not handle the request just now. Try again in a few minutes.', 'zinn-seo' );
	}

	/**
	 * A failure result.
	 *
	 * @param int    $status  HTTP status (0 when none).
	 * @param string $code    Machine code.
	 * @param string $message Our message.
	 * @return array{ok: bool, status: int, body: array<string, mixed>, code: string, message: string, retry_after: int}
	 */
	private static function failure( int $status, string $code, string $message ): array {
		return array(
			'ok'          => false,
			'status'      => $status,
			'body'        => array(),
			'code'        => $code,
			'message'     => $message,
			'retry_after' => 0,
		);
	}
}
