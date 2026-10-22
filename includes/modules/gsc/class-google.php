<?php
/**
 * The site's own Google account, through the site's Zinn® connection (zs-090, zs-092, zs-083,
 * zs-081).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Gsc;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ This plugin never holds a Google secret. Every Google call is the site's PHP asking the Zinn®
 * API with the site's connection token (server to server); the API asks Google with the grant the
 * site owner gave the one Zinn® Google app, kept in Zinn®'s vault. So "Connect Google" needs the
 * site to be connected to a Zinn® account first — a free account; no credit is spent here.
 *
 * Answers are kept briefly (`zseo_gsc_link`, 5 minutes) so a screen does not ask twice.
 */
final class Google {

	/** Option: the last answer about the link. */
	public const CACHE = 'zseo_gsc_link';

	/** Seconds the link answer is kept. */
	public const TTL = 300;

	/** Features the person may connect, in the order the screen offers them. */
	public const FEATURES = array( 'search_console', 'analytics', 'business', 'indexing' );

	/**
	 * The link, as the screens show it.
	 *
	 * @param bool $choices Also list the properties that cover this site (a few Google calls).
	 * @param bool $fresh   Ask again even when a recent answer is kept.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function link( bool $choices = false, bool $fresh = false ) {
		if ( ! Connection::connected() ) {
			return self::none();
		}
		$kept = get_option( self::CACHE );
		if ( ! $fresh && ! $choices && is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) > time() - self::TTL ) {
			return (array) $kept['link'];
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/google' . ( $choices ? '?choices=1' : '' ) );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}
		$link = self::clean( (array) $result['body'] );
		update_option(
			self::CACHE,
			array(
				'at'   => time(),
				'link' => $link,
			),
			false
		);

		return $link;
	}

	/**
	 * What the screen shows before any Zinn® connection.
	 *
	 * @return array<string, mixed>
	 */
	public static function none(): array {
		return array(
			'connected'               => false,
			'status'                  => 'none',
			'account_email'           => '',
			'features'                => array(),
			'search_console_property' => '',
			'analytics_property'      => '',
			'business_location'       => '',
			'zinn_connected'          => Connection::connected(),
		);
	}

	/**
	 * Keep only the fields the screens read, each of its type. Pure.
	 *
	 * @param array<string, mixed> $body The API's answer.
	 * @return array<string, mixed>
	 */
	public static function clean( array $body ): array {
		$out = array(
			'connected'               => ! empty( $body['connected'] ),
			'status'                  => sanitize_key( (string) ( $body['status'] ?? 'none' ) ),
			'account_email'           => sanitize_email( (string) ( $body['account_email'] ?? '' ) ),
			'features'                => array_values( array_intersect( self::FEATURES, array_map( 'strval', (array) ( $body['features'] ?? array() ) ) ) ),
			'search_console_property' => sanitize_text_field( (string) ( $body['search_console_property'] ?? '' ) ),
			'analytics_property'      => sanitize_text_field( (string) ( $body['analytics_property'] ?? '' ) ),
			'business_location'       => sanitize_text_field( (string) ( $body['business_location'] ?? '' ) ),
			'zinn_connected'          => true,
		);
		if ( isset( $body['choices'] ) && is_array( $body['choices'] ) ) {
			$c              = $body['choices'];
			$out['choices'] = array(
				'search_console' => array_values(
					array_map(
						static fn( $r ): array => array(
							'property'   => sanitize_text_field( (string) ( $r['property'] ?? '' ) ),
							'permission' => sanitize_key( (string) ( $r['permission'] ?? '' ) ),
						),
						array_filter( (array) ( $c['search_console'] ?? array() ), 'is_array' )
					)
				),
				'analytics'      => array_values(
					array_map(
						static fn( $r ): array => array(
							'id'      => sanitize_text_field( (string) ( $r['id'] ?? '' ) ),
							'name'    => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
							'account' => sanitize_text_field( (string) ( $r['account'] ?? '' ) ),
						),
						array_filter( (array) ( $c['analytics'] ?? array() ), 'is_array' )
					)
				),
				'business'       => array_values(
					array_map(
						static fn( $r ): array => array(
							'name'    => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
							'title'   => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
							'website' => esc_url_raw( (string) ( $r['website'] ?? '' ) ),
							'address' => sanitize_text_field( (string) ( $r['address'] ?? '' ) ),
						),
						array_filter( (array) ( $c['business'] ?? array() ), 'is_array' )
					)
				),
			);
		}

		return $out;
	}

	/**
	 * Has the person granted this feature (and is the link working)?
	 *
	 * @param string $feature search_console|analytics|business|indexing.
	 * @return bool
	 */
	public static function has( string $feature ): bool {
		$link = self::link();

		return is_array( $link ) && ! empty( $link['connected'] ) && in_array( $feature, (array) $link['features'], true );
	}

	/**
	 * Google's consent page for this site, to open in a new tab.
	 *
	 * @param array<int, string> $features Features to ask for (Search Console is always asked).
	 * @param string             $back     The page of this plugin to come back to.
	 * @return array{consent_url: string}|\WP_Error
	 */
	public static function start( array $features, string $back ) {
		$features = array_values( array_intersect( self::FEATURES, $features ) );
		$result   = Connection::call(
			'POST',
			'/v1/seo-connection/google',
			array(
				'features'   => $features,
				'return_url' => $back,
			)
		);
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}
		delete_option( self::CACHE );
		$url = (string) ( $result['body']['consent_url'] ?? '' );
		if ( 0 !== strpos( $url, 'https://accounts.google.com/' ) ) {
			return new \WP_Error( 'zinn_seo_google', __( 'Google\'s sign-in page could not be opened. Please try again.', 'zinn-seo' ), array( 'status' => 502 ) );
		}

		return array( 'consent_url' => $url );
	}

	/**
	 * The Search screen's address, on the address the site is connected as (Google may send the
	 * person back only to an address of that site).
	 *
	 * @return string
	 */
	public static function back_url(): string {
		$admin  = admin_url( 'admin.php?page=zinn-seo&view=search' );
		$home   = untrailingslashit( home_url() );
		$public = untrailingslashit( Connection::site_url() );

		return '' !== $public && $public !== $home ? str_replace( $home, $public, $admin ) : $admin;
	}

	/**
	 * Choose the property (or location) this site reads.
	 *
	 * @param array<string, string> $choice Any of search_console_property, analytics_property, business_location.
	 * @return array<string, mixed>|\WP_Error The link.
	 */
	public static function choose( array $choice ) {
		$body   = array_intersect_key( array_map( 'strval', $choice ), array_flip( array( 'search_console_property', 'analytics_property', 'business_location' ) ) );
		$result = Connection::call( 'PATCH', '/v1/seo-connection/google', $body );
		delete_option( self::CACHE );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}
		Data::forget();
		/**
		 * The site now reads another Search Console or Analytics property: kept answers are gone.
		 */
		do_action( 'zinn_seo_google_changed' );
		// A property was chosen: send the sitemap now (zs-092). Coming back from Google does it too,
		// but the person may never come back to wp-admin (a closed tab, a login page).
		if ( '' !== ( $body['search_console_property'] ?? '' ) ) {
			Sitemap_Submit::google( 'connect' );
		}

		return self::link( true, true );
	}

	/**
	 * Disconnect Google for THIS site: Zinn® forgets this site's copy of the grant. It does not ask
	 * Google to revoke it (since #9117), because one Google account's grant to Zinn® also serves
	 * that account's other sites, Analytics listings and report sheets; to withdraw Zinn® from the
	 * whole Google account, remove it at myaccount.google.com/permissions.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function disconnect() {
		$result = Connection::call( 'DELETE', '/v1/seo-connection/google' );
		delete_option( self::CACHE );
		Data::forget();
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}

		return self::none() + array( 'zinn_connected' => true );
	}

	/**
	 * One API call for this site's Google data (the other modules' door).
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path under `/v1/seo-connection/google`.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>|\WP_Error The answer's body.
	 */
	public static function call( string $method, string $path, ?array $body = null ) {
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_google_not_connected', __( 'Connect this site to your free Zinn® account first; then connect Google.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$result = Connection::call( $method, '/v1/seo-connection/google' . $path, $body );
		if ( ! $result['ok'] ) {
			if ( in_array( self::code( $result ), array( 'needsReconnect', 'notConnected' ), true ) ) {
				delete_option( self::CACHE );
			}
			return self::error( $result );
		}

		return (array) $result['body'];
	}

	/**
	 * The refusal's own code (`refusal.seoGoogle.<code>`), or ''.
	 *
	 * @param array<string, mixed> $result A failed call.
	 * @return string
	 */
	public static function code( array $result ): string {
		$key = (string) ( $result['error']['refusal'] ?? '' );

		return 0 === strpos( $key, 'refusal.seoGoogle.' ) ? substr( $key, 18 ) : '';
	}

	/**
	 * A failed call as a WP_Error with a sentence in the reader's language.
	 *
	 * @param array<string, mixed> $result A failed call.
	 * @return \WP_Error
	 */
	public static function error( array $result ): \WP_Error {
		$code = self::code( $result );
		$said = self::sentence( $code );
		if ( '' === $said ) {
			return Client::as_error( $result );
		}
		$status = (int) ( $result['status'] ?? 0 );

		return new \WP_Error(
			'zinn_seo_google_' . sanitize_key( $code ),
			$said,
			array(
				'status' => $status >= 400 ? $status : 502,
				'zinn'   => array(
					'class'   => 'google',
					'code'    => $code,
					'message' => $said,
				),
			)
		);
	}

	/**
	 * What a Google refusal means, in plain words. '' for one this version does not know.
	 *
	 * @param string $code The refusal's code.
	 * @return string
	 */
	public static function sentence( string $code ): string {
		switch ( $code ) {
			case 'notConnected':
				return __( 'Google is not connected yet. Press Connect Google to see your searches here.', 'zinn-seo' );
			case 'needsReconnect':
			case 'noRefreshToken':
			case 'badState':
				return __( 'Google stopped accepting this site\'s sign-in (it was removed or expired). Press Connect Google again — nothing else is lost.', 'zinn-seo' );
			case 'propertyNotChosen':
				return __( 'Choose which Search Console property is this site first.', 'zinn-seo' );
			case 'locationNotChosen':
				return __( 'Choose which Business Profile location is this business first.', 'zinn-seo' );
			case 'scopeMissing':
			case 'scopeNotGranted':
				return __( 'Google was not given permission for this. Press Connect Google again and leave its box ticked.', 'zinn-seo' );
			case 'noAccess':
			case 'propertyNotFound':
				return __( 'The Google account you connected cannot see this property. Connect the account that owns it, or ask its owner to add you in Search Console.', 'zinn-seo' );
			case 'propertyNotThisSite':
				return __( 'That property is for another website. Choose the one that covers this site\'s address.', 'zinn-seo' );
			case 'locationNotFound':
				return __( 'The Google account you connected does not manage that business.', 'zinn-seo' );
			case 'apiDisabled':
				return __( 'This part of Google is not switched on for Zinn® SEO yet. Nothing is wrong with your account — it starts working as soon as we switch it on.', 'zinn-seo' );
			case 'quotaZero':
				return __( 'Google has not opened Business Profile access for Zinn® SEO yet. Nothing is wrong with your account — it starts working once Google approves it.', 'zinn-seo' );
			case 'vendorUnavailable':
				return __( 'Google did not answer just now. Try again in a few minutes.', 'zinn-seo' );
			case 'vendorBusy':
				return __( 'Google asked us to slow down for a moment. Try again in a minute.', 'zinn-seo' );
			case 'badQuery':
				return __( 'Google could not use that request. Change the dates or filters and try again.', 'zinn-seo' );
		}

		return '';
	}

	/**
	 * Back from Google: forget the kept answer so the screen shows the new state at once.
	 *
	 * @return void
	 */
	public static function returned(): void {
		if ( ! isset( $_GET['zseo_google'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only clears a cache; Google sends the person back with this flag.
			return;
		}
		delete_option( self::CACHE );
		if ( 'connected' === sanitize_key( wp_unslash( (string) $_GET['zseo_google'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
			// Choose the one property that covers this site, then send the sitemap (zs-092).
			$link = self::link( true, true );
			if ( is_array( $link ) && '' !== (string) $link['search_console_property'] ) {
				Sitemap_Submit::all( 'connect' );
			}
		}
	}
}
