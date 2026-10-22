<?php
/**
 * Changes AI agents asked for that wait for a person (zs-214, the "approve" mode).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A waiting change is the REST request the agent's tool would have made (method, route and its
 * parameters) and who asked. Approving replays it AS THE PERSON WHO APPROVES, through WordPress's
 * REST dispatch, so their permission is checked again at that moment and the route validates the
 * values again; the change history records it as an agent's change. Rejecting drops it.
 *
 * Bounded: at most MAX wait; past that the agent is told to wait for the owner, and nothing is
 * dropped silently.
 */
final class Approvals {

	/** The option. */
	public const OPTION = 'zseo_mcp_approvals';

	/** Most waiting changes. */
	public const MAX = 200;

	/**
	 * Queue a request.
	 *
	 * @param \WP_REST_Request $request The request the agent made.
	 * @param string           $ability The ability that made it.
	 * @return array<string, mixed>|\WP_Error The queued item.
	 */
	public static function queue( \WP_REST_Request $request, string $ability ) {
		$items = self::all();
		if ( count( $items ) >= self::MAX ) {
			return new \WP_Error(
				'zinn_seo_agent_queue_full',
				__( 'Too many changes are already waiting for approval on this site. Ask the site owner to review them on Zinn® SEO → Settings → AI agents, then try again.', 'zinn-seo' ),
				array( 'status' => 429 )
			);
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$params = array_merge( (array) $request->get_query_params(), (array) $request->get_body_params(), $params );
		unset( $params['dry_run'] );
		$user    = wp_get_current_user();
		$item    = array(
			'id'      => substr( md5( uniqid( $ability, true ) ), 0, 12 ),
			'time'    => time(),
			'ability' => $ability,
			'method'  => $request->get_method(),
			'route'   => $request->get_route(),
			'params'  => $params,
			'user'    => (int) $user->ID,
			'login'   => (string) $user->user_login,
		);
		$items[] = $item;
		update_option( self::OPTION, $items, false );

		return $item;
	}

	/**
	 * Every waiting change, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function pending(): array {
		return self::all();
	}

	/**
	 * Approve (replay) or reject one.
	 *
	 * @param string $id       Item id.
	 * @param string $decision approve|reject.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function decide( string $id, string $decision ) {
		$items = self::all();
		$found = null;
		foreach ( $items as $i => $item ) {
			if ( $item['id'] === $id ) {
				$found = $item;
				unset( $items[ $i ] );
				break;
			}
		}
		if ( null === $found ) {
			return new \WP_Error( 'zinn_seo_no_approval', __( 'That change is no longer waiting: it was already approved or rejected.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		// Removed first, so a double click can never apply a change twice.
		update_option( self::OPTION, array_values( $items ), false );
		if ( 'approve' !== $decision ) {
			return array(
				'id'       => $id,
				'decision' => 'rejected',
			);
		}
		$request = new \WP_REST_Request( (string) $found['method'], (string) $found['route'] );
		if ( 'GET' === $found['method'] || 'DELETE' === $found['method'] ) {
			$request->set_query_params( (array) $found['params'] );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( (object) $found['params'] ) );
		}
		$agent    = static fn(): string => 'agent';
		$response = null;
		add_filter( 'zinn_seo_actor', $agent );
		try {
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'zinn_seo_actor', $agent );
		}
		if ( $response->is_error() ) {
			$error = $response->as_error();
			return new \WP_Error( (string) $error->get_error_code(), (string) $error->get_error_message(), array( 'status' => (int) $response->get_status() ) );
		}

		return array(
			'id'       => $id,
			'decision' => 'approved',
			'result'   => $response->get_data(),
		);
	}

	/**
	 * Read the queue.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function all(): array {
		$items = get_option( self::OPTION, array() );

		return is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
	}
}
