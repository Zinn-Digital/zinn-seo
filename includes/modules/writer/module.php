<?php
/**
 * Module: the AI writer — post ideas that fit this site, a starter draft or a full article from a
 * brief, and a passage rewritten or expanded; every answer a draft a person reviews, on the owner's
 * own AI key or Zinn® credits (zs-700; the planner and drafting in bulk are Pro).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'writer',
	'name'        => static fn() => __( 'AI writer', 'zinn-seo' ),
	'description' => static fn() => __( 'Get post ideas that fit your site, turn one into a draft or a full article from a brief, and rewrite or expand any passage in the editor. Everything arrives as a draft you review; nothing is published by itself.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'ai',
	'requires'    => array( 'ai' ),
	'features'    => array( 'zs-700' ),
	'files'       => array( 'class-writer.php' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		$register = static function (): void {
			\ZinnDigital\ZinnSEO\Writer\Writer::register_tasks();
		};
		if ( did_action( 'zinn_seo_ai_tasks' ) ) {
			$register();
		} else {
			add_action( 'zinn_seo_ai_tasks', $register );
		}
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_editor_data',
				static function ( $data ): array {
					$data           = (array) $data;
					$data['writer'] = true;
					return $data;
				}
			);
		}
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( \ZinnDigital\ZinnSEO\Writer\Writer::class, 'routes' ) );
		}
	},
);
