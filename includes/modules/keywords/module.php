<?php
/**
 * Module: keyword lists ("briefcase") — saved sets of phrases with a label and a colour,
 * assigned to posts, and reused by rank tracking, content briefs and the topical map (zs-731).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'keywords',
	'name'        => static fn() => __( 'Keyword lists', 'zinn-seo' ),
	'description' => static fn() => __( 'Keep the phrases you want to rank for in labelled lists, give each post its lists, and send a list to rank tracking, a content brief or the topical map in one click.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'features'    => array( 'zs-731' ),
	'files'       => array( 'class-keyword-lists.php' ),
	// Nothing on a visitor's page.
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Keywords\Keyword_Lists::boot();
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_editor_data',
				static function ( $data ): array {
					$data            = (array) $data;
					$data['kwlists'] = true;
					return $data;
				}
			);
		}
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-keyword-lists-rest.php';
			\ZinnDigital\ZinnSEO\Keywords\Keyword_Lists_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo keyword-lists',
				static function (): void {
					foreach ( \ZinnDigital\ZinnSEO\Keywords\Keyword_Lists::page( 0, 500 )['items'] as $list ) {
						\WP_CLI::line( sprintf( '%d	%s	%d phrases	%d posts', $list['id'], $list['label'], count( $list['phrases'] ), $list['posts'] ) );
					}
				}
			);
		}
	},
);
