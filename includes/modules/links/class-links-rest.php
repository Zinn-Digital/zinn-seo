<?php
/**
 * REST routes of the links module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

use ZinnDigital\ZinnSEO\Analysis\Analysis;
use ZinnDigital\ZinnSEO\Analysis\Doc;
use ZinnDigital\ZinnSEO\Analysis\Loader;
use ZinnDigital\ZinnSEO\Analysis\Render;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /links/summary`: index state, totals (links, orphans, dead ends), the build job.
 * - `POST /links/build`: (re)build the whole index.
 * - `GET /links/report?kind=orphan|deadend`: the pages, newest first, keyset (`before`).
 * - `POST /links/suggest/{id}`: outgoing suggestions for the editor's current content; Pro adds
 *   incoming ones.
 * - `GET /links/post/{id}`: the pages linking to a post and the pages it links to.
 */
final class Links_Rest {

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
		$done = true;
		$view = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::EDIT );
		register_rest_route(
			Plugin::REST_NS,
			'/links/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response(
					array(
						'totals' => Index::totals(),
						'job'    => Jobs::view( Index::JOB ),
					)
				),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/links/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					Index::maybe_install();
					$started = Jobs::start( Index::JOB, array() );
					return is_wp_error( $started ) ? $started : rest_ensure_response( Jobs::view( Index::JOB ) );
				},
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/links/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => $view,
				'args'                => array(
					'kind'   => array(
						'type'    => 'string',
						'enum'    => array( 'orphan', 'deadend' ),
						'default' => 'orphan',
					),
					'type'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'before' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'per'    => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/links/suggest/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'suggest' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r['id'] ),
				'args'                => array(
					'content' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/links/post/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'post_links' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r['id'] ),
			)
		);
	}

	/**
	 * A post as a report row.
	 *
	 * @param int                            $id     Post.
	 * @param array<int, array<string, int>> $counts Index::counts().
	 * @return array<string, mixed>|null
	 */
	public static function row( int $id, array $counts ): ?array {
		$p = get_post( $id );
		if ( ! $p instanceof \WP_Post ) {
			return null;
		}

		return array(
			'id'          => $id,
			'title'       => '' !== $p->post_title ? $p->post_title : __( '(no title)', 'zinn-seo' ),
			'type'        => $p->post_type,
			'edit'        => (string) get_edit_post_link( $id, 'raw' ),
			'view'        => (string) get_permalink( $p ),
			'inbound'     => (int) ( $counts[ $id ]['in'] ?? 0 ),
			'outbound'    => (int) ( $counts[ $id ]['out'] ?? 0 ),
			'cornerstone' => (bool) Meta::get_post( $id, 'cornerstone' ),
		);
	}

	/**
	 * GET /links/report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function report( \WP_REST_Request $request ) {
		if ( ! Index::built() ) {
			return rest_ensure_response(
				array(
					'built' => false,
					'items' => array(),
					'next'  => 0,
				)
			);
		}
		$page = Index::report( (string) $request['kind'], (int) $request['before'], (int) $request['per'], (string) $request['type'] );
		_prime_post_caches( $page['items'], false, true );
		$counts = Index::counts( $page['items'] );
		$rows   = array_values( array_filter( array_map( static fn( $id ) => self::row( (int) $id, $counts ), $page['items'] ) ) );

		return rest_ensure_response(
			array(
				'built' => true,
				'items' => $rows,
				'next'  => $page['next'],
			)
		);
	}

	/**
	 * POST /links/suggest/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function suggest( \WP_REST_Request $request ) {
		Loader::classes();
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$content = $request->get_param( 'content' );
		$copy    = clone $post;
		if ( is_string( $content ) ) {
			$copy->post_content = $content;
		}
		$html = Render::html( $copy, is_string( $content ) ? $content : null );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$doc    = Doc::parse( $html );
		$linked = array_map( static fn( $l ) => (string) $l['href'], array_filter( Analysis::links_of( $doc, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), static fn( $l ) => ! empty( $l['internal'] ) ) );
		$out    = Suggest::limit( Suggest::outgoing( $copy, $doc, $linked ) );

		/**
		 * Filters the link suggestions sent to the editor (Pro adds `incoming`).
		 *
		 * @param array<string, mixed> $result   Suggestions.
		 * @param \WP_Post             $post     The post.
		 */
		return rest_ensure_response(
			(array) apply_filters(
				'zinn_seo_link_suggestions',
				array(
					'outgoing' => $out['items'],
					'more'     => $out['more'],
					'incoming' => null,
					'indexed'  => Index::built(),
				),
				$post
			)
		);
	}

	/**
	 * GET /links/post/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function post_links( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$sources = Index::sources( $id, 200 );
		$targets = Index::targets( $id );
		$all     = array_values( array_unique( array_merge( $sources, $targets ) ) );
		_prime_post_caches( $all, false, true );
		$counts = Index::counts( $all );

		return rest_ensure_response(
			array(
				'indexed' => Index::built(),
				'from'    => array_values( array_filter( array_map( static fn( $s ) => self::row( $s, $counts ), $sources ) ) ),
				'to'      => array_values( array_filter( array_map( static fn( $t ) => self::row( $t, $counts ), $targets ) ) ),
			)
		);
	}
}
