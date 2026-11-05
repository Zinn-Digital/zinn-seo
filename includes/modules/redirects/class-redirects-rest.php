<?php
/**
 * REST routes of the redirect manager.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /redirects` — rules, newest first, keyset (`before`), filters; totals and groups.
 * - `POST /redirects` — create; `PUT /redirects/{id}` — change; `DELETE /redirects/{id}`.
 * - `POST /redirects/bulk` — delete, enable, disable or regroup many.
 * - `GET  /redirects/test?url=` — what a visitor asking for that address gets, hop by hop.
 * - `GET  /redirects/health` — index, last check, last import, export link.
 * - `POST /redirects/index` — rewrite the index; `POST /redirects/check` — find chains and loops;
 *   `POST /redirects/fix` — fix them.
 * - `GET  /redirects/import` — sources with their rule counts; `POST /redirects/import/preview`;
 *   `POST /redirects/import` (start); `POST /redirects/import/upload` (keep a spreadsheet).
 *
 * Managing needs `zseo_redirects`; reading needs `zseo_view` or it.
 */
final class Redirects_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * May the current user manage redirects?
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( Capabilities::REDIRECTS );
	}

	/**
	 * May the current user see them?
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return current_user_can( Capabilities::REDIRECTS ) || current_user_can( Capabilities::VIEW );
	}

	/**
	 * The fields of a rule.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function rule_args(): array {
		return array(
			'source'   => array( 'type' => 'string' ),
			'target'   => array( 'type' => 'string' ),
			'code'     => array(
				'type' => 'integer',
				'enum' => Store::CODES,
			),
			'regex'    => array( 'type' => 'boolean' ),
			'enabled'  => array( 'type' => 'boolean' ),
			'group'    => array( 'type' => 'string' ),
			'note'     => array( 'type' => 'string' ),
			'position' => array( 'type' => 'integer' ),
		);
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
		$done   = true;
		$view   = array( self::class, 'can_view' );
		$manage = array( self::class, 'can_manage' );
		register_rest_route(
			Plugin::REST_NS,
			'/redirects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list' ),
					'permission_callback' => $view,
					'args'                => array(
						'search'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'group'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'code'    => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'origin'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'problem' => array(
							'type'    => 'string',
							'default' => '',
						),
						'regex'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'enabled' => array(
							'type'    => 'string',
							'default' => '',
						),
						'before'  => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'per'     => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 500,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create' ),
					'permission_callback' => $manage,
					'args'                => self::rule_args(),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update' ),
					'permission_callback' => $manage,
					'args'                => self::rule_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$n = Store::delete( array( (int) $r['id'] ) );
						return 0 === $n ? new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) ) : rest_ensure_response( array( 'deleted' => $n ) );
					},
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'bulk' ),
				'permission_callback' => $manage,
				'args'                => array(
					'ids'    => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'delete', 'enable', 'disable', 'group' ),
						'required' => true,
					),
					'group'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/test',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'test' ),
				'permission_callback' => $view,
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'health' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/index',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					Store::maybe_install();
					$s = Jobs::start( Index::JOB, array() );
					return is_wp_error( $s ) ? $s : rest_ensure_response( Jobs::view( Index::JOB ) );
				},
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$s = Checker::start( (bool) $r['targets'] );
					return is_wp_error( $s ) ? $s : rest_ensure_response( Jobs::view( Checker::JOB ) );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'targets' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/fix',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn() => rest_ensure_response( Checker::fix() ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/import',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response(
						array(
							'sources' => Importer::sources(),
							'job'     => Jobs::view( Importer::JOB ),
							'last'    => get_option( 'zseo_redirects_imported', null ),
						)
					),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$source = (string) $r['source'];
						if ( ! self::valid_source( $source ) ) {
							return new \WP_Error( 'zinn_seo_import_source', __( 'There is no such source to import from.', 'zinn-seo' ), array( 'status' => 400 ) );
						}
						$s = Importer::start( $source, (string) $r['text'] );
						return is_wp_error( $s ) ? $s : rest_ensure_response( Jobs::view( Importer::JOB ) );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'source' => array(
							'type'     => 'string',
							'required' => true,
						),
						'text'   => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/import/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$source = (string) $r['source'];
					if ( ! self::valid_source( $source ) ) {
						return new \WP_Error( 'zinn_seo_import_source', __( 'There is no such source to import from.', 'zinn-seo' ), array( 'status' => 400 ) );
					}
					return rest_ensure_response( Importer::preview( $source, (string) $r['text'] ) );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'text'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/redirects/import/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$files = $r->get_file_params();
					$body  = isset( $files['file']['tmp_name'] ) && is_uploaded_file( (string) $files['file']['tmp_name'] ) ? (string) file_get_contents( (string) $files['file']['tmp_name'] ) : (string) $r['content']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded temporary file.
					if ( '' === trim( $body ) ) {
						return new \WP_Error( 'zinn_seo_import_empty', __( 'The file is empty.', 'zinn-seo' ), array( 'status' => 400 ) );
					}
					$token = Importer::keep_upload( $body );
					return is_wp_error( $token ) ? $token : rest_ensure_response( array( 'source' => 'csv:' . $token ) );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'content' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Is an import source one we know?
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	private static function valid_source( string $source ): bool {
		return 'server' === $source || isset( Importer::names()[ $source ] ) || 1 === preg_match( '/^csv:[a-z0-9]{32}$/', $source );
	}

	/**
	 * GET /redirects.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function list( \WP_REST_Request $r ) {
		$page = Store::page( $r->get_params() );
		if ( 0 === (int) $r['before'] ) {
			$page['totals'] = Store::totals();
			$page['groups'] = Store::groups();
		}

		return rest_ensure_response( $page );
	}

	/**
	 * The rule fields a request sent.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array<string, mixed>
	 */
	private static function fields( \WP_REST_Request $r ): array {
		$out = array();
		foreach ( array_keys( self::rule_args() ) as $k ) {
			if ( null !== $r->get_param( $k ) ) {
				$out[ $k ] = $r->get_param( $k );
			}
		}

		return $out;
	}

	/**
	 * POST /redirects.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( \WP_REST_Request $r ) {
		$in           = self::fields( $r );
		$in['origin'] = (string) ( $r->get_param( 'origin' ) ?? 'manual' );
		$saved        = Store::save( $in );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( \ZinnDigital\ZinnSEO\Settings::get( 'redirects_collapse', true ) && '' !== (string) $saved['target'] && ! $saved['regex'] ) {
			Checker::collapse( (string) $saved['source'], (string) $saved['target'] );
		}

		return new \WP_REST_Response( $saved, 201 );
	}

	/**
	 * PUT /redirects/{id}.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( \WP_REST_Request $r ) {
		$row = Store::get( (int) $r['id'] );
		if ( null === $row ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$in       = array_merge(
			array(
				'source'   => (string) $row['source'],
				'target'   => (string) $row['target'],
				'code'     => (int) $row['code'],
				'regex'    => (bool) (int) $row['is_regex'],
				'enabled'  => (bool) (int) $row['enabled'],
				'group'    => (string) $row['grp'],
				'note'     => (string) $row['note'],
				'position' => (int) $row['position'],
			),
			self::fields( $r )
		);
		$in['id'] = (int) $row['id'];
		$saved    = Store::save( $in );

		return is_wp_error( $saved ) ? $saved : rest_ensure_response( $saved );
	}

	/**
	 * POST /redirects/bulk.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function bulk( \WP_REST_Request $r ) {
		$ids = array_map( 'intval', (array) $r['ids'] );
		Index::defer();
		switch ( (string) $r['action'] ) {
			case 'delete':
				$n = Store::delete( $ids );
				break;
			case 'enable':
			case 'disable':
				$n = Store::enable( $ids, 'enable' === $r['action'] );
				break;
			default:
				$n = 0;
				foreach ( $ids as $id ) {
					$row = Store::get( $id );
					if ( null === $row ) {
						continue;
					}
					$saved = Store::save(
						array(
							'id'       => $id,
							'source'   => (string) $row['source'],
							'target'   => (string) $row['target'],
							'code'     => (int) $row['code'],
							'regex'    => (bool) (int) $row['is_regex'],
							'enabled'  => (bool) (int) $row['enabled'],
							'group'    => (string) $r['group'],
							'note'     => (string) $row['note'],
							'position' => (int) $row['position'],
						)
					);
					$n    += is_wp_error( $saved ) ? 0 : 1;
				}
		}
		Index::flush();

		return rest_ensure_response( array( 'changed' => $n ) );
	}

	/**
	 * GET /redirects/test.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function test( \WP_REST_Request $r ) {
		$url   = (string) $r['url'];
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$uri   = ( '' === $path ? '/' : $path ) . ( '' === $query ? '' : '?' . $query );
		$match = Store::is_local( $url ) ? Serve::match( $uri, true ) : null;
		if ( null === $match ) {
			return rest_ensure_response(
				array(
					'match' => null,
					'hops'  => array(),
				)
			);
		}
		$rule = Store::get( (int) $match['id'] );
		$f    = '' === (string) $match['location'] ? null : Checker::follow( (int) $match['id'], (string) $match['location'] );
		$hops = array();
		foreach ( null === $f ? array( (int) $match['id'] ) : $f['path'] as $id ) {
			$hop = Store::get( (int) $id );
			if ( null !== $hop ) {
				$hops[] = Store::shape( $hop );
			}
		}

		return rest_ensure_response(
			array(
				'match'  => array(
					'rule'     => null === $rule ? null : Store::shape( $rule ),
					'code'     => (int) $match['code'],
					'location' => (string) $match['location'],
				),
				'status' => null === $f ? 'gone' : $f['status'],
				'final'  => null === $f ? '' : (string) $f['final'],
				'hops'   => $hops,
			)
		);
	}

	/**
	 * GET /redirects/health.
	 *
	 * @return \WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'index'     => Index::health(),
				'checked'   => get_option( 'zseo_redirects_checked', null ),
				'check'     => Jobs::view( Checker::JOB ),
				'totals'    => Store::totals(),
				'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=' . Redirects::EXPORT ), Redirects::EXPORT ),
			)
		);
	}
}
