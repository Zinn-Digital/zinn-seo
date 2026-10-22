<?php
/**
 * Internal site-search insights: what visitors look for, and what finds nothing (zs-414).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SiteSearch;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Counters;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every search a visitor makes on the site's own search (`?s=`) is ONE counter per phrase in
 * `{prefix}zseo_counters`: kind `search` (found something; the sample is how many results) or
 * `search0` (found nothing — the content ideas). Ceiling 10,000 rows per kind; robots and page 2+
 * of a search are not counted; a phrase is kept lower-cased, trimmed, at most 100 characters —
 * never who searched. One upsert at the end of a search request; nothing on any other page.
 *
 * The screen turns a phrase that found nothing into a draft (title = the phrase) in one click, or
 * into a redirect from the search to an existing page.
 */
final class Site_Search {

	/**
	 * This request's phrase and result count.
	 *
	 * @var array{0: string, 1: int}|null
	 */
	private static ?array $pending = null;

	/**
	 * Front end.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public search; only whether it is present.
		if ( ! isset( $_GET['s'] ) ) {
			return;
		}
		add_action( 'template_redirect', array( self::class, 'note' ), 99 );
	}

	/**
	 * Note the search for shutdown.
	 *
	 * @return void
	 */
	public static function note(): void {
		global $wp_query;
		if ( ! is_search() || ! $wp_query instanceof \WP_Query || (int) get_query_var( 'paged' ) > 1 ) {
			return;
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( Counters::is_bot( $agent ) ) {
			return;
		}
		$phrase = self::phrase( (string) get_search_query( false ) );
		if ( '' === $phrase ) {
			return;
		}
		self::$pending = array( $phrase, (int) $wp_query->found_posts );
		add_action( 'shutdown', array( self::class, 'count' ) );
	}

	/**
	 * Count it.
	 *
	 * @return void
	 */
	public static function count(): void {
		if ( null === self::$pending ) {
			return;
		}
		Counters::hit( self::$pending[1] > 0 ? 'search' : 'search0', self::$pending[0], (string) self::$pending[1] );
		self::$pending = null;
	}

	/**
	 * A phrase as stored.
	 *
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function phrase( string $raw ): string {
		$p = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw ) ) );
		$p = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p, 'UTF-8' ) : strtolower( $p );
		$p = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 100 ) : substr( $p, 0, 100 );

		return mb_strlen( $p ) < 2 ? '' : $p;
	}

	/**
	 * REST: `GET /site-search?kind=search0|search&order=&after=&search=`,
	 * `DELETE /site-search` (hash or all), `POST /site-search/draft` (a draft for a phrase).
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$view = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$kind = array(
			'type'    => 'string',
			'enum'    => array( 'search', 'search0' ),
			'default' => 'search0',
		);
		register_rest_route(
			Plugin::REST_NS,
			'/site-search',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$page           = Counters::rows( (string) $r['kind'], (string) $r['order'], (int) $r['per'], (string) $r['after'], (string) $r['search'] );
						$page['counts'] = array(
							'search'  => Counters::count( 'search' ),
							'search0' => Counters::count( 'search0' ),
						);
						$page['items']  = array_map(
							static function ( array $row ): array {
								$row['draft'] = self::draft_for( (string) $row['key'] );
								return $row;
							},
							$page['items']
						);
						return rest_ensure_response( $page );
					},
					'permission_callback' => $view,
					'args'                => array(
						'kind'   => $kind,
						'order'  => array(
							'type'    => 'string',
							'enum'    => array( 'hits', 'last_seen', 'first_seen' ),
							'default' => 'hits',
						),
						'after'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'search' => array(
							'type'    => 'string',
							'default' => '',
						),
						'per'    => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 200,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$hash = (string) $r['hash'];
						if ( '' === $hash && ! $r['all'] ) {
							return new \WP_Error( 'zinn_seo_search_which', __( 'Say which phrase to forget, or all of them.', 'zinn-seo' ), array( 'status' => 400 ) );
						}
						return rest_ensure_response( array( 'deleted' => Counters::forget( (string) $r['kind'], '' === $hash ? null : $hash ) ) );
					},
					'permission_callback' => static fn(): bool => Capabilities::can_manage(),
					'args'                => array(
						'kind' => $kind,
						'hash' => array(
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{32}$|^$',
							'default' => '',
						),
						'all'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/site-search/draft',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'make_draft' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'phrase' => array(
						'type'     => 'string',
						'required' => true,
					),
					'type'   => array(
						'type'    => 'string',
						'default' => 'post',
					),
				),
			)
		);
	}

	/**
	 * The draft already made for a phrase, if any.
	 *
	 * @param string $phrase Phrase.
	 * @return array{id: int, edit: string}|null
	 */
	public static function draft_for( string $phrase ): ?array {
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'draft', 'publish', 'pending', 'future' ),
				'meta_key'       => '_zseo_from_search', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin screen, 50 rows a page.
				'meta_value'     => md5( $phrase ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		if ( array() === $ids ) {
			return null;
		}

		return array(
			'id'   => (int) $ids[0],
			'edit' => (string) get_edit_post_link( (int) $ids[0], 'raw' ),
		);
	}

	/**
	 * POST /site-search/draft: a draft whose title is the phrase (its focus phrase too).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function make_draft( \WP_REST_Request $r ) {
		$phrase = self::phrase( (string) $r['phrase'] );
		$type   = post_type_exists( (string) $r['type'] ) ? (string) $r['type'] : 'post';
		if ( '' === $phrase ) {
			return new \WP_Error( 'zinn_seo_search_phrase', __( 'There is no phrase to write about.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$pto = get_post_type_object( $type );
		if ( ! $pto || ! current_user_can( $pto->cap->edit_posts ) ) {
			return new \WP_Error( 'zinn_seo_forbidden', __( 'Sorry, you are not allowed to do that.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		$existing = self::draft_for( $phrase );
		if ( null !== $existing ) {
			return rest_ensure_response( $existing );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'draft',
				'post_title'  => function_exists( 'mb_convert_case' ) ? mb_convert_case( $phrase, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $phrase ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( (int) $id, '_zseo_from_search', md5( $phrase ) );
		update_post_meta( (int) $id, '_zseo_focus', $phrase );

		return new \WP_REST_Response(
			array(
				'id'   => (int) $id,
				'edit' => (string) get_edit_post_link( (int) $id, 'raw' ),
			),
			201
		);
	}
}
