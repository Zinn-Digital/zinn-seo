<?php
/**
 * Builds the sitemap files in the background: all of them, or only the ones holding pages that
 * changed (zs-040, zs-041, zs-042, zs-043, zs-046, zs-047, zs-048, zs-050).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;
use ZinnDigital\ZinnSEO\Templates\Templates;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset pages of a background build: a cursor cannot be cached, and every value is a placeholder of its prepare().

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ BOUNDED MEMORY, CONSTANT TIME PER PAGE, NO CAP (architecture §1.4, §1.6; CLAUDE.md §2.10).
 *
 * - Rows are read in pages of BATCH by a KEYSET over WordPress's own `type_status_date` index —
 *   `(post_date, ID)` after the last row read — never OFFSET, so the last page of a million posts
 *   costs what the first one does.
 * - Each page's meta, terms, parents, translations and image files are read in one query each,
 *   then the page is written to the file and forgotten (the in-memory object cache is emptied
 *   after every page), so the build holds one page of rows whatever the site's size.
 * - The work is a resumable job (Jobs): a step runs ≤ 20 s, saves its cursor and chains the next.
 *   Nothing is sliced off: a site with a million posts simply takes more steps.
 *
 * Units: one per included post type (`post:<type>`), taxonomy (`tax:<taxonomy>`), the authors,
 * and `home` (the home page when it shows the blog, post type archives, and each language's home).
 * Each unit is split into files of `sitemap_per_file` addresses, per language when Tranzly lists
 * more than one (zs-046).
 */
final class Builder {

	/** Rows read per query. */
	public const BATCH = 500;

	/** The job's id. */
	public const JOB = 'sitemaps';

	/**
	 * The units this site's sitemap has, in order.
	 *
	 * @return array<int, string>
	 */
	public static function units(): array {
		$out           = array( 'home' );
		$exclude_types = (array) Settings::get( 'sitemap_exclude_types', array() );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type => $object ) {
			$type = (string) $type;
			if ( in_array( $type, $exclude_types, true ) ) {
				continue;
			}
			// ⛔ A public type with no page of its own (an FAQ or review item shown inside other
			// pages, `publicly_queryable` false) has no address worth listing (Q277).
			if ( ! \ZinnDigital\ZinnSEO\Templates\Content_Types::own_page( $object ) ) {
				continue;
			}
			if ( 'attachment' === $type && ! Templates::attachment_pages() ) {
				continue;
			}
			if ( 'noindex' === (string) Templates::effective( 'post:' . $type )['index'] ) {
				continue; // zs-048: a type set to noindex is not offered to search engines.
			}
			$out[] = 'post:' . $type;
		}
		$exclude_tax = (array) Settings::get( 'sitemap_exclude_taxonomies', array() );
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax => $object ) {
			$tax = (string) $tax;
			if ( 'post_format' === $tax || in_array( $tax, $exclude_tax, true ) || ! \ZinnDigital\ZinnSEO\Templates\Content_Types::own_page( $object ) ) {
				continue;
			}
			if ( 'noindex' === (string) Templates::effective( 'tax:' . $tax )['index'] ) {
				continue;
			}
			$out[] = 'tax:' . $tax;
		}
		if ( (bool) Settings::get( 'sitemap_authors', true ) && 'noindex' !== (string) Templates::effective( 'author' )['index'] ) {
			$out[] = 'authors';
		}

		foreach ( array_keys( self::sources() ) as $id ) {
			$out[] = 'x:' . $id;
		}

		/**
		 * Filters the sitemap's units: `home`, `post:<type>`, `tax:<taxonomy>`, `authors`, and
		 * `x:<id>` for each source of other addresses (see `zinn_seo_sitemap_sources`).
		 *
		 * @param array<int, string> $out Units in order.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'zinn_seo_sitemap_units', $out ) ) );
	}

	/**
	 * Sources of addresses that are neither posts, terms nor authors: a marketplace's stores, a
	 * forum's member pages, a site's business locations.
	 *
	 * @return array<string, array{read: callable, entry: callable, count?: callable}>
	 */
	public static function sources(): array {
		static $sources = null;
		if ( null === $sources || ( defined( 'ZINN_SEO_TESTS' ) && ZINN_SEO_TESTS ) ) {
			/**
			 * Filters the sitemap's other sources: id (`[a-z0-9-]+`) => `read`, `entry` and
			 * optionally `count`.
			 *
			 * - `read( int $after, int $until, int $limit ): object[]` — the next rows, each with a
			 *   numeric `ID`, in ascending `ID` order, with `ID > $after` (and `<= $until` when
			 *   `$until > 0`). Keyset only: never an offset, never a cap of its own.
			 * - `entry( object $row ): array` — `loc`, and optionally `lastmod` (Unix time) and
			 *   `images`; an empty array leaves the row out.
			 * - `count(): int` — how many there are (for the progress bar).
			 *
			 * @param array<string, array<string, callable>> $sources Sources.
			 */
			$found   = (array) apply_filters( 'zinn_seo_sitemap_sources', array() );
			$sources = array();
			foreach ( $found as $id => $source ) {
				if ( is_string( $id ) && 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,30}$/', $id ) && is_array( $source ) && is_callable( $source['read'] ?? null ) && is_callable( $source['entry'] ?? null ) ) {
					$sources[ $id ] = $source;
				}
			}
		}

		return $sources;
	}

	/**
	 * Addresses per file.
	 *
	 * @return int
	 */
	public static function per_file(): int {
		return max( 100, min( Writer::MAX_URLS, (int) Settings::get( 'sitemap_per_file', 1000 ) ) );
	}

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function register(): void {
		\ZinnDigital\ZinnSEO\Jobs::register(
			self::JOB,
			static fn() => __( 'Build the sitemaps', 'zinn-seo' ),
			array( self::class, 'step' )
		);
	}

	/**
	 * Start a full build (unless one is running), or queue pages for an incremental one.
	 *
	 * @param bool $full A full build.
	 * @return void
	 */
	public static function start( bool $full ): void {
		$job = \ZinnDigital\ZinnSEO\Jobs::get( self::JOB );
		if ( $full ) {
			Store::mark( array( '*' ) );
		}
		if ( in_array( (string) $job['status'], array( 'pending', 'paused' ), true ) ) {
			return; // The running job takes the marks before it finishes.
		}
		\ZinnDigital\ZinnSEO\Jobs::start( self::JOB, array() );
	}

	/**
	 * How far the job is NOW, also while a step is running in another process: a refresh saves
	 * its cursor after every file, a full build reports what its last step reached.
	 *
	 * @param array<string, mixed> $job The job's record.
	 * @return array{done: int, total: int|null, unit: string}
	 */
	public static function progress( array $job ): array {
		$work = Store::work();
		if ( 'full' !== (string) ( $job['state']['mode'] ?? '' ) && ! $work['full'] && ( $work['done'] > 0 || array() !== $work['files'] ) ) {
			return array(
				'done'  => (int) $work['done'],
				'total' => (int) $work['done'] + count( $work['files'] ),
				'unit'  => 'files',
			);
		}
		$p = (array) $job['progress'];

		return array(
			'done'  => (int) ( $p['done'] ?? 0 ),
			'total' => isset( $p['total'] ) ? (int) $p['total'] : null,
			'unit'  => (string) ( $p['unit'] ?? 'rows' ),
		);
	}

	/**
	 * One step of the job.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Microtime to stop by.
	 * @return array{state: array<string, mixed>, done: bool, progress: array{done: int, total: int|null}}
	 */
	public static function step( array $state, float $deadline ): array {
		$m = Store::manifest();
		if ( 'full' !== (string) ( $state['mode'] ?? '' ) ) {
			$work = Refresh::intake( (array) $m['files'], (array) ( $m['units'] ?? self::units() ) );
			if ( ! $work['full'] && ! empty( $m['complete'] ) ) {
				return self::refresh_step( $work, $deadline );
			}
			$state = self::full_state( $m );
		}
		$state = self::full_step( $state, $deadline );
		if ( empty( $state['finished'] ) ) {
			return array(
				'state'    => $state,
				'done'     => false,
				'progress' => array(
					'done'  => (int) $state['rows'],
					'total' => (int) $state['total'],
					'unit'  => 'rows',
				),
			);
		}
		// The build read every page, so it covered everything taken before it began: the cursor
		// starts afresh. What was marked WHILE it ran still waits (a `*` among it builds again)
		// and the next step takes it.
		Store::save_work( null );

		return array(
			'state'    => array(),
			'done'     => 0 === Store::waiting(),
			'progress' => array(
				'done'  => (int) $state['rows'],
				'total' => (int) $state['rows'],
				'unit'  => 'rows',
			),
		);
	}

	// ── Full build ────────────────────────────────────────────────────────────────────────────

	/**
	 * A fresh full-build state (a new generation).
	 *
	 * @param array<string, mixed> $m Manifest.
	 * @return array<string, mixed>
	 */
	private static function full_state( array $m ): array {
		if ( is_array( $m['next'] ) && isset( $m['next']['gen'] ) ) {
			Store::remove_gen( (string) $m['next']['gen'] );
		}
		$gen       = Store::new_gen();
		$m['next'] = array(
			'gen'     => $gen,
			'files'   => array(),
			'started' => time(),
		);
		Store::save( $m );
		Store::sweep( $m );

		return array(
			'mode'    => 'full',
			'gen'     => $gen,
			'units'   => self::units(),
			'u'       => 0,
			'after'   => array( '', 0 ),
			'open'    => array(),
			'rows'    => 0,
			'total'   => self::estimate(),
			'started' => microtime( true ),
			'peak'    => 0,
			'errors'  => array(),
			'foreign' => array(),
			'closed'  => array(),
			'last_n'  => array(),
		);
	}

	/**
	 * Rows to read, for the progress bar (one COUNT per unit, from WordPress's own counts).
	 *
	 * @return int
	 */
	private static function estimate(): int {
		$total = 0;
		foreach ( self::units() as $unit ) {
			if ( 0 === strpos( $unit, 'post:' ) ) {
				$counts = wp_count_posts( substr( $unit, 5 ) );
				$total += (int) ( 'post:attachment' === $unit ? ( $counts->inherit ?? 0 ) : ( $counts->publish ?? 0 ) );
			} elseif ( 0 === strpos( $unit, 'tax:' ) ) {
				$total += (int) wp_count_terms(
					array(
						'taxonomy'   => substr( $unit, 4 ),
						'hide_empty' => ! self::lists_empty( substr( $unit, 4 ) ),
					)
				);
			} elseif ( 0 === strpos( $unit, 'x:' ) && is_callable( self::sources()[ substr( $unit, 2 ) ]['count'] ?? null ) ) {
				$total += (int) call_user_func( self::sources()[ substr( $unit, 2 ) ]['count'] );
			}
		}

		return $total;
	}

	/**
	 * Build as much as fits before the deadline.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	private static function full_step( array $state, float $deadline ): array {
		$units = (array) $state['units'];
		$count = count( $units );
		$state = self::rewind_open( $state );
		while ( $state['u'] < $count ) {
			$unit = (string) $units[ $state['u'] ];
			$rows = self::read( $unit, (array) $state['after'], null, null );
			if ( array() === $rows ) {
				$state = self::close_all( $state );
				++$state['u'];
				$state['after'] = array( '', 0 );
			} else {
				$entries        = self::entries( $unit, $rows );
				$state          = self::write( $state, $unit, $entries );
				$last           = end( $rows );
				$state['after'] = self::key_of( $unit, $last );
				$state['rows'] += count( $rows );
			}
			self::forget();
			$state['peak'] = max( (int) $state['peak'], memory_get_peak_usage( true ) );
			if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
				return self::flush_closed( $state );
			}
		}
		$state = self::flush_closed( $state );
		self::finish( $state );
		$state['finished'] = true;

		return $state;
	}

	/**
	 * Cut every open file back to what the saved state knows of it.
	 *
	 * ⛔ A step killed by the host had already appended rows the state never recorded; the step
	 * that resumes reads those rows again. Without this they were written twice (the same address
	 * listed twice, and a file longer than its count says). The files' sizes are part of the
	 * state for exactly this.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function rewind_open( array $state ): array {
		foreach ( (array) $state['open'] as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['size'] ) ) {
				continue; // A build begun by a release that did not record sizes.
			}
			$path = Store::path( (string) $state['gen'], (string) $f['name'] );
			clearstatcache( true, $path );
			if ( is_file( $path ) && (int) filesize( $path ) > (int) $f['size'] ) {
				$handle = fopen( $path, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- our own sitemap file, cut back to a known length: WP_Filesystem cannot truncate.
				if ( false !== $handle ) {
					ftruncate( $handle, (int) $f['size'] );
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
				}
			}
		}

		return $state;
	}

	/**
	 * Switch to the new generation and record the build.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function finish( array $state ): void {
		$m    = Store::manifest();
		$old  = (string) $m['gen'];
		$next = is_array( $m['next'] ) ? $m['next'] : array( 'files' => array() );
		$m    = array_merge(
			$m,
			array(
				'gen'       => (string) $state['gen'],
				'complete'  => true,
				'built'     => time(),
				'refreshed' => time(),
				'files'     => (array) $next['files'],
				'next'      => null,
				'took'      => round( microtime( true ) - (float) $state['started'], 2 ),
				'peak'      => (int) $state['peak'],
				'rows'      => (int) $state['rows'],
				'errors'    => self::check( (array) $next['files'], (array) $state['foreign'] ),
				'units'     => (array) $state['units'],
			)
		);
		Store::save( $m );
		if ( '' !== $old && $old !== (string) $state['gen'] ) {
			Store::remove_gen( $old );
		}

		/**
		 * Fires when the sitemap files changed (a full build finished, or a file was rebuilt).
		 * The Search Console module resubmits the sitemap here.
		 *
		 * @param array<string, mixed> $m The manifest.
		 */
		do_action( 'zinn_seo_sitemap_changed', $m );
	}

	/**
	 * Problems worth showing on the health screen (zs-051, zs-332).
	 *
	 * @param array<string, array<string, mixed>> $files   Files.
	 * @param array<int, string>                  $foreign Addresses on another host.
	 * @return array<int, array{code: string, file: string, detail: string}>
	 */
	public static function check( array $files, array $foreign ): array {
		$out = array();
		foreach ( $files as $name => $f ) {
			if ( (int) $f['count'] > Writer::MAX_URLS ) {
				$out[] = array(
					'code'   => 'too_many',
					'file'   => (string) $name,
					'detail' => (string) $f['count'],
				);
			}
			if ( (int) $f['bytes'] > Writer::MAX_BYTES ) {
				$out[] = array(
					'code'   => 'too_big',
					'file'   => (string) $name,
					'detail' => (string) $f['bytes'],
				);
			}
		}
		foreach ( array_slice( array_values( array_unique( $foreign ) ), 0, 10 ) as $url ) {
			$out[] = array(
				'code'   => 'foreign_host',
				'file'   => '',
				'detail' => (string) $url,
			);
		}

		return $out;
	}

	/**
	 * Close every open file of the current unit.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function close_all( array $state ): array {
		foreach ( array_keys( (array) $state['open'] ) as $lang ) {
			$state = self::close( $state, (string) $lang );
		}

		return $state;
	}

	/**
	 * Close one open file and record it in the generation being built.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $lang  Language key of the open file.
	 * @return array<string, mixed>
	 */
	private static function close( array $state, string $lang ): array {
		$f = $state['open'][ $lang ] ?? null;
		if ( ! is_array( $f ) ) {
			return $state;
		}
		$path = Store::path( (string) $state['gen'], (string) $f['name'] );
		file_put_contents( $path, Writer::close(), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file, appended to in the background.
		$f['bytes'] = (int) filesize( $path );
		// Recorded in the manifest once per step (flush_closed), not once per file: at a million
		// pages the manifest lists a thousand files, and rewriting it after each one would cost
		// the square of the site's size.
		$state['closed'][ (string) $f['name'] ]                             = self::record( $f );
		$state['last_n'][ (string) $f['unit'] . '|' . (string) $f['lang'] ] = (int) $f['n'];
		unset( $state['open'][ $lang ] );

		return $state;
	}

	/**
	 * Add the files closed in this step to the generation being built (one manifest write).
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function flush_closed( array $state ): array {
		$closed = (array) ( $state['closed'] ?? array() );
		if ( array() === $closed ) {
			return $state;
		}
		$m = Store::manifest();
		if ( is_array( $m['next'] ) && (string) ( $m['next']['gen'] ?? '' ) === (string) $state['gen'] ) {
			$m['next']['files'] = array_merge( (array) ( $m['next']['files'] ?? array() ), $closed );
			Store::save( $m );
		}
		$state['closed'] = array();

		return $state;
	}

	/**
	 * A manifest record from an open-file state.
	 *
	 * @param array<string, mixed> $f Open file.
	 * @return array<string, mixed>
	 */
	private static function record( array $f ): array {
		return array(
			'unit'    => (string) $f['unit'],
			'lang'    => (string) $f['lang'],
			'n'       => (int) $f['n'],
			'first'   => (array) $f['first'],
			'last'    => (array) $f['last'],
			'count'   => (int) $f['count'],
			'lastmod' => (int) $f['lastmod'],
			'bytes'   => (int) $f['bytes'],
			'images'  => (int) $f['images'],
			'videos'  => (int) $f['videos'],
		);
	}

	/**
	 * Append entries to their language's open file, opening and closing files as they fill.
	 *
	 * @param array<string, mixed>             $state   State.
	 * @param string                           $unit    Unit.
	 * @param array<int, array<string, mixed>> $entries Entries (each with `lang`, `key`, `xml`).
	 * @return array<string, mixed>
	 */
	private static function write( array $state, string $unit, array $entries ): array {
		$per     = self::per_file();
		$buffers = array();
		foreach ( $entries as $e ) {
			$lang = (string) $e['lang'];
			$f    = $state['open'][ $lang ] ?? null;
			if ( is_array( $f ) && (int) $f['count'] >= $per ) {
				if ( isset( $buffers[ $lang ] ) ) {
					file_put_contents( Store::path( (string) $state['gen'], (string) $f['name'] ), $buffers[ $lang ], FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file.
					unset( $buffers[ $lang ] );
				}
				$n                      = (int) $f['n'];
				$state                  = self::close( $state, $lang );
				$f                      = null;
				$state['open'][ $lang ] = self::opened( $state, $unit, $lang, $n + 1, $e['key'] );
			} elseif ( ! is_array( $f ) ) {
				$state['open'][ $lang ] = self::opened( $state, $unit, $lang, self::next_n( $state, $unit, $lang ), $e['key'] );
			}
			$f                      = $state['open'][ $lang ];
			$f['count']            += 1;
			$f['last']              = $e['key'];
			$f['lastmod']           = max( (int) $f['lastmod'], (int) $e['lastmod'] );
			$f['images']           += (int) $e['images'];
			$f['videos']           += (int) $e['videos'];
			$f['size']              = (int) ( $f['size'] ?? 0 ) + strlen( (string) $e['xml'] );
			$state['open'][ $lang ] = $f;
			$buffers[ $lang ]       = ( $buffers[ $lang ] ?? '' ) . $e['xml'];
			if ( ! empty( $e['foreign'] ) && count( $state['foreign'] ) < 50 ) {
				$state['foreign'][] = (string) $e['foreign'];
			}
		}
		foreach ( $buffers as $lang => $xml ) {
			file_put_contents( Store::path( (string) $state['gen'], (string) $state['open'][ $lang ]['name'] ), $xml, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file.
		}

		return $state;
	}

	/**
	 * The number the next file of a unit and language gets in the generation being built.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $unit  Unit.
	 * @param string               $lang  Language.
	 * @return int
	 */
	private static function next_n( array $state, string $unit, string $lang ): int {
		return (int) ( $state['last_n'][ $unit . '|' . $lang ] ?? 0 ) + 1;
	}

	/**
	 * Open (create) a file.
	 *
	 * @param array<string, mixed>     $state State.
	 * @param string                   $unit  Unit.
	 * @param string                   $lang  Language.
	 * @param int                      $n     Number.
	 * @param array{0: string, 1: int} $first Key of its first row.
	 * @return array<string, mixed> The open-file state.
	 */
	private static function opened( array $state, string $unit, string $lang, int $n, array $first ): array {
		$name = Store::name( $unit, $lang, $n );
		$dir  = Store::base() . $state['gen'];
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$head = Writer::open( self::uses() );
		file_put_contents( $dir . '/' . $name . '.xml', $head ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file.

		return array(
			'name'    => $name,
			'size'    => strlen( $head ),
			'unit'    => $unit,
			'lang'    => $lang,
			'n'       => $n,
			'first'   => $first,
			'last'    => $first,
			'count'   => 0,
			'lastmod' => 0,
			'bytes'   => 0,
			'images'  => 0,
			'videos'  => 0,
		);
	}

	/**
	 * The namespaces the files declare.
	 *
	 * @return array<string, bool>
	 */
	private static function uses(): array {
		/**
		 * Filters the XML namespaces sitemap files declare (the Pro video sitemap adds `video`).
		 *
		 * @param array<string, bool> $uses Namespaces.
		 */
		return (array) apply_filters(
			'zinn_seo_sitemap_namespaces',
			array(
				'image' => (bool) Settings::get( 'sitemap_images', true ),
				'xhtml' => self::languages_on(),
			)
		);
	}

	// ── Incremental refresh ───────────────────────────────────────────────────────────────────

	/**
	 * One step of the incremental refresh: the queue is Refresh's, the files and the manifest ours.
	 *
	 * @param array<string, mixed> $work     The work list (after `Refresh::intake()`).
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed> The step's answer to Jobs (state, done, progress, wait).
	 */
	private static function refresh_step( array $work, float $deadline ): array {
		$out  = Refresh::run(
			$work,
			$deadline,
			static function ( string $unit, string $lang, int $n ): bool {
				$t = self::current_target( array( $unit, $lang, $n ) );
				return self::rebuild_file( $t[0], $t[1], $t[2] );
			}
		);
		$work = $out['work'];
		if ( $out['rebuilt'] > 0 ) {
			$m              = Store::manifest();
			$m['refreshed'] = time();
			Store::save( $m );
			do_action( 'zinn_seo_sitemap_changed', $m ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in finish().
		}
		$answer = array(
			'state'    => array(),
			// Marks made by a listener of the action above wait for the next step.
			'done'     => $out['over'] && array() === Store::dirty(),
			'progress' => array(
				'done'  => (int) $work['done'],
				'total' => (int) $work['done'] + count( (array) $work['files'] ),
				'unit'  => 'files',
			),
		);
		if ( null !== $out['runaway'] ) {
			$answer['wait']   = (int) $out['runaway']['wait'];
			$answer['reason'] = self::runaway( $out['runaway'] );
		} elseif ( $answer['done'] ) {
			self::clear_runaway(); // The refresh ended on its own: a guard report is history.
		}

		return $answer;
	}

	/**
	 * The file a work-list entry names, as the manifest has it NOW: a tail file that has since
	 * gone (its pages were deleted) is answered by the unit's last file, never by a file number
	 * that would re-read the unit from its start.
	 *
	 * @param array<int, mixed> $t [unit, language, number].
	 * @return array{0: string, 1: string, 2: int}
	 */
	private static function current_target( array $t ): array {
		$unit = (string) ( $t[0] ?? '' );
		$lang = (string) ( $t[1] ?? '' );
		$n    = max( 1, (int) ( $t[2] ?? 1 ) );
		$last = 0;
		foreach ( (array) Store::manifest()['files'] as $f ) {
			if ( (string) $f['unit'] === $unit && (string) $f['lang'] === $lang ) {
				$last = max( $last, (int) $f['n'] );
			}
		}

		return array( $unit, $lang, $n > $last ? max( 1, $last ) : $n );
	}

	/**
	 * Record that the guard stopped the refresh, for the health screen and the job's own line.
	 *
	 * @param array<string, mixed> $r What Refresh::run() saw: file, times, distinct, left, minutes.
	 * @return string The reason, for the job record.
	 */
	private static function runaway( array $r ): string {
		$m           = Store::manifest();
		$errors      = array_values( array_filter( (array) $m['errors'], static fn( $e ): bool => 'runaway' !== (string) ( $e['code'] ?? '' ) ) );
		$errors[]    = array(
			'code'   => 'runaway',
			'file'   => (string) $r['file'],
			// Rebuilds of this file | distinct files rebuilt | files still waiting | minutes.
			'detail' => implode( '|', array( (int) $r['times'], (int) $r['distinct'], (int) $r['left'], (int) $r['minutes'] ) ),
		);
		$m['errors'] = $errors;
		Store::save( $m );

		return sprintf(
			/* translators: 1: a sitemap file name, 2: a number of times, 3: a number of minutes. */
			_n( 'The sitemap file %1$s was rebuilt %2$s times in %3$s minute.', 'The sitemap file %1$s was rebuilt %2$s times in %3$s minutes.', (int) $r['minutes'], 'zinn-seo' ),
			(string) $r['file'],
			number_format_i18n( (int) $r['times'] ),
			number_format_i18n( (int) $r['minutes'] )
		) . ' ' . __( 'Something keeps changing its pages, so the refresh is resting and then carries on by itself.', 'zinn-seo' );
	}

	/**
	 * A refresh ended on its own: the guard's report no longer describes anything.
	 *
	 * @return void
	 */
	private static function clear_runaway(): void {
		$m    = Store::manifest();
		$kept = array_values( array_filter( (array) $m['errors'], static fn( $e ): bool => 'runaway' !== (string) ( $e['code'] ?? '' ) ) );
		if ( count( $kept ) !== count( (array) $m['errors'] ) ) {
			$m['errors'] = $kept;
			Store::save( $m );
		}
	}

	/**
	 * Rebuild one file in the live generation: its rows are re-read between its bounds (the last
	 * file of a unit reads to the end, and splits into new files when it fills up).
	 *
	 * @param string $unit Unit.
	 * @param string $lang Language.
	 * @param int    $n    File number.
	 * @return bool False when a middle file grew past the limit (a full build is needed).
	 */
	public static function rebuild_file( string $unit, string $lang, int $n ): bool {
		$m    = Store::manifest();
		$gen  = (string) $m['gen'];
		$name = Store::name( $unit, $lang, $n );
		$own  = $m['files'][ $name ] ?? null;
		$tail = true;
		foreach ( (array) $m['files'] as $f ) {
			if ( (string) $f['unit'] === $unit && (string) $f['lang'] === $lang && (int) $f['n'] > $n ) {
				$tail = false;
			}
		}
		$from  = is_array( $own ) ? (array) $own['first'] : array( '', 0 );
		$until = ( is_array( $own ) && ! $tail ) ? (array) $own['last'] : null;
		// The first file also takes rows before its first row (a back-dated post).
		if ( 1 === $n ) {
			$from = array( '', 0 );
		}
		// Rows between this file and the next belong here too.
		if ( ! $tail ) {
			$next = $m['files'][ Store::name( $unit, $lang, $n + 1 ) ] ?? null;
			if ( is_array( $next ) ) {
				$until = array( (string) $next['first'][0], (int) $next['first'][1] - 1 );
			}
		}
		$per     = self::per_file();
		$limit   = $tail ? $per : Writer::MAX_URLS;
		$after   = array( (string) $from[0], (int) $from[1] - 1 );
		$current = $n;
		$files   = array();
		$f       = null;
		$tmp     = '';
		$base    = Store::base();
		if ( '' === $gen || '' === $base ) {
			return true;
		}
		while ( true ) {
			$rows = self::read( $unit, $after, $until, $lang );
			if ( array() === $rows ) {
				break;
			}
			foreach ( self::entries( $unit, $rows ) as $e ) {
				if ( (string) $e['lang'] !== $lang ) {
					continue;
				}
				if ( null !== $f && (int) $f['count'] >= $limit ) {
					if ( ! $tail ) {
						return false;
					}
					$files[] = self::seal( $f, $tmp );
					++$current;
					$f = null;
				}
				if ( null === $f ) {
					$f   = array(
						'name'    => Store::name( $unit, $lang, $current ),
						'unit'    => $unit,
						'lang'    => $lang,
						'n'       => $current,
						'first'   => 1 === $current ? array( '', 0 ) : $e['key'],
						'last'    => $e['key'],
						'count'   => 0,
						'lastmod' => 0,
						'bytes'   => 0,
						'images'  => 0,
						'videos'  => 0,
					);
					$tmp = $base . $gen . '/' . $f['name'] . '.tmp';
					file_put_contents( $tmp, Writer::open( self::uses() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file.
				}
				file_put_contents( $tmp, $e['xml'], FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file.
				++$f['count'];
				$f['last']    = $e['key'];
				$f['lastmod'] = max( (int) $f['lastmod'], (int) $e['lastmod'] );
				$f['images'] += (int) $e['images'];
				$f['videos'] += (int) $e['videos'];
			}
			$after = self::key_of( $unit, end( $rows ) );
			self::forget();
		}
		if ( null !== $f ) {
			$files[] = self::seal( $f, $tmp );
		}
		$m = Store::manifest();
		// The file (and, for the tail, every file after it) is replaced by what was written.
		foreach ( (array) $m['files'] as $old => $rec ) {
			if ( (string) $rec['unit'] === $unit && (string) $rec['lang'] === $lang && ( (int) $rec['n'] === $n || ( $tail && (int) $rec['n'] > $n ) ) ) {
				unset( $m['files'][ $old ] );
				$written = false;
				foreach ( $files as $nf ) {
					$written = $written || $nf['name'] === $old;
				}
				if ( ! $written ) {
					wp_delete_file( $base . $gen . '/' . $old . '.xml' );
				}
			}
		}
		foreach ( $files as $nf ) {
			if ( null !== $own && $nf['name'] === $name && ! $tail ) {
				$nf['last'] = (array) $own['last'];
			}
			$m['files'][ (string) $nf['name'] ] = self::record( $nf );
		}
		Store::save( $m );

		return true;
	}

	/**
	 * Close a rebuilt file and move it over the old one.
	 *
	 * @param array<string, mixed> $f   Open file.
	 * @param string               $tmp Its temporary path.
	 * @return array<string, mixed>
	 */
	private static function seal( array $f, string $tmp ): array {
		file_put_contents( $tmp, Writer::close(), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own sitemap file.
		$final = substr( $tmp, 0, -4 ) . '.xml';
		rename( $tmp, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- an atomic swap of our own file: a reader sees the old file or the new one, never half of one.
		$f['bytes'] = (int) filesize( $final );

		return $f;
	}

	// ── Reading ───────────────────────────────────────────────────────────────────────────────

	/**
	 * The keyset key of a row: `[post_date, ID]` for posts, `['', term_id]` for terms, `['', ID]`
	 * for authors.
	 *
	 * @param string $unit Unit.
	 * @param mixed  $row  Row.
	 * @return array{0: string, 1: int}
	 */
	public static function key_of( string $unit, $row ): array {
		if ( 0 === strpos( $unit, 'post:' ) ) {
			return array( (string) $row->post_date, (int) $row->ID );
		}
		if ( 0 === strpos( $unit, 'tax:' ) ) {
			return array( '', (int) $row->term_id );
		}
		if ( 'home' === $unit ) {
			return array( '', (int) ( $row->n ?? 0 ) );
		}

		return array( '', (int) $row->ID );
	}

	/**
	 * One page of rows after a key (and up to a key), optionally one language only.
	 *
	 * @param string                 $unit  Unit.
	 * @param array<int, mixed>      $after Key to read after.
	 * @param array<int, mixed>|null $until Key to stop at (inclusive), or null.
	 * @param string|null            $lang  Language ('' = default), or null for all.
	 * @return array<int, object>
	 */
	public static function read( string $unit, array $after, ?array $until, ?string $lang ): array {
		global $wpdb;
		if ( 'home' === $unit ) {
			return (int) ( $after[1] ?? 0 ) <= 0 ? array( (object) array( 'n' => 1 ) ) : array();
		}
		if ( 0 === strpos( $unit, 'x:' ) ) {
			$source = self::sources()[ substr( $unit, 2 ) ] ?? null;
			// One language only: these addresses belong to the site's default language.
			if ( null === $source || ( null !== $lang && '' !== $lang ) ) {
				return array();
			}
			$rows = call_user_func( $source['read'], (int) ( $after[1] ?? 0 ), null !== $until ? (int) $until[1] : 0, self::BATCH );

			return is_array( $rows ) ? array_values( array_filter( $rows, static fn( $row ): bool => is_object( $row ) && isset( $row->ID ) ) ) : array();
		}
		$join  = '';
		$where = '';
		$args  = array();
		$rel   = self::relations_table();
		if ( 0 === strpos( $unit, 'post:' ) ) {
			$type   = substr( $unit, 5 );
			$status = 'attachment' === $type ? 'inherit' : 'publish';
			$sql    = "SELECT p.*{{lang}} FROM {$wpdb->posts} p{{join}} WHERE p.post_type = %s AND p.post_status = %s AND p.post_password = '' AND p.post_date >= %s AND ( p.post_date > %s OR p.ID > %d ){{where}} ORDER BY p.post_date ASC, p.ID ASC LIMIT %d";
			$args   = array( $type, $status, (string) $after[0], (string) $after[0], (int) $after[1] );
			if ( null !== $until ) {
				$where .= ' AND ( p.post_date < %s OR ( p.post_date = %s AND p.ID <= %d ) )';
				array_push( $args, (string) $until[0], (string) $until[0], (int) $until[1] );
			}
			$object = 'post';
			$id     = 'p.ID';
		} elseif ( 0 === strpos( $unit, 'tax:' ) ) {
			$min  = self::lists_empty( substr( $unit, 4 ) ) ? -1 : 0;
			$sql  = "SELECT t.*, tt.*{{lang}} FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id{{join}} WHERE tt.taxonomy = %s AND tt.count > {$min} AND t.term_id > %d{{where}} ORDER BY t.term_id ASC LIMIT %d";
			$args = array( substr( $unit, 4 ), (int) $after[1] );
			if ( null !== $until ) {
				$where .= ' AND t.term_id <= %d';
				$args[] = (int) $until[1];
			}
			$object = 'term';
			$id     = 't.term_id';
		} else {
			$types = array_map(
				static fn( string $u ): string => substr( $u, 5 ),
				array_filter( self::units(), static fn( string $u ): bool => 0 === strpos( $u, 'post:' ) && 'post:attachment' !== $u && 'post:page' !== $u )
			);
			if ( array() === $types ) {
				return array();
			}
			$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$sql  = "SELECT u.ID, u.user_nicename, u.display_name FROM {$wpdb->users} u WHERE u.ID > %d{{where}} AND EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.post_author = u.ID AND p.post_status = 'publish' AND p.post_type IN ({$in}) ) ORDER BY u.ID ASC LIMIT %d";
			$args = array_merge( array( (int) $after[1] ), $types );
			if ( null !== $until ) {
				$where .= ' AND u.ID <= %d';
				array_splice( $args, 1, 0, array( (int) $until[1] ) );
			}
			$object = '';
			$id     = 'u.ID';
		}
		$select_lang = '';
		if ( '' !== $object && '' !== $rel ) {
			$select_lang = ', zr.lang AS zseo_lang';
			$join        = " LEFT JOIN {$rel} zr ON zr.object_type = '{$object}' AND zr.object_id = {$id}";
			if ( null !== $lang ) {
				if ( '' === $lang ) {
					$where .= ' AND ( zr.lang IS NULL OR zr.lang = %s )';
					$args[] = self::default_language();
				} else {
					$where .= ' AND zr.lang = %s';
					$args[] = $lang;
				}
			}
		}
		$args[] = self::BATCH;
		$sql    = str_replace( array( '{{lang}}', '{{join}}', '{{where}}' ), array( $select_lang, $join, $where ), $sql );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built above from fixed fragments and table names; every value is a placeholder.

		return is_array( $rows ) ? $rows : array();
	}

	// ── Entries ───────────────────────────────────────────────────────────────────────────────

	/**
	 * Turn a page of rows into sitemap entries (excluded rows dropped).
	 *
	 * @param string             $unit Unit.
	 * @param array<int, object> $rows Rows.
	 * @return array<int, array<string, mixed>> Each: lang, key, xml, lastmod, images, videos, foreign.
	 */
	public static function entries( string $unit, array $rows ): array {
		if ( 'home' === $unit ) {
			return self::home_entries();
		}
		if ( 0 === strpos( $unit, 'tax:' ) ) {
			return self::term_entries( $unit, $rows );
		}
		if ( 'authors' === $unit ) {
			return self::author_entries( $unit, $rows );
		}
		if ( 0 === strpos( $unit, 'x:' ) ) {
			$source = self::sources()[ substr( $unit, 2 ) ] ?? null;
			$out    = array();
			foreach ( null === $source ? array() : $rows as $row ) {
				$entry = (array) call_user_func( $source['entry'], $row );
				if ( '' !== (string) ( $entry['loc'] ?? '' ) ) {
					$out[] = self::entry_record( $unit, $row, $entry, '' );
				}
			}
			return $out;
		}

		return self::post_entries( $unit, $rows );
	}

	/**
	 * Has a content type at least one published item? (WordPress's own cached count.)
	 *
	 * @param string $type Content type.
	 * @return bool
	 */
	public static function has_published( string $type ): bool {
		$counts = wp_count_posts( $type );

		return is_object( $counts ) && (int) ( $counts->publish ?? 0 ) > 0;
	}

	/**
	 * The `home` unit: the home page when it lists posts, each language's home page (Tranzly),
	 * and the archive page of each included post type that has one.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function home_entries(): array {
		$out     = array();
		$lastmod = (int) strtotime( (string) get_lastpostmodified( 'gmt' ) . ' UTC' );
		$urls    = array();
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			$urls[] = home_url( '/' );
			if ( class_exists( '\ZinnDigital\Tranzly\Seo\Sitemaps' ) && method_exists( '\ZinnDigital\Tranzly\Seo\Sitemaps', 'extra_homes' ) ) {
				foreach ( (array) \ZinnDigital\Tranzly\Seo\Sitemaps::extra_homes() as $url ) {
					$urls[] = (string) $url;
				}
			}
			/**
			 * Filters the home pages of the site's other languages, which the sitemap lists
			 * beside the main one (a translation plugin adds its own, such as /fr/).
			 *
			 * @param array<int, string> $homes Addresses.
			 */
			foreach ( (array) apply_filters( 'zinn_seo_sitemap_extra_homes', array() ) as $url ) {
				if ( is_string( $url ) && '' !== $url && ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}
		foreach ( self::units() as $unit ) {
			if ( 0 !== strpos( $unit, 'post:' ) ) {
				continue;
			}
			$type = get_post_type_object( substr( $unit, 5 ) );
			if ( $type instanceof \WP_Post_Type && $type->has_archive && 'post' !== $type->name ) {
				if ( 'noindex' === (string) Templates::effective( 'archive:' . $type->name )['index'] ) {
					continue;
				}
				// An archive with nothing in it is a thin page: not listed, as an empty term is
				// not (an empty /locations/ was, found by the importer certification, Q254).
				if ( ! self::has_published( $type->name ) ) {
					continue;
				}
				$link = get_post_type_archive_link( $type->name );
				if ( is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}
		$pages = in_array( 'post:page', self::units(), true );
		$n     = 0;
		foreach ( self::unclaimed( $urls, static fn( string $url ): bool => $pages && self::held_by_page( $url ) ) as $url ) {
			$out[] = self::entry_record(
				'home',
				(object) array( 'n' => ++$n ),
				array(
					'loc'     => $url,
					'lastmod' => $lastmod,
				),
				''
			);
		}

		return $out;
	}

	/**
	 * The home file's addresses, each once, leaving out an address a listed page already holds:
	 * WooCommerce's Shop page IS the product archive's address, and the page's own entry lists it
	 * (with its own date and images), so the archive's copy would be the same address twice. Pure,
	 * for the tests.
	 *
	 * @param array<int, string> $urls Home and archive addresses, in order.
	 * @param callable           $held Whether this address is a page the sitemap lists (string in, bool out).
	 * @return array<int, string>
	 */
	public static function unclaimed( array $urls, callable $held ): array {
		$out  = array();
		$seen = array();
		foreach ( $urls as $url ) {
			$url = (string) $url;
			$key = untrailingslashit( $url );
			if ( '' === $url || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( ! $held( $url ) ) {
				$out[] = $url;
			}
		}

		return $out;
	}

	/**
	 * Is this address a published page that the sitemap lists in its own right: same address,
	 * not left out by its id, not set to noindex? Only then may the archive's copy be dropped —
	 * a Shop page that is itself kept out of search still leaves the archive listed.
	 *
	 * @param string $url An archive address.
	 * @return bool
	 */
	private static function held_by_page( string $url ): bool {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path . '/', $base . '/' ) ) {
			$path = trim( substr( $path, strlen( $base ) ), '/' );
		}
		if ( '' === $path ) {
			return false;
		}
		$page = get_page_by_path( $path, OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status || '' !== (string) $page->post_password ) {
			return false;
		}
		if ( untrailingslashit( (string) get_permalink( $page ) ) !== untrailingslashit( $url ) ) {
			return false;
		}
		if ( in_array( (string) $page->ID, self::excluded_ids(), true ) ) {
			return false;
		}

		return ! isset( Resolver::robots( Context::for_post( $page ) )['noindex'] );
	}

	/**
	 * Term entries.
	 *
	 * @param string             $unit Unit.
	 * @param array<int, object> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function term_entries( string $unit, array $rows ): array {
		$ids   = array_map( static fn( $r ): int => (int) $r->term_id, $rows );
		$clean = array();
		foreach ( $rows as $row ) {
			$copy = clone $row;
			unset( $copy->zseo_lang );
			$clean[] = $copy;
		}
		update_term_cache( $clean );
		$terms = array();
		foreach ( $ids as $id ) {
			$terms[] = get_term( $id );
		}
		update_termmeta_cache( $ids );
		// Parent terms appear in the addresses of hierarchical taxonomies.
		$parents = array_filter( array_map( static fn( $r ): int => (int) $r->parent, $rows ) );
		if ( array() !== $parents ) {
			_prime_term_caches( array_values( array_unique( $parents ) ), false );
		}
		self::prime_translations( 'term', $ids );
		$exclude = self::excluded_ids();
		$out     = array();
		foreach ( $terms as $i => $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$c = Context::for_term( $term );
			if ( isset( Resolver::robots( $c )['noindex'] ) ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$canonical = (string) Resolver::own( $c, 'canonical' );
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $link ) ) {
				continue;
			}
			if ( in_array( 't' . $term->term_id, $exclude, true ) ) {
				continue;
			}
			$entry = array( 'loc' => $link );
			$lang  = self::language_of( 'term', (int) $term->term_id, $rows[ $i ] );
			$entry = (array) apply_filters( 'zinn_seo_sitemap_term_entry', $entry, $term );
			if ( array() === $entry || empty( $entry['loc'] ) ) {
				continue;
			}
			$out[] = self::entry_record( $unit, $rows[ $i ], $entry, $lang );
		}

		return $out;
	}

	/**
	 * Author entries: only authors whose page search engines should find — not one hidden from
	 * search, moved to another address (its canonical) or redirected (zs-756). The query already
	 * keeps only authors with published posts.
	 *
	 * @param string             $unit Unit.
	 * @param array<int, object> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function author_entries( string $unit, array $rows ): array {
		$ids = array_map( static fn( $r ): int => (int) $r->ID, $rows );
		if ( array() !== $ids ) {
			update_meta_cache( 'user', $ids );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$link = get_author_posts_url( (int) $row->ID, (string) $row->user_nicename );
			if ( ! self::author_listed( (int) $row->ID, $link ) ) {
				continue;
			}
			$out[] = self::entry_record( $unit, $row, array( 'loc' => $link ), '' );
		}

		return $out;
	}

	/**
	 * Does an author's page belong in the sitemap? Not when hidden from search, redirected, or
	 * pointing search engines at another address.
	 *
	 * @param int    $user_id Author.
	 * @param string $link    The author page's address.
	 * @return bool
	 */
	public static function author_listed( int $user_id, string $link ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		if ( '' !== trim( (string) get_user_meta( $user_id, \ZinnDigital\ZinnSEO\Meta::key( 'redirect' ), true ) ) ) {
			return false;
		}
		$c         = Context::for_key( 'author' );
		$c->object = $user;
		if ( isset( Resolver::robots( $c )['noindex'] ) ) {
			return false;
		}
		$canonical = (string) Resolver::own( $c, 'canonical' );

		return '' === $canonical || untrailingslashit( $canonical ) === untrailingslashit( $link );
	}

	/**
	 * Post entries: permalinks, honest last-modified dates, images, translations.
	 *
	 * @param string             $unit Unit.
	 * @param array<int, object> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function post_entries( string $unit, array $rows ): array {
		$type  = substr( $unit, 5 );
		$posts = array();
		$ids   = array();
		foreach ( $rows as $row ) {
			$copy = clone $row;
			unset( $copy->zseo_lang );
			$post    = new \WP_Post( $copy );
			$posts[] = $post;
			$ids[]   = (int) $post->ID;
		}
		update_post_cache( $posts );
		update_meta_cache( 'post', $ids );
		if ( array() !== get_object_taxonomies( $type ) ) {
			update_object_term_cache( $ids, $type );
		}
		self::prime_parents( $posts );
		$groups = self::prime_translations( 'post', $ids );
		$images = (bool) Settings::get( 'sitemap_images', true ) ? self::images( $posts ) : array();
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$hide   = 'product' === $type ? self::woo_hidden( $ids ) : array();
		$skip   = self::excluded_ids();
		$out    = array();
		foreach ( $posts as $i => $post ) {
			if ( in_array( (string) $post->ID, $skip, true ) || isset( $hide[ $post->ID ] ) ) {
				continue;
			}
			$c = Context::for_post( $post );
			if ( isset( Resolver::robots( $c )['noindex'] ) ) {
				continue;
			}
			$link = (string) get_permalink( $post );
			if ( '' === $link ) {
				continue;
			}
			$canonical = (string) Resolver::own( $c, 'canonical' );
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $link ) ) {
				continue; // zs-048: the page names another page as the one to index.
			}
			$entry = array(
				'loc'     => $link,
				'lastmod' => self::lastmod( $post ),
				'images'  => $images[ $post->ID ] ?? array(),
			);
			$lang  = self::language_of( 'post', (int) $post->ID, $rows[ $i ] );
			if ( isset( $groups[ $post->ID ] ) && count( $groups[ $post->ID ] ) > 1 && (bool) Settings::get( 'sitemap_alternates', true ) ) {
				$entry['alternates'] = self::alternates( $groups[ $post->ID ] );
			}

			/**
			 * Filters one post's sitemap entry. Return an empty array to leave the post out (the
			 * redirects module drops redirected posts here; the Pro sitemap adds videos).
			 *
			 * @param array<string, mixed> $entry `loc`, `lastmod`, `images`, `alternates`.
			 * @param \WP_Post             $post  The post.
			 */
			$entry = (array) apply_filters( 'zinn_seo_sitemap_entry', $entry, $post );
			if ( array() === $entry || empty( $entry['loc'] ) ) {
				continue;
			}
			$record = self::entry_record( $unit, $rows[ $i ], $entry, $lang );
			$host   = (string) wp_parse_url( (string) $entry['loc'], PHP_URL_HOST );
			if ( '' !== $home && strtolower( $host ) !== strtolower( $home ) && ! self::allowed_host( $host ) ) {
				$record['foreign'] = (string) $entry['loc'];
			}
			$out[] = $record;
		}

		return $out;
	}

	/**
	 * Another host a translation plugin legitimately serves languages on (Tranzly domains mode).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function allowed_host( string $host ): bool {
		/**
		 * Filters whether sitemap addresses on another host are expected (a language served on
		 * its own domain). Others are reported on the health screen as a possible staging leak.
		 *
		 * @param bool   $allowed Allowed.
		 * @param string $host    The host.
		 */
		return (bool) apply_filters( 'zinn_seo_sitemap_allowed_host', false, $host );
	}

	/**
	 * Build the stored record for an entry.
	 *
	 * @param string               $unit  Unit.
	 * @param object               $row   Row.
	 * @param array<string, mixed> $entry Entry.
	 * @param string               $lang  Language.
	 * @return array<string, mixed>
	 */
	private static function entry_record( string $unit, $row, array $entry, string $lang ): array {
		return array(
			'lang'    => $lang,
			'key'     => self::key_of( $unit, $row ),
			'xml'     => Writer::entry( $entry ),
			'lastmod' => (int) ( $entry['lastmod'] ?? 0 ),
			'images'  => count( (array) ( $entry['images'] ?? array() ) ),
			'videos'  => count( (array) ( $entry['videos'] ?? array() ) ),
			'foreign' => '',
		);
	}

	/**
	 * The honest last-modified time of a post (zs-047): the time its CONTENT last changed. When a
	 * save changed nothing a reader sees (a meta box, a menu order), the time of the save before
	 * is kept in `_zseo_lastmod` (Lastmod::on_update), so the sitemap does not tell search
	 * engines to recrawl an unchanged page.
	 *
	 * @param \WP_Post $post Post.
	 * @return int Unix time.
	 */
	public static function lastmod( \WP_Post $post ): int {
		$kept     = (string) get_post_meta( (int) $post->ID, '_zseo_lastmod', true );
		$modified = '' !== $kept ? $kept : (string) $post->post_modified_gmt;
		$time     = (int) strtotime( $modified . ' UTC' );
		$created  = (int) strtotime( (string) $post->post_date_gmt . ' UTC' );

		return max( $time, $created, 0 );
	}

	/**
	 * Image addresses per post (zs-042): the featured image, a shop product's gallery and the
	 * images in the text, read for the whole page of posts in ONE query, never per image.
	 *
	 * @param array<int, \WP_Post> $posts Posts (their meta primed).
	 * @return array<int, array<int, string>> Post ID => addresses.
	 */
	public static function images( array $posts ): array {
		global $wpdb;
		$max     = max( 1, min( 1000, (int) Settings::get( 'sitemap_images_max', 20 ) ) );
		$want    = array();
		$direct  = array();
		$all_ids = array();
		$uploads = wp_get_upload_dir();
		$baseurl = (string) $uploads['baseurl'];
		$home    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $posts as $post ) {
			$ids   = array();
			$thumb = (int) get_post_meta( (int) $post->ID, '_thumbnail_id', true );
			if ( $thumb > 0 ) {
				$ids[] = $thumb;
			}
			$gallery = (string) get_post_meta( (int) $post->ID, '_product_image_gallery', true );
			foreach ( array_filter( array_map( 'intval', explode( ',', $gallery ) ) ) as $id ) {
				$ids[] = $id;
			}
			$found = Media::images( (string) $post->post_content );
			$ids   = array_values( array_unique( array_merge( $ids, $found['ids'] ) ) );
			$urls  = array();
			foreach ( $found['urls'] as $url ) {
				// Only this site's own images (a hotlinked picture is not this page's to claim).
				if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( $home ) || 0 === strpos( $url, $baseurl ) ) {
					$urls[] = $url;
				}
			}
			$want[ $post->ID ]   = $ids;
			$direct[ $post->ID ] = $urls;
			$all_ids             = array_merge( $all_ids, $ids );
		}
		$files   = array();
		$all_ids = array_values( array_unique( array_filter( $all_ids ) ) );
		if ( array() !== $all_ids ) {
			$in   = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ({$in})", $all_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a run of %d, one per ID.
			foreach ( (array) $rows as $row ) {
				$files[ (int) $row->post_id ] = Media::file_url( (string) $row->meta_value, $baseurl );
			}
		}
		$out = array();
		foreach ( $want as $post_id => $ids ) {
			$list = array();
			foreach ( $ids as $id ) {
				if ( isset( $files[ $id ] ) && '' !== $files[ $id ] ) {
					$list[] = $files[ $id ];
				}
			}
			$list            = array_values( array_unique( array_merge( $list, $direct[ $post_id ] ) ) );
			$out[ $post_id ] = array_slice( $list, 0, $max );
		}

		return $out;
	}

	/**
	 * Shop products a visitor cannot find (catalogue visibility "hidden") and, when the site
	 * chose so, products out of stock (zs-050). Read from the terms primed with the page.
	 *
	 * @param array<int, int> $ids Product IDs.
	 * @return array<int, bool> Hidden IDs.
	 */
	private static function woo_hidden( array $ids ): array {
		$out   = array();
		$stock = (bool) Settings::get( 'sitemap_woo_outofstock', true );
		foreach ( $ids as $id ) {
			$terms = get_the_terms( $id, 'product_visibility' );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			$slugs = wp_list_pluck( $terms, 'slug' );
			if ( ( in_array( 'exclude-from-catalog', $slugs, true ) && in_array( 'exclude-from-search', $slugs, true ) ) || ( ! $stock && in_array( 'outofstock', $slugs, true ) ) ) {
				$out[ $id ] = true;
			}
		}

		return $out;
	}

	/**
	 * Prime the parents (and their parents) of hierarchical posts, so their addresses cost no
	 * query each.
	 *
	 * @param array<int, \WP_Post> $posts Posts.
	 * @return void
	 */
	private static function prime_parents( array $posts ): void {
		$ids = array();
		foreach ( $posts as $post ) {
			if ( (int) $post->post_parent > 0 ) {
				$ids[] = (int) $post->post_parent;
			}
		}
		for ( $depth = 0; $depth < 20 && array() !== $ids; $depth++ ) {
			$ids = array_values( array_unique( $ids ) );
			_prime_post_caches( $ids, false, false );
			$next = array();
			foreach ( $ids as $id ) {
				$parent = get_post( $id );
				if ( $parent instanceof \WP_Post && (int) $parent->post_parent > 0 && ! wp_cache_get( (int) $parent->post_parent, 'posts' ) ) {
					$next[] = (int) $parent->post_parent;
				}
			}
			$ids = $next;
		}
	}

	// ── Languages (Tranzly, zs-046) ─────────────────────────────────────────────────────────

	/**
	 * Does the site have more than one language through Tranzly?
	 *
	 * @return bool
	 */
	public static function languages_on(): bool {
		return function_exists( 'tranzly_languages' ) && count( (array) tranzly_languages() ) > 1 && '' !== self::relations_table();
	}

	/**
	 * Tranzly's translation-groups table, or '' when Tranzly is not active here.
	 *
	 * @return string
	 */
	public static function relations_table(): string {
		if ( ! function_exists( 'tranzly_languages' ) || ! class_exists( '\ZinnDigital\Tranzly\Core\Schema' ) || ! method_exists( '\ZinnDigital\Tranzly\Core\Schema', 'tables' ) ) {
			return '';
		}
		if ( method_exists( '\ZinnDigital\Tranzly\Core\Schema', 'live' ) && ! \ZinnDigital\Tranzly\Core\Schema::live() ) {
			return '';
		}
		$table = (string) ( \ZinnDigital\Tranzly\Core\Schema::tables()['relations'] ?? '' );

		return 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table ) ? $table : '';
	}

	/**
	 * The default language's code.
	 *
	 * @return string
	 */
	public static function default_language(): string {
		return function_exists( 'tranzly_default_language' ) ? (string) tranzly_default_language() : '';
	}

	/**
	 * The language a row is written in, as the file key: '' for the default language.
	 *
	 * @param string $type `post` or `term`.
	 * @param int    $id   ID.
	 * @param object $row  Row (carries `zseo_lang` from the join).
	 * @return string
	 */
	private static function language_of( string $type, int $id, $row ): string {
		unset( $type, $id );
		$lang = isset( $row->zseo_lang ) ? (string) $row->zseo_lang : '';
		if ( '' === $lang || ! self::languages_on() || self::default_language() === $lang ) {
			return '';
		}

		return $lang;
	}

	/**
	 * Load the translation groups of a page of objects in one query, and the posts of every
	 * group member (for their addresses).
	 *
	 * @param string          $type `post` or `term`.
	 * @param array<int, int> $ids  IDs.
	 * @return array<int, array<string, int>> ID => language => member ID.
	 */
	private static function prime_translations( string $type, array $ids ): array {
		if ( ! self::languages_on() || ! class_exists( '\ZinnDigital\Tranzly\Core\Relations' ) ) {
			return array();
		}
		\ZinnDigital\Tranzly\Core\Relations::prime( $type, $ids );
		$out     = array();
		$members = array();
		foreach ( $ids as $id ) {
			$map = (array) \ZinnDigital\Tranzly\Core\Relations::translations( $type, $id );
			if ( count( $map ) > 1 ) {
				$out[ $id ] = array_map( 'intval', $map );
				$members    = array_merge( $members, array_values( $map ) );
			}
		}
		$members = array_values( array_diff( array_unique( array_map( 'intval', $members ) ), $ids ) );
		if ( 'post' === $type && array() !== $members ) {
			_prime_post_caches( $members, true, true );
		} elseif ( 'term' === $type && array() !== $members ) {
			_prime_term_caches( $members, true );
		}

		return $out;
	}

	/**
	 * The hreflang => address map of a translation group (published members only), plus `x-default`.
	 *
	 * @param array<string, int> $group Language => post ID.
	 * @return array<string, string>
	 */
	private static function alternates( array $group ): array {
		$out     = array();
		$default = self::default_language();
		foreach ( $group as $lang => $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			$link = (string) get_permalink( $post );
			if ( '' === $link ) {
				continue;
			}
			$out[ self::hreflang( (string) $lang ) ] = $link;
			if ( (string) $lang === $default ) {
				$out['x-default'] = $link;
			}
		}

		return count( $out ) > 1 ? $out : array();
	}

	/**
	 * A WordPress locale as an hreflang value: `de_DE` → `de-DE`, `pt_BR_formal` → `pt-BR`.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function hreflang( string $locale ): string {
		$parts = explode( '_', str_replace( '-', '_', $locale ) );
		$out   = strtolower( (string) $parts[0] );
		if ( isset( $parts[1] ) && 1 === preg_match( '/^([A-Za-z]{2}|\d{3}|[A-Za-z]{4})$/', $parts[1] ) ) {
			$out .= '-' . ( 4 === strlen( $parts[1] ) ? ucfirst( strtolower( $parts[1] ) ) : strtoupper( $parts[1] ) );
		}

		return $out;
	}

	// ── Settings helpers ──────────────────────────────────────────────────────────────────────

	/**
	 * IDs the site listed to leave out: post IDs as strings, term IDs as `t<id>`.
	 *
	 * @return array<int, string>
	 */
	public static function excluded_ids(): array {
		static $cache = null;
		$raw          = (string) Settings::get( 'sitemap_exclude_ids', '' );
		if ( null !== $cache && $cache[0] === $raw ) {
			return $cache[1];
		}
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $raw ) as $part ) {
			$part = strtolower( trim( (string) $part ) );
			if ( 1 === preg_match( '/^t?\d+$/', $part ) ) {
				$out[] = $part;
			}
		}
		$cache = array( $raw, $out );

		return $out;
	}

	/**
	 * Empty the in-memory object cache after a page of rows (bounded memory at any site size).
	 *
	 * @return void
	 */
	private static function forget(): void {
		Resolver::flush();
		if ( class_exists( '\ZinnDigital\Tranzly\Core\Relations' ) && method_exists( '\ZinnDigital\Tranzly\Core\Relations', 'reset_memo' ) ) {
			\ZinnDigital\Tranzly\Core\Relations::reset_memo();
		}
		// ⛔ wp_cache_flush_runtime() complains (a "doing it wrong" notice) under an object cache
		// that cannot flush only its in-memory copy, so ask first.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} elseif ( ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	/**
	 * Does the sitemap list this taxonomy's terms that have no content yet (the site chose so;
	 * a site moving from Rank Math keeps its "include empty terms" choice)?
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function lists_empty( string $taxonomy ): bool {
		return in_array( $taxonomy, array_map( 'strval', (array) Settings::get( 'sitemap_empty_taxonomies', array() ) ), true );
	}
}
