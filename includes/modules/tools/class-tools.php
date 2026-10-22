<?php
/**
 * The pay-per-use checks: rankings, AI answers, backlinks, the site audit and keyword research.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Tools;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Credits\Credits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every check runs at Zinn® on the connected site's own "watch-list row" (its target): this class
 * reads and changes that row and starts runs, with the site's connection token. It holds no
 * result of its own — the history lives with the account, so it survives a site move and is the
 * same in the Zinn® app.
 *
 * ⛔ Money. A check the account's plan does not already include is paid per use from credit. Zinn®
 * decides at the moment of spending whether it may run (credit, the site's monthly limit, the
 * account's standing) and answers a refusal BEFORE anything is charged; this class passes that
 * answer on in plain words (`Client::sentence()`). Whatever starts spending — switching a
 * schedule on, saving a list that is checked on a schedule, "Run now" — needs the capability to
 * spend the owner's credit, and the screens show the price first.
 */
final class Tools {

	/** Where the target's id is kept between requests. */
	public const OPTION = 'zseo_tools';

	/** The most phrases, questions or links one list may hold here (the API has its own ceilings). */
	public const LIST_MAX = 1000;

	/**
	 * The tools: id => the product at Zinn® (`kind`), the unit a run is priced in, how a run is
	 * started and whether it can run on a schedule.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defs(): array {
		return array(
			'rank'        => array(
				'kind'     => 'rank',
				'unit'     => 'rank_check',
				'schedule' => true,
				'feature'  => 'zs-184',
			),
			'ai'          => array(
				'kind'     => 'ai_visibility',
				'unit'     => 'ai_prompt_run',
				'schedule' => true,
				'feature'  => 'zs-182',
			),
			'links'       => array(
				'kind'     => 'link_watch',
				'unit'     => 'link_check',
				// It follows its list: a saved list is checked on the schedule, an empty one is not.
				'schedule' => false,
				'feature'  => 'zs-183',
			),
			'audit'       => array(
				'kind'     => 'onpage',
				'unit'     => 'audit_page',
				'schedule' => true,
				'feature'  => 'zs-185',
			),
			'keywords'    => array(
				'kind'     => 'keyword_research',
				'unit'     => 'keyword_list',
				'schedule' => false,
				'research' => 'keywords',
				'feature'  => 'zs-186',
			),
			'competitors' => array(
				'kind'     => 'competitors',
				'unit'     => 'domain_report',
				'schedule' => false,
				'research' => 'gap',
				'feature'  => 'zs-413',
			),
			'domain'      => array(
				'kind'     => 'domain_score',
				'unit'     => 'domain_report',
				'schedule' => false,
				'research' => 'domain',
				'feature'  => 'zs-413',
			),
			// Sold by the month in the Zinn® account, never per use: they run only when the
			// account's plan holds them, and nothing here can switch them to pay-per-use.
			'profile'     => array(
				'kind'      => 'backlinks',
				'unit'      => '',
				'schedule'  => false,
				'plan_only' => true,
				'feature'   => 'zs-183',
			),
			'mentions'    => array(
				'kind'      => 'brand_mentions',
				'unit'      => '',
				'schedule'  => false,
				'plan_only' => true,
				'feature'   => 'zs-187',
			),
			'local'       => array(
				'kind'      => 'local_rank',
				'unit'      => '',
				'schedule'  => false,
				'plan_only' => true,
				'feature'   => 'zs-113',
			),
		);
	}

	/**
	 * The countries a check can be asked in: country code => the country's English name (the
	 * words Zinn® resolves) and its main language. The screens show the name in the reader's
	 * language.
	 *
	 * @return array<string, array{location: string, language: string}>
	 */
	public static function markets(): array {
		$rows = array(
			'US' => array( 'United States', 'en' ),
			'GB' => array( 'United Kingdom', 'en' ),
			'IE' => array( 'Ireland', 'en' ),
			'CA' => array( 'Canada', 'en' ),
			'AU' => array( 'Australia', 'en' ),
			'IN' => array( 'India', 'en' ),
			'DE' => array( 'Germany', 'de' ),
			'FR' => array( 'France', 'fr' ),
			'ES' => array( 'Spain', 'es' ),
			'IT' => array( 'Italy', 'it' ),
			'NL' => array( 'Netherlands', 'nl' ),
			'AE' => array( 'United Arab Emirates', 'ar' ),
			'SA' => array( 'Saudi Arabia', 'ar' ),
			// A country for each language the plugin is written in (zs-766): the names Zinn® resolves.
			'ET' => array( 'Ethiopia', 'am' ),
			'EG' => array( 'Egypt', 'ar' ),
			'AZ' => array( 'Azerbaijan', 'az' ),
			'BG' => array( 'Bulgaria', 'bg' ),
			'BD' => array( 'Bangladesh', 'bn' ),
			'CZ' => array( 'Czechia', 'cs' ),
			'AT' => array( 'Austria', 'de' ),
			'CH' => array( 'Switzerland', 'de' ),
			'GR' => array( 'Greece', 'el' ),
			'MX' => array( 'Mexico', 'es' ),
			'AR' => array( 'Argentina', 'es' ),
			'NG' => array( 'Nigeria', 'en' ),
			'IL' => array( 'Israel', 'he' ),
			'HR' => array( 'Croatia', 'hr' ),
			'HU' => array( 'Hungary', 'hu' ),
			'AM' => array( 'Armenia', 'hy' ),
			'ID' => array( 'Indonesia', 'id' ),
			'JP' => array( 'Japan', 'ja' ),
			'GE' => array( 'Georgia', 'ka' ),
			'KZ' => array( 'Kazakhstan', 'kk' ),
			'KH' => array( 'Cambodia', 'km' ),
			'KR' => array( 'South Korea', 'ko' ),
			'LA' => array( 'Laos', 'lo' ),
			'MN' => array( 'Mongolia', 'mn' ),
			'MY' => array( 'Malaysia', 'ms' ),
			'MM' => array( 'Myanmar (Burma)', 'my' ),
			'NP' => array( 'Nepal', 'ne' ),
			'BE' => array( 'Belgium', 'nl' ),
			'PK' => array( 'Pakistan', 'ur' ),
			'PL' => array( 'Poland', 'pl' ),
			'AF' => array( 'Afghanistan', 'ps' ),
			'BR' => array( 'Brazil', 'pt' ),
			'PT' => array( 'Portugal', 'pt' ),
			'RO' => array( 'Romania', 'ro' ),
			'LK' => array( 'Sri Lanka', 'si' ),
			'SO' => array( 'Somalia', 'so' ),
			'AL' => array( 'Albania', 'sq' ),
			'RS' => array( 'Serbia', 'sr' ),
			'KE' => array( 'Kenya', 'sw' ),
			'TZ' => array( 'Tanzania', 'sw' ),
			'TJ' => array( 'Tajikistan', 'tg' ),
			'TH' => array( 'Thailand', 'th' ),
			'PH' => array( 'Philippines', 'tl' ),
			'TR' => array( 'Turkiye', 'tr' ),
			'UA' => array( 'Ukraine', 'uk' ),
			'UZ' => array( 'Uzbekistan', 'uz' ),
			'VN' => array( 'Vietnam', 'vi' ),
			'SG' => array( 'Singapore', 'en' ),
		);
		$out = array();
		foreach ( $rows as $code => $row ) {
			$out[ $code ] = array(
				'location' => $row[0],
				'language' => $row[1],
			);
		}

		return $out;
	}

	/**
	 * The market that fits a WordPress locale (`de_DE` → DE/de; `en_GB` → GB/en; `fr_CA` →
	 * CA/fr). A language with no country here is asked in the first market that speaks it, and
	 * anything else in the United States, in that language.
	 *
	 * @param string $locale WordPress locale or language tag.
	 * @return array{country: string, location: string, language: string}
	 */
	public static function market_for( string $locale ): array {
		$parts    = preg_split( '/[_-]/', $locale );
		$language = strtolower( (string) ( $parts[0] ?? 'en' ) );
		$country  = strtoupper( (string) ( $parts[1] ?? '' ) );
		$markets  = self::markets();
		if ( 'UK' === $country ) {
			$country = 'GB';
		}
		if ( ! isset( $markets[ $country ] ) ) {
			$country = 'US';
			foreach ( $markets as $code => $market ) {
				if ( $market['language'] === $language ) {
					$country = $code;
					break;
				}
			}
		}

		return array(
			'country'  => $country,
			'location' => $markets[ $country ]['location'],
			'language' => 1 === preg_match( '/^[a-z]{2,3}$/', $language ) ? $language : $markets[ $country ]['language'],
		);
	}

	/** Questions an AI-answers check asks when the account has no list of its own. */
	public const AI_QUESTIONS = 3;

	/** Pages one site audit reads when the account's plan does not say more. */
	public const AUDIT_PAGES = 100;

	/**
	 * How many units one run of a tool spends, from the target as it is now.
	 *
	 * @param string               $tool   Tool id.
	 * @param array<string, mixed> $target The target.
	 * @return int
	 */
	public static function units( string $tool, array $target ): int {
		switch ( $tool ) {
			case 'rank':
				return count( self::clean_keywords( (array) ( $target['keywords'] ?? array() ) ) );
			case 'ai':
				$own = count( array_filter( array_map( 'strval', (array) ( $target['prompts'] ?? array() ) ), 'strlen' ) );
				return $own > 0 ? $own : self::AI_QUESTIONS;
			case 'links':
				return count( (array) ( $target['watched_links'] ?? array() ) );
			case 'audit':
				return self::AUDIT_PAGES;
			case 'keywords':
			case 'competitors':
			case 'domain':
				return 1;
		}

		return 0;
	}

	/**
	 * Phrases to track, cleaned: no empty phrase, no duplicate (the same phrase from another
	 * country, language or device is a different check and stays).
	 *
	 * @param array<int, mixed> $rows Rows.
	 * @return array<int, array{phrase: string, location: string, language: string, device: string}>
	 */
	public static function clean_keywords( array $rows ): array {
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$row    = is_array( $row ) ? $row : array( 'phrase' => $row );
			$phrase = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $row['phrase'] ?? '' ) ) ) ?? '' );
			$device = (string) ( $row['device'] ?? '' );
			$clean  = array(
				'phrase'   => function_exists( 'mb_substr' ) ? mb_substr( $phrase, 0, 200 ) : substr( $phrase, 0, 200 ),
				'location' => substr( sanitize_text_field( (string) ( $row['location'] ?? '' ) ), 0, 120 ),
				'language' => substr( sanitize_key( (string) ( $row['language'] ?? '' ) ), 0, 8 ),
				'device'   => in_array( $device, array( 'desktop', 'mobile' ), true ) ? $device : '',
			);
			$key    = strtolower( implode( "\x1f", $clean ) );
			if ( '' === $clean['phrase'] || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $clean;
		}

		return $out;
	}

	/**
	 * Links to watch, cleaned: an http(s) page that should link here, optionally to which page
	 * and with which words.
	 *
	 * @param array<int, mixed> $rows Rows.
	 * @return array<int, array{source_url: string, target_url: string, anchor: string}>
	 */
	public static function clean_links( array $rows ): array {
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$row    = is_array( $row ) ? $row : array( 'source_url' => $row );
			$source = Client::web( $row['source_url'] ?? '', 1000 );
			$target = Client::web( $row['target_url'] ?? '', 1000 );
			if ( '' === $source || isset( $seen[ strtolower( $source . ' ' . $target ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $source . ' ' . $target ) ] = true;
			$out[] = array(
				'source_url' => $source,
				'target_url' => $target,
				'anchor'     => substr( sanitize_text_field( (string) ( $row['anchor'] ?? '' ) ), 0, 500 ),
			);
		}

		return $out;
	}

	/**
	 * The site's target, read from Zinn® (created there on first use).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function target() {
		$result = Connection::call( 'GET', '/v1/seo-connection/target' );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$target = (array) $result['body'];
		$id     = (string) ( $target['id'] ?? '' );
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return new \WP_Error( 'zinn_seo_ours', Client::sentence( array( 'class' => 'ours' ) ), array( 'status' => 502 ) );
		}
		$kept = get_option( self::OPTION, array() );
		if ( ! is_array( $kept ) || ( $kept['target_id'] ?? '' ) !== $id ) {
			update_option( self::OPTION, array( 'target_id' => $id ) + ( is_array( $kept ) ? $kept : array() ), false );
		}

		return $target;
	}

	/**
	 * The target's id, from the kept copy or from Zinn®.
	 *
	 * @return string|\WP_Error
	 */
	public static function target_id() {
		$kept = get_option( self::OPTION, array() );
		$id   = is_array( $kept ) ? (string) ( $kept['target_id'] ?? '' ) : '';
		if ( 1 === preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return $id;
		}
		$target = self::target();

		return is_wp_error( $target ) ? $target : (string) $target['id'];
	}

	/**
	 * What the account's plan already includes (so the screens never offer to sell it again).
	 *
	 * @return array<string, mixed>
	 */
	public static function plan(): array {
		$result = Connection::call( 'GET', '/v1/visibility/plan' );
		$body   = $result['ok'] ? (array) $result['body'] : array();

		return array(
			'products'  => array_values( array_map( 'sanitize_key', (array) ( $body['products'] ?? array() ) ) ),
			'from_plan' => array_values( array_map( 'sanitize_key', (array) ( $body['from_plan'] ?? array() ) ) ),
			'known'     => $result['ok'],
		);
	}

	/**
	 * The screens' starting point: the target, the plan, the prices, and each tool's state.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function overview() {
		if ( ! Connection::connected() ) {
			return array(
				'connected' => false,
				'status'    => Connection::describe()['status'],
			);
		}
		$target = self::target();
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		// Fresh, not the kept copy: the price line under "Run" compares the price with the credit,
		// and a balance five minutes old would promise a run the account can no longer pay for.
		$account = Credits::account( true );
		$plan    = self::plan();

		return array(
			'connected' => true,
			'can_spend' => Connection::can_spend(),
			'target'    => self::target_view( $target ),
			'plan'      => $plan,
			'account'   => is_wp_error( $account ) ? null : $account,
			'tools'     => self::states( $target, $plan, is_array( $account ) ? (array) ( $account['prices'] ?? array() ) : array() ),
		);
	}

	/**
	 * Each tool's state: on a schedule or not, paid by the plan or per use, paused for credit,
	 * what one run costs.
	 *
	 * @param array<string, mixed> $target The target.
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, int>   $prices unit => micros.
	 * @return array<string, array<string, mixed>>
	 */
	public static function states( array $target, array $plan, array $prices ): array {
		$kinds = (array) ( $target['kinds'] ?? array() );
		$holds = (array) ( $target['credit_holds'] ?? array() );
		$out   = array();
		foreach ( self::defs() as $id => $def ) {
			$in_plan    = in_array( $def['kind'], (array) $plan['products'], true );
			$units      = self::units( $id, $target );
			$out[ $id ] = array(
				'kind'       => $def['kind'],
				'unit'       => $def['unit'],
				'schedule'   => (bool) $def['schedule'],
				'plan_only'  => ! empty( $def['plan_only'] ),
				'on'         => in_array( $def['kind'], $kinds, true ),
				'in_plan'    => $in_plan,
				'held_since' => isset( $holds[ $def['kind'] ] ) ? (string) $holds[ $def['kind'] ] : '',
				'units'      => $units,
				'unit_price' => $in_plan ? 0 : ( $prices[ $def['unit'] ] ?? null ),
				'run_cost'   => $in_plan ? 0 : Credits::cost( $prices, (string) $def['unit'], $units ),
			);
		}
		// The index checker is not a product on the watch-list row: it is paid per page, always.
		$out['index'] = array(
			'kind'       => 'index',
			'unit'       => 'index_check',
			'schedule'   => false,
			'plan_only'  => false,
			'on'         => false,
			'in_plan'    => false,
			'held_since' => '',
			'units'      => 1,
			'unit_price' => $prices['index_check'] ?? null,
			'run_cost'   => $prices['index_check'] ?? null,
		);

		return $out;
	}

	/**
	 * The target as the screens use it.
	 *
	 * @param array<string, mixed> $target The API's target.
	 * @return array<string, mixed>
	 */
	public static function target_view( array $target ): array {
		return array(
			'id'            => (string) ( $target['id'] ?? '' ),
			'domain'        => sanitize_text_field( (string) ( $target['domain'] ?? '' ) ),
			'brand'         => sanitize_text_field( (string) ( $target['brand'] ?? '' ) ),
			'category'      => sanitize_text_field( (string) ( $target['category'] ?? '' ) ),
			'market'        => sanitize_text_field( (string) ( $target['market'] ?? '' ) ),
			'funding'       => 'credits' === ( $target['funding'] ?? '' ) ? 'credits' : 'plan',
			'kinds'         => array_values( array_map( 'sanitize_key', (array) ( $target['kinds'] ?? array() ) ) ),
			'cadence_days'  => (int) ( $target['cadence_days'] ?? 7 ),
			'next_due_at'   => sanitize_text_field( (string) ( $target['next_due_at'] ?? '' ) ),
			'last_audit_at' => sanitize_text_field( (string) ( $target['last_audit_at'] ?? '' ) ),
			'keywords'      => self::clean_keywords( (array) ( $target['keywords'] ?? array() ) ),
			'prompts'       => array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $target['prompts'] ?? array() ) ) ), 'strlen' ) ),
			'links'         => self::clean_links( (array) ( $target['watched_links'] ?? array() ) ),
			'mentions'      => array_values( array_filter( (array) ( $target['mention_phrases'] ?? array() ), 'is_array' ) ),
			'holds'         => array_map( 'strval', (array) ( $target['credit_holds'] ?? array() ) ),
		);
	}

	/**
	 * One call on the target, answered as the cleaned target.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $suffix Path after the target ('' for the target itself).
	 * @param array<string, mixed> $body   Body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function on_target( string $method, string $suffix, array $body ) {
		$id = self::target_id();
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$result = Connection::call( $method, '/v1/visibility/targets/' . rawurlencode( $id ) . $suffix, $body );
		if ( ! $result['ok'] ) {
			if ( 404 === (int) $result['status'] ) {
				// The account removed the row: the next read makes a new one.
				delete_option( self::OPTION );
			}
			return Client::as_error( $result );
		}
		Credits::forget();

		return (array) $result['body'];
	}

	/**
	 * Make the checks the plan does not include pay-per-use on this site — asked for by name,
	 * only when someone who may spend switches a paid check on.
	 *
	 * @param string $tool Tool id.
	 * @return true|\WP_Error
	 */
	private static function fund( string $tool ) {
		$def = self::defs()[ $tool ] ?? null;
		if ( null === $def ) {
			return new \WP_Error( 'zinn_seo_unknown_tool', __( 'There is no check with that name.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( ! Connection::can_spend() ) {
			return Client::as_error( Client::classify( 403, array( 'code' => 'insufficient_scope' ) ) );
		}
		$target = self::target();
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$in_plan = in_array( $def['kind'], (array) self::plan()['products'], true );
		if ( ! empty( $def['plan_only'] ) ) {
			return $in_plan ? true : new \WP_Error( 'zinn_seo_needs_plan', __( 'This check is part of a monthly plan in your Zinn® account, and that plan is not active there. Nothing was charged.', 'zinn-seo' ), array( 'status' => 402 ) );
		}
		if ( 'credits' === ( $target['funding'] ?? '' ) || $in_plan ) {
			return true;
		}
		$done = self::on_target( 'PATCH', '', array( 'funding' => 'credits' ) );

		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * What the business is and where it sells (the AI questions are built from it), and how often
	 * the scheduled checks run.
	 *
	 * @param array<string, mixed> $fields brand, category, market, cadence_days.
	 * @return array<string, mixed>|\WP_Error The target view.
	 */
	public static function update_site( array $fields ) {
		$body = array();
		foreach ( array( 'brand', 'category', 'market' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$body[ $key ] = substr( sanitize_text_field( (string) $fields[ $key ] ), 0, 200 );
			}
		}
		if ( array_key_exists( 'cadence_days', $fields ) ) {
			$days                 = (int) $fields['cadence_days'];
			$body['cadence_days'] = in_array( $days, array( 0, 1, 7, 14, 30 ), true ) ? $days : 7;
		}
		if ( ! $body ) {
			return new \WP_Error( 'zinn_seo_nothing', __( 'There was nothing to save.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$target = self::on_target( 'PATCH', '', $body );

		return is_wp_error( $target ) ? $target : self::target_view( $target );
	}

	/**
	 * Put a check on the schedule, or take it off. Its history and its lists stay.
	 *
	 * @param string $tool Tool id.
	 * @param bool   $on   On the schedule.
	 * @return array<string, mixed>|\WP_Error The target view.
	 */
	public static function schedule( string $tool, bool $on ) {
		$def = self::defs()[ $tool ] ?? null;
		if ( null === $def || empty( $def['schedule'] ) ) {
			return new \WP_Error( 'zinn_seo_unknown_tool', __( 'That check does not run on a schedule.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( $on ) {
			$funded = self::fund( $tool );
			if ( is_wp_error( $funded ) ) {
				return $funded;
			}
		}
		$target = self::target();
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$kinds = array_values( array_diff( array_map( 'strval', (array) ( $target['kinds'] ?? array() ) ), array( $def['kind'] ) ) );
		if ( $on ) {
			$kinds[] = $def['kind'];
		}
		$target = self::on_target( 'PATCH', '', array( 'kinds' => $kinds ) );

		return is_wp_error( $target ) ? $target : self::target_view( $target );
	}

	/**
	 * Save a tool's list: the phrases to track, the questions to ask, the links to watch.
	 *
	 * @param string            $tool  rank|ai|links.
	 * @param array<int, mixed> $items The list.
	 * @return array<string, mixed>|\WP_Error The target view.
	 */
	public static function save_list( string $tool, array $items ) {
		if ( count( $items ) > self::LIST_MAX ) {
			/* translators: %s: a number. */
			return new \WP_Error( 'zinn_seo_too_many', sprintf( __( 'That is more than %s lines. Split the list, or track the phrases that matter most.', 'zinn-seo' ), number_format_i18n( self::LIST_MAX ) ), array( 'status' => 422 ) );
		}
		if ( 'rank' === $tool ) {
			$path = '/keywords';
			$body = array( 'keywords' => self::clean_keywords( $items ) );
		} elseif ( 'ai' === $tool ) {
			$path = '/prompts';
			$body = array( 'prompts' => array_values( array_unique( array_filter( array_map( static fn( $p ): string => substr( sanitize_text_field( (string) $p ), 0, 300 ), $items ), 'strlen' ) ) ) );
		} elseif ( 'links' === $tool ) {
			$path = '/links';
			$body = array( 'links' => self::clean_links( $items ) );
		} else {
			return new \WP_Error( 'zinn_seo_unknown_tool', __( 'That check has no list.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$funded = self::fund( $tool );
		if ( is_wp_error( $funded ) ) {
			return $funded;
		}
		$target = self::on_target( 'PUT', $path, $body );

		return is_wp_error( $target ) ? $target : self::target_view( $target );
	}

	/**
	 * Run a check now.
	 *
	 * @param string               $tool Tool id.
	 * @param array<string, mixed> $args `seed` and `page_url` (keyword research); `location` and
	 *                                   `language` (research); `competitor` (the gap report).
	 * @return array<string, mixed>|\WP_Error `{started: true, kind}`.
	 */
	public static function run( string $tool, array $args = array() ) {
		$def = self::defs()[ $tool ] ?? null;
		if ( null === $def ) {
			return new \WP_Error( 'zinn_seo_unknown_tool', __( 'There is no check with that name.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$funded = self::fund( $tool );
		if ( is_wp_error( $funded ) ) {
			return $funded;
		}
		if ( ! empty( $def['research'] ) ) {
			$body = array();
			if ( 'keywords' === $def['research'] ) {
				$seed = substr( sanitize_text_field( (string) ( $args['seed'] ?? '' ) ), 0, 120 );
				$page = Client::web( $args['page_url'] ?? '' );
				if ( '' !== $seed ) {
					$body['seed'] = $seed;
				}
				if ( '' !== $page ) {
					$body['page_url'] = $page;
				}
			}
			// The market the question is asked in (Pro: research per country and language).
			foreach ( array(
				'location' => 120,
				'language' => 8,
			) as $key => $max ) {
				$value = substr( sanitize_text_field( (string) ( $args[ $key ] ?? '' ) ), 0, $max );
				if ( '' !== $value ) {
					$body[ $key ] = $value;
				}
			}
			// The rival a gap report is drawn against (Pro).
			if ( 'gap' === $def['research'] && '' !== trim( (string) ( $args['competitor'] ?? '' ) ) ) {
				$body['competitor'] = substr( sanitize_text_field( (string) $args['competitor'] ), 0, 253 );
			}
			// An object even when empty: the API reads a JSON object.
			$result = Connection::call( 'POST', '/v1/seo-connection/research/' . $def['research'], $body ? $body : array( 'seed' => '' ) );
		} else {
			$id = self::target_id();
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$result = Connection::call( 'POST', '/v1/visibility/targets/' . rawurlencode( $id ) . '/run', array( 'kind' => $def['kind'] ) );
		}
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		Credits::forget();

		return array(
			'started' => true,
			'kind'    => (string) $def['kind'],
		);
	}

	/**
	 * A check's recent runs, newest first (reading them costs nothing).
	 *
	 * @param string $tool Tool id.
	 * @return array<string, mixed>|\WP_Error `{audits: [...]}`.
	 */
	public static function results( string $tool ) {
		$def = self::defs()[ $tool ] ?? null;
		if ( null === $def ) {
			return new \WP_Error( 'zinn_seo_unknown_tool', __( 'There is no check with that name.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$id = self::target_id();
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$result = Connection::call( 'GET', '/v1/visibility/targets/' . rawurlencode( $id ) . '/audits?kind=' . rawurlencode( (string) $def['kind'] ) );
		if ( ! $result['ok'] ) {
			// Never run on this site and not in the plan: an empty history, not an error.
			if ( 403 === (int) $result['status'] && 'refused' === ( $result['error']['class'] ?? '' ) && ! in_array( (string) ( $result['error']['code'] ?? '' ), array_values( Client::refusals() ), true ) ) {
				return array( 'audits' => array() );
			}
			return Client::as_error( $result );
		}

		return array( 'audits' => array_values( array_filter( (array) ( $result['body']['audits'] ?? array() ), 'is_array' ) ) );
	}

	/**
	 * One run with every finding.
	 *
	 * @param string $audit_id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function audit( string $audit_id ) {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $audit_id ) ) {
			return new \WP_Error( 'zinn_seo_unknown_run', __( 'That result could not be found.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$result = Connection::call( 'GET', '/v1/visibility/audits/' . rawurlencode( $audit_id ) );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * The page on this site an audit finding is about, so "Fix" opens its editor.
	 *
	 * @param string $url An address the audit named.
	 * @return array{id: int, edit: string, title: string}|null
	 */
	public static function page_for( string $url ): ?array {
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! is_string( $home ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( $home ) ) {
			return null;
		}
		$id = url_to_postid( $url );
		if ( $id < 1 || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}

		return array(
			'id'    => $id,
			'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			'title' => (string) get_the_title( $id ),
		);
	}

	/**
	 * A run, with each finding's example addresses matched to this site's pages ("fix links
	 * back into WordPress").
	 *
	 * @param array<string, mixed> $audit The API's run.
	 * @return array<string, mixed>
	 */
	public static function with_fix_links( array $audit ): array {
		$looked = 0;
		foreach ( (array) ( $audit['findings'] ?? array() ) as $i => $finding ) {
			$examples = (array) ( $finding['detail']['examples'] ?? array() );
			$pages    = array();
			foreach ( $examples as $example ) {
				// Bounded: each lookup is a query, and a run can name hundreds of addresses.
				if ( ! is_string( $example ) || ++$looked > 60 ) {
					continue;
				}
				$page = self::page_for( $example );
				if ( null !== $page ) {
					$pages[ $example ] = $page;
				}
			}
			// Always a map (an empty one too), so a reader never has to ask whether it is a list.
			$audit['findings'][ $i ]['pages'] = (object) $pages;
		}

		return $audit;
	}
}
