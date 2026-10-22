<?php
/**
 * Google's Indexing API for job postings and livestreams — the one use Google allows (zs-081).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Gsc;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ Google: *"the Indexing API can only be used to crawl pages with either JobPosting or
 * BroadcastEvent embedded in a VideoObject"*. So it is sent ONLY for the content types the site
 * marks as job or livestream pages (`gsc_indexing_types`; WP Job Manager's `job_listing` is
 * offered by default when present) — never for ordinary posts, which use the sitemap and IndexNow.
 *
 * Publishing or updating such a page sends `URL_UPDATED`; unpublishing, trashing or a job
 * expiring sends `URL_DELETED`. Saving never waits for Google: the address goes into a small queue
 * (`zseo_gsc_indexing_queue`) that a background step drains, and Google's 200-a-day default is
 * respected by waiting until tomorrow rather than dropping anything.
 */
final class Indexing {

	/** Option: addresses waiting. */
	public const QUEUE = 'zseo_gsc_indexing_queue';

	/** Option: the last outcomes (newest first, 50). */
	public const LOG = 'zseo_gsc_indexing_log';

	/** Job id. */
	public const JOB = 'gsc-indexing';

	/**
	 * The content types whose pages are sent.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array_values( array_filter( array_map( 'strval', (array) Settings::get( 'gsc_indexing_types', array() ) ), 'post_type_exists' ) );
	}

	/**
	 * Is this post one Google allows the Indexing API for (by the site's choice)?
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function eligible( \WP_Post $post ): bool {
		$yes = in_array( $post->post_type, self::types(), true );

		/**
		 * Filters whether a post's address is sent to Google's Indexing API. Return true only for
		 * pages carrying JobPosting or a BroadcastEvent in a VideoObject (Google's rule).
		 *
		 * @param bool     $yes  The site's choice by content type.
		 * @param \WP_Post $post The post.
		 */
		return (bool) apply_filters( 'zinn_seo_indexing_api_eligible', $yes, $post );
	}

	/**
	 * A post changed status: queue the right notice.
	 *
	 * @param string   $to   New status.
	 * @param string   $from Old status.
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function on_status( $to, $from, $post ): void {
		if ( ! $post instanceof \WP_Post || ! (bool) Settings::get( 'gsc_indexing', false ) || ! self::eligible( $post ) ) {
			return;
		}
		$kind = self::kind( (string) $to, (string) $from );
		if ( '' === $kind ) {
			return;
		}
		$url = 'URL_DELETED' === $kind ? (string) get_post_meta( $post->ID, '_zseo_indexing_url', true ) : (string) get_permalink( $post );
		if ( 'URL_UPDATED' === $kind ) {
			update_post_meta( $post->ID, '_zseo_indexing_url', $url );
		}
		if ( '' !== $url ) {
			self::queue( $url, $kind );
		}
	}

	/**
	 * Which notice a status change calls for. Pure.
	 *
	 * @param string $to   New status.
	 * @param string $from Old status.
	 * @return string URL_UPDATED, URL_DELETED or ''.
	 */
	public static function kind( string $to, string $from ): string {
		if ( 'publish' === $to ) {
			return 'URL_UPDATED';
		}
		if ( 'publish' === $from ) {
			return 'URL_DELETED';
		}

		return '';
	}

	/**
	 * Add an address to the queue (the newest notice for an address wins) and start the drain.
	 *
	 * @param string $url  Address.
	 * @param string $kind URL_UPDATED|URL_DELETED.
	 * @return void
	 */
	public static function queue( string $url, string $kind ): void {
		$q         = (array) get_option( self::QUEUE, array() );
		$q[ $url ] = $kind;
		update_option( self::QUEUE, $q, false );
		$job = Jobs::get( self::JOB );
		if ( 'pending' !== ( $job['status'] ?? '' ) ) {
			Jobs::start( self::JOB, array( 'sent' => 0 ) );
		}
	}

	/**
	 * Drain step.
	 *
	 * @param array<string, mixed> $state    `sent`.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$sent = (int) ( $state['sent'] ?? 0 );
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$q = (array) get_option( self::QUEUE, array() );
			if ( array() === $q ) {
				return array(
					'state'    => array( 'sent' => $sent ),
					'done'     => true,
					'progress' => array(
						'done'  => $sent,
						'total' => $sent,
					),
				);
			}
			$url  = (string) array_key_first( $q );
			$kind = (string) $q[ $url ];
			$res  = Google::call(
				'POST',
				'/indexing',
				array(
					'url'  => $url,
					'type' => $kind,
				)
			);
			if ( is_wp_error( $res ) ) {
				$code = strtolower( (string) $res->get_error_code() );
				self::log( $url, $kind, false, $res->get_error_message() );
				// Google's quota (or a passing fault): wait and try the SAME address again.
				if ( false !== strpos( $code, 'busy' ) || false !== strpos( $code, 'unavailable' ) || false !== strpos( $code, 'quota' ) ) {
					return array(
						'state'    => array( 'sent' => $sent ),
						'done'     => false,
						'wait'     => false !== strpos( $code, 'quota' ) ? DAY_IN_SECONDS : 600,
						'progress' => array(
							'done'  => $sent,
							'total' => $sent + count( $q ),
						),
					);
				}
				// Switched off at Google, permission not given, not connected: keep the queue, pause
				// the job with the reason; it carries on when resumed.
				return array(
					'state'    => array( 'sent' => $sent ),
					'done'     => false,
					'pause'    => $res->get_error_message(),
					'progress' => array(
						'done'  => $sent,
						'total' => $sent + count( $q ),
					),
				);
			}
			unset( $q[ $url ] );
			update_option( self::QUEUE, $q, false );
			self::log( $url, $kind, true, (string) ( $res['notified_at'] ?? '' ) );
			++$sent;
		}

		return array(
			'state'    => array( 'sent' => $sent ),
			'done'     => false,
			'progress' => array(
				'done'  => $sent,
				'total' => $sent + count( (array) get_option( self::QUEUE, array() ) ),
			),
		);
	}

	/**
	 * Keep an outcome for the screen.
	 *
	 * @param string $url  Address.
	 * @param string $kind Notice.
	 * @param bool   $ok   Accepted by Google.
	 * @param string $note Google's time, or why not.
	 * @return void
	 */
	public static function log( string $url, string $kind, bool $ok, string $note ): void {
		$log = (array) get_option( self::LOG, array() );
		array_unshift(
			$log,
			array(
				'url'  => $url,
				'kind' => $kind,
				'ok'   => $ok,
				'note' => $note,
				'at'   => time(),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
		/**
		 * Google's Indexing API answered one address (the indexing console keeps every answer).
		 *
		 * @param string $url  Address.
		 * @param string $kind URL_UPDATED|URL_DELETED.
		 * @param bool   $ok   Accepted by Google.
		 * @param string $note Google's time, or why not.
		 */
		do_action( 'zinn_seo_google_indexing_sent', $url, $kind, $ok, $note );
	}

	/**
	 * What the screen shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function view(): array {
		$job = Jobs::get( self::JOB );

		return array(
			'on'        => (bool) Settings::get( 'gsc_indexing', false ),
			'types'     => self::types(),
			'available' => array_values(
				array_map(
					static fn( $t ) => array(
						'name'  => (string) $t->name,
						'label' => (string) $t->labels->singular_name,
					),
					get_post_types( array( 'public' => true ), 'objects' )
				)
			),
			'waiting'   => count( (array) get_option( self::QUEUE, array() ) ),
			'log'       => (array) get_option( self::LOG, array() ),
			'paused'    => 'paused' === ( $job['status'] ?? '' ) ? (string) ( $job['error'] ?? '' ) : '',
			'granted'   => Google::has( 'indexing' ),
		);
	}
}
