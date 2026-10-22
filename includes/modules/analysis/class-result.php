<?php
/**
 * One check's outcome, and the score of a group of them.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure. A check says `good`, `ok` (worth a look), `bad` (fix this) or `na` (not measurable here,
 * with the reason — never silently dropped, so "nothing to do" and "could not tell" never look
 * alike). Its `fix` names where the fix happens: a field (`title`, `desc`, `slug`, `focus`), the
 * content, or a link to follow.
 */
final class Result {

	/** Points per status. */
	private const POINTS = array(
		'good' => 1.0,
		'ok'   => 0.5,
		'bad'  => 0.0,
	);

	/**
	 * A check's outcome.
	 *
	 * @param string             $id     Check id.
	 * @param string             $status good|ok|bad|na.
	 * @param string             $text   What it found, in words.
	 * @param int                $weight How much it counts (0: information only).
	 * @param string             $fix    Where to fix it: a field name, `content`, or ''.
	 * @param array<int, string> $marks  Text to highlight in the editor (sentences, words).
	 * @return array<string, mixed>
	 */
	public static function make( string $id, string $status, string $text, int $weight = 1, string $fix = '', array $marks = array() ): array {
		return array(
			'id'     => $id,
			'status' => in_array( $status, array( 'good', 'ok', 'bad', 'na' ), true ) ? $status : 'na',
			'text'   => $text,
			'weight' => max( 0, $weight ),
			'fix'    => $fix,
			'marks'  => array_values( array_slice( array_map( 'strval', $marks ), 0, 20 ) ),
		);
	}

	/**
	 * The score of a list of checks: 0-100, weighted, `na` and weight-0 checks left out.
	 * Null when nothing could be measured.
	 *
	 * @param array<int, array<string, mixed>> $checks Checks.
	 * @return int|null
	 */
	public static function score( array $checks ): ?int {
		$total = 0;
		$got   = 0.0;
		foreach ( $checks as $c ) {
			$w = (int) ( $c['weight'] ?? 0 );
			if ( $w <= 0 || ! isset( self::POINTS[ $c['status'] ?? 'na' ] ) ) {
				continue;
			}
			$total += $w;
			$got   += $w * self::POINTS[ $c['status'] ];
		}

		return $total > 0 ? (int) round( 100 * $got / $total ) : null;
	}

	/**
	 * The rating words for a score.
	 *
	 * @param int|null $score Score.
	 * @return string good (≥ 75), ok (≥ 50), bad, or none.
	 */
	public static function rating( ?int $score ): string {
		if ( null === $score ) {
			return 'none';
		}
		if ( $score >= 75 ) {
			return 'good';
		}

		return $score >= 50 ? 'ok' : 'bad';
	}
}
