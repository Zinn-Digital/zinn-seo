<?php
/**
 * The indexing console's REST routes (zs-742).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Indexing;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /indexing/console` — a page of the log (`engine`, `state`, `search`, `page`), the
 *   rules, which engines are ready.
 * - `POST /indexing/console/submit` `{urls, engines}`.
 * - `POST /indexing/console/retry` `{ids}` (none = every failed row).
 * - `POST /indexing/console/rules` `{rules}`.
 * - `GET  /indexing/console/export` — the whole log as a CSV download.
 */
final class Console_Rest {

	/**
	 * Boot data: which engines this site can use.
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		$data['indexing'] = self::engines();

		return $data;
	}

	/**
	 * Which engines are ready, and why not.
	 *
	 * @return array<string, array{ready: bool, why: string}>
	 */
	public static function engines(): array {
		$indexnow = '\ZinnDigital\ZinnSEO\Indexnow\Indexnow';
		$bing     = '\ZinnDigital\ZinnSEO\Bing\Bing';
		$gsc      = '\ZinnDigital\ZinnSEO\Gsc\Indexing';
		$refusal  = class_exists( $indexnow ) && \ZinnDigital\ZinnSEO\Modules::enabled( 'indexnow' ) ? (string) $indexnow::refusal() : 'off';

		return array(
			'indexnow' => array(
				'ready' => '' === $refusal,
				'why'   => '' === $refusal ? '' : ( 'off' === $refusal ? __( 'Switch on IndexNow under Settings → Features.', 'zinn-seo' ) : __( 'This copy of the site does not send to search engines (a staging copy, or search engines are discouraged).', 'zinn-seo' ) ),
			),
			'bing'     => array(
				'ready' => class_exists( $bing ) && $bing::connected(),
				'why'   => class_exists( $bing ) && $bing::connected() ? '' : __( 'Connect Bing Webmaster Tools with your own free key (Search performance → Bing).', 'zinn-seo' ),
			),
			'google'   => array(
				'ready' => class_exists( $gsc ) && array() !== $gsc::types(),
				'why'   => class_exists( $gsc ) && array() !== $gsc::types() ? '' : __( 'Google takes direct requests only for job and livestream pages. Choose those page types under Search performance → Google indexing.', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Who may use the console.
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
		$done = true;
		$ns   = Plugin::REST_NS;
		register_rest_route(
			$ns,
			'/indexing/console',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response(
					Console::page(
						array(
							'engine' => (string) $r['engine'],
							'state'  => (string) $r['state'],
							'search' => (string) $r['search'],
							'page'   => (int) $r['page'],
							'per'    => 50,
						)
					) + array(
						'rules'   => Console::rules(),
						'engines' => self::engines(),
						'waiting' => count( Console::bing_queue() ),
					)
				),
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'engine' => array(
						'type'    => 'string',
						'enum'    => array_merge( array( '' ), Console::ENGINES ),
						'default' => '',
					),
					'state'  => array(
						'type'    => 'string',
						'enum'    => array_merge( array( '' ), Console::STATES ),
						'default' => '',
					),
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
						'default'   => '',
					),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/indexing/console/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Console::submit( array_map( 'strval', (array) $r['urls'] ), array_map( 'strval', (array) $r['engines'] ) ) ),
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'urls'    => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
						'maxItems' => Console::MAX_SUBMIT,
					),
					'engines' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'items'    => array(
							'type' => 'string',
							'enum' => Console::ENGINES,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/indexing/console/retry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Console::retry( array_map( 'intval', (array) $r['ids'] ) ) ),
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'ids' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/indexing/console/rules',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$rules = Console::clean_rules( (string) $r['rules'] );
					update_option( Console::EXCLUDE, implode( "\n", $rules ), false );
					return rest_ensure_response( array( 'rules' => $rules ) );
				},
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'rules' => array(
						'type'      => 'string',
						'maxLength' => 50000,
						'default'   => '',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/indexing/console/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'export' ),
				'permission_callback' => array( self::class, 'can' ),
			)
		);
	}

	/**
	 * Stream the log as CSV.
	 *
	 * @return void
	 */
	public static function export(): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="indexing-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		Console::export(
			static function ( string $line ): void {
				echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, quoted by Console::csv_line.
			}
		);
		exit;
	}
}
