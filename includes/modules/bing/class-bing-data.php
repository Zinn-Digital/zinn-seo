<?php
/**
 * More of the site's own Bing Webmaster Tools data, read with the owner's own key (zs-743): crawl
 * problems Bing found, how often Bing crawls, its crawl-rate setting, backlinks Bing knows, and
 * keyword research on Bing.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bing;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free shows the summary (how many crawl problems, the first ten, how many pages have links,
 * one keyword at a time); Pro shows every problem and linked page, the links to each page and
 * the crawl-rate setting.
 *
 * ⛔ What Bing's public API does not carry is not shown: there is NO Copilot / AI-answer report
 * in the Bing Webmaster API (`IWebmasterApi`, checked 2026-10-13 — 64 methods, none about
 * Copilot or AI; four guessed method names answer 404). Copilot visits arrive in the ordinary
 * Bing traffic figures, which this module already shows, and in the AI-referral report.
 *
 * Every answer is kept an hour in one option that is not autoloaded, per method and argument.
 */
final class Bing_Data {

	/** The kept answers. */
	public const OPTION = 'zseo_bing_data';

	/** Seconds an answer is kept. */
	public const TTL = HOUR_IN_SECONDS;

	/** Rows the free plugin shows of a list. */
	public const FREE_ROWS = 10;

	/** Crawl problem flags Bing answers (`CrawlIssues`), with what each means. */
	public const ISSUES = array(
		1     => 'code301',
		2     => 'code302',
		4     => 'code4xx',
		8     => 'code5xx',
		16    => 'blocked_by_robots',
		32    => 'contains_malware',
		64    => 'important_url_blocked',
		128   => 'dns_errors',
		256   => 'timeout_errors',
		512   => 'connection_errors',
		1024  => 'content_too_big',
		2048  => 'not_ok',
		4096  => 'missing_title',
		8192  => 'missing_description',
		16384 => 'unsupported_language',
	);

	/**
	 * One Bing call, kept an hour.
	 *
	 * @param string               $name  API method.
	 * @param array<string, mixed> $query Query.
	 * @param bool                 $fresh Ask again.
	 * @return mixed|\WP_Error
	 */
	private static function read( string $name, array $query, bool $fresh = false ) {
		$key  = $name . ':' . md5( (string) wp_json_encode( $query ) );
		$kept = get_option( self::OPTION, array() );
		$kept = is_array( $kept ) ? $kept : array();
		if ( ! $fresh && isset( $kept[ $key ] ) && (int) $kept[ $key ]['at'] > time() - self::TTL ) {
			return $kept[ $key ]['data'];
		}
		$data = Bing::call( 'GET', $name, array_map( 'strval', $query ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		foreach ( $kept as $k => $row ) {
			if ( (int) ( $row['at'] ?? 0 ) <= time() - self::TTL ) {
				unset( $kept[ $k ] );
			}
		}
		$kept[ $key ] = array(
			'at'   => time(),
			'data' => $data,
		);
		update_option( self::OPTION, $kept, false );

		return $data;
	}

	/**
	 * Forget every kept answer.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Bing's dates (`/Date(1777075200000)/`) as `Y-m-d`. Pure.
	 *
	 * @param mixed $value Bing date.
	 * @return string
	 */
	public static function day( $value ): string {
		return 1 === preg_match( '#/Date\((-?\d+)#', (string) $value, $m ) ? gmdate( 'Y-m-d', intdiv( (int) $m[1], 1000 ) ) : '';
	}

	/**
	 * The problem names of a flags value. Pure.
	 *
	 * @param int $flags Bing's `Issues` bits.
	 * @return array<int, string>
	 */
	public static function issue_names( int $flags ): array {
		$out = array();
		foreach ( self::ISSUES as $bit => $name ) {
			if ( $flags & $bit ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * The crawl problems, cleaned. Pure.
	 *
	 * @param mixed $raw Bing's `GetCrawlIssues` answer.
	 * @return array<int, array<string, mixed>>
	 */
	public static function clean_issues( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['Url'] ) ) {
				continue;
			}
			$out[] = array(
				'url'      => esc_url_raw( (string) $row['Url'] ),
				'issues'   => self::issue_names( (int) ( $row['Issues'] ?? 0 ) ),
				'http'     => (int) ( $row['HttpCode'] ?? 0 ),
				'in_links' => (int) ( $row['InLinks'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Everything the Bing tab's extra sections show.
	 *
	 * @param bool $fresh Ask Bing again.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function overview( bool $fresh = false ) {
		if ( ! Bing::connected() ) {
			return new \WP_Error( 'zinn_seo_bing_key', __( 'Connect Bing Webmaster Tools with your own key first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$pro    = Plugin::is_pro();
		$site   = Bing::row()['site'];
		$issues = self::read( 'GetCrawlIssues', array( 'siteUrl' => $site ), $fresh );
		$links  = self::read(
			'GetLinkCounts',
			array(
				'siteUrl' => $site,
				'page'    => 0,
			),
			$fresh
		);
		$stats  = self::read( 'GetCrawlStats', array( 'siteUrl' => $site ), $fresh );
		$all    = is_wp_error( $issues ) ? array() : self::clean_issues( $issues );
		$pages  = array();
		foreach ( is_array( $links ) ? (array) ( $links['Links'] ?? array() ) : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['Url'] ) ) {
				$pages[] = array(
					'url'   => esc_url_raw( (string) $row['Url'] ),
					'count' => (int) ( $row['Count'] ?? 0 ),
				);
			}
		}
		$crawl = array();
		foreach ( is_array( $stats ) ? $stats : array() as $row ) {
			if ( is_array( $row ) ) {
				$crawl[] = array(
					'date'     => self::day( $row['Date'] ?? '' ),
					'crawled'  => (int) ( $row['CrawledPages'] ?? 0 ),
					'errors'   => (int) ( $row['CrawlErrors'] ?? 0 ),
					'blocked'  => (int) ( $row['BlockedByRobotsTxt'] ?? 0 ),
					'in_index' => (int) ( $row['InIndex'] ?? 0 ),
				);
			}
		}
		usort( $crawl, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		$out = array(
			'pro'          => $pro,
			'issues_total' => count( $all ),
			'issues'       => $pro ? $all : array_slice( $all, 0, self::FREE_ROWS ),
			'issues_error' => is_wp_error( $issues ) ? $issues->get_error_message() : '',
			'linked_pages' => is_array( $links ) ? (int) ( $links['TotalPages'] ?? count( $pages ) ) : null,
			'links'        => $pro ? $pages : array_slice( $pages, 0, self::FREE_ROWS ),
			'crawl'        => $crawl,
		);
		if ( $pro ) {
			$settings        = self::read( 'GetCrawlSettings', array( 'siteUrl' => $site ), $fresh );
			$out['settings'] = is_array( $settings ) ? array(
				'rate'      => array_map( 'intval', array_slice( array_pad( (array) ( $settings['CrawlRate'] ?? array() ), 24, 5 ), 0, 24 ) ),
				'boost'     => ! empty( $settings['CrawlBoostEnabled'] ),
				'can_boost' => ! empty( $settings['CrawlBoostAvailable'] ),
			) : null;
		}

		return $out;
	}

	/**
	 * The pages linking to one of the site's pages (Pro).
	 *
	 * @param string $url  The site's page.
	 * @param int    $page Bing's page number.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function links_to( string $url, int $page = 0 ) {
		$raw = self::read(
			'GetUrlLinks',
			array(
				'siteUrl' => Bing::row()['site'],
				'link'    => $url,
				'page'    => max( 0, $page ),
			)
		);
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$out = array();
		foreach ( (array) ( $raw['Details'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['Url'] ) ) {
				$out[] = array(
					'from'   => esc_url_raw( (string) $row['Url'] ),
					'anchor' => sanitize_text_field( (string) ( $row['AnchorText'] ?? '' ) ),
				);
			}
		}

		return array(
			'links' => $out,
			'pages' => (int) ( $raw['TotalPages'] ?? 0 ),
		);
	}

	/**
	 * Bing keyword research: weekly impressions on Bing for a search, in a market.
	 *
	 * @param string $phrase   The search.
	 * @param string $country  Two letters (`us`).
	 * @param string $language Language tag (`en-US`).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function keyword( string $phrase, string $country, string $language ) {
		$phrase = trim( sanitize_text_field( $phrase ) );
		if ( '' === $phrase ) {
			return new \WP_Error( 'zinn_seo_bing_keyword', __( 'Type a search to look up.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$raw = self::read(
			'GetKeywordStats',
			array(
				'q'        => $phrase,
				'country'  => strtolower( substr( preg_replace( '/[^a-z]/i', '', $country ), 0, 2 ) ),
				'language' => preg_replace( '/[^A-Za-z-]/', '', $language ),
			)
		);
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$weeks = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( is_array( $row ) ) {
				$weeks[] = array(
					'date'        => self::day( $row['Date'] ?? '' ),
					'impressions' => (int) ( $row['Impressions'] ?? 0 ),
					'broad'       => (int) ( $row['BroadImpressions'] ?? 0 ),
				);
			}
		}
		usort( $weeks, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

		return array(
			'phrase' => $phrase,
			'weeks'  => $weeks,
		);
	}

	/**
	 * Change how fast Bing crawls, hour by hour (Pro). Each hour 1 (slow) to 10 (fast).
	 *
	 * @param array<int, mixed> $rate  24 values.
	 * @param bool              $boost Crawl boost (where Bing offers it).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save_settings( array $rate, bool $boost ) {
		$rate = array_map( static fn( $v ) => max( 1, min( 10, (int) $v ) ), array_slice( array_pad( array_values( $rate ), 24, 5 ), 0, 24 ) );
		$res  = Bing::call(
			'POST',
			'SaveCrawlSettings',
			array(),
			array(
				'siteUrl'       => Bing::row()['site'],
				'crawlSettings' => array(
					'CrawlBoostAvailable' => $boost,
					'CrawlBoostEnabled'   => $boost,
					'CrawlRate'           => $rate,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::forget();

		return array(
			'rate'  => $rate,
			'boost' => $boost,
		);
	}

	/**
	 * Routes under `/search/bing/…`.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done   = true;
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$pro    = static fn(): bool => current_user_can( Capabilities::MANAGE ) && Plugin::is_pro();
		$answer = static fn( $r ) => is_wp_error( $r ) ? $r : rest_ensure_response( $r );
		register_rest_route(
			Plugin::REST_NS,
			'/search/bing/data',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => $answer( self::overview( (bool) $r['fresh'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/bing/keyword',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => $answer( self::keyword( (string) $r['q'], (string) $r['country'], (string) $r['language'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'q'        => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 100,
					),
					'country'  => array(
						'type'    => 'string',
						'default' => 'us',
					),
					'language' => array(
						'type'    => 'string',
						'default' => 'en-US',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/bing/links',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => $answer( self::links_to( esc_url_raw( (string) $r['url'] ), (int) $r['page'] ) ),
				'permission_callback' => $pro,
				'args'                => array(
					'url'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'page' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/search/bing/crawl-settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => $answer( self::save_settings( (array) $r['rate'], (bool) $r['boost'] ) ),
				'permission_callback' => $pro,
				'args'                => array(
					'rate'  => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 24,
						'maxItems' => 24,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 10,
						),
					),
					'boost' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}
}
