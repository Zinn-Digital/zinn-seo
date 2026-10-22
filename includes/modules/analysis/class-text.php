<?php
/**
 * Words, sentences and phrase matching in any script (zs-001, zs-003, zs-004).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure. ⭐ Works in every writing system WordPress ships, not a list of ~20 languages:
 *
 * - Sentences end at the full stops of every script (Latin `. ! ? …`, CJK `。！？`, Arabic `؟ ۔`,
 *   Devanagari `। ॥`, Armenian `։`, Ethiopic `። ፧`, Myanmar `။`, Khmer `។ ៕`, Tibetan `།`, Greek `;`)
 *   — a Latin full stop only when the next sentence starts (space, then not a lower-case letter or
 *   digit), so "e.g. this" and "3.5" stay one sentence.
 * - Scripts written without spaces between words (Chinese, Japanese, Thai, Lao, Khmer, Myanmar,
 *   Tibetan) are measured in CHARACTERS, as their readers and Yoast's Japanese analysis do; every
 *   other script in words.
 * - Matching a phrase lowers case, unifies apostrophes and dashes, drops Arabic and Hebrew vowel
 *   marks and the tatweel, and (optionally) accepts word forms by stem.
 */
final class Text {

	/** Characters that end a sentence wherever they are. */
	private const HARD_STOPS = '。！？｡‼⁇⁈⁉؟۔।॥։።፧፨။។៕།';

	/**
	 * Unicode scripts written without spaces between words (measured in characters).
	 *
	 * @var array<int, string>
	 */
	private const UNSPACED = array( 'Han', 'Hiragana', 'Katakana', 'Thai', 'Lao', 'Khmer', 'Myanmar', 'Tibetan' );

	/**
	 * `preg_split()` that always returns a list (never false).
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Text.
	 * @return array<int, string>
	 */
	public static function split( string $pattern, string $subject ): array {
		$parts = preg_split( $pattern, $subject );

		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Does this text use a script written without spaces between words?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function unspaced( string $text ): bool {
		$sample  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 6000 );
		$letters = preg_match_all( '/\p{L}/u', $sample );
		if ( ! $letters ) {
			return false;
		}
		$closed = 0;
		foreach ( self::UNSPACED as $script ) {
			$closed += (int) preg_match_all( '/\p{' . $script . '}/u', $sample );
		}

		return $closed / $letters > 0.3;
	}

	/**
	 * The words of a text (for an unspaced script: its letters, one per "word").
	 *
	 * @param string    $text     Text.
	 * @param bool|null $unspaced Measure in characters (null: decide from the text).
	 * @return array<int, string>
	 */
	public static function words( string $text, ?bool $unspaced = null ): array {
		$unspaced = null === $unspaced ? self::unspaced( $text ) : $unspaced;
		if ( $unspaced ) {
			preg_match_all( '/[\p{L}\p{N}]\p{M}*/u', $text, $m );
			return $m[0];
		}
		preg_match_all( '/[\p{L}\p{N}][\p{L}\p{M}\p{N}\'’\-]*/u', $text, $m );

		return array_values(
			array_filter(
				array_map( static fn( $w ) => rtrim( (string) $w, "-'’" ), $m[0] ),
				static fn( $w ) => '' !== $w
			)
		);
	}

	/**
	 * How long a text is in its own unit (words, or characters for an unspaced script).
	 *
	 * @param string    $text     Text.
	 * @param bool|null $unspaced See words().
	 * @return int
	 */
	public static function length( string $text, ?bool $unspaced = null ): int {
		return count( self::words( $text, $unspaced ) );
	}

	/**
	 * Split a paragraph into sentences.
	 *
	 * @param string $text Paragraph.
	 * @return array<int, string>
	 */
	public static function sentences( string $text ): array {
		$text = Doc::squash( $text );
		if ( '' === $text ) {
			return array();
		}
		$hard = preg_quote( self::HARD_STOPS, '/' );
		// After a hard stop (and any closing quotes or brackets): always a boundary.
		$text = (string) preg_replace( '/([' . $hard . ']+[\'"’”»）)\]]*)\s*/u', "$1\x1E", $text );
		// Latin-style stops: only when a sentence clearly starts after the space.
		$text = (string) preg_replace( '/([.!?…;]+[\'"’”»)\]]*)\s+(?=[^\p{Ll}\p{N}\s])/u', "$1\x1E", $text );
		$out  = array();
		foreach ( explode( "\x1E", $text ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && preg_match( '/[\p{L}\p{N}]/u', $part ) ) {
				$out[] = $part;
			}
		}
		// A Greek question mark is `;` — but a `;` elsewhere is a clause break, not a sentence end.
		return self::rejoin_semicolons( $out );
	}

	/**
	 * Undo splits made at a semicolon in a non-Greek sentence.
	 *
	 * @param array<int, string> $parts Parts.
	 * @return array<int, string>
	 */
	private static function rejoin_semicolons( array $parts ): array {
		$out = array();
		foreach ( $parts as $part ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && ';' === substr( $out[ $last ], -1 ) && ! preg_match( '/\p{Greek}/u', $out[ $last ] ) ) {
				$out[ $last ] .= ' ' . $part;
				continue;
			}
			$out[] = $part;
		}

		return $out;
	}

	/**
	 * Lower case, unified punctuation, no Arabic/Hebrew vowel marks or tatweel, single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function norm( string $text ): string {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = str_replace( array( '’', '‘', '`', '´', '‐', '‑', '–', '—', 'ـ' ), array( "'", "'", "'", "'", '-', '-', '-', '-', '' ), $text );
		// Arabic harakat + Quranic marks, Hebrew niqqud: written sometimes, never part of a match.
		$text = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0591}-\x{05C7}]/u', '', $text );
		// Alef forms read as one letter when matching Arabic.
		$text = str_replace( array( 'أ', 'إ', 'آ' ), 'ا', $text );

		return Doc::squash( $text );
	}

	/**
	 * A word's stem for matching word forms: the word less its last two letters (at least four
	 * kept). Language-neutral on purpose (every language): a text word that STARTS with the stem
	 * is the same word in another form, so "optimise" matches "optimised" and "optimising", "site"
	 * matches "sites", and "optimal" matches neither.
	 *
	 * @param string $word Normalised word.
	 * @return string
	 */
	public static function stem( string $word ): string {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );
		if ( $len <= 4 ) {
			return $word;
		}
		$keep = max( 4, $len - 2 );

		return function_exists( 'mb_substr' ) ? mb_substr( $word, 0, $keep, 'UTF-8' ) : substr( $word, 0, $keep );
	}

	/**
	 * Is a text word a form of a phrase word?
	 *
	 * @param string $text_word   Normalised word from the text.
	 * @param string $phrase_word Normalised word from the phrase.
	 * @return bool
	 */
	public static function same_word( string $text_word, string $phrase_word ): bool {
		if ( $text_word === $phrase_word ) {
			return true;
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $phrase_word, 'UTF-8' ) : strlen( $phrase_word );
		if ( $len <= 3 ) {
			return false;
		}
		$stem  = self::stem( $phrase_word );
		$extra = ( function_exists( 'mb_strlen' ) ? mb_strlen( $text_word, 'UTF-8' ) : strlen( $text_word ) ) - ( function_exists( 'mb_strlen' ) ? mb_strlen( $stem, 'UTF-8' ) : strlen( $stem ) );

		return 0 === strpos( $text_word, $stem ) && $extra <= 5;
	}

	/**
	 * Does a text contain a phrase?
	 *
	 * Exact (normalised) first; with `$forms`, every word of the phrase present by stem, in any
	 * order, within the text (for a sentence or a short field that is the "phrase is there" test
	 * readers expect; word order changes between languages and in natural writing).
	 *
	 * @param string $haystack Text.
	 * @param string $phrase   Phrase.
	 * @param bool   $forms    Accept word forms and any order.
	 * @return bool
	 */
	public static function contains( string $haystack, string $phrase, bool $forms = true ): bool {
		$h = self::norm( $haystack );
		$p = self::norm( $phrase );
		if ( '' === $p || '' === $h ) {
			return false;
		}
		if ( self::unspaced( $p ) ) {
			return false !== strpos( $h, str_replace( ' ', '', $p ) ) || false !== strpos( $h, $p );
		}
		if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $p, '/' ) . '(?![\p{L}\p{N}])/u', $h ) ) {
			return true;
		}
		if ( ! $forms ) {
			return false;
		}
		$have = array_unique( self::words( $h, false ) );
		foreach ( self::words( $p, false ) as $w ) {
			$found = false;
			foreach ( $have as $t ) {
				if ( self::same_word( $t, $w ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How many times a phrase occurs (exact, normalised; word forms by stem for one-word phrases).
	 *
	 * @param string $haystack Text.
	 * @param string $phrase   Phrase.
	 * @return int
	 */
	public static function count( string $haystack, string $phrase ): int {
		$h = self::norm( $haystack );
		$p = self::norm( $phrase );
		if ( '' === $p || '' === $h ) {
			return 0;
		}
		if ( self::unspaced( $p ) ) {
			return substr_count( $h, str_replace( ' ', '', $p ) );
		}
		$exact = (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $p, '/' ) . '(?![\p{L}\p{N}])/u', $h );
		$words = self::words( $p, false );
		if ( 1 !== count( $words ) ) {
			return $exact;
		}
		$forms = 0;
		foreach ( self::words( $h, false ) as $w ) {
			if ( self::same_word( $w, $words[0] ) ) {
				++$forms;
			}
		}

		return max( $exact, $forms );
	}

	/**
	 * A slug's words (a slug percent-encodes non-Latin letters).
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function slug_text( string $slug ): string {
		return str_replace( array( '-', '_' ), ' ', rawurldecode( $slug ) );
	}

	/**
	 * Count syllables in a word by vowel groups (for the reading-ease formulas of the languages
	 * that have one).
	 *
	 * @param string $word   Normalised word.
	 * @param string $vowels A character class body of the language's vowels.
	 * @param bool   $silent_e English: a final silent e is not a syllable.
	 * @return int
	 */
	public static function syllables( string $word, string $vowels, bool $silent_e = false ): int {
		if ( $silent_e && 1 === preg_match( '/[^aeiouy]e$/u', $word ) && ! preg_match( '/[^aeiouy]le$/u', $word ) ) {
			$word = substr( $word, 0, -1 );
		}
		$n = (int) preg_match_all( '/[' . $vowels . ']+/u', $word );

		return max( 1, $n );
	}
}
