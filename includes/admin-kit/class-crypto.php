<?php
/**
 * Sealing the site-connection token at rest.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-crypto.php by wp/bin/build-admin-kit.php.
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
 * Sealing with libsodium secretbox, using a random key made once per site.
 *
 * The key is 32 bytes from `random_bytes()`, stored base64 in this plugin's own option, which is
 * NOT autoloaded. Values sealed by an older kit (`v1:`) used a key derived from the site's salts;
 * a self-hosted build still opens them, and `Connection::token()` seals them again with the random
 * key the first time it reads them, so a new install never derives the old key. The wordpress.org
 * build carries no salt derivation at all. A value that cannot be opened (the
 * key option was lost, or a newer format wrote it) opens to null and the connection reads as
 * disconnected, never as a garbled token sent to the API.
 */
final class Crypto {

	/** Version tag on a value sealed with the random key. */
	private const PREFIX = 'v2:';

	/** Version tag on a value sealed by an older kit, with the salt-derived key. */
	private const LEGACY_PREFIX = 'v1:';

	/** This plugin's option holding the random key, base64. */
	public const KEY_OPTION = 'zseo_kit_crypto_key';

	// dotorg-omit:begin
	/** HKDF context of the older key. A different context from the AI core's, so the two never coincide. */
	private const LEGACY_INFO = 'zinn-admin-kit/connection/v1';
	// dotorg-omit:end

	/**
	 * The random key, once read this request.
	 *
	 * @var string|null
	 */
	private static ?string $key = null;

	/**
	 * Seal a secret.
	 *
	 * @param string $plain Plain value.
	 * @return string
	 */
	public static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		// Sodium's own codec rather than base64_encode(): same text, and it keeps the plugins free
		// of the base64 functions their security review forbids (PbsSecurityFindingsTest §7).
		return self::PREFIX . sodium_bin2base64( $nonce . $box, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Open a sealed value: the random key first, then (for an older value) the salt-derived one.
	 *
	 * @param string $sealed Sealed value.
	 * @return string|null
	 */
	public static function open( string $sealed ): ?string {
		if ( str_starts_with( $sealed, self::PREFIX ) ) {
			return self::unbox( substr( $sealed, strlen( self::PREFIX ) ), self::key() );
		}
		if ( ! str_starts_with( $sealed, self::LEGACY_PREFIX ) ) {
			return null;
		}
		$body  = substr( $sealed, strlen( self::LEGACY_PREFIX ) );
		$plain = self::unbox( $body, self::key() );
		// dotorg-omit:begin
		if ( null === $plain ) {
			$plain = self::unbox( $body, self::legacy_key() );
		}
		// dotorg-omit:end

		return $plain;
	}

	/**
	 * Was this value sealed by an older kit, so it should be sealed again?
	 *
	 * @param string $sealed Sealed value.
	 * @return bool
	 */
	public static function is_legacy( string $sealed ): bool {
		return str_starts_with( $sealed, self::LEGACY_PREFIX );
	}

	/**
	 * Forget the key read this request (tests).
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$key = null;
	}

	/**
	 * Open the base64 body of a sealed value with one key.
	 *
	 * @param string $body Base64 of nonce . ciphertext.
	 * @param string $key  32-byte key.
	 * @return string|null
	 */
	private static function unbox( string $body, string $key ): ?string {
		try {
			$raw = sodium_base642bin( $body, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return null;
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * The random 32-byte key, made the first time it is needed.
	 *
	 * @return string
	 */
	private static function key(): string {
		if ( null !== self::$key ) {
			return self::$key;
		}
		$key = self::decode_key( get_option( self::KEY_OPTION, '' ) );
		if ( null === $key ) {
			$made = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			// Not autoloaded: the key is read only when a secret is sealed or opened.
			if ( add_option( self::KEY_OPTION, sodium_bin2base64( $made, SODIUM_BASE64_VARIANT_ORIGINAL ), '', false ) ) {
				$key = $made;
			} else {
				// Another request made it first: use that one, so both agree.
				$key = self::decode_key( get_option( self::KEY_OPTION, '' ) ) ?? $made;
			}
		}
		self::$key = $key;

		return $key;
	}

	/**
	 * A stored key, decoded, or null when it is missing or not 32 bytes.
	 *
	 * @param mixed $stored Option value.
	 * @return string|null
	 */
	private static function decode_key( $stored ): ?string {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		try {
			$key = sodium_base642bin( $stored, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return null;
		}

		return SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $key ) ? $key : null;
	}

	// dotorg-omit:begin
	/**
	 * The older key, derived from the site's salts. Only ever used to open a `v1:` value.
	 *
	 * @return string
	 */
	private static function legacy_key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::LEGACY_INFO );
	}
	// dotorg-omit:end
}
