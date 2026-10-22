<?php
/**
 * Which language a page is written in, and what the readability checks know about it (zs-003).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Readability;

use ZinnDigital\ZinnSEO\Analysis\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ EVERY language WordPress ships gets an analysis, never "not supported":
 *
 * 1. Script-neutral checks (sentence and paragraph length, subheadings, repeated sentence starts,
 *    the SEO checks) run in every language, in words, or in characters for scripts written
 *    without spaces (Text).
 * 2. Transition words come from `data/languages.json` (curated, ~35 languages) and, for any other
 *    language, from a translated list in the plugin's own catalogue — so every language the
 *    plugin is translated into has them, and translators can add any other.
 * 3. Passive voice and reading ease need grammar: they run where `languages.json` has a rule or a
 *    formula, and say plainly "not measured in <language>" elsewhere.
 *
 * The language of a post is Tranzly's language for it when Tranzly runs, else the site's.
 */
final class Language {

	/**
	 * Loaded data, by base language.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $data = null;

	/**
	 * Translated fallback transition lists, by locale.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $fallback = array();

	/**
	 * Every language's data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$data ) {
			$raw        = file_get_contents( __DIR__ . '/data/languages.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own data file.
			$data       = is_string( $raw ) ? json_decode( $raw, true ) : null;
			self::$data = is_array( $data ) ? array_filter( $data, 'is_array' ) : array();
		}

		return self::$data;
	}

	/**
	 * The base language of a locale (`pt_BR` → `pt`, `zh_TW` → `zh`, `nn_NO`/`nb_NO` → `nb`).
	 *
	 * @param string $locale Locale or BCP 47 tag.
	 * @return string
	 */
	public static function base( string $locale ): string {
		$base = strtolower( (string) preg_replace( '/[-_].*$/', '', $locale ) );
		$map  = array(
			'nn'  => 'nb',
			'no'  => 'nb',
			'zgh' => 'ber',
		);

		return $map[ $base ] ?? $base;
	}

	/**
	 * The locale a post is written in.
	 *
	 * @param int $post_id Post (0: the site's).
	 * @return string WordPress locale or BCP 47 tag.
	 */
	public static function of_post( int $post_id ): string {
		if ( $post_id > 0 && function_exists( 'tranzly_get_post_language' ) ) {
			$lang = (string) call_user_func( 'tranzly_get_post_language', $post_id );
			if ( '' !== $lang ) {
				return $lang;
			}
		}
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';

		/**
		 * Filters the language a post is analysed in.
		 *
		 * @param string $locale  Locale.
		 * @param int    $post_id Post.
		 */
		return (string) apply_filters( 'zinn_seo_analysis_language', '' !== $locale ? $locale : 'en_US', $post_id );
	}

	/**
	 * What the checks know about a language: the curated data merged over defaults.
	 *
	 * @param string $locale Locale.
	 * @return array<string, mixed>
	 */
	public static function profile( string $locale ): array {
		$base           = self::base( $locale );
		$data           = self::all()[ $base ] ?? array();
		$out            = $data + array(
			'unit'          => 'word',
			'sentence_max'  => 20,
			'transitions'   => array(),
			'starts_ignore' => array(),
		);
		$out['base']    = $base;
		$out['curated'] = isset( self::all()[ $base ] );
		if ( array() === $out['transitions'] ) {
			$out['transitions']        = self::translated_transitions( $locale );
			$out['transitions_source'] = array() === $out['transitions'] ? 'none' : 'translation';
		} else {
			$out['transitions_source'] = 'curated';
		}

		/**
		 * Filters what the readability checks know about a language (add transition words, a
		 * passive-voice rule or a reading-ease formula for a language).
		 *
		 * @param array<string, mixed> $out    Profile.
		 * @param string               $locale Locale.
		 */
		return (array) apply_filters( 'zinn_seo_language_profile', $out, $locale );
	}

	/**
	 * Transition words from the plugin's own catalogue, in a locale (any language).
	 *
	 * @param string $locale Locale.
	 * @return array<int, string>
	 */
	public static function translated_transitions( string $locale ): array {
		if ( isset( self::$fallback[ $locale ] ) ) {
			return self::$fallback[ $locale ];
		}
		$switched = false;
		if ( function_exists( 'switch_to_locale' ) && function_exists( 'determine_locale' ) && determine_locale() !== $locale && 1 === preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $locale ) ) {
			$switched = (bool) switch_to_locale( $locale );
		}
		/* translators: Transition words and phrases in YOUR language (the words that connect one sentence to the next), separated by commas. Give 20 to 40 common ones, lower case. Do not translate this list word for word: write your language's own connecting words. */
		$list = __( 'however, therefore, because, for example, in addition, first, second, finally, also, but, so, then, as a result, although, moreover, instead, meanwhile, in short, for instance, otherwise, similarly, in contrast, consequently, furthermore, nevertheless', 'zinn-seo' );
		if ( $switched ) {
			restore_previous_locale();
		}
		$english = 'however, therefore, because, for example, in addition, first, second, finally, also, but, so, then, as a result, although, moreover, instead, meanwhile, in short, for instance, otherwise, similarly, in contrast, consequently, furthermore, nevertheless';
		// An untranslated list is English: right only for an English page.
		if ( $list === $english && 'en' !== self::base( $locale ) ) {
			self::$fallback[ $locale ] = array();
			return array();
		}
		$words                     = self::words( $list );
		self::$fallback[ $locale ] = $words;

		return $words;
	}

	/**
	 * A translator's list of words as words: split on the comma of any script.
	 *
	 * ⛔ This is the one line a TRANSLATED site reaches and an English one never does. It named
	 * `Text` without importing it, so every page in a language that has a translation of the
	 * plugin and no curated profile stopped the analysis with "Class
	 * ZinnDigital\ZinnSEO\Readability\Text not found" (found on demo.zinnseo.com in 58
	 * languages, 2026-10-11: the score-every-page job failed at its first step).
	 *
	 * @param string $text Words separated by commas (`,` `،` `、` `，`) or semicolons.
	 * @return array<int, string>
	 */
	public static function words( string $text ): array {
		return array_values( array_filter( array_map( 'trim', Text::split( '/[,،、，;]+/u', $text ) ) ) );
	}

	/**
	 * Forget loaded data (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$data     = null;
		self::$fallback = array();
	}
}
