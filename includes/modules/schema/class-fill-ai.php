<?php
/**
 * AI schema fill in bulk (zs-765): FAQ, how-to steps, product details and article details written
 * by AI from each page across the whole site, checked, previewed, then used with one-click undo.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Ai\Ai;
use ZinnDigital\ZinnSEO\Ai\Bulk_Context;
use ZinnDigital\ZinnSEO\Ai\Free_Tasks;
use ZinnDigital\ZinnSEO\Ai\Suggestions;
use ZinnDigital\ZinnSEO\Ai\Tasks;
use ZinnDigital\ZinnSEO\Bulk\History;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ BATCHED AND PAID FOR ONCE (CLAUDE.md §2.19, §2.45): several pages share one request (a
 * kind's `pack`), and on Zinn® credits `CREDIT_REQUESTS` requests travel in one call. A page that
 * already has a suggestion waiting for the kind is never selected again, so a run killed half-way
 * or started twice pays for nothing twice.
 *
 * ⛔⛔ NO CAP (§2.10): a run covers every page its scope selects, keyset by post id, as much per
 * step as the step's time box allows.
 *
 * ⛔ PAUSES, NEVER DROPS (§2.46): the Zinn® API asks the account's standing and credit before it
 * spends anything. When it refuses (no credit, a spending limit, an account on hold, not
 * connected) or the site owner's own key stops working, the run PAUSES with the reason; a top-up
 * resumes it by itself, anything else with one click. A provider outage waits five minutes.
 *
 * ⛔ NOTHING VISIBLE CHANGES UNTIL A PERSON SAYS SO (zs-200): the AI writes suggestions; the
 * review shows each one with its check against schema.org and Google's rules; "Use" (one, the
 * selected or all that pass) writes them as ONE batch of the bulk history, undone in one click.
 *
 * ⛔ No user switching (WordPress.org): a background step runs as nobody inside Bulk_Context,
 * which lets the AI read the pages the person who started the run was allowed to choose; the
 * rights were checked when the run started (manage SEO + use AI).
 */
final class Fill_Ai {

	/** The generation job. */
	public const JOB = 'schema-fill';

	/** The "use them" job. */
	public const JOB_APPLY = 'schema-fill-apply';

	/** Requests per call on Zinn® credits. */
	public const CREDIT_REQUESTS = 3;

	/** Pages per request, per kind (one call answers several pages: batched). */
	public const PACK = array(
		'faq'     => 3,
		'howto'   => 2,
		'product' => 5,
		'article' => 5,
	);

	/**
	 * The REFERENCE price of one page on Zinn® credits, measured on our own account (millionths
	 * of a US dollar, per page, averaged over a run), and when: what a site is shown until its own
	 * first run. ⛔ A measured price, never a typed guess (CLAUDE.md §2.45); re-measure when the
	 * model behind credits changes. ⭐ After a site's first run its estimate follows ITS OWN runs
	 * (price_for()): a page's price depends on how much text it has, and zinnhub's long articles
	 * cost 2.4x the demo's short guides (ZH6-2).
	 */
	public const MEASURED = array(
		'faq'     => 1180,
		'howto'   => 1579,
		'product' => 609,
		'article' => 1065,
	);

	/**
	 * When MEASURED was measured: `ZSEO_COST_LONG=1 wp/tests/perf/zinn-seo-sch1-ai-cost.sh` on
	 * the live Zinn® API, internal org, the demo's content made FULL LENGTH (past the most text
	 * the AI is sent), 10 pages per kind; the balance fell by exactly the 35,768 micros the API
	 * reported. A full-length page is quoted so the figure shown before a first run is not beaten
	 * by the bill: the same day the demo's short pages cost faq 576, how-to 685, article 452,
	 * product 214 (14,768 micros, also exact), and zinnhub's real articles 1,034 (ZH6-2).
	 */
	public const MEASURED_ON = '2026-10-17';

	/** Option: what this site's own runs cost per page, per kind (not autoloaded). */
	public const PRICES = 'zseo_schema_fill_prices';

	/** Runs kept per kind (the recent ones: an old model's price ages out). */
	public const PRICE_RUNS = 20;

	/** The AI task id of a kind. */
	public const TASK_PREFIX = 'schema-';

	/** Fewer characters of text than this: too little to write an FAQ, steps or an article from. */
	public const SHORT = 200;

	/**
	 * Hooks: the tasks (once the AI module is up) and the jobs.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( did_action( 'zinn_seo_ai_tasks' ) ) {
			self::register_tasks();
		} else {
			add_action( 'zinn_seo_ai_tasks', array( self::class, 'register_tasks' ) );
		}
		Jobs::register( self::JOB, static fn() => __( 'AI structured data for the whole site', 'zinn-seo' ), array( self::class, 'step' ) );
		Jobs::register( self::JOB_APPLY, static fn() => __( 'Using reviewed structured data', 'zinn-seo' ), array( self::class, 'apply_step' ) );
		add_action( 'zinn_seo_credit_state', array( self::class, 'on_credit' ), 10, 2 );
		add_action( 'zinn_seo_ai_ran', array( self::class, 'on_ran' ), 10, 4 );
		add_action( 'zinn_seo_credit_news', array( self::class, 'on_news' ) );
	}

	/**
	 * Is AI available (the AI module is on)?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( Tasks::class ) && null !== Tasks::get( self::task( 'faq' ) );
	}

	/**
	 * The task id of a kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function task( string $kind ): string {
		return self::TASK_PREFIX . $kind;
	}

	/**
	 * Register the four AI tasks (free: paid with Zinn® credits or the owner's own key).
	 *
	 * @return void
	 */
	public static function register_tasks(): void {
		foreach ( Fill::KINDS as $kind ) {
			Tasks::register( self::task( $kind ), self::definition( $kind ) );
		}
	}

	/**
	 * A kind's task definition.
	 *
	 * @param string $kind Kind.
	 * @return array<string, mixed>
	 */
	public static function definition( string $kind ): array {
		$base = array(
			'tier'    => 'free',
			'bulk'    => false,
			'subject' => 'post',
			'pack'    => self::PACK[ $kind ],
			'store'   => true,
			'input'   => static fn( int $id ) => self::input( $kind, $id ),
			'clean'   => static fn( array $a ): array => Fill::clean( $kind, $a ),
			'current' => static fn( int $id ): array => Fill::get( $id, $kind ),
			'apply'   => static fn( int $id, array $fields ) => self::apply_one( $kind, $id, $fields ),
		);
		$text = array( 'type' => 'string' );
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		switch ( $kind ) {
			case 'faq':
				return $base + array(
					'label'       => static fn() => __( 'FAQ structured data from the page', 'zinn-seo' ),
					'per_item'    => 1200,
					'fields'      => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'question' => $text,
									'answer'   => $text,
								),
								'required'             => array( 'question', 'answer' ),
								'additionalProperties' => false,
							),
						),
					),
					'instruction' => static fn() => 'You write the FAQ section for a web page, to be shown under its text and given to search engines as FAQ structured data. Three to six questions a real visitor asks about the page\'s subject, each answered in one to three sentences using ONLY facts the page states. Do not repeat the page\'s headings word for word. When the page does not answer any question a visitor would ask (a contact page, a list of links), answer an empty list.',
				);
			case 'howto':
				return $base + array(
					'label'       => static fn() => __( 'How-to steps from the page', 'zinn-seo' ),
					'per_item'    => 1500,
					'fields'      => array(
						'name'        => $text,
						'description' => $text,
						'minutes'     => array( 'type' => 'integer' ),
						'supplies'    => $list,
						'tools'       => $list,
						'steps'       => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'name' => $text,
									'text' => $text,
								),
								'required'             => array( 'name', 'text' ),
								'additionalProperties' => false,
							),
						),
					),
					'instruction' => static fn() => 'You turn the instructions in a web page into structured how-to steps. "name": what it shows how to do. "description": one sentence. "minutes": the total time when the page states it, else 0. "supplies" and "tools": only those the page names. "steps": each with a short "name" and a "text" of one to three sentences, in the page\'s order. When the page holds no step-by-step instructions, answer an empty list of steps.',
				);
			case 'product':
				return $base + array(
					'label'       => static fn() => __( 'Product details from the product page', 'zinn-seo' ),
					'per_item'    => 300,
					'fields'      => array(
						'brand'    => $text,
						'model'    => $text,
						'material' => $text,
						'color'    => $text,
						'size'     => $text,
					),
					'instruction' => static fn() => 'You read a shop\'s product page and its facts and give the product\'s brand, model, material, colour and size for structured data. Give a value ONLY when the page or its facts state it; otherwise an empty string. Never guess a brand from the shop\'s name, never invent a model number.',
				);
		}

		return $base + array(
			'label'       => static fn() => __( 'Article details from the article', 'zinn-seo' ),
			'per_item'    => 400,
			'fields'      => array(
				'description' => $text,
				'keywords'    => $list,
				'about'       => $list,
			),
			'instruction' => static fn() => 'You describe an article for structured data. "description": a factual summary of one or two sentences, at most 300 characters, in the article\'s own terms. "keywords": three to eight short phrases a searcher would use for it. "about": one to three main subjects, each the plain name of a thing, person, place or idea (for example "Sourdough bread"), never a sentence.',
		);
	}

	/**
	 * The facts the AI reads for one page, or why the page is left alone (nothing is paid for a
	 * page refused here).
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Post.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function input( string $kind, int $id ) {
		$post = Free_Tasks::editable( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( post_password_required( $post ) ) {
			return new \WP_Error( 'zinn_seo_fill_private', __( 'This page is password protected.', 'zinn-seo' ) );
		}
		if ( Fill::has_own( $post, $kind ) ) {
			return new \WP_Error( 'zinn_seo_fill_own', 'faq' === $kind ? __( 'This page already has an FAQ of its own.', 'zinn-seo' ) : __( 'This page already has how-to steps of its own.', 'zinn-seo' ) );
		}
		if ( 'article' === $kind && '' === Schema::post_types( $post )['article'] ) {
			return new \WP_Error( 'zinn_seo_fill_not_article', __( 'This page has no article type.', 'zinn-seo' ) );
		}
		$facts = array(
			'language' => Tasks::language_of( $id ),
			'name'     => wp_strip_all_tags( get_the_title( $post ) ),
		);
		if ( 'product' === $kind ) {
			if ( 'product' !== $post->post_type ) {
				return new \WP_Error( 'zinn_seo_fill_not_product', __( 'That is not a product.', 'zinn-seo' ) );
			}
			$facts['short'] = Tasks::cut( wp_strip_all_tags( (string) $post->post_excerpt ), 600 );
			$facts['text']  = Tasks::text_of( $post, 2500 );
			$facts['facts'] = self::product_facts( $id );
			return $facts;
		}
		$text = Tasks::text_of( $post, 'howto' === $kind ? 7000 : 5000 );
		if ( mb_strlen( $text ) < self::SHORT ) {
			return new \WP_Error( 'zinn_seo_fill_short', __( 'This page has too little text to work from.', 'zinn-seo' ) );
		}
		$facts['text'] = $text;

		return $facts;
	}

	/**
	 * A WooCommerce product's own facts (attributes, SKU, categories).
	 *
	 * @param int $id Product.
	 * @return array<string, string>
	 */
	private static function product_facts( int $id ): array {
		$facts = array();
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $facts;
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return $facts;
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) && function_exists( 'wc_attribute_label' ) ) {
				$values = $attribute->is_taxonomy() && function_exists( 'wc_get_product_terms' )
					? wc_get_product_terms( $id, $attribute->get_name(), array( 'fields' => 'names' ) )
					: $attribute->get_options();
				$facts[ wc_attribute_label( $attribute->get_name() ) ] = implode( ', ', array_map( 'strval', (array) $values ) );
			}
		}
		$facts['sku'] = (string) $product->get_sku();
		if ( function_exists( 'wc_get_product_category_list' ) ) {
			$facts['categories'] = wp_strip_all_tags( (string) wc_get_product_category_list( $id, ', ' ) );
		}

		return array_filter( $facts );
	}

	/**
	 * Use one page's reviewed record: checked first, then written (recorded in the open history
	 * batch, if any).
	 *
	 * @param string               $kind   Kind.
	 * @param int                  $id     Post.
	 * @param array<string, mixed> $record Record.
	 * @return true|\WP_Error
	 */
	public static function apply_one( string $kind, int $id, array $record ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'That item no longer exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$record = Fill::clean( $kind, $record );
		if ( array() === $record ) {
			return new \WP_Error( 'zinn_seo_fill_empty', __( 'The AI found nothing to add for this page.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		$check = Fill::check( $kind, $record, $post );
		if ( ! $check['ok'] ) {
			return new \WP_Error( 'zinn_seo_fill_invalid', implode( ' ', $check['errors'] ), array( 'status' => 422 ) );
		}

		return Meta::set( 'post', $id, Fill::field( $kind ), $record, 'ai' );
	}

	/**
	 * The post types a run may cover: the content types the analysis covers (pages people can
	 * open; types switched out of the analysis are left out — zs-768), only products for
	 * product details.
	 *
	 * @param string $kind Kind.
	 * @return array<int, string>
	 */
	public static function types( string $kind ): array {
		if ( 'product' === $kind ) {
			return post_type_exists( 'product' ) ? array( 'product' ) : array();
		}
		$types = class_exists( '\ZinnDigital\ZinnSEO\Analysis\Analysis' ) && method_exists( '\ZinnDigital\ZinnSEO\Analysis\Analysis', 'types' )
			? \ZinnDigital\ZinnSEO\Analysis\Analysis::types()
			: array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$types = array_values( array_diff( array_map( 'strval', $types ), array( 'product', 'attachment' ) ) );
		sort( $types );

		/**
		 * Filters the content types the AI structured-data fill may cover.
		 *
		 * @param array<int, string> $types Post types.
		 * @param string             $kind  faq, howto, product or article.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'zinn_seo_schema_fill_types', $types, $kind ) ) );
	}

	/**
	 * The scope, cleaned.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string, mixed> $scope `types`, `missing`.
	 * @return array{types: array<int, string>, missing: bool}
	 */
	public static function scope( string $kind, array $scope ): array {
		$allowed = self::types( $kind );
		$types   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $scope['types'] ?? $allowed ) ), $allowed ) );

		return array(
			'types'   => array() === $types ? $allowed : $types,
			'missing' => ! array_key_exists( 'missing', $scope ) || ! empty( $scope['missing'] ),
		);
	}

	/**
	 * The WHERE of a run: published pages of the types, with no suggestion waiting for the kind,
	 * and (by default) none of their own yet. The count and the run read the SAME predicate.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string, mixed> $scope Scope.
	 * @return string SQL, prepared.
	 */
	private static function where( string $kind, array $scope ): string {
		global $wpdb;
		$scope = self::scope( $kind, $scope );
		if ( array() === $scope['types'] ) {
			return '1 = 0';
		}
		$types  = implode( ',', array_map( static fn( string $t ): string => $wpdb->prepare( '%s', $t ), $scope['types'] ) );
		$where  = "p.post_type IN ({$types}) AND p.post_status = 'publish' AND p.post_password = ''";
		$where .= $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s )", Suggestions::key( self::task( $kind ) ) );
		if ( $scope['missing'] ) {
			$where .= $wpdb->prepare( " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s )", Meta::key( Fill::field( $kind ) ) );
		}
		// ZH5-3: pages that input() always refuses are not counted, priced or sampled. Text is
		// read from the content alone and only ever gets shorter (tags, shortcodes, spaces), so
		// content under SHORT characters can never reach it: a WooCommerce cart, checkout or
		// account page, an empty page.
		if ( 'product' !== $kind ) {
			$where .= $wpdb->prepare( ' AND CHAR_LENGTH(p.post_content) >= %d', self::SHORT );
		}
		if ( 'article' === $kind ) {
			$where .= ' AND (' . self::has_article_type( $scope['types'] ) . ')';
		}

		return $where;
	}

	/**
	 * SQL: the page has an article type (its own, or its content type's default) — a page set to
	 * "none", or a type whose default is none with no type of its own, is never filled (ZH5-3).
	 *
	 * @param array<int, string> $types Post types in the run.
	 * @return string SQL, prepared.
	 */
	private static function has_article_type( array $types ): string {
		global $wpdb;
		$key   = Meta::key( 'schema_article' );
		$named = array_values( array_filter( Schema::ARTICLE_TYPES, static fn( string $t ): bool => '' !== $t ) );
		$in    = implode( ',', array_map( static fn( string $t ): string => $wpdb->prepare( '%s', $t ), $named ) );
		$parts = array();
		foreach ( $types as $type ) {
			$parts[] = '' === Schema::type_defaults( $type )['article']
				? $wpdb->prepare( "( p.post_type = %s AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = %s AND a.meta_value IN ({$in}) ) )", $type, $key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is built from prepared parts.
				: $wpdb->prepare( "( p.post_type = %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = %s AND a.meta_value = 'none' ) )", $type, $key );
		}

		return array() === $parts ? '1 = 0' : implode( ' OR ', $parts );
	}

	/**
	 * The next pages of a run after an id (keyset, id order).
	 *
	 * @param string               $kind  Kind.
	 * @param int                  $after Last id done.
	 * @param int                  $limit How many.
	 * @param array<string, mixed> $scope Scope.
	 * @return array<int, int>
	 */
	public static function next( string $kind, int $after, int $limit, array $scope ): array {
		global $wpdb;
		$where = self::where( $kind, $scope );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from prepared parts; keyset over the primary key, no cache can hold a cursor.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $after, max( 1, $limit ) ) ) );
	}

	/**
	 * How many pages a run would ask about.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string, mixed> $scope Scope.
	 * @return int
	 */
	public static function count( string $kind, array $scope ): int {
		global $wpdb;
		$where = self::where( $kind, $scope );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see next(); asked once, when a person counts.
	}

	/**
	 * What a run would cost, before it starts: the count, the measured price per page on Zinn®
	 * credits (this site's own, once it has run the kind: price_for()), the balance and whether it is enough (`null` price: not on credits — the site
	 * owner's own key pays).
	 *
	 * @param string $kind  Kind.
	 * @param int    $count Pages.
	 * @return array{count: int, credits: bool, per_page_micros: int|null, total_micros: int|null, balance_micros: int|null, enough: bool|null, buy_url: string, measured_on: string, basis: string, basis_runs: int}
	 */
	public static function estimate( string $kind, int $count ): array {
		$def     = Tasks::get( self::task( $kind ) );
		$credits = null !== $def && Ai::on_credits( $def );
		$unit    = self::price_for( $kind, $credits );
		$price   = null !== $unit && $unit['micros'] > 0 ? $unit['micros'] : null;
		$balance = null;
		$buy     = '';
		if ( $credits ) {
			$account = self::account();
			$balance = isset( $account['balance_micros'] ) && is_numeric( $account['balance_micros'] ) ? (int) $account['balance_micros'] : null;
			$buy     = (string) ( $account['buy_url'] ?? '' );
		}
		$total = null !== $price ? $price * $count : null;

		return array(
			'count'           => $count,
			'credits'         => $credits,
			'per_page_micros' => $price,
			'total_micros'    => $total,
			'balance_micros'  => $balance,
			'enough'          => null !== $total && null !== $balance ? $balance >= $total : null,
			'buy_url'         => $buy,
			'measured_on'     => self::MEASURED_ON,
			'basis'           => null !== $unit ? $unit['basis'] : '',
			'basis_runs'      => null !== $unit ? $unit['runs'] : 0,
		);
	}

	/**
	 * The price per page a site is shown on Zinn® credits: the median of what ITS OWN recent
	 * runs of the kind cost per answered page (`site`), or the reference measured on our own
	 * account until it has run one (`reference`). `null` off credits (the owner's own key pays).
	 *
	 * @param string $kind    Kind.
	 * @param bool   $credits Paid with Zinn® credits.
	 * @return array{micros: int, basis: string, runs: int}|null
	 */
	public static function price_for( string $kind, bool $credits ): ?array {
		if ( ! $credits ) {
			return null;
		}
		$all  = get_option( self::PRICES, array() );
		$runs = is_array( $all ) && isset( $all[ $kind ] ) && is_array( $all[ $kind ] ) ? array_values( array_map( 'intval', $all[ $kind ] ) ) : array();
		if ( array() === $runs ) {
			return array(
				'micros' => (int) ( self::MEASURED[ $kind ] ?? 0 ),
				'basis'  => 'reference',
				'runs'   => 0,
			);
		}
		sort( $runs );
		$n   = count( $runs );
		$mid = intdiv( $n, 2 );

		return array(
			'micros' => 1 === $n % 2 ? $runs[ $mid ] : (int) ceil( ( $runs[ $mid - 1 ] + $runs[ $mid ] ) / 2 ),
			'basis'  => 'site',
			'runs'   => $n,
		);
	}

	/**
	 * After any AI run (a sample, a whole-site run, Autopilot): keep what a schema kind's run
	 * cost per answered page on Zinn® credits — the figure the account's balance fell by, as the
	 * API reports it (ZH6: 10,336 reported = 10,336 by balance difference).
	 *
	 * @param string                           $task    Task id.
	 * @param array<int, array<string, mixed>> $items   The run's items.
	 * @param int                              $cost    Micros charged.
	 * @param bool                             $credits Paid with Zinn® credits.
	 * @return void
	 */
	public static function on_ran( string $task, array $items, int $cost, bool $credits ): void {
		$kind = 0 === strpos( $task, self::TASK_PREFIX ) ? substr( $task, strlen( self::TASK_PREFIX ) ) : '';
		$done = count( array_filter( $items, static fn( $i ): bool => is_array( $i ) && ! empty( $i['ok'] ) ) );
		if ( ! $credits || $cost <= 0 || 0 === $done || ! isset( self::PACK[ $kind ] ) ) {
			return;
		}
		$all          = get_option( self::PRICES, array() );
		$all          = is_array( $all ) ? $all : array();
		$runs         = isset( $all[ $kind ] ) && is_array( $all[ $kind ] ) ? $all[ $kind ] : array();
		$runs[]       = (int) ceil( $cost / $done );
		$all[ $kind ] = array_slice( $runs, -self::PRICE_RUNS );
		update_option( self::PRICES, $all, false );
	}

	/**
	 * The connected account's credit (the credit module's kept copy, read again when old), or
	 * nothing when the credit feature is off or the account cannot be read.
	 *
	 * @return array<string, mixed>
	 */
	private static function account(): array {
		$credits = '\ZinnDigital\ZinnSEO\Credits\Credits';
		if ( ! class_exists( $credits ) ) {
			$file = dirname( __DIR__ ) . '/credits/class-credits.php';
			if ( ! is_readable( $file ) || ! \ZinnDigital\ZinnSEO\Modules::enabled( 'credits' ) ) {
				return array();
			}
			require_once $file;
		}
		$account = $credits::account();

		return is_array( $account ) ? $account : array();
	}

	/**
	 * Start a run, count it, or try it on a few pages at once.
	 *
	 * @param string               $kind   Kind.
	 * @param array<string, mixed> $scope  Scope.
	 * @param string               $mode   `count`, `sample` (the first few, now) or `start`.
	 * @param int                  $sample Pages for a sample (1–10).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start( string $kind, array $scope, string $mode = 'start', int $sample = 5 ) {
		if ( ! Fill::is_kind( $kind ) || ! self::available() ) {
			return new \WP_Error( 'zinn_seo_fill_kind', __( 'AI is switched off, or that is not something the AI can fill.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$scope = self::scope( $kind, $scope );
		if ( 'count' === $mode ) {
			return array( 'kind' => $kind ) + self::estimate( $kind, self::count( $kind, $scope ) );
		}
		if ( 'sample' === $mode ) {
			$ids = self::next( $kind, 0, max( 1, min( 10, $sample ) ), $scope );
			if ( array() === $ids ) {
				return array(
					'kind'  => $kind,
					'items' => array(),
					'cost'  => 0,
				);
			}
			$run = Ai::run( self::task( $kind ), $ids );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$error = Ai::as_error( $run );
			if ( null !== $error ) {
				return $error;
			}

			return array(
				'kind'  => $kind,
				'items' => $run['items'],
				'cost'  => (int) $run['cost_micros'],
			);
		}
		if ( self::busy( self::JOB ) ) {
			return new \WP_Error( 'zinn_seo_fill_busy', __( 'An AI structured data run is already going. Wait for it to finish, or stop it, before starting another.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$count   = self::count( $kind, $scope );
		$started = Jobs::start(
			self::JOB,
			array(
				'kind'    => $kind,
				'scope'   => $scope,
				'after'   => 0,
				'total'   => $count,
				'done'    => 0,
				'made'    => 0,
				'failed'  => 0,
				'skipped' => 0,
				'cost'    => 0,
				'reason'  => '',
			)
		);

		return is_wp_error( $started ) ? $started : array(
			'job'   => self::JOB,
			'kind'  => $kind,
			'count' => $count,
		);
	}

	/**
	 * Is a job running or paused?
	 *
	 * @param string $id Job.
	 * @return bool
	 */
	public static function busy( string $id ): bool {
		return in_array( (string) Jobs::get( $id )['status'], array( 'pending', 'paused' ), true );
	}

	/**
	 * One step of a run.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		if ( ! isset( $state['kind'], $state['scope'], $state['after'] ) || ! Fill::is_kind( (string) $state['kind'] ) ) {
			return self::progress( $state, true );
		}

		return (array) Bulk_Context::run(
			static function () use ( $state, $deadline ): array {
				$kind = (string) $state['kind'];
				$def  = Tasks::get( self::task( $kind ) );
				if ( null === $def ) {
					return self::progress( $state, false, __( 'AI is switched off. Switch it on, then carry on.', 'zinn-seo' ) );
				}
				$batch = Ai::on_credits( $def ) ? self::PACK[ $kind ] * self::CREDIT_REQUESTS : self::PACK[ $kind ];
				while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
					$ids = self::next( $kind, (int) $state['after'], $batch, (array) $state['scope'] );
					if ( array() === $ids ) {
						return self::progress( $state, true );
					}
					$run = Ai::run( self::task( $kind ), $ids );
					if ( is_wp_error( $run ) ) {
						return self::progress( $state, false, $run->get_error_message() );
					}
					$state['cost'] = (int) $state['cost'] + (int) $run['cost_micros'];
					$verdict       = self::verdict( (array) $run['items'] );
					if ( null !== $verdict['stop'] ) {
						$stop = (array) $verdict['stop'];
						if ( isset( $stop['wait'] ) ) {
							$out           = self::progress( $state, false );
							$out['wait']   = (int) $stop['wait'];
							$out['reason'] = (string) ( $stop['message'] ?? '' );
							return $out;
						}
						// Nothing in this chunk was kept: the cursor stays, a resume asks again.
						$state['reason'] = (string) ( $stop['action'] ?? 'none' );
						return self::progress( $state, false, (string) ( $stop['message'] ?? '' ) );
					}
					$state['made']    = (int) $state['made'] + $verdict['made'];
					$state['skipped'] = (int) $state['skipped'] + $verdict['skipped'];
					$state['failed']  = (int) $state['failed'] + $verdict['failed'];
					$state['done']    = (int) $state['done'] + count( $ids );
					$state['after']   = (int) end( $ids );
					$state['reason']  = '';
				}

				return self::progress( $state, false );
			}
		);
	}

	/**
	 * What a chunk's answers mean for the run: how many suggestions were kept, how many pages
	 * were left alone (refused before anything was paid), how many failed, and whether the run
	 * must stop (credit, standing, connection, key, settings) or wait (an outage).
	 *
	 * Pure, for the tests.
	 *
	 * @param array<int, array<string, mixed>> $items `Ai::run()` items.
	 * @return array{made: int, skipped: int, failed: int, stop: array<string, mixed>|null}
	 */
	public static function verdict( array $items ): array {
		$made    = 0;
		$skipped = 0;
		$failed  = 0;
		$stop    = null;
		$wait    = null;
		foreach ( $items as $row ) {
			if ( ! empty( $row['ok'] ) ) {
				++$made;
				continue;
			}
			$failure = (array) ( $row['failure'] ?? array() );
			$kind    = (string) ( $failure['kind'] ?? '' );
			$action  = (string) ( $failure['action'] ?? '' );
			if ( in_array( $action, array( 'top_up', 'account', 'connect', 'fix_key', 'settings' ), true ) ) {
				$stop = $stop ?? $failure;
			} elseif ( 'refused' === $kind && '' === (string) ( $failure['provider'] ?? '' ) && '' === (string) ( $failure['link'] ?? '' ) ) {
				// Ours, before any request: a page with its own FAQ, too little text, not a product.
				++$skipped;
			} elseif ( 'refused' === $kind ) {
				// The Zinn® API or the provider refused to spend (e.g. an account not in good
				// standing): only a person changes that.
				$stop = $stop ?? array_merge( $failure, array( 'action' => 'account' ) );
			} elseif ( in_array( $kind, array( 'provider_outage', 'rate_limited', 'unreachable' ), true ) ) {
				$wait = $wait ?? ( $failure + array( 'wait' => 300 ) );
				++$failed;
			} else {
				++$failed;
			}
		}
		if ( null === $stop && null !== $wait && 0 === $made ) {
			$stop = $wait;
		}
		if ( null !== $stop && $made > 0 && ! isset( $stop['wait'] ) ) {
			// Some pages were answered before the money ran out: keep them, stop at the next chunk.
			$stop = null;
		}

		return array(
			'made'    => $made,
			'skipped' => $skipped,
			'failed'  => $failed,
			'stop'    => $stop,
		);
	}

	/**
	 * A step's answer for the job runner.
	 *
	 * @param array<string, mixed> $state State.
	 * @param bool                 $done  Finished.
	 * @param string               $pause Pause with this reason ('' none).
	 * @return array<string, mixed>
	 */
	private static function progress( array $state, bool $done, string $pause = '' ): array {
		$out = array(
			'state'    => $state,
			'done'     => $done,
			'progress' => array(
				'done'  => (int) ( $state['done'] ?? 0 ),
				'total' => isset( $state['total'] ) ? (int) $state['total'] : null,
			),
		);
		if ( '' !== $pause ) {
			$out['pause'] = $pause;
		}

		return $out;
	}

	/**
	 * Resume a run the credit paused, the moment the credit is back.
	 *
	 * @param string $state  The credit's new state.
	 * @param string $before The state before.
	 * @return void
	 */
	public static function on_credit( $state, $before = '' ): void {
		if ( in_array( (string) $state, array( 'ok', 'low', 'short' ), true ) && in_array( (string) $before, array( 'empty', 'held', '' ), true ) ) {
			self::on_news();
		}
	}

	/**
	 * News about the credit (a top-up): resume a run waiting for it.
	 *
	 * @return void
	 */
	public static function on_news(): void {
		$job = Jobs::get( self::JOB );
		if ( 'paused' === $job['status'] && 'top_up' === (string) ( $job['state']['reason'] ?? '' ) ) {
			Jobs::control( self::JOB, 'resume' );
		}
	}

	/**
	 * How many suggestions are waiting for a kind (the meta_key index).
	 *
	 * @param string $kind Kind.
	 * @return int
	 */
	public static function waiting( string $kind ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Suggestions::key( self::task( $kind ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count over the meta_key index, asked when the screen opens.
	}

	/**
	 * The review: suggestions waiting for a kind after an id, each with its check and a preview.
	 *
	 * @param string $kind  Kind.
	 * @param int    $after Last id seen.
	 * @param int    $limit Page size.
	 * @return array{items: array<int, array<string, mixed>>, next: int, total: int}
	 */
	public static function review( string $kind, int $after = 0, int $limit = 20 ): array {
		$page  = Suggestions::queue( self::task( $kind ), $after, $limit );
		$items = array();
		foreach ( (array) $page['items'] as $row ) {
			$post = get_post( (int) $row['id'] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$record         = Fill::clean( $kind, (array) $row['fields'] );
			$row['record']  = $record;
			$row['check']   = Fill::check( $kind, $record, $post );
			$row['summary'] = Fill::summary( $kind, $record );
			$row['url']     = (string) get_permalink( $post );
			unset( $row['check']['nodes'] );
			$items[] = $row;
		}
		$page['items'] = $items;

		return $page;
	}

	/**
	 * Use the selected suggestions now, as ONE batch of the bulk history (undone in one click).
	 *
	 * @param string          $kind Kind.
	 * @param array<int, int> $ids  Posts (at most 200).
	 * @return array{batch: string, used: int, failed: array<int, array{id: int, message: string}>}
	 */
	public static function apply_ids( string $kind, array $ids ): array {
		$task = self::task( $kind );
		History::open(
			'post',
			/* translators: %s: what the AI filled, e.g. "FAQ structured data from the page". */
			sprintf( __( 'Used AI structured data: %s', 'zinn-seo' ), self::label( $kind ) ),
			'ai'
		);
		$used   = 0;
		$failed = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 200 ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$failed[] = array(
					'id'      => $id,
					'message' => __( 'You cannot edit that item.', 'zinn-seo' ),
				);
				continue;
			}
			$done = Suggestions::accept( $id, $task );
			if ( is_wp_error( $done ) ) {
				$failed[] = array(
					'id'      => $id,
					'message' => $done->get_error_message(),
				);
			} else {
				++$used;
			}
		}
		$summary = History::close();

		return array(
			'batch'  => (int) ( $summary['count'] ?? 0 ) > 0 ? (string) $summary['id'] : '',
			'used'   => $used,
			'failed' => $failed,
		);
	}

	/**
	 * Start "use every waiting suggestion that passes the check" (background, one batch).
	 *
	 * @param string $kind Kind.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start_apply( string $kind ) {
		if ( ! Fill::is_kind( $kind ) ) {
			return new \WP_Error( 'zinn_seo_fill_kind', __( 'That is not something the AI can fill.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( self::busy( self::JOB_APPLY ) ) {
			return new \WP_Error( 'zinn_seo_fill_busy', __( 'Suggestions are already being used. Wait for that to finish.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$count = self::waiting( $kind );
		/* translators: %s: what the AI filled. */
		History::open( 'post', sprintf( __( 'Used AI structured data: %s', 'zinn-seo' ), self::label( $kind ) ), 'ai' );
		$batch   = History::suspend();
		$started = Jobs::start(
			self::JOB_APPLY,
			array(
				'kind'   => $kind,
				'after'  => 0,
				'total'  => $count,
				'done'   => 0,
				'used'   => 0,
				'failed' => 0,
				'batch'  => $batch,
			)
		);

		return is_wp_error( $started ) ? $started : array(
			'job'   => self::JOB_APPLY,
			'kind'  => $kind,
			'count' => $count,
			'batch' => (string) ( $batch['id'] ?? '' ),
		);
	}

	/**
	 * One step of "use them all". A suggestion that fails its check stays waiting (and is counted),
	 * so the person can see why.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function apply_step( array $state, float $deadline ): array {
		if ( ! isset( $state['kind'], $state['after'] ) || ! Fill::is_kind( (string) $state['kind'] ) ) {
			return self::progress( $state, true );
		}
		global $wpdb;
		$task = self::task( (string) $state['kind'] );
		if ( is_array( $state['batch'] ?? null ) ) {
			History::resume( (array) $state['batch'] );
		}
		$finished = false;
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset over the meta_key index.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT 100", Suggestions::key( $task ), (int) $state['after'] ) ) );
			if ( array() === $ids ) {
				$finished = true;
				break;
			}
			update_meta_cache( 'post', $ids );
			_prime_post_caches( $ids, false, false );
			foreach ( $ids as $id ) {
				$done = Bulk_Context::run( static fn() => Suggestions::accept( $id, $task ) );
				if ( is_wp_error( $done ) ) {
					$state['failed'] = (int) $state['failed'] + 1;
				} else {
					$state['used'] = (int) $state['used'] + 1;
				}
				$state['after'] = $id;
				$state['done']  = (int) $state['done'] + 1;
				if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
					break 2;
				}
			}
			History::release( array( 'post' => $ids ) );
		}
		if ( null !== History::current() ) {
			$state['batch'] = $finished ? History::close() : History::suspend();
			if ( ! $finished && is_array( $state['batch'] ) ) {
				History::index_put( $state['batch'] );
			}
		}

		return self::progress( $state, $finished );
	}

	/**
	 * Throw suggestions away (the selected, or every one waiting for the kind).
	 *
	 * @param string          $kind Kind.
	 * @param array<int, int> $ids  Posts; empty with `$all`: every one.
	 * @param bool            $all  Every waiting one.
	 * @return int How many.
	 */
	public static function skip( string $kind, array $ids, bool $all = false ): int {
		global $wpdb;
		$task = self::task( $kind );
		if ( $all ) {
			$key = Suggestions::key( $task );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the posts holding a suggestion, over the meta_key index.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one DELETE over the meta_key index instead of one per post; the caches are cleared below.
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) );
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
				wp_cache_flush_group( 'post_meta' );
			} else {
				foreach ( $ids as $id ) {
					wp_cache_delete( $id, 'post_meta' );
				}
			}
			return $n;
		}
		$n = 0;
		foreach ( array_slice( array_map( 'intval', $ids ), 0, 200 ) as $id ) {
			if ( current_user_can( 'edit_post', $id ) && null !== Suggestions::get( $id, $task ) ) {
				Suggestions::drop( $id, $task );
				++$n;
			}
		}

		return $n;
	}

	/**
	 * A kind's label.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function label( string $kind ): string {
		$def = class_exists( Tasks::class ) ? Tasks::get( self::task( $kind ) ) : null;

		return null !== $def ? (string) call_user_func( $def['label'] ) : $kind;
	}

	/**
	 * The jobs' state for the screen.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function status(): array {
		$out = array();
		foreach ( array( self::JOB, self::JOB_APPLY ) as $id ) {
			$job        = Jobs::get( $id );
			$state      = (array) $job['state'];
			$out[ $id ] = array(
				'status'   => (string) $job['status'],
				'progress' => (array) $job['progress'],
				'error'    => (string) $job['error'],
				'reason'   => (string) ( $state['reason'] ?? '' ),
				'kind'     => (string) ( $state['kind'] ?? '' ),
				'made'     => (int) ( $state['made'] ?? $state['used'] ?? 0 ),
				'skipped'  => (int) ( $state['skipped'] ?? 0 ),
				'failed'   => (int) ( $state['failed'] ?? 0 ),
				'cost'     => (int) ( $state['cost'] ?? 0 ),
				'started'  => (int) $job['started'],
				'finished' => (int) $job['finished'],
				'batch'    => (string) ( $state['batch']['id'] ?? '' ),
			);
		}

		return $out;
	}
}
