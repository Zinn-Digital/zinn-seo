<?php
/**
 * The Zinn® CDN: the site's images, CSS, JavaScript and fonts served from a global CDN.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cdn;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One click on the CDN screen asks Zinn® for a pull zone that reads this site's own files over
 * https; Zinn® answers with its hostname (`<name>.b-cdn.net`), and from then on the front end's
 * static file addresses point there. Nothing about the site's domain, DNS or certificate changes.
 *
 * - Front end only, and zero added weight: the page's HTML is rewritten as it leaves WordPress
 *   (one output buffer, no script, no style). wp-admin, REST, AJAX, feeds, the customizer and
 *   the login page are never touched.
 * - Off is instant: the rewrite stops on the next page view (the CDN is also switched off at
 *   Zinn®, which stops the bill).
 * - Paused at Zinn® (out of credit, or the account has an unpaid bill) the plugin stops
 *   rewriting as soon as it hears; until then the CDN sends every request back to the site, so
 *   nothing breaks in between.
 * - Cache busting: every purge raises a version number added to the rewritten addresses
 *   (`?zcv=`), so browsers fetch the new file too. Media, theme and plugin changes purge.
 *
 * The state is one small option, read on every front-end page: whether the CDN is on, its
 * hostname, the version. The account side (price, this month's traffic) is read from Zinn® on
 * the CDN screen only, never on a visitor's page.
 */
final class Cdn {

	/** The front end's state: `on`, `host`, `ver`, `state`, `checked`. Autoloaded (read on every page). */
	public const OPTION = 'zseo_cdn';

	/** The scheduled status refresh. */
	public const CRON = 'zseo_cdn_refresh';

	/** The minute-by-minute check while a new zone warms up. */
	public const WARM_HOOK = 'zseo_cdn_warm';

	/** The debounced purge after a media / theme / plugin change. */
	public const PURGE_HOOK = 'zseo_cdn_purge';

	/** What a CDN hostname must look like before anything is rewritten to it. */
	public const HOST_PATTERN = '/^[a-z0-9][a-z0-9-]{1,62}\.b-cdn\.net$/';

	/** File types served from the CDN. */
	public const EXTENSIONS = 'avif|bmp|css|eot|gif|ico|jpe?g|js|mp3|mp4|otf|pdf|png|svg|ttf|webm|webp|woff2?';

	/**
	 * The kept state.
	 *
	 * @return array{on: bool, host: string, ver: int, state: string, checked: int, ready: string}
	 */
	public static function stored(): array {
		$raw  = get_option( self::OPTION, array() );
		$raw  = is_array( $raw ) ? $raw : array();
		$host = strtolower( (string) ( $raw['host'] ?? '' ) );

		return array(
			'on'      => ! empty( $raw['on'] ) && 1 === preg_match( self::HOST_PATTERN, $host ),
			'host'    => 1 === preg_match( self::HOST_PATTERN, $host ) ? $host : '',
			'ver'     => max( 0, (int) ( $raw['ver'] ?? 0 ) ),
			'state'   => sanitize_key( (string) ( $raw['state'] ?? '' ) ),
			'checked' => (int) ( $raw['checked'] ?? 0 ),
			// The CDN host that has been seen SERVING one of this site's files (see `serving`).
			'ready'   => strtolower( (string) ( $raw['ready'] ?? '' ) ),
		);
	}

	/**
	 * Save the state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function save( array $state ): void {
		$before = self::stored();
		update_option( self::OPTION, array_merge( $before, $state ), true );
		$after = self::stored();
		$was   = self::rewriting( $before ) ? $before['host'] . '|' . $before['ver'] : '';
		$now   = self::rewriting( $after ) ? $after['host'] . '|' . $after['ver'] : '';
		if ( $was !== $now ) {
			self::purge_page_caches();
		}
	}

	/**
	 * A page cache keeps the HTML it already rewrote (or did not): when the CDN goes on, off or
	 * is purged, the cached pages are emptied too, so "off" really is off on the next view.
	 * Every cache plugin that offers a "purge everything" call, and nothing else.
	 *
	 * @return void
	 */
	public static function purge_page_caches(): void {
		do_action( 'zinn_cache_purge_all' );
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own documented purge action.
		do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's own documented purge action.
		foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache' ) as $flush ) {
			if ( function_exists( $flush ) ) {
				$flush();
			}
		}
		/**
		 * Fires after the Zinn® CDN went on, off or was purged, for a cache this plugin does not know.
		 */
		do_action( 'zinn_seo_cdn_changed' );
	}

	/**
	 * Is the front end rewriting right now?
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return self::rewriting( self::stored() );
	}

	/**
	 * Whether a kept state rewrites: on, serving at Zinn®, and its host seen serving a file.
	 *
	 * @param array<string, mixed> $s A `stored()` state.
	 * @return bool
	 */
	private static function rewriting( array $s ): bool {
		return ! empty( $s['on'] ) && 'active' === $s['state'] && '' !== $s['host'] && $s['ready'] === $s['host'];
	}

	/**
	 * Does the CDN host serve this site's files yet? A new zone takes a minute or two to reach
	 * every location (measured 2026-10-13: ~90 s of "not configured" answers), and rewriting
	 * before then would break the site's images and styles, so one core file is asked for first.
	 *
	 * @param string $host The CDN host.
	 * @return bool
	 */
	public static function serving( string $host ): bool {
		/**
		 * Filters whether the CDN host serves the site's files (true/false skips the request).
		 *
		 * @param bool|null $serving Null: ask the CDN.
		 * @param string    $host    The CDN host.
		 */
		$forced = apply_filters( 'zinn_seo_cdn_serving', null, $host );
		if ( is_bool( $forced ) ) {
			return $forced;
		}
		$answer = wp_remote_request(
			'https://' . $host . '/wp-includes/css/dashicons.min.css',
			array(
				'method'      => 'HEAD',
				'timeout'     => 8,
				'redirection' => 0,
			)
		);

		return ! is_wp_error( $answer ) && 200 === (int) wp_remote_retrieve_response_code( $answer );
	}

	/**
	 * While a new zone warms up: look again in a minute (scheduled, never on a visitor's page).
	 *
	 * @return void
	 */
	public static function warm(): void {
		$s = self::stored();
		if ( ! $s['on'] || '' === $s['host'] || $s['ready'] === $s['host'] ) {
			return;
		}
		if ( self::serving( $s['host'] ) ) {
			self::save( array( 'ready' => $s['host'] ) );
			return;
		}
		if ( ! wp_next_scheduled( self::WARM_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::WARM_HOOK );
		}
	}

	/**
	 * Start the output buffer on a visitor's page, when the CDN is on.
	 *
	 * @return void
	 */
	public static function front(): void {
		if ( ! self::active() || self::skip() ) {
			return;
		}
		ob_start( array( self::class, 'buffer' ) );
	}

	/**
	 * Pages that are never rewritten.
	 *
	 * @return bool
	 */
	public static function skip(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
			return true;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DONOTCDN' ) && DONOTCDN ) ) {
			return true;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SCRIPT_NAME'] ) ) : '';

		return '' !== $script && false !== strpos( $script, 'wp-login.php' );
	}

	/**
	 * The output buffer: HTML only.
	 *
	 * @param string $html The page.
	 * @return string
	 */
	public static function buffer( $html ): string {
		$html = (string) $html;
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) ) {
				return $html;
			}
		}
		$s = self::stored();

		return self::rewrite( $html, (string) wp_parse_url( home_url(), PHP_URL_HOST ), $s['host'], $s['ver'] );
	}

	/**
	 * Rewrite this site's static file addresses to the CDN host.
	 *
	 * Absolute (`https://site/wp-content/…`, `//site/…`) and root-relative (`/wp-includes/…`)
	 * addresses of files under `wp-content/` and `wp-includes/` with a static extension. Anything
	 * else — pages, other sites' files, escaped JSON in inline scripts — is left alone.
	 *
	 * @param string $html     The page.
	 * @param string $site     This site's host.
	 * @param string $cdn      The CDN host.
	 * @param int    $version  Cache-busting version (0: none).
	 * @return string
	 */
	public static function rewrite( string $html, string $site, string $cdn, int $version = 0 ): string {
		$site = strtolower( $site );
		if ( '' === $site || 1 !== preg_match( self::HOST_PATTERN, $cdn ) || '' === $html ) {
			return $html;
		}
		$bare    = 0 === strpos( $site, 'www.' ) ? substr( $site, 4 ) : $site;
		$hosts   = array( $bare, 'www.' . $bare );
		$hostre  = implode( '|', array_map( static fn( string $h ): string => preg_quote( $h, '#' ), $hosts ) );
		$pattern = '#(?<=["\'(\s,=])(?:(?:https?:)?//(?:' . $hostre . '))?(/(?:wp-content|wp-includes)/[^"\'\s<>()\\\\]+?\.(?:' . self::EXTENSIONS . '))(\?[^"\'\s<>()\\\\]*)?(?=["\')\s,])#i';

		return (string) preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $cdn, $version ): string {
				$query = (string) ( $m[2] ?? '' );
				if ( $version > 0 ) {
					$query .= ( '' === $query ? '?' : '&' ) . 'zcv=' . $version;
				}

				return 'https://' . $cdn . $m[1] . $query;
			},
			$html
		);
	}

	/**
	 * What the CDN screen shows: Zinn®'s answer, plus what this site is doing.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function status() {
		if ( ! Connection::connected() ) {
			return array(
				'connected' => false,
				'local'     => self::stored(),
			);
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/cdn' );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}

		return self::adopt( (array) $result['body'] );
	}

	/**
	 * Switch it on.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function enable() {
		$result = Connection::call( 'POST', '/v1/seo-connection/cdn' );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}

		return self::adopt( (array) $result['body'] );
	}

	/**
	 * Switch it off: the rewrite stops first, so the site is back on its own files at once.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function disable() {
		self::save( array( 'on' => false ) );
		$result = Connection::call( 'DELETE', '/v1/seo-connection/cdn' );
		if ( ! $result['ok'] && 404 !== (int) $result['status'] ) {
			return self::error( $result );
		}

		return $result['ok'] ? self::adopt( (array) $result['body'] ) : array(
			'connected' => true,
			'local'     => self::stored(),
		);
	}

	/**
	 * Empty the CDN's cache, and raise the version so browsers fetch the new files too.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function purge() {
		$result = Connection::call( 'POST', '/v1/seo-connection/cdn/purge' );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}
		self::save( array( 'ver' => self::stored()['ver'] + 1 ) );

		return array(
			'purged' => true,
			'local'  => self::stored(),
		);
	}

	/**
	 * After a change to media, the theme or plugins: one purge a minute later, however many
	 * changes arrive (never on the request that made the change).
	 *
	 * @return void
	 */
	public static function schedule_purge(): void {
		if ( self::active() && ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::PURGE_HOOK );
		}
	}

	/**
	 * The scheduled purge.
	 *
	 * @return void
	 */
	public static function run_purge(): void {
		if ( self::active() ) {
			self::purge();
		}
	}

	/**
	 * The twice-daily refresh: a CDN paused or switched off at Zinn® stops being rewritten to.
	 *
	 * @return void
	 */
	public static function refresh(): void {
		if ( self::stored()['on'] && Connection::connected() ) {
			self::status();
		}
	}

	/**
	 * Forget the CDN (the site was disconnected): the front end goes back to its own files.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::save(
			array(
				'on'    => false,
				'state' => '',
			)
		);
	}

	/**
	 * Keep what Zinn® said, and answer the screen.
	 *
	 * @param array<string, mixed> $body `getSeoCdn` answer.
	 * @return array<string, mixed>
	 */
	public static function adopt( array $body ): array {
		$zone  = is_array( $body['zone'] ?? null ) ? $body['zone'] : null;
		$state = $zone ? sanitize_key( (string) ( $zone['state'] ?? '' ) ) : '';
		$host  = $zone ? strtolower( (string) ( $zone['hostname'] ?? '' ) ) : '';
		$live  = in_array( $state, array( 'active', 'paused_credit', 'paused_standing' ), true ) && 1 === preg_match( self::HOST_PATTERN, $host );
		$ready = self::stored()['ready'];
		if ( $live && 'active' === $state && $ready !== $host ) {
			$ready = self::serving( $host ) ? $host : $ready;
		}
		self::save(
			array(
				'on'      => $live,
				'host'    => $live ? $host : '',
				'state'   => $state,
				'checked' => time(),
				'ready'   => $ready,
			)
		);
		if ( $live && 'active' === $state && $ready !== $host && ! wp_next_scheduled( self::WARM_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::WARM_HOOK );
		}
		$month = $zone && is_array( $zone['month'] ?? null ) ? $zone['month'] : array();
		$price = is_array( $body['price'] ?? null ) ? $body['price'] : array();
		$rates = array();
		foreach ( (array) ( $price['per_gb_micros'] ?? array() ) as $band => $micros ) {
			$rates[ sanitize_key( (string) $band ) ] = (int) $micros;
		}

		return array(
			'connected' => true,
			'hosted'    => ! empty( $body['hosted'] ),
			'https'     => ! empty( $body['https'] ),
			'offered'   => ! empty( $body['offered'] ),
			'zone'      => $zone ? array(
				'state'    => $state,
				'hostname' => 1 === preg_match( self::HOST_PATTERN, $host ) ? $host : '',
				'since'    => sanitize_text_field( (string) ( $zone['enabled_at'] ?? '' ) ),
				'month'    => array(
					'bytes'          => (int) ( $month['bytes'] ?? 0 ),
					'charged_micros' => (int) ( $month['charged_micros'] ?? 0 ),
					'owed_micros'    => (int) ( $month['owed_micros'] ?? 0 ),
				),
			) : null,
			'price'     => array(
				'minimum_micros' => (int) ( $price['monthly_minimum_micros'] ?? 0 ),
				'per_gb_micros'  => $rates,
			),
			'local'     => self::stored() + array( 'warming' => $live && 'active' === $state && ! self::active() ),
		);
	}

	/**
	 * A refusal or failure, in plain words.
	 *
	 * @param array<string, mixed> $result The call's result.
	 * @return \WP_Error
	 */
	public static function error( array $result ): \WP_Error {
		$error = Client::as_error( $result );
		$key   = (string) ( $result['error']['refusal'] ?? '' );
		$words = self::sentences()[ $key ] ?? '';
		if ( '' === $words ) {
			return $error;
		}
		$data = (array) $error->get_error_data();

		return new \WP_Error( $error->get_error_code(), $words, $data );
	}

	/**
	 * Zinn®'s refusals, said by the plugin in the reader's language.
	 *
	 * @return array<string, string>
	 */
	public static function sentences(): array {
		return array(
			'refusal.zinnCdn.hostedHere'        => __( 'This site is on Zinn® hosting, so it already has our CDN. Turn on Premium CDN for it in your Zinn® dashboard.', 'zinn-seo' ),
			'refusal.zinnCdn.notHttps'          => __( 'The CDN needs your site to use https://. Change the site address to https:// in Settings → General, then try again.', 'zinn-seo' ),
			'refusal.zinnCdn.noCredit'          => __( 'Your Zinn® credit is below the CDN’s monthly minimum, so it was not turned on and nothing was charged. Add credit, then try again.', 'zinn-seo' ),
			'refusal.zinnCdn.notInGoodStanding' => __( 'Your Zinn® account has an unpaid bill, so the CDN was not turned on. Pay it in your Zinn® dashboard, then try again.', 'zinn-seo' ),
			'refusal.zinnCdn.notOn'             => __( 'The CDN is not on for this site.', 'zinn-seo' ),
			'refusal.zinnCdn.unavailable'       => __( 'The CDN could not be reached just now. Nothing was changed or charged. Try again in a few minutes.', 'zinn-seo' ),
		);
	}
}
