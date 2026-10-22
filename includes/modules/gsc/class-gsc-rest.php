<?php
/**
 * REST routes of the Search Console module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Gsc;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET /search/google` (`choices`, `fresh`) — the Google link; `POST /search/google`
 *   `{features}` — Google's consent page (opened in a new tab); `PATCH /search/google` — choose the
 *   property; `DELETE /search/google` — disconnect.
 * - `GET /search/overview?days=` — totals against the period before, day by day, top pages and
 *   searches (free).
 * - `GET /search/index` — Google's verdicts kept so far, today's allowance, the whole-site job;
 *   `POST /search/index/check` `{url}`; `POST /search/index/site` `{action}`.
 * - `GET /search/sitemaps` — Search Console's sitemaps and our last submissions;
 *   `POST /search/sitemaps` — send now.
 * - `GET|PUT /search/indexing` — the Indexing API for job and livestream pages.
 *
 * Reading needs the right to see SEO reports; connecting, choosing and sending need the right to
 * manage the plugin.
 */
final class Gsc_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * A REST answer from a result or an error.
	 *
	 * @param mixed $result Result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function answer( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
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
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::MANAGE );
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE );
		$ns     = Plugin::REST_NS;

		register_rest_route(
			$ns,
			'/search/google',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Google::link( (bool) $r['choices'], (bool) $r['fresh'] ) ),
					'permission_callback' => $view,
					'args'                => array(
						'choices' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'fresh'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Google::start( array_map( 'strval', (array) $r['features'] ), Google::back_url() ) ),
					'permission_callback' => $manage,
					'args'                => array(
						'features' => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array(
								'type' => 'string',
								'enum' => Google::FEATURES,
							),
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Google::choose( array_filter( (array) $r->get_json_params(), 'is_string' ) ) ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static fn() => self::answer( Google::disconnect() ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( Data::overview( (int) $r['days'], (bool) $r['fresh'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'days'  => array(
						'type'    => 'integer',
						'default' => 28,
					),
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( self::report( (string) $r['dim'], (int) $r['days'], (bool) $r['fresh'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'dim'   => array(
						'type'    => 'string',
						'enum'    => array( 'page', 'query', 'country', 'device' ),
						'default' => 'page',
					),
					'days'  => array(
						'type'    => 'integer',
						'default' => 28,
					),
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/index',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Inspect::report( 50, max( 1, (int) $r['page'] ) ) ),
				'permission_callback' => $view,
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/index/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::answer( self::check( (string) $r['url'] ) ),
				'permission_callback' => $view,
				'args'                => array(
					'url' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/index/site',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$action = (string) $r['action'];
					$result = 'start' === $action ? Jobs::start( Inspect::JOB ) : Jobs::control( Inspect::JOB, $action );

					return is_wp_error( $result ) ? $result : rest_ensure_response( Inspect::report() );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'start', 'pause', 'resume', 'cancel' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/sitemaps',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( self::sitemaps() ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static function () {
						Sitemap_Submit::all( 'manual' );
						return rest_ensure_response( self::sitemaps() );
					},
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			$ns,
			'/search/indexing',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( Indexing::view() ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$saved = \ZinnDigital\ZinnSEO\Settings::save(
							array(
								'gsc_indexing'       => (bool) $r['on'],
								'gsc_indexing_types' => array_values( array_filter( array_map( 'sanitize_key', (array) $r['types'] ), 'post_type_exists' ) ),
							)
						);
						if ( is_wp_error( $saved ) ) {
							return $saved;
						}
						if ( 'paused' === Jobs::get( Indexing::JOB )['status'] ) {
							Jobs::control( Indexing::JOB, 'resume' );
						}
						return rest_ensure_response( Indexing::view() );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'on'    => array(
							'type'     => 'boolean',
							'required' => true,
						),
						'types' => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Every row of one dimension with this period's and the previous period's figures (Pro: the
	 * free plan shows the top ten on the overview).
	 *
	 * @param string $dim   page|query|country|device.
	 * @param int    $days  Days.
	 * @param bool   $fresh Read again.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function report( string $dim, int $days, bool $fresh ) {
		if ( ! Plugin::is_pro() ) {
			return new \WP_Error( 'zinn_seo_pro', __( 'Every page and every search, with the change against the period before, is part of Zinn® SEO Pro.', 'zinn-seo' ), array( 'status' => 402 ) );
		}
		$days              = in_array( $days, array( 7, 28, 90, 180, 365 ), true ) ? $days : 28;
		list( $from, $to ) = Data::window( $days );
		list( $pf, $pt )   = Data::window( $days, $days );
		$deadline          = microtime( true ) + 20.0;
		$now               = Data::set(
			array(
				'startDate'  => $from,
				'endDate'    => $to,
				'dimensions' => array( $dim ),
			),
			$deadline,
			$fresh
		);
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		$then   = Data::set(
			array(
				'startDate'  => $pf,
				'endDate'    => $pt,
				'dimensions' => array( $dim ),
			),
			$deadline,
			$fresh
		);
		$before = array();
		if ( ! is_wp_error( $then ) ) {
			foreach ( Data::named( $then['rows'], array( $dim ) ) as $r ) {
				$before[ (string) $r[ $dim ] ] = $r;
			}
		}
		$rows = array();
		foreach ( Data::named( $now['rows'], array( $dim ) ) as $r ) {
			$k      = (string) $r[ $dim ];
			$rows[] = array(
				'key'         => $k,
				'clicks'      => $r['clicks'],
				'impressions' => $r['impressions'],
				'ctr'         => $r['ctr'],
				'position'    => $r['position'],
				'was'         => isset( $before[ $k ] ) ? array(
					'clicks'   => $before[ $k ]['clicks'],
					'position' => $before[ $k ]['position'],
				) : null,
			);
		}
		usort( $rows, static fn( $a, $b ) => array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] ) );

		return array(
			'dim'      => $dim,
			'from'     => $from,
			'to'       => $to,
			'rows'     => $rows,
			'complete' => $now['complete'] && ( is_wp_error( $then ) || $then['complete'] ),
		);
	}

	/**
	 * Check one page now (it must be on this site).
	 *
	 * @param string $url Address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function check( string $url ) {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bare = preg_replace( '/^www\./', '', $host );
		$it   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! in_array( $it, array( $bare, 'www.' . $bare ), true ) ) {
			return new \WP_Error( 'zinn_seo_not_this_site', __( 'Only pages of this site can be checked here.', 'zinn-seo' ), array( 'status' => 422 ) );
		}

		return Inspect::page( esc_url_raw( $url ) );
	}

	/**
	 * Search Console's sitemaps, and what this plugin last sent where.
	 *
	 * @return array<string, mixed>
	 */
	public static function sitemaps(): array {
		$known = Google::has( 'search_console' ) ? Google::call( 'GET', '/sitemaps' ) : array();

		return array(
			'sitemap'   => Sitemap_Submit::sitemap(),
			'submitted' => (array) get_option( Sitemap_Submit::OPTION, array() ),
			'google'    => is_wp_error( $known ) ? array() : (array) ( $known['sitemaps'] ?? array() ),
			'error'     => is_wp_error( $known ) ? $known->get_error_message() : '',
		);
	}
}
