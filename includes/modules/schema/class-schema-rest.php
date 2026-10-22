<?php
/**
 * REST: the graph of a page, the offline validator and the vocabulary (zs-031, zs-035).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under `zinn-seo/v1/schema`:
 * - `GET  graph?post=<id>` — the graph the page prints (edit rights on the post);
 * - `POST validate` `{post}` or `{json}` — the offline check (the editor's Structured data panel,
 *   the Schema screen's validator, and AI agents);
 * - `GET  vocab/types?q=` and `vocab/properties?type=` — the schema.org vocabulary for the builders.
 */
final class Schema_Rest {

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Register.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$ns     = 'zinn-seo/v1';
		$editor = static fn(): bool => current_user_can( 'edit_posts' ) || current_user_can( Capabilities::MANAGE );
		register_rest_route(
			$ns,
			'/schema/graph',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'graph' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r->get_param( 'post' ) ),
				'args'                => array(
					'post' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/schema/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'validate' ),
				'permission_callback' => $editor,
				'args'                => array(
					'post' => array( 'type' => 'integer' ),
					'json' => array(
						'type'      => 'string',
						'maxLength' => 500000,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/schema/vocab/types',
			array(
				'methods'             => 'GET',
				'callback'            => static fn( \WP_REST_Request $r ): \WP_REST_Response => new \WP_REST_Response( Vocab::search_types( (string) $r->get_param( 'q' ), 30 ) ),
				'permission_callback' => $editor,
			)
		);
		register_rest_route(
			$ns,
			'/schema/vocab/properties',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'properties' ),
				'permission_callback' => $editor,
			)
		);
	}

	/**
	 * A page's graph.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function graph( \WP_REST_Request $r ) {
		$post = get_post( (int) $r->get_param( 'post' ) );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'There is no such page.', 'zinn-seo' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( Feed::graph_for( $post ) );
	}

	/**
	 * Validate a page's graph, or pasted JSON-LD.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function validate( \WP_REST_Request $r ) {
		$json = (string) $r->get_param( 'json' );
		if ( '' !== trim( $json ) ) {
			$graph = self::parse( $json );
			if ( null === $graph ) {
				return new \WP_Error( 'zinn_seo_bad_json', __( 'That is not JSON-LD: paste the contents of a script type="application/ld+json" tag, or the whole tag.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
		} else {
			$id = (int) $r->get_param( 'post' );
			if ( ! Capabilities::can_edit_post( $id ) ) {
				return new \WP_Error( 'zinn_seo_forbidden', __( 'You may not edit that page.', 'zinn-seo' ), array( 'status' => 403 ) );
			}
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				return new \WP_Error( 'zinn_seo_not_found', __( 'There is no such page.', 'zinn-seo' ), array( 'status' => 404 ) );
			}
			$graph = Feed::graph_for( $post );
		}
		$out          = Validator::check( $graph, self::own_names() );
		$out['graph'] = $graph;

		return new \WP_REST_Response( $out );
	}

	/**
	 * JSON-LD from pasted text: JSON, one or more script tags, or a whole page's HTML.
	 *
	 * @param string $text Text.
	 * @return array<mixed>|null
	 */
	public static function parse( string $text ): ?array {
		$text = trim( $text );
		if ( false !== stripos( $text, '<script' ) ) {
			$nodes = array();
			if ( preg_match_all( '#<script\b[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $text, $m ) ) {
				foreach ( $m[1] as $raw ) {
					$data = json_decode( html_entity_decode( trim( $raw ), ENT_NOQUOTES, 'UTF-8' ), true );
					if ( is_array( $data ) ) {
						$nodes = array_merge( $nodes, Validator::top_nodes( $data ) );
					}
				}
			}
			return array() === $nodes ? null : array( '@graph' => $nodes );
		}
		$data = json_decode( $text, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * The names the site goes by.
	 *
	 * @return array<int, string>
	 */
	public static function own_names(): array {
		return array_values(
			array_filter(
				array(
					(string) Settings::get( 'identity_name', '' ),
					(string) Settings::get( 'identity_alt_name', '' ),
					(string) get_bloginfo( 'name' ),
				)
			)
		);
	}

	/**
	 * A type's properties.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function properties( \WP_REST_Request $r ) {
		$type = (string) $r->get_param( 'type' );
		if ( ! Vocab::is_type( $type ) ) {
			/* translators: %s: a type name. */
			return new \WP_Error( 'zinn_seo_unknown_type', sprintf( __( '"%s" is not a schema.org type.', 'zinn-seo' ), $type ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response(
			array(
				'type'       => $type,
				'parents'    => array_values( array_diff( Vocab::ancestors( $type ), array( $type ) ) ),
				'properties' => Vocab::properties_of( $type ),
			)
		);
	}
}
