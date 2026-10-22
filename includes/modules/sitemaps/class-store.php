<?php
/**
 * Where the pre-built sitemap files live and what they hold (zs-043).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the waiting marks and the work list are rows written with ONE atomic statement each (see the class comment); the options API reads, then writes, and caches, which is exactly what loses a mark.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Files: `uploads/zd-private/sm/<generation>/<name>.xml` (the folder refuses direct web access;
 * the plugin sends the files). A full build writes a NEW generation and switches to it only when
 * it is complete, so search engines never read a half-built set; an edit rebuilds only the file
 * that holds the changed page, in place (written beside it, then renamed over it).
 *
 * The manifest (option `zseo_sitemap_manifest`, not autoloaded, read only by sitemap requests and
 * the screens) lists every file with what the incremental rebuild needs to find a page's file:
 * the unit (`post:<type>`, `tax:<taxonomy>`, `authors`), the language, the file's number and the
 * keyset bounds of its first and last row.
 *
 * ⛔⛔ HOW A CHANGE REACHES ITS FILE WITHOUT BEING LOST OR DONE TWICE (CLAUDE.md §2.10, §2.55).
 * Three rows, each with one writer rule:
 *
 * - `zseo_sitemap_marks`: the changes waiting, one per line. Any request APPENDS with one
 *   statement (`INSERT … ON DUPLICATE KEY UPDATE option_value = CONCAT( … )`). Nobody reads it
 *   and writes it back, so two requests saving at the same moment cannot overwrite each other.
 * - `zseo_sitemap_claim`: the marks a build step has taken. Taking is ONE statement too (the
 *   marks row is renamed), so a mark is either still waiting or taken, never both, never neither.
 *   The claim is deleted only after the files it names are in the work list: a step killed in
 *   between finds its claim again.
 * - `zseo_sitemap_work`: the refresh's cursor — the files still to rebuild, how many were done,
 *   how often each was rebuilt. Written only by the step that holds the job's lock, after EVERY
 *   file, so a killed step resumes at the file it was on.
 */
final class Store {

	/** Manifest option. */
	public const MANIFEST = 'zseo_sitemap_manifest';

	/** Pages waiting for their file to be rebuilt, as releases up to 0.9.1 stored them (read once, then removed). */
	public const DIRTY = 'zseo_sitemap_dirty';

	/** The changes waiting: one mark per line, appended to atomically. */
	public const MARKS = 'zseo_sitemap_marks';

	/** Past this size the waiting marks collapse into `*` (about 25,000 pages waiting untaken). */
	public const MARKS_BYTES = 1048576;

	/** The marks a step has taken and not yet turned into work. */
	public const CLAIM = 'zseo_sitemap_claim';

	/** The refresh's cursor: the files still to rebuild. */
	public const WORK = 'zseo_sitemap_work';

	/** Folder under uploads. */
	public const DIR = 'zd-private/sm';

	/**
	 * The manifest.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest(): array {
		$m = get_option( self::MANIFEST, array() );
		$m = is_array( $m ) ? $m : array();

		return $m + array(
			'gen'       => '',
			'complete'  => false,
			'built'     => 0,
			'refreshed' => 0,
			'files'     => array(),
			'next'      => null,
			'errors'    => array(),
			'took'      => 0.0,
			'peak'      => 0,
			'rows'      => 0,
		);
	}

	/**
	 * Store the manifest.
	 *
	 * @param array<string, mixed> $m Manifest.
	 * @return void
	 */
	public static function save( array $m ): void {
		update_option( self::MANIFEST, $m, false );
	}

	/**
	 * The files search engines are given now: the live generation, or — before the first build
	 * finished — the files of the first build that are already complete.
	 *
	 * @param array<string, mixed>|null $m Manifest (read when null).
	 * @return array{gen: string, files: array<string, array<string, mixed>>}
	 */
	public static function served( ?array $m = null ): array {
		$m = $m ?? self::manifest();
		if ( ! empty( $m['complete'] ) && '' !== (string) $m['gen'] ) {
			return array(
				'gen'   => (string) $m['gen'],
				'files' => (array) $m['files'],
			);
		}
		if ( is_array( $m['next'] ) && isset( $m['next']['gen'] ) ) {
			return array(
				'gen'   => (string) $m['next']['gen'],
				'files' => (array) ( $m['next']['files'] ?? array() ),
			);
		}

		return array(
			'gen'   => '',
			'files' => array(),
		);
	}

	/**
	 * The base folder (created on first use, closed to the web).
	 *
	 * @return string Absolute path, with a trailing slash, or '' when uploads cannot be written.
	 */
	public static function base(): string {
		$uploads = wp_get_upload_dir();
		$private = trailingslashit( (string) $uploads['basedir'] ) . 'zd-private';
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $private . '/.htaccess' ) ) {
			file_put_contents( $private . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own folder.
			file_put_contents( $private . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own folder.
		}

		return trailingslashit( $dir );
	}

	/**
	 * A file's absolute path.
	 *
	 * @param string $gen  Generation.
	 * @param string $name File name without `.xml`.
	 * @return string
	 */
	public static function path( string $gen, string $name ): string {
		return self::base() . $gen . '/' . $name . '.xml';
	}

	/**
	 * A new generation id.
	 *
	 * @return string
	 */
	public static function new_gen(): string {
		return 'g' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * A file's name for a unit, language and number: `post-1`, `post-de-de-2`, `tax-category-1`,
	 * `authors-1`.
	 *
	 * @param string $unit Unit.
	 * @param string $lang Language ('' for the default).
	 * @param int    $n    Number, from 1.
	 * @return string
	 */
	public static function name( string $unit, string $lang, int $n ): string {
		$base = str_replace( array( 'post:', 'tax:', ':' ), array( '', 'tax-', '-' ), $unit );
		$base = strtolower( (string) preg_replace( '/[^a-z0-9-]+/i', '-', $base ) );
		if ( '' !== $lang ) {
			$base .= '-' . strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $lang ) );
		}

		return trim( $base, '-' ) . '-' . max( 1, $n );
	}

	/**
	 * Which file holds a row: the one whose bounds contain its key, else the last file of its
	 * unit and language (rows newer than every file go to the end). Pure, for the tests.
	 *
	 * @param array<string, array<string, mixed>> $files Manifest files.
	 * @param string                              $unit  Unit.
	 * @param string                              $lang  Language.
	 * @param array{0: string, 1: int}            $key   The row's keyset key: [date, ID].
	 * @return string|null The file name, or null when the unit has no file yet.
	 */
	public static function file_for( array $files, string $unit, string $lang, array $key ): ?string {
		$last      = null;
		$last_n    = 0;
		$candidate = null;
		foreach ( $files as $name => $f ) {
			if ( (string) ( $f['unit'] ?? '' ) !== $unit || (string) ( $f['lang'] ?? '' ) !== $lang ) {
				continue;
			}
			if ( (int) $f['n'] > $last_n ) {
				$last_n = (int) $f['n'];
				$last   = (string) $name;
			}
			if ( self::compare( $key, (array) $f['first'] ) >= 0 && self::compare( $key, (array) $f['last'] ) <= 0 ) {
				$candidate = (string) $name;
			}
		}
		if ( null !== $candidate ) {
			return $candidate;
		}
		if ( null === $last ) {
			return null;
		}
		// Before the first file's first row: it belongs to the first file.
		foreach ( $files as $name => $f ) {
			if ( (string) ( $f['unit'] ?? '' ) === $unit && (string) ( $f['lang'] ?? '' ) === $lang && 1 === (int) $f['n'] && self::compare( $key, (array) $f['first'] ) < 0 ) {
				return (string) $name;
			}
		}
		// Between two files: the earlier one (its range is extended on rebuild).
		$best   = null;
		$best_k = null;
		foreach ( $files as $name => $f ) {
			if ( (string) ( $f['unit'] ?? '' ) !== $unit || (string) ( $f['lang'] ?? '' ) !== $lang ) {
				continue;
			}
			if ( self::compare( $key, (array) $f['last'] ) > 0 && ( null === $best_k || self::compare( (array) $f['last'], $best_k ) > 0 ) ) {
				$best   = (string) $name;
				$best_k = (array) $f['last'];
			}
		}

		return $best ?? $last;
	}

	/**
	 * Compare two keyset keys `[date, ID]`.
	 *
	 * @param array<int, mixed> $a Key.
	 * @param array<int, mixed> $b Key.
	 * @return int -1, 0 or 1.
	 */
	public static function compare( array $a, array $b ): int {
		$date = strcmp( (string) ( $a[0] ?? '' ), (string) ( $b[0] ?? '' ) );
		if ( 0 !== $date ) {
			return $date < 0 ? -1 : 1;
		}

		return (int) ( $a[1] ?? 0 ) <=> (int) ( $b[1] ?? 0 );
	}

	/**
	 * Remove a generation's folder (only `.xml` and `.tmp` files of our own shape).
	 *
	 * @param string $gen Generation.
	 * @return void
	 */
	public static function remove_gen( string $gen ): void {
		if ( 1 !== preg_match( '/^g[a-z0-9]{8}$/', $gen ) ) {
			return;
		}
		$dir = self::base() . $gen;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( is_string( $file ) && 1 === preg_match( '/^[a-z0-9-]+\.(xml|tmp)$/', $file ) ) {
				wp_delete_file( $dir . '/' . $file );
			}
		}
		if ( array( '.', '..' ) === array_values( (array) scandir( $dir ) ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty, folder.
		}
	}

	/**
	 * Remove every generation the manifest does not name (a build killed before it switched).
	 *
	 * @param array<string, mixed> $m Manifest.
	 * @return void
	 */
	public static function sweep( array $m ): void {
		$base = self::base();
		if ( '' === $base || ! is_dir( $base ) ) {
			return;
		}
		$keep = array_filter( array( (string) $m['gen'], is_array( $m['next'] ) ? (string) ( $m['next']['gen'] ?? '' ) : '' ) );
		foreach ( (array) scandir( $base ) as $dir ) {
			if ( is_string( $dir ) && 1 === preg_match( '/^g[a-z0-9]{8}$/', $dir ) && ! in_array( $dir, $keep, true ) ) {
				self::remove_gen( $dir );
			}
		}
	}

	/**
	 * One of our rows, read from the table itself (never the options cache: another process
	 * appends to it).
	 *
	 * @param string $name Row.
	 * @return string|null Its value, or null when there is none.
	 */
	private static function row( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Remove one of our rows.
	 *
	 * @param string $name Row.
	 * @return void
	 */
	private static function drop( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * The marks in a row's value.
	 *
	 * @param string|null $value Lines.
	 * @return array<int, string>
	 */
	private static function lines( ?string $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'trim', explode( "\n", $value ) ), static fn( string $line ): bool => '' !== $line ) ) );
	}

	/**
	 * Marks a release up to 0.9.1 left in its own option are moved to the waiting marks, once.
	 *
	 * @return void
	 */
	private static function adopt_legacy(): void {
		$old = get_option( self::DIRTY, null );
		if ( null === $old ) {
			return;
		}
		delete_option( self::DIRTY );
		if ( is_array( $old ) && array() !== $old ) {
			self::mark( array_map( 'strval', $old ) );
		}
	}

	/**
	 * Changes waiting for a build step to take them: unit|lang|date|id strings, or `*` for
	 * everything. (Files already taken into the work list are in `work()`.)
	 *
	 * @return array<int, string>
	 */
	public static function dirty(): array {
		self::adopt_legacy();

		return array_values( array_unique( array_merge( self::lines( self::row( self::CLAIM ) ), self::lines( self::row( self::MARKS ) ) ) ) );
	}

	/**
	 * How much is waiting: marks not yet taken plus files not yet rebuilt.
	 *
	 * @return int
	 */
	public static function waiting(): int {
		$work = self::work();

		return count( self::dirty() ) + count( $work['files'] ) + ( $work['full'] ? 1 : 0 );
	}

	/**
	 * Mark pages dirty: ONE statement that appends, so marks made by two requests at the same
	 * moment both survive.
	 *
	 * @param array<int, string> $marks Marks.
	 * @return void
	 */
	public static function mark( array $marks ): void {
		global $wpdb;
		$clean = array();
		foreach ( $marks as $mark ) {
			$mark = trim( str_replace( array( "\r", "\n" ), '', (string) $mark ) );
			if ( '' !== $mark ) {
				$clean[ $mark ] = true;
			}
		}
		if ( array() === $clean ) {
			return;
		}
		$lines = isset( $clean['*'] ) ? "*\n" : implode( "\n", array_keys( $clean ) ) . "\n";
		// A waiting list past MARKS_BYTES (a job paused for weeks on a busy site) becomes "rebuild
		// everything": that covers every mark it held, and the row stays small. Not a cap — the
		// full build does all of the work the marks asked for.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = CASE WHEN LENGTH(option_value) > %d THEN %s ELSE CONCAT(option_value, %s) END", self::MARKS, $lines, self::MARKS_BYTES, "*\n", $lines ) );
	}

	/**
	 * Take the waiting marks for the step that holds the job's lock.
	 *
	 * ⛔ The taking is ONE statement (a rename): a request appending at that moment lands either
	 * in the row that was taken or in a new waiting row, never nowhere. The caller records the
	 * work the marks stand for and only then calls `release()`; a step killed in between is
	 * given the same marks again here.
	 *
	 * @return array<int, string> The marks taken (empty when nothing waits).
	 */
	public static function claim(): array {
		global $wpdb;
		self::adopt_legacy();
		$claimed = self::row( self::CLAIM );
		if ( null === $claimed ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", self::CLAIM, self::MARKS ) );
			$claimed = self::row( self::CLAIM );
		}

		return self::lines( $claimed );
	}

	/**
	 * The marks taken are in the work list now: forget the claim.
	 *
	 * @return void
	 */
	public static function release(): void {
		self::drop( self::CLAIM );
	}

	/**
	 * The refresh's cursor.
	 *
	 * @return array{full: bool, files: array<string, array{0: string, 1: string, 2: int}>, seen: array<string, array{0: int, 1: int}>, done: int, since: int}
	 */
	public static function work(): array {
		$stored = json_decode( (string) self::row( self::WORK ), true );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'full'  => ! empty( $stored['full'] ),
			'files' => is_array( $stored['files'] ?? null ) ? $stored['files'] : array(),
			'seen'  => is_array( $stored['seen'] ?? null ) ? array_filter( $stored['seen'], 'is_array' ) : array(),
			'done'  => (int) ( $stored['done'] ?? 0 ),
			'since' => (int) ( $stored['since'] ?? 0 ),
		);
	}

	/**
	 * Store the refresh's cursor (only the step holding the job's lock calls this).
	 *
	 * @param array<string, mixed>|null $work Cursor, or null when the refresh is over.
	 * @return void
	 */
	public static function save_work( ?array $work ): void {
		global $wpdb;
		if ( null === $work ) {
			self::drop( self::WORK );
			return;
		}
		$json = (string) wp_json_encode( $work );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = %s", self::WORK, $json, $json ) );
	}

	/**
	 * Forget every waiting change and the cursor (the module was switched off).
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::DIRTY );
		self::drop( self::MARKS );
		self::drop( self::CLAIM );
		self::drop( self::WORK );
	}
}
