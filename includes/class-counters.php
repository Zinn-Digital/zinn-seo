<?php
/**
 * Aggregated counters: the one table every "how often" feature shares (architecture §1.5).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own counters table; every value is a placeholder.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ COUNTERS, NEVER A ROW PER HIT (catalogue A4.4 — the Redirection plugin's per-hit 404 log is
 * the thing that takes big sites down). `{prefix}zseo_counters` holds ONE row per (kind, key, day):
 *
 * | kind      | key                    | day                     | who writes it                  |
 * |-----------|------------------------|-------------------------|--------------------------------|
 * | `bot`     | an AI crawler's id     | the day of the visits   | Pro crawler log (zs-066)       |
 * | `404`     | the missing address    | `ALL_TIME` (one row)    | 404 monitor (zs-093)           |
 * | `search`  | a site-search phrase   | `ALL_TIME`              | site-search insights (zs-414)  |
 * | `search0` | a phrase that found 0  | `ALL_TIME`              | site-search insights (zs-414)  |
 *
 * ⭐ HARD CEILING per kind (CEILING rows, filter `zinn_seo_counter_ceiling`): when a NEW row takes a
 * kind over it, the least-recently-seen rows of that kind are removed in the same request. The cost
 * of that check is paid only when a row is created (an increment of an existing row is one upsert),
 * and it is an indexed count (`KEY kind_seen`). A flood of random addresses therefore churns the
 * table in place; it never grows it.
 */
final class Counters {

	/** Table name without the prefix. */
	public const TABLE = 'zseo_counters';

	/** Schema version (option `zseo_counters_v`). Version 2 added `KEY kind_seen`. */
	public const VERSION = 2;

	/** Rows kept per kind. */
	public const CEILING = 10000;

	/** The `day` of a kind kept as one all-time row per key. */
	public const ALL_TIME = '1970-01-01';

	/**
	 * The table's full name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Is the table there at the current version (without creating it)?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return (int) get_option( 'zseo_counters_v', 0 ) >= self::VERSION;
	}

	/**
	 * Create or upgrade the table on first use.
	 *
	 * @return bool Whether it exists.
	 */
	public static function ensure_table(): bool {
		if ( self::ready() ) {
			return true;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				kind varchar(16) NOT NULL,
				key_hash char(32) NOT NULL,
				item_key varchar(191) NOT NULL,
				day date NOT NULL,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				sample_ref varchar(191) NOT NULL DEFAULT '',
				PRIMARY KEY  (kind,key_hash,day),
				KEY day (day),
				KEY kind_seen (kind,last_seen)
			) {$charset};"
		);
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( $exists ) {
			update_option( 'zseo_counters_v', self::VERSION, false );
		}

		return $exists;
	}

	/**
	 * The ceiling of a kind.
	 *
	 * @param string $kind Kind.
	 * @return int
	 */
	public static function ceiling( string $kind ): int {
		/**
		 * Filters how many rows one kind of counter keeps (least recently seen are removed first).
		 *
		 * @param int    $ceiling Rows (default 10,000).
		 * @param string $kind    Kind: 404, search, search0, bot.
		 */
		return max( 10, (int) apply_filters( 'zinn_seo_counter_ceiling', self::CEILING, $kind ) );
	}

	/**
	 * Add one to a counter (creating it), then hold the kind's ceiling.
	 *
	 * @param string $kind   Kind.
	 * @param string $key    Key (cut to 191 characters).
	 * @param string $sample A sample to keep with it (referrer, result count, path…), cut to 191.
	 * @param string $day    `Y-m-d`, or ALL_TIME.
	 * @return bool Whether a NEW row was created.
	 */
	public static function hit( string $kind, string $key, string $sample = '', string $day = self::ALL_TIME ): bool {
		if ( '' === $key || ! self::ensure_table() ) {
			return false;
		}
		global $wpdb;
		$key  = self::cut( $key );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (kind, key_hash, item_key, day, hits, first_seen, last_seen, sample_ref) VALUES (%s, %s, %s, %s, 1, %s, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), sample_ref = IF(VALUES(sample_ref) = \'\', sample_ref, VALUES(sample_ref))',
				self::table(),
				$kind,
				md5( $key ),
				$key,
				$day,
				$now,
				$now,
				self::cut( $sample )
			)
		);
		// MySQL reports 1 affected row for an insert and 2 for an update of an existing row.
		$created = 1 === (int) $done;
		if ( $created ) {
			self::hold_ceiling( $kind );
		}

		return $created;
	}

	/**
	 * Remove the least recently seen rows of a kind above its ceiling.
	 *
	 * @param string $kind Kind.
	 * @return int Rows removed.
	 */
	public static function hold_ceiling( string $kind ): int {
		global $wpdb;
		$ceiling = self::ceiling( $kind );
		$count   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE kind = %s', self::table(), $kind ) );
		if ( $count <= $ceiling ) {
			return 0;
		}

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE kind = %s ORDER BY last_seen ASC, hits ASC LIMIT %d', self::table(), $kind, $count - $ceiling ) );
	}

	/**
	 * Rows of a kind, for screens: sorted, keyset-paged.
	 *
	 * @param string $kind   Kind.
	 * @param string $order  hits|last_seen|first_seen.
	 * @param int    $per    Rows.
	 * @param string $after  Keyset cursor from the previous page (`<value>|<key_hash>`), '' for the first.
	 * @param string $search Only keys containing this.
	 * @return array{items: array<int, array<string, mixed>>, next: string, total: int}
	 */
	public static function rows( string $kind, string $order = 'hits', int $per = 50, string $after = '', string $search = '' ): array {
		if ( ! self::ready() ) {
			return array(
				'items' => array(),
				'next'  => '',
				'total' => 0,
			);
		}
		global $wpdb;
		$col   = in_array( $order, array( 'hits', 'last_seen', 'first_seen' ), true ) ? $order : 'hits';
		$where = $wpdb->prepare( 'kind = %s', $kind );
		if ( '' !== $search ) {
			$where .= $wpdb->prepare( ' AND item_key LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ', self::table() ) . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared above.
		$page  = $where;
		if ( '' !== $after && false !== strpos( $after, '|' ) ) {
			list( $value, $hash ) = explode( '|', $after, 2 );
			$page                .= 'hits' === $col
				? $wpdb->prepare( ' AND (hits < %d OR (hits = %d AND key_hash > %s))', (int) $value, (int) $value, $hash )
				: $wpdb->prepare( " AND ({$col} < %s OR ({$col} = %s AND key_hash > %s))", $value, $value, $hash ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $col is one of three literals.
		}
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT key_hash, item_key, hits, first_seen, last_seen, sample_ref FROM %i WHERE ', self::table() ) . $page . $wpdb->prepare( " ORDER BY {$col} DESC, key_hash ASC LIMIT %d", $per + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared pieces; $col is a literal.
		$more  = count( $rows ) > $per;
		$rows  = array_slice( $rows, 0, $per );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'hash'       => (string) $row->key_hash,
				'key'        => (string) $row->item_key,
				'hits'       => (int) $row->hits,
				'first_seen' => (string) $row->first_seen,
				'last_seen'  => (string) $row->last_seen,
				'sample'     => (string) $row->sample_ref,
			);
		}
		$last = end( $rows );

		return array(
			'items' => $items,
			'next'  => $more && $last ? ( (string) $last->{$col} ) . '|' . (string) $last->key_hash : '',
			'total' => $total,
		);
	}

	/**
	 * How many rows a kind holds.
	 *
	 * @param string $kind Kind.
	 * @return int
	 */
	public static function count( string $kind ): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE kind = %s', self::table(), $kind ) );
	}

	/**
	 * Remove rows: one key (by hash), or every row of a kind.
	 *
	 * @param string      $kind Kind.
	 * @param string|null $hash One key's hash, or null for all.
	 * @return int Rows removed.
	 */
	public static function forget( string $kind, ?string $hash = null ): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		if ( null === $hash ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE kind = %s', self::table(), $kind ) );
		}

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE kind = %s AND key_hash = %s', self::table(), $kind, $hash ) );
	}

	/**
	 * One row by key.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $kind, string $key ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT item_key, hits, first_seen, last_seen, sample_ref FROM %i WHERE kind = %s AND key_hash = %s AND day = %s', self::table(), $kind, md5( self::cut( $key ) ), self::ALL_TIME ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Cut a key or sample to the column's 191 characters (multibyte-safe).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function cut( string $text ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 191 ) : substr( $text, 0, 191 );
	}

	/**
	 * Is this request from a robot? A short list of the agents that make most automated traffic,
	 * so the 404 and search counters show what PEOPLE hit (zs-093 "bot filter").
	 *
	 * @param string $agent User-Agent.
	 * @return bool
	 */
	public static function is_bot( string $agent ): bool {
		if ( '' === trim( $agent ) ) {
			return true;
		}

		return 1 === preg_match( '/bot\b|bot\/|crawl|spider|slurp|fetch|scan|curl\/|wget|python-|go-http|java\/|httpclient|okhttp|headless|lighthouse|pingdom|uptime|monitor|facebookexternalhit|preview|feed|archiver|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|claude|perplexity|ccbot|amazonbot|applebot|bingpreview|yandex|baidu|duckduck|sogou|exabot|seznam|ia_archiver/i', $agent );
	}
}
