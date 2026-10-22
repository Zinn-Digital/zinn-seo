<?php
/**
 * This plugin's side of working with Tranzly.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations\Family;

use ZinnDigital\ZinnSEO\Fixit\Fixit;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This side's jobs (the rest is the contract in {@see Family} and Tranzly's own code).
 */
final class Tranzly {

	/** The SEO fields a translation should carry when its original does. */
	private const AUDITED = array( 'title', 'desc' );

	/** The background job that fills them in. */
	public const JOB = 'translate-seo-fields';

	/**
	 * Originals one request to the translation plugin looks at. ONE: an original may have fifty
	 * translations, each a request to an engine that does not batch, and a step must stay well
	 * inside a web request's time. The job's own loop asks again until its deadline.
	 */
	private const STEP_ORIGINALS = 1;

	/**
	 * Hook in.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		// A language on its own domain or subdomain is this site too: its pages belong in the sitemap.
		add_filter( 'zinn_seo_sitemap_allowed_host', array( self::class, 'allowed_host' ), 10, 2 );
		// A saved translation of the site-wide words (the llms.txt summary) is in the cached files.
		add_action( 'updated_option', array( self::class, 'on_strings' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'on_new_strings' ), 10, 2 );
		// Translations made before the two plugins worked together catch up, in the background.
		Jobs::register( self::JOB, static fn() => __( 'Translating search titles and descriptions', 'zinn-seo' ), array( self::class, 'step' ) );
		if ( in_array( $context, array( 'admin', 'rest', 'cli' ), true ) ) {
			add_filter( 'zinn_seo_fixit_sources', array( self::class, 'fixit_source' ) );
			add_action( 'zinn_seo_fixit_actions', array( self::class, 'fixit_action' ) );
		}
	}

	/**
	 * One step of the catch-up job: ask the translation plugin to fill the SEO fields that the
	 * next originals' translations lack (`zinn_seo_translate_fields_step`: Tranzly translates
	 * just those fields with the site's own engine and writes them), until the deadline.
	 *
	 * Resumable from its cursor (the last original done), with no cap on how many there are. When
	 * the translation engine cannot work — none set up, a spending limit reached, the service
	 * down — the job PAUSES with the engine's own words and carries on from the same page when it
	 * is resumed; nothing is skipped.
	 *
	 * @param array<string, mixed> $state    `after` (cursor), `pages`, `filled`, `failed`.
	 * @param float                $deadline Absolute microtime deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$state += array(
			'after'  => 0,
			'pages'  => 0,
			'filled' => 0,
			'failed' => 0,
		);
		do {
			/**
			 * Filters one step of filling the SEO fields that existing translations lack. A
			 * translation plugin answers with `next` (the last original it did, or null when
			 * none is left), `originals`, `filled`, `failed` and `error` ('' or why it stopped).
			 *
			 * @param array<string, mixed>|null $result Null until a translation plugin answers.
			 * @param int                       $after  The last original of the step before.
			 * @param int                       $limit  Originals to look at.
			 */
			$result = apply_filters( 'zinn_seo_translate_fields_step', null, (int) $state['after'], self::STEP_ORIGINALS );
			$read   = self::read_step( $state, $result );
			$state  = $read['state'];
			if ( '' !== $read['pause'] || $read['done'] ) {
				break;
			}
		} while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) );
		if ( $read['done'] ) {
			delete_transient( 'zseo_fixit' );
		}

		return array(
			'state'    => $state,
			'done'     => $read['done'],
			'pause'    => $read['pause'],
			'progress' => array(
				'done'  => (int) $state['filled'],
				'total' => null,
			),
		);
	}

	/**
	 * What one answer of the translation plugin means for the job. Pure.
	 *
	 * @param array<string, mixed> $state  The job's state.
	 * @param mixed                $result The answer.
	 * @return array{state: array<string, mixed>, done: bool, pause: string}
	 */
	public static function read_step( array $state, $result ): array {
		// Nobody answered (no translation plugin does this work on this site): an empty answer,
		// whose missing `next` ends the job.
		$result          = is_array( $result ) ? $result : array();
		$state['pages']  = (int) ( $state['pages'] ?? 0 ) + max( 0, (int) ( $result['originals'] ?? 0 ) );
		$state['filled'] = (int) ( $state['filled'] ?? 0 ) + max( 0, (int) ( $result['filled'] ?? 0 ) );
		$state['failed'] = (int) ( $state['failed'] ?? 0 ) + max( 0, (int) ( $result['failed'] ?? 0 ) );
		$error           = trim( (string) ( $result['error'] ?? '' ) );
		$next            = $result['next'] ?? null;
		if ( '' !== $error ) {
			// The cursor stays where the translation plugin left it: the same page is first again.
			$state['after'] = null === $next ? (int) ( $state['after'] ?? 0 ) : (int) $next;

			return array(
				'state' => $state,
				'done'  => false,
				'pause' => $error,
			);
		}
		// A cursor that does not move would never end: it is the end.
		$done           = null === $next || (int) $next <= (int) ( $state['after'] ?? 0 );
		$state['after'] = $done ? (int) ( $state['after'] ?? 0 ) : (int) $next;

		return array(
			'state' => $state,
			'done'  => $done,
			'pause' => '',
		);
	}

	/**
	 * `zinn_seo_fixit_actions`: the one-click fix of the Fix it item below.
	 *
	 * @return void
	 */
	public static function fixit_action(): void {
		Fixit::action( self::JOB, static fn() => Jobs::start( self::JOB, array() ) );
	}

	/**
	 * The hosts Tranzly serves a language on.
	 *
	 * @return array<int, string>
	 */
	public static function language_hosts(): array {
		static $hosts = null;
		if ( null === $hosts ) {
			$hosts = array();
			if ( function_exists( 'tranzly_languages' ) && function_exists( 'tranzly_language_url' ) ) {
				foreach ( (array) tranzly_languages() as $language ) {
					$host = wp_parse_url( (string) tranzly_language_url( (string) ( $language['code'] ?? '' ), home_url( '/' ) ), PHP_URL_HOST );
					if ( is_string( $host ) && '' !== $host ) {
						$hosts[] = strtolower( $host );
					}
				}
			}
			$hosts = array_values( array_unique( $hosts ) );
		}

		return $hosts;
	}

	/**
	 * `zinn_seo_sitemap_allowed_host`.
	 *
	 * @param mixed $allowed Allowed so far.
	 * @param mixed $host    Host.
	 * @return bool
	 */
	public static function allowed_host( $allowed, $host = '' ): bool {
		return (bool) $allowed || in_array( strtolower( (string) $host ), self::language_hosts(), true );
	}

	/**
	 * A language's strings were saved in Tranzly. When the translation of one of THIS plugin's
	 * site-wide texts changed, the llms.txt files are built again when asked, and
	 * `zinn_seo_site_texts_changed` says so (a page cache then drops its pages: every title made
	 * from a translated pattern changed). Any other string — a menu label, a widget — is not this
	 * plugin's business and announces nothing.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $before Value before.
	 * @param mixed $after  Value now.
	 * @return void
	 */
	public static function on_strings( $option, $before = array(), $after = array() ): void {
		if ( ! is_string( $option ) || 0 !== strpos( $option, 'tranzly_strings_' ) || ! self::texts_differ( (array) $before, (array) $after, Family::site_texts() ) ) {
			return;
		}
		if ( class_exists( '\ZinnDigital\ZinnSEO\Llms\Llms' ) ) {
			\ZinnDigital\ZinnSEO\Llms\Llms::forget();
		}

		/**
		 * Fires when the translation of one of this plugin's site-wide texts (a title or
		 * description pattern, the llms.txt summary, the organisation's description) changed.
		 *
		 * @param string $option The translation plugin's option that changed.
		 */
		do_action( 'zinn_seo_site_texts_changed', $option );
	}

	/**
	 * `added_option`: a language's first strings.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Value.
	 * @return void
	 */
	public static function on_new_strings( $option, $value = array() ): void {
		self::on_strings( $option, array(), $value );
	}

	/**
	 * Does one of these texts have a different translation in the two stores? Tranzly keys a
	 * shared text by the hash of its words (`text.<sha1>`); the site title and the tagline, which
	 * this plugin's patterns print, have keys of their own. Pure.
	 *
	 * @param array<string, mixed> $before Store before.
	 * @param array<string, mixed> $after  Store now.
	 * @param array<int, string>   $texts  This plugin's site-wide texts.
	 * @return bool
	 */
	public static function texts_differ( array $before, array $after, array $texts ): bool {
		$keys = array( 'site.blogname', 'site.blogdescription' );
		foreach ( $texts as $text ) {
			$keys[] = 'text.' . sha1( (string) $text );
		}
		foreach ( $keys as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `zinn_seo_fixit_sources`.
	 *
	 * @param mixed $sources Sources.
	 * @return array<string, mixed>
	 */
	public static function fixit_source( $sources ): array {
		$sources              = (array) $sources;
		$sources['languages'] = array( self::class, 'issues' );

		return $sources;
	}

	/**
	 * Published translations whose original has a written SEO title or description while they
	 * have none of their own, or still carry the original's word for word: in search results
	 * those pages show a pattern, or the wrong language.
	 *
	 * ⭐ One query over Tranzly's translation groups, whatever the number of languages; it starts
	 * from the originals that HAVE the field (an indexed meta-key read), so a site that never
	 * wrote one costs nothing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function issues(): array {
		global $wpdb;
		$builder = '\ZinnDigital\ZinnSEO\Sitemaps\Builder';
		if ( ! function_exists( 'tranzly_default_language' ) || ! class_exists( $builder ) || ! $builder::languages_on() ) {
			return array();
		}
		$table   = (string) $builder::relations_table();
		$default = (string) tranzly_default_language();
		if ( '' === $table || '' === $default ) {
			return array();
		}
		$keys  = array_map( array( Meta::class, 'key' ), self::AUDITED );
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- an admin report, cached five minutes by the Fix-it inbox.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is checked against /^[A-Za-z0-9_]+$/ by Builder::relations_table().
				"SELECT COUNT( DISTINCT t.object_id ) FROM {$wpdb->postmeta} sm INNER JOIN {$table} s ON s.object_type = 'post' AND s.object_id = sm.post_id AND s.lang = %s INNER JOIN {$table} t ON t.group_id = s.group_id AND t.object_type = 'post' AND t.lang <> %s INNER JOIN {$wpdb->posts} p ON p.ID = t.object_id AND p.post_status = 'publish' LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = t.object_id AND tm.meta_key = sm.meta_key WHERE sm.meta_key IN ( %s, %s ) AND sm.meta_value <> '' AND ( tm.meta_id IS NULL OR tm.meta_value = '' OR tm.meta_value = sm.meta_value )",
				$default,
				$default,
				$keys[0],
				$keys[1]
			)
		);
		if ( $count < 1 ) {
			return array();
		}

		return array(
			array(
				'id'       => 'languages-untranslated-seo',
				'area'     => 'content',
				'priority' => 62,
				/* translators: %d: number of pages. */
				'title'    => sprintf( _n( '%d translated page still has no search title or description of its own', '%d translated pages still have no search title or description of their own', $count, 'zinn-seo' ), $count ),
				'detail'   => __( 'The original page has a title or description written for search results, but its translation has none, or still shows the original language. One click translates just those titles and descriptions, in the background, with the translation engine you set up in Tranzly. Nothing else on the pages is changed.', 'zinn-seo' ),
				'count'    => $count,
				'minutes'  => 1,
				'fix'      => array(
					'kind'  => 'action',
					'id'    => self::JOB,
					'label' => __( 'Translate them now', 'zinn-seo' ),
				),
			),
		);
	}
}
