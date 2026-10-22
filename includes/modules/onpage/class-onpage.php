<?php
/**
 * The full-site audit Zinn® runs on credit (DataForSEO's On-Page crawl), merged into Fix it, so
 * a site too big or too locked-down to crawl from inside WordPress gets the same one inbox
 * (zs-652).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Onpage;

use ZinnDigital\ZinnSEO\Connect\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The audit itself is the credit check of the Checks screen (`tools` module, kind `onpage`,
 * billed per page in `audit_page` on the one Zinn® credit meter). This module copies the LATEST
 * finished audit's findings into an option that is not autoloaded — once a day by the scheduled
 * event, and when a person opens Fix it and the copy is a day old (a single background event, so
 * the inbox never waits on Zinn®) — and offers each finding to Fix it as an issue with the pages
 * it touches and a link to the audit's own detail, where each page has its Fix link.
 */
final class Onpage {

	/** The kept findings. Not autoloaded. */
	public const OPTION = 'zseo_onpage_findings';

	/** The refresh event. */
	public const EVENT = 'zseo_onpage_refresh';

	/** Seconds the copy is kept before it is refreshed. */
	public const TTL = DAY_IN_SECONDS;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_filter( 'zinn_seo_fixit_sources', array( self::class, 'source' ) );
		add_action( self::EVENT, array( self::class, 'refresh' ) );
		add_action( 'zinn_seo_disconnected', array( self::class, 'forget' ) );
	}

	/**
	 * The words for a finding code (the same words as the audit screen).
	 *
	 * @return array<string, string>
	 */
	public static function titles(): array {
		return array(
			'broken_links'                  => __( 'Links that lead nowhere', 'zinn-seo' ),
			'broken_resources'              => __( 'Images or files that do not load', 'zinn-seo' ),
			'links_relation_conflict'       => __( 'Links marked both followed and nofollow', 'zinn-seo' ),
			'duplicate_title_tag'           => __( 'Pages sharing the same title', 'zinn-seo' ),
			'no_title'                      => __( 'Pages with no title', 'zinn-seo' ),
			'no_description'                => __( 'Pages with no description', 'zinn-seo' ),
			'duplicate_description'         => __( 'Pages sharing the same description', 'zinn-seo' ),
			'duplicate_content'             => __( 'Pages with the same content', 'zinn-seo' ),
			'no_h1_tag'                     => __( 'Pages with no main heading', 'zinn-seo' ),
			'title_too_long'                => __( 'Titles too long to show in full', 'zinn-seo' ),
			'title_too_short'               => __( 'Titles too short to say much', 'zinn-seo' ),
			'small_page_size'               => __( 'Pages with almost nothing on them', 'zinn-seo' ),
			'low_content_rate'              => __( 'Pages with little text for their size', 'zinn-seo' ),
			'no_image_alt'                  => __( 'Images with no description', 'zinn-seo' ),
			'no_favicon'                    => __( 'No site icon', 'zinn-seo' ),
			'canonical_to_broken'           => __( 'Canonical addresses that point at a missing page', 'zinn-seo' ),
			'canonical_chain'               => __( 'Canonical addresses that pass through another', 'zinn-seo' ),
			'is_redirect'                   => __( 'Addresses that redirect', 'zinn-seo' ),
			'is_4xx_code'                   => __( 'Pages that cannot be found', 'zinn-seo' ),
			'is_5xx_code'                   => __( 'Pages that fail with a server error', 'zinn-seo' ),
			'is_orphan_page'                => __( 'Pages nothing links to', 'zinn-seo' ),
			'no_content_encoding'           => __( 'Pages sent without compression', 'zinn-seo' ),
			'https_to_http_links'           => __( 'Secure pages linking to insecure ones', 'zinn-seo' ),
			'non_indexable'                 => __( 'Pages Google is told not to index', 'zinn-seo' ),
			'irrelevant_description'        => __( 'Descriptions that do not match the page', 'zinn-seo' ),
			'irrelevant_title'              => __( 'Titles that do not match the page', 'zinn-seo' ),
			'deprecated_html_tags'          => __( 'Outdated HTML', 'zinn-seo' ),
			'has_render_blocking_resources' => __( 'Files that delay the page showing', 'zinn-seo' ),
			'redirect_chain'                => __( 'Redirects that pass through several steps', 'zinn-seo' ),
			'sitemap_url_not_indexable'     => __( 'Sitemap lists pages Google is told not to index', 'zinn-seo' ),
		);
	}

	/**
	 * Forget the copy.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Read the latest finished audit from Zinn® and keep its findings.
	 *
	 * @return array<string, mixed>|null The kept copy, or null when there is none to keep.
	 */
	public static function refresh(): ?array {
		$tools = '\ZinnDigital\ZinnSEO\Tools\Tools';
		if ( ! class_exists( $tools ) || ! Connection::connected() ) {
			return null;
		}
		$list = $tools::results( 'audit' );
		if ( is_wp_error( $list ) ) {
			return null;
		}
		$latest = null;
		foreach ( (array) ( $list['audits'] ?? array() ) as $audit ) {
			if ( in_array( (string) ( $audit['status'] ?? '' ), array( 'complete', 'partial' ), true ) ) {
				$latest = $audit;
				break;
			}
		}
		$copy = array(
			'at'       => time(),
			'audit'    => '',
			'finished' => '',
			'findings' => array(),
		);
		if ( null !== $latest ) {
			$full = $tools::audit( (string) ( $latest['id'] ?? '' ) );
			if ( ! is_wp_error( $full ) ) {
				$copy['audit']    = (string) ( $full['id'] ?? '' );
				$copy['finished'] = (string) ( $full['finished_at'] ?? $full['created_at'] ?? '' );
				$copy['findings'] = self::clean( (array) ( $full['findings'] ?? array() ) );
			}
		}
		update_option( self::OPTION, $copy, false );

		return $copy;
	}

	/**
	 * The findings worth an issue: failed, with a code this plugin can name. Pure.
	 *
	 * @param array<int, mixed> $findings The audit's findings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function clean( array $findings ): array {
		$out = array();
		foreach ( $findings as $f ) {
			if ( ! is_array( $f ) || ! empty( $f['passed'] ) ) {
				continue;
			}
			$code = sanitize_key( (string) ( $f['code'] ?? '' ) );
			if ( '' === $code || 'onpage_clean' === $code ) {
				continue;
			}
			$severity = (string) ( $f['severity'] ?? 'info' );
			$out[]    = array(
				'code'     => $code,
				'severity' => in_array( $severity, array( 'critical', 'warn', 'info' ), true ) ? $severity : 'info',
				'pages'    => max( 1, (int) ( $f['detail']['pages'] ?? 1 ) ),
			);
		}

		return $out;
	}

	/**
	 * Fix it's source: the kept findings, each an issue. A copy older than a day is refreshed in
	 * the background (never while the inbox waits).
	 *
	 * @param mixed $sources Sources.
	 * @return array<string, callable>
	 */
	public static function source( $sources ): array {
		$sources           = (array) $sources;
		$sources['onpage'] = static function (): array {
			$copy = get_option( self::OPTION );
			if ( ( ! is_array( $copy ) || (int) ( $copy['at'] ?? 0 ) < time() - self::TTL ) && ! wp_next_scheduled( self::EVENT ) && Connection::connected() ) {
				wp_schedule_single_event( time() + 5, self::EVENT );
			}
			return is_array( $copy ) ? self::issues( (array) ( $copy['findings'] ?? array() ) ) : array();
		};

		return $sources;
	}

	/**
	 * Fix it issues from kept findings. Pure (apart from translation).
	 *
	 * @param array<int, array<string, mixed>> $findings Kept findings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function issues( array $findings ): array {
		$titles = self::titles();
		$out    = array();
		foreach ( $findings as $f ) {
			$code = (string) $f['code'];
			if ( ! isset( $titles[ $code ] ) ) {
				continue;
			}
			$out[] = array(
				'id'       => 'onpage-' . $code,
				'area'     => 'site',
				'priority' => 'critical' === $f['severity'] ? 80 : ( 'warn' === $f['severity'] ? 60 : 35 ),
				'title'    => $titles[ $code ],
				/* translators: %s: a number of pages. */
				'detail'   => sprintf( _n( 'Found by the full-site audit on %s page.', 'Found by the full-site audit on %s pages.', (int) $f['pages'], 'zinn-seo' ), number_format_i18n( (int) $f['pages'] ) ),
				'count'    => (int) $f['pages'],
				'minutes'  => min( 240, 3 * (int) $f['pages'] ),
				'fix'      => array(
					'kind'  => 'link',
					'url'   => admin_url( 'admin.php?page=zinn-seo&view=checks#/audit' ),
					'label' => __( 'See the pages', 'zinn-seo' ),
				),
			);
		}

		return $out;
	}
}
