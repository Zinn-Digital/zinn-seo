<?php
/**
 * What AI has cost this month, who used it, and the limits (zs-198).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read from the AI core's own usage log (Pro: `{prefix}zinn_ai_usage`, one row per request, kept
 * 400 days) and its limits (monthly spend, monthly requests, which roles may use AI), which every
 * plugin carrying the core shares. Zinn® credits rows carry the exact debit the Zinn® API
 * reported; rows on the owner's own key are priced from the model catalogue.
 */
final class Usage {

	/**
	 * The log's policy class in this copy of the core, when the Pro core is loaded.
	 *
	 * @return string|null
	 */
	private static function policy(): ?string {
		$class = '\ZinnDigital\ZinnSEO\AiCore\Pro\Pro_Policy';

		return class_exists( $class ) ? $class : null;
	}

	/**
	 * The summary the AI screen shows; null in the free edition (no log is kept there).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function summary(): ?array {
		$policy = self::policy();
		if ( null === $policy ) {
			return null;
		}
		global $wpdb;
		$table = $policy::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the core's own log table, read live for this screen.
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		$month  = $exists ? $policy::month_totals() : array(
			'requests'      => 0,
			'micro_usd'     => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
		$start  = ( new \DateTimeImmutable( 'first day of this month 00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$split  = $exists ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT provider = 'zinn' AS credits, COUNT(*) AS requests, COALESCE(SUM(cost_micro_usd),0) AS micro_usd FROM %i WHERE created_at >= %s AND outcome = 'ok' GROUP BY provider = 'zinn'", $table, $start ), ARRAY_A ) : array();
		$recent = $exists ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, user_id, host, purpose, provider, model, input_tokens, output_tokens, cost_micro_usd, outcome FROM %i ORDER BY id DESC LIMIT 25', $table ), ARRAY_A ) : array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		$credits = array(
			'requests'  => 0,
			'micro_usd' => 0,
		);
		$own     = $credits;
		foreach ( $split as $row ) {
			$totals = array(
				'requests'  => (int) $row['requests'],
				'micro_usd' => (int) $row['micro_usd'],
			);
			if ( ! empty( $row['credits'] ) ) {
				$credits = $totals;
			} else {
				$own = $totals;
			}
		}
		$rows = array();
		foreach ( $recent as $row ) {
			$user   = get_userdata( (int) $row['user_id'] );
			$rows[] = array(
				'at'       => (string) $row['created_at'],
				'who'      => $user instanceof \WP_User ? (string) $user->display_name : '',
				'plugin'   => (string) $row['host'],
				'purpose'  => (string) $row['purpose'],
				'provider' => (string) $row['provider'],
				'model'    => (string) $row['model'],
				'tokens'   => (int) $row['input_tokens'] + (int) $row['output_tokens'],
				'micro'    => (int) $row['cost_micro_usd'],
				'outcome'  => (string) $row['outcome'],
			);
		}

		return array(
			'month'   => $month,
			'credits' => $credits,
			'own'     => $own,
			'limits'  => $policy::settings(),
			'recent'  => $rows,
		);
	}
}
