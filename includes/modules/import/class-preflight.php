<?php
/**
 * Before a move starts: what is on this site that the move has to allow for (queue Q263, point 1).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ Every item is one of three: `ok`, `note` (the move allows for it — a page cache it will
 * empty, a multilingual plugin whose languages are checked too), or `stop` (the move could not
 * PROVE itself, so it does not start: the site cannot read its own pages, the change history is
 * missing, both plugins print tags). A `stop` refuses the copy; nothing has changed by then.
 *
 * The result is kept with the move (`state.preflight`) and shown on the screen and in WP-CLI.
 */
final class Preflight {

	/**
	 * Fetch the home page as a visitor, with its response headers.
	 *
	 * @return array{code: int, error: string, headers: array<string, string>}
	 */
	public static function home(): array {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 0,
				'error'   => $response->get_error_message(),
				'headers' => array(),
			);
		}
		$headers = array();
		$raw     = wp_remote_retrieve_headers( $response );
		// Requests' header dictionary is an object: an (array) cast reads its private fields.
		$raw = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		foreach ( $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'error'   => '',
			'headers' => $headers,
		);
	}

	/**
	 * The installed version of each of a source's plugin files.
	 *
	 * @param Source $source Source.
	 * @return array<string, string> Name => version.
	 */
	public static function versions( Source $source ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			if ( ! is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				return array();
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = (array) get_plugins();
		$out = array();
		foreach ( $source->plugins() as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$out[ (string) ( $all[ $file ]['Name'] ?? $file ) ] = (string) ( $all[ $file ]['Version'] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * Look, and keep what was found with the move.
	 *
	 * @param string $id Source.
	 * @return array<string, mixed>|\WP_Error The preflight.
	 */
	public static function run( string $id ) {
		$source = Import::source( $id );
		if ( null === $source || ! $source->present() ) {
			return new \WP_Error( 'zinn_seo_no_source', __( 'There is nothing from that plugin on this site.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$home   = self::home();
		$result = self::assess( $source, $home );
		$state  = Import::state();
		// Only the preflight part is ours to change.
		$state['preflight'] = $result;
		Import::put( $state );

		return $result;
	}

	/**
	 * Judge what was found (no request, no write: the test's entry point).
	 *
	 * @param Source               $source Source.
	 * @param array<string, mixed> $home   What home() returned.
	 * @return array<string, mixed>
	 */
	public static function assess( Source $source, array $home ): array {
		$items  = array();
		$add    = static function ( string $id, string $level, string $kind, string $arg = '' ) use ( &$items ): void {
			$items[] = array(
				'id'    => $id,
				'level' => $level,
				'kind'  => $kind,
				'arg'   => $arg,
				'text'  => self::text( $kind, $arg ),
			);
		};
		$active = $source->active();

		// The plugin being moved from, and its version.
		$named = array();
		foreach ( self::versions( $source ) as $name => $version ) {
			$named[] = '' !== $version ? $name . ' ' . $version : $name;
		}
		$add( 'source', 'ok', array() === $active ? 'source_off' : 'source_on', implode( ', ', array() !== $named ? $named : array( $source->name() ) ) );

		// Another SEO plugin besides the one being moved from.
		$others = array_diff_key( \ZinnDigital\ZinnSEO\Head\Head::other_seo_plugins(), array_flip( $source->plugins() ) );
		if ( array() !== $others ) {
			$add( 'others', 'note', 'others', implode( ', ', array_values( $others ) ) );
		}

		// The site must read its own pages: every proof in the move is a page read as a visitor.
		$code = (int) ( $home['code'] ?? 0 );
		if ( 0 === $code || $code >= 400 ) {
			$add( 'loopback', 'stop', 'loopback_down', '' !== (string) ( $home['error'] ?? '' ) ? (string) $home['error'] : 'HTTP ' . $code );
		} else {
			$add( 'loopback', 'ok', 'loopback_ok' );
		}

		if ( $source->has_pages() && (bool) Settings::get( 'run_with_others', false ) ) {
			$add( 'both', 'stop', 'both' );
		}
		if ( ! Import::history() ) {
			$add( 'history', 'stop', 'history' );
		}
		if ( '' !== $source->redirects() && ! Modules::enabled( 'redirects' ) ) {
			$add( 'redirects', 'note', 'redirects', $source->name() );
		}

		// Caches in front of the pages.
		$caches = Purge::detect( (array) ( $home['headers'] ?? array() ) );
		$auto   = array();
		$manual = array();
		foreach ( $caches as $cache ) {
			if ( $cache['auto'] ) {
				$auto[] = $cache['name'];
			} else {
				$manual[] = $cache['name'];
			}
		}
		if ( array() !== $auto ) {
			$add( 'cache', 'ok', 'cache_auto', implode( ', ', $auto ) );
		}
		if ( array() !== $manual ) {
			$add( 'cache_manual', 'note', 'cache_manual', implode( ', ', $manual ) );
		}
		if ( array() === $caches ) {
			$add( 'cache', 'ok', 'cache_none' );
		}

		// Languages.
		$languages = array();
		$plugins   = Purge::active();
		foreach ( Setup::known_language_plugins() as $file => $name ) {
			if ( in_array( $file, $plugins, true ) ) {
				$languages[] = $name;
			}
		}
		if ( array() !== $languages ) {
			$add( 'languages', 'note', 'languages', implode( ', ', $languages ) );
		}

		if ( is_multisite() && array() !== array_intersect( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) ) ) {
			$add( 'network', 'note', 'network', $source->name() );
		}

		$stops = array_values( array_filter( $items, static fn( array $i ): bool => 'stop' === $i['level'] ) );

		return array(
			'source'  => $source->id(),
			'at'      => time(),
			'items'   => $items,
			'blocked' => array() !== $stops,
			'headers' => array_intersect_key( (array) ( $home['headers'] ?? array() ), Purge::headers() ),
		);
	}

	/**
	 * The sentence for a finding, in the language of whoever reads it now (the preflight is kept
	 * with the move, and the person who reads it may not be the one who ran it).
	 *
	 * @param string $kind What was found.
	 * @param string $arg  Names (plugins, caches) or the error.
	 * @return string
	 */
	public static function text( string $kind, string $arg = '' ): string {
		switch ( $kind ) {
			case 'source_off':
				/* translators: %s: plugin names and versions. */
				return sprintf( __( 'Found %s, switched off. Its data is still here and can be brought over.', 'zinn-seo' ), $arg );
			case 'source_on':
				/* translators: %s: plugin names and versions. */
				return sprintf( __( 'Found %s, active. It keeps serving your pages until every check has passed.', 'zinn-seo' ), $arg );
			case 'others':
				/* translators: %s: plugin names. */
				return sprintf( __( 'Also active: %s. Move from one plugin at a time; the other keeps printing its tags until you move from it too.', 'zinn-seo' ), $arg );
			case 'loopback_down':
				/* translators: %s: the error or HTTP status. */
				return sprintf( __( 'This site could not open its own home page (%s). Zinn® SEO proves every step by reading your pages as a visitor does, so the move cannot start until it can. Ask your host to allow the site to reach its own address.', 'zinn-seo' ), $arg );
			case 'loopback_ok':
				return __( 'This site can read its own pages, so every step can be checked on the real page.', 'zinn-seo' );
			case 'both':
				return __( '"Print alongside other SEO plugins" is on, so pages carry two sets of tags. Switch it off under Search appearance first.', 'zinn-seo' );
			case 'history':
				return __( 'The change history is not available, so the move could not be undone. Switch the bulk editor on first.', 'zinn-seo' );
			case 'redirects':
				/* translators: %s: the name of another SEO plugin. */
				return sprintf( __( '%s has redirects, and the Redirects module is off, so they will not come over. Switch the module on first to keep them.', 'zinn-seo' ), $arg );
			case 'cache_auto':
				/* translators: %s: cache names. */
				return sprintf( __( 'Page cache: %s. It is emptied automatically right after the switch, so visitors and search engines see the new pages at once.', 'zinn-seo' ), $arg );
			case 'cache_manual':
				/* translators: %s: cache names. */
				return sprintf( __( 'Also caching your pages: %s. Zinn® SEO cannot empty it from here, so empty it yourself right after the switch.', 'zinn-seo' ), $arg );
			case 'cache_none':
				return __( 'No page cache found in front of your pages.', 'zinn-seo' );
			case 'languages':
				/* translators: %s: multilingual plugin names. */
				return sprintf( __( 'Multilingual site (%s). The home page of every language is checked before and after the switch, including the language links search engines read.', 'zinn-seo' ), $arg );
			case 'network':
				/* translators: %s: the name of another SEO plugin. */
				return sprintf( __( '%s is active for the whole network. Everything can be brought over here, and a network administrator switches it off for every site at once.', 'zinn-seo' ), $arg );
		}

		return '';
	}

	/**
	 * A kept preflight with its sentences in the reader's language.
	 *
	 * @param mixed $preflight Kept preflight.
	 * @return mixed
	 */
	public static function localised( $preflight ) {
		if ( ! is_array( $preflight ) ) {
			return $preflight;
		}
		foreach ( (array) ( $preflight['items'] ?? array() ) as $i => $item ) {
			if ( isset( $item['kind'] ) ) {
				$preflight['items'][ $i ]['text'] = self::text( (string) $item['kind'], (string) ( $item['arg'] ?? '' ) );
			}
		}

		return $preflight;
	}

	/**
	 * Why a preflight stops the move, or ''.
	 *
	 * @param array<string, mixed> $preflight Preflight.
	 * @return string
	 */
	public static function refusal( array $preflight ): string {
		foreach ( (array) ( $preflight['items'] ?? array() ) as $item ) {
			if ( 'stop' === ( $item['level'] ?? '' ) ) {
				return (string) $item['text'];
			}
		}

		return '';
	}
}
