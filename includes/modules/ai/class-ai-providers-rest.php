<?php
/**
 * REST: `zinn-seo/v1/ai/providers*` — the Settings > AI providers page, for AI agents (DOC1, Q270).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

use ZinnDigital\ZinnSEO\AiCore\Catalogue;
use ZinnDigital\ZinnSEO\AiCore\Core;
use ZinnDigital\ZinnSEO\AiCore\Failure;
use ZinnDigital\ZinnSEO\AiCore\Models;
use ZinnDigital\ZinnSEO\AiCore\Registry;
use ZinnDigital\ZinnSEO\AiCore\Store;
use ZinnDigital\ZinnSEO\AiCore\Tester;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI providers page (the shared AI core's Settings > AI providers) is a form-post page: before
 * these routes an agent could not see which provider is connected, test it, refresh its model
 * lists, add a model the provider has not listed yet, choose which model each task uses, or switch
 * the daily model-list updates. Each route does exactly what that page's button does, under the
 * page's own permission (`manage_options`).
 *
 * ⛔ A provider's KEY is never read or written here: a credential is typed by a person on the page,
 * never passed through a model (the same rule as the Facebook app secret, mcp-rest-map.py).
 */
final class Ai_Providers_Rest {

	/** The page's own permission (AiCore\Admin::CAP). */
	public const CAP = 'manage_options';

	/** The tasks a default model is chosen for: the core's text tasks, images and embeddings. */
	public const DEFAULT_TASKS = array( 'general', 'write', 'translate', 'code', 'images', 'embeddings' );

	/**
	 * May manage the providers?
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAP );
	}

	/**
	 * Register the routes (called from Ai_Rest::routes()).
	 *
	 * @param string $ns REST namespace.
	 * @return void
	 */
	public static function routes( string $ns ): void {
		$provider = array(
			'type'     => 'string',
			'required' => true,
		);
		register_rest_route(
			$ns,
			'/ai/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( self::state() ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/ai/providers/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'test' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array( 'provider' => $provider ),
			)
		);
		register_rest_route(
			$ns,
			'/ai/providers/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'refresh' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/ai/providers/models',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'models' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'provider' => $provider,
					'action'   => array(
						'type'     => 'string',
						'enum'     => array( 'add', 'remove' ),
						'required' => true,
					),
					'model'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ai/providers/defaults',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'defaults' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'task'     => array(
						'type'     => 'string',
						'enum'     => self::DEFAULT_TASKS,
						'required' => true,
					),
					'provider' => array(
						'type'    => 'string',
						'default' => '',
					),
					'model'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ai/providers/catalogue',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'catalogue' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'remote' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Every provider (never its key), the model each task uses, and the daily updates switch.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$providers = array();
		foreach ( Registry::all() as $id => $spec ) {
			$row         = Store::provider( (string) $id );
			$test        = is_array( $row['test'] ?? null ) ? $row['test'] : array();
			$providers[] = array(
				'id'           => (string) $id,
				'label'        => (string) ( $spec['label'] ?? $id ),
				'connected'    => Store::configured( (string) $id ),
				'key'          => Store::key_state( (string) $id ),
				'last_test'    => array() === $test ? null : array(
					'ok'      => (bool) ( $test['ok'] ?? false ),
					'at'      => (int) ( $test['at'] ?? 0 ),
					'message' => (string) ( $test['message'] ?? '' ),
				),
				'added_models' => array_values( array_map( static fn( $m ): string => (string) ( $m['id'] ?? '' ), Store::added_models( (string) $id ) ) ),
			);
		}
		$defaults = array();
		foreach ( Store::TASKS as $task ) {
			$defaults[ $task ] = Store::default_for( $task );
		}
		$defaults['images']     = Store::image_default();
		$defaults['embeddings'] = Store::embed_default();
		$record                 = Store::get();
		$catalogue              = is_array( $record['catalogue'] ?? null ) ? $record['catalogue'] : array();

		return array(
			'providers' => $providers,
			'defaults'  => $defaults,
			'catalogue' => array(
				'daily_updates' => ! empty( $catalogue['remote'] ),
				'published'     => Catalogue::published(),
			),
			'page'      => Core::settings_url(),
		);
	}

	/**
	 * A known provider id, or a REST error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private static function provider( \WP_REST_Request $request ) {
		$id = sanitize_key( (string) $request->get_param( 'provider' ) );
		if ( ! Registry::exists( $id ) ) {
			return new \WP_Error( 'zinn_seo_ai_provider', __( 'Unknown provider. Use an id from the list of AI providers.', 'zinn-seo' ), array( 'status' => 400 ) );
		}

		return $id;
	}

	/**
	 * A provider failure as a REST error (its own words, its kind for the caller).
	 *
	 * @param Failure $failure Failure.
	 * @return \WP_Error
	 */
	private static function failure( Failure $failure ): \WP_Error {
		return new \WP_Error(
			'zinn_seo_ai_' . $failure->kind,
			$failure->message,
			array(
				'status'   => 502,
				'kind'     => $failure->kind,
				'provider' => $failure->provider,
			)
		);
	}

	/**
	 * POST /ai/providers/test: the page's "Test" button.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test( \WP_REST_Request $request ) {
		$id = self::provider( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$outcome = Tester::run( $id );
		Store::set_provider(
			$id,
			array(
				'test' => array(
					'ok'      => $outcome['ok'],
					'at'      => time(),
					'message' => null === $outcome['failure'] ? '' : $outcome['failure']->message,
				),
			)
		);
		if ( ! $outcome['ok'] && null !== $outcome['failure'] ) {
			return self::failure( $outcome['failure'] );
		}

		return rest_ensure_response(
			array(
				'ok'      => (bool) $outcome['ok'],
				'message' => (string) $outcome['message'],
				'model'   => (string) $outcome['model'],
			)
		);
	}

	/**
	 * POST /ai/providers/refresh: the page's "Refresh model lists".
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function refresh() {
		$done = array();
		foreach ( array_keys( Registry::all() ) as $id ) {
			if ( ! Store::configured( (string) $id ) ) {
				continue;
			}
			$models = Models::discovered( (string) $id, true );
			if ( $models instanceof Failure ) {
				return self::failure( $models );
			}
			Models::image_choices( (string) $id, true );
			Models::embedding_choices( (string) $id, true );
			$done[ (string) $id ] = is_array( $models ) ? count( $models ) : 0;
		}

		return rest_ensure_response( array( 'refreshed' => $done ) );
	}

	/**
	 * POST /ai/providers/models: add a model the provider has not listed yet, or remove one added.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function models( \WP_REST_Request $request ) {
		$id = self::provider( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$model = trim( sanitize_text_field( (string) $request->get_param( 'model' ) ) );
		if ( 1 !== preg_match( '#^[A-Za-z0-9._:/@+\-]{1,200}$#', $model ) ) {
			return new \WP_Error( 'zinn_seo_ai_model', __( 'Enter the model\'s exact name, for example as the provider\'s documentation writes it.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$add = 'add' === (string) $request->get_param( 'action' );
		Store::update(
			static function ( array $record ) use ( $id, $model, $add ): array {
				$added = is_array( $record['added_models'] ?? null ) ? $record['added_models'] : array();
				$added = array_values(
					array_filter(
						$added,
						static fn( $row ): bool => ! ( is_array( $row ) && ( $row['provider'] ?? '' ) === $id && ( $row['id'] ?? '' ) === $model )
					)
				);
				if ( $add ) {
					$added[] = array(
						'provider' => $id,
						'id'       => $model,
						'label'    => $model,
					);
				}
				$record['added_models'] = $added;

				return $record;
			}
		);

		return rest_ensure_response(
			array(
				'provider'     => $id,
				'added_models' => array_values( array_map( static fn( $m ): string => (string) ( $m['id'] ?? '' ), Store::added_models( $id ) ) ),
			)
		);
	}

	/**
	 * PUT /ai/providers/defaults: which provider and model one task uses (empty: the automatic
	 * choice).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function defaults( \WP_REST_Request $request ) {
		$task     = (string) $request->get_param( 'task' );
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$model    = trim( sanitize_text_field( (string) $request->get_param( 'model' ) ) );
		$choice   = array();
		if ( '' !== $provider || '' !== $model ) {
			if ( ! Store::configured( $provider ) || '' === $model ) {
				return new \WP_Error( 'zinn_seo_ai_default', __( 'Choose a connected provider and one of its models.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
			$choice = array(
				'provider' => $provider,
				'model'    => $model,
			);
		}
		Store::update(
			static function ( array $record ) use ( $task, $choice ): array {
				if ( 'images' === $task || 'embeddings' === $task ) {
					$record[ $task ] = $choice;

					return $record;
				}
				$all = is_array( $record['defaults'] ?? null ) ? $record['defaults'] : array();
				if ( array() === $choice ) {
					unset( $all[ $task ] );
				} else {
					$all[ $task ] = $choice;
				}
				$record['defaults'] = $all;

				return $record;
			}
		);

		return rest_ensure_response( self::state()['defaults'] );
	}

	/**
	 * PUT /ai/providers/catalogue: daily model-list updates on or off (on checks now).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function catalogue( \WP_REST_Request $request ): \WP_REST_Response {
		$remote = (bool) $request->get_param( 'remote' );
		Store::update(
			static function ( array $record ) use ( $remote ): array {
				$row           = is_array( $record['catalogue'] ?? null ) ? $record['catalogue'] : array();
				$row['remote'] = $remote;
				if ( ! $remote ) {
					unset( $row['envelope'] );
				}
				$record['catalogue'] = $row;

				return $record;
			}
		);
		Catalogue::reset();
		Core::maintain();

		return rest_ensure_response(
			array(
				'daily_updates' => $remote,
				'checked'       => $remote ? Catalogue::refresh() : '',
			)
		);
	}
}
