<?php
/**
 * REST: `zinn-seo/v1/ai*` — what the screens, the editor, WP-CLI and AI agents use.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

use ZinnDigital\ZinnSEO\AiCore\Client;
use ZinnDigital\ZinnSEO\AiCore\Core;
use ZinnDigital\ZinnSEO\AiCore\Zinn;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ Two permissions, both asked at the point of use: `zseo_ai` to use AI at all, and — when the
 * request will be paid with Zinn® credits — `zseo_spend` as well (administrators only by default:
 * spending the owner's money is the owner's call). A person who may use AI with the site's own
 * key is refused a credits run with a sentence that says so, never a silent fall-back.
 */
final class Ai_Rest {

	/**
	 * Register on `rest_api_init`.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * May use AI?
	 *
	 * @return bool
	 */
	public static function can_use(): bool {
		return current_user_can( Capabilities::AI );
	}

	/**
	 * The routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$ns   = Plugin::REST_NS;
		// The AI providers page, for AI agents (DOC1): needs the shared AI core, which loads with
		// this module.
		if ( class_exists( \ZinnDigital\ZinnSEO\AiCore\Store::class ) ) {
			require_once __DIR__ . '/class-ai-providers-rest.php';
			Ai_Providers_Rest::routes( $ns );
		}
		register_rest_route(
			$ns,
			'/ai',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( self::state() ),
					'permission_callback' => array( self::class, 'can_use' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => static fn(): bool => current_user_can( Capabilities::MANAGE ),
					'args'                => array(
						'funding' => array(
							'type'     => 'string',
							'enum'     => Door::FUNDING,
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ai/models',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::models( (string) $r['feature'] ) ),
					'permission_callback' => array( self::class, 'can_use' ),
					'args'                => array(
						'feature' => array(
							'type'     => 'string',
							'enum'     => array_keys( Model_Picker::FEATURES ),
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'choose_model' ),
					'permission_callback' => static fn(): bool => current_user_can( Capabilities::MANAGE ),
					'args'                => array(
						'feature' => array(
							'type'     => 'string',
							'enum'     => array_keys( Model_Picker::FEATURES ),
							'required' => true,
						),
						'choice'  => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 200,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ai/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'run' ),
				'permission_callback' => array( self::class, 'can_use' ),
				'args'                => array(
					'task'    => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z][a-z0-9-]{0,30}$',
					),
					'ids'     => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'default'  => array( 0 ),
						'maxItems' => 50,
					),
					'options' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ai/suggestions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Suggestions::queue( (string) $r['task'], (int) $r['after'], (int) $r['limit'] ) ),
				'permission_callback' => array( self::class, 'can_use' ),
				'args'                => array(
					'task'  => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z][a-z0-9-]{0,30}$',
					),
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ai/suggestions/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'item' ),
					'permission_callback' => static fn( \WP_REST_Request $r ): bool => self::can_use() && current_user_can( 'edit_post', (int) $r['id'] ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'decide' ),
					'permission_callback' => static fn( \WP_REST_Request $r ): bool => self::can_use() && current_user_can( 'edit_post', (int) $r['id'] ),
					'args'                => array(
						'task'   => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[a-z][a-z0-9-]{0,30}$',
						),
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'accept', 'reject' ),
							'required' => true,
						),
						'fields' => array(
							'type' => 'object',
						),
					),
				),
			)
		);
	}

	/**
	 * What the AI screen and the editor need to know.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$credits = class_exists( Zinn::class ) ? Zinn::state() : array(
			'connected' => false,
			'can_spend' => false,
		);
		$on      = Ai::ready() && Client::on_credits( 'write' );
		$own     = Door::own_key( 'write' );

		return array(
			'funding'      => Door::funding(),
			'ready'        => Ai::ready() && ( $own || ! empty( $credits['can_spend'] ) ),
			'on_credits'   => $on,
			'own_key'      => $own,
			'reads_images' => ! $on && $own,
			'credits'      => array(
				'connected'      => ! empty( $credits['connected'] ),
				'can_spend'      => ! empty( $credits['can_spend'] ),
				'account'        => (string) ( $credits['account'] ?? '' ),
				'balance_micros' => $credits['balance_micros'] ?? null,
				'buy_url'        => (string) ( $credits['buy_url'] ?? '' ),
			),
			'may_spend'    => current_user_can( Capabilities::SPEND ),
			'pro'          => Plugin::is_pro(),
			'tasks'        => Tasks::describe(),
			'settings_url' => class_exists( Core::class ) ? Core::settings_url() : '',
			'usage'        => Usage::summary(),
		);
	}

	/**
	 * A feature's model choices: the saved one and the current models of every own-key provider.
	 *
	 * @param string $feature Feature id.
	 * @return array<string, mixed>
	 */
	public static function models( string $feature ): array {
		$task = Model_Picker::FEATURES[ $feature ] ?? 'write';
		Model_Picker::follow( $feature );

		return array(
			'feature'    => $feature,
			'moved'      => Model_Picker::moved( $feature ),
			'choice'     => Model_Picker::saved( $feature ),
			'on_credits' => 'image' === $task ? false : Ai::ready() && Client::on_credits( $task ),
			'options'    => Model_Picker::options( $feature ),
		);
	}

	/**
	 * Save a feature's model ('' = the site's default). Only a current model may be chosen.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function choose_model( \WP_REST_Request $r ) {
		$feature = (string) $r['feature'];
		$choice  = trim( (string) $r['choice'] );
		if ( '' !== $choice && ! in_array( $choice, array_column( Model_Picker::options( $feature ), 'value' ), true ) ) {
			return new \WP_Error( 'zinn_seo_ai_model', __( 'That model is not one of the current models of your connected AI providers. Choose one from the list.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		$all             = (array) Settings::get( 'ai_models', array() );
		$all[ $feature ] = array( 'choice' => $choice );
		$saved           = Settings::save( array( 'ai_models' => $all ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( self::models( $feature ) );
	}

	/**
	 * Save the funding choice.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $r ) {
		$saved = Settings::save( array( 'ai_funding' => (string) $r['funding'] ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( self::state() );
	}

	/**
	 * Run a task now.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run( \WP_REST_Request $r ) {
		$task = (string) $r['task'];
		$def  = Tasks::get( $task );
		if ( null !== $def && Ai::on_credits( $def ) && ! current_user_can( Capabilities::SPEND ) ) {
			return new \WP_Error( 'zinn_seo_ai_spend', __( 'AI on this site is paid with Zinn® credits, and your account is not allowed to spend them. Ask the site\'s administrator.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		$run = Ai::run( $task, array_map( 'intval', (array) $r['ids'] ), is_array( $r['options'] ) ? $r['options'] : array() );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$error = Ai::as_error( $run );

		return null !== $error ? $error : rest_ensure_response( $run );
	}

	/**
	 * An item's waiting suggestions.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function item( \WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$out = array();
		foreach ( Suggestions::for_item( $id ) as $task => $row ) {
			$out[ $task ] = array(
				'fields'  => $row['fields'],
				'at'      => (int) $row['at'],
				'credits' => ! empty( $row['credits'] ),
				'current' => Suggestions::current( $id, (string) $task ),
			);
		}

		return rest_ensure_response( (object) $out );
	}

	/**
	 * Accept or reject a suggestion.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function decide( \WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$task = (string) $r['task'];
		if ( 'reject' === $r['action'] ) {
			Suggestions::drop( $id, $task );
			return rest_ensure_response( array( 'dropped' => true ) );
		}
		$done = Suggestions::accept( $id, $task, is_array( $r['fields'] ) ? $r['fields'] : null );

		return is_wp_error( $done ) ? $done : rest_ensure_response( $done );
	}
}
