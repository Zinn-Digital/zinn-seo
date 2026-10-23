<?php
/**
 * The translated addresses of one page, from whichever translation plugin the site runs (0.17.65).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A translation plugin that gives the SAME post or term an address per language (`/es/…`, often
 * with a translated slug) changes those addresses when the page's own address changes — so a
 * redirect written for the English address only left every other language a 404 (ZHORD6 on
 * zinnhub.com, 2026-10-22). This is the one adapter the automatic redirects ask: language =>
 * address of the same page, the original left out.
 *
 * Providers, each only when its plugin is running: Zinn Translate (`Zinn_Translate_Router`), the
 * zinnhub build of it (`ZT_Url` — its frozen `_zt_slug_<lang>` slugs), Tranzly
 * (`tranzly_language_url`) and TranslatePress (its URL converter). Any other plugin adds itself on
 * the `zinn_seo_translated_addresses` filter.
 *
 * WPML and Polylang keep each language as its OWN post or term: a change to one fires that object's
 * own hooks with its own address (`/es/…`), so the automatic redirects already cover each language
 * there, and their other-language posts are never touched by a change to this one.
 */
final class Translated {

	/**
	 * Replaces the providers in tests.
	 *
	 * @var callable|null
	 */
	public static $providers = null;

	/**
	 * The other-language addresses of a page on this site.
	 *
	 * @param string $url Its address in the site's own language.
	 * @return array<string, string> Language => address (absolute), the original excluded.
	 */
	public static function of( string $url ): array {
		if ( '' === $url ) {
			return array();
		}
		$out = array();
		foreach ( self::providers() as $provider ) {
			try {
				foreach ( (array) $provider( $url ) as $lang => $u ) {
					$u = (string) $u;
					if ( is_string( $lang ) && '' !== $lang && '' !== $u && $u !== $url && ! isset( $out[ $lang ] ) ) {
						$out[ $lang ] = $u;
					}
				}
			} catch ( \Throwable $e ) {
				continue; // A translation plugin's failure never stops a redirect being written.
			}
		}

		/**
		 * The other-language addresses of a page, for the automatic redirects (and the cache
		 * purge that follows a redirect save).
		 *
		 * @param array<string, string> $out Language => address.
		 * @param string                $url The page's address in the site's own language.
		 */
		return (array) apply_filters( 'zinn_seo_translated_addresses', $out, $url );
	}

	/**
	 * Paths of the same page in every language: language => path ('' = the original).
	 *
	 * @param string $url Address.
	 * @return array<string, string>
	 */
	public static function paths( string $url ): array {
		$out = array( '' => Auto::path( $url ) );
		foreach ( self::of( $url ) as $lang => $u ) {
			$path = Auto::path( $u );
			if ( '' !== $path ) {
				$out[ $lang ] = $path;
			}
		}

		return '' === $out[''] ? array() : $out;
	}

	/**
	 * The running translation plugins' answers.
	 *
	 * @return array<int, callable>
	 */
	private static function providers(): array {
		if ( is_callable( self::$providers ) ) {
			return (array) call_user_func( self::$providers );
		}
		$out = array();
		if ( class_exists( '\Zinn_Translate_Router' ) && class_exists( '\Zinn_Translate_Options' ) ) {
			$out[] = static function ( string $url ): array {
				$r = array();
				foreach ( (array) \Zinn_Translate_Options::locales() as $locale ) {
					$r[ (string) $locale ] = \Zinn_Translate_Router::localise_url( $url, (string) $locale );
				}
				return $r;
			};
		}
		if ( class_exists( '\ZT_Url' ) && class_exists( '\ZT_Launch' ) && method_exists( '\ZT_Url', 'localize_to_batch' ) ) {
			$out[] = static fn( string $url ): array => (array) \ZT_Url::localize_to_batch( $url, (array) \ZT_Launch::public_languages() );
		}
		if ( function_exists( 'tranzly_languages' ) && function_exists( 'tranzly_language_url' ) && function_exists( 'tranzly_default_language' ) ) {
			$out[] = static function ( string $url ): array {
				$r       = array();
				$default = (string) tranzly_default_language();
				foreach ( (array) tranzly_languages() as $l ) {
					$code = (string) ( $l['code'] ?? '' );
					if ( '' !== $code && $code !== $default ) {
						$r[ $code ] = (string) tranzly_language_url( $code, $url );
					}
				}
				return $r;
			};
		}
		if ( class_exists( '\TRP_Translate_Press' ) ) {
			$out[] = static function ( string $url ): array {
				$trp      = \TRP_Translate_Press::get_trp_instance();
				$settings = (array) $trp->get_component( 'settings' )->get_settings();
				$convert  = $trp->get_component( 'url_converter' );
				$r        = array();
				foreach ( (array) ( $settings['publish-languages'] ?? array() ) as $code ) {
					if ( (string) ( $settings['default-language'] ?? '' ) !== (string) $code ) {
						$r[ (string) $code ] = (string) $convert->get_url_for_language( (string) $code, $url, '' );
					}
				}
				return $r;
			};
		}

		return $out;
	}
}
