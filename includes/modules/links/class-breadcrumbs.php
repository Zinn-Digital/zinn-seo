<?php
/**
 * Breadcrumbs for themes: a template function and a shortcode (zs-105).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

use ZinnDigital\ZinnSEO\Blocks\Library;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;
use ZinnDigital\ZinnSEO\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The same trail as the Breadcrumbs block (stage P3) and the page's BreadcrumbList, so what the
 * visitor sees and what search engines read cannot differ; in each language with Tranzly (the
 * trail is built from the translated page). For themes: `zinn_seo_breadcrumbs()` in a template,
 * or `[zinn_seo_breadcrumbs]` anywhere shortcodes run. Arguments: `home` (first item's text),
 * `separator` (› / » ·), `show_last` (show the current page), `show_home` (`0` leaves the Home
 * step out) and `max` (the most steps shown, at least 3: a longer trail folds its middle into
 * "…" — zs-759).
 */
final class Breadcrumbs {

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_shortcode( 'zinn_seo_breadcrumbs', array( self::class, 'shortcode' ) );
		if ( is_admin() ) {
			add_filter( 'zinn_seo_admin_data', array( self::class, 'admin_data' ) );
		}
	}

	/**
	 * The content types the breadcrumb settings offer, each with the taxonomies its trail can run
	 * through and whether it has an archive (zs-758).
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data  = (array) $data;
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$taxes = array();
			foreach ( get_object_taxonomies( $type->name, 'objects' ) as $tax ) {
				if ( $tax->public && 'post_format' !== $tax->name ) {
					$taxes[] = array(
						'name'  => (string) $tax->name,
						'label' => (string) $tax->labels->name,
					);
				}
			}
			$types[] = array(
				'name'         => (string) $type->name,
				'label'        => (string) $type->labels->name,
				'archive'      => (bool) $type->has_archive && ! in_array( $type->name, array( 'post', 'page' ), true ),
				'hierarchical' => (bool) $type->hierarchical,
				'taxonomies'   => $taxes,
			);
		}
		$data['crumbTypes'] = $types;

		return $data;
	}

	/**
	 * The trail's HTML ('' when the blocks module is off or there is no trail).
	 *
	 * @param array<string, mixed> $args home, separator, show_last, show_home, max.
	 * @return string
	 */
	public static function html( array $args = array() ): string {
		if ( ! Modules::enabled( 'blocks' ) || ! class_exists( Library::class ) ) {
			return '';
		}
		$values = array();
		foreach ( array( 'home', 'separator' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$values[ $key ] = (string) $args[ $key ];
			}
		}
		foreach ( array( 'show_last', 'show_home' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$values[ $key ] = ! in_array( strtolower( (string) $args[ $key ] ), array( '0', 'false', 'no', '' ), true );
			}
		}
		if ( isset( $args['max'] ) && is_scalar( $args['max'] ) && is_numeric( $args['max'] ) ) {
			$values['max'] = (int) $args['max'];
		}
		$object = get_queried_object();
		$ctx    = Render_Context::for_post( $object instanceof \WP_Post ? $object : null, 'theme' );

		return Library::render( 'breadcrumbs', $values, $ctx );
	}

	/**
	 * `[zinn_seo_breadcrumbs]`.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		return self::html( is_array( $atts ) ? $atts : array() );
	}
}
