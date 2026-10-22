<?php
/**
 * The incremental refresh's queue: which files a set of changes dirties, and the loop that
 * rebuilds them once each (zs-043).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ THIS LOOP ENDS, AND HOW MANY FILES IT WRITES IS BOUNDED (CLAUDE.md §2.10, §2.55).
 *
 * - The cursor is the work list (`Store::work()`). A file leaves it the moment it is rebuilt, and
 *   the list is saved after EVERY file: the next step — after the deadline, or after the host
 *   killed this one — starts at the first file not yet done. Nothing rebuilt is rebuilt again.
 * - A file enters the list only through a mark taken by `intake()`, at most once per taking. So
 *   with D files holding marked pages and no new marks, exactly D files are rebuilt; a mark made
 *   meanwhile adds at most one rebuild. The refresh ends when marks stop.
 * - Releases up to 0.9.1 took a mark off the list only when EVERY file had been rebuilt in ONE
 *   step. A site whose changed files needed two steps rebuilt the first step's worth for ever
 *   (demo.zinnseo.com, 2026-10-11: about 73,000 rebuilds in five hours, until it was cancelled).
 * - The guard (RUNAWAY) is for what no cursor can stop: marks that keep coming for the same file
 *   (something saving a page every time the sitemap changes). It counts rebuilds per file over a
 *   WINDOW of time, across refreshes — such a loop ends one refresh and starts the next — rests
 *   the job until the window has passed and says so on the health screen. Nothing is dropped:
 *   the marks and the list wait.
 *
 * The file work itself is handed in (`$rebuild`), so this class is the queue and nothing else.
 */
final class Refresh {

	/**
	 * The runaway guard: how often ONE file may be rebuilt within WINDOW seconds. Every rebuild
	 * needs a new mark for a page of that file, so only a site that keeps marking the same pages
	 * comes near it: a feedback loop (something saving a page whenever the sitemap changes) or
	 * an import writing several pages a second into one file. A person saving a post every
	 * twenty seconds all day stays under it.
	 */
	public const RUNAWAY = 50;

	/** The guard's window, and the longest the refresh rests when it stops. Marks made meanwhile are kept. */
	public const WINDOW = 900;

	/**
	 * Take the waiting marks into the durable work list: the files that hold the marked pages,
	 * each once, or "everything" when a `*` is among them.
	 *
	 * ⛔ Order: the work list is SAVED before the claim is released, so a step killed anywhere in
	 * here is handed the same marks again (adding a file twice changes nothing: it is a set).
	 *
	 * @param array<string, array<string, mixed>> $files The manifest's files.
	 * @param array<int, string>                  $units The units the sitemap has.
	 * @return array{full: bool, files: array<string, array{0: string, 1: string, 2: int}>, seen: array<string, array{0: int, 1: int}>, done: int, since: int}
	 */
	public static function intake( array $files, array $units ): array {
		$work = Store::work();
		// Twice: a claim left by a killed step first, then what has been marked since.
		for ( $pass = 0; $pass < 2; $pass++ ) {
			$marks = Store::claim();
			if ( array() === $marks ) {
				break;
			}
			if ( in_array( '*', $marks, true ) ) {
				$work['full'] = true;
			} elseif ( ! $work['full'] ) {
				foreach ( self::targets( $marks, $files, $units ) as $name => $target ) {
					$work['files'][ $name ] = $target; // A set: a file already listed is listed once.
				}
			}
			if ( 0 === $work['since'] ) {
				$work['since'] = time();
			}
			Store::save_work( $work );
			Store::release();
		}

		return $work;
	}

	/**
	 * The files that hold a list of marked pages: file name => [unit, language, number]. Pure.
	 *
	 * @param array<int, string>                  $marks Marks (`unit|lang|date|id`, or `home`).
	 * @param array<string, array<string, mixed>> $files The manifest's files.
	 * @param array<int, string>                  $units The units the sitemap has.
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function targets( array $marks, array $files, array $units ): array {
		$targets = array();
		foreach ( $marks as $mark ) {
			$parts = explode( '|', (string) $mark );
			$unit  = (string) $parts[0];
			if ( 'home' === $unit ) {
				$targets['home-1'] = array( 'home', '', 1 );
				continue;
			}
			if ( ! in_array( $unit, $units, true ) ) {
				continue; // Not in the sitemap (an excluded type).
			}
			$lang = (string) ( $parts[1] ?? '' );
			$key  = array( (string) ( $parts[2] ?? '' ), (int) ( $parts[3] ?? 0 ) );
			$name = Store::file_for( $files, $unit, $lang, $key );
			if ( null === $name ) {
				$targets[ Store::name( $unit, $lang, 1 ) ] = array( $unit, $lang, 1 );
			} else {
				$targets[ $name ] = array( $unit, $lang, (int) $files[ $name ]['n'] );
			}
		}

		return $targets;
	}

	/**
	 * Rebuild the files in the work list, as many as fit before the deadline (always at least
	 * one: a step that starts makes progress).
	 *
	 * @param array<string, mixed> $work     The work list (after `intake()`).
	 * @param float                $deadline Microtime.
	 * @param callable             $rebuild  `fn( string $unit, string $lang, int $n ): bool` —
	 *                                       false when the file can no longer hold its pages.
	 * @return array{work: array<string, mixed>, rebuilt: int, runaway: array<string, mixed>|null, over: bool}
	 */
	public static function run( array $work, float $deadline, callable $rebuild ): array {
		$rebuilt = 0;
		$runaway = null;
		$now     = time();
		foreach ( (array) $work['files'] as $name => $t ) {
			$name = (string) $name;
			$seen = (array) ( $work['seen'][ $name ] ?? array( 0, $now ) );
			if ( $now - (int) $seen[1] >= self::WINDOW ) {
				$seen = array( 0, $now ); // Its window has passed: a fresh allowance.
			}
			if ( (int) $seen[0] >= self::RUNAWAY ) {
				// This file waits; the others in the list are still rebuilt.
				$runaway = $runaway ?? array(
					'file'     => $name,
					'times'    => (int) $seen[0],
					'distinct' => count( (array) $work['seen'] ),
					'left'     => count( (array) $work['files'] ),
					'minutes'  => (int) ceil( max( 1, $now - (int) $seen[1] ) / 60 ),
					'wait'     => max( 60, (int) $seen[1] + self::WINDOW - $now ),
				);
				continue;
			}
			if ( ! $rebuild( (string) $t[0], (string) $t[1], (int) $t[2] ) ) {
				// Grew past what one file may hold: re-split everything (the next step).
				$work['full'] = true;
				Store::save_work( $work );
				break;
			}
			unset( $work['files'][ $name ] );
			$work['seen'][ $name ] = array( (int) $seen[0] + 1, (int) $seen[1] );
			++$work['done'];
			++$rebuilt;
			Store::save_work( $work ); // The cursor advances with every file.
			if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
				break;
			}
		}
		$over = null === $runaway && ! $work['full'] && array() === $work['files'] && array() === Store::dirty();
		if ( $over ) {
			// The refresh is over: the cursor goes. What the guard has counted inside its window
			// stays, because a loop ends one refresh and starts the next.
			$kept = array_filter( (array) $work['seen'], static fn( $seen ): bool => $now - (int) $seen[1] < self::WINDOW );
			Store::save_work(
				array() === $kept ? null : array(
					'full'  => false,
					'files' => array(),
					'seen'  => $kept,
					'done'  => 0,
					'since' => 0,
				)
			);
		}

		return array(
			'work'    => $work,
			'rebuilt' => $rebuilt,
			'runaway' => $runaway,
			'over'    => $over,
		);
	}
}
