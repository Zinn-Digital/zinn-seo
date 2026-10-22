<?php
/**
 * Site authority: the site owner's own SEO data accounts (Ahrefs' free Domain Rating key, Open
 * PageRank) and Zinn Authority on Zinn® credit, with history and up/down alerts (zs-500 … zs-509).
 * Other sources join through the `zinn_seo_authority_sources` filter.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Authority;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ The key a site owner pastes is passed straight to Zinn®, which checks it with the vendor and
 * keeps it in its own vault. It is never written to this site's database, a transient, a log or
 * the page: this class has no code path that stores it, and the screens only ever see the last
 * four characters Zinn® sends back. (Owner, 2026-10-08: keys are held on the Zinn side.)
 *
 * Everything here reads and writes through the site's Zinn® connection; nothing runs on a visitor's
 * page. The list of sources, keys and latest values is kept for five minutes in an option that is
 * not autoloaded, so moving between tabs does not call Zinn® on every click.
 */
final class Authority {

	/** The kept list (not autoloaded). */
	public const OPTION = 'zseo_authority';

	/** Seconds the list is kept. */
	public const TTL = 300;

	/** The sources this plugin connects: Ahrefs' free Domain Rating key, Open PageRank and Zinn Authority. */
	public const FREE_SOURCES = array( 'ahrefs_free', 'openpagerank', 'zinn_authority' );

	/**
	 * The sources this site can connect.
	 *
	 * @return array<int, string>
	 */
	public static function sources(): array {
		/**
		 * Filters the SEO data sources this site can connect (Pro adds the paid-account vendors).
		 *
		 * @param array<int, string> $sources Source keys.
		 */
		$sources = (array) apply_filters( 'zinn_seo_authority_sources', self::FREE_SOURCES );

		return array_values( array_unique( array_map( 'sanitize_key', $sources ) ) );
	}

	/**
	 * Whether this site can connect a source.
	 *
	 * @param string $source Source key.
	 * @return bool
	 */
	public static function allowed( string $source ): bool {
		return in_array( $source, self::sources(), true );
	}

	/**
	 * Sources, keys, what this site tracks and the latest values.
	 *
	 * @param bool $fresh Ask Zinn® now.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function state( bool $fresh = false ) {
		if ( ! Connection::connected() ) {
			return array(
				'connected' => false,
				'pro'       => Plugin::is_pro(),
				'canSpend'  => false,
				'sources'   => array(),
			);
		}
		$kept = get_option( self::OPTION, array() );
		if ( ! $fresh && is_array( $kept ) && isset( $kept['at'], $kept['data'] ) && (int) $kept['at'] > time() - self::TTL ) {
			return self::shape( (array) $kept['data'] );
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/data-sources' );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}
		$data = is_array( $result['body'] ) ? $result['body'] : array();
		update_option(
			self::OPTION,
			array(
				'at'   => time(),
				'data' => $data,
			),
			false
		);

		return self::shape( $data );
	}

	/**
	 * The screens' copy: each source marked as available here or Pro-only, links kept only when
	 * they are https.
	 *
	 * @param array<string, mixed> $data The API's answer.
	 * @return array<string, mixed>
	 */
	public static function shape( array $data ): array {
		$sources = array();
		foreach ( (array) ( $data['sources'] ?? array() ) as $source ) {
			if ( ! is_array( $source ) || '' === (string) ( $source['key'] ?? '' ) ) {
				continue;
			}
			$source['available']       = self::allowed( (string) $source['key'] );
			$source['key_url']         = Client::https( $source['key_url'] ?? '' );
			$source['attribution_url'] = Client::https( $source['attribution_url'] ?? '' );
			$sources[]                 = $source;
		}
		$data['sources']    = $sources;
		$data['connected']  = true;
		$data['pro']        = Plugin::is_pro();
		$data['canSpend']   = Connection::can_spend();
		$data['canCompete'] = (bool) apply_filters( 'zinn_seo_authority_competitors', false );

		return $data;
	}

	/**
	 * Forget the kept list (after any change, and on disconnect).
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Connect the site owner's key. The key goes to Zinn® and nowhere else.
	 *
	 * @param string               $source Source key.
	 * @param array<string, mixed> $fields `api_key`, or Moz's `access_id` + `access_secret`; Semrush's `database`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function connect_key( string $source, array $fields ) {
		$gate = self::gate( $source );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$body = array();
		foreach ( array( 'api_key', 'access_id', 'access_secret', 'database' ) as $name ) {
			$value = isset( $fields[ $name ] ) && is_string( $fields[ $name ] ) ? trim( $fields[ $name ] ) : '';
			if ( '' !== $value ) {
				// Keys are opaque: no sanitising that could change one. Length is capped by the API.
				$body[ $name ] = 'database' === $name ? sanitize_key( $value ) : $value;
			}
		}
		$result = Connection::call( 'PUT', '/v1/seo-connection/data-sources/' . rawurlencode( $source ) . '/key', $body );
		unset( $body, $fields );
		self::forget();

		return $result['ok'] ? (array) $result['body'] : self::error( $result );
	}

	/**
	 * Forget the key at Zinn® (deleted from its vault); history stays.
	 *
	 * @param string $source Source key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function remove_key( string $source ) {
		// ⭐ Any source a key was ever stored for may be removed, even one this site can no longer
		// connect: taking a key back out of Zinn®'s vault must never need a licence.
		if ( 1 !== preg_match( '/^[a-z_]{2,32}$/', $source ) ) {
			return self::invalid();
		}
		$result = Connection::call( 'DELETE', '/v1/seo-connection/data-sources/' . rawurlencode( $source ) . '/key' );
		self::forget();

		return $result['ok'] ? array( 'removed' => true ) : self::error( $result );
	}

	/**
	 * Save what to show, track and be told about, and how often.
	 *
	 * @param string               $source Source key.
	 * @param array<string, mixed> $feed   `show`, `track`, `alerts`, `cadence_days`, `enabled`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save_feed( string $source, array $feed ) {
		$gate = self::gate( $source );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$alerts = array();
		foreach ( (array) ( $feed['alerts'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$alerts[] = array(
				'metric' => sanitize_key( (string) ( $rule['metric'] ?? '' ) ),
				'when'   => sanitize_key( (string) ( $rule['when'] ?? '' ) ),
				'by'     => preg_replace( '/[^0-9.]/', '', (string) ( $rule['by'] ?? '0' ) ),
			);
		}
		$body   = array(
			'show'         => array_values( array_map( 'sanitize_key', (array) ( $feed['show'] ?? array() ) ) ),
			'track'        => array_values( array_map( 'sanitize_key', (array) ( $feed['track'] ?? array() ) ) ),
			'alerts'       => $alerts,
			'cadence_days' => (int) ( $feed['cadence_days'] ?? 30 ),
			'enabled'      => ! isset( $feed['enabled'] ) || (bool) $feed['enabled'],
		);
		$result = Connection::call( 'PUT', '/v1/seo-connection/data-sources/' . rawurlencode( $source ) . '/feed', $body );
		self::forget();

		return $result['ok'] ? (array) $result['body'] : self::error( $result );
	}

	/**
	 * What a schedule will spend — before it is saved.
	 *
	 * @param string             $source  Source key.
	 * @param array<int, string> $metrics Metrics.
	 * @param int                $cadence Days between checks.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function estimate( string $source, array $metrics, int $cadence ) {
		if ( ! self::allowed( $source ) ) {
			return self::invalid();
		}
		$result = Connection::call(
			'POST',
			'/v1/seo-connection/data-sources/' . rawurlencode( $source ) . '/estimate',
			array(
				'metrics'      => array_values( array_map( 'sanitize_key', $metrics ) ),
				'cadence_days' => $cadence,
			)
		);

		return $result['ok'] ? (array) $result['body'] : self::error( $result );
	}

	/**
	 * Check a source now (the result arrives in a minute; the screen reads it again).
	 *
	 * @param string $source Source key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $source ) {
		$gate = self::gate( $source );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/data-sources/' . rawurlencode( $source ) . '/run' );
		self::forget();

		return $result['ok'] ? (array) $result['body'] : self::error( $result );
	}

	/**
	 * One metric's history for the site (and its competitors).
	 *
	 * @param string $source Source key.
	 * @param string $metric Metric key.
	 * @param int    $days   Days back (1 – 1,095).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function history( string $source, string $metric, int $days ) {
		if ( ! self::allowed( $source ) ) {
			return self::invalid();
		}
		$query  = http_build_query(
			array(
				'source' => $source,
				'metric' => sanitize_key( $metric ),
				'days'   => max( 1, min( 1095, $days ) ),
			)
		);
		$result = Connection::call( 'GET', '/v1/seo-connection/data-history?' . $query );
		if ( ! $result['ok'] ) {
			return self::error( $result );
		}
		$body                    = (array) $result['body'];
		$body['attribution_url'] = Client::https( $body['attribution_url'] ?? '' );

		return $body;
	}

	/**
	 * Refuse a source this site may not use.
	 *
	 * @param string $source Source key.
	 * @return true|\WP_Error
	 */
	private static function gate( string $source ) {
		return self::allowed( $source ) ? true : self::invalid();
	}

	/**
	 * A source this site cannot connect.
	 *
	 * @return \WP_Error
	 */
	private static function invalid(): \WP_Error {
		return new \WP_Error( 'zinn_seo_authority_source', __( 'This site cannot connect that source.', 'zinn-seo' ), array( 'status' => 404 ) );
	}

	/**
	 * A failed call, with the sentence the site owner needs.
	 *
	 * @param array<string, mixed> $result A failed call.
	 * @return \WP_Error
	 */
	public static function error( array $result ): \WP_Error {
		$error  = (array) ( $result['error'] ?? array() );
		$key    = (string) ( $error['refusal'] ?? '' );
		$params = (array) ( $error['params'] ?? array() );
		$said   = self::sentence( $key, $params );
		if ( '' === $said ) {
			return Client::as_error( $result );
		}
		// ⛔ Q201: a refusal Zinn® classified (CLAUDE.md §2.57 — the site owner's own key, a
		// supplier outage, Zinn® declining) keeps its class and its door: whose key, the vendor's
		// API keys or billing page, the status page. Only the sentence becomes this screen's.
		// Before, every refusal with a sentence here became `input` and the link was dropped.
		if ( in_array( (string) ( $error['class'] ?? '' ), array( 'own_key', 'outage', 'refused', 'reconnect', 'busy' ), true ) ) {
			$result['error']['message'] = $said;

			return Client::as_error( $result );
		}
		$status = (int) ( $result['status'] ?? 0 );

		return new \WP_Error(
			'zinn_seo_authority',
			$said,
			array(
				'status' => $status >= 400 ? $status : 502,
				'zinn'   => array(
					'class'   => 'input',
					'code'    => $key,
					'message' => $said,
				),
			)
		);
	}

	/**
	 * Zinn®'s refusal as a sentence in the reader's language. '' for one this version does not know.
	 *
	 * @param string               $key    The refusal key.
	 * @param array<string, mixed> $params Its parameters.
	 * @return string
	 */
	public static function sentence( string $key, array $params ): string {
		$vendor = sanitize_text_field( (string) ( $params['vendor'] ?? '' ) );
		switch ( $key ) {
			case 'refusal.seoData.keyRefused':
				/* translators: %s: a company name, e.g. "Ahrefs". */
				return sprintf( __( '%s did not accept that key. Copy it again from your account there and check it has API access.', 'zinn-seo' ), $vendor );
			case 'refusal.seoData.keyRequired':
				return __( 'Paste the API key from your account first.', 'zinn-seo' );
			case 'refusal.seoData.keyInvalid':
				return __( 'That does not look like an API key. Copy it again from your account.', 'zinn-seo' );
			case 'refusal.seoData.vendorUnavailable':
				/* translators: %s: a company name, e.g. "Majestic". */
				return sprintf( __( '%s did not answer just now, so the key was not saved. Try again in a few minutes.', 'zinn-seo' ), $vendor );
			case 'refusal.seoData.vaultUnavailable':
				return __( 'Your key could not be stored safely just now, so nothing was saved. Try again in a few minutes.', 'zinn-seo' );
			case 'refusal.seoData.metricInvalid':
				return __( 'Choose metrics this source provides.', 'zinn-seo' );
			case 'refusal.seoData.cadenceInvalid':
				return __( 'Choose to check daily, weekly or monthly.', 'zinn-seo' );
			case 'refusal.seoData.alertInvalid':
				return __( 'Each alert needs a metric, a kind and a number of 0 or more.', 'zinn-seo' );
			case 'refusal.seoData.tooManyCompetitors':
				/* translators: %d: how many competitors may be tracked. */
				return sprintf( __( 'Track up to %d competitors.', 'zinn-seo' ), (int) ( $params['max'] ?? 10 ) );
			case 'refusal.seoData.noFeed':
				return __( 'Choose what to track from this source first.', 'zinn-seo' );
			case 'refusal.seoData.accountOnHold':
				return __( 'Your Zinn® account is on hold, so no key can be added. Sort it out in your Zinn® account and try again.', 'zinn-seo' );
			case 'refusal.seoData.runUnavailable':
				return __( 'The check could not be started just now. Nothing was spent. Try again in a few minutes.', 'zinn-seo' );
		}

		return '';
	}
}
