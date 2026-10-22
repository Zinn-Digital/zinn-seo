<?php
/**
 * This site's connection to a Zinn® account: connect, create an account, wait, disconnect.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Connect;

use ZinnDigital\ZinnSEO\AdminKit\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site is tied to the account that pays for its checks by a device code: the plugin asks for
 * a code, the site owner approves it on zinndigital.com in a NEW TAB (signed in there — no
 * password is ever typed into wp-admin), and the site collects its own token. Someone with no
 * account types an e-mail address instead; the account is made and a set-password link is
 * e-mailed to them, and the site connects when they have signed in.
 *
 * ⛔ Nothing is sent until the site owner agrees on the connect screen: `start()` and `signup()`
 * refuse without `consent`, and the screen says exactly what is sent (the site's address, an
 * install id made for this purpose, the plugin and WordPress versions and the language).
 *
 * State lives in ONE option that is not autoloaded (`zseo_connect`), so a visitor's page never
 * reads it. The token and the pending device code are sealed with a key derived from the site's
 * salts; a copy of the database alone does not reveal them.
 */
final class Connection {

	/** The option. */
	public const OPTION = 'zseo_connect';

	/**
	 * The stored record, with every key present.
	 *
	 * @return array<string, mixed>
	 */
	public static function row(): array {
		$row = get_option( self::OPTION, array() );
		$row = is_array( $row ) ? $row : array();

		return $row + array(
			'site_uuid'     => '',
			'status'        => 'none',
			'token'         => '',
			'connection_id' => '',
			'org_id'        => '',
			'org_name'      => '',
			'scopes'        => array(),
			'connected_at'  => 0,
			'reason'        => '',
			'consent_at'    => 0,
			'pending'       => array(),
		);
	}

	/**
	 * Save the record (never autoloaded).
	 *
	 * @param array<string, mixed> $row Record.
	 * @return void
	 */
	private static function put( array $row ): void {
		update_option( self::OPTION, $row, false );
	}

	/**
	 * This install's id: made once, random, and used for nothing else. A site that connects
	 * again with the same id replaces its old token instead of leaving a second one behind.
	 *
	 * @return string
	 */
	public static function site_uuid(): string {
		$row = self::row();
		if ( 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/', (string) $row['site_uuid'] ) ) {
			return (string) $row['site_uuid'];
		}
		$row['site_uuid'] = wp_generate_uuid4();
		self::put( $row );

		return (string) $row['site_uuid'];
	}

	/**
	 * The address this site connects as.
	 *
	 * @return string
	 */
	public static function site_url(): string {
		/**
		 * Filters the address this site connects to a Zinn® account as (its home address).
		 *
		 * @param string $url The site's address.
		 */
		return (string) apply_filters( 'zinn_seo_connect_site_url', home_url( '/' ) );
	}

	/**
	 * The opened token, or empty when the site is not connected.
	 *
	 * @return string
	 */
	public static function token(): string {
		$row = self::row();
		if ( 'active' !== $row['status'] || '' === (string) $row['token'] ) {
			return '';
		}
		$token = Crypto::open( (string) $row['token'] );

		return is_string( $token ) && 1 === preg_match( '/^zsc_[A-Za-z0-9_-]{20,}$/', $token ) ? $token : '';
	}

	/**
	 * Is the site connected?
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return '' !== self::token();
	}

	/**
	 * May this site spend credit (the account's owner can connect a site for viewing only)?
	 *
	 * @return bool
	 */
	public static function can_spend(): bool {
		return self::connected() && in_array( 'seo.spend', (array) self::row()['scopes'], true );
	}

	/**
	 * What the screens show. Nothing secret: no token, no device code.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$row     = self::row();
		$pending = (array) $row['pending'];
		$waiting = ! empty( $pending['user_code'] ) && (int) ( $pending['expires_at'] ?? 0 ) > time();
		$status  = self::connected() ? 'active' : ( $waiting ? 'pending' : ( 'revoked' === $row['status'] ? 'revoked' : 'none' ) );
		$out     = array(
			'status'       => $status,
			'site_url'     => self::site_url(),
			'org_name'     => 'active' === $status ? (string) $row['org_name'] : '',
			'connected_at' => 'active' === $status ? (int) $row['connected_at'] : 0,
			'can_spend'    => self::can_spend(),
			'reason'       => 'revoked' === $status ? (string) $row['reason'] : '',
			'sends'        => self::sends(),
		);
		if ( 'pending' === $status ) {
			$out['pending'] = array(
				'user_code'  => (string) $pending['user_code'],
				'verify_url' => (string) ( $pending['verify_url'] ?? '' ),
				'expires_in' => max( 0, (int) $pending['expires_at'] - time() ),
				'interval'   => max( 3, (int) ( $pending['interval'] ?? 5 ) ),
				'method'     => (string) ( $pending['method'] ?? 'device' ),
				'account'    => (string) ( $pending['account'] ?? '' ),
				'email'      => (string) ( $pending['email'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Exactly what connecting sends, for the consent text and the readme.
	 *
	 * @return array<string, string>
	 */
	public static function sends(): array {
		global $wp_version;

		return array(
			'site_url'       => self::site_url(),
			'plugin_version' => ZINN_SEO_VERSION,
			'wp_version'     => (string) $wp_version,
			'locale'         => (string) get_user_locale(),
		);
	}

	/**
	 * Ask for a device code (the "I have an account" door).
	 *
	 * @param bool $consent The site owner agreed on the connect screen.
	 * @return array<string, mixed>|\WP_Error What `describe()` returns.
	 */
	public static function start( bool $consent ) {
		return self::begin( $consent, '/v1/seo-connect/start', array(), 'device' );
	}

	/**
	 * Make an account from an e-mail address and start connecting (the "I am new" door).
	 *
	 * @param bool   $consent The site owner agreed on the connect screen.
	 * @param string $email   Their e-mail address.
	 * @param string $name    Their name, optional.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function signup( bool $consent, string $email, string $name = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'zinn_seo_connect_email', __( 'Enter the e-mail address your account should use.', 'zinn-seo' ), array( 'status' => 400 ) );
		}

		return self::begin(
			$consent,
			'/v1/seo-connect/signup',
			array(
				'email'   => $email,
				'name'    => sanitize_text_field( $name ),
				// The API's honeypot: a person leaves it empty.
				'website' => '',
			),
			'signup'
		);
	}

	/**
	 * Start either door.
	 *
	 * @param bool                 $consent Agreed.
	 * @param string               $path    API path.
	 * @param array<string, mixed> $more    Extra body fields.
	 * @param string               $method  device|signup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function begin( bool $consent, string $path, array $more, string $method ) {
		if ( ! $consent ) {
			return new \WP_Error( 'zinn_seo_connect_consent', __( 'Tick the box to agree before connecting. Nothing is sent until you do.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$result = Client::call(
			'POST',
			$path,
			array(
				'site_uuid' => self::site_uuid(),
				'consent'   => true,
			) + self::sends() + $more
		);
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$body = (array) $result['body'];
		if ( 202 === (int) $result['status'] || empty( $body['device_code'] ) || empty( $body['user_code'] ) ) {
			return new \WP_Error( 'zinn_seo_connect_answer', __( 'Zinn® answered, but not with something this plugin understands. Nothing was saved. Please try again.', 'zinn-seo' ), array( 'status' => 502 ) );
		}
		$row               = self::row();
		$row['consent_at'] = time();
		$row['pending']    = array(
			'device_code' => Crypto::seal( (string) $body['device_code'] ),
			'user_code'   => sanitize_text_field( (string) $body['user_code'] ),
			'verify_url'  => Client::https( $body['verification_uri_complete'] ?? $body['verification_uri'] ?? '' ),
			'expires_at'  => time() + max( 60, min( 3600, (int) ( $body['expires_in'] ?? 600 ) ) ),
			'interval'    => max( 3, min( 60, (int) ( $body['interval'] ?? 5 ) ) ),
			'method'      => $method,
			'account'     => in_array( (string) ( $body['account'] ?? '' ), array( 'created', 'exists' ), true ) ? (string) $body['account'] : '',
			'email'       => (string) ( $more['email'] ?? '' ),
			'next_poll'   => 0,
		);
		if ( 'active' !== $row['status'] ) {
			$row['status'] = 'none';
		}
		self::put( $row );

		return self::describe();
	}

	/**
	 * Ask whether the site owner has approved yet; on approval, keep the token.
	 *
	 * ⭐ Never faster than the API asked (`interval`): a screen that polls eagerly gets the stored
	 * state back without a call, so the API's `slow_down` cannot be provoked from the browser.
	 *
	 * @return array<string, mixed>|\WP_Error What `describe()` returns, plus `outcome`
	 *                                        (waiting|connected|denied|expired).
	 */
	public static function poll() {
		$row     = self::row();
		$pending = (array) $row['pending'];
		if ( empty( $pending['device_code'] ) ) {
			return self::describe() + array( 'outcome' => self::connected() ? 'connected' : 'expired' );
		}
		if ( (int) ( $pending['expires_at'] ?? 0 ) <= time() ) {
			$row['pending'] = array();
			self::put( $row );

			return self::describe() + array( 'outcome' => 'expired' );
		}
		if ( (int) ( $pending['next_poll'] ?? 0 ) > time() ) {
			return self::describe() + array( 'outcome' => 'waiting' );
		}
		$code = Crypto::open( (string) $pending['device_code'] );
		if ( ! is_string( $code ) || '' === $code ) {
			$row['pending'] = array();
			self::put( $row );

			return self::describe() + array( 'outcome' => 'expired' );
		}
		$result = Client::call( 'POST', '/v1/seo-connect/token', array( 'device_code' => $code ) );
		if ( $result['ok'] ) {
			$body  = (array) $result['body'];
			$token = (string) ( $body['token'] ?? '' );
			if ( 1 !== preg_match( '/^zsc_[A-Za-z0-9_-]{20,}$/', $token ) ) {
				return new \WP_Error( 'zinn_seo_connect_answer', __( 'Zinn® answered, but not with something this plugin understands. Nothing was saved. Please try again.', 'zinn-seo' ), array( 'status' => 502 ) );
			}
			$org                  = (array) ( $body['org'] ?? array() );
			$row['status']        = 'active';
			$row['token']         = Crypto::seal( $token );
			$row['connection_id'] = sanitize_text_field( (string) ( $body['connection_id'] ?? '' ) );
			$row['org_id']        = sanitize_text_field( (string) ( $org['id'] ?? '' ) );
			$row['org_name']      = sanitize_text_field( (string) ( $org['name'] ?? '' ) );
			$row['scopes']        = array_values( array_map( 'sanitize_text_field', (array) ( $body['scopes'] ?? array() ) ) );
			$row['connected_at']  = time();
			$row['reason']        = '';
			$row['pending']       = array();
			self::put( $row );

			/**
			 * Fires when the site has been connected to a Zinn® account.
			 *
			 * @param array<string, mixed> $connection What `Connection::describe()` returns.
			 */
			do_action( 'zinn_seo_connected', self::describe() );

			return self::describe() + array( 'outcome' => 'connected' );
		}
		$code_back = (string) ( $result['error']['code'] ?? '' );
		$api       = strtolower( (string) ( $result['error']['api_code'] ?? '' ) );
		$state     = self::pending_state( (int) $result['status'], $code_back, $api );
		if ( 'waiting' === $state || 'slow' === $state ) {
			$wait                        = max( 3, (int) ( $pending['interval'] ?? 5 ) ) + ( 'slow' === $state ? 5 : 0 );
			$row['pending']['interval']  = $wait;
			$row['pending']['next_poll'] = time() + $wait - 1;
			self::put( $row );

			return self::describe() + array( 'outcome' => 'waiting' );
		}
		if ( 'denied' === $state || 'expired' === $state ) {
			$row['pending'] = array();
			self::put( $row );

			return self::describe() + array( 'outcome' => $state );
		}

		return Client::as_error( $result );
	}

	/**
	 * What a refused token exchange means.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   The classified code.
	 * @param string $api    The API's own error code, lower case.
	 * @return string waiting|slow|denied|expired|error.
	 */
	public static function pending_state( int $status, string $code, string $api ): string {
		$seen = '' !== $api ? $api : strtolower( $code );
		if ( 429 === $status || 'slow_down' === $seen ) {
			return 'slow';
		}
		if ( 400 !== $status ) {
			return 'error';
		}
		if ( 'authorization_pending' === $seen ) {
			return 'waiting';
		}
		if ( 'access_denied' === $seen ) {
			return 'denied';
		}
		if ( 'expired_token' === $seen ) {
			return 'expired';
		}

		return 'error';
	}

	/**
	 * Stop waiting for an approval.
	 *
	 * @return array<string, mixed>
	 */
	public static function cancel(): array {
		$row            = self::row();
		$row['pending'] = array();
		self::put( $row );

		return self::describe();
	}

	/**
	 * Disconnect: tell Zinn® to retire the token, then forget it here. The local token goes
	 * even when Zinn® cannot be reached, so "Disconnect" always disconnects this site.
	 *
	 * @return array<string, mixed>
	 */
	public static function disconnect(): array {
		$token = self::token();
		if ( '' !== $token ) {
			Client::call( 'DELETE', '/v1/seo-connection', null, $token );
		}
		$row                  = self::row();
		$row['status']        = 'none';
		$row['token']         = '';
		$row['connection_id'] = '';
		$row['org_id']        = '';
		$row['org_name']      = '';
		$row['scopes']        = array();
		$row['pending']       = array();
		$row['reason']        = '';
		self::put( $row );

		/** Fires when the site has been disconnected from its Zinn® account. */
		do_action( 'zinn_seo_disconnected' );

		return self::describe();
	}

	/**
	 * The API said the token is no longer good: remember why, and stop using it.
	 *
	 * @param string $reason connection_revoked|invalid_token.
	 * @return void
	 */
	public static function mark_revoked( string $reason ): void {
		$row = self::row();
		if ( 'active' !== $row['status'] ) {
			return;
		}
		$row['status'] = 'revoked';
		$row['token']  = '';
		$row['reason'] = sanitize_key( $reason );
		self::put( $row );

		/** Fires when the site has been disconnected from its Zinn® account. */
		do_action( 'zinn_seo_disconnected' );
	}

	/**
	 * One call with the site's token. A site that is not connected, and a token the API no longer
	 * accepts, both answer as `reconnect` — and the second is remembered so the next screen
	 * says "connect again" without another call.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path starting `/v1/`.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param array<string, string>     $extra  Extra headers.
	 * @return array<string, mixed>
	 */
	public static function call( string $method, string $path, ?array $body = null, array $extra = array() ): array {
		$token = self::token();
		if ( '' === $token ) {
			return Client::classify( 401, array( 'code' => 'revoked' === self::row()['status'] ? 'connection_revoked' : 'invalid_token' ) );
		}
		$result = Client::call( $method, $path, $body, $token, $extra );
		if ( ! $result['ok'] && 'reconnect' === ( $result['error']['class'] ?? '' ) ) {
			self::mark_revoked( (string) $result['error']['code'] );
		}

		return $result;
	}
}
