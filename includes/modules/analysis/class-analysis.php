<?php
/**
 * The content analysis: one run, every editor, stored for lists and the Fix-it inbox (zs-001 …).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Readability\Language;
use ZinnDigital\ZinnSEO\Readability\Readability;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;
use ZinnDigital\ZinnSEO\Templates\Vars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups: `seo`, `a11y`, `readability` (with `inclusive`), and what other modules add through
 * `zinn_seo_analysis` (Pro: `answer`, `entities`).
 *
 * ⭐ ONE analysis on the server, called by every surface: the block editor's sidebar (with the
 * editor's unsaved content), the classic editor, Elementor's panel, Page Builder Sandwich (it IS
 * the block editor), the post lists, the Content screen, the Fix-it inbox, REST, WP-CLI and AI
 * agents. Never two implementations that could disagree.
 *
 * Stored per post (private postmeta, never read on a visitor's page): `_zseo_score` (SEO 0-100),
 * `_zseo_rscore` (readability), `_zseo_scored` (unix time), `_zseo_issues` (the failing check
 * ids, for the inbox), `_zseo_fk` (one row per normalised focus phrase, for the duplicates report)
 * and `_zseo_fkh_<hash>` (the same phrase as a META KEY, for the cannibalisation lookup: see
 * `lookup_key()`).
 *
 * Re-scoring is a background job (`analysis`): a save queues the post (one option write); the
 * whole site can be scored from the Content screen. No cap: a million posts take longer, and the
 * job resumes after a host kills it.
 */
final class Analysis {

	/** Focus phrases a free site analyses per post (Pro: unlimited). */
	public const FREE_PHRASES = 3;

	/** Option holding post IDs waiting to be scored. */
	public const QUEUE = 'zseo_analysis_queue';

	/** Job id. */
	public const JOB = 'analysis';

	/** The background job that gives posts scored before 0.17.9 their lookup rows. */
	public const JOB_LOOKUP = 'focus-lookup';

	/** Option set once every stored focus phrase has its lookup row. */
	public const LOOKUP_READY = 'zseo_fk_lookup_ready';

	/** Meta key prefix of a focus phrase's lookup row. */
	public const LOOKUP_PREFIX = '_zseo_fkh_';

	/** Statuses a post is scored in. */
	public const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * IDs queued in this request (written once, at shutdown).
	 *
	 * @var array<int, bool>
	 */
	private static array $queued = array();

	/**
	 * Hooks (admin, REST, CLI, cron).
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		Jobs::register( self::JOB, static fn() => __( 'Scoring the SEO and readability of every page', 'zinn-seo' ), array( self::class, 'step' ) );
		Jobs::register( self::JOB_LOOKUP, static fn() => __( 'Indexing focus phrases for the duplicate check', 'zinn-seo' ), array( self::class, 'lookup_step' ) );
		add_action( 'save_post', array( self::class, 'on_save' ), 30, 2 );
		if ( 'admin' === $context ) {
			add_action( 'admin_init', array( self::class, 'list_hooks' ) );
			add_action( 'admin_init', array( self::class, 'maybe_start_lookup' ) );
		}
	}

	/**
	 * Page builders' own types: templates, headers, footers and pop-ups that are never a page
	 * of their own in search, though WordPress calls them public.
	 */
	public const BUILDER_TYPES = array( 'elementor_library', 'e-floating-buttons', 'athemes_hf', 'et_pb_layout', 'fl-builder-template', 'bricks_template', 'ct_template', 'oxy_user_library', 'kadence_element', 'astra-advanced-hook', 'jet-theme-core', 'brizy_template', 'tbuilder_layout', 'tbuilder_layout_part', 'wp_block' );

	/**
	 * The post types analysed: the public types whose pages people can open and search
	 * engines may list (not attachments, not page-builder templates).
	 *
	 * ⛔ WHY. "Every public type" also counted types with no page at all. On zinnhub.com
	 * (2026-10-15) the inbox read "40,669 pages have not been analysed" and "39,986 have no
	 * focus phrase": 27,835 of them were FAQ items (`ffw`, public but not viewable) and
	 * Elementor templates, which nobody can open and no search engine lists.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		$objects = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			$name             = is_object( $object ) && isset( $object->name ) ? (string) $object->name : ( is_string( $object ) ? $object : (string) $name );
			$objects[ $name ] = $object;
		}
		$types = self::page_types( $objects );
		// The person's choice per type (Search appearance › Content types, Q277): a type can be
		// left out of scores and analysis, or one with no page of its own brought in.
		if ( class_exists( '\ZinnDigital\ZinnSEO\Templates\Content_Types' ) ) {
			$types = array_values( array_filter( $types, static fn( string $t ): bool => \ZinnDigital\ZinnSEO\Templates\Content_Types::scored( $t, true ) ) );
			foreach ( array_keys( \ZinnDigital\ZinnSEO\Templates\Content_Types::post_types() ) as $name ) {
				$name = (string) $name;
				if ( 'attachment' !== $name && ! in_array( $name, $types, true ) && \ZinnDigital\ZinnSEO\Templates\Content_Types::scored( $name, false ) ) {
					$types[] = $name;
				}
			}
		}

		/**
		 * Filters the post types the content analysis runs on.
		 *
		 * @param array<int, string> $types Post types.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'zinn_seo_analysis_types', $types ) ) );
	}

	/**
	 * The types with pages of their own, from the public types. Pure, for the tests.
	 *
	 * @param array<string, mixed> $objects Name => post type object (`public`, `publicly_queryable`,
	 *                                      `_builtin`); anything else is kept, as before.
	 * @return array<int, string>
	 */
	public static function page_types( array $objects ): array {
		$out = array();
		foreach ( $objects as $name => $object ) {
			$name = (string) $name;
			// A built-in public type (post, page) is viewable; any other only when it is
			// publicly queryable, as `is_post_type_viewable()` has it.
			$viewable = ! is_object( $object ) || ( ! empty( $object->_builtin ) ? ! empty( $object->public ) : ! empty( $object->publicly_queryable ) );
			if ( 'attachment' === $name || ! $viewable || in_array( $name, self::BUILDER_TYPES, true ) ) {
				continue;
			}
			$out[] = $name;
		}

		return $out;
	}

	/**
	 * How many focus phrases a post's analysis measures (0 = no limit).
	 *
	 * @return int
	 */
	public static function phrase_limit(): int {
		/**
		 * Filters how many focus phrases per post the analysis measures (0: no limit; Pro lifts it).
		 *
		 * @param int $limit Phrases.
		 */
		return max( 0, (int) apply_filters( 'zinn_seo_analysis_phrase_limit', self::FREE_PHRASES ) );
	}

	/**
	 * Focus phrases from the stored text: one per line, `phrase | synonym, synonym`.
	 *
	 * @param string $text   Stored text.
	 * @param bool   $all    Ignore the free limit.
	 * @return array<int, array{phrase: string, synonyms: array<int, string>}>
	 */
	public static function phrases( string $text, bool $all = false ): array {
		$out = array();
		foreach ( Text::split( '/\R/u', $text ) as $line ) {
			$parts  = explode( '|', $line, 2 );
			$phrase = trim( Doc::squash( $parts[0] ) );
			if ( '' === $phrase ) {
				continue;
			}
			$out[] = array(
				'phrase'   => $phrase,
				'synonyms' => isset( $parts[1] ) ? array_values( array_filter( array_map( 'trim', Text::split( '/[,،、，]+/u', $parts[1] ) ) ) ) : array(),
			);
		}
		$limit = self::phrase_limit();
		if ( ! $all && $limit > 0 ) {
			$out = array_slice( $out, 0, $limit );
		}

		return $out;
	}

	/**
	 * Analyse a post, optionally with the editor's unsaved values.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $draft Unsaved values: content, title, slug, excerpt, seo_title,
	 *                                    seo_desc, focus, related, cornerstone, noindex.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( \WP_Post $post, array $draft = array() ) {
		$copy = clone $post;
		foreach ( array(
			'title'   => 'post_title',
			'slug'    => 'post_name',
			'excerpt' => 'post_excerpt',
		) as $in => $field ) {
			if ( isset( $draft[ $in ] ) && is_string( $draft[ $in ] ) ) {
				$copy->$field = $draft[ $in ];
			}
		}
		if ( isset( $draft['content'] ) && is_string( $draft['content'] ) ) {
			$copy->post_content = $draft['content'];
		}
		$html = Render::html( $copy, isset( $draft['content'] ) && is_string( $draft['content'] ) ? $draft['content'] : null );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$doc    = Doc::parse( $html );
		$c      = Context::for_post( $copy );
		$lookup = Resolver::lookup( $c );
		$sep    = Resolver::sep();
		$own    = static function ( string $field, string $key ) use ( $draft, $post ): string {
			return isset( $draft[ $key ] ) && is_string( $draft[ $key ] ) ? $draft[ $key ] : (string) Meta::get_post( (int) $post->ID, $field );
		};
		$title  = '' !== trim( $own( 'title', 'seo_title' ) ) ? Vars::render( $own( 'title', 'seo_title' ), $lookup, $sep ) : Resolver::auto_title( $c );
		$desc   = '' !== trim( $own( 'desc', 'seo_desc' ) ) ? Vars::render( $own( 'desc', 'seo_desc' ), $lookup, $sep ) : Resolver::auto_description( $c );
		$focus  = self::phrases( $own( 'focus', 'focus' ) );
		$robots = isset( $draft['noindex'] ) ? ( $draft['noindex'] ? array( 'noindex' => true ) : array() ) : Resolver::robots( $c );
		$corner = isset( $draft['cornerstone'] ) ? (bool) $draft['cornerstone'] : (bool) Meta::get_post( (int) $post->ID, 'cornerstone' );
		$locale = Language::of_post( (int) $post->ID );
		$in     = array(
			'title'        => $title,
			'desc'         => $desc,
			'slug'         => (string) $copy->post_name,
			'focus'        => $focus,
			'related'      => array_values( array_filter( array_map( 'trim', Text::split( '/[,\n،、，]+/u', $own( 'related', 'related' ) ) ) ) ),
			'cornerstone'  => $corner,
			'host'         => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'noindex'      => ! empty( $robots['noindex'] ),
			'inbound'      => self::inbound( (int) $post->ID ),
			'used_by'      => self::used_by( $focus, (int) $post->ID ),
			'modified'     => (int) strtotime( (string) $post->post_modified_gmt . ' UTC' ),
			'author_bio'   => self::author_bio( (int) $post->post_author ),
			'quick_answer' => Render::has_block( $copy, 'quick-answer' ),
			'type'         => (string) $post->post_type,
			'locale'       => $locale,
		);

		/**
		 * Filters what the checks know about a page before they run.
		 *
		 * @param array<string, mixed> $in   Input (see Checks).
		 * @param \WP_Post             $copy The post with the editor's unsaved values.
		 */
		$in = (array) apply_filters( 'zinn_seo_analysis_input', $in, $copy );

		$seo  = Checks::seo( $doc, $in );
		$a11y = Checks::a11y( $doc );
		$out  = array(
			'post'        => (int) $post->ID,
			'seo'         => array(
				'score'  => Result::score( $seo ),
				'checks' => $seo,
			),
			'a11y'        => array(
				'score'  => Result::score( $a11y ),
				'checks' => $a11y,
			),
			'readability' => null,
			'answer'      => null,
			'title'       => $title,
			'desc'        => $desc,
			'focus'       => $focus,
			'stats'       => self::stats( $doc ),
			'outline'     => array_map(
				static fn( $h ) => array(
					'level' => (int) $h['level'],
					'text'  => (string) $h['text'],
				),
				$doc->headings
			),
			'language'    => null,
			'phrases_max' => self::phrase_limit(),
			'links'       => self::links_of( $doc, (string) $in['host'] ),
		);
		if ( Modules::enabled( 'readability' ) && class_exists( Readability::class ) ) {
			$profile            = Language::profile( $locale );
			$read               = Readability::checks( $doc, $profile );
			$inclusive          = Readability::inclusive( $doc, (string) $profile['base'] );
			$out['readability'] = array(
				'score'     => Result::score( $read ),
				'checks'    => $read,
				'inclusive' => $inclusive,
			);
			$out['language']    = array(
				'locale'      => $locale,
				'base'        => (string) $profile['base'],
				'curated'     => (bool) $profile['curated'],
				'transitions' => (string) $profile['transitions_source'],
				'passive'     => ! empty( $profile['passive'] ),
				'ease'        => isset( Readability::FLESCH[ (string) ( $profile['flesch'] ?? '' ) ] ),
			);
		}
		/**
		 * Filters an analysis (other modules add groups: link suggestions, entities, AI preview).
		 *
		 * @param array<string, mixed> $out  Result.
		 * @param Doc                  $doc  The rendered page.
		 * @param array<string, mixed> $in   Input.
		 * @param \WP_Post             $copy The post with the editor's unsaved values.
		 */
		return (array) apply_filters( 'zinn_seo_analysis', $out, $doc, $in, $copy );
	}

	/**
	 * The links of a rendered page: `{href, text, internal, rel, image}` (anchors, mail and
	 * phone links left out). The link index stores the internal ones.
	 *
	 * @param Doc    $doc  Page.
	 * @param string $host This site's host.
	 * @return array<int, array<string, mixed>>
	 */
	public static function links_of( Doc $doc, string $host ): array {
		$out = array();
		foreach ( $doc->links as $l ) {
			$kind = Checks::internal( (string) $l['href'], $host );
			if ( null === $kind ) {
				continue;
			}
			$out[] = array(
				'href'     => (string) $l['href'],
				'text'     => (string) $l['text'],
				'internal' => $kind,
				'rel'      => array_values( (array) $l['rel'] ),
				'image'    => null !== $l['image_alt'] && '' === trim( (string) $l['text'] ),
				'alt'      => (string) $l['image_alt'],
			);
		}

		return $out;
	}

	/**
	 * Counts for the editor (words, reading time, links, images).
	 *
	 * @param Doc $doc Page.
	 * @return array<string, mixed>
	 */
	public static function stats( Doc $doc ): array {
		$text     = implode( "\n", $doc->paragraphs() );
		$unspaced = Text::unspaced( $text );
		$length   = Text::length( $text, $unspaced );
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		$outbound = 0;
		foreach ( $doc->links as $l ) {
			$kind = Checks::internal( (string) $l['href'], $host );
			if ( true === $kind ) {
				++$internal;
			} elseif ( false === $kind ) {
				++$outbound;
			}
		}
		$sentences = 0;
		foreach ( $doc->paragraphs() as $p ) {
			$sentences += count( Text::sentences( $p ) );
		}

		return array(
			'length'     => $length,
			'unit'       => $unspaced ? 'char' : 'word',
			'sentences'  => $sentences,
			'paragraphs' => count( $doc->paragraphs() ),
			'headings'   => count( $doc->headings ),
			'images'     => count( $doc->images ),
			'internal'   => $internal,
			'outbound'   => $outbound,
			// About 230 words or 500 characters a minute (the article index block uses the same).
			'minutes'    => max( 1, (int) ceil( $length / ( $unspaced ? 500 : 230 ) ) ),
		);
	}

	/**
	 * Internal links pointing at a post, from the link index (null when it is not built yet).
	 *
	 * @param int $post_id Post.
	 * @return int|null
	 */
	public static function inbound( int $post_id ): ?int {
		/**
		 * Filters the number of internal links pointing at a post (the links module answers).
		 *
		 * @param int|null $count   Count, null when unknown.
		 * @param int      $post_id Post.
		 */
		$count = apply_filters( 'zinn_seo_inbound_links', null, $post_id );

		return null === $count ? null : (int) $count;
	}

	/**
	 * Other posts whose focus is one of these phrases (zs-009, local).
	 *
	 * @param array<int, array<string, mixed>> $focus   Phrases.
	 * @param int                              $post_id This post.
	 * @return array<string, array<int, string>> Normalised phrase => other posts' titles.
	 */
	public static function used_by( array $focus, int $post_id ): array {
		global $wpdb;
		$out   = array();
		$ready = self::lookup_ready();
		foreach ( $focus as $f ) {
			$key = self::focus_key( (string) $f['phrase'] );
			if ( '' === $key ) {
				continue;
			}
			// ⛔ zs-044 (FIN1): `meta_key = '_zseo_fk' AND meta_value = …` has no index on the
			// value, so it read EVERY stored focus phrase (Handler_read_next 76,967 at 77,000
			// scored posts, growing with the site: scoring 1M posts slowed from 87 to 14 a
			// second). The phrase's own meta key is one indexed lookup of its own rows.
			$rows = $ready
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed meta_key lookup per phrase.
				? $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.post_id <> %d AND p.post_status IN ('publish','future','private') LIMIT 5", self::lookup_key( $key ), $post_id ), ARRAY_A )
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- until the lookup rows exist (lookup_step()).
				: $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_zseo_fk' AND m.meta_value = %s AND m.post_id <> %d AND p.post_status IN ('publish','future','private') LIMIT 5", $key, $post_id ), ARRAY_A );
			if ( is_array( $rows ) && array() !== $rows ) {
				$out[ Text::norm( (string) $f['phrase'] ) ] = array_map( static fn( $r ) => '' !== (string) $r['post_title'] ? (string) $r['post_title'] : '#' . (int) $r['ID'], $rows );
			}
		}

		return $out;
	}

	/**
	 * The stored key of a focus phrase (normalised, at most 191 characters).
	 *
	 * @param string $phrase Phrase.
	 * @return string
	 */
	public static function focus_key( string $phrase ): string {
		$n = Text::norm( $phrase );

		return function_exists( 'mb_substr' ) ? mb_substr( $n, 0, 191 ) : substr( $n, 0, 191 );
	}

	/**
	 * The meta KEY a stored focus phrase is looked up by (core indexes meta_key, never
	 * meta_value): the prefix and 16 hex characters of the phrase's md5.
	 *
	 * @param string $key A focus_key().
	 * @return string
	 */
	public static function lookup_key( string $key ): string {
		return self::LOOKUP_PREFIX . substr( md5( $key ), 0, 16 );
	}

	/**
	 * Write a phrase's lookup row. ⛔ Straight to the table, not add_post_meta(): every meta key
	 * the meta API sees becomes a new filter NAME (`sanitize_post_meta_<key>`,
	 * `add_post_metadata`…), and WordPress counts every filter name it runs for did_filter() —
	 * one array entry per distinct key for the life of the process: +4,026 entries and 270 bytes
	 * per post scored, i.e. memory that grows with the site (zs-044, measured). These rows are
	 * an internal index nobody reads through the meta API, so the post's meta cache is dropped.
	 *
	 * @param int    $post_id Post.
	 * @param string $key     A focus_key().
	 * @return void
	 */
	public static function add_lookup_row( int $post_id, string $key ): void {
		global $wpdb;
		$lookup = self::lookup_key( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an indexed existence check of the plugin's own index row.
		if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $post_id, $lookup ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above: the meta API would register a filter name per key.
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $lookup, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the indexed column is the point.
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a constant.
				),
				array( '%d', '%s', '%s' )
			);
		}
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Remove a post's lookup rows (before it is scored again). Direct, for the reason above.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function drop_lookup_rows( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the post_id index, then its own prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like( self::LOOKUP_PREFIX ) . '%' ) );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Does every stored focus phrase have its lookup row yet?
	 *
	 * @return bool
	 */
	public static function lookup_ready(): bool {
		static $ready = null;
		if ( true === $ready ) {
			return true;
		}
		if ( (bool) get_option( self::LOOKUP_READY, false ) ) {
			$ready = true;
			return true;
		}
		global $wpdb;
		// A site that never stored a focus phrase (a new install) has nothing to backfill.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed probe until the flag is set.
		if ( null === $wpdb->get_var( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_zseo_fk' LIMIT 1" ) ) {
			update_option( self::LOOKUP_READY, 1, false );
			$ready = true;
			return true;
		}

		return false;
	}

	/**
	 * Start the lookup rows' backfill once (a site scored before 0.17.9). A site with nothing
	 * stored finishes in one step.
	 *
	 * @return void
	 */
	public static function maybe_start_lookup(): void {
		if ( self::lookup_ready() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$job = Jobs::get( self::JOB_LOOKUP );
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'pending', 'paused' ), true ) || Jobs::running( $job ) ) {
			return;
		}
		Jobs::start( self::JOB_LOOKUP, array( 'after' => 0 ) );
	}

	/**
	 * One step of the backfill: every `_zseo_fk` row by meta_id (keyset on the meta_key index,
	 * which carries the id), each given its lookup row. Uncapped and resumable.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function lookup_step( array $state, float $deadline ): array {
		global $wpdb;
		$after    = (int) ( $state['after'] ?? 0 );
		$done     = (int) ( $state['done'] ?? 0 );
		$finished = false;
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset over the meta_key index.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_zseo_fk' AND meta_id > %d ORDER BY meta_id ASC LIMIT 500", $after ), ARRAY_A );
			if ( ! is_array( $rows ) || array() === $rows ) {
				$finished = true;
				break;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['meta_id'];
				self::add_lookup_row( (int) $row['post_id'], (string) $row['meta_value'] );
				++$done;
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		if ( $finished ) {
			update_option( self::LOOKUP_READY, 1, false );
		}

		return array(
			'state'    => array(
				'after' => $after,
				'done'  => $done,
			),
			'done'     => $finished,
			'progress' => array(
				'done'  => $done,
				'total' => null,
			),
		);
	}

	/**
	 * Does the author have a biography or expertise?
	 *
	 * @param int $user_id Author.
	 * @return bool
	 */
	public static function author_bio( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( '' !== trim( (string) get_the_author_meta( 'description', $user_id ) ) ) {
			return true;
		}
		foreach ( (array) get_user_meta( $user_id ) as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_zseo_' ) && '' !== trim( (string) ( is_array( $value ) ? ( $value[0] ?? '' ) : $value ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Store a post's scores (the post list, the Content screen and the inbox read them).
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $result  run() on the SAVED post.
	 * @return void
	 */
	public static function store( int $post_id, array $result ): void {
		$seo  = $result['seo']['score'] ?? null;
		$read = $result['readability']['score'] ?? null;
		null === $seo ? delete_post_meta( $post_id, '_zseo_score' ) : update_post_meta( $post_id, '_zseo_score', (int) $seo );
		null === $read ? delete_post_meta( $post_id, '_zseo_rscore' ) : update_post_meta( $post_id, '_zseo_rscore', (int) $read );
		update_post_meta( $post_id, '_zseo_scored', time() );
		$issues = array();
		foreach ( array( 'seo', 'a11y', 'readability' ) as $group ) {
			foreach ( (array) ( $result[ $group ]['checks'] ?? array() ) as $c ) {
				if ( 'bad' === ( $c['status'] ?? '' ) && (int) ( $c['weight'] ?? 0 ) > 0 ) {
					$issues[] = (string) $c['id'];
				}
			}
		}
		$issues = array_values( array_unique( $issues ) );
		array() === $issues ? delete_post_meta( $post_id, '_zseo_issues' ) : update_post_meta( $post_id, '_zseo_issues', implode( ',', $issues ) );
		self::drop_lookup_rows( $post_id );
		delete_post_meta( $post_id, '_zseo_fk' );
		$keys = array();
		foreach ( (array) ( $result['focus'] ?? array() ) as $f ) {
			$key = self::focus_key( (string) ( $f['phrase'] ?? '' ) );
			if ( '' !== $key && ! isset( $keys[ $key ] ) ) {
				$keys[ $key ] = true;
				add_post_meta( $post_id, '_zseo_fk', $key );
				self::add_lookup_row( $post_id, $key );
			}
		}

		/**
		 * Fires after a post's analysis was stored.
		 *
		 * @param int                  $post_id Post.
		 * @param array<string, mixed> $result  The analysis.
		 */
		do_action( 'zinn_seo_analysis_stored', $post_id, $result );
	}

	/**
	 * Analyse the saved post and store it.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function score( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::types(), true ) ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID can be analysed.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$result = self::run( $post );
		if ( ! is_wp_error( $result ) ) {
			self::store( $post_id, $result );
		}

		return $result;
	}

	/**
	 * A post was saved: queue it to be scored (one option write per request, at shutdown).
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save( $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) || ! in_array( $post->post_status, self::STATUSES, true ) || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}
		if ( array() === self::$queued ) {
			add_action( 'shutdown', array( self::class, 'flush_queue' ) );
		}
		self::$queued[ (int) $post_id ] = true;
	}

	/**
	 * Write the queued IDs and start the job.
	 *
	 * @return void
	 */
	public static function flush_queue(): void {
		if ( array() === self::$queued ) {
			return;
		}
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		foreach ( array_keys( self::$queued ) as $id ) {
			$queue[ (int) $id ] = time();
		}
		self::$queued = array();
		update_option( self::QUEUE, $queue, false );
		$job = Jobs::get( self::JOB );
		if ( 'pending' !== $job['status'] ) {
			Jobs::start( self::JOB, array( 'mode' => 'queue' ) );
		}
	}

	/**
	 * Score everything (the Content screen's "Score every page").
	 *
	 * @return true|\WP_Error
	 */
	public static function start_all() {
		return Jobs::start(
			self::JOB,
			array(
				'mode'  => 'all',
				'after' => 0,
			)
		);
	}

	/**
	 * One step of the job: the queue first, then (mode all) every post by ID.
	 *
	 * ⛔ zs-044 (FIN1, 2026-10-14): the job renders every page ONCE, so it renders as a sweep
	 * (`Render::sweeping()`, as the link index does since T1). Before, each page's rendered copy
	 * went into a 10-minute transient nobody read again: scoring 20,000 posts peaked at 121.8 MB
	 * (a whole-site job's ceiling is 96 MB) and left a wp_options row per post — at 1M posts, 1M
	 * rows on the table every request reads.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		// A site scored before 0.17.9 first gives its stored phrases their lookup rows, so the
		// scoring below never falls back to the scan (the job carries on by itself after).
		if ( ! self::lookup_ready() ) {
			$lookup          = self::lookup_step( (array) ( $state['lookup'] ?? array() ), $deadline );
			$state['lookup'] = $lookup['state'];
			if ( ! $lookup['done'] ) {
				return array(
					'state'    => $state,
					'done'     => false,
					'progress' => array(
						'done'  => (int) ( $state['done'] ?? 0 ),
						'total' => $state['total'] ?? null,
					),
				);
			}
		}
		Render::sweeping( true );
		try {
			return self::sweep_step( $state, $deadline );
		} finally {
			Render::sweeping( false );
			// A step that stopped part-way through a batch releases that batch too.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
	}

	/**
	 * The work of one step (see step()).
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	private static function sweep_step( array $state, float $deadline ): array {
		global $wpdb;
		$done  = (int) ( $state['done'] ?? 0 );
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		while ( array() !== $queue && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$id = (int) array_key_first( $queue );
			unset( $queue[ $id ] );
			update_option( self::QUEUE, $queue, false );
			$post = get_post( $id );
			// Skip what the editor already stored after this save.
			if ( $post instanceof \WP_Post && (int) get_post_meta( $id, '_zseo_scored', true ) < (int) strtotime( $post->post_modified_gmt . ' UTC' ) ) {
				self::score( $id );
			}
			++$done;
			$queue = get_option( self::QUEUE, array() );
			$queue = is_array( $queue ) ? $queue : array();
		}
		if ( 'all' !== ( $state['mode'] ?? 'queue' ) ) {
			return array(
				'state'    => array(
					'mode' => 'queue',
					'done' => $done,
				),
				'done'     => array() === $queue,
				'progress' => array(
					'done'  => $done,
					'total' => $done + count( $queue ),
				),
			);
		}
		$after = (int) ( $state['after'] ?? 0 );
		$types = self::types();
		$total = $state['total'] ?? null;
		if ( null === $total ) {
			$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$st = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; once per job.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status IN ({$st})", array_merge( $types, self::STATUSES ) ) );
		}
		$finished = false;
		$longest  = 0.0; // HARDEN1: a page that would not fit is not started (the longest lately; the step's first always runs).
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$st = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset by ID; placeholders built above.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$in}) AND post_status IN ({$st}) ORDER BY ID ASC LIMIT 50", array_merge( array( $after ), $types, self::STATUSES ) ) );
			if ( ! is_array( $ids ) || array() === $ids ) {
				$finished = true;
				break;
			}
			update_meta_cache( 'post', array_map( 'intval', $ids ) );
			foreach ( $ids as $id ) {
				if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline, $longest ) ) {
					break 2;
				}
				$began = microtime( true );
				self::score( (int) $id );
				$longest = max( microtime( true ) - $began, 0.8 * $longest );
				$after   = (int) $id;
				++$done;
			}
			\ZinnDigital\ZinnSEO\Jobs::free();
		}

		return array(
			'state'    => array(
				'mode'  => 'all',
				'after' => $after,
				'done'  => $done,
				'total' => $total,
			),
			'done'     => $finished,
			'progress' => array(
				'done'  => $done,
				'total' => (int) $total,
			),
		);
	}

	/**
	 * Post list screens: the SEO column, its sorting and the score filter (zs-011).
	 *
	 * @return void
	 */
	public static function list_hooks(): void {
		foreach ( self::types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( self::class, 'sortable' ) );
		}
		add_action( 'restrict_manage_posts', array( self::class, 'filter_select' ), 10, 1 );
		add_action( 'pre_get_posts', array( self::class, 'filter_query' ) );
		add_action( 'admin_head-edit.php', array( self::class, 'list_style' ) );
	}

	/**
	 * Add the column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		$columns             = (array) $columns;
		$columns['zseo_seo'] = __( 'SEO', 'zinn-seo' );

		return $columns;
	}

	/**
	 * The ratings' words.
	 *
	 * @param string $rating good|ok|bad|none.
	 * @return string
	 */
	public static function rating_label( string $rating ): string {
		switch ( $rating ) {
			case 'good':
				return __( 'Good', 'zinn-seo' );
			case 'ok':
				return __( 'Could be better', 'zinn-seo' );
			case 'bad':
				return __( 'Needs work', 'zinn-seo' );
		}

		return __( 'Not analysed yet', 'zinn-seo' );
	}

	/**
	 * Print a row's column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		if ( 'zseo_seo' !== $column ) {
			return;
		}
		$post_id = (int) $post_id;
		$score   = get_post_meta( $post_id, '_zseo_score', true );
		$read    = get_post_meta( $post_id, '_zseo_rscore', true );
		$parts   = array();
		foreach ( array(
			array( __( 'SEO', 'zinn-seo' ), $score ),
			array( __( 'Readability', 'zinn-seo' ), $read ),
		) as $row ) {
			$value   = '' === $row[1] ? null : (int) $row[1];
			$rating  = Result::rating( $value );
			$parts[] = '<span class="zseo-pill zseo-pill--' . esc_attr( $rating ) . '"><span class="zseo-pill__label">' . esc_html( $row[0] ) . '</span> ' . ( null === $value ? '&ndash;' : esc_html( (string) $value ) ) . '<span class="screen-reader-text">, ' . esc_html( self::rating_label( $rating ) ) . '</span></span>';
		}
		$focus = self::phrases( (string) Meta::get_post( $post_id, 'focus' ), true );
		$extra = '';
		if ( array() !== $focus ) {
			$extra .= '<span class="zseo-col-focus">' . esc_html( $focus[0]['phrase'] ) . '</span>';
		}
		if ( Meta::get_post( $post_id, 'cornerstone' ) ) {
			$extra .= '<span class="zseo-col-corner">' . esc_html__( 'Cornerstone', 'zinn-seo' ) . '</span>';
		}
		/**
		 * Filters the extra lines under a post's SEO scores in the post list (the links module adds
		 * its link counts).
		 *
		 * @param string $extra   HTML (escaped).
		 * @param int    $post_id Post.
		 */
		$extra = (string) apply_filters( 'zinn_seo_list_column', $extra, $post_id );
		echo wp_kses_post( implode( ' ', $parts ) . ( '' !== $extra ? '<br>' . $extra : '' ) );
	}

	/**
	 * Sortable by SEO score.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function sortable( $columns ): array {
		$columns             = (array) $columns;
		$columns['zseo_seo'] = 'zseo_score';

		return $columns;
	}

	/**
	 * The score filter above the list.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public static function filter_select( $post_type ): void {
		if ( ! is_string( $post_type ) || ! in_array( $post_type, self::types(), true ) ) {
			return;
		}
		$current = isset( $_GET['zseo_filter'] ) ? sanitize_key( wp_unslash( $_GET['zseo_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, read only.
		$choices = array(
			''            => __( 'All SEO scores', 'zinn-seo' ),
			'bad'         => __( 'SEO: needs work', 'zinn-seo' ),
			'ok'          => __( 'SEO: could be better', 'zinn-seo' ),
			'good'        => __( 'SEO: good', 'zinn-seo' ),
			'rbad'        => __( 'Readability: needs work', 'zinn-seo' ),
			'none'        => __( 'Not analysed yet', 'zinn-seo' ),
			'nofocus'     => __( 'No focus phrase', 'zinn-seo' ),
			'cornerstone' => __( 'Cornerstone content', 'zinn-seo' ),
			'noindex'     => __( 'Hidden from search (noindex)', 'zinn-seo' ),
		);

		/**
		 * Filters the SEO filter's choices on post lists (the links module adds orphans).
		 *
		 * @param array<string, string> $choices Value => label.
		 */
		$choices = (array) apply_filters( 'zinn_seo_list_filters', $choices );
		echo '<label for="zseo-filter" class="screen-reader-text">' . esc_html__( 'Filter by SEO', 'zinn-seo' ) . '</label>';
		echo '<select name="zseo_filter" id="zseo-filter">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Apply the filter and the sort.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function filter_query( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! is_admin() ) {
			return;
		}
		if ( 'zseo_score' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_zseo_score' );
			$query->set( 'orderby', 'meta_value_num' );
		}
		$filter = isset( $_GET['zseo_filter'] ) ? sanitize_key( wp_unslash( $_GET['zseo_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, read only.
		if ( '' === $filter ) {
			return;
		}
		$meta = (array) $query->get( 'meta_query' );
		switch ( $filter ) {
			case 'bad':
			case 'rbad':
				$meta[] = array(
					'key'     => 'rbad' === $filter ? '_zseo_rscore' : '_zseo_score',
					'value'   => 50,
					'compare' => '<',
					'type'    => 'NUMERIC',
				);
				break;
			case 'ok':
				$meta[] = array(
					'key'     => '_zseo_score',
					'value'   => array( 50, 74 ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				);
				break;
			case 'good':
				$meta[] = array(
					'key'     => '_zseo_score',
					'value'   => 75,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				);
				break;
			case 'none':
				$meta[] = array(
					'key'     => '_zseo_scored',
					'compare' => 'NOT EXISTS',
				);
				break;
			case 'nofocus':
				$meta[] = array(
					'key'     => '_zseo_focus',
					'compare' => 'NOT EXISTS',
				);
				break;
			case 'cornerstone':
				$meta[] = array(
					'key'   => '_zseo_cornerstone',
					'value' => '1',
				);
				break;
			case 'noindex':
				$meta[] = array(
					'key'     => '_zseo_robots',
					'value'   => '"noindex"',
					'compare' => 'LIKE',
				);
				break;
			default:
				/**
				 * Fires for an SEO list filter this module does not know (the links module's).
				 *
				 * @param string    $filter Filter value.
				 * @param \WP_Query $query  Query.
				 */
				do_action( 'zinn_seo_list_filter', $filter, $query );
				return;
		}
		$query->set( 'meta_query', $meta );
	}

	/**
	 * The column's few styles, on post lists only (an inline style on a handle of its own, no
	 * stylesheet file to load).
	 *
	 * @param string $hook_suffix Screen.
	 * @return void
	 */
	public static function list_style( $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}
		wp_register_style( 'zinn-seo-list', false, array(), ZINN_SEO_VERSION );
		wp_enqueue_style( 'zinn-seo-list' );
		wp_add_inline_style(
			'zinn-seo-list',
			'.column-zseo_seo{inline-size:11em}'
			. '.zseo-pill{display:inline-block;margin-block:1px;margin-inline-end:4px;padding-block:1px;padding-inline:6px;border-radius:9px;font-size:12px;line-height:1.5;background:#f0f0f1;color:#1d2327;white-space:nowrap}'
			. '.zseo-pill--good{background:#d8f0dd;color:#0a5c1f}.zseo-pill--ok{background:#fcf0d0;color:#6b4800}.zseo-pill--bad{background:#fbe0de;color:#8a1f11}'
			. '.zseo-pill__label{font-weight:600}'
			. '.zseo-col-focus,.zseo-col-corner,.zseo-col-links{display:inline-block;margin-inline-end:6px;font-size:12px;color:#50575e}'
			. '.zseo-col-corner{color:#6b4800;font-weight:600}'
		);
	}
}
