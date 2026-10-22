<?php
/**
 * Social cache refresh (zs-744): when a page's sharing title, text or image changes, ask Facebook
 * to read it again, so the next share shows the new card — opt-in, batched, never from a
 * visitor's request. LinkedIn has no public way to ask; the screen links to its Post Inspector.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Social;

use ZinnDigital\ZinnSEO\AdminKit\Crypto;
use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External service: Facebook Graph API, `https://graph.facebook.com/` — the documented way to
 * refresh a shared link (`POST /?id=<url>&scrape=true`), with an app access token made from the
 * site owner's OWN Facebook app (`<app id>|<app secret>`). Only the page addresses are sent.
 * Nothing goes through Zinn®, and nothing is sent until the owner switches it on and pastes their
 * app's id and secret. The secret is kept sealed on this site (`Crypto`, the site's own salts).
 *
 * ⭐ Batched: up to {@see BATCH} addresses in one Graph batch request; a bulk edit of 10,000 pages
 * is 200 requests from a background job, never 10,000 — and never more than one per page per
 * {@see QUIET} seconds (a page saved five times in a minute is read once).
 */
final class Rescrape {

	/** Option: on, the app id and the sealed secret. */
	public const OPTION = 'zseo_social_rescrape';

	/** Option: addresses waiting (url => queued at). Not autoloaded. */
	public const QUEUE = 'zseo_social_rescrape_queue';

	/** Option: the last answers (newest first). Not autoloaded. */
	public const LOG = 'zseo_social_rescrape_log';

	/** The job. */
	public const JOB = 'social-rescrape';

	/** Graph batch limit. */
	public const BATCH = 50;

	/** Seconds a page waits after its last change before it is sent. */
	public const QUIET = 60;

	/** The Graph endpoint. */
	public const GRAPH = 'https://graph.facebook.com/';

	/** The fields that change what a shared link shows. */
	public const FIELDS = array( 'og_title', 'og_desc', 'og_image', 'title', 'desc' );

	/**
	 * Replaces the HTTP call in tests: `function ( string $url, array $args ): array|\WP_Error`.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/**
	 * Hook in (admin, REST, cron, CLI — nothing on the front end).
	 *
	 * @return void
	 */
	public static function hooks(): void {
		Jobs::register( self::JOB, static fn() => __( 'Refreshing shared links on Facebook', 'zinn-seo' ), array( self::class, 'step' ) );
		if ( ! self::on() ) {
			return;
		}
		add_action( 'zinn_seo_field_saved', array( self::class, 'field_saved' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'post_updated' ), 10, 3 );
	}

	/**
	 * The stored settings.
	 *
	 * @return array{on: bool, app_id: string, secret: string}
	 */
	public static function row(): array {
		$row = get_option( self::OPTION, array() );
		$row = is_array( $row ) ? $row : array();

		return array(
			'on'     => ! empty( $row['on'] ),
			'app_id' => (string) ( $row['app_id'] ?? '' ),
			'secret' => (string) ( $row['secret'] ?? '' ),
		);
	}

	/**
	 * Switched on with an app to ask with.
	 *
	 * @return bool
	 */
	public static function on(): bool {
		$row = self::row();

		return $row['on'] && '' !== $row['app_id'] && '' !== $row['secret'];
	}

	/**
	 * Save the settings. An empty secret keeps the stored one.
	 *
	 * @param bool   $on     Switched on.
	 * @param string $app_id Facebook app id (digits).
	 * @param string $secret App secret ('' = keep).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save( bool $on, string $app_id, string $secret ) {
		$app_id = preg_replace( '/\D/', '', $app_id );
		$secret = trim( $secret );
		if ( '' !== $secret && 1 !== preg_match( '/^[a-f0-9]{32}$/i', $secret ) ) {
			return new \WP_Error( 'zinn_seo_rescrape_secret', __( 'That does not look like a Facebook app secret (32 letters and numbers). Copy it again from your app’s Settings → Basic.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		$row = self::row();
		if ( $on && '' === (string) $app_id ) {
			return new \WP_Error( 'zinn_seo_rescrape_app', __( 'Paste your Facebook app ID first.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		update_option(
			self::OPTION,
			array(
				'on'     => $on,
				'app_id' => (string) $app_id,
				'secret' => '' !== $secret ? Crypto::seal( $secret ) : $row['secret'],
			),
			false
		);

		return self::view();
	}

	/**
	 * What the screen shows (never the secret).
	 *
	 * @return array<string, mixed>
	 */
	public static function view(): array {
		$row = self::row();
		$log = get_option( self::LOG, array() );

		return array(
			'on'         => $row['on'],
			'app_id'     => $row['app_id'],
			'has_secret' => '' !== $row['secret'],
			'waiting'    => count( self::queue() ),
			'log'        => is_array( $log ) ? array_slice( $log, 0, 50 ) : array(),
		);
	}

	/**
	 * The queue.
	 *
	 * @return array<string, int> url => queued at.
	 */
	public static function queue(): array {
		$queue = get_option( self::QUEUE, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Queue addresses (one row per address: saving twice keeps one).
	 *
	 * @param array<int, string> $urls Addresses.
	 * @param int|null           $now  Unix time (tests).
	 * @return int Queued.
	 */
	public static function add( array $urls, ?int $now = null ): int {
		$queue = self::queue();
		$n     = 0;
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' === $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				continue;
			}
			$queue[ $url ] = $now ?? time();
			++$n;
		}
		if ( $n ) {
			update_option( self::QUEUE, $queue, false );
			if ( 'pending' !== (string) Jobs::get( self::JOB )['status'] ) {
				Jobs::start( self::JOB, array() );
			}
		}

		return $n;
	}

	/**
	 * A sharing field of a published post changed.
	 *
	 * @param string $kind Object kind.
	 * @param int    $id   Object id.
	 * @param string $name Field.
	 * @return void
	 */
	public static function field_saved( $kind, $id, $name ): void {
		if ( 'post' !== $kind || ! in_array( (string) $name, self::FIELDS, true ) ) {
			return;
		}
		$post = get_post( (int) $id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			self::add( array( (string) get_permalink( $post ) ) );
		}
	}

	/**
	 * A published post's title or excerpt changed (both feed the shared card).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   After.
	 * @param \WP_Post $before  Before.
	 * @return void
	 */
	public static function post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'publish' !== $after->post_status || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}
		if ( $after->post_title !== $before->post_title || $after->post_excerpt !== $before->post_excerpt ) {
			self::add( array( (string) get_permalink( $after ) ) );
		}
	}

	/**
	 * The addresses ready to send (quiet for {@see QUIET} seconds). Pure.
	 *
	 * @param array<string, int> $queue Queue.
	 * @param int                $now   Unix time.
	 * @return array<int, string>
	 */
	public static function ready( array $queue, int $now ): array {
		return array_keys( array_filter( $queue, static fn( $at ) => (int) $at <= $now - self::QUIET ) );
	}

	/**
	 * The Graph batch body for some addresses. Pure.
	 *
	 * @param array<int, string> $urls Addresses (≤ {@see BATCH}).
	 * @return array<int, array<string, string>>
	 */
	public static function batch( array $urls ): array {
		return array_map(
			static fn( $url ) => array(
				'method'       => 'POST',
				'relative_url' => '?' . http_build_query(
					array(
						'id'     => $url,
						'scrape' => 'true',
					),
					'',
					'&',
					PHP_QUERY_RFC3986
				),
			),
			array_values( $urls )
		);
	}

	/**
	 * Read Facebook's batch answer: per address, accepted or why not. Pure.
	 *
	 * @param array<int, string> $urls   Addresses, in the order sent.
	 * @param mixed              $answer Decoded answer (one item per request, in order).
	 * @return array<int, array{url: string, ok: bool, note: string}>
	 */
	public static function results( array $urls, $answer ): array {
		$out = array();
		foreach ( array_values( $urls ) as $i => $url ) {
			$item = is_array( $answer ) ? ( $answer[ $i ] ?? null ) : null;
			$code = is_array( $item ) ? (int) ( $item['code'] ?? 0 ) : 0;
			$body = is_array( $item ) ? json_decode( (string) ( $item['body'] ?? '' ), true ) : null;
			$ok   = 200 === $code && is_array( $body ) && ! isset( $body['error'] );
			$note = '';
			if ( ! $ok ) {
				$note = is_array( $body ) && isset( $body['error']['message'] ) ? sanitize_text_field( (string) $body['error']['message'] ) : __( 'Facebook did not answer for this address.', 'zinn-seo' );
			} elseif ( isset( $body['title'] ) ) {
				$note = sanitize_text_field( (string) $body['title'] );
			}
			$out[] = array(
				'url'  => $url,
				'ok'   => $ok,
				'note' => mb_substr( $note, 0, 200 ),
			);
		}

		return $out;
	}

	/**
	 * Send one batch to Facebook.
	 *
	 * @param array<int, string> $urls Addresses.
	 * @return array<int, array{url: string, ok: bool, note: string}>|\WP_Error
	 */
	public static function send( array $urls ) {
		$row    = self::row();
		$secret = '' === $row['secret'] ? '' : (string) Crypto::open( $row['secret'] );
		if ( '' === $row['app_id'] || '' === $secret ) {
			return new \WP_Error( 'zinn_seo_rescrape_app', __( 'Paste your Facebook app ID and secret first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$args = array(
			'timeout'     => 30,
			'redirection' => 0,
			'body'        => array(
				'access_token'    => $row['app_id'] . '|' . $secret,
				'batch'           => (string) wp_json_encode( self::batch( $urls ) ),
				'include_headers' => 'false',
			),
		);
		$res  = null !== self::$transport ? call_user_func( self::$transport, self::GRAPH, $args ) : wp_safe_remote_post( self::GRAPH, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'zinn_seo_rescrape_unreachable', __( 'This site could not reach Facebook. It will try again.', 'zinn-seo' ), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$json   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $status ) {
			$message = is_array( $json ) && isset( $json['error']['message'] ) ? sanitize_text_field( (string) $json['error']['message'] ) : __( 'Facebook refused the request.', 'zinn-seo' );
			return new \WP_Error( 'zinn_seo_rescrape_refused', $message, array( 'status' => 400 === $status || 401 === $status ? 422 : 502 ) );
		}

		return self::results( $urls, $json );
	}

	/**
	 * The job: send what is ready, one batch at a time, until the deadline.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Stop by.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$done = (int) ( $state['done'] ?? 0 );
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$queue = self::queue();
			$ready = array_slice( self::ready( $queue, time() ), 0, self::BATCH );
			if ( array() === $ready ) {
				break;
			}
			$results = self::send( $ready );
			if ( is_wp_error( $results ) ) {
				$status = (int) ( $results->get_error_data()['status'] ?? 0 );
				self::log(
					array_map(
						static fn( $u ) => array(
							'url'  => $u,
							'ok'   => false,
							'note' => $results->get_error_message(),
						),
						$ready
					)
				);
				if ( 422 === $status ) {
					// The app itself was refused: nothing will work until the owner fixes it.
					foreach ( $ready as $url ) {
						unset( $queue[ $url ] );
					}
					update_option( self::QUEUE, $queue, false );
				}
				return array(
					'state' => array( 'done' => $done ),
					'done'  => 422 === $status,
					'pause' => 422 === $status ? '' : $results->get_error_message(),
				);
			}
			foreach ( $ready as $url ) {
				unset( $queue[ $url ] );
			}
			update_option( self::QUEUE, $queue, false );
			self::log( $results );
			$done += count( $ready );
		}
		$left = count( self::queue() );

		return array(
			'state'    => array( 'done' => $done ),
			'done'     => 0 === $left,
			'wait'     => $left ? self::QUIET : 0,
			'progress' => array(
				'done'  => $done,
				'total' => $done + $left,
			),
		);
	}

	/**
	 * Keep answers (newest first, the last 200).
	 *
	 * @param array<int, array<string, mixed>> $results Results.
	 * @return void
	 */
	private static function log( array $results ): void {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		foreach ( $results as $r ) {
			array_unshift(
				$log,
				array(
					'url'  => (string) $r['url'],
					'ok'   => (bool) $r['ok'],
					'note' => (string) $r['note'],
					'at'   => time(),
				)
			);
		}
		update_option( self::LOG, array_slice( $log, 0, 200 ), false );
	}

	/**
	 * `GET/POST /social/rescrape` (settings; the secret is never sent back) and
	 * `POST /social/rescrape/now` `{urls}` (refresh these pages now).
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done   = true;
		$manage = static fn(): bool => current_user_can( \ZinnDigital\ZinnSEO\Capabilities::MANAGE );
		register_rest_route(
			\ZinnDigital\ZinnSEO\Plugin::REST_NS,
			'/social/rescrape',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( self::view() ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$res = self::save( (bool) $r['on'], (string) $r['app_id'], (string) $r['secret'] );
						return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'on'     => array(
							'type'     => 'boolean',
							'required' => true,
						),
						'app_id' => array(
							'type'      => 'string',
							'maxLength' => 32,
							'default'   => '',
						),
						'secret' => array(
							'type'      => 'string',
							'maxLength' => 64,
							'default'   => '',
						),
					),
				),
			)
		);
		register_rest_route(
			\ZinnDigital\ZinnSEO\Plugin::REST_NS,
			'/social/rescrape/now',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					if ( ! self::on() ) {
						return new \WP_Error( 'zinn_seo_rescrape_off', __( 'Switch on social refresh and add your Facebook app first.', 'zinn-seo' ), array( 'status' => 409 ) );
					}
					$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
					$own  = array_values(
						array_filter(
							array_map( 'esc_url_raw', array_map( 'strval', (array) $r['urls'] ) ),
							static fn( $u ) => strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) === $home
						)
					);
					// A person asked: no quiet period.
					$n = self::add( $own, time() - self::QUIET );
					return rest_ensure_response( array( 'queued' => $n ) + self::view() );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'urls' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => 1000,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * LinkedIn's Post Inspector for an address (LinkedIn has no public refresh API). Pure.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function linkedin_inspector( string $url ): string {
		return 'https://www.linkedin.com/post-inspector/inspect/' . rawurlencode( $url );
	}
}
