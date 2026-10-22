<?php
/**
 * REST routes and Fix-it entries of the hacked-site scan.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\SpamScan;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /spam` (the last result and the job), `POST /spam/scan` (start a scan).
 */
final class Scan_Rest {

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
		register_rest_route(
			Plugin::REST_NS,
			'/spam',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response(
					array(
						'result' => Scan::result(),
						'job'    => Jobs::view( Scan::JOB ),
						'kinds'  => array_map( static fn( $k ) => $k[0], Scan::kinds() ),
						'watch'  => (bool) apply_filters( 'zinn_seo_spam_watch', false ),
					)
				),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/spam/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					$s = Scan::start();
					return is_wp_error( $s ) ? $s : rest_ensure_response( Jobs::view( Scan::JOB ) );
				},
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/spam/ignore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'ignore' ),
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
				'args'                => array(
					'key'     => array(
						'type'        => 'string',
						'description' => __( 'The finding to mark as normal (its key from GET /spam).', 'zinn-seo' ),
					),
					'restore' => array(
						'type'        => 'boolean',
						'description' => __( 'True: show every finding marked as normal again.', 'zinn-seo' ),
					),
				),
			)
		);
	}

	/**
	 * `POST /spam/ignore`: mark one finding "this is normal" (`key`), or show every one again (`restore`).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function ignore( $req ) {
		$restore = (bool) $req->get_param( 'restore' );
		$key     = sanitize_text_field( (string) $req->get_param( 'key' ) );
		if ( ! $restore && 1 !== preg_match( '/^[a-z_]+:.*:[0-9a-f]{32}$/', $key ) ) {
			return new \WP_Error( 'zseo_spam_key', __( 'That finding was not found. Scan again and try once more.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		Scan::ignore( $key, $restore );

		return rest_ensure_response( array( 'result' => Scan::result() ) );
	}

	/**
	 * Fix-it source.
	 *
	 * @param array<string, callable> $sources Sources.
	 * @return array<string, callable>
	 */
	public static function fixit_sources( $sources ): array {
		$sources         = (array) $sources;
		$sources['spam'] = array( self::class, 'issues' );

		return $sources;
	}

	/**
	 * The last scan's findings as Fix-it issues.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function issues(): array {
		$r = Scan::result();
		if ( null === $r ) {
			return array(
				array(
					'id'       => 'spam-never-scanned',
					'area'     => 'technical',
					'priority' => 20,
					'title'    => __( 'The site has never been scanned for SEO spam', 'zinn-seo' ),
					'detail'   => __( 'A hack that adds spam pages or hidden links can go unnoticed for months while it costs your rankings. The scan takes a minute and runs in the background.', 'zinn-seo' ),
					'minutes'  => 1,
					'fix'      => array(
						'kind'  => 'link',
						'url'   => admin_url( 'admin.php?page=zinn-seo&view=protection#/scan' ),
						'label' => __( 'Scan the site', 'zinn-seo' ),
					),
				),
			);
		}
		$out = array();
		foreach ( (array) $r['findings'] as $kind => $f ) {
			$out[] = array(
				'id'       => 'spam-' . $kind,
				'area'     => 'technical',
				'priority' => 95,
				'title'    => sprintf( '%s (%d)', (string) ( $f['name'] ?? $kind ), (int) $f['count'] ),
				'detail'   => (string) ( $f['advice'] ?? '' ),
				'count'    => (int) $f['count'],
				'minutes'  => 15,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => admin_url( 'admin.php?page=zinn-seo&view=protection#/scan' ),
					'label' => __( 'See what was found', 'zinn-seo' ),
				),
			);
		}

		return $out;
	}
}
