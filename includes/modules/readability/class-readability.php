<?php
/**
 * Readability checks in every language (zs-003) and the inclusive-language check (zs-432).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Readability;

use ZinnDigital\ZinnSEO\Analysis\Doc;
use ZinnDigital\ZinnSEO\Analysis\Result;
use ZinnDigital\ZinnSEO\Analysis\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure (given a Doc and a language profile). Thresholds are the widely used ones (Yoast's,
 * which writers already know), in the language's own unit: sentences over the language's
 * `sentence_max` in at most a quarter of sentences, paragraphs up to 150 words, a subheading at
 * least every 300 words, transition words in 30 % of sentences, passive voice in at most 10 %,
 * no three sentences in a row starting with the same word, and a reading-ease score where the
 * language has a formula.
 */
final class Readability {

	/**
	 * Reading-ease formulas: id => [constant, words-per-sentence factor, syllables-per-word factor].
	 * Flesch (en), Amstad (de), Kandel-Moles (fr), Fernández Huerta (es, per 100 words → per
	 * word), Flesch-Vacca (it), Douma (nl), Martins (pt), Oborneva (ru).
	 */
	public const FLESCH = array(
		'en' => array( 206.835, 1.015, 84.6 ),
		'de' => array( 180.0, 1.0, 58.5 ),
		'fr' => array( 207.0, 1.015, 73.6 ),
		'es' => array( 206.84, 1.02, 60.0 ),
		'it' => array( 217.0, 1.3, 60.0 ),
		'nl' => array( 206.835, 0.93, 77.0 ),
		'pt' => array( 248.835, 1.015, 84.6 ),
		'ru' => array( 206.835, 1.3, 60.1 ),
	);

	/**
	 * Every readability check of a page.
	 *
	 * @param Doc                  $doc     The rendered page.
	 * @param array<string, mixed> $profile Language::profile().
	 * @return array<int, array<string, mixed>>
	 */
	public static function checks( Doc $doc, array $profile ): array {
		$paragraphs = $doc->paragraphs();
		$text       = implode( "\n", $paragraphs );
		$unspaced   = 'char' === ( $profile['unit'] ?? 'word' ) || Text::unspaced( $text );
		$words      = Text::length( $text, $unspaced );
		$sentences  = array();
		foreach ( $paragraphs as $p ) {
			foreach ( Text::sentences( $p ) as $s ) {
				$sentences[] = $s;
			}
		}
		$out = array();
		if ( $words < 50 || count( $sentences ) < 2 ) {
			$out[] = Result::make(
				'readability_length',
				'na',
				__( 'Write a little more: the readability checks start at about 50 words.', 'zinn-seo' ),
				0,
				'content'
			);
			return $out;
		}
		$out[] = self::sentence_length( $sentences, $profile, $unspaced );
		$out[] = self::paragraph_length( $paragraphs, $unspaced );
		$out[] = self::subheadings( $doc, $unspaced, $words );
		$out[] = self::transitions( $sentences, $profile, $unspaced, $words );
		$out[] = self::passive( $sentences, $profile );
		if ( ! $unspaced ) {
			$out[] = self::starts( $sentences, $profile );
		}
		$out[] = self::reading_ease( $sentences, $profile );

		return $out;
	}

	/**
	 * Long sentences.
	 *
	 * @param array<int, string>   $sentences Sentences.
	 * @param array<string, mixed> $profile   Profile.
	 * @param bool                 $unspaced  Measured in characters.
	 * @return array<string, mixed>
	 */
	public static function sentence_length( array $sentences, array $profile, bool $unspaced ): array {
		$max  = max( 5, (int) ( $profile['sentence_max'] ?? ( $unspaced ? 40 : 20 ) ) );
		$long = array();
		foreach ( $sentences as $s ) {
			if ( Text::length( $s, $unspaced ) > $max ) {
				$long[] = $s;
			}
		}
		$share = count( $long ) / max( 1, count( $sentences ) );
		$pct   = (int) round( 100 * $share );
		if ( $share <= 0.25 ) {
			if ( $unspaced ) {
				/* translators: 1: a percentage, 2: a number of characters. */
				$text = sprintf( __( 'Sentence length: %1$d%% of sentences are longer than %2$d characters. Good.', 'zinn-seo' ), $pct, $max );
			} else {
				/* translators: 1: a percentage, 2: a number of words. */
				$text = sprintf( __( 'Sentence length: %1$d%% of sentences are longer than %2$d words. Good.', 'zinn-seo' ), $pct, $max );
			}
			return Result::make( 'sentence_length', 'good', $text, 3, 'content' );
		}
		if ( $unspaced ) {
			/* translators: 1: a percentage, 2: a number of characters. */
			$text = sprintf( __( 'Sentence length: %1$d%% of sentences are longer than %2$d characters; keep it to a quarter. Split the highlighted sentences.', 'zinn-seo' ), $pct, $max );
		} else {
			/* translators: 1: a percentage, 2: a number of words. */
			$text = sprintf( __( 'Sentence length: %1$d%% of sentences are longer than %2$d words; keep it to a quarter. Split the highlighted sentences.', 'zinn-seo' ), $pct, $max );
		}

		return Result::make( 'sentence_length', $share <= 0.3 ? 'ok' : 'bad', $text, 3, 'content', $long );
	}

	/**
	 * Long paragraphs.
	 *
	 * @param array<int, string> $paragraphs Paragraphs.
	 * @param bool               $unspaced   Measured in characters.
	 * @return array<string, mixed>
	 */
	public static function paragraph_length( array $paragraphs, bool $unspaced ): array {
		$limit = $unspaced ? 300 : 150;
		$long  = array();
		$worst = 0;
		foreach ( $paragraphs as $p ) {
			$n = Text::length( $p, $unspaced );
			if ( $n > $limit ) {
				$long[] = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 80 ) : substr( $p, 0, 80 );
				$worst  = max( $worst, $n );
			}
		}
		if ( array() === $long ) {
			return Result::make( 'paragraph_length', 'good', __( 'Paragraph length: none is too long. Good.', 'zinn-seo' ), 2, 'content' );
		}
		/* translators: 1: number of paragraphs, 2: words or characters. */
		$text = sprintf( _n( 'Paragraph length: %1$d paragraph is longer than %2$d; break it up.', 'Paragraph length: %1$d paragraphs are longer than %2$d; break them up.', count( $long ), 'zinn-seo' ), count( $long ), $limit );

		return Result::make( 'paragraph_length', $worst > $limit * 4 / 3 ? 'bad' : 'ok', $text, 2, 'content', $long );
	}

	/**
	 * Subheadings at least every 300 words.
	 *
	 * @param Doc  $doc      Page.
	 * @param bool $unspaced Measured in characters.
	 * @param int  $words    Length of the text.
	 * @return array<string, mixed>
	 */
	public static function subheadings( Doc $doc, bool $unspaced, int $words ): array {
		$limit = $unspaced ? 600 : 300;
		if ( $words <= $limit ) {
			return Result::make( 'subheadings', 'good', __( 'Subheadings: the text is short enough not to need any.', 'zinn-seo' ), 1, 'content' );
		}
		if ( array() === $doc->headings ) {
			return Result::make( 'subheadings', 'bad', __( 'Subheadings: there are none. Add headings so readers can scan the text.', 'zinn-seo' ), 3, 'content' );
		}
		$run   = 0;
		$worst = 0;
		$first = '';
		foreach ( $doc->blocks as $block ) {
			if ( 'h' === $block['type'] ) {
				$run = 0;
				continue;
			}
			$run += Text::length( (string) $block['text'], $unspaced );
			if ( $run > $worst ) {
				$worst = $run;
				$first = (string) $block['text'];
			}
		}
		if ( $worst <= $limit ) {
			return Result::make( 'subheadings', 'good', __( 'Subheadings: the text is well divided. Good.', 'zinn-seo' ), 3, 'content' );
		}
		/* translators: %d: words or characters. */
		$text = sprintf( __( 'Subheadings: one section runs for %d without a heading; add one.', 'zinn-seo' ), $worst );

		return Result::make( 'subheadings', $worst > $limit * 1.5 ? 'bad' : 'ok', $text, 3, 'content', array( function_exists( 'mb_substr' ) ? mb_substr( $first, 0, 80 ) : substr( $first, 0, 80 ) ) );
	}

	/**
	 * Transition words.
	 *
	 * @param array<int, string>   $sentences Sentences.
	 * @param array<string, mixed> $profile   Profile.
	 * @param bool                 $unspaced  Unspaced script.
	 * @param int                  $words     Length of the text.
	 * @return array<string, mixed>
	 */
	public static function transitions( array $sentences, array $profile, bool $unspaced, int $words ): array {
		$list = array_values( array_filter( array_map( array( Text::class, 'norm' ), (array) ( $profile['transitions'] ?? array() ) ) ) );
		if ( array() === $list ) {
			return Result::make( 'transitions', 'na', __( 'Transition words: there is no word list for this language yet. Translators can add one in the plugin\'s translation.', 'zinn-seo' ), 0 );
		}
		if ( $words < ( $unspaced ? 400 : 200 ) ) {
			return Result::make( 'transitions', 'na', __( 'Transition words: checked from about 200 words.', 'zinn-seo' ), 0 );
		}
		$with = 0;
		foreach ( $sentences as $s ) {
			$n = Text::norm( $s );
			foreach ( $list as $t ) {
				$hit = $unspaced ? false !== strpos( $n, $t ) : 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $t, '/' ) . '(?![\p{L}\p{N}])/u', $n );
				if ( $hit ) {
					++$with;
					break;
				}
			}
		}
		$pct = (int) round( 100 * $with / max( 1, count( $sentences ) ) );
		if ( $pct >= 30 ) {
			/* translators: %d: a percentage. */
			return Result::make( 'transitions', 'good', sprintf( __( 'Transition words: %d%% of sentences have one. Good.', 'zinn-seo' ), $pct ), 2, 'content' );
		}
		/* translators: %d: a percentage. */
		$text = sprintf( __( 'Transition words: only %d%% of sentences have one; aim for 30%%. Words like "however" or "for example" guide the reader.', 'zinn-seo' ), $pct );

		return Result::make( 'transitions', $pct >= 20 ? 'ok' : 'bad', $text, 2, 'content' );
	}

	/**
	 * Is a sentence in the passive voice?
	 *
	 * @param string               $sentence Sentence.
	 * @param array<string, mixed> $rule     The language's passive rule.
	 * @return bool
	 */
	public static function is_passive( string $sentence, array $rule ): bool {
		$n = Text::norm( $sentence );
		if ( isset( $rule['sentence'] ) ) {
			$hit = @preg_match( (string) $rule['sentence'], $n ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filtered rule may be invalid; false = no match.
			return 1 === $hit;
		}
		$aux = array_flip( array_map( array( Text::class, 'norm' ), (array) ( $rule['aux'] ?? array() ) ) );
		if ( array() === $aux ) {
			return false;
		}
		$irregular = array_flip( (array) ( $rule['irregular'] ?? array() ) );
		$except    = array_flip( (array) ( $rule['except'] ?? array() ) );
		$pattern   = (string) ( $rule['participle'] ?? '' );
		$window    = max( 1, (int) ( $rule['window'] ?? 3 ) );
		$words     = Text::words( $n, false );
		$count     = count( $words );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $aux[ $words[ $i ] ] ) ) {
				continue;
			}
			$last = min( $count - 1, $i + $window );
			for ( $j = $i + 1; $j <= $last; $j++ ) {
				$w = $words[ $j ];
				if ( isset( $except[ $w ] ) ) {
					continue;
				}
				if ( isset( $irregular[ $w ] ) || ( '' !== $pattern && 1 === @preg_match( $pattern, $w ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Passive voice.
	 *
	 * @param array<int, string>   $sentences Sentences.
	 * @param array<string, mixed> $profile   Profile.
	 * @return array<string, mixed>
	 */
	public static function passive( array $sentences, array $profile ): array {
		$rule = $profile['passive'] ?? null;
		if ( ! is_array( $rule ) || array() === $rule ) {
			return Result::make( 'passive', 'na', __( 'Passive voice: not measured in this language (its passive cannot be told reliably from the words alone).', 'zinn-seo' ), 0 );
		}
		$found = array();
		foreach ( $sentences as $s ) {
			if ( self::is_passive( $s, $rule ) ) {
				$found[] = $s;
			}
		}
		$pct = (int) round( 100 * count( $found ) / max( 1, count( $sentences ) ) );
		if ( $pct <= 10 ) {
			/* translators: %d: a percentage. */
			return Result::make( 'passive', 'good', sprintf( __( 'Passive voice: %d%% of sentences. Good.', 'zinn-seo' ), $pct ), 2, 'content' );
		}
		/* translators: %d: a percentage. */
		$text = sprintf( __( 'Passive voice: %d%% of sentences; keep it to 10%%. Say who does what in the highlighted sentences.', 'zinn-seo' ), $pct );

		return Result::make( 'passive', $pct <= 15 ? 'ok' : 'bad', $text, 2, 'content', $found );
	}

	/**
	 * Three or more sentences in a row starting with the same word.
	 *
	 * @param array<int, string>   $sentences Sentences.
	 * @param array<string, mixed> $profile   Profile.
	 * @return array<string, mixed>
	 */
	public static function starts( array $sentences, array $profile ): array {
		$ignore = array_flip( (array) ( $profile['starts_ignore'] ?? array() ) );
		$prev   = '';
		$run    = 1;
		$found  = array();
		foreach ( $sentences as $s ) {
			$words = Text::words( Text::norm( $s ), false );
			$first = $words[0] ?? '';
			if ( isset( $ignore[ $first ] ) && isset( $words[1] ) ) {
				$first .= ' ' . $words[1];
			}
			if ( '' !== $first && $first === $prev ) {
				++$run;
				if ( 3 === $run ) {
					$found[] = $first;
				}
			} else {
				$run = 1;
			}
			$prev = $first;
		}
		if ( array() === $found ) {
			return Result::make( 'sentence_starts', 'good', __( 'Sentence variety: no three sentences in a row start the same way. Good.', 'zinn-seo' ), 1, 'content' );
		}
		/* translators: %s: words that start several sentences in a row. */
		$text = sprintf( __( 'Sentence variety: several sentences in a row start with "%s"; vary them.', 'zinn-seo' ), implode( '", "', array_unique( $found ) ) );

		return Result::make( 'sentence_starts', 'bad', $text, 1, 'content', array_unique( $found ) );
	}

	/**
	 * The language's reading-ease score.
	 *
	 * @param array<int, string>   $sentences Sentences.
	 * @param array<string, mixed> $profile   Profile.
	 * @return array<string, mixed>
	 */
	public static function reading_ease( array $sentences, array $profile ): array {
		$formula = self::FLESCH[ (string) ( $profile['flesch'] ?? '' ) ] ?? null;
		if ( null === $formula ) {
			return Result::make( 'reading_ease', 'na', __( 'Reading ease: there is no standard formula for this language; the checks above cover it.', 'zinn-seo' ), 0 );
		}
		$score = self::flesch( $sentences, $profile );
		if ( null === $score ) {
			return Result::make( 'reading_ease', 'na', __( 'Reading ease: not enough text to score.', 'zinn-seo' ), 0 );
		}
		$shown = (int) round( max( 0, min( 100, $score ) ) );
		if ( $shown >= 60 ) {
			/* translators: %d: a score from 0 to 100. */
			return Result::make( 'reading_ease', 'good', sprintf( __( 'Reading ease: %d out of 100, easy to read. Good.', 'zinn-seo' ), $shown ), 2, 'content' );
		}
		/* translators: %d: a score from 0 to 100. */
		$text = sprintf( __( 'Reading ease: %d out of 100, fairly hard to read. Use shorter sentences and simpler words.', 'zinn-seo' ), $shown );

		return Result::make( 'reading_ease', $shown >= 50 ? 'ok' : 'bad', $text, 2, 'content' );
	}

	/**
	 * The reading-ease number (higher is easier), or null.
	 *
	 * @param array<int, string>   $sentences Sentences.
	 * @param array<string, mixed> $profile   Profile.
	 * @return float|null
	 */
	public static function flesch( array $sentences, array $profile ): ?float {
		$formula = self::FLESCH[ (string) ( $profile['flesch'] ?? '' ) ] ?? null;
		if ( null === $formula || array() === $sentences ) {
			return null;
		}
		$vowels    = preg_quote( (string) ( $profile['vowels'] ?? 'aeiouy' ), '/' );
		$silent    = ! empty( $profile['silent_e'] );
		$words     = 0;
		$syllables = 0;
		foreach ( $sentences as $s ) {
			foreach ( Text::words( Text::norm( $s ), false ) as $w ) {
				if ( ! preg_match( '/\p{L}/u', $w ) ) {
					continue;
				}
				++$words;
				$syllables += Text::syllables( $w, $vowels, $silent );
			}
		}
		if ( $words < 10 ) {
			return null;
		}
		$asl = $words / count( $sentences );
		$asw = $syllables / $words;

		return $formula[0] - $formula[1] * $asl - $formula[2] * $asw;
	}

	/**
	 * The inclusive-language terms of a language.
	 *
	 * @param string $base Base language.
	 * @return array<int, array<string, string>>
	 */
	public static function inclusive_terms( string $base ): array {
		static $all = null;
		if ( null === $all ) {
			$raw = file_get_contents( __DIR__ . '/data/inclusive.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own data file.
			$all = is_string( $raw ) ? json_decode( $raw, true ) : array();
			$all = is_array( $all ) ? $all : array();
		}
		$terms = isset( $all[ $base ] ) && is_array( $all[ $base ] ) ? $all[ $base ] : array();

		/**
		 * Filters the inclusive-language terms of a language: lists of
		 * `{t: "term|form", alt: "suggestion", cat: "…", level: "bad"|"ok"}`.
		 *
		 * @param array<int, array<string, string>> $terms Terms.
		 * @param string                            $base  Base language.
		 */
		return array_values( array_filter( (array) apply_filters( 'zinn_seo_inclusive_terms', $terms, $base ), 'is_array' ) );
	}

	/**
	 * Inclusive language: terms that exclude or hurt, with what to write instead.
	 *
	 * @param Doc    $doc  Page.
	 * @param string $base Base language.
	 * @return array<int, array<string, mixed>>
	 */
	public static function inclusive( Doc $doc, string $base ): array {
		$terms = self::inclusive_terms( $base );
		if ( array() === $terms ) {
			return array( Result::make( 'inclusive', 'na', __( 'Inclusive language: there is no list for this language yet.', 'zinn-seo' ), 0 ) );
		}
		$text  = Text::norm( $doc->text() );
		$found = array();
		foreach ( $terms as $term ) {
			foreach ( explode( '|', (string) ( $term['t'] ?? '' ) ) as $form ) {
				$form = Text::norm( $form );
				if ( '' !== $form && 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $form, '/' ) . '(?![\p{L}\p{N}])/u', $text ) ) {
					$found[] = array(
						'term'  => $form,
						'alt'   => (string) ( $term['alt'] ?? '' ),
						'level' => 'bad' === ( $term['level'] ?? '' ) ? 'bad' : 'ok',
					);
					break;
				}
			}
		}
		if ( array() === $found ) {
			return array( Result::make( 'inclusive', 'good', __( 'Inclusive language: nothing found that could exclude readers. Good.', 'zinn-seo' ), 1, 'content' ) );
		}
		$out = array();
		foreach ( $found as $f ) {
			if ( 'bad' === $f['level'] ) {
				/* translators: 1: a word or phrase in the text, 2: suggested alternatives. */
				$text = sprintf( __( 'Inclusive language: "%1$s" can exclude or hurt readers. Consider: %2$s.', 'zinn-seo' ), $f['term'], $f['alt'] );
			} else {
				/* translators: 1: a word or phrase in the text, 2: suggested alternatives. */
				$text = sprintf( __( 'Inclusive language: "%1$s" may exclude some readers, depending on the context. Consider: %2$s.', 'zinn-seo' ), $f['term'], $f['alt'] );
			}
			$out[] = Result::make( 'inclusive', $f['level'], $text, 1, 'content', array( $f['term'] ) );
		}

		return $out;
	}
}
