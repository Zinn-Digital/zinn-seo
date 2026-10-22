<?php
/**
 * Change batches and undo (zs-351): every bulk edit, CSV import and mass change can be reversed.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bulk;

use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The table `{prefix}zseo_history` (architecture §1.5): one row per field change made inside a
 * batch, with the value before and after. A batch is opened, every Meta::set() inside it is
 * recorded through `zinn_seo_field_saved`, and the batch is closed with a summary in a small
 * non-autoloaded index (the last 50 batches, for the screen).
 *
 * ⛔ Undo never overwrites a newer edit: a field is restored only when it still holds the value
 * the batch wrote. Anything changed since is left alone and counted, so the person is told.
 * An undo is itself a batch, so it can be undone.
 *
 * Ceiling: rows older than 365 days are pruned whenever a batch closes (the table is a history,
 * not an archive). The SEO-revisions feature reads the same table.
 */
final class History {

	/** Table schema version. */
	public const DB_VERSION = 1;

	/** Option holding the installed schema version. */
	public const DB_OPTION = 'zseo_history_db';

	/** Option holding the batch index. */
	public const INDEX = 'zseo_bulk_batches';

	/** Batches kept in the index. */
	public const KEEP = 50;

	/** Days of rows kept. */
	public const DAYS = 365;

	/**
	 * The open batch, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $open = null;

	/**
	 * Rows waiting to be written (flushed in groups).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private static array $buffer = array();

	/**
	 * The table's name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_history';
	}

	/**
	 * Create or upgrade the table when its version is behind (admin, REST and CLI only).
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
batch char(12) NOT NULL,
object_type varchar(8) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
field varchar(41) NOT NULL,
old_value longtext NULL,
new_value longtext NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
actor varchar(16) NOT NULL DEFAULT '',
changed_at int(10) unsigned NOT NULL,
PRIMARY KEY  (id),
KEY batch (batch,id),
KEY object (object_type,object_id),
KEY changed_at (changed_at)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Record every field change made while a batch is open.
	 *
	 * @return void
	 */
	public static function listen(): void {
		add_action( 'zinn_seo_field_saved', array( self::class, 'record' ), 10, 6 );
	}

	/**
	 * Open a batch.
	 *
	 * @param string $kind post|term.
	 * @param string $label  What it was, in words (for the history list).
	 * @param string $actor  bulk, import, undo, agent.
	 * @return string The batch id.
	 */
	public static function open( string $kind, string $label, string $actor ): string {
		self::maybe_install();
		self::$open   = array(
			'id'     => strtolower( wp_generate_password( 12, false, false ) ),
			'object' => $kind,
			'label'  => $label,
			'actor'  => $actor,
			'user'   => get_current_user_id(),
			'count'  => 0,
		);
		self::$buffer = array();

		return (string) self::$open['id'];
	}

	/**
	 * Continue a batch across requests (a background job's steps).
	 *
	 * @param array<string, mixed> $batch Batch as returned by current().
	 * @return void
	 */
	public static function resume( array $batch ): void {
		self::$open   = $batch;
		self::$buffer = array();
	}

	/**
	 * The open batch.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$open;
	}

	/**
	 * Record one change (action `zinn_seo_field_saved`).
	 *
	 * @param string $kind   post|term|user.
	 * @param int    $id     Object ID.
	 * @param string $name   Field.
	 * @param mixed  $before Old value.
	 * @param mixed  $after  New value.
	 * @param string $actor  Who.
	 * @return void
	 */
	public static function record( $kind, $id, $name, $before, $after, $actor = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the action's signature.
		if ( null === self::$open ) {
			return;
		}
		$def = Meta::fields()[ (string) $name ] ?? null;
		// A missing meta row reads as ''; record it as the field's empty value, which is what
		// Meta::set() stores to remove it again.
		$old = null !== $def && ( '' === $before || null === $before || false === $before ) ? Meta::empty_value( $def ) : $before;
		if ( self::same( $old, $after ) ) {
			return;
		}
		self::$buffer[]      = array( (string) $kind, (int) $id, (string) $name, (string) wp_json_encode( $old ), (string) wp_json_encode( $after ) );
		self::$open['count'] = (int) self::$open['count'] + 1;
		if ( count( self::$buffer ) >= 200 ) {
			self::flush();
		}
	}

	/**
	 * Write buffered rows in one multi-row insert.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( null === self::$open || array() === self::$buffer ) {
			return;
		}
		global $wpdb;
		$values = array();
		$args   = array();
		$now    = time();
		foreach ( self::$buffer as $row ) {
			$values[] = '(%s,%s,%d,%s,%s,%s,%d,%s,%d)';
			array_push( $args, self::$open['id'], $row[0], $row[1], $row[2], $row[3], $row[4], (int) self::$open['user'], (string) self::$open['actor'], $now );
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; $values is a list of placeholder groups.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (batch, object_type, object_id, field, old_value, new_value, user_id, actor, changed_at) VALUES " . implode( ',', $values ), $args ) );
		self::$buffer = array();
	}

	/**
	 * Close the batch: write what is left, add it to the index, prune old rows.
	 *
	 * @return array<string, mixed>|null The batch summary.
	 */
	public static function close(): ?array {
		if ( null === self::$open ) {
			return null;
		}
		self::flush();
		$batch      = self::$open;
		self::$open = null;
		if ( (int) $batch['count'] > 0 ) {
			self::index_put( $batch );
		}
		self::prune();

		return $batch;
	}

	/**
	 * Pause the batch at the end of a job step (rows written, batch kept for the next step).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function suspend(): ?array {
		self::flush();
		$batch      = self::$open;
		self::$open = null;

		return $batch;
	}

	/**
	 * Add or update a batch in the index.
	 *
	 * @param array<string, mixed> $batch Batch.
	 * @return void
	 */
	public static function index_put( array $batch ): void {
		$index = self::index();
		$found = false;
		foreach ( $index as $i => $row ) {
			if ( $row['id'] === $batch['id'] ) {
				$index[ $i ] = array_merge( $row, array( 'count' => (int) $batch['count'] ) );
				$found       = true;
			}
		}
		if ( ! $found ) {
			array_unshift(
				$index,
				array(
					'id'     => (string) $batch['id'],
					'object' => (string) $batch['object'],
					'label'  => (string) $batch['label'],
					'actor'  => (string) $batch['actor'],
					'user'   => (int) $batch['user'],
					'count'  => (int) $batch['count'],
					'at'     => time(),
					'undone' => '',
				)
			);
		}
		update_option( self::INDEX, array_slice( $index, 0, self::KEEP ), false );
	}

	/**
	 * The batch index, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function index(): array {
		$index = get_option( self::INDEX, array() );

		return is_array( $index ) ? array_values( array_filter( $index, 'is_array' ) ) : array();
	}

	/**
	 * One batch's summary.
	 *
	 * @param string $id Batch.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::index() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Undo part of a batch: rows after a cursor, until a deadline. Each field goes back to its
	 * old value only if it still holds the value the batch wrote.
	 *
	 * @param string $id       Batch.
	 * @param int    $after    Row cursor (0 to start).
	 * @param float  $deadline Microtime deadline.
	 * @return array{cursor: int, restored: int, kept: int, done: bool}
	 */
	public static function undo_step( string $id, int $after, float $deadline ): array {
		global $wpdb;
		$table    = self::table();
		$restored = 0;
		$kept     = 0;
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table, keyset by id.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, field, old_value, new_value FROM {$table} WHERE batch = %s AND id > %d ORDER BY id ASC LIMIT 200", $id, $after ), ARRAY_A );
			if ( ! is_array( $rows ) || array() === $rows ) {
				return array(
					'cursor'   => $after,
					'restored' => $restored,
					'kept'     => $kept,
					'done'     => true,
				);
			}
			// One meta read for every object of this page of rows, not one per row.
			$by_kind = array();
			foreach ( $rows as $row ) {
				$by_kind[ in_array( $row['object_type'], array( 'term', 'user' ), true ) ? (string) $row['object_type'] : 'post' ][] = (int) $row['object_id'];
			}
			foreach ( $by_kind as $type => $ids ) {
				update_meta_cache( $type, array_values( array_unique( $ids ) ) );
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$kind  = in_array( $row['object_type'], array( 'term', 'user' ), true ) ? (string) $row['object_type'] : 'post';
				$now   = Meta::get( $kind, (int) $row['object_id'], (string) $row['field'] );
				$wrote = json_decode( (string) $row['new_value'], true );
				if ( self::same( $now, $wrote ) ) {
					Meta::set( $kind, (int) $row['object_id'], (string) $row['field'], json_decode( (string) $row['old_value'], true ), 'undo' );
					++$restored;
				} else {
					++$kept;
				}
			}
			self::release( $by_kind );
		}

		return array(
			'cursor'   => $after,
			'restored' => $restored,
			'kept'     => $kept,
			'done'     => false,
		);
	}

	/**
	 * Let go of what one page of work put in this process's object cache.
	 *
	 * ⛔ Measured 2026-10-12 (stage T1): a long job that primes each page and never lets go keeps
	 * every object it touched until the process ends — the importer peaked at 4.4 GB on 1,000,000
	 * posts. Each page is released, so a step holds one page whatever the size of the site.
	 *
	 * @param array<string, array<int, int>> $by_kind post|term|user => ids.
	 * @return void
	 */
	public static function release( array $by_kind ): void {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}
		// A persistent object cache without a runtime flush: drop exactly what this page read.
		$groups = array(
			'post' => array( 'posts', 'post_meta' ),
			'term' => array( 'terms', 'term_meta' ),
			'user' => array( 'users', 'user_meta' ),
		);
		foreach ( $by_kind as $kind => $ids ) {
			foreach ( (array) $ids as $id ) {
				foreach ( $groups[ $kind ] ?? array() as $group ) {
					wp_cache_delete( (int) $id, $group );
				}
			}
		}
	}

	/**
	 * Mark a batch undone in the index.
	 *
	 * @param string $id   Batch.
	 * @param string $by   The undo batch's id.
	 * @return void
	 */
	public static function mark_undone( string $id, string $by ): void {
		$index = self::index();
		foreach ( $index as $i => $row ) {
			if ( $row['id'] === $id ) {
				$index[ $i ]['undone'] = $by;
			}
		}
		update_option( self::INDEX, $index, false );
	}

	/**
	 * Same value, loosely (an array of flags in another order is the same value).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	public static function same( $a, $b ): bool {
		if ( is_array( $a ) && is_array( $b ) ) {
			sort( $a );
			sort( $b );
			return array_values( $a ) === array_values( $b );
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}

		return (string) ( is_array( $a ) ? '' : $a ) === (string) ( is_array( $b ) ? '' : $b );
	}

	/**
	 * Remove rows older than the ceiling.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		$table = self::table();
		// Batch rows only: single changes (SEO revisions, batch '') keep each page's newest 50
		// whatever their age, and are pruned per page by the revisions module.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE changed_at < %d AND batch <> ''", time() - self::DAYS * DAY_IN_SECONDS ) );
	}
}
