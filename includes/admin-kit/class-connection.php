<?php
/**
 * Connecting this site to a Zinn Digital support contact (X7).
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-connection.php by wp/bin/build-admin-kit.php.
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
 * One optional, revocable connection per plugin (docs/plugins-overhaul/x1-x7-contract.md §2.1-2.3).
 *
 * The API returns a token once; it is stored sealed (Crypto) and sent only as a Bearer header to
 * the support endpoints. Its scopes are support tickets and diagnostics: it can file a ticket,
 * and nothing else. ⛔ It is not a login and nothing here accepts a call INTO the site.
 *
 * Revoking works from both sides: "Disconnect" here calls the API and forgets the token even if
 * the API cannot be reached (so this site never sends it again), and a revoke on the Zinn side
 * makes the next call answer 401, which this class records as the reason on the card.
 */
final class Connection {

	/** Option holding the connection. Per plugin: the renderer rewrites the prefix. */
	public const OPTION = 'zseo_kit_connection';

	/** How long a status read from the API is trusted. */
	private const STATUS_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * The stored connection, without the token.
	 *
	 * @return array{connected: bool, email: string, connectionId: string, connectedAt: int, status: string, reason: string, checkedAt: int, lastUsedAt: string}
	 */
	public static function describe(): array {
		$row = self::row();

		return array(
			'connected'    => '' !== (string) ( $row['token'] ?? '' ) && 'active' === ( $row['status'] ?? '' ),
			'email'        => (string) ( $row['email'] ?? '' ),
			'connectionId' => (string) ( $row['connection_id'] ?? '' ),
			'connectedAt'  => (int) ( $row['connected_at'] ?? 0 ),
			'status'       => (string) ( $row['status'] ?? 'none' ),
			'reason'       => (string) ( $row['reason'] ?? '' ),
			'checkedAt'    => (int) ( $row['checked_at'] ?? 0 ),
			'lastUsedAt'   => (string) ( $row['last_used_at'] ?? '' ),
		);
	}

	/**
	 * Connect: create the contact's connection and keep the token, sealed.
	 *
	 * @param string $email Contact e-mail.
	 * @param string $name  Contact name, optional.
	 * @return array{ok: bool, message: string, connection: array<string, mixed>}
	 */
	public static function connect( string $email, string $name ): array {
		global $wp_version;

		$result = Engine::call(
			'POST',
			'/v1/plugin-support/connections',
			array(
				'email'          => $email,
				'name'           => $name,
				'product'        => Kit::host( 'slug' ),
				'site_url'       => home_url(),
				'site_name'      => get_bloginfo( 'name' ),
				'plugin_version' => Kit::host( 'version' ),
				'wp_version'     => (string) $wp_version,
				'php_version'    => PHP_VERSION,
				'locale'         => get_user_locale(),
				'consent'        => true,
				'website'        => '',
			)
		);
		if ( ! $result['ok'] ) {
			return array(
				'ok'         => false,
				'message'    => $result['message'],
				'connection' => self::describe(),
			);
		}
		$token = (string) ( $result['body']['token'] ?? '' );
		if ( 1 !== preg_match( '/^zps_[A-Za-z0-9_-]{20,}$/', $token ) ) {
			return array(
				'ok'         => false,
				'message'    => __( 'Zinn Digital® answered, but not with a connection this plugin understands. Nothing was saved.', 'zinn-seo' ),
				'connection' => self::describe(),
			);
		}
		update_option(
			self::OPTION,
			array(
				'token'         => Crypto::seal( $token ),
				'connection_id' => sanitize_text_field( (string) ( $result['body']['connection_id'] ?? '' ) ),
				'email'         => sanitize_email( (string) ( $result['body']['contact']['email'] ?? $email ) ),
				'connected_at'  => time(),
				'status'        => 'active',
				'reason'        => '',
				'checked_at'    => time(),
				'last_used_at'  => '',
			),
			false
		);

		return array(
			'ok'         => true,
			'message'    => '',
			'connection' => self::describe(),
		);
	}

	/**
	 * The connection's status, refreshed from the API at most every ten minutes.
	 *
	 * @param bool $force Ask the API now.
	 * @return array<string, mixed>
	 */
	public static function status( bool $force = false ): array {
		$row   = self::row();
		$token = self::token();
		if ( '' === $token || 'active' !== ( $row['status'] ?? '' ) ) {
			return self::describe();
		}
		if ( ! $force && time() - (int) ( $row['checked_at'] ?? 0 ) < self::STATUS_TTL ) {
			return self::describe();
		}
		$result = Engine::call( 'GET', '/v1/plugin-support/connection', null, $token );
		if ( $result['ok'] ) {
			$row['last_used_at'] = sanitize_text_field( (string) ( $result['body']['last_used_at'] ?? '' ) );
			$row['checked_at']   = time();
			update_option( self::OPTION, $row, false );
		} elseif ( 401 === $result['status'] ) {
			self::mark_revoked( 'connection_revoked' === $result['code'] ? 'revoked_by_zinn' : 'unknown_to_zinn' );
		}

		return self::describe();
	}

	/**
	 * Disconnect from this side.
	 *
	 * @return array{ok: bool, message: string, connection: array<string, mixed>}
	 */
	public static function revoke(): array {
		$token   = self::token();
		$message = '';
		if ( '' !== $token ) {
			$result = Engine::call( 'DELETE', '/v1/plugin-support/connection', null, $token );
			if ( ! $result['ok'] && 401 !== $result['status'] ) {
				// Forget the token anyway, so this site never sends it again; say so.
				$message = __( 'This site has forgotten its connection. Zinn Digital® could not be told just now, so support staff will see it as unused until it is removed on their side.', 'zinn-seo' );
			}
		}
		self::mark_revoked( 'revoked_here' );

		return array(
			'ok'         => true,
			'message'    => $message,
			'connection' => self::describe(),
		);
	}

	/**
	 * The plain token, or empty when there is none or it cannot be opened.
	 *
	 * @return string
	 */
	public static function token(): string {
		$row = self::row();
		if ( 'active' !== ( $row['status'] ?? '' ) ) {
			return '';
		}
		$plain = Crypto::open( (string) ( $row['token'] ?? '' ) );
		if ( null === $plain ) {
			// The salts changed, or the value is damaged: the site is no longer connected, and
			// the card says why rather than sending a garbled token.
			self::mark_revoked( 'unreadable' );

			return '';
		}

		return $plain;
	}

	/**
	 * Record that the connection has ended, and why. The token is dropped.
	 *
	 * @param string $reason `revoked_here`, `revoked_by_zinn` or `unknown_to_zinn`.
	 * @return void
	 */
	public static function mark_revoked( string $reason ): void {
		$row = self::row();
		if ( array() === $row ) {
			return;
		}
		$row['token']      = '';
		$row['status']     = 'revoked';
		$row['reason']     = sanitize_key( $reason );
		$row['checked_at'] = time();
		update_option( self::OPTION, $row, false );
	}

	/**
	 * The stored row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(): array {
		$row = get_option( self::OPTION, array() );

		return is_array( $row ) ? $row : array();
	}
}
