<?php
/**
 * IndexNow: tell Bing, Yandex, Seznam, Naver and the other IndexNow engines about a changed page
 * the moment it changes (zs-080), and never from a staging copy (zs-332).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Indexnow;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Robots\Staging;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The protocol (indexnow.org): the site proves it owns the addresses with a key file at
 * `/<key>.txt`, then POSTs up to 10,000 addresses per request to one engine, which shares them
 * with the others.
 *
 * - Addresses are queued (a non-autoloaded option) when a public, indexable page is published,
 *   changed in what a reader sees, or removed; a job sends the queue in batches, so a bulk import
 *   of 100,000 posts becomes ten requests, not 100,000. Nothing is sent from a visitor's request.
 * - A refusal is never silent: the screen shows the reason (staging copy, search engines
 *   discouraged, an address no engine can reach) and the log keeps each answer.
 * - 429 and 5xx answers keep the queue and retry later (one scheduled event, only while there is
 *   something to retry: 1, 5, 15, 60 minutes, then hourly).
 */
final class Indexnow {

	/** Queue option (not autoloaded). */
	public const QUEUE = 'zseo_indexnow_queue';

	/** Log option (not autoloaded): the last answers. */
	public const LOG = 'zseo_indexnow_log';

	/** Retry state option. */
	public const RETRY = 'zseo_indexnow_retry';

	/** The retry event. */
	public const RETRY_HOOK = 'zseo_indexnow_retry';

	/** The job. */
	public const JOB = 'indexnow';

	/**
	 * Pages queued by their OWN change (not asked for by a person): address => `{id, base}`,
	 * `base` the content the engines last had ('' unknown: always sent). Non-autoloaded.
	 */
	public const SEEN = 'zseo_indexnow_seen';

	/** Post meta: the hash of the content last sent to IndexNow (written on a real send only). */
	public const SENT_HASH = '_zseo_indexnow_hash';

	/** Most addresses one request may carry (indexnow.org). */
	public const BATCH = 10000;

	/** Engines: setting value => endpoint. */
	public const ENGINES = array(
		'indexnow' => 'https://api.indexnow.org/indexnow',
		'bing'     => 'https://www.bing.com/indexnow',
		'yandex'   => 'https://yandex.com/indexnow',
		'seznam'   => 'https://search.seznam.cz/indexnow',
		'naver'    => 'https://searchadvisor.naver.com/indexnow',
	);

	/**
	 * Pages changed in this request.
	 *
	 * @var array<int, string>
	 */
	private static array $pending = array();

	/**
	 * The pages behind this request's own changes: address => `{id, base}` (see SEEN).
	 *
	 * @var array<string, array{id: int, base: string}>
	 */
	private static array $changed = array();

	/**
	 * Q394: the address each published post being saved in this request had BEFORE the save
	 * (post id => address), so a post leaving "published" is sent at the address the engines know.
	 *
	 * @var array<int, string>
	 */
	private static array $was = array();

	/** Query keys that make an address WordPress's own, never a public one, under pretty permalinks. */
	public const QUERY_KEYS = array( 'p', 'page_id', 'post_type', 'preview' );

	/**
	 * Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * The key (created on first use: 32 random hexadecimal characters).
	 *
	 * @return string
	 */
	public static function key(): string {
		$key = (string) Settings::get( 'indexnow_key', '' );
		if ( '' !== $key ) {
			return $key;
		}
		$key = bin2hex( random_bytes( 16 ) );
		Settings::save( array( 'indexnow_key' => $key ), 'system' );

		return $key;
	}

	/**
	 * Why this site may not send, or '' when it may.
	 *
	 * @return string staging|discouraged|unreachable|''
	 */
	public static function refusal(): string {
		$reason = '';
		if ( class_exists( Staging::class ) && Staging::is_staging() ) {
			$reason = 'staging';
		} elseif ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$reason = 'discouraged';
		} elseif ( ! Staging::public_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$reason = 'unreachable';
		}

		/**
		 * Filters why this site does not send to IndexNow ('' = it sends). A site reached
		 * through a proxy under another name can allow its own host; tests point a local site
		 * at a mock engine.
		 *
		 * @param string $reason staging|discouraged|unreachable|''.
		 */
		return (string) apply_filters( 'zinn_seo_indexnow_refusal', $reason );
	}

	/**
	 * Hooks.
	 *
	 * @param string $context Request context.
	 * @return void
	 */
	public static function boot( string $context ): void {
		Jobs::register( self::JOB, static fn() => __( 'Send changed pages to IndexNow', 'zinn-seo' ), array( self::class, 'step' ) );
		add_action( self::RETRY_HOOK, array( self::class, 'retry' ) );
		add_action( 'pre_post_update', array( self::class, 'before_update' ), 20, 1 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 20, 3 );
		add_action( 'post_updated', array( self::class, 'on_update' ), 20, 3 );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 20, 1 );
		add_action( 'shutdown', array( self::class, 'flush' ) );
		if ( 'front' === $context ) {
			add_action( 'zinn_seo_loaded', array( self::class, 'maybe_serve_key' ) );
		}
	}

	/**
	 * Answer `/<key>.txt` with the key.
	 *
	 * @return void
	 */
	public static function maybe_serve_key(): void {
		$key = (string) Settings::get( 'indexnow_key', '' );
		if ( '' === $key || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home . '/' . $key . '.txt' !== $path ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}

	/**
	 * May this post's address be sent? Published, public, not password-protected, indexable,
	 * and of a type the owner chose (all public types by default).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function wanted( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}
		$types = (array) Settings::get( 'indexnow_types', array() );
		if ( array() !== $types && ! in_array( $post->post_type, $types, true ) ) {
			return false;
		}

		return ! isset( Resolver::robots( Context::for_post( $post ) )['noindex'] );
	}

	/**
	 * Published, or taken down.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || $new_status === $old_status || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			if ( self::wanted( $post ) ) {
				$url             = (string) get_permalink( $post );
				self::$pending[] = $url;
				// Newly public: always sent (whatever the engines had before is gone or unknown).
				self::$changed[ $url ] = self::$changed[ $url ] ?? array(
					'id'   => (int) $post->ID,
					'base' => '',
				);
			}
			return;
		}
		// Taken down: the engines learn it is gone when they fetch it (a 404 or 410).
		if ( is_post_type_viewable( $post->post_type ) ) {
			delete_post_meta( (int) $post->ID, self::SENT_HASH ); // What they had is gone now.
			// Q394: the address it had WHILE PUBLISHED (a draft's own is `?p=ID`, which no engine knows).
			// The bin renames the slug (`__trashed`) BEFORE `pre_post_update`, so a trashed post's
			// address is rebuilt from the slug WordPress kept for it (`_wp_desired_post_slug`).
			$url = 'trash' !== $new_status && isset( self::$was[ (int) $post->ID ] ) ? self::$was[ (int) $post->ID ] : self::published_url( $post );
			unset( self::$was[ (int) $post->ID ] );
			if ( '' !== $url ) {
				self::$pending[] = $url;
			}
		}
	}

	/**
	 * Before a post is saved: keep the address a published post has now (Q394), for the save
	 * that takes it down or moves it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function before_update( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			self::$was[ (int) $post->ID ] = (string) get_permalink( $post );
		}
	}

	/**
	 * A published post deleted outright (never through the trash): its address is gone.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			self::$pending[] = (string) get_permalink( $post );
		}
	}

	/**
	 * The address a post has (or had) while published, whatever its status now: its own slug (for
	 * a trashed post, the one WordPress kept before adding `__trashed`) under its parents and its
	 * type's base (Q394). '' when the slug is gone (a page put back to "pending" loses it): the
	 * address kept before the save (`before_update()`) is the one used then.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function published_url( \WP_Post $post ): string {
		$as              = clone $post;
		$as->post_status = 'publish';
		$desired         = 'trash' === $post->post_status ? (string) get_post_meta( (int) $post->ID, '_wp_desired_post_slug', true ) : '';
		$as->post_name   = '' !== $desired ? $desired : (string) $post->post_name;
		if ( '' === $as->post_name ) {
			return ''; // WordPress empties a pending page's slug: no address known, none sent.
		}

		return (string) get_permalink( $as );
	}

	/**
	 * Is this one of WordPress's own query addresses (`?p=`, `?page_id=`, `?post_type=`,
	 * `?preview=`) on a site with pretty permalinks? No engine knows such an address, so it is
	 * never sent (Q394). With plain permalinks it IS the public address. Pure, for the tests.
	 *
	 * @param string $url    Address.
	 * @param bool   $pretty The site uses pretty permalinks.
	 * @return bool
	 */
	public static function query_address( string $url, bool $pretty ): bool {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $pretty || '' === $query ) {
			return false;
		}
		parse_str( $query, $args );

		return array() !== array_intersect_key( $args, array_flip( self::QUERY_KEYS ) );
	}

	/**
	 * A published post changed in what a reader sees.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after.
	 * @param \WP_Post $before  Post before.
	 * @return void
	 */
	public static function on_update( $post_id, $after, $before ): void {
		unset( $post_id );
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'publish' !== $after->post_status || 'publish' !== $before->post_status ) {
			return;
		}
		// Q394: a published post moved (slug or parent): its old address is gone, tell the engines.
		$old = self::$was[ (int) $after->ID ] ?? (string) get_permalink( $before );
		unset( self::$was[ (int) $after->ID ] );
		if ( is_post_type_viewable( $after->post_type ) && '' !== $old && (string) get_permalink( $after ) !== $old ) {
			self::$pending[] = $old;
		}
		if ( \ZinnDigital\ZinnSEO\Sitemaps\Lastmod::content_changed( $before, $after ) && self::wanted( $after ) ) {
			$url             = (string) get_permalink( $after );
			self::$pending[] = $url;
			if ( ! isset( self::$changed[ $url ] ) ) {
				// Q349: what the engines last had — the content last SENT, or, for a page never
				// sent, the content before this change (what they read themselves).
				$sent                  = (string) get_post_meta( (int) $after->ID, self::SENT_HASH, true );
				self::$changed[ $url ] = array(
					'id'   => (int) $after->ID,
					'base' => '' !== $sent ? $sent : self::content_hash( $before ),
				);
			}
		}
	}

	/**
	 * The hash of what a reader sees of a page: the fields whose change queues it (Lastmod).
	 * Pure, for the tests.
	 *
	 * @param object $post Post (any object with the post fields).
	 * @return string
	 */
	public static function content_hash( $post ): string {
		$parts = array();
		foreach ( \ZinnDigital\ZinnSEO\Sitemaps\Lastmod::CONTENT_FIELDS as $field ) {
			$parts[] = (string) ( $post->$field ?? '' );
		}

		return md5( implode( "\0", $parts ) );
	}

	/**
	 * The pages queued by their own change (see SEEN).
	 *
	 * @return array<string, array{id: int, base: string}>
	 */
	public static function seen(): array {
		$seen = get_option( self::SEEN, array() );

		return is_array( $seen ) ? $seen : array();
	}

	/**
	 * Queue addresses (from the page events, the screen or an agent) and start sending.
	 *
	 * ⭐ An address queued by its page's own change (`$changed`) is sent only if, when the job
	 * sends, the page differs from what the engines last had (Q349: the autopilot's apply and
	 * then undo of the same content pinged IndexNow for pages that ended as they were). An
	 * address a person or an agent asks for is always sent.
	 *
	 * @param array<int, string>                          $urls    Addresses.
	 * @param array<string, array{id: int, base: string}> $changed The pages behind the page events' addresses.
	 * @return int How many were queued.
	 */
	public static function queue( array $urls, array $changed = array() ): int {
		$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$pretty = '' !== (string) get_option( 'permalink_structure', '' );
		$ok     = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			/**
			 * Filters whether an address may be sent automatically (the indexing console's
			 * wildcard rules say no to the pages the owner keeps out).
			 *
			 * @param bool   $allowed Allowed.
			 * @param string $url     Address.
			 */
			if ( '' !== $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host && ! self::query_address( $url, $pretty ) && apply_filters( 'zinn_seo_indexing_allowed', true, $url ) ) {
				$ok[] = $url;
			}
		}
		if ( array() === $ok ) {
			return 0;
		}
		$queue = self::pending_queue();
		$all   = array_values( array_unique( array_merge( $queue, $ok ) ) );
		update_option( self::QUEUE, $all, false );
		$seen  = self::seen();
		$moved = false;
		foreach ( $ok as $url ) {
			if ( isset( $changed[ $url ] ) ) {
				if ( ! isset( $seen[ $url ] ) ) {
					// The earliest change since the last send keeps its baseline.
					$seen[ $url ] = $changed[ $url ];
					$moved        = true;
				}
			} elseif ( isset( $seen[ $url ] ) ) {
				unset( $seen[ $url ] ); // Asked for (or taken down): sent, changed or not.
				$moved = true;
			}
		}
		if ( $moved ) {
			update_option( self::SEEN, $seen, false );
		}
		$job = Jobs::get( self::JOB );
		if ( 'pending' !== (string) $job['status'] && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			Jobs::start( self::JOB, array() );
		}

		return count( $ok );
	}

	/**
	 * The queue.
	 *
	 * @return array<int, string>
	 */
	public static function pending_queue(): array {
		$queue = get_option( self::QUEUE, array() );

		return is_array( $queue ) ? array_values( array_map( 'strval', $queue ) ) : array();
	}

	/**
	 * Store this request's addresses (one write per request).
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( array() === self::$pending ) {
			return;
		}
		$urls          = self::$pending;
		$changed       = self::$changed;
		self::$pending = array();
		self::$changed = array();
		if ( '' !== self::refusal() ) {
			return;
		}
		self::queue( $urls, $changed );
	}

	/**
	 * The batches a queue is sent in. Pure, for the tests.
	 *
	 * @param array<int, string> $urls Addresses.
	 * @param int                $size Batch size.
	 * @return array<int, array<int, string>>
	 */
	public static function batches( array $urls, int $size = self::BATCH ): array {
		return array_chunk( array_values( array_unique( $urls ) ), max( 1, $size ) );
	}

	/**
	 * Minutes to wait before retry N (1-based). Pure, for the tests.
	 *
	 * @param int $attempt Attempt.
	 * @return int
	 */
	public static function backoff( int $attempt ): int {
		$steps = array( 1, 5, 15, 60 );

		return $steps[ max( 0, min( $attempt, count( $steps ) ) - 1 ) ] ?? 60;
	}

	/**
	 * The request body for a batch.
	 *
	 * @param array<int, string> $urls Addresses.
	 * @return array<string, mixed>
	 */
	public static function payload( array $urls ): array {
		$key = self::key();

		return array(
			'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array_values( $urls ),
		);
	}

	/**
	 * One step: send one batch.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array{state: array<string, mixed>, done: bool, progress: array{done: int, total: int|null}}
	 */
	public static function step( array $state, float $deadline ): array {
		$queue   = self::pending_queue();
		$sent    = (int) ( $state['sent'] ?? 0 );
		$refusal = self::refusal();
		if ( array() === $queue || '' !== $refusal ) {
			if ( '' !== $refusal && array() !== $queue ) {
				self::log( count( $queue ), 0, $refusal );
				delete_option( self::QUEUE );
			}
			return array(
				'state'    => array(),
				'done'     => true,
				'progress' => array(
					'done'  => $sent,
					'total' => $sent,
				),
			);
		}
		$batch   = self::batches( $queue )[0];
		$seen    = self::seen();
		$checked = self::unchanged( $batch, $seen, $deadline - Jobs::reserve( 15.0 ) );
		$batch   = $checked['send'];
		if ( array() !== $checked['skip'] ) {
			// Back to what the engines last had: nothing to tell them (Q349).
			update_option( self::QUEUE, array_values( array_diff( self::pending_queue(), $checked['skip'] ) ), false );
			$seen = array_diff_key( $seen, array_flip( $checked['skip'] ) );
			update_option( self::SEEN, $seen, false );
		}
		if ( array() === $batch ) {
			$left = count( self::pending_queue() );
			return array(
				'state'    => array( 'sent' => $sent ),
				'done'     => 0 === $left,
				'progress' => array(
					'done'  => $sent,
					'total' => $sent + $left,
				),
			);
		}
		$code = self::send( $batch );
		if ( $code >= 200 && $code < 300 ) {
			update_option( self::QUEUE, array_values( array_diff( self::pending_queue(), $batch ) ), false );
			delete_option( self::RETRY );
			$sent += count( $batch );
			self::remember_sent( $checked['hash'] );
			$seen = array_diff_key( self::seen(), array_flip( $batch ) );
			update_option( self::SEEN, $seen, false );
		} elseif ( 429 === $code || $code >= 500 || 0 === $code ) {
			$retry = get_option( self::RETRY, array() );
			$retry = is_array( $retry ) ? $retry : array();
			$n     = (int) ( $retry['attempt'] ?? 0 ) + 1;
			update_option(
				self::RETRY,
				array(
					'attempt' => $n,
					'at'      => time() + 60 * self::backoff( $n ),
				),
				false
			);
			wp_schedule_single_event( time() + 60 * self::backoff( $n ), self::RETRY_HOOK );
			return array(
				'state'    => array( 'sent' => $sent ),
				'done'     => true,
				'progress' => array(
					'done'  => $sent,
					'total' => $sent + count( $queue ),
				),
			);
		} else {
			// 400 (bad request), 403 (key not valid), 422 (addresses not on this host): sending
			// the same batch again cannot succeed, so it is dropped and the reason logged.
			update_option( self::QUEUE, array_values( array_diff( self::pending_queue(), $batch ) ), false );
			update_option( self::SEEN, array_diff_key( self::seen(), array_flip( $batch ) ), false );
		}
		$left = count( self::pending_queue() );

		return array(
			'state'    => array( 'sent' => $sent ),
			'done'     => 0 === $left,
			'progress' => array(
				'done'  => $sent,
				'total' => $sent + $left,
			),
		);
	}

	/**
	 * Split a batch: the addresses whose page, queued by its own change, is now exactly what the
	 * engines last had (`skip`), and the ones to send. A page is read as bare columns, a hundred
	 * at a time (no post objects kept for 10,000 addresses), until `$until`; the addresses not
	 * reached by then wait for the next step. `hash`: post id => its content hash now, for the
	 * pages in `send`.
	 *
	 * @param array<int, string>                          $batch Addresses.
	 * @param array<string, array{id: int, base: string}> $seen  See SEEN.
	 * @param float                                       $until Microtime to stop reading at.
	 * @return array{send: array<int, string>, skip: array<int, string>, hash: array<int, string>}
	 */
	public static function unchanged( array $batch, array $seen, float $until ): array {
		global $wpdb;
		$out    = array(
			'send' => array(),
			'skip' => array(),
			'hash' => array(),
		);
		$fields = implode( ', ', \ZinnDigital\ZinnSEO\Sitemaps\Lastmod::CONTENT_FIELDS );
		foreach ( array_chunk( $batch, 100 ) as $n => $chunk ) {
			if ( $n > 0 && microtime( true ) >= $until ) {
				break; // The rest waits for the next step, still queued.
			}
			$ids = array();
			foreach ( $chunk as $url ) {
				if ( isset( $seen[ $url ]['id'] ) ) {
					$ids[] = (int) $seen[ $url ]['id'];
				}
			}
			$rows = array();
			if ( array() !== $ids ) {
				$in = implode( ',', array_map( 'intval', $ids ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids and fixed column names; bare columns, so no post object is kept per address.
				foreach ( (array) $wpdb->get_results( "SELECT ID, {$fields} FROM {$wpdb->posts} WHERE ID IN ({$in})" ) as $row ) {
					$rows[ (int) $row->ID ] = $row;
				}
			}
			foreach ( $chunk as $url ) {
				$id  = (int) ( $seen[ $url ]['id'] ?? 0 );
				$row = $rows[ $id ] ?? null;
				if ( null === $row ) {
					$out['send'][] = $url;
					continue;
				}
				$hash = self::content_hash( $row );
				if ( '' !== (string) $seen[ $url ]['base'] && hash_equals( (string) $seen[ $url ]['base'], $hash ) ) {
					$out['skip'][] = $url;
					continue;
				}
				$out['send'][]      = $url;
				$out['hash'][ $id ] = $hash;
			}
		}

		return $out;
	}

	/**
	 * Keep what was just sent as what the engines last had: one DELETE and one INSERT per hundred
	 * pages (never a meta write per address), and the pages' meta caches cleared.
	 *
	 * @param array<int, string> $hashes Post id => content hash.
	 * @return void
	 */
	private static function remember_sent( array $hashes ): void {
		global $wpdb;
		foreach ( array_chunk( $hashes, 100, true ) as $chunk ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $chunk ) ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer ids; each row of values prepared above it.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$ids})", self::SENT_HASH ) );
			$values = array();
			foreach ( $chunk as $id => $hash ) {
				$values[] = $wpdb->prepare( '(%d, %s, %s)', (int) $id, self::SENT_HASH, (string) $hash );
			}
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( array_keys( $chunk ) as $id ) {
				wp_cache_delete( (int) $id, 'post_meta' );
			}
		}
	}

	/**
	 * The retry event.
	 *
	 * @return void
	 */
	public static function retry(): void {
		if ( array() !== self::pending_queue() ) {
			Jobs::start( self::JOB, array() );
		}
	}

	/**
	 * POST one batch to the chosen engine; returns the HTTP status (0 when unreachable).
	 *
	 * @param array<int, string> $urls Addresses.
	 * @return int
	 */
	public static function send( array $urls ): int {
		$engine   = (string) Settings::get( 'indexnow_engine', 'indexnow' );
		$endpoint = self::ENGINES[ $engine ] ?? self::ENGINES['indexnow'];
		/**
		 * Filters the IndexNow endpoint (tests point it at a local mock).
		 *
		 * @param string $endpoint Address.
		 */
		$endpoint = (string) apply_filters( 'zinn_seo_indexnow_endpoint', $endpoint );
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => (string) wp_json_encode( self::payload( $urls ), JSON_UNESCAPED_SLASHES ),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$message  = is_wp_error( $response ) ? $response->get_error_message() : '';
		self::log( count( $urls ), $code, $message, $urls );

		return $code;
	}

	/**
	 * Keep an answer (the last 50).
	 *
	 * @param int                $count   Addresses.
	 * @param int                $code    HTTP status (0: not sent).
	 * @param string             $message Error or refusal.
	 * @param array<int, string> $urls    Addresses (the first five are kept as a sample).
	 * @return void
	 */
	private static function log( int $count, int $code, string $message, array $urls = array() ): void {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'at'      => time(),
				'count'   => $count,
				'code'    => $code,
				'message' => $message,
				'sample'  => array_slice( $urls, 0, 5 ),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
		/**
		 * IndexNow answered a batch (the indexing console keeps every address's answer).
		 *
		 * @param array<int, string> $urls    Addresses.
		 * @param int                $code    HTTP status (0: not sent).
		 * @param string             $message Error or refusal.
		 */
		do_action( 'zinn_seo_indexnow_sent', $urls, $code, $message );
	}

	/**
	 * The log.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history(): array {
		$log = get_option( self::LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Deep links that ask a search engine to look at one address now (zs-082): Google Search
	 * Console's URL inspection (where "Request indexing" is) and Bing Webmaster Tools' URL
	 * inspection. Links only: nothing automates either tool.
	 *
	 * @param string $url Address.
	 * @return array{google: string, bing: string}
	 */
	public static function inspect_links( string $url ): array {
		$property = (string) Settings::get( 'gsc_property', '' );
		if ( '' === $property ) {
			$property = home_url( '/' );
		}

		return array(
			'google' => add_query_arg(
				array(
					'resource_id' => rawurlencode( $property ),
					'id'          => rawurlencode( $url ),
				),
				'https://search.google.com/search-console/inspect'
			),
			'bing'   => add_query_arg(
				array(
					'siteUrl' => rawurlencode( home_url( '/' ) ),
					'url'     => rawurlencode( $url ),
				),
				'https://www.bing.com/webmasters/urlinspection'
			),
		);
	}
}
