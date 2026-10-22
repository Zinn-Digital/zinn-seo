<?php
/**
 * The live Zinn® catalogue as this plugin shows it (zs-650).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Catalogue;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ The plugin holds no product list and no price: Zinn® answers both (`listSeoCatalogue`), so a
 * new service or a new price shows here without a plugin release. The answer is kept an hour in
 * an option that is not autoloaded; a visitor's page never reads it.
 *
 * Every price is a whole number of minor units with its currency (never a float), and is shown
 * as Zinn® sent it. Buying opens the service's page in the person's Zinn® account in a NEW TAB:
 * nothing is bought from WordPress (WordPress.org guideline 8 — no checkout inside the plugin).
 */
final class Catalogue {

	/** The kept answer. Not autoloaded. */
	public const OPTION = 'zseo_catalogue';

	/** Seconds the answer is kept. */
	public const TTL = HOUR_IN_SECONDS;

	/** Purchase states that mean the account has it. */
	public const HELD = array( 'pending', 'fulfilling', 'awaiting_staff', 'completed' );

	/**
	 * Forget the kept answer (connection changed).
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * One product row as Zinn® sent it, cleaned; null when it is not a product.
	 *
	 * @param mixed $row The API's row.
	 * @return array<string, mixed>|null
	 */
	public static function clean_product( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$code     = sanitize_key( (string) ( $row['code'] ?? '' ) );
		$currency = strtoupper( (string) ( $row['currency'] ?? '' ) );
		if ( '' === $code || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) || ! is_numeric( $row['price_minor'] ?? null ) ) {
			return null;
		}

		return array(
			'code'        => $code,
			'name'        => sanitize_text_field( (string) ( $row['name'] ?? $code ) ),
			'category'    => sanitize_key( (string) ( $row['category'] ?? '' ) ),
			'price_minor' => (int) $row['price_minor'],
			'currency'    => $currency,
			'interval'    => 'monthly' === ( $row['interval'] ?? '' ) ? 'monthly' : 'one_off',
			'screen'      => sanitize_key( (string) ( $row['screen'] ?? 'catalogue' ) ),
			'context'     => sanitize_key( (string) ( $row['context'] ?? '' ) ),
			'feature'     => 1 === preg_match( '/^zs-\d{3}$/', (string) ( $row['feature'] ?? '' ) ) ? (string) $row['feature'] : '',
			'buy_url'     => Client::https( $row['buy_url'] ?? '' ),
			// The offer that orders it into a Zinn® basket (stage C3), or '' (open buy_url).
			'offer'       => sanitize_key( (string) ( $row['offer'] ?? '' ) ),
			'offer_item'  => sanitize_key( (string) ( $row['offer_product'] ?? '' ) ),
		);
	}

	/**
	 * The catalogue: products and what the account holds.
	 *
	 * @param bool $fresh Ask Zinn® again.
	 * @return array{products: array<int, array<string, mixed>>, owned: array<int, array<string, mixed>>, at: int}|\WP_Error
	 */
	public static function get( bool $fresh = false ) {
		$kept = get_option( self::OPTION );
		if ( ! $fresh && is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) > time() - self::TTL && is_array( $kept['products'] ?? null ) ) {
			return $kept;
		}
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/catalogue' );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$body     = (array) $result['body'];
		$products = array_values( array_filter( array_map( array( self::class, 'clean_product' ), (array) ( $body['products'] ?? array() ) ) ) );
		$owned    = array();
		foreach ( (array) ( $body['owned'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! in_array( (string) ( $row['status'] ?? '' ), self::HELD, true ) ) {
				continue;
			}
			$owned[] = array(
				'code'   => sanitize_key( (string) ( $row['code'] ?? '' ) ),
				'name'   => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'status' => (string) $row['status'],
				'since'  => sanitize_text_field( (string) ( $row['since'] ?? '' ) ),
			);
		}
		$data = array(
			'products' => $products,
			'owned'    => $owned,
			'at'       => time(),
		);
		update_option( self::OPTION, $data, false );

		return $data;
	}

	/**
	 * The products offered in one place of the plugin (a screen and a context on it).
	 *
	 * @param array<string, mixed> $catalogue The catalogue.
	 * @param string               $screen    View id.
	 * @param string               $context   Context on it ('' = every context).
	 * @return array<int, array<string, mixed>>
	 */
	public static function offered_in( array $catalogue, string $screen, string $context = '' ): array {
		return array_values(
			array_filter(
				(array) ( $catalogue['products'] ?? array() ),
				static fn( $p ): bool => is_array( $p ) && $screen === $p['screen'] && ( '' === $context || $context === $p['context'] )
			)
		);
	}

	/**
	 * Whether the account holds any of a category's products.
	 *
	 * @param array<string, mixed> $catalogue The catalogue.
	 * @param array<int, string>   $codes     Product codes.
	 * @return bool
	 */
	public static function holds( array $catalogue, array $codes ): bool {
		foreach ( (array) ( $catalogue['owned'] ?? array() ) as $row ) {
			if ( in_array( (string) ( $row['code'] ?? '' ), $codes, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Who may see prices: people who may spend, or manage the site's SEO.
	 *
	 * @return bool
	 */
	public static function can_see(): bool {
		return current_user_can( Capabilities::SPEND ) || current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Boot data: only whether the screen exists (the list itself is fetched by the screen).
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		$data['catalogue'] = array(
			'connected' => Connection::connected(),
			'agency'    => Plugin::is_agency(),
			// Orders go through the offers module's basket when it is switched on (stage C3).
			'canOrder'  => Connection::connected() && current_user_can( Capabilities::SPEND ) && \ZinnDigital\ZinnSEO\Modules::enabled( 'offers' ),
		);

		return $data;
	}

	/**
	 * `GET /catalogue` (`?fresh=1` asks Zinn® again).
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		register_rest_route(
			Plugin::REST_NS,
			'/catalogue',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::get( (bool) $r['fresh'] ) ),
				'permission_callback' => array( self::class, 'can_see' ),
				'args'                => array(
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}
}
