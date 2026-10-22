<?php
/**
 * REST routes of the move from another SEO plugin.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `zinn-seo/v1/import…`. Everything here changes what search engines read about the whole site,
 * so every route needs `zseo_manage`; switching a plugin off needs WordPress's own
 * `deactivate_plugins` as well (checked in Switcher).
 */
final class Import_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * May the current user run a move?
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done   = true;
		$ns     = Plugin::REST_NS;
		$can    = array( self::class, 'can' );
		$source = array(
			'source'    => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[a-z]{2,20}$',
			),
			'overwrite' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
		register_rest_route(
			$ns,
			'/import',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( Import::describe() ),
				'permission_callback' => $can,
			)
		);
		$post = static function ( string $path, callable $run, array $args = array() ) use ( $ns, $can ): void {
			register_rest_route(
				$ns,
				$path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function ( \WP_REST_Request $request ) use ( $run ) {
						$result = $run( $request );
						return is_wp_error( $result ) ? $result : rest_ensure_response( Import::describe() );
					},
					'permission_callback' => $can,
					'args'                => $args,
				)
			);
		};
		$post( '/import/count', static fn( \WP_REST_Request $r ) => Import::start( (string) $r['source'], true, (bool) $r['overwrite'] ), $source );
		$post( '/import/begin', static fn( \WP_REST_Request $r ) => Import::begin( (string) $r['source'], (bool) $r['overwrite'] ), $source );
		$post( '/import/copy', static fn( \WP_REST_Request $r ) => Import::start( (string) $r['source'], false, (bool) $r['overwrite'] ), $source );
		$post(
			'/import/preflight',
			static fn( \WP_REST_Request $r ) => Preflight::run( (string) $r['source'] ),
			array_intersect_key( $source, array( 'source' => 1 ) )
		);
		$post( '/import/verify', static fn() => Verify::start() );
		$post( '/import/stop', static fn() => Import::stop() );
		$post(
			'/import/check',
			static fn( \WP_REST_Request $r ) => Check::start( (bool) $r['keep'] ),
			array(
				'keep' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			)
		);
		$post( '/import/accept', static fn() => Check::accept() );
		$post( '/import/switch', static fn() => Switcher::off() );
		$post( '/import/undo', static fn() => Import::undo() );
		register_rest_route(
			$ns,
			'/import/differences',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'differences' ),
				'permission_callback' => $can,
			)
		);
	}

	/**
	 * GET /import/differences: the first differences the comparison found.
	 *
	 * @return \WP_REST_Response
	 */
	public static function differences(): \WP_REST_Response {
		return rest_ensure_response( self::listed() );
	}

	/**
	 * The listed differences with a title for each page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function listed(): array {
		$list = get_option( Check::DIFFS, array() );
		$out  = array();
		foreach ( is_array( $list ) ? array_values( $list ) : array() as $row ) {
			$row   = (array) $row;
			$label = (string) $row['url'];
			$edit  = '';
			if ( 'post' === $row['kind'] ) {
				$label = (string) get_the_title( (int) $row['id'] );
				$edit  = (string) get_edit_post_link( (int) $row['id'], 'raw' );
			} elseif ( 'term' === $row['kind'] ) {
				$term = get_term( (int) $row['id'] );
				if ( $term instanceof \WP_Term ) {
					$label = (string) $term->name;
					$edit  = (string) get_edit_term_link( $term );
				}
			}
			$row['label'] = '' !== $label ? $label : (string) $row['url'];
			$row['edit']  = $edit;
			$out[]        = $row;
		}

		return $out;
	}
}
