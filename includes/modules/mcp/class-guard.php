<?php
/**
 * Agent writes: logged, tried first on request, held for approval or refused (zs-214).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every Zinn® SEO ability call is an AGENT call (WordPress runs it through WP_Ability::execute(),
 * whether it came over MCP, WP-CLI or the abilities REST API). While one runs:
 *
 *  - the change history records `agent` as the actor (the `zinn_seo_actor` filter), so every
 *    change an agent makes shows as one and can be undone there;
 *  - a CHANGE to one of this plugin's REST routes (anything but GET) is stopped AFTER WordPress
 *    has validated its values and checked the user's permission (`rest_dispatch_request`), and:
 *      * `dry_run: true` (on a route that has no dry run of its own) answers what WOULD be sent
 *        and what the route reads now, and changes nothing;
 *      * write mode `off` refuses it;
 *      * write mode `approve` queues it for a person (Approvals) and says so;
 *      * write mode `apply` lets it through.
 *  - one line per call goes to the agents' log (Log), with how it ended.
 *
 * A person's own requests are never touched: nothing here runs unless an ability started.
 */
final class Guard {

	/**
	 * The log line of the ability running now ('' when none).
	 *
	 * @var string
	 */
	private static string $current = '';

	/**
	 * The ability running now.
	 *
	 * @var string
	 */
	private static string $ability = '';

	/**
	 * Hook in (only when the MCP module is on).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'wp_before_execute_ability', array( self::class, 'before' ), 10, 2 );
		add_action( 'wp_after_execute_ability', array( self::class, 'after' ), 10, 3 );
		add_filter( 'rest_dispatch_request', array( self::class, 'dispatch' ), 10, 4 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'outcome' ), 10, 3 );
		add_filter( 'zinn_seo_actor', array( self::class, 'actor' ) );
		add_action( 'shutdown', array( self::class, 'shutdown' ) );
	}

	/**
	 * Is an agent's ability running now?
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return '' !== self::$current;
	}

	/**
	 * `wp_before_execute_ability`.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Its input.
	 * @return void
	 */
	public static function before( $name, $input = null ): void {
		if ( ! is_string( $name ) || ! str_starts_with( $name, Abilities::CATEGORY . '/' ) ) {
			return;
		}
		// The previous call ended without WordPress's "after" action: it returned an error.
		self::close( 'error' );
		self::$ability = $name;
		self::$current = Log::start( $name, $input );
	}

	/**
	 * `wp_after_execute_ability`.
	 *
	 * @param string $name   Ability name.
	 * @param mixed  $input  Its input.
	 * @param mixed  $result Its result.
	 * @return void
	 */
	public static function after( $name, $input = null, $result = null ): void {
		unset( $input );
		if ( $name !== self::$ability || '' === self::$current ) {
			return;
		}
		self::close( is_wp_error( $result ) ? 'error' : 'done' );
	}

	/**
	 * `rest_dispatch_request`: hold, refuse or preview an agent's change.
	 *
	 * @param mixed            $result  Null to dispatch normally.
	 * @param \WP_REST_Request $request Request.
	 * @param string           $route   Matched route.
	 * @param array            $handler Its handler.
	 * @return mixed
	 */
	public static function dispatch( $result, $request, $route = '', $handler = array() ) {
		if ( null !== $result || ! self::active() || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		$method = strtoupper( (string) $request->get_method() );
		if ( 'GET' === $method || 'HEAD' === $method || ! str_starts_with( (string) $request->get_route(), '/' . \ZinnDigital\ZinnSEO\Plugin::REST_NS . '/' ) ) {
			return $result;
		}
		$own_dry_run = isset( ( (array) ( $handler['args'] ?? array() ) )['dry_run'] );
		if ( ! $own_dry_run && rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ) ) {
			Log::finish( self::$current, 'dry-run', 200 );
			return rest_ensure_response( self::preview( $request, (string) $route ) );
		}
		$mode = Mcp::writes();
		if ( 'off' === $mode ) {
			Log::finish( self::$current, 'refused', 403, 'writes off' );
			return new \WP_Error(
				'zinn_seo_agent_writes_off',
				__( 'The site owner lets AI agents read and plan only, so nothing was changed. Send dry_run: true to show them what you would change, or ask them to allow changes on Zinn® SEO → Settings → AI agents.', 'zinn-seo' ),
				array( 'status' => 403 )
			);
		}
		if ( 'approve' === $mode ) {
			$item = Approvals::queue( $request, self::$ability );
			if ( is_wp_error( $item ) ) {
				Log::finish( self::$current, 'refused', 429, 'queue full' );
				return $item;
			}
			Log::finish( self::$current, 'queued', 202, (string) $item['id'] );
			$response = rest_ensure_response(
				array(
					'queued'   => true,
					'approval' => $item['id'],
					'message'  => __( 'Nothing has changed yet: the site owner approves changes made by AI agents. This change is waiting for them on Zinn® SEO → Settings → AI agents.', 'zinn-seo' ),
				)
			);
			$response->set_status( 202 );
			return $response;
		}

		return $result;
	}

	/**
	 * `rest_request_after_callbacks`: remember how the agent's REST call ended.
	 *
	 * @param mixed            $response Response.
	 * @param array            $handler  Handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function outcome( $response, $handler = array(), $request = null ) {
		unset( $handler );
		if ( ! self::active() || ! $request instanceof \WP_REST_Request || ! str_starts_with( (string) $request->get_route(), '/' . \ZinnDigital\ZinnSEO\Plugin::REST_NS . '/' ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) ) {
			$data = (array) $response->get_error_data();
			Log::finish( self::$current, 'error', (int) ( $data['status'] ?? 500 ), (string) $response->get_error_code() );
		}

		return $response;
	}

	/**
	 * `zinn_seo_actor`: an agent while an ability runs.
	 *
	 * @param string $actor Actor.
	 * @return string
	 */
	public static function actor( $actor ): string {
		return self::active() ? 'agent' : (string) $actor;
	}

	/**
	 * `shutdown`: a call still open ended in an error.
	 *
	 * @return void
	 */
	public static function shutdown(): void {
		self::close( 'error' );
	}

	/**
	 * What a change would do: the request, and what the route reads now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $route   Matched route pattern.
	 * @return array<string, mixed>
	 */
	public static function preview( \WP_REST_Request $request, string $route ): array {
		$params = $request->get_json_params();
		$params = array_merge( (array) $request->get_query_params(), (array) $request->get_body_params(), is_array( $params ) ? $params : array() );
		unset( $params['dry_run'] );
		$now = null;
		foreach ( (array) ( rest_get_server()->get_routes()[ $route ] ?? array() ) as $other ) {
			if ( empty( $other['methods']['GET'] ) ) {
				continue;
			}
			$read = new \WP_REST_Request( 'GET', $request->get_route() );
			$read->set_url_params( $request->get_url_params() );
			$got = rest_do_request( $read );
			if ( ! $got->is_error() ) {
				$now = $got->get_data();
			}
			break;
		}

		return array(
			'dry_run' => true,
			'changed' => false,
			'would'   => array(
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
				'params' => $params,
			),
			'now'     => $now,
			'message' => __( 'Nothing was changed. Send the same call without dry_run to make this change.', 'zinn-seo' ),
		);
	}

	/**
	 * Close the open line.
	 *
	 * @param string $outcome Outcome.
	 * @return void
	 */
	private static function close( string $outcome ): void {
		if ( '' === self::$current ) {
			return;
		}
		Log::finish( self::$current, $outcome );
		self::$current = '';
		self::$ability = '';
	}
}
