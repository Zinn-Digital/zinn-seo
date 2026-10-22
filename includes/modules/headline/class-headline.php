<?php
/**
 * The headline analyser (zs-723).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Headline;

use ZinnDigital\ZinnSEO\Ai\Tasks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores a title for click-through, the way copywriters judge one, in the title's own language:
 *
 * - LENGTH: what a search result shows whole (about 40 to 60 characters; 15 to 32 in languages
 *   written without spaces, whose characters carry more);
 * - WORDS: six to twelve words read at a glance (languages with spaces);
 * - WORD BALANCE (where we hold the language's word lists): a share of common words so it reads
 *   as a sentence, an emotional word, a power word;
 * - A NUMBER, and a FORM readers recognise (a list, a how-to, a question);
 * - NO SHOUTING (words in capitals, a row of exclamation marks).
 *
 * The score is the points it earns of the points that can be judged in its language, as a
 * percentage, so a language without word lists is never marked down for them. Pure: no query,
 * no network; the AI rewrites are a separate, optional task (`headline`).
 */
final class Headline {

	/** Languages written without spaces between words: judged on characters. */
	private const UNSPACED = array( 'ja', 'zh', 'th', 'lo', 'km', 'my', 'bo' );

	/**
	 * The word lists, by language (loaded once).
	 *
	 * @var array<string, array<string, array<int, string>>>|null
	 */
	private static ?array $words = null;

	/**
	 * The site's language as a two-letter code.
	 *
	 * @return string
	 */
	public static function site_language(): string {
		return self::lang( function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en' );
	}

	/**
	 * A locale or BCP-47 tag as its language code (`en_GB`, `en-GB` -> `en`).
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	public static function lang( string $tag ): string {
		$tag = strtolower( (string) preg_replace( '/[-_].*$/', '', trim( $tag ) ) );

		return 1 === preg_match( '/^[a-z]{2,3}$/', $tag ) ? $tag : 'en';
	}

	/**
	 * The word lists of one language, or null.
	 *
	 * @param string $lang Language code.
	 * @return array<string, array<int, string>>|null
	 */
	public static function words( string $lang ): ?array {
		if ( null === self::$words ) {
			$list        = require __DIR__ . '/data/words.php';
			self::$words = is_array( $list ) ? $list : array();
		}

		return self::$words[ $lang ] ?? null;
	}

	/**
	 * Languages the word balance is judged in.
	 *
	 * @return array<int, string>
	 */
	public static function languages(): array {
		self::words( 'en' );

		return array_keys( (array) self::$words );
	}

	/**
	 * Split a title into lower-case words (hyphens and apostrophes stay inside a word).
	 *
	 * @param string $title Title.
	 * @return array<int, string>
	 */
	public static function tokens( string $title ): array {
		$parts = preg_split( "/[^\\p{L}\\p{N}\\p{M}'’-]+/u", self::lower( $title ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$part = trim( str_replace( '’', "'", $part ), "-'" );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Lower case in any script.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Points for a value against an ideal range: all of them inside, `$step` fewer for each unit
	 * outside, never below zero.
	 *
	 * @param float $value Value.
	 * @param float $low   Lowest ideal.
	 * @param float $high  Highest ideal.
	 * @param int   $max   Points.
	 * @param float $step  Points lost per unit outside.
	 * @return int
	 */
	private static function range_points( float $value, float $low, float $high, int $max, float $step ): int {
		if ( $value >= $low && $value <= $high ) {
			return $max;
		}
		$off = $value < $low ? $low - $value : $value - $high;

		return (int) max( 0, round( $max - $off * $step ) );
	}

	/**
	 * Analyse a title.
	 *
	 * @param string $title Title.
	 * @param string $lang  Language (a locale or tag); '' for the site's.
	 * @return array{score: int, grade: string, language: string, lists: bool, type: string, chars: int, words: int, balance: array<string, int>, checks: array<int, array<string, mixed>>}
	 */
	public static function analyse( string $title, string $lang = '' ): array {
		$title    = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $title ) ) );
		$lang     = '' === $lang ? self::site_language() : self::lang( $lang );
		$unspaced = in_array( $lang, self::UNSPACED, true );
		$tokens   = self::tokens( $title );
		$chars    = self::length( $title );
		$count    = count( $tokens );
		$lists    = $unspaced ? null : self::words( $lang );
		$checks   = array();

		// Length.
		$ideal    = $unspaced ? array( 15, 32 ) : array( 40, 60 );
		$checks[] = array(
			'id'     => 'length',
			'points' => self::range_points( (float) $chars, (float) $ideal[0], (float) $ideal[1], 25, $unspaced ? 2.5 : 1.0 ),
			'max'    => 25,
			'tip'    => $chars < $ideal[0]
				/* translators: 1: characters now, 2: fewest ideal, 3: most ideal. */
				? sprintf( __( 'Short: %1$d characters. Titles of %2$d to %3$d characters show in full and say enough to earn the click.', 'zinn-seo' ), $chars, $ideal[0], $ideal[1] )
				: ( $chars > $ideal[1]
					/* translators: 1: characters now, 2: most ideal. */
					? sprintf( __( 'Long: %1$d characters. Search results cut titles after about %2$d, so the end may not be seen.', 'zinn-seo' ), $chars, $ideal[1] )
					/* translators: %d: characters. */
					: sprintf( __( 'Good length: %d characters, shown in full in search results.', 'zinn-seo' ), $chars ) ),
		);

		// Words.
		if ( ! $unspaced ) {
			$checks[] = array(
				'id'     => 'words',
				'points' => self::range_points( (float) $count, 6.0, 12.0, 10, 3.0 ),
				'max'    => 10,
				'tip'    => ( $count < 6 || $count > 12 )
					/* translators: %d: words. */
					? sprintf( _n( '%d word. Six to twelve words are read at a glance and still say what the page gives.', '%d words. Six to twelve words are read at a glance and still say what the page gives.', $count, 'zinn-seo' ), $count )
					/* translators: %d: words. */
					: sprintf( __( '%d words: read at a glance.', 'zinn-seo' ), $count ),
			);
		}

		// Word balance.
		$balance = array(
			'common'    => 0,
			'emotional' => 0,
			'power'     => 0,
			'other'     => 0,
		);
		if ( null !== $lists && $count > 0 ) {
			$common    = 0;
			$emotional = 0;
			$power     = 0;
			$text      = ' ' . implode( ' ', $tokens ) . ' ';
			foreach ( $tokens as $word ) {
				if ( in_array( $word, $lists['common'], true ) ) {
					++$common;
				}
				if ( in_array( $word, $lists['emotional'], true ) ) {
					++$emotional;
				}
				if ( in_array( $word, $lists['power'], true ) ) {
					++$power;
				}
			}
			// Phrases of several words ("step-by-step" is one token; "por qué" two).
			foreach ( array( 'emotional', 'power' ) as $kind ) {
				foreach ( $lists[ $kind ] as $phrase ) {
					if ( false !== strpos( $phrase, ' ' ) && false !== strpos( $text, ' ' . $phrase . ' ' ) ) {
						'power' === $kind ? ++$power : ++$emotional;
					}
				}
			}
			$balance  = array(
				'common'    => (int) round( 100 * $common / $count ),
				'emotional' => (int) round( 100 * $emotional / $count ),
				'power'     => (int) round( 100 * $power / $count ),
				'other'     => (int) max( 0, 100 - round( 100 * ( $common + $emotional + $power ) / $count ) ),
			);
			$checks[] = array(
				'id'     => 'common',
				'points' => self::range_points( (float) $balance['common'], 20.0, 35.0, 10, 0.5 ),
				'max'    => 10,
				'tip'    => $balance['common'] < 20
					/* translators: %d: a percentage. */
					? sprintf( __( 'Common words: %d%%. A few more everyday words (a, the, your, for) make it read as a sentence, not a list of keywords.', 'zinn-seo' ), $balance['common'] )
					: ( $balance['common'] > 35
						/* translators: %d: a percentage. */
						? sprintf( __( 'Common words: %d%%. Swap some for words that say what the page is about.', 'zinn-seo' ), $balance['common'] )
						/* translators: %d: a percentage. */
						: sprintf( __( 'Common words: %d%%, a natural balance.', 'zinn-seo' ), $balance['common'] ) ),
			);
			$checks[] = array(
				'id'     => 'emotional',
				'points' => 0 === $emotional ? 0 : ( $balance['emotional'] >= 10 ? 15 : 10 ),
				'max'    => 15,
				'tip'    => 0 === $emotional
					? __( 'No emotional word. A word that makes the reader feel something (surprising, mistake, love, warning) earns more clicks.', 'zinn-seo' )
					/* translators: %d: a percentage. */
					: sprintf( __( 'Emotional words: %d%%. Readers feel something before they click.', 'zinn-seo' ), $balance['emotional'] ),
			);
			$checks[] = array(
				'id'     => 'power',
				'points' => $power > 0 ? 10 : 0,
				'max'    => 10,
				'tip'    => $power > 0
					? __( 'A power word makes a promise.', 'zinn-seo' )
					: __( 'No power word. A word that promises something (free, proven, easy, now, complete) gives a reason to click.', 'zinn-seo' ),
			);
		}

		// A number.
		$number   = 1 === preg_match( '/\p{N}/u', $title );
		$checks[] = array(
			'id'     => 'number',
			'points' => $number ? 10 : 0,
			'max'    => 10,
			'tip'    => $number
				? __( 'A number: readers know what they will get.', 'zinn-seo' )
				: __( 'No number. A count, a year or a time ("7 ways", "in 10 minutes") sets what the reader will get.', 'zinn-seo' ),
		);

		// Form.
		$type     = self::type( $title, $tokens, $lists );
		$checks[] = array(
			'id'     => 'type',
			'points' => 'general' === $type ? 4 : 10,
			'max'    => 10,
			'tip'    => 'general' === $type
				? __( 'A plain statement. A list ("7 ways…"), a how-to or a question is a form readers recognise and click.', 'zinn-seo' )
				: ( 'list' === $type ? __( 'A list: readers know how much they will get.', 'zinn-seo' ) : ( 'how' === $type ? __( 'A how-to: it promises a result.', 'zinn-seo' ) : __( 'A question: the reader clicks for the answer.', 'zinn-seo' ) ) ),
		);

		// No shouting.
		$caps = 0;
		foreach ( array_filter( (array) preg_split( '/\s+/u', $title ), 'strlen' ) as $word ) {
			$letters = (string) preg_replace( '/[^\p{L}]/u', '', (string) $word );
			$upper   = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letters, 'UTF-8' ) : strtoupper( $letters );
			if ( self::length( $letters ) >= 5 && $upper === $letters && self::lower( $letters ) !== $letters ) {
				++$caps;
			}
		}
		$shout    = $caps > 0 || substr_count( $title, '!' ) > 1 || 1 === preg_match( '/[!?]{2,}/', $title );
		$checks[] = array(
			'id'     => 'calm',
			'points' => $shout ? 0 : 5,
			'max'    => 5,
			'tip'    => $shout
				? __( 'Words in capitals or several exclamation marks read as shouting, and search engines may rewrite the title.', 'zinn-seo' )
				: __( 'No shouting.', 'zinn-seo' ),
		);

		$points = 0;
		$max    = 0;
		foreach ( $checks as $i => $check ) {
			$points            += (int) $check['points'];
			$max               += (int) $check['max'];
			$checks[ $i ]['ok'] = (int) $check['points'] >= (int) $check['max'];
		}
		$score = '' === $title || 0 === $max ? 0 : (int) round( 100 * $points / $max );

		return array(
			'score'    => $score,
			'grade'    => $score >= 70 ? 'good' : ( $score >= 50 ? 'fair' : 'poor' ),
			'language' => $lang,
			'lists'    => null !== $lists,
			'type'     => $type,
			'chars'    => $chars,
			'words'    => $unspaced ? 0 : $count,
			'balance'  => $balance,
			'checks'   => $checks,
		);
	}

	/**
	 * The title's form: `list`, `how`, `question` or `general`.
	 *
	 * @param string                                 $title  Title.
	 * @param array<int, string>                     $tokens Its words.
	 * @param array<string, array<int, string>>|null $lists  The language's lists.
	 * @return string
	 */
	public static function type( string $title, array $tokens, ?array $lists ): string {
		if ( 1 === preg_match( '/^\s*\p{N}+\b/u', $title ) ) {
			return 'list';
		}
		$lower = ' ' . implode( ' ', $tokens ) . ' ';
		foreach ( (array) ( $lists['how'] ?? array() ) as $how ) {
			if ( 0 === strpos( ltrim( $lower ), $how . ' ' ) ) {
				return 'how';
			}
		}
		if ( 1 === preg_match( '/[?？؟]\s*$/u', $title ) || ( array() !== $tokens && in_array( $tokens[0], (array) ( $lists['question'] ?? array() ), true ) && false !== strpos( $title, '?' ) ) ) {
			return 'question';
		}

		return 'general';
	}

	/**
	 * The optional AI rewrites: five alternative titles, each scored here before it is shown.
	 *
	 * @return void
	 */
	public static function register_task(): void {
		if ( ! class_exists( Tasks::class ) ) {
			return;
		}
		Tasks::register(
			'headline',
			array(
				'label'       => static fn() => __( 'Headline rewrites', 'zinn-seo' ),
				'tier'        => 'free',
				'subject'     => 'none',
				'store'       => false,
				'per_item'    => 500,
				'fields'      => array(
					'titles' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'input'       => static function ( int $id, array $opts ) {
					$title = Tasks::cut( sanitize_text_field( (string) ( $opts['title'] ?? '' ) ), 300 );
					if ( '' === $title ) {
						return new \WP_Error( 'zinn_seo_headline_empty', __( 'Write a title first.', 'zinn-seo' ) );
					}
					$focus = Tasks::cut( sanitize_text_field( (string) ( $opts['focus'] ?? '' ) ), 120 );
					return array(
						'language' => self::lang( (string) ( $opts['language'] ?? self::site_language() ) ),
						'title'    => $title,
						'phrase'   => $focus,
						'summary'  => Tasks::cut( sanitize_textarea_field( (string) ( $opts['summary'] ?? '' ) ), 1200 ),
					);
				},
				'instruction' => static fn() => 'You rewrite a web page title so more searchers click it, keeping it true to the page. Give five different "titles" in the item\'s language: each 40 to 60 characters (shorter in languages written without spaces), using the "phrase" naturally when given, with at most one of: a number, a how-to, a question. No clickbait, no capitals for emphasis, no exclamation marks, no quotation marks.',
				'clean'       => static fn( array $a, int $id, array $opts ): array => array(
					'titles' => array_map(
						static fn( string $t ): array => array(
							'title' => $t,
							'score' => self::analyse( $t, (string) ( $opts['language'] ?? '' ) )['score'],
						),
						Tasks::lines( $a['titles'] ?? array(), 120, 5 )
					),
				),
			)
		);
	}
}
