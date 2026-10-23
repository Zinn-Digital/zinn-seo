<?php
/**
 * Is an update WordPress offers really for the plugin or theme that is installed? (0.17.65)
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The owner, 2026-10-22: a fatal-error e-mail told him to "update Zinn Translate to version 2.4.10"
 * on zinnhub.com — which runs a FORK of it (header `Version: 0.32.9-…-zhfix6`, built for that site).
 * WordPress matches updates by folder name only, so the public release's offer landed on the fork;
 * following the advice would have overwritten every custom change.
 *
 * So an update is advised (or tested and applied by Safe updates) only when the installed copy is
 * genuinely the plugin its update source knows:
 *
 * - its Version is a public release number (digits and dots only: `2.4.9`, never `0.32.9-zhfix6`,
 *   `1.2-dev` or `3.0-custom`);
 * - the offer is newer than it;
 * - it comes from where the plugin says it updates from: an `Update URI` header names the host the
 *   offer must come from (and a plugin with its own Update URI never takes a WordPress.org offer);
 * - the offer's homepage, when both say one, is the plugin's own `Plugin URI` (same host);
 * - the offer's author, when it says one, is the plugin's `Author`.
 *
 * Anything else is a CUSTOM BUILD: the screens and e-mails say so and that its maintainer decides,
 * and Safe updates never applies the offer. Pure apart from `for_plugin()` / `for_theme()`.
 */
final class Update_Trust {

	/**
	 * Is this offer genuinely for this installed copy?
	 *
	 * @param array<string, string> $header Installed header: Version, PluginURI|ThemeURI, UpdateURI, Author.
	 * @param array<string, mixed>  $offer  The offer: new_version, url, id, package, author.
	 * @return array{ok: bool, why: string} `why`: '' | custom_version | not_newer | other_source | other_author.
	 */
	public static function genuine( array $header, array $offer ): array {
		$have = trim( (string) ( $header['Version'] ?? '' ) );
		$want = trim( (string) ( $offer['new_version'] ?? '' ) );
		if ( ! self::release( $have ) ) {
			return self::verdict( 'custom_version' );
		}
		if ( '' === $want || ! version_compare( $want, $have, '>' ) ) {
			return self::verdict( 'not_newer' );
		}
		$uri  = trim( (string) ( $header['UpdateURI'] ?? '' ) );
		$id   = (string) ( $offer['id'] ?? '' );
		$from = array_filter( array( self::host( $id ), self::host( (string) ( $offer['url'] ?? '' ) ), self::host( (string) ( $offer['package'] ?? '' ) ) ) );
		if ( '' !== $uri && ! in_array( self::host( $uri ), array( 'w.org', 'wordpress.org' ), true ) ) {
			$own = self::host( $uri );
			if ( 0 === strpos( $id, 'w.org/' ) || ( array() !== $from && ! in_array( $own, $from, true ) ) ) {
				return self::verdict( 'other_source' );
			}
		}
		$home = self::host( (string) ( $header['PluginURI'] ?? $header['ThemeURI'] ?? '' ) );
		$url  = self::host( (string) ( $offer['url'] ?? '' ) );
		if ( '' !== $home && '' !== $url && 0 !== strpos( $id, 'w.org/' ) && $home !== $url ) {
			return self::verdict( 'other_source' );
		}
		$by = self::name( (string) ( $offer['author'] ?? '' ) );
		if ( '' !== $by && '' !== self::name( (string) ( $header['Author'] ?? '' ) ) && self::name( (string) $header['Author'] ) !== $by ) {
			return self::verdict( 'other_author' );
		}

		return self::verdict( '' );
	}

	/**
	 * Is this a public release number (digits and dots only)? Pure.
	 *
	 * @param string $version Version.
	 * @return bool
	 */
	public static function release( string $version ): bool {
		return 1 === preg_match( '/^\d+(\.\d+){0,3}$/', $version );
	}

	/**
	 * The offer WordPress has for an installed plugin, judged.
	 *
	 * @param string $file Plugin file (folder/file.php).
	 * @return array{version: string, custom: bool, why: string}|null Null: no offer at all.
	 */
	public static function for_plugin( string $file ): ?array {
		return self::offer_for( 'plugin', $file );
	}

	/**
	 * The offer WordPress has for an installed theme, judged.
	 *
	 * @param string $slug Theme folder.
	 * @return array{version: string, custom: bool, why: string}|null
	 */
	public static function for_theme( string $slug ): ?array {
		return self::offer_for( 'theme', $slug );
	}

	/**
	 * Is this offer for an installed plugin or theme one for a custom build (never advised, never
	 * applied)? Used where WordPress hands us the offer itself (Safe updates, automatic updates).
	 *
	 * @param string               $kind  plugin|theme.
	 * @param string               $item  Plugin file or theme folder.
	 * @param array<string, mixed> $offer The offer.
	 * @return bool
	 */
	public static function custom_offer( string $kind, string $item, array $offer ): bool {
		return self::judged( self::header( $kind, $item ), $offer )['custom'];
	}

	/**
	 * The offer in WordPress's update list for one item, judged.
	 *
	 * @param string $kind plugin|theme.
	 * @param string $item Plugin file or theme folder.
	 * @return array{version: string, custom: bool, why: string}|null
	 */
	private static function offer_for( string $kind, string $item ): ?array {
		$t = get_site_transient( 'theme' === $kind ? 'update_themes' : 'update_plugins' );
		$u = is_object( $t ) && isset( $t->response[ $item ] ) ? (array) $t->response[ $item ] : null;

		return null === $u ? null : self::judged( self::header( $kind, $item ), $u );
	}

	/**
	 * The installed copy's header.
	 *
	 * @param string $kind plugin|theme.
	 * @param string $item Plugin file or theme folder.
	 * @return array<string, string>
	 */
	private static function header( string $kind, string $item ): array {
		if ( 'theme' === $kind ) {
			$theme = wp_get_theme( $item );
			$get   = static fn( string $k ): string => $theme->exists() ? (string) $theme->get( $k ) : '';

			return array(
				'Version'   => $get( 'Version' ),
				'ThemeURI'  => $get( 'ThemeURI' ),
				'UpdateURI' => $get( 'UpdateURI' ),
				'Author'    => wp_strip_all_tags( $get( 'Author' ) ),
			);
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$h = (array) ( get_plugins()[ $item ] ?? array() );

		return array(
			'Version'   => (string) ( $h['Version'] ?? '' ),
			'PluginURI' => (string) ( $h['PluginURI'] ?? '' ),
			'UpdateURI' => (string) ( $h['UpdateURI'] ?? '' ),
			'Author'    => (string) ( $h['Author'] ?? '' ),
		);
	}

	/**
	 * Plain words for a custom build (MAIL1 shape: what it means, the one next step).
	 *
	 * @param string $name Plugin or theme name.
	 * @return string
	 */
	public static function custom_words( string $name ): string {
		/* translators: %s: plugin or theme name. */
		return sprintf( __( '%s on this site is a custom build, not the public release, so do not update it from Dashboard › Updates: that would replace its custom changes. Send the details to whoever maintains it.', 'zinn-seo' ), $name );
	}

	/**
	 * A judged offer. Pure.
	 *
	 * @param array<string, string> $header Header.
	 * @param array<string, mixed>  $offer  Offer.
	 * @return array{version: string, custom: bool, why: string}
	 */
	public static function judged( array $header, array $offer ): array {
		$v = self::genuine( $header, $offer );

		return array(
			'version' => $v['ok'] ? (string) ( $offer['new_version'] ?? '' ) : '',
			'custom'  => ! $v['ok'] && 'not_newer' !== $v['why'],
			'why'     => $v['why'],
		);
	}

	/**
	 * A verdict.
	 *
	 * @param string $why Why not ('' ok).
	 * @return array{ok: bool, why: string}
	 */
	private static function verdict( string $why ): array {
		return array(
			'ok'  => '' === $why,
			'why' => $why,
		);
	}

	/**
	 * The host of an address or an Update URI id (`w.org/plugins/x` → w.org), without www. Pure.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function host( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$host = (string) wp_parse_url( false === strpos( $url, '://' ) ? 'https://' . $url : $url, PHP_URL_HOST );

		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * A name to compare (lower case, letters and digits only). Pure.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function name( string $name ): string {
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( wp_strip_all_tags( $name ) ) );
	}
}
