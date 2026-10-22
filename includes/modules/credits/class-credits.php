<?php
/**
 * The connected account's credit: balance, what the next 30 days cost, what is paused, prices.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Credits;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amounts are whole numbers everywhere: Zinn® credit is counted in millionths of a US dollar
 * ("micros") and a pack's price in cents. Nothing here is ever a float until it is printed.
 *
 * The account is read from Zinn® and kept for five minutes in an option that is not autoloaded,
 * so opening the screens does not call the API on every click and a visitor's page never reads
 * it. Whether a paid check may run is decided by Zinn® at the moment of spending (credit, the
 * site's monthly limit, the account's standing); this copy only decides what the screens SAY.
 */
final class Credits {

	/** The cached account. */
	public const OPTION = 'zseo_credits';

	/** Seconds the account is kept. */
	public const TTL = 300;

	/** Micros in one dollar. */
	public const MICROS = 1000000;

	/**
	 * The units a check is priced in, with the product each belongs to.
	 *
	 * @return array<string, string> unit => tool id.
	 */
	public static function units(): array {
		return array(
			'rank_check'         => 'rank',
			'index_check'        => 'index',
			'ai_prompt_run'      => 'ai',
			'link_check'         => 'links',
			'audit_page'         => 'audit',
			'keyword_list'       => 'keywords',
			'domain_report'      => 'competitors',
			// The research toolbox (C1).
			'geo_point'          => 'toolbox',
			'review_read'        => 'toolbox',
			'shelf_check'        => 'toolbox',
			'youtube_check'      => 'toolbox',
			'serp_snapshot'      => 'toolbox',
			'traffic_report'     => 'toolbox',
			'tech_lookup'        => 'toolbox',
			'sentiment_check'    => 'toolbox',
			'question_set'       => 'toolbox',
			'content_brief'      => 'toolbox',
			// Google Trends in the toolbox and the keyword finder (X1B, zs-709).
			'trends_check'       => 'toolbox',
			// The writing tools (X1C): a sentence searched by the originality check.
			'originality_phrase' => 'writing',
		);
	}

	/**
	 * An amount of credit as money, for PHP-printed text (e-mails, WP-CLI). Amounts under ten
	 * cents keep four decimals, so a price of $0.0185 is not shown as "$0.02".
	 *
	 * @param int $micros Millionths of a US dollar.
	 * @return string
	 */
	public static function money( int $micros ): string {
		$negative = $micros < 0;
		$micros   = abs( $micros );
		$decimals = $micros > 0 && $micros < 100000 ? 4 : 2;
		$unit     = 10 ** ( 6 - $decimals );
		// Half up, in whole numbers.
		$scaled = intdiv( $micros + intdiv( $unit, 2 ), $unit );
		$whole  = intdiv( $scaled, 10 ** $decimals );
		$part   = str_pad( (string) ( $scaled % ( 10 ** $decimals ) ), $decimals, '0', STR_PAD_LEFT );

		return ( $negative ? '-' : '' ) . '$' . number_format_i18n( $whole ) . '.' . $part;
	}

	/**
	 * What a run costs: units × the unit's price. Null when the price is not known (never zero:
	 * an unknown price must not read as free).
	 *
	 * @param array<string, int> $prices unit => micros.
	 * @param string             $unit   Unit.
	 * @param int                $count  How many.
	 * @return int|null
	 */
	public static function cost( array $prices, string $unit, int $count ): ?int {
		if ( ! isset( $prices[ $unit ] ) || $prices[ $unit ] < 0 || $count < 0 ) {
			return null;
		}

		return (int) $prices[ $unit ] * $count;
	}

	/**
	 * The one-word state the screens colour by, worst first.
	 *
	 * @param array<string, mixed> $account The API's account.
	 * @return string held|empty|short|low|ok.
	 */
	public static function state( array $account ): string {
		$holds = (array) ( $account['holds'] ?? array() );
		if ( ! empty( $holds['site'] ) || ! empty( $holds['org_held_since'] ) ) {
			return 'held';
		}
		if ( ! empty( $account['is_empty'] ) || (int) ( $account['balance_micros'] ?? 0 ) <= 0 ) {
			return 'empty';
		}
		if ( ! empty( $account['forecast']['short'] ) ) {
			return 'short';
		}

		return ! empty( $account['is_low'] ) ? 'low' : 'ok';
	}

	/**
	 * The account as the screens use it: only what is shown, every amount a whole number, every
	 * address https.
	 *
	 * @param array<string, mixed> $account The API's `getSeoAccount` answer.
	 * @return array<string, mixed>
	 */
	public static function view( array $account ): array {
		$prices = array();
		foreach ( (array) ( $account['unit_prices'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['unit'], $row['price_micros'] ) && isset( self::units()[ (string) $row['unit'] ] ) ) {
				$prices[ (string) $row['unit'] ] = (int) $row['price_micros'];
			}
		}
		$packs = array();
		foreach ( (array) ( $account['packs'] ?? array() ) as $pack ) {
			if ( ! is_array( $pack ) || empty( $pack['code'] ) ) {
				continue;
			}
			$packs[] = array(
				'code'         => sanitize_key( (string) $pack['code'] ),
				'name'         => sanitize_text_field( (string) ( $pack['name'] ?? '' ) ),
				'price_minor'  => (int) ( $pack['price_minor'] ?? 0 ),
				'currency'     => strtoupper( sanitize_key( (string) ( $pack['currency'] ?? 'USD' ) ) ),
				'grant_micros' => (int) ( $pack['grant_micros'] ?? 0 ),
				'bonus_micros' => (int) ( $pack['bonus_micros'] ?? 0 ),
			);
		}
		$held = array();
		foreach ( (array) ( $account['holds']['site'] ?? array() ) as $kind => $since ) {
			$held[ sanitize_key( (string) $kind ) ] = sanitize_text_field( (string) $since );
		}
		$forecast = (array) ( $account['forecast'] ?? array() );
		$site     = (array) ( $account['site'] ?? array() );
		$auto     = (array) ( $account['settings'] ?? array() );
		$buy      = Client::https( $account['buy_url'] ?? '' );

		return array(
			'state'          => self::state( $account ),
			'currency'       => 'USD',
			'balance_micros' => (int) ( $account['balance_micros'] ?? 0 ),
			'is_low'         => ! empty( $account['is_low'] ),
			'is_empty'       => ! empty( $account['is_empty'] ),
			'forecast'       => array(
				'spend_micros' => (int) ( $forecast['spend_micros'] ?? 0 ),
				'days'         => (int) ( $forecast['days'] ?? 30 ),
				'runs_out_on'  => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $forecast['runs_out_on'] ?? '' ) ) ? (string) $forecast['runs_out_on'] : '',
				'short'        => ! empty( $forecast['short'] ),
			),
			'holds'          => array(
				'site'          => $held,
				'since'         => sanitize_text_field( (string) ( $account['holds']['org_held_since'] ?? '' ) ),
				'needed_micros' => (int) ( $account['holds']['needed_micros'] ?? 0 ),
			),
			'prices'         => $prices,
			'site'           => array(
				'domain'           => sanitize_text_field( (string) ( $site['domain'] ?? '' ) ),
				'funding'          => 'credits' === ( $site['funding'] ?? '' ) ? 'credits' : 'plan',
				'kinds'            => array_values( array_map( 'sanitize_key', (array) ( $site['kinds'] ?? array() ) ) ),
				'cap_micros'       => isset( $site['monthly_spend_cap_micros'] ) ? (int) $site['monthly_spend_cap_micros'] : null,
				'spent_micros'     => (int) ( $site['spent_this_month_micros'] ?? 0 ),
				'remaining_micros' => isset( $site['cap_remaining_micros'] ) ? (int) $site['cap_remaining_micros'] : null,
			),
			'auto_topup'     => array(
				'on'               => ! empty( $auto['auto_topup_enabled'] ),
				'paused'           => ! empty( $auto['auto_topup_paused'] ),
				'threshold_micros' => (int) ( $auto['effective_threshold_micros'] ?? 0 ),
				'pack'             => sanitize_key( (string) ( $auto['auto_topup_pack_code'] ?? '' ) ),
				'failed'           => '' !== (string) ( $auto['auto_topup_last_error'] ?? '' ),
				// P10 / Q127: the rest of the account's settings, so the plugin shows them all.
				'cap_micros'       => isset( $auto['effective_monthly_cap_micros'] ) ? (int) $auto['effective_monthly_cap_micros'] : null,
				'spent_micros'     => (int) ( $auto['auto_topup_spent_this_month_micros'] ?? 0 ),
				'min_micros'       => (int) ( $auto['min_threshold_micros'] ?? 0 ),
			),
			'packs'          => $packs,
			'buy_url'        => $buy,
		);
	}

	/**
	 * The account, from the copy kept for five minutes or fresh from Zinn®.
	 *
	 * @param bool $fresh Ask Zinn® now.
	 * @return array<string, mixed>|\WP_Error `{connected: false}` for a site that is not connected.
	 */
	public static function account( bool $fresh = false ) {
		if ( ! Connection::connected() ) {
			return array( 'connected' => false );
		}
		$kept = get_option( self::OPTION, array() );
		if ( ! $fresh && is_array( $kept ) && isset( $kept['at'], $kept['view'] ) && (int) $kept['at'] > time() - self::TTL ) {
			return array(
				'connected'  => true,
				'fetched_at' => (int) $kept['at'],
			) + (array) $kept['view'];
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/account' );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$view   = self::view( (array) $result['body'] );
		$before = is_array( $kept ) && isset( $kept['view']['state'] ) ? (string) $kept['view']['state'] : '';
		update_option(
			self::OPTION,
			array(
				'at'   => time(),
				'view' => $view,
			),
			false
		);
		if ( $before !== $view['state'] ) {
			/**
			 * Fires when the account's credit state changes (held, empty, short, low, ok).
			 *
			 * @param string               $state  The new state.
			 * @param string               $before The state before ('' the first time).
			 * @param array<string, mixed> $view   The account as the screens use it.
			 */
			do_action( 'zinn_seo_credit_state', $view['state'], $before, $view );
		}

		return array(
			'connected'  => true,
			'fetched_at' => time(),
		) + $view;
	}

	/**
	 * Forget the kept copy (after a spend, a top-up notice, a disconnect).
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The unit prices (for a cost estimate).
	 *
	 * @param bool $kept_only Only from the copy kept here, however old — never a call to Zinn®
	 *                        (the editor asks while it loads, and must not wait on the network).
	 * @return array<string, int>
	 */
	public static function prices( bool $kept_only = false ): array {
		if ( $kept_only ) {
			$kept = get_option( self::OPTION, array() );

			return is_array( $kept ) && isset( $kept['view']['prices'] ) ? array_map( 'intval', (array) $kept['view']['prices'] ) : array();
		}
		$account = self::account();

		return is_array( $account ) && isset( $account['prices'] ) ? (array) $account['prices'] : array();
	}
}
