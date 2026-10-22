<?php
/**
 * REST routes of the pay-per-use checks.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Tools;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /tools` — the site's set-up at Zinn®, the plan, the prices, each check's state.
 * - `PUT /tools/site` — what the business is and where it sells; how often scheduled checks run.
 * - `PUT /tools/<tool>/schedule` `{on}` — put a check on the schedule or take it off.
 * - `PUT /tools/<rank|ai|links>/list` `{items}` — the phrases, questions or links.
 * - `POST /tools/<tool>/run` — run it now (`seed`, `page_url` for keyword research).
 * - `GET /tools/<tool>/results` — recent runs; `GET /tools/run/<id>` — one run with its findings
 *   and, for each address it names, the page here to fix.
 * - `GET /tools/index` — the index checker's totals, price for the whole site, job and runs;
 *   `POST /tools/index/check` `{urls}`; `GET /tools/index/run/<id>`;
 *   `POST /tools/index/site` `{action: start|pause|resume|cancel}`.
 * - `GET /tools/pages?search=&before=` — this site's published pages with their last answer.
 *
 * Reading needs the right to see SEO reports; anything that can spend needs the right to spend
 * the owner's credit (administrators by default).
 */
final class Tools_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done  = true;
		$view  = static fn(): bool => current_user_can( Capabilities::SPEND ) || current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$spend = static fn(): bool => current_user_can( Capabilities::SPEND );
		$tool  = '(?P<tool>[a-z]{2,20})';
		$uuid  = '(?P<id>[0-9a-fA-F-]{36})';
		$ns    = Plugin::REST_NS;

		register_rest_route(
			$ns,
			'/tools',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( Tools::overview() ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/tools/site',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Tools::update_site( array_intersect_key( (array) $r->get_json_params(), array_flip( array( 'brand', 'category', 'market', 'cadence_days' ) ) ) ) ),
				'permission_callback' => $spend,
			)
		);
		register_rest_route(
			$ns,
			'/tools/' . $tool . '/schedule',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Tools::schedule( (string) $r['tool'], (bool) $r['on'] ) ),
				'permission_callback' => $spend,
				'args'                => array(
					'on' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/tools/' . $tool . '/list',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Tools::save_list( (string) $r['tool'], (array) $r['items'] ) ),
				'permission_callback' => $spend,
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/tools/' . $tool . '/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response(
					Tools::run(
						(string) $r['tool'],
						array(
							'seed'     => (string) $r['seed'],
							'page_url' => (string) $r['page_url'],
						)
					)
				),
				'permission_callback' => $spend,
				'args'                => array(
					'seed'     => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 120,
					),
					'page_url' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 2000,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/tools/' . $tool . '/results',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Tools::results( (string) $r['tool'] ) ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/tools/run/' . $uuid,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$audit = Tools::audit( (string) $r['id'] );
					return rest_ensure_response( is_wp_error( $audit ) ? $audit : Tools::with_fix_links( $audit ) );
				},
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/tools/index',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( Index::overview() ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/tools/index/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Index::check( (array) $r['urls'] ) ),
				'permission_callback' => $spend,
				'args'                => array(
					'urls' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/tools/index/run/' . $uuid,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Index::read( (string) $r['id'] ) ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$ns,
			'/tools/index/site',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$action = (string) $r['action'];
					if ( 'start' === $action ) {
						return rest_ensure_response( Index::start_site() );
					}
					$done = Jobs::control( Index::JOB, $action );
					return rest_ensure_response( is_wp_error( $done ) ? $done : Index::job() );
				},
				'permission_callback' => $spend,
				'args'                => array(
					'action' => array(
						'type'    => 'string',
						'enum'    => array( 'start', 'pause', 'resume', 'cancel' ),
						'default' => 'start',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/tools/pages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::pages( (string) $r['search'], (int) $r['before'], (string) $r['state'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
					'before' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'state'  => array(
						'type'    => 'string',
						'enum'    => array( '', 'yes', 'no', 'unknown', 'never' ),
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * This site's published pages, newest first, fifty at a time (keyset on the id), each with
	 * its last answer from the index checker.
	 *
	 * @param string $search Words in the title.
	 * @param int    $before Only pages with a lower id (the last id of the page before).
	 * @param string $state  Only pages whose last answer is this.
	 * @return array{items: array<int, array<string, mixed>>, next: int}
	 */
	public static function pages( string $search, int $before, string $state ): array {
		$per   = 50;
		$marks = array(
			'yes'     => '1:',
			'no'      => '0:',
			'unknown' => '?:',
		);
		$args  = array(
			'post_type'              => Index::types(),
			'post_status'            => 'publish',
			'posts_per_page'         => $per + 1,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'fields'                 => 'ids',
			'update_post_term_cache' => false,
			's'                      => sanitize_text_field( $search ),
		);
		if ( isset( $marks[ $state ] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key; the value is compared by prefix.
			$args['meta_query'] = array(
				array(
					'key'     => Index::META,
					'value'   => '^' . preg_quote( $marks[ $state ], '/' ),
					'compare' => 'REGEXP',
				),
			);
		} elseif ( 'never' === $state ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key.
			$args['meta_query'] = array(
				array(
					'key'     => Index::META,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$keyset = static function ( $where ) use ( $before ) {
			global $wpdb;
			return $before > 0 ? $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $before ) : $where;
		};
		add_filter( 'posts_where', $keyset );
		$ids = array_map( 'intval', (array) ( new \WP_Query( $args ) )->posts );
		remove_filter( 'posts_where', $keyset );
		$more  = count( $ids ) > $per;
		$ids   = array_slice( $ids, 0, $per );
		$items = array();
		foreach ( $ids as $id ) {
			$items[] = array(
				'id'     => $id,
				'title'  => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'    => (string) get_permalink( $id ),
				'edit'   => current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '',
				'hidden' => Index::hidden( $id ),
			) + Index::state( $id );
		}

		return array(
			'items' => $items,
			'next'  => $more && $ids ? (int) end( $ids ) : 0,
		);
	}
}
