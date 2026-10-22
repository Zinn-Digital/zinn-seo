<?php
/**
 * REST: the block library as the screens see it.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET zinn-seo/v1/blocks`: every block with its fields, edition and where it is offered — read by
 * the Blocks screen and the MCP abilities. Switching blocks on and off, and the article index's
 * post types, are ordinary settings (`blocks_off`, `article_index_types`).
 */
final class Blocks_Rest {

	/**
	 * Hook the routes.
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
		register_rest_route(
			'zinn-seo/v1',
			'/blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'list' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::MANAGE ) || current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * The list.
	 *
	 * @return \WP_REST_Response
	 */
	public static function list(): \WP_REST_Response {
		return new \WP_REST_Response( self::describe() );
	}

	/**
	 * Every block, described (also the editors' boot data).
	 *
	 * @param bool $with_off Include the blocks the site switched off.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe( bool $with_off = true ): array {
		$off = (array) Settings::get( 'blocks_off', array() );
		if ( $with_off ) {
			remove_filter( 'zinn_seo_blocks', array( Blocks::class, 'drop_switched_off' ), 99 );
			Library::flush( true );
		}
		$out = array();
		foreach ( Library::all() as $block ) {
			$out[] = array(
				'name'        => $block->block_name(),
				'short'       => $block->name(),
				'title'       => $block->title(),
				'description' => $block->description(),
				'icon'        => $block->icon(),
				'keywords'    => $block->keywords(),
				'tier'        => $block->tier(),
				'offered'     => $block->offered(),
				'on'          => ! in_array( $block->name(), $off, true ),
				'fields'      => Fields::describe( $block->fields() ),
				'example'     => $block->example(),
			);
		}
		if ( $with_off ) {
			add_filter( 'zinn_seo_blocks', array( Blocks::class, 'drop_switched_off' ), 99 );
			Library::flush( true );
		}

		return $out;
	}
}
