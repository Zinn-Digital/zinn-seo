<?php
/**
 * Calls to the Zinn Digital® API on behalf of the connected site, and what a refusal means.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Connect;

use ZinnDigital\ZinnSEO\AdminKit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only outbound HTTP the credit tools make: `https://api.zinndigital.com`, from the server,
 * and only after the site owner agreed on the connect screen (WordPress.org guideline 7). The
 * browser never calls the API.
 *
 * Every answer is an array: `ok`, `status`, `body`, and for a refusal `error`:
 *
 *   class    who has to act —
 *            `reconnect`   the connection was revoked or is unknown: connect again;
 *            `refused`     Zinn said no on purpose (no credit, the site's monthly limit, the
 *                          account is on hold, this site may not do that): no supplier is named;
 *            `outage`      a supplier Zinn uses for AI answers is down: its name and status page;
 *            `own_key`     the site owner's own key with a supplier was refused: where to fix it;
 *            `input`       what was sent cannot be used (the API's own sentence says what);
 *            `busy`        too many requests: wait `retry_after` seconds;
 *            `unreachable` this site could not reach Zinn at all;
 *            `ours`        anything else: Zinn's problem, never blamed on a supplier.
 *   code     the API's code or refusal key;
 *   message  a sentence in the reader's language (see `sentence()`);
 *   and, when the class has them: `vendor`, `status_url`, `fix_url` (+ `fix_kind`: `keys` or
 *   `billing`), `retry_after`, `params`.
 */
final class Client {

	/**
	 * Replaces the HTTP call in tests: `function ( string $method, string $url, array $args ): array|\WP_Error`
	 * returning what `wp_safe_remote_request()` returns.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/**
	 * Refusal keys that mean "Zinn declined on purpose", with the reason the screens key on.
	 *
	 * @return array<string, string>
	 */
	public static function refusals(): array {
		return array(
			'refusal.seoConnect.notEnoughCredit'   => 'no_credit',
			'refusal.pluginAi.noCredit'            => 'no_credit',
			'refusal.seoConnect.spendCapReached'   => 'site_limit',
			'refusal.seoConnect.notInGoodStanding' => 'account_hold',
			'refusal.seoConnect.insufficientScope' => 'not_allowed',
			'refusal.seoConnect.ownSiteOnly'       => 'own_site_only',
			'refusal.seoConnect.toolUnavailable'   => 'unavailable',
		);
	}

	/**
	 * An address from an API answer, kept only when it is an absolute https address: a link
	 * the screens print must never be `javascript:`, plain http or a bare path.
	 *
	 * @param mixed $url What the API sent.
	 * @return string The address, or ''.
	 */
	public static function https( $url ): string {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}

		return esc_url_raw( $url, array( 'https' ) );
	}

	/**
	 * A web address someone typed or an API named, kept only when it is an absolute http(s)
	 * address.
	 *
	 * @param mixed $url The address.
	 * @param int   $max Longest address kept.
	 * @return string The address, or ''.
	 */
	public static function web( $url, int $max = 2000 ): string {
		$url    = is_string( $url ) ? trim( $url ) : '';
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}

		return substr( esc_url_raw( $url, array( 'http', 'https' ) ), 0, $max );
	}

	/**
	 * Make one call.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path starting `/v1/`.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param string                    $token  The site's connection token, or empty.
	 * @param array<string, string>     $extra  Extra request headers.
	 * @return array<string, mixed>
	 */
	public static function call( string $method, string $path, ?array $body = null, string $token = '', array $extra = array() ): array {
		$base = Engine::base();
		if ( '' === $base ) {
			return self::failed( 0, 'unreachable', 'bad_base', array() );
		}
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'zinn-seo/' . ZINN_SEO_VERSION . '; ' . home_url(),
		) + $extra;
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args     = array(
			'method'      => $method,
			'headers'     => $headers,
			'body'        => null === $body ? null : wp_json_encode( $body ),
			'timeout'     => 25,
			'redirection' => 0,
		);
		$response = null !== self::$transport ? call_user_func( self::$transport, $method, $base . $path, $args ) : wp_safe_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return self::failed( 0, 'unreachable', 'unreachable', array() );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( $status >= 200 && $status < 400 ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'body'   => $decoded,
				'etag'   => (string) wp_remote_retrieve_header( $response, 'etag' ),
			);
		}
		$error = is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();
		$retry = 429 === $status ? max( 1, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) : 0;

		return self::classify( $status, $error, $retry );
	}

	/**
	 * Turn the API's error into who has to act and a sentence for them.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $error  The API's `error` object.
	 * @param int                  $retry  Seconds to wait (429).
	 * @return array<string, mixed>
	 */
	public static function classify( int $status, array $error, int $retry = 0 ): array {
		$code    = (string) ( $error['code'] ?? '' );
		$failure = is_array( $error['failure'] ?? null ) ? $error['failure'] : array();
		$refusal = is_array( $error['refusal'] ?? null ) ? $error['refusal'] : array();
		$key     = (string) ( $refusal['key'] ?? '' );
		$extra   = array(
			'params'      => is_array( $refusal['params'] ?? null ) ? $refusal['params'] : array(),
			'api_message' => (string) ( $error['message'] ?? '' ),
			'api_code'    => strtolower( $code ),
			'details'     => is_array( $error['details'] ?? null ) ? $error['details'] : array(),
			// The API's refusal key, whatever the class: a module with its own sentences reads it.
			'refusal'     => $key,
		);
		if ( 401 === $status ) {
			return self::failed( $status, 'reconnect', 'connection_revoked' === $code ? 'connection_revoked' : 'invalid_token', $extra );
		}
		// A classified supplier failure (the API's `failure` object) says which kind it is.
		$class = (string) ( $failure['class'] ?? '' );
		if ( 'provider_outage' === $class ) {
			return self::failed(
				$status,
				'outage',
				(string) ( $failure['code'] ?? 'outage' ),
				$extra + array(
					'vendor'      => sanitize_text_field( (string) ( $failure['vendor_name'] ?? '' ) ),
					'status_url'  => self::https( $failure['status_url'] ?? '' ),
					'retry_after' => (int) ( $failure['retry_after'] ?? 0 ),
				)
			);
		}
		if ( 'credential_problem' === $class ) {
			// The keys page for a refused key, the billing page for an unpaid one — the same order
			// the Zinn® dashboard uses (`externalActionFor`), and `fix_kind` says which it is.
			$keys    = self::https( $failure['keys_url'] ?? '' );
			$billing = self::https( $failure['billing_url'] ?? '' );
			return self::failed(
				$status,
				'own_key',
				(string) ( $failure['code'] ?? 'credential' ),
				$extra + array(
					'vendor'   => sanitize_text_field( (string) ( $failure['vendor_name'] ?? '' ) ),
					'fix_url'  => '' !== $keys ? $keys : $billing,
					'fix_kind' => '' !== $keys ? 'keys' : ( '' !== $billing ? 'billing' : '' ),
				)
			);
		}
		$known = self::refusals();
		if ( isset( $known[ $key ] ) ) {
			return self::failed( $status, 'refused', $known[ $key ], $extra );
		}
		if ( 'refused' === $class || 'insufficient_scope' === $code ) {
			return self::failed( $status, 'refused', 'insufficient_scope' === $code ? 'not_allowed' : 'no_credit', $extra );
		}
		if ( 429 === $status ) {
			return self::failed( $status, 'busy', 'slow_down', $extra + array( 'retry_after' => $retry ) );
		}
		if ( 403 === $status ) {
			// Refused for a reason this version has no sentence for: the API's own words say why.
			return self::failed( $status, 'refused', '' !== $key ? $key : 'refused', $extra );
		}
		if ( in_array( $status, array( 400, 404, 409, 422 ), true ) ) {
			return self::failed( $status, 'input', '' !== $key ? $key : sanitize_key( $code ), $extra );
		}

		return self::failed( $status, 'ours', sanitize_key( $code ), $extra );
	}

	/**
	 * A failed answer.
	 *
	 * @param int                  $status HTTP status (0: never reached).
	 * @param string               $error_class Class.
	 * @param string               $code   Code.
	 * @param array<string, mixed> $extra  More fields.
	 * @return array<string, mixed>
	 */
	private static function failed( int $status, string $error_class, string $code, array $extra ): array {
		$error            = array(
			'class' => $error_class,
			'code'  => $code,
		) + $extra;
		$error['message'] = self::sentence( $error );
		unset( $error['api_message'] );

		return array(
			'ok'     => false,
			'status' => $status,
			'body'   => array(),
			'error'  => $error,
		);
	}

	/**
	 * The sentence for a refusal, in the reader's language. Plain words, and never a supplier's
	 * name unless that supplier is the one that is down or whose key the site owner must fix.
	 *
	 * @param array<string, mixed> $error Class, code and details.
	 * @return string
	 */
	public static function sentence( array $error ): string {
		$class  = (string) ( $error['class'] ?? '' );
		$code   = (string) ( $error['code'] ?? '' );
		$vendor = (string) ( $error['vendor'] ?? '' );
		switch ( $class ) {
			case 'reconnect':
				return 'connection_revoked' === $code
					? __( 'This site was disconnected from your Zinn® account. Connect it again to carry on — your results and your credit are safe.', 'zinn-seo' )
					: __( 'Zinn® no longer recognises this site\'s connection. Connect it again to carry on — your results and your credit are safe.', 'zinn-seo' );
			case 'refused':
				switch ( $code ) {
					case 'no_credit':
						return __( 'There is not enough credit for this. Nothing was charged. Add credit and run it again — anything already scheduled picks up by itself.', 'zinn-seo' );
					case 'site_limit':
						return __( 'This site has reached the monthly spending limit set in your Zinn® account. Nothing was charged. Raise the limit there, or wait for the 1st of next month.', 'zinn-seo' );
					case 'account_hold':
						return __( 'Your Zinn® account is on hold, so paid checks are paused. Nothing was charged. Sign in to your account to sort it out and the checks carry on.', 'zinn-seo' );
					case 'not_allowed':
						return __( 'This site is connected for viewing only, so it cannot start paid checks. The owner of the Zinn® account can allow it.', 'zinn-seo' );
					case 'own_site_only':
						return __( 'This site can only check itself. To watch another site, add it in your Zinn® account.', 'zinn-seo' );
					case 'unavailable':
						return __( 'This check is not available right now. Nothing was charged. Please try again later.', 'zinn-seo' );
				}
				break;
			case 'outage':
				return '' !== $vendor
					/* translators: %s: the name of an AI company, e.g. "OpenAI". */
					? sprintf( __( '%s is having problems right now, so this could not finish. Nothing was charged. Their status page shows when it is back — then try again.', 'zinn-seo' ), $vendor )
					: __( 'The service that answers this is having problems right now. Nothing was charged. Please try again in a few minutes.', 'zinn-seo' );
			case 'own_key':
				return '' !== $vendor
					/* translators: %s: the name of an AI company, e.g. "OpenAI". */
					? sprintf( __( '%s refused your own key (it may be unpaid, out of quota or revoked). Fix it with them, then try again.', 'zinn-seo' ), $vendor )
					: __( 'Your own key was refused by its provider (it may be unpaid, out of quota or revoked). Fix it with them, then try again.', 'zinn-seo' );
			case 'busy':
				return __( 'Too many requests were sent in a short time. Wait a moment, then try again.', 'zinn-seo' );
			case 'unreachable':
				return 'bad_base' === $code
					? __( 'The Zinn® address this site is set to use is not a secure (https) address, so nothing was sent.', 'zinn-seo' )
					: __( 'This site could not reach Zinn®. Check that it can make outgoing connections, then try again.', 'zinn-seo' );
			case 'ours':
				return __( 'Something went wrong on our side. Nothing was charged. Please try again in a few minutes.', 'zinn-seo' );
		}
		// What was sent cannot be used, or a refusal this version has no sentence for: the API's
		// own sentence names the exact field or limit.
		$api = trim( (string) ( $error['api_message'] ?? '' ) );

		return '' !== $api ? $api : __( 'That could not be done. Check what you entered and try again.', 'zinn-seo' );
	}

	/**
	 * The same failure as a WP_Error for a REST answer (the screens read `data.zinn`).
	 *
	 * @param array<string, mixed> $result A failed `call()`.
	 * @return \WP_Error
	 */
	public static function as_error( array $result ): \WP_Error {
		$error  = (array) ( $result['error'] ?? array() );
		$status = (int) ( $result['status'] ?? 0 );
		$map    = array(
			'reconnect'   => 409,
			'refused'     => 402,
			'outage'      => 503,
			'own_key'     => 424,
			'input'       => 422,
			'busy'        => 429,
			'unreachable' => 502,
			'ours'        => 502,
		);
		$class  = (string) ( $error['class'] ?? 'ours' );
		unset( $error['details'] );

		return new \WP_Error(
			'zinn_seo_' . $class,
			(string) ( $error['message'] ?? '' ),
			array(
				'status' => $map[ $class ] ?? 502,
				'zinn'   => $error + array( 'http' => $status ),
			)
		);
	}
}
