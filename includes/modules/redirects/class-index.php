<?php
/**
 * The redirect index visitors' requests read: constant time, no database query (zs-095).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; every value is a placeholder.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.rename_rename -- small private index files under uploads, written atomically; WP_Filesystem cannot rename atomically and is not loaded on visitors' requests.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ A VISITOR'S REQUEST NEVER SCANS RULES. Plain rules are split by the first three hex digits
 * of their key's MD5 into at most 4,096 small JSON files, `uploads/zd-private/rd/<shard>.json`
 * (`{"<md5>": [id, code, target]}`); a request hashes its own address, reads the ONE file of its
 * shard (or finds there is none) and looks its hash up in it. The cost does not depend on how many
 * rules exist: about 25 entries per file at 100,000 rules (measured in zinn_seo_p5.sh).
 *
 * - Regular-expression rules are a separate list, `rd/regex.json`, in their order. The first
 *   REGEX_EARLY of them are tried on every request; ALL of them on a request WordPress could not
 *   answer (a 404), so no rule is ever skipped, only made cheaper (the screen warns above 500).
 * - Where the uploads folder cannot be written the index falls back to the table (one indexed
 *   query per request, `redirects_store` = db) and the Redirects screen says so.
 * - A site that has never stored a rule pays nothing: `redirects_store` is '' and no file is read.
 * - Every change rewrites only the shard(s) it touches; the `redirects-index` job rewrites them all
 *   (16 shards per query, deleting shards that became empty) and repairs a lost folder.
 */
final class Index {

	/** Folder under uploads. */
	public const DIR = 'zd-private/rd';

	/** Job id. */
	public const JOB = 'redirects-index';

	/** Regular-expression rules tried on every request (all of them on a 404). */
	public const REGEX_EARLY = 500;

	/**
	 * While an import or a migration writes many rules, shards are written once per step, not
	 * once per rule: shard => true (`regex` for the pattern list).
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $deferred = null;

	/**
	 * Hold index writes until flush() (many rules in one request).
	 *
	 * @return void
	 */
	public static function defer(): void {
		if ( null === self::$deferred ) {
			self::$deferred = array();
		}
	}

	/**
	 * Write the shards held since defer().
	 *
	 * @return int Shards written.
	 */
	public static function flush(): int {
		$held           = self::$deferred ?? array();
		self::$deferred = null;
		if ( array() === $held ) {
			return 0;
		}
		$dir = self::dir( true );
		if ( '' === $dir ) {
			self::set_store( 'db' );
			return 0;
		}
		$n = 0;
		foreach ( array_keys( $held ) as $shard ) {
			if ( 'regex' === $shard ) {
				self::write_regex( $dir );
				continue;
			}
			self::write_shards( $dir, (string) $shard, (string) $shard );
			++$n;
		}
		self::set_store( 'files' );

		return $n;
	}

	/**
	 * The index folder (absolute, with a trailing slash), created and closed to the web.
	 *
	 * @param bool $create Create it.
	 * @return string '' when it cannot be used.
	 */
	public static function dir( bool $create = false ): string {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$private = trailingslashit( (string) $uploads['basedir'] ) . 'zd-private';
		$dir     = $private . '/rd/';
		if ( $create && ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			if ( ! file_exists( $private . '/.htaccess' ) ) {
				file_put_contents( $private . '/index.php', "<?php\n// Silence.\n" );
				file_put_contents( $private . '/.htaccess', "Require all denied\nDeny from all\n" );
			}
			file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" );
		}

		return is_dir( $dir ) && wp_is_writable( $dir ) ? $dir : ( $create ? '' : ( is_dir( $dir ) ? $dir : '' ) );
	}

	/**
	 * How the index is kept: files, db (fallback) or '' (no rule stored yet).
	 *
	 * @return string
	 */
	public static function store(): string {
		return (string) Settings::get( 'redirects_store', '' );
	}

	/**
	 * A rule was created, changed or deleted: rewrite the shard(s) and the regex list it touches.
	 *
	 * @param array<string, mixed>|null $before Raw row before.
	 * @param array<string, mixed>|null $after  Raw row after.
	 * @return void
	 */
	public static function changed( ?array $before, ?array $after ): void {
		$shards = array();
		$regex  = false;
		foreach ( array( $before, $after ) as $row ) {
			if ( null === $row ) {
				continue;
			}
			if ( (int) $row['is_regex'] ) {
				$regex = true;
			} else {
				$shards[ Path::shard( (string) $row['path_hash'] ) ] = true;
			}
		}
		self::notify();
		if ( null !== self::$deferred ) {
			self::$deferred += $shards + ( $regex ? array( 'regex' => true ) : array() );
			return;
		}
		$dir = self::dir( true );
		if ( '' === $dir ) {
			self::set_store( 'db' );
			return;
		}
		foreach ( array_keys( $shards ) as $shard ) {
			self::write_shards( $dir, (string) $shard, (string) $shard );
		}
		if ( $regex ) {
			self::write_regex( $dir );
		}
		self::set_store( 'files' );
	}

	/**
	 * Tell listeners (the Pro server-rules sync) that rules changed — once per request, at its end.
	 *
	 * @return void
	 */
	private static function notify(): void {
		if ( ! has_action( 'shutdown', array( self::class, 'announce' ) ) ) {
			add_action( 'shutdown', array( self::class, 'announce' ), 5 );
		}
	}

	/**
	 * Fire `zinn_seo_redirects_changed` (shutdown).
	 *
	 * @return void
	 */
	public static function announce(): void {
		/**
		 * Fires at the end of a request in which redirect rules changed.
		 */
		do_action( 'zinn_seo_redirects_changed' );
	}

	/**
	 * Record how the index is kept (one settings write, only when it changes).
	 *
	 * @param string $store files|db.
	 * @return void
	 */
	private static function set_store( string $store ): void {
		if ( self::store() !== $store ) {
			Settings::save( array( 'redirects_store' => $store ) );
		}
	}

	/**
	 * Write every shard from `$from` to `$to` (inclusive, 3-hex), deleting the ones now empty.
	 *
	 * @param string $dir  Folder.
	 * @param string $from First shard.
	 * @param string $to   Last shard.
	 * @return int Rules written.
	 */
	public static function write_shards( string $dir, string $from, string $to ): int {
		global $wpdb;
		$upper = hexdec( $to ) + 1;
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, path_hash, code, target FROM %i WHERE path_hash >= %s AND path_hash < %s AND is_regex = 0 AND enabled = 1',
				Store::table(),
				$from,
				$upper >= 4096 ? 'g' : str_pad( dechex( (int) $upper ), Path::SHARD, '0', STR_PAD_LEFT )
			),
			ARRAY_A
		);
		$by    = array();
		foreach ( $rows as $row ) {
			$by[ Path::shard( (string) $row['path_hash'] ) ][ (string) $row['path_hash'] ] = array( (int) $row['id'], (int) $row['code'], (string) $row['target'] );
		}
		for ( $n = hexdec( $from ); $n < $upper; $n++ ) {
			$shard = str_pad( dechex( (int) $n ), Path::SHARD, '0', STR_PAD_LEFT );
			$file  = $dir . $shard . '.json';
			if ( empty( $by[ $shard ] ) ) {
				if ( file_exists( $file ) ) {
					wp_delete_file( $file );
				}
				continue;
			}
			self::put( $file, (string) wp_json_encode( $by[ $shard ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}

		return count( $rows );
	}

	/**
	 * Write the regular-expression list.
	 *
	 * @param string $dir Folder.
	 * @return int Rules.
	 */
	public static function write_regex( string $dir ): int {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, code, target FROM %i WHERE is_regex = 1 AND enabled = 1 ORDER BY position ASC, id ASC', Store::table() ), ARRAY_A );
		$list = array();
		foreach ( $rows as $row ) {
			$list[] = array( (int) $row['id'], (string) $row['source'], (int) $row['code'], (string) $row['target'] );
		}
		if ( array() === $list ) {
			if ( file_exists( $dir . 'regex.json' ) ) {
				wp_delete_file( $dir . 'regex.json' );
			}
		} else {
			self::put( $dir . 'regex.json', (string) wp_json_encode( $list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		if ( (int) Settings::get( 'redirects_regex', 0 ) !== count( $list ) ) {
			Settings::save( array( 'redirects_regex' => count( $list ) ) );
		}

		return count( $list );
	}

	/**
	 * Write a file atomically (a reader sees the old or the new file, never half of one).
	 *
	 * @param string $file Path.
	 * @param string $body Content.
	 * @return void
	 */
	private static function put( string $file, string $body ): void {
		$tmp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false !== file_put_contents( $tmp, $body ) ) {
			rename( $tmp, $file );
		}
	}

	/**
	 * Find the plain rule for a request (the visitor's path).
	 *
	 * @param array<int, string> $keys Lookup keys (Path::lookups()).
	 * @return array{0: int, 1: int, 2: string, 3: string}|null [id, code, target, the key that matched].
	 */
	public static function find( array $keys ): ?array {
		$store = self::store();
		if ( 'files' === $store ) {
			$dir = self::dir();
			if ( '' === $dir ) {
				return null;
			}
			foreach ( $keys as $key ) {
				$hash = Path::hash( $key );
				$file = $dir . Path::shard( $hash ) . '.json';
				if ( ! is_file( $file ) ) {
					continue;
				}
				$map = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $map ) && isset( $map[ $hash ] ) && is_array( $map[ $hash ] ) ) {
					return array( (int) $map[ $hash ][0], (int) $map[ $hash ][1], (string) $map[ $hash ][2], $key );
				}
			}
			return null;
		}
		if ( 'db' === $store && Store::ready() ) {
			global $wpdb;
			$hashes = array_map( array( Path::class, 'hash' ), $keys );
			$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, path_hash, code, target FROM %i WHERE path_hash IN ({$in}) AND is_regex = 0 AND enabled = 1", array_merge( array( Store::table() ), $hashes ) ), ARRAY_A );
			foreach ( $hashes as $i => $hash ) {
				foreach ( $rows as $row ) {
					if ( $row['path_hash'] === $hash ) {
						return array( (int) $row['id'], (int) $row['code'], (string) $row['target'], (string) $keys[ $i ] );
					}
				}
			}
		}

		return null;
	}

	/**
	 * The regular-expression rules, in order.
	 *
	 * @return array<int, array{0: int, 1: string, 2: int, 3: string}>
	 */
	public static function regex_rules(): array {
		if ( (int) Settings::get( 'redirects_regex', 0 ) < 1 ) {
			return array();
		}
		if ( 'files' === self::store() ) {
			$dir  = self::dir();
			$list = '' !== $dir && is_file( $dir . 'regex.json' ) ? json_decode( (string) file_get_contents( $dir . 'regex.json' ), true ) : null;
			return is_array( $list ) ? $list : array();
		}
		if ( ! Store::ready() ) {
			return array();
		}
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, code, target FROM %i WHERE is_regex = 1 AND enabled = 1 ORDER BY position ASC, id ASC', Store::table() ), ARRAY_A ) as $row ) {
			$out[] = array( (int) $row['id'], (string) $row['source'], (int) $row['code'], (string) $row['target'] );
		}

		return $out;
	}

	/**
	 * Register the rebuild job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Writing the redirect index', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * One step of the full rebuild: 16 shards per query, until the deadline.
	 *
	 * @param array<string, mixed> $state    `next` shard number.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$next = (int) ( $state['next'] ?? 0 );
		$dir  = self::dir( true );
		if ( '' === $dir || ! Store::ready() ) {
			if ( Store::ready() ) {
				self::set_store( 'db' );
			}
			return array(
				'state'    => $state,
				'done'     => true,
				'progress' => array(
					'done'  => 4096,
					'total' => 4096,
				),
			);
		}
		$rules = (int) ( $state['rules'] ?? 0 );
		while ( $next < 4096 && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$rules += self::write_shards( $dir, str_pad( dechex( $next ), Path::SHARD, '0', STR_PAD_LEFT ), str_pad( dechex( min( 4095, $next + 15 ) ), Path::SHARD, '0', STR_PAD_LEFT ) );
			$next  += 16;
		}
		$done = $next >= 4096;
		if ( $done ) {
			self::write_regex( $dir );
			self::set_store( 'files' );
			self::notify();
			update_option(
				'zseo_redirects_built',
				array(
					'at'    => time(),
					'rules' => $rules,
				),
				false
			);
		}

		return array(
			'state'    => array(
				'next'  => $next,
				'rules' => $rules,
			),
			'done'     => $done,
			'progress' => array(
				'done'  => min( 4096, $next ),
				'total' => 4096,
			),
		);
	}

	/**
	 * Health for the screen: how the index is kept, files, rules in it.
	 *
	 * @return array<string, mixed>
	 */
	public static function health(): array {
		$dir   = self::dir();
		$files = '' !== $dir ? glob( $dir . '*.json' ) : array();
		$built = get_option( 'zseo_redirects_built', array() );

		return array(
			'store'    => self::store(),
			'writable' => '' !== self::dir( true ),
			'files'    => is_array( $files ) ? count( $files ) : 0,
			'regex'    => (int) Settings::get( 'redirects_regex', 0 ),
			'early'    => self::REGEX_EARLY,
			'built'    => is_array( $built ) ? (int) ( $built['at'] ?? 0 ) : 0,
			'job'      => Jobs::view( self::JOB ),
		);
	}
}
