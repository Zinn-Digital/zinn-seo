<?php
/**
 * REST: what a page's head will say, for the editor panel, the bulk editor and agents.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Head;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /resolved/{post|term}/{id}`: the title, description, canonical and robots a visitor's page
 * gets now, plus what each would be if the page's own field were empty (the template result).
 * Other modules add their part (`social`, `discover`) through `zinn_seo_resolved`.
 */
final class Head_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Plugin::REST_NS,
			'/resolved/(?P<object>post|term)/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'resolved' ),
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					$id = (int) $request['id'];
					return 'term' === $request['object'] ? Capabilities::can_edit_term( $id ) : Capabilities::can_edit_post( $id );
				},
			)
		);
	}

	/**
	 * GET /resolved/{object}/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resolved( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$object = 'term' === $request['object'] ? get_term( $id ) : get_post( $id );
		if ( $object instanceof \WP_Post ) {
			$c = Context::for_post( $object );
		} elseif ( $object instanceof \WP_Term ) {
			$c = Context::for_term( $object );
		} else {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::describe( $c ) );
	}

	/**
	 * Everything the head says about a page.
	 *
	 * @param Context $c Context.
	 * @return array<string, mixed>
	 */
	public static function describe( Context $c ): array {
		$out = array(
			'title'     => Resolver::title( $c ),
			'desc'      => Resolver::description( $c ),
			'canonical' => Resolver::canonical( $c ),
			'address'   => Resolver::address( $c ),
			'robots'    => Resolver::robots( $c ),
			'auto'      => Editor::auto( $c ),
		);

		/**
		 * Filters what the head says about a page (the social module adds `social` and
		 * `discover`).
		 *
		 * @param array<string, mixed> $out Description.
		 * @param Context              $c   The page.
		 */
		return (array) apply_filters( 'zinn_seo_resolved', $out, $c );
	}
}
