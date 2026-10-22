<?php
/**
 * REST: the sitemap's health and a rebuild (zs-051).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sitemaps` (the health screen, agents), `POST /sitemaps/rebuild`, `GET /sitemaps/files`.
 */
final class Sitemaps_Rest {

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
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage();
		$manage = static fn(): bool => Capabilities::can_manage();
		register_rest_route(
			Plugin::REST_NS,
			'/sitemaps',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/sitemaps/files',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'files' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/sitemaps/rebuild',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rebuild' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * GET /sitemaps.
	 *
	 * @return \WP_REST_Response
	 */
	public static function status(): \WP_REST_Response {
		Sitemaps::ensure_built();
		// A build whose chain of steps broke (a host that blocks requests to itself, no cron)
		// is started again by the person watching it, instead of waiting for the watchdog.
		$job = Jobs::get( Builder::JOB );
		// Pending, no step running (no lock) and nothing done for ten seconds: the chain broke.
		if ( 'pending' === (string) $job['status'] && 0 === (int) $job['lock'] && (int) $job['wait'] <= time() && (int) $job['updated'] < time() - 10 ) {
			Jobs::kick( Builder::JOB );
		}

		return rest_ensure_response( self::describe() );
	}

	/**
	 * Everything the health screen shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$m      = Store::manifest();
		$served = Store::served( $m );
		$units  = array();
		foreach ( $served['files'] as $f ) {
			$key = (string) $f['unit'] . '|' . (string) $f['lang'];
			if ( ! isset( $units[ $key ] ) ) {
				$units[ $key ] = array(
					'unit'    => (string) $f['unit'],
					'label'   => self::unit_label( (string) $f['unit'] ),
					'lang'    => (string) $f['lang'],
					'files'   => 0,
					'urls'    => 0,
					'images'  => 0,
					'videos'  => 0,
					'bytes'   => 0,
					'lastmod' => 0,
				);
			}
			++$units[ $key ]['files'];
			$units[ $key ]['urls']   += (int) $f['count'];
			$units[ $key ]['images'] += (int) $f['images'];
			$units[ $key ]['videos'] += (int) $f['videos'];
			$units[ $key ]['bytes']  += (int) $f['bytes'];
			$units[ $key ]['lastmod'] = max( $units[ $key ]['lastmod'], (int) $f['lastmod'] );
		}
		$job = Jobs::get( Builder::JOB );

		return array(
			'active'    => Sitemaps::active(),
			'url'       => Sitemaps::url(),
			'complete'  => ! empty( $m['complete'] ),
			'built'     => (int) $m['built'],
			'refreshed' => (int) $m['refreshed'],
			'took'      => (float) $m['took'],
			'peak'      => (int) $m['peak'],
			'files'     => count( $served['files'] ),
			'urls'      => array_sum( array_column( $units, 'urls' ) ),
			'units'     => array_values( $units ),
			'errors'    => (array) $m['errors'],
			'pending'   => Store::waiting(),
			'job'       => array(
				'status'   => (string) $job['status'],
				'progress' => Builder::progress( $job ),
				'updated'  => (int) $job['updated'],
				'error'    => (string) $job['error'],
				'running'  => Jobs::running( $job ),
				'wait'     => max( 0, (int) $job['wait'] - time() ),
			),
			'choices'   => self::choices(),
			'languages' => Builder::languages_on(),
			'woo'       => post_type_exists( 'product' ),
			'xsl'       => Sitemaps::stylesheet_on(),
		);
	}

	/**
	 * The content types and taxonomies the screen offers, with why one is not listed.
	 *
	 * @return array{types: array<int, array<string, mixed>>, taxonomies: array<int, array<string, mixed>>}
	 */
	public static function choices(): array {
		$excluded = (array) Settings::get( 'sitemap_exclude_types', array() );
		$types    = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$why = '';
			if ( 'attachment' === $type->name && ! Templates::attachment_pages() ) {
				$why = 'no_pages';
			} elseif ( 'noindex' === (string) Templates::effective( 'post:' . $type->name )['index'] ) {
				$why = 'noindex';
			}
			$types[] = array(
				'name'     => $type->name,
				'label'    => (string) $type->labels->name,
				'included' => ! in_array( $type->name, $excluded, true ) && '' === $why,
				'excluded' => in_array( $type->name, $excluded, true ),
				'why'      => $why,
			);
		}
		$excluded = (array) Settings::get( 'sitemap_exclude_taxonomies', array() );
		$taxes    = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			$why     = 'noindex' === (string) Templates::effective( 'tax:' . $tax->name )['index'] ? 'noindex' : '';
			$taxes[] = array(
				'name'     => $tax->name,
				'label'    => (string) $tax->labels->name,
				'included' => ! in_array( $tax->name, $excluded, true ) && '' === $why,
				'excluded' => in_array( $tax->name, $excluded, true ),
				'why'      => $why,
			);
		}

		return array(
			'types'      => $types,
			'taxonomies' => $taxes,
		);
	}

	/**
	 * A unit's name for people.
	 *
	 * @param string $unit Unit.
	 * @return string
	 */
	public static function unit_label( string $unit ): string {
		if ( 'home' === $unit ) {
			return __( 'Home and archive pages', 'zinn-seo' );
		}
		if ( 'authors' === $unit ) {
			return __( 'Authors', 'zinn-seo' );
		}
		if ( 0 === strpos( $unit, 'post:' ) ) {
			$type = get_post_type_object( substr( $unit, 5 ) );
			return $type instanceof \WP_Post_Type ? (string) $type->labels->name : substr( $unit, 5 );
		}
		$tax = get_taxonomy( substr( $unit, 4 ) );

		return $tax instanceof \WP_Taxonomy ? (string) $tax->labels->name : substr( $unit, 4 );
	}

	/**
	 * GET /sitemaps/files.
	 *
	 * @return \WP_REST_Response
	 */
	public static function files(): \WP_REST_Response {
		$out = array();
		foreach ( Store::served()['files'] as $name => $f ) {
			$out[] = array(
				'name'    => (string) $name,
				'url'     => Sitemaps::url( (string) $name ),
				'unit'    => (string) $f['unit'],
				'lang'    => (string) $f['lang'],
				'urls'    => (int) $f['count'],
				'images'  => (int) $f['images'],
				'videos'  => (int) $f['videos'],
				'bytes'   => (int) $f['bytes'],
				'lastmod' => (int) $f['lastmod'],
			);
		}

		return rest_ensure_response( $out );
	}

	/**
	 * POST /sitemaps/rebuild.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rebuild(): \WP_REST_Response {
		Builder::start( true );

		return rest_ensure_response( self::describe() );
	}
}
