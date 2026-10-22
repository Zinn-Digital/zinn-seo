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
 * Sealing with libsodium secretbox, using a key derived from the site's own salts (docs/adr/0034's scheme).
 *
 * The key is HKDF-SHA256 over `wp_salt( 'auth' )`, which lives in `wp-config.php`, so a copy of
 * the database alone does not reveal the token. A value that cannot be opened (the salts
 * changed, or a newer format wrote it) opens to null and the connection reads as disconnected,
 * never as a garbled token sent to the API.
 */
final class Crypto {

	/** Version tag on every sealed value. */
	private const PREFIX = 'v1:';

	/** HKDF context. A different context from the AI core's, so the two keys never coincide. */
	private const INFO = 'zinn-admin-kit/connection/v1';

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
	 * Open a sealed value.
	 *
	 * @param string $sealed Sealed value.
	 * @return string|null
	 */
	public static function open( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		try {
			$raw = sodium_base642bin( substr( $sealed, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return null;
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * The 32-byte key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::INFO );
	}
}
