<?php
/**
 * The indexing console (zs-742): send any address to the search engines by hand, keep pages out
 * of automatic submission with wildcard rules, and see every answer per address, retry what
 * failed and download it all.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Indexing;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three engines, each through the part of the plugin that already speaks to it:
 *
 * - **IndexNow** (Bing, Yandex, Seznam, Naver …) through the IndexNow module's own queue; its
 *   answers arrive on `zinn_seo_indexnow_sent`.
 * - **Bing's URL Submission API** with the site's own Bing key (the Bing module), sent by a
 *   background job inside the day's quota Bing reports (`GetUrlSubmissionQuota`); what the quota
 *   cannot take today waits for tomorrow — nothing is dropped.
 * - **Google's Indexing API**, only where Google allows it (job and livestream pages, the Search
 *   Console module's own rule) — every other address is recorded as skipped, with the reason.
 *
 * ⛔ One row per address and engine in `{prefix}zseo_indexing_log`, indexed by time, state and
 * address, so a site sending 100,000 addresses reads a page of the log in one indexed query. Rows
 * older than {@see DAYS} days are deleted by AGE, never by count.
 */
final class Console {

	/** Table version. */
	public const DB_VERSION = 1;

	/** Option holding the installed table version. */
	public const DB_OPTION = 'zseo_indexing_db';

	/** Option: the wildcard rules, one per line. */
	public const EXCLUDE = 'zseo_indexing_exclude';

	/** Option: addresses waiting for Bing's URL API. Not autoloaded. */
	public const BING_QUEUE = 'zseo_indexing_bing_queue';

	/** The Bing sending job. */
	public const JOB = 'indexing-bing';

	/** Days a log row is kept. */
	public const DAYS = 90;

	/** Most addresses one request may carry. */
	public const MAX_SUBMIT = 10000;

	/** Most addresses one Bing call may carry (Bing's own batch limit). */
	public const BING_BATCH = 500;

	/** The engines. */
	public const ENGINES = array( 'indexnow', 'bing', 'google' );

	/** Row states. `retried`: failed, then sent again (its new answer is a new row). */
	public const STATES = array( 'sent', 'failed', 'skipped', 'retried' );

	/** The daily clean-up event. */
	public const PRUNE_HOOK = 'zseo_indexing_prune';

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_indexing_log';
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
url text NOT NULL,
url_hash char(40) NOT NULL,
engine varchar(10) NOT NULL,
state varchar(8) NOT NULL,
code smallint(5) unsigned NOT NULL DEFAULT 0,
message varchar(255) NOT NULL DEFAULT '',
source varchar(10) NOT NULL DEFAULT 'auto',
sent_at int(10) unsigned NOT NULL,
PRIMARY KEY  (id),
KEY sent_at (sent_at),
KEY state_sent (state,sent_at),
KEY engine_sent (engine,sent_at),
KEY url_hash (url_hash)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Register the Bing job and listen for answers from the other two engines.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		Jobs::register( self::JOB, static fn() => __( 'Sending addresses to Bing', 'zinn-seo' ), array( self::class, 'step' ) );
		add_action( 'zinn_seo_indexnow_sent', array( self::class, 'on_indexnow' ), 10, 3 );
		add_action( 'zinn_seo_google_indexing_sent', array( self::class, 'on_google' ), 10, 4 );
		add_filter( 'zinn_seo_indexing_allowed', array( self::class, 'allowed_filter' ), 10, 2 );
		add_action( self::PRUNE_HOOK, array( self::class, 'prune' ) );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	// ── exclusions ─────────────────────────────────────────────────────────────────────────

	/**
	 * The wildcard rules, cleaned: one per line, `*` matches anything.
	 *
	 * @return array<int, string>
	 */
	public static function rules(): array {
		$text = (string) get_option( self::EXCLUDE, '' );

		return self::clean_rules( $text );
	}

	/**
	 * Rules from text. Pure.
	 *
	 * @param string $text One rule per line.
	 * @return array<int, string>
	 */
	public static function clean_rules( string $text ): array {
		$out   = array();
		$lines = preg_split( '/\R/', $text );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) || strlen( $line ) > 300 ) {
				continue;
			}
			if ( ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Does an address match a rule? A rule that starts with `/` is matched against the address's
	 * path and query; any other rule against the whole address. `*` matches anything. Pure.
	 *
	 * @param string             $url   Address.
	 * @param array<int, string> $rules Rules.
	 * @return string The first rule it matches, or ''.
	 */
	public static function excluded_by( string $url, array $rules ): string {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$local = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
		foreach ( $rules as $rule ) {
			$subject = 0 === strpos( $rule, '/' ) ? $local : $url;
			$regex   = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';
			if ( 1 === preg_match( $regex, $subject ) ) {
				return $rule;
			}
		}

		return '';
	}

	/**
	 * `zinn_seo_indexing_allowed`: automatic submissions skip excluded addresses.
	 *
	 * @param bool   $allowed Allowed so far.
	 * @param string $url     Address.
	 * @return bool
	 */
	public static function allowed_filter( $allowed, $url ): bool {
		return (bool) $allowed && '' === self::excluded_by( (string) $url, self::rules() );
	}

	// ── the log ────────────────────────────────────────────────────────────────────────────

	/**
	 * Record answers, one row per address (inserted 500 at a time).
	 *
	 * @param string             $engine  Engine.
	 * @param array<int, string> $urls    Addresses.
	 * @param string             $state   sent|failed|skipped.
	 * @param int                $code    HTTP status (0 when not sent).
	 * @param string             $message What the engine or the plugin said.
	 * @param string             $source  auto|manual|retry.
	 * @return int Rows written.
	 */
	public static function record( string $engine, array $urls, string $state, int $code, string $message, string $source = 'auto' ): int {
		if ( ! in_array( $engine, self::ENGINES, true ) || ! in_array( $state, self::STATES, true ) || array() === $urls ) {
			return 0;
		}
		self::maybe_install();
		global $wpdb;
		$table   = self::table();
		$now     = time();
		$message = mb_substr( sanitize_text_field( $message ), 0, 255 );
		$source  = in_array( $source, array( 'auto', 'manual', 'retry' ), true ) ? $source : 'auto';
		$written = 0;
		foreach ( array_chunk( array_values( $urls ), 500 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $url ) {
				$url      = esc_url_raw( (string) $url );
				$values[] = '(%s,%s,%s,%s,%d,%s,%s,%d)';
				array_push( $args, $url, sha1( $url ), $engine, $state, max( 0, min( 65535, $code ) ), $message, $source, $now );
			}
			$sql = 'INSERT INTO %i (url,url_hash,engine,state,code,message,source,sent_at) VALUES ' . implode( ',', $values );
			array_unshift( $args, $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own log table; $sql is a fixed text with one fixed placeholder group per row (built above, no value in it), every value goes through prepare(): 500 rows in one INSERT instead of 500 queries.
			$written += (int) $wpdb->query( $wpdb->prepare( $sql, $args ) );
		}

		return $written;
	}

	/**
	 * Delete rows older than {@see DAYS} days.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune(): int {
		self::maybe_install();
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE sent_at < %d', $table, time() - self::DAYS * DAY_IN_SECONDS ) );
	}

	/**
	 * A page of the log, newest first.
	 *
	 * @param array<string, mixed> $filter `engine`, `state`, `search` (part of an address), `page`, `per`.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public static function page( array $filter ): array {
		self::maybe_install();
		global $wpdb;
		$table  = self::table();
		$engine = (string) ( $filter['engine'] ?? '' );
		$state  = (string) ( $filter['state'] ?? '' );
		$search = trim( (string) ( $filter['search'] ?? '' ) );
		$engine = in_array( $engine, self::ENGINES, true ) ? $engine : '';
		$state  = in_array( $state, self::STATES, true ) ? $state : '';
		$like   = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';
		$per    = max( 1, min( 200, (int) ( $filter['per'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $filter['page'] ?? 1 ) - 1 ) * $per;
		// One fixed statement: an empty filter matches every row ('' = '' is true), so no SQL is built.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,url,engine,state,code,message,source,sent_at FROM %i WHERE ( %s = '' OR engine = %s ) AND ( %s = '' OR state = %s ) AND ( %s = '' OR url LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $engine, $engine, $state, $state, $like, $like, $per, $offset ), ARRAY_A );
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR engine = %s ) AND ( %s = '' OR state = %s ) AND ( %s = '' OR url LIKE %s )", $table, $engine, $engine, $state, $state, $like, $like ) );
		$counts = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT state, COUNT(*) AS n FROM %i GROUP BY state', $table ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'   => array_map(
				static fn( $r ) => array(
					'id'      => (int) $r['id'],
					'url'     => (string) $r['url'],
					'engine'  => (string) $r['engine'],
					'state'   => (string) $r['state'],
					'code'    => (int) $r['code'],
					'message' => (string) $r['message'],
					'source'  => (string) $r['source'],
					'at'      => (int) $r['sent_at'],
				),
				$rows
			),
			'total'  => $total,
			'counts' => array_column( $counts, 'n', 'state' ),
		);
	}

	/**
	 * The whole log as CSV (newest first), streamed in pages of 1,000 rows.
	 *
	 * @param callable $write Receives each line.
	 * @return int Rows written.
	 */
	public static function export( callable $write ): int {
		self::maybe_install();
		global $wpdb;
		$table = self::table();
		$write( "address,engine,result,status,answer,how,when\n" );
		$n    = 0;
		$last = PHP_INT_MAX;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id,url,engine,state,code,message,source,sent_at FROM %i WHERE id < %d ORDER BY id DESC LIMIT 1000', $table, $last ), ARRAY_A );
			foreach ( $rows as $r ) {
				$write( self::csv_line( array( $r['url'], $r['engine'], $r['state'], $r['code'], $r['message'], $r['source'], gmdate( 'Y-m-d H:i:s', (int) $r['sent_at'] ) ) ) );
				$last = (int) $r['id'];
				++$n;
			}
			$more = 1000 === count( $rows );
		} while ( $more );

		return $n;
	}

	/**
	 * One CSV line (RFC 4180), with spreadsheet formula injection neutralised. Pure.
	 *
	 * @param array<int, mixed> $cells Cells.
	 * @return string
	 */
	public static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$text = (string) $cell;
			if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $text ) ) {
				$text = "'" . $text;
			}
			$out[] = preg_match( '/[",\r\n]/', $text ) ? '"' . str_replace( '"', '""', $text ) . '"' : $text;
		}

		return implode( ',', $out ) . "\n";
	}

	// ── answers from the other modules ─────────────────────────────────────────────────────

	/**
	 * IndexNow answered a batch.
	 *
	 * @param array<int, string> $urls    Addresses.
	 * @param int                $code    HTTP status.
	 * @param string             $message Error.
	 * @return void
	 */
	public static function on_indexnow( $urls, $code, $message ): void {
		$code = (int) $code;
		$ok   = $code >= 200 && $code < 300;
		self::record( 'indexnow', (array) $urls, $ok ? 'sent' : 'failed', $code, $ok ? '' : ( '' !== (string) $message ? (string) $message : self::status_text( $code ) ), self::$source );
	}

	/**
	 * Google's Indexing API answered one address.
	 *
	 * @param string $url  Address.
	 * @param string $kind URL_UPDATED|URL_DELETED.
	 * @param bool   $ok   Accepted.
	 * @param string $note Google's answer.
	 * @return void
	 */
	public static function on_google( $url, $kind, $ok, $note ): void {
		self::record( 'google', array( (string) $url ), $ok ? 'sent' : 'failed', $ok ? 200 : 0, (string) $note, self::$source );
	}

	/**
	 * Words for an HTTP status IndexNow answered.
	 *
	 * @param int $code Status.
	 * @return string
	 */
	public static function status_text( int $code ): string {
		switch ( $code ) {
			case 0:
				return __( 'The search engine could not be reached.', 'zinn-seo' );
			case 400:
				return __( 'The request was not valid.', 'zinn-seo' );
			case 403:
				return __( 'The key file was not found on the site.', 'zinn-seo' );
			case 422:
				return __( 'These addresses do not belong to this site.', 'zinn-seo' );
			case 429:
				return __( 'Too many requests: it will be tried again.', 'zinn-seo' );
			default:
				/* translators: %d: an HTTP status. */
				return sprintf( __( 'The search engine answered %d.', 'zinn-seo' ), $code );
		}
	}

	/**
	 * How the addresses being sent now were asked for (auto, manual, retry).
	 *
	 * @var string
	 */
	private static string $source = 'auto';

	// ── sending ────────────────────────────────────────────────────────────────────────────

	/**
	 * Send addresses to the chosen engines (manual submission or retry).
	 *
	 * @param array<int, string> $urls    Addresses.
	 * @param array<int, string> $engines Engines.
	 * @param string             $source  manual|retry.
	 * @return array<string, mixed> What happened, per engine.
	 */
	public static function submit( array $urls, array $engines, string $source = 'manual' ) {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$own  = array();
		$bad  = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( '' === $url ) {
				continue;
			}
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				$bad[] = $url;
				continue;
			}
			if ( ! in_array( $url, $own, true ) ) {
				$own[] = $url;
			}
		}
		if ( count( $own ) > self::MAX_SUBMIT ) {
			/* translators: %s: the most addresses one request may carry. */
			return new \WP_Error( 'zinn_seo_indexing_too_many', sprintf( __( 'Send up to %s addresses at a time.', 'zinn-seo' ), number_format_i18n( self::MAX_SUBMIT ) ), array( 'status' => 400 ) );
		}
		if ( array() === $own ) {
			return new \WP_Error( 'zinn_seo_indexing_nothing', __( 'None of these addresses is on this site.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$engines      = array_values( array_intersect( self::ENGINES, $engines ) );
		$out          = array(
			'addresses' => count( $own ),
			'other'     => count( $bad ),
		);
		self::$source = in_array( $source, array( 'manual', 'retry' ), true ) ? $source : 'manual';
		foreach ( $engines as $engine ) {
			$out[ $engine ] = self::send_to( $engine, $own );
		}
		self::$source = 'auto';

		return $out;
	}

	/**
	 * Hand addresses to one engine.
	 *
	 * @param string             $engine Engine.
	 * @param array<int, string> $urls   Addresses (this site's).
	 * @return array<string, mixed>
	 */
	private static function send_to( string $engine, array $urls ): array {
		if ( 'indexnow' === $engine ) {
			$class = '\ZinnDigital\ZinnSEO\Indexnow\Indexnow';
			// The owner's choice stands: IndexNow switched off sends nothing, by hand or not.
			if ( ! class_exists( $class ) || ! \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ) ) {
				self::record( 'indexnow', $urls, 'skipped', 0, __( 'IndexNow is switched off.', 'zinn-seo' ), self::$source );
				return array( 'skipped' => count( $urls ) );
			}
			$refusal = (string) $class::refusal();
			if ( '' !== $refusal ) {
				self::record( 'indexnow', $urls, 'skipped', 0, __( 'This site cannot send to IndexNow right now (a staging copy, or search engines are discouraged).', 'zinn-seo' ), self::$source );
				return array( 'skipped' => count( $urls ) );
			}
			// Manual sends go now (the answer is recorded by the IndexNow module's own send).
			$sent = 0;
			foreach ( $class::batches( $urls ) as $batch ) {
				$class::send( $batch );
				$sent += count( $batch );
			}
			return array( 'sent' => $sent );
		}
		if ( 'bing' === $engine ) {
			$bing = '\ZinnDigital\ZinnSEO\Bing\Bing';
			if ( ! class_exists( $bing ) || ! $bing::connected() ) {
				self::record( 'bing', $urls, 'skipped', 0, __( 'Connect Bing Webmaster Tools with your own key first.', 'zinn-seo' ), self::$source );
				return array( 'skipped' => count( $urls ) );
			}
			$queue = self::bing_queue();
			foreach ( $urls as $url ) {
				$queue[] = array(
					'url'    => $url,
					'source' => self::$source,
				);
			}
			update_option( self::BING_QUEUE, $queue, false );
			if ( 'pending' !== (string) Jobs::get( self::JOB )['status'] ) {
				Jobs::start( self::JOB, array() );
			}
			return array( 'queued' => count( $urls ) );
		}
		// Google: only where Google allows the Indexing API.
		$gsc     = '\ZinnDigital\ZinnSEO\Gsc\Indexing';
		$allowed = array();
		$refused = array();
		foreach ( $urls as $url ) {
			$post_id = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $url );
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( class_exists( $gsc ) && $post instanceof \WP_Post && $gsc::eligible( $post ) ) {
				$allowed[] = $url;
			} else {
				$refused[] = $url;
			}
		}
		if ( array() !== $refused ) {
			self::record( 'google', $refused, 'skipped', 0, __( 'Google takes direct requests only for job and livestream pages. Your sitemap tells Google about the rest.', 'zinn-seo' ), self::$source );
		}
		foreach ( $allowed as $url ) {
			$gsc::queue( $url, 'URL_UPDATED' );
		}

		return array(
			'queued'  => count( $allowed ),
			'skipped' => count( $refused ),
		);
	}

	/**
	 * The Bing queue.
	 *
	 * @return array<int, array{url: string, source: string}>
	 */
	public static function bing_queue(): array {
		$queue = get_option( self::BING_QUEUE, array() );

		return is_array( $queue ) ? array_values( array_filter( $queue, 'is_array' ) ) : array();
	}

	/**
	 * The Bing job: send the queue in batches inside today's quota; what is over the quota waits
	 * for tomorrow (the job pauses and the watchdog starts it again).
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Microtime to stop by.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$bing  = '\ZinnDigital\ZinnSEO\Bing\Bing';
		$queue = self::bing_queue();
		$done  = (int) ( $state['done'] ?? 0 );
		if ( array() === $queue ) {
			return array(
				'state'    => $state,
				'done'     => true,
				'progress' => array(
					'done'  => $done,
					'total' => $done,
				),
			);
		}
		if ( ! class_exists( $bing ) || ! $bing::connected() ) {
			update_option( self::BING_QUEUE, array(), false );
			foreach ( $queue as $item ) {
				self::record( 'bing', array( $item['url'] ), 'skipped', 0, __( 'Bing Webmaster Tools was disconnected.', 'zinn-seo' ), (string) $item['source'] );
			}
			return array(
				'state' => $state,
				'done'  => true,
			);
		}
		$site  = (string) $bing::row()['site'];
		$quota = $bing::call( 'GET', 'GetUrlSubmissionQuota', array( 'siteUrl' => $site ) );
		$left  = is_array( $quota ) ? (int) ( $quota['DailyQuota'] ?? 0 ) : self::BING_BATCH;
		if ( $left <= 0 ) {
			// Bing's allowance for today is used up: wait for tomorrow (UTC), keep everything.
			return array(
				'state' => $state,
				'done'  => false,
				'wait'  => self::until_tomorrow(),
			);
		}
		while ( array() !== $queue && $left > 0 && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$take   = array_splice( $queue, 0, min( self::BING_BATCH, $left ) );
			$urls   = array_column( $take, 'url' );
			$answer = $bing::call(
				'POST',
				'SubmitUrlBatch',
				array(),
				array(
					'siteUrl' => $site,
					'urlList' => $urls,
				)
			);
			if ( is_wp_error( $answer ) ) {
				$status = (int) ( $answer->get_error_data()['status'] ?? 0 );
				if ( 429 === $status ) {
					// Over the quota after all: put them back and wait.
					$queue = array_merge( $take, $queue );
					update_option( self::BING_QUEUE, $queue, false );
					return array(
						'state' => array( 'done' => $done ),
						'done'  => false,
						'wait'  => self::until_tomorrow(),
					);
				}
				foreach ( $take as $item ) {
					self::record( 'bing', array( $item['url'] ), 'failed', $status, $answer->get_error_message(), (string) $item['source'] );
				}
			} else {
				foreach ( $take as $item ) {
					self::record( 'bing', array( $item['url'] ), 'sent', 200, '', (string) $item['source'] );
				}
			}
			$left -= count( $take );
			$done += count( $take );
			update_option( self::BING_QUEUE, $queue, false );
		}

		return array(
			'state'    => array( 'done' => $done ),
			'done'     => array() === $queue,
			'progress' => array(
				'done'  => $done,
				'total' => $done + count( $queue ),
			),
		);
	}

	/**
	 * Seconds until the next UTC day, when Bing's daily allowance starts again.
	 *
	 * @return int
	 */
	public static function until_tomorrow(): int {
		return max( 60, DAY_IN_SECONDS - ( time() % DAY_IN_SECONDS ) + 60 );
	}

	/**
	 * Send failed rows again, each to the engine it failed on.
	 *
	 * @param array<int, int> $ids Row ids; empty = every failed row of the last {@see DAYS} days.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function retry( array $ids ) {
		self::maybe_install();
		global $wpdb;
		$table = self::table();
		$ids   = array_values( array_filter( array_map( 'intval', $ids ) ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; one %d per id.
		$rows = array() === $ids
			? (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,url,engine FROM %i WHERE state = 'failed'", $table ), ARRAY_A )
			: (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,url,engine FROM %i WHERE state = 'failed' AND id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $table ), $ids ) ), ARRAY_A );
		// phpcs:enable
		if ( array() === $rows ) {
			return new \WP_Error( 'zinn_seo_indexing_none_failed', __( 'There is nothing to send again.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$by = array();
		foreach ( $rows as $row ) {
			$by[ (string) $row['engine'] ][] = (string) $row['url'];
		}
		$out = array();
		foreach ( $by as $engine => $urls ) {
			$sent           = self::submit( array_values( array_unique( $urls ) ), array( $engine ), 'retry' );
			$out[ $engine ] = is_wp_error( $sent ) ? array( 'error' => $sent->get_error_message() ) : ( $sent[ $engine ] ?? array() );
		}
		// The retried rows are marked so they are not offered again; their new answers are new rows.
		$done = array_map( 'intval', array_column( $rows, 'id' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; one %d per id.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'retried' WHERE id IN (" . implode( ',', array_fill( 0, count( $done ), '%d' ) ) . ')', array_merge( array( $table ), $done ) ) );

		return $out;
	}
}
