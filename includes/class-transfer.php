<?php
/**
 * Settings export and import, and copying settings to another site (zs-249).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An export is a small JSON document: a format marker, the plugin version, where it came from and
 * the settings. ⛔ Keys marked `export => false` (the site's own no-trace hash) and anything
 * secret never leave the site, so a file handed to someone else carries no credential.
 *
 * An import is always previewed first: dry_run lists what would change, key by key, and refuses
 * the whole file when any value is not allowed, so a half-imported site is impossible. A site
 * address inside a value (the canonical base, say) is moved to the importing site's address.
 */
final class Transfer {

	/** Format marker. */
	public const FORMAT = 'zinn-seo-settings';

	/** Format version. */
	public const VERSION = 1;

	/**
	 * The export document.
	 *
	 * @return array<string, mixed>
	 */
	public static function export(): array {
		$schema   = Settings::schema();
		$settings = array();
		foreach ( Settings::all() as $key => $value ) {
			$def = $schema[ $key ] ?? array();
			if ( false === ( $def['export'] ?? true ) || ! empty( $def['secret'] ) ) {
				continue;
			}
			$settings[ $key ] = $value;
		}

		return array(
			'format'   => self::FORMAT,
			'version'  => self::VERSION,
			'plugin'   => ZINN_SEO_VERSION,
			'site'     => home_url( '/' ),
			'exported' => gmdate( 'c' ),
			'settings' => $settings,
		);
	}

	/**
	 * Import a document: preview (dry run) or apply.
	 *
	 * @param mixed $doc     The decoded document (or a JSON string).
	 * @param bool  $dry_run Only report what would change.
	 * @return array{changes: array<int, array{key: string, from: mixed, to: mixed}>, applied: bool}|\WP_Error
	 */
	public static function import( $doc, bool $dry_run = true ) {
		if ( is_string( $doc ) ) {
			$doc = json_decode( $doc, true );
		}
		if ( ! is_array( $doc ) || self::FORMAT !== ( $doc['format'] ?? '' ) || ! is_array( $doc['settings'] ?? null ) ) {
			return new \WP_Error( 'zinn_seo_import_format', __( 'This is not a Zinn® SEO settings file.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $doc['version'] ?? 0 ) > self::VERSION ) {
			return new \WP_Error( 'zinn_seo_import_newer', __( 'This file was made by a newer version of Zinn® SEO. Update the plugin on this site, then import it again.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$schema   = Settings::schema();
		$incoming = array();
		foreach ( (array) $doc['settings'] as $key => $value ) {
			$def = $schema[ (string) $key ] ?? null;
			if ( null === $def || false === ( $def['export'] ?? true ) || ! empty( $def['secret'] ) ) {
				continue;
			}
			$incoming[ (string) $key ] = self::move_address( $value, (string) ( $doc['site'] ?? '' ), home_url( '/' ) );
		}
		$checked = Settings::validate( $incoming );
		if ( array() !== $checked['errors'] ) {
			return new \WP_Error(
				'zinn_seo_import_invalid',
				__( 'Nothing was imported, because some values in the file are not allowed:', 'zinn-seo' ) . ' ' . implode( ' ', $checked['errors'] ),
				array(
					'status' => 400,
					'fields' => $checked['errors'],
				)
			);
		}
		$current = Settings::all();
		$changes = array();
		foreach ( $checked['clean'] as $key => $value ) {
			if ( ( $current[ $key ] ?? null ) !== $value ) {
				$changes[] = array(
					'key'  => $key,
					'from' => $current[ $key ] ?? null,
					'to'   => $value,
				);
			}
		}
		if ( $dry_run || array() === $changes ) {
			return array(
				'changes' => $changes,
				'applied' => false,
			);
		}
		$saved = Settings::save( $checked['clean'], 'import' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'changes' => $changes,
			'applied' => true,
		);
	}

	/**
	 * Move the exporting site's address to this site's inside a string value.
	 *
	 * @param mixed  $value Value.
	 * @param string $from  The exporting site's home address.
	 * @param string $to    This site's home address.
	 * @return mixed
	 */
	public static function move_address( $value, string $from, string $to ) {
		if ( ! is_string( $value ) || '' === $from || $from === $to ) {
			return $value;
		}
		$from = untrailingslashit( $from );
		$to   = untrailingslashit( $to );

		return 0 === strpos( $value, $from ) ? $to . substr( $value, strlen( $from ) ) : $value;
	}
}
