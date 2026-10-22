<?php
/**
 * The live content optimiser: the top 10 for a phrase, read once, kept, and a score as you write.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Optimiser;

use ZinnDigital\ZinnSEO\Analysis\Doc;
use ZinnDigital\ZinnSEO\Analysis\Text;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Credits\Credits;
use ZinnDigital\ZinnSEO\Extra\Extra;
use ZinnDigital\ZinnSEO\Tools\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HOW IT WORKS (zs-766)
 *
 * 1. The writer's focus phrase, a country and a language name a SEARCH. The first time a search
 *    is wanted, someone who may spend the owner's Zinn® credit asks for it (the price is shown
 *    first): Zinn® reads the top 10 results (one `brief` run of the research toolbox, billed
 *    in `content_brief`, good standing asked by Zinn® at the point of spend) and answers a MODEL —
 *    the terms the pages share with how often they use them, the names they mention, the
 *    questions they answer, their headings and lengths. The competitors' text never reaches
 *    this site.
 * 2. The model is KEPT here, one row per search (phrase + country + language), in its own table,
 *    and re-used by every post that targets the same search: a second writer, a second post, a
 *    reload of the editor cost nothing. A model older than {@see self::FRESH_DAYS} days is still
 *    used; the screen offers to read the results again.
 * 3. The SCORE is computed in the editor, in the browser, on every pause in typing — no call to
 *    this site or to Zinn® per keystroke. {@see self::score()} is the same sum in PHP, for MCP,
 *    WP-CLI and the page-builder panel (the two are held equal by one shared fixture).
 *
 * MONEY: a read is asked for once per search, by a person, never on a keystroke and never by a
 * visitor. Two requests for the same search at once spend once: the row is CLAIMED with one
 * UPDATE before Zinn® is asked ({@see self::claim()}). A site whose account is paused or empty is
 * told so — and where to buy credit — before anything is sent.
 *
 * SCALE: one indexed row per search, read by its key; nothing runs on a visitor's page or in a
 * background sweep.
 */
final class Optimiser {

	/** Table schema version. */
	public const DB_VERSION = 1;

	/** Option holding the installed schema version. */
	public const DB_OPTION = 'zseo_opt_db';

	/** Post meta: the search a post is optimised for (`{location, language}`; the phrase is the focus). */
	public const META = '_zseo_opt';

	/** Days a model is called fresh. */
	public const FRESH_DAYS = 30;

	/** Seconds a claimed read may stay pending before another request may claim it again. */
	public const PENDING_SECONDS = 900;

	/** The research toolbox's tool that reads the top 10. */
	public const PRODUCT = 'brief';

	/** Its unit. */
	public const UNIT = 'content_brief';

	/** Longest phrase. */
	public const MAX_PHRASE = 120;

	/** Weight of each part of the score, in hundredths. */
	public const WEIGHTS = array(
		'terms'     => 45,
		'entities'  => 15,
		'questions' => 15,
		'headings'  => 15,
		'length'    => 10,
	);

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_serp_cache';
	}

	/**
	 * Create the table when its version is behind.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
k char(40) NOT NULL,
phrase varchar(191) NOT NULL,
location varchar(64) NOT NULL,
language varchar(16) NOT NULL,
status varchar(10) NOT NULL DEFAULT 'none',
run_id varchar(40) NOT NULL DEFAULT '',
model longtext NULL,
error varchar(255) NOT NULL DEFAULT '',
claimed_at datetime NULL,
fetched_at datetime NULL,
PRIMARY KEY  (id),
UNIQUE KEY k (k),
KEY fetched_at (fetched_at)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * The key of a search: the phrase as matched (lower case, single spaces), the country and the
	 * language.
	 *
	 * @param string $phrase   Phrase.
	 * @param string $location Country (Zinn®'s English name).
	 * @param string $language Language code.
	 * @return string 40 hex characters.
	 */
	public static function key( string $phrase, string $location, string $language ): string {
		return sha1( Text::norm( $phrase ) . '|' . strtolower( trim( $location ) ) . '|' . strtolower( trim( $language ) ) );
	}

	/**
	 * The countries a search can be read in (the toolbox's markets).
	 *
	 * @return array<string, array{location: string, language: string}>
	 */
	public static function markets(): array {
		return class_exists( Tools::class ) ? Tools::markets() : array(
			'US' => array(
				'location' => 'United States',
				'language' => 'en',
			),
		);
	}

	/**
	 * The search a post is optimised for: its first focus phrase, and the country and language
	 * stored with it (else the post's language and the site's country).
	 *
	 * @param int $post_id Post.
	 * @return array{phrase: string, location: string, language: string}
	 */
	public static function search_for( int $post_id ): array {
		$focus  = (string) \ZinnDigital\ZinnSEO\Meta::get( 'post', $post_id, 'focus' );
		$first  = trim( (string) strtok( $focus, "\n" ) );
		$phrase = trim( (string) strtok( $first, '|' ) );
		$stored = get_post_meta( $post_id, self::META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$def    = self::default_market( $post_id );

		return array(
			'phrase'   => $phrase,
			'location' => self::clean_location( (string) ( $stored['location'] ?? '' ) ) ?? $def['location'],
			'language' => self::clean_language( (string) ( $stored['language'] ?? '' ) ) ?? $def['language'],
		);
	}

	/**
	 * The country and language a post is read in by default: the post's language (a translation
	 * plugin's, else the site's) and the country that fits it.
	 *
	 * @param int $post_id Post (0: the site).
	 * @return array{location: string, language: string}
	 */
	public static function default_market( int $post_id = 0 ): array {
		/**
		 * The language a post is written in (a translation plugin answers per post).
		 *
		 * @param string $locale  WordPress locale.
		 * @param int    $post_id Post.
		 */
		$locale = (string) apply_filters( 'zinn_seo_post_locale', self::post_locale( $post_id ), $post_id );
		if ( class_exists( Tools::class ) ) {
			$m = Tools::market_for( $locale );

			return array(
				'location' => $m['location'],
				'language' => $m['language'],
			);
		}

		return array(
			'location' => 'United States',
			'language' => strtolower( (string) strtok( $locale, '_' ) ),
		);
	}

	/**
	 * The language a post is written in, as the translation plugin says (Tranzly, Polylang,
	 * WPML), else the site's.
	 *
	 * @param int $post_id Post (0: the site).
	 * @return string WordPress locale or language tag.
	 */
	public static function post_locale( int $post_id ): string {
		if ( $post_id > 0 ) {
			if ( function_exists( 'tranzly_get_post_language' ) ) {
				$lang = (string) tranzly_get_post_language( $post_id );
				if ( '' !== $lang ) {
					return $lang;
				}
			}
			if ( function_exists( 'pll_get_post_language' ) ) {
				$lang = (string) pll_get_post_language( $post_id, 'locale' );
				if ( '' !== $lang ) {
					return $lang;
				}
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$wpml = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $wpml ) && ! empty( $wpml['locale'] ) ) {
				return (string) $wpml['locale'];
			}
		}

		return (string) get_locale();
	}

	/**
	 * A country, if it is one a search can be read in.
	 *
	 * @param string $location Country name.
	 * @return string|null
	 */
	public static function clean_location( string $location ): ?string {
		foreach ( self::markets() as $m ) {
			if ( 0 === strcasecmp( $m['location'], trim( $location ) ) ) {
				return $m['location'];
			}
		}

		return null;
	}

	/**
	 * A language code (`de`, `pt-br`), if it is one.
	 *
	 * @param string $language Code.
	 * @return string|null
	 */
	public static function clean_language( string $language ): ?string {
		$language = strtolower( str_replace( '_', '-', trim( $language ) ) );

		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $language ) ? $language : null;
	}

	/**
	 * Keep the country and language a post is optimised for.
	 *
	 * @param int    $post_id  Post.
	 * @param string $location Country.
	 * @param string $language Language.
	 * @return void
	 */
	public static function remember( int $post_id, string $location, string $language ): void {
		if ( $post_id > 0 ) {
			update_post_meta(
				$post_id,
				self::META,
				array(
					'location' => $location,
					'language' => $language,
				)
			);
		}
	}

	/**
	 * The most common short words of the writer's language (the translated list the topic
	 * coverage check uses too), sent with a read so its terms leave them out.
	 *
	 * The list is the SEARCH's language's, not the reader's: an editor working in Arabic on an
	 * English post scores it with the English list, exactly as the next writer will.
	 *
	 * @param string $language The search's language code ('' : the language WordPress speaks now).
	 * @return array<int, string>
	 */
	public static function stopwords( string $language = '' ): array {
		$switched = false;
		if ( '' !== $language && function_exists( 'switch_to_locale' ) ) {
			$locale = self::locale_for( $language );
			if ( '' !== $locale && determine_locale() !== $locale ) {
				$switched = switch_to_locale( $locale );
			}
		}
		/* translators: The most common short words of YOUR language (articles, prepositions, pronouns, conjunctions), lower case, separated by commas. Used to leave them out of topic terms. */
		$list = __( 'the, a, an, and, or, of, to, in, on, for, with, is, are, was, were, be, been, by, at, as, it, its, this, that, these, those, from, your, you, we, our, can, will, not, but, more, all, any, if, so, than, then, there, their, they, he, she, his, her, has, have, had, do, does, about, into, over, also, only, just, how, what, why, when, where, which, who, most, other, some, such, no, yes, one, two, new', 'zinn-seo' );

		if ( $switched ) {
			restore_previous_locale();
		}

		return array_values( array_unique( array_filter( array_map( array( Text::class, 'norm' ), Text::split( '/[,،、，]+/u', $list ) ) ) ) );
	}

	/**
	 * The WordPress locale this plugin's translations use for a language code (`de` → `de_DE`):
	 * the first of its installed language packs for that language, or '' when none is installed.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public static function locale_for( string $language ): string {
		$lang = strtolower( (string) strtok( str_replace( '_', '-', $language ), '-' ) );
		if ( 1 !== preg_match( '/^[a-z]{2,3}$/', $lang ) ) {
			return '';
		}
		if ( 'en' === $lang ) {
			return 'en_US';
		}
		$dirs = array( WP_LANG_DIR . '/plugins', dirname( __DIR__, 3 ) . '/languages' );
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '/zinn-seo-' . $lang . '*.mo' ) as $file ) {
				if ( 1 === preg_match( '/zinn-seo-(' . $lang . '(?:_[A-Z]{2})?)\.mo$/', (string) $file, $m ) ) {
					return $m[1];
				}
			}
		}

		return '';
	}

	/**
	 * One kept search, by key.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	public static function row( string $key ): ?array {
		global $wpdb;
		self::maybe_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, read by its unique key.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE k = %s', self::table(), $key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A kept search as the editor uses it.
	 *
	 * @param array<string, mixed>|null $row Row.
	 * @return array<string, mixed>
	 */
	public static function view( ?array $row ): array {
		if ( null === $row ) {
			return array( 'state' => 'none' );
		}
		$state = (string) $row['status'];
		$out   = array(
			'state'    => in_array( $state, array( 'pending', 'ready', 'failed' ), true ) ? $state : 'none',
			'key'      => (string) $row['k'],
			'phrase'   => (string) $row['phrase'],
			'location' => (string) $row['location'],
			'language' => (string) $row['language'],
		);
		$model = json_decode( (string) $row['model'], true );
		if ( is_array( $model ) ) {
			$fetched           = strtotime( (string) $row['fetched_at'] . ' UTC' );
			$age               = false === $fetched ? 0 : (int) floor( max( 0, time() - $fetched ) / DAY_IN_SECONDS );
			$out['model']      = $model;
			$out['fetched_at'] = false === $fetched ? '' : gmdate( 'c', $fetched );
			$out['age_days']   = $age;
			$out['old']        = $age >= self::FRESH_DAYS;
		}
		if ( 'failed' === $state ) {
			$out['error'] = (string) $row['error'];
		}

		return $out;
	}

	/**
	 * What a read costs and whether this person and this account can pay for it now. Reads only
	 * the copy of the account kept for five minutes (never a call per keystroke).
	 *
	 * @param bool $ask Ask Zinn® for the account when no copy is kept.
	 * @return array<string, mixed>
	 */
	public static function money( bool $ask = false ): array {
		$connected = Connection::connected();
		$prices    = class_exists( Credits::class ) ? Credits::prices( ! $ask ) : array();
		$account   = $ask && $connected && class_exists( Credits::class ) ? Credits::account() : null;
		$state     = is_array( $account ) && isset( $account['state'] ) ? (string) $account['state'] : '';
		if ( '' === $state ) {
			$kept  = get_option( Credits::OPTION, array() );
			$state = is_array( $kept ) && isset( $kept['view']['state'] ) ? (string) $kept['view']['state'] : '';
			$buy   = is_array( $kept ) && isset( $kept['view']['buy_url'] ) ? (string) $kept['view']['buy_url'] : '';
			$bal   = is_array( $kept ) && isset( $kept['view']['balance_micros'] ) ? (int) $kept['view']['balance_micros'] : null;
		} else {
			$buy = (string) ( $account['buy_url'] ?? '' );
			$bal = (int) ( $account['balance_micros'] ?? 0 );
		}

		return array(
			'connected'      => $connected,
			'can_spend'      => $connected && Connection::can_spend() && current_user_can( \ZinnDigital\ZinnSEO\Capabilities::SPEND ),
			'price_micros'   => isset( $prices[ self::UNIT ] ) ? (int) $prices[ self::UNIT ] : null,
			'state'          => $state,
			'balance_micros' => $bal,
			'buy_url'        => $buy,
		);
	}

	/**
	 * A read's price for people: whole cents as cents ("$0.05"), anything finer as credit is
	 * shown elsewhere ("$0.0185").
	 *
	 * @param int $micros Millionths of a US dollar.
	 * @return string
	 */
	public static function price_text( int $micros ): string {
		if ( 0 !== $micros % 10000 ) {
			return Credits::money( $micros );
		}

		return '$' . number_format_i18n( intdiv( $micros, 1000000 ) ) . '.' . str_pad( (string) intdiv( $micros % 1000000, 10000 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * The kept model for a search (free: nothing is spent).
	 *
	 * @param string $phrase   Phrase.
	 * @param string $location Country.
	 * @param string $language Language.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function lookup( string $phrase, string $location, string $language ) {
		$search = self::search( $phrase, $location, $language );
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		return $search + self::kept( $search );
	}

	/**
	 * The kept model of a checked search, a pending read followed first (free: Zinn® is only asked
	 * how the read it was already paid for is going). Every reader of a kept model comes here, so
	 * none of them shows "pending" for a read that has answered (ZH6-4: `optimiser score` said
	 * pending for 6 minutes after the read was answered; only `lookup` followed it).
	 *
	 * @param array{phrase: string, location: string, language: string} $search Search.
	 * @return array<string, mixed>
	 */
	public static function kept( array $search ): array {
		$row  = self::row( self::key( $search['phrase'], $search['location'], $search['language'] ) );
		$view = self::view( $row );
		if ( 'pending' === $view['state'] && null !== $row ) {
			$view = self::poll( $row );
		}

		return $view;
	}

	/**
	 * A search, checked.
	 *
	 * @param string $phrase   Phrase.
	 * @param string $location Country.
	 * @param string $language Language.
	 * @return array{phrase: string, location: string, language: string}|\WP_Error
	 */
	public static function search( string $phrase, string $location, string $language ) {
		$phrase = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $phrase ) ) ?? '' );
		if ( '' === $phrase ) {
			return new \WP_Error( 'zinn_seo_opt_phrase', __( 'Add a focus phrase first: the optimiser compares your text with the pages that rank for it.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $phrase ) : strlen( $phrase ) ) > self::MAX_PHRASE ) {
			return new \WP_Error( 'zinn_seo_opt_phrase', __( 'That phrase is too long to search for. Use the words people type, up to 120 characters.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$def = self::default_market();
		$loc = '' === trim( $location ) ? $def['location'] : self::clean_location( $location );
		if ( null === $loc ) {
			return new \WP_Error( 'zinn_seo_opt_market', __( 'Pick a country from the list.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$lang = '' === trim( $language ) ? $def['language'] : self::clean_language( $language );
		if ( null === $lang ) {
			return new \WP_Error( 'zinn_seo_opt_market', __( 'Pick a language from the list.', 'zinn-seo' ), array( 'status' => 400 ) );
		}

		return array(
			'phrase'   => $phrase,
			'location' => $loc,
			'language' => $lang,
		);
	}

	/**
	 * Claim a search for one read: one UPDATE, so two requests at once never both spend.
	 *
	 * @param array{phrase: string, location: string, language: string} $search Search.
	 * @param bool                                                      $again  Read again although a model is kept.
	 * @return bool True when this request may read it now.
	 */
	public static function claim( array $search, bool $again ): bool {
		global $wpdb;
		self::maybe_install();
		$table = self::table();
		$key   = self::key( $search['phrase'], $search['location'], $search['language'] );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; the claim must be one atomic statement.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (k, phrase, location, language, status) VALUES (%s, %s, %s, %s, 'none')",
				$table,
				$key,
				function_exists( 'mb_substr' ) ? mb_substr( $search['phrase'], 0, 191 ) : substr( $search['phrase'], 0, 191 ),
				$search['location'],
				$search['language']
			)
		);
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::PENDING_SECONDS );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// Read again on purpose: any row not being read now. Otherwise: only a row never read, or
		// whose read failed. Either way a read left pending longer than PENDING_SECONDS is free again.
		$done = $again
			? $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending', run_id = '', error = '', claimed_at = %s WHERE k = %s AND ( status <> 'pending' OR claimed_at < %s )", $table, $now, $key, $stale ) )
			: $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending', run_id = '', error = '', claimed_at = %s WHERE k = %s AND ( status IN ('none', 'failed') OR ( status = 'pending' AND claimed_at < %s ) )", $table, $now, $key, $stale ) );
		// phpcs:enable

		return 1 === (int) $done;
	}

	/**
	 * Read the top 10 for a search now (spends credit, once per search). A kept model is returned
	 * as it is unless `$again`.
	 *
	 * @param string $phrase   Phrase.
	 * @param string $location Country.
	 * @param string $language Language.
	 * @param bool   $again    Read again although a model is kept.
	 * @param int    $post_id  Post to remember the country and language for (0: none).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function pull( string $phrase, string $location, string $language, bool $again = false, int $post_id = 0 ) {
		$search = self::search( $phrase, $location, $language );
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		self::remember( $post_id, $search['location'], $search['language'] );
		$key  = self::key( $search['phrase'], $search['location'], $search['language'] );
		$kept = self::view( self::row( $key ) );
		if ( ! $again && 'ready' === $kept['state'] ) {
			return $search + $kept;
		}
		$refused = self::refusal();
		if ( null !== $refused ) {
			return $refused;
		}
		if ( ! self::claim( $search, $again ) ) {
			// Someone is reading it already (or it was read a moment ago): show that, spend nothing.
			return $search + self::view( self::row( $key ) );
		}
		$run = Extra::run(
			self::PRODUCT,
			array(
				'phrase'   => $search['phrase'],
				'location' => $search['location'],
				'language' => $search['language'],
				'stop'     => self::stopwords( $search['language'] ),
			),
			true
		);
		if ( is_wp_error( $run ) ) {
			self::fail( $key, $run->get_error_message() );

			return $run;
		}
		self::store( $key, array( 'run_id' => sanitize_text_field( (string) ( $run['id'] ?? '' ) ) ) );
		/**
		 * Fires when the optimiser asked Zinn® to read the top 10 for a search (credit was spent).
		 *
		 * @param array{phrase: string, location: string, language: string} $search Search.
		 * @param array<string, mixed>                                      $run    The run.
		 */
		do_action( 'zinn_seo_optimiser_pull', $search, $run );

		return $search + self::poll( (array) self::row( $key ), $run );
	}

	/**
	 * Why a read cannot be paid for now (null: it can). Zinn® asks the account's standing again at
	 * the moment of spending; this answers early, with where to add credit.
	 *
	 * @return \WP_Error|null
	 */
	public static function refusal(): ?\WP_Error {
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first: reading the pages that rank is paid from Zinn® credits.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		if ( ! Connection::can_spend() ) {
			return new \WP_Error( 'zinn_seo_opt_no_spend', __( 'This site is connected for viewing only. Ask the account owner to let it use credits.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		$money = self::money( true );
		if ( in_array( $money['state'], array( 'held', 'empty' ), true ) ) {
			return new \WP_Error(
				'zinn_seo_opt_credit',
				'held' === $money['state']
					? __( 'Your Zinn® account is paused, so nothing can be paid for. Add credit or settle the account, then try again.', 'zinn-seo' )
					: __( 'You are out of Zinn® credits. Add credit, then try again.', 'zinn-seo' ),
				array(
					'status'  => 402,
					'buy_url' => $money['buy_url'],
				)
			);
		}

		return null;
	}

	/**
	 * Follow a pending read: ask Zinn® for the run, and keep the model when it is answered.
	 *
	 * @param array<string, mixed>      $row Row.
	 * @param array<string, mixed>|null $run The run, when the caller has it already.
	 * @return array<string, mixed>
	 */
	public static function poll( array $row, ?array $run = null ) {
		$key = (string) ( $row['k'] ?? '' );
		$id  = (string) ( $row['run_id'] ?? '' );
		if ( '' === $key || 'pending' !== ( $row['status'] ?? '' ) ) {
			return self::view( array() === $row ? null : $row );
		}
		if ( null === $run ) {
			if ( '' === $id ) {
				return self::view( $row );
			}
			$got = Extra::run_get( $id );
			if ( is_wp_error( $got ) ) {
				// Not answered yet is not an answer: stay pending (a lost run re-opens after PENDING_SECONDS).
				return self::view( $row );
			}
			$run = $got;
		}
		$status = (string) ( $run['status'] ?? 'queued' );
		if ( in_array( $status, array( 'complete', 'partial' ), true ) ) {
			$model = self::model( (array) ( $run['result'] ?? array() ), (string) ( $row['language'] ?? '' ) );
			if ( array() === $model['pages'] || 0 === $model['read'] ) {
				self::fail( $key, __( 'None of the top results could be read. Your credit was returned.', 'zinn-seo' ) );
			} else {
				self::store(
					$key,
					array(
						'status'     => 'ready',
						'model'      => (string) wp_json_encode( $model ),
						'fetched_at' => gmdate( 'Y-m-d H:i:s' ),
						'error'      => '',
					)
				);
			}
		} elseif ( 'failed' === $status ) {
			$why = sanitize_text_field( (string) ( $run['error'] ?? '' ) );
			self::fail( $key, '' !== $why ? $why : __( 'Zinn® could not read the results. Your credit was returned.', 'zinn-seo' ) );
		}

		return self::view( self::row( $key ) );
	}

	/**
	 * Mark a read failed (a kept model stays: a failed re-read never loses the one we had).
	 *
	 * @param string $key     Key.
	 * @param string $message Why.
	 * @return void
	 */
	private static function fail( string $key, string $message ): void {
		$row = self::row( $key );
		self::store(
			$key,
			array(
				'status' => null !== $row && '' !== (string) $row['model'] ? 'ready' : 'failed',
				'error'  => function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 250 ) : substr( $message, 0, 250 ),
			)
		);
	}

	/**
	 * Update a row.
	 *
	 * @param string               $key    Key.
	 * @param array<string, mixed> $fields Columns.
	 * @return void
	 */
	private static function store( string $key, array $fields ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, by its unique key.
		$wpdb->update( self::table(), $fields, array( 'k' => $key ) );
	}

	/**
	 * Forget a kept search.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function forget( string $key ): bool {
		global $wpdb;
		self::maybe_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, by its unique key.
		return (bool) $wpdb->delete( self::table(), array( 'k' => $key ) );
	}

	/**
	 * Kept searches, newest first, a page at a time (keyset on id).
	 *
	 * @param int $before Rows with an id below this (0: from the newest).
	 * @param int $limit  Rows.
	 * @return array{items: array<int, array<string, mixed>>, next: int}
	 */
	public static function page( int $before = 0, int $limit = 50 ): array {
		global $wpdb;
		self::maybe_install();
		$table = self::table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, keyset paging on the primary key.
		$rows = $before > 0
			? $wpdb->get_results( $wpdb->prepare( 'SELECT id, k, phrase, location, language, status, fetched_at, error FROM %i WHERE id < %d ORDER BY id DESC LIMIT %d', $table, $before, $limit + 1 ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT id, k, phrase, location, language, status, fetched_at, error FROM %i ORDER BY id DESC LIMIT %d', $table, $limit + 1 ), ARRAY_A );
		// phpcs:enable
		$rows = is_array( $rows ) ? $rows : array();
		$next = 0;
		if ( count( $rows ) > $limit ) {
			array_pop( $rows );
			$next = (int) $rows[ count( $rows ) - 1 ]['id'];
		}

		return array(
			'items' => array_map(
				static fn( $r ) => array(
					'key'        => (string) $r['k'],
					'phrase'     => (string) $r['phrase'],
					'location'   => (string) $r['location'],
					'language'   => (string) $r['language'],
					'state'      => (string) $r['status'],
					'fetched_at' => null === $r['fetched_at'] ? '' : gmdate( 'c', (int) strtotime( (string) $r['fetched_at'] . ' UTC' ) ),
				),
				$rows
			),
			'next'  => $next,
		);
	}

	/**
	 * The model a run's answer makes, as the editor scores against it. Only measurements are
	 * kept: the pages' addresses, titles, lengths and headings, and the shared terms, names and
	 * questions — never the pages' text.
	 *
	 * @param array<string, mixed> $result   The `brief` run's result.
	 * @param string               $language The search's language (its common words are kept with the model).
	 * @return array<string, mixed>
	 */
	public static function model( array $result, string $language = '' ): array {
		$common = self::stopwords( $language );
		$stop   = array_fill_keys( $common, true );
		$pages  = array();
		$words  = array();
		foreach ( (array) ( $result['pages'] ?? array() ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$url  = esc_url_raw( (string) ( $p['url'] ?? '' ), array( 'http', 'https' ) );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$out  = array();
			foreach ( (array) ( $p['outline'] ?? array() ) as $h ) {
				if ( is_array( $h ) && '' !== trim( (string) ( $h['text'] ?? '' ) ) ) {
					$out[] = array(
						'level' => max( 1, min( 6, (int) ( $h['level'] ?? 2 ) ) ),
						'text'  => sanitize_text_field( (string) $h['text'] ),
					);
				}
			}
			if ( array() === $out ) {
				foreach ( (array) ( $p['headings'] ?? array() ) as $h ) {
					$out[] = array(
						'level' => 2,
						'text'  => sanitize_text_field( (string) $h ),
					);
				}
			}
			$read = ! empty( $p['read'] ) || ! isset( $p['read'] );
			$w    = isset( $p['words'] ) && null !== $p['words'] ? max( 0, (int) $p['words'] ) : null;
			if ( $read && $w ) {
				$words[] = $w;
			}
			$pages[] = array(
				'position' => (int) ( $p['position'] ?? count( $pages ) + 1 ),
				'url'      => $url,
				'domain'   => preg_replace( '/^www\./', '', $host ) ?? $host,
				'title'    => sanitize_text_field( (string) ( $p['title'] ?? '' ) ),
				'words'    => $w,
				'read'     => $read,
				'outline'  => array_slice( $out, 0, 60 ),
			);
		}
		$read  = count( array_filter( $pages, static fn( $p ) => $p['read'] ) );
		$terms = array();
		foreach ( (array) ( $result['term_stats'] ?? array() ) as $t ) {
			$term = is_array( $t ) ? Text::norm( (string) ( $t['term'] ?? '' ) ) : '';
			if ( '' === $term ) {
				continue;
			}
			// A common word at either end (a one-word term is both ends) is no term to write for.
			$ws = Text::words( $term, false );
			if ( array() !== $ws && ( isset( $stop[ $ws[0] ] ) || isset( $stop[ $ws[ count( $ws ) - 1 ] ] ) ) ) {
				continue;
			}
			$terms[] = array(
				'term'   => $term,
				'pages'  => max( 1, (int) ( $t['pages'] ?? 1 ) ),
				'min'    => max( 1, (int) ( $t['min'] ?? 1 ) ),
				'median' => max( 1, (int) ( $t['median'] ?? 1 ) ),
				'max'    => max( 1, (int) ( $t['max'] ?? 1 ) ),
			);
		}
		$entities = array();
		foreach ( (array) ( $result['entities'] ?? array() ) as $e ) {
			if ( is_array( $e ) && '' !== trim( (string) ( $e['name'] ?? '' ) ) ) {
				$entities[] = array(
					'name'  => sanitize_text_field( (string) $e['name'] ),
					'pages' => max( 1, (int) ( $e['pages'] ?? 1 ) ),
				);
			}
		}
		$questions = array();
		$asked     = array_merge( (array) ( $result['page_questions'] ?? array() ), (array) ( $result['questions'] ?? array() ) );
		$seen      = array();
		foreach ( $asked as $q ) {
			$q = sanitize_text_field( (string) $q );
			$n = rtrim( Text::norm( $q ), ' ?？؟' );
			if ( '' !== $n && ! isset( $seen[ $n ] ) ) {
				$seen[ $n ]  = true;
				$questions[] = $q;
			}
		}

		return array(
			'v'            => 1,
			'phrase'       => sanitize_text_field( (string) ( $result['phrase'] ?? '' ) ),
			'read'         => $read,
			'median_words' => self::median( $words ),
			'words_low'    => self::percentile( $words, 25 ),
			'words_high'   => self::percentile( $words, 75 ),
			'terms'        => $terms,
			'entities'     => $entities,
			'questions'    => array_slice( $questions, 0, 30 ),
			'headings'     => self::topics( $pages, $stop ),
			'pages'        => $pages,
			// The search language's common words, so every reader scores with the same list.
			'stop'         => $common,
		);
	}

	/**
	 * The heading subjects several top pages share: headings grouped when their content words
	 * mostly agree, each group named by its most common wording.
	 *
	 * @param array<int, array<string, mixed>> $pages Pages.
	 * @param array<string, bool>              $stop  Common words.
	 * @return array<int, array{text: string, words: array<int, string>, pages: int}>
	 */
	public static function topics( array $pages, array $stop ): array {
		$groups = array();
		foreach ( $pages as $n => $p ) {
			foreach ( (array) $p['outline'] as $h ) {
				if ( (int) $h['level'] < 2 || (int) $h['level'] > 3 ) {
					continue;
				}
				$ws = self::content_words( (string) $h['text'], $stop );
				if ( array() === $ws || count( $ws ) > 8 ) {
					continue;
				}
				$placed = false;
				foreach ( $groups as &$g ) {
					if ( self::overlap( $ws, $g['words'] ) >= 0.5 && self::overlap( $g['words'], $ws ) >= 0.5 ) {
						$g['pages'][ $n ]                  = true;
						$g['texts'][ (string) $h['text'] ] = ( $g['texts'][ (string) $h['text'] ] ?? 0 ) + 1;
						$placed                            = true;
						break;
					}
				}
				unset( $g );
				if ( ! $placed ) {
					$groups[] = array(
						'words' => $ws,
						'pages' => array( $n => true ),
						'texts' => array( (string) $h['text'] => 1 ),
					);
				}
			}
		}
		$out = array();
		foreach ( $groups as $g ) {
			if ( count( $g['pages'] ) < 2 ) {
				continue;
			}
			arsort( $g['texts'] );
			$out[] = array(
				'text'  => (string) array_key_first( $g['texts'] ),
				'words' => $g['words'],
				'pages' => count( $g['pages'] ),
			);
		}
		usort( $out, static fn( $a, $b ) => $b['pages'] <=> $a['pages'] );

		return array_slice( $out, 0, 30 );
	}

	/**
	 * A text's content words: normalised, not common, not numbers.
	 *
	 * @param string              $text Text.
	 * @param array<string, bool> $stop Common words.
	 * @return array<int, string>
	 */
	public static function content_words( string $text, array $stop ): array {
		$out = array();
		foreach ( Text::words( Text::norm( $text ), false ) as $w ) {
			if ( ! isset( $stop[ $w ] ) && ! is_numeric( $w ) && ( function_exists( 'mb_strlen' ) ? mb_strlen( $w ) : strlen( $w ) ) >= 2 ) {
				$out[] = $w;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The share of `$want` found in `$have` (as the same word or a form of it).
	 *
	 * @param array<int, string> $want Words looked for.
	 * @param array<int, string> $have Words there.
	 * @return float 0..1.
	 */
	public static function overlap( array $want, array $have ): float {
		if ( array() === $want ) {
			return 0.0;
		}
		$found = 0;
		foreach ( $want as $w ) {
			foreach ( $have as $h ) {
				if ( Text::same_word( $h, $w ) || Text::same_word( $w, $h ) ) {
					++$found;
					break;
				}
			}
		}

		return $found / count( $want );
	}

	/**
	 * Median of whole numbers (null for none).
	 *
	 * @param array<int, int> $values Values.
	 * @return int|null
	 */
	public static function median( array $values ): ?int {
		if ( array() === $values ) {
			return null;
		}
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );

		return 1 === $n % 2 ? $values[ $mid ] : intdiv( $values[ $mid - 1 ] + $values[ $mid ], 2 );
	}

	/**
	 * A percentile of whole numbers, nearest rank (null for none).
	 *
	 * @param array<int, int> $values Values.
	 * @param int             $p      Percentile.
	 * @return int|null
	 */
	public static function percentile( array $values, int $p ): ?int {
		if ( array() === $values ) {
			return null;
		}
		sort( $values );
		$rank = (int) max( 1, ceil( $p / 100 * count( $values ) ) );

		return $values[ $rank - 1 ];
	}

	/**
	 * A draft as the score reads it: its text and its headings.
	 *
	 * @param string $html Rendered content.
	 * @return array{text: string, headings: array<int, array{level: int, text: string}>}
	 */
	public static function draft( string $html ): array {
		$doc = Doc::parse( $html );

		return array(
			'text'     => $doc->text(),
			'headings' => array_map(
				static fn( $h ) => array(
					'level' => (int) $h['level'],
					'text'  => (string) $h['text'],
				),
				$doc->headings
			),
		);
	}

	/**
	 * The score of a draft against a model, with what to add and what is overused. The editor's
	 * `score()` (src/editor/optimiser/pure.js) is the same sum, held equal by a shared fixture.
	 *
	 * @param array<string, mixed>                                                       $model Model.
	 * @param array{text: string, headings: array<int, array{level: int, text: string}>} $draft Draft.
	 * @param array<int, string>|null                                                    $stop  Common words (null: the model's own, the search language's).
	 * @return array<string, mixed>
	 */
	public static function score( array $model, array $draft, ?array $stop = null ): array {
		$stop   = array_fill_keys( array_map( array( Text::class, 'norm' ), $stop ?? ( isset( $model['stop'] ) && is_array( $model['stop'] ) ? array_map( 'strval', $model['stop'] ) : self::stopwords() ) ), true );
		$text   = (string) $draft['text'];
		$norm   = Text::norm( $text );
		$length = Text::length( $text );
		$median = (int) ( $model['median_words'] ?? 0 );
		$f      = $median > 0 ? max( 0.25, min( 1.0, $length / $median ) ) : 1.0;
		$read   = max( 1, (int) ( $model['read'] ?? 1 ) );
		$parts  = array();

		// Terms.
		$terms = array();
		$sum   = 0.0;
		$total = 0.0;
		foreach ( (array) ( $model['terms'] ?? array() ) as $t ) {
			$c      = Text::count( $norm, (string) $t['term'] );
			$lo     = max( 1, (int) round( (int) $t['min'] * $f ) );
			$hi     = max( $lo, (int) round( (int) $t['max'] * $f ) );
			$w      = (int) $t['pages'] / $read;
			$status = 'good';
			$credit = 1.0;
			if ( 0 === $c ) {
				$status = 'missing';
				$credit = 0.0;
			} elseif ( $c < $lo ) {
				$status = 'under';
				$credit = $c / $lo;
			} elseif ( $c > $hi + 1 ) {
				$status = 'over';
				$credit = 0.6;
			}
			$sum    += $w * $credit;
			$total  += $w;
			$terms[] = array(
				'term'   => (string) $t['term'],
				'count'  => $c,
				'low'    => $lo,
				'high'   => $hi,
				'pages'  => (int) $t['pages'],
				'status' => $status,
			);
		}
		if ( $total > 0 ) {
			$parts['terms'] = $sum / $total;
		}

		// Names.
		$names = array();
		$sum   = 0.0;
		$total = 0.0;
		foreach ( (array) ( $model['entities'] ?? array() ) as $e ) {
			$has     = Text::contains( $norm, (string) $e['name'], false );
			$w       = (int) $e['pages'];
			$sum    += $has ? $w : 0;
			$total  += $w;
			$names[] = array(
				'name'    => (string) $e['name'],
				'pages'   => $w,
				'present' => $has,
			);
		}
		if ( $total > 0 ) {
			$parts['entities'] = $sum / $total;
		}

		// Questions.
		$heads = array_map( static fn( $h ) => self::content_words( (string) $h['text'], $stop ), (array) $draft['headings'] );
		$have  = self::content_words( $text, $stop );
		$qs    = array();
		$got   = 0;
		foreach ( (array) ( $model['questions'] ?? array() ) as $q ) {
			$ws    = self::content_words( (string) $q, $stop );
			$state = 'missing';
			foreach ( $heads as $h ) {
				if ( array() !== $ws && self::overlap( $ws, $h ) >= 0.6 ) {
					$state = 'answered';
					break;
				}
			}
			if ( 'missing' === $state && array() !== $ws && self::overlap( $ws, $have ) >= 1.0 ) {
				$state = 'mentioned';
			}
			$got += 'answered' === $state ? 2 : ( 'mentioned' === $state ? 1 : 0 );
			$qs[] = array(
				'question' => (string) $q,
				'state'    => $state,
			);
		}
		if ( array() !== $qs ) {
			$parts['questions'] = $got / ( 2 * count( $qs ) );
		}

		// Heading subjects.
		$hs    = array();
		$sum   = 0.0;
		$total = 0.0;
		foreach ( (array) ( $model['headings'] ?? array() ) as $g ) {
			$has = false;
			foreach ( $heads as $h ) {
				if ( self::overlap( (array) $g['words'], $h ) >= 0.5 ) {
					$has = true;
					break;
				}
			}
			$w      = (int) $g['pages'];
			$sum   += $has ? $w : 0;
			$total += $w;
			$hs[]   = array(
				'text'    => (string) $g['text'],
				'pages'   => $w,
				'present' => $has,
			);
		}
		if ( $total > 0 ) {
			$parts['headings'] = $sum / $total;
		}

		// Length.
		if ( $median > 0 ) {
			$r               = $length / $median;
			$parts['length'] = $r < 0.8 ? $r / 0.8 : ( $r > 1.5 ? max( 0.6, 1.5 / $r ) : 1.0 );
		}

		$weight = 0;
		$points = 0.0;
		foreach ( $parts as $name => $value ) {
			$weight += self::WEIGHTS[ $name ];
			$points += self::WEIGHTS[ $name ] * $value;
		}

		return array(
			'score'     => $weight > 0 ? (int) round( 100 * $points / $weight ) : null,
			'parts'     => array_map( static fn( $v ) => (int) round( 100 * $v ), $parts ),
			'words'     => $length,
			'target'    => array(
				'median' => $median ? $median : null,
				'low'    => $model['words_low'] ?? null,
				'high'   => $model['words_high'] ?? null,
			),
			'terms'     => $terms,
			'entities'  => $names,
			'questions' => $qs,
			'headings'  => $hs,
		);
	}

	/**
	 * Score a saved post against the model of the search it is optimised for.
	 *
	 * @param int    $post_id  Post.
	 * @param string $phrase   Phrase ('' : the post's first focus phrase).
	 * @param string $location Country ('' : the post's).
	 * @param string $language Language ('' : the post's).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function score_post( int $post_id, string $phrase = '', string $location = '', string $language = '' ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'That page does not exist.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$for    = self::search_for( $post_id );
		$search = self::search( '' !== $phrase ? $phrase : $for['phrase'], '' !== $location ? $location : $for['location'], '' !== $language ? $language : $for['language'] );
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		$kept = self::kept( $search );
		$out  = $search + array(
			'post'  => $post_id,
			'state' => $kept['state'],
		);
		if ( ! isset( $kept['model'] ) ) {
			return $out + array( 'score' => null );
		}
		// The page as visitors get it — a page builder's saved layout included (the analysis renders it).
		$html = \ZinnDigital\ZinnSEO\Analysis\Render::html( $post );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$html = (string) $html;

		return $out + array(
			'fetched_at' => $kept['fetched_at'],
			'old'        => $kept['old'],
		) + self::score( (array) $kept['model'], self::draft( $html ) );
	}
}
