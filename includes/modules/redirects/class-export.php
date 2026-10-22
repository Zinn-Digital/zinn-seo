<?php
/**
 * Redirects out as a spreadsheet (zs-094); the Pro module adds server rules (zs-095).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; every value is a placeholder.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every rule, keyset by id (never an OFFSET, never all rows in memory): `each()` hands rows to a
 * callback 1,000 at a time, so a million rules stream in constant memory. The spreadsheet's header
 * is the one the importer reads back (a round trip is lossless), cells that a spreadsheet program
 * would run as a formula are prefixed with an apostrophe.
 */
final class Export {

	/** Spreadsheet columns, in order. */
	public const COLUMNS = array( 'source', 'target', 'code', 'regex', 'enabled', 'group', 'note', 'hits', 'last_hit', 'origin' );

	/**
	 * Walk every rule.
	 *
	 * @param callable $each `function ( array $row ): void` (raw row).
	 * @param bool     $enabled_only Only rules that are switched on.
	 * @return int Rules.
	 */
	public static function each( callable $each, bool $enabled_only = false ): int {
		if ( ! Store::ready() ) {
			return 0;
		}
		global $wpdb;
		$after = 0;
		$n     = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d' . ( $enabled_only ? ' AND enabled = 1' : '' ) . ' ORDER BY id ASC LIMIT 1000', Store::table(), $after ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a literal clause.
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$each( $row );
				++$n;
			}
			$full = 1000 === count( $rows );
		} while ( $full );

		return $n;
	}

	/**
	 * One spreadsheet cell, safe from formula injection.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/**
	 * One rule as a spreadsheet line.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return string
	 */
	public static function csv_line( array $row ): string {
		$cells = array(
			(string) $row['source'],
			(string) $row['target'],
			(string) $row['code'],
			(int) $row['is_regex'] ? '1' : '0',
			(int) $row['enabled'] ? '1' : '0',
			(string) $row['grp'],
			(string) $row['note'],
			(string) $row['hits'],
			(string) ( $row['last_hit'] ?? '' ),
			(string) $row['origin'],
		);

		return implode( ',', array_map( array( self::class, 'cell' ), $cells ) ) . "\r\n";
	}

	/**
	 * Stream the spreadsheet to the browser and stop.
	 *
	 * @return void
	 */
	public static function send_csv(): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF" . implode( ',', self::COLUMNS ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a spreadsheet, not HTML.
		self::each(
			static function ( array $row ): void {
				echo self::csv_line( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a spreadsheet, not HTML; every cell quoted.
			}
		);
		exit;
	}
}
