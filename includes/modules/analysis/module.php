<?php
/**
 * Module: the content analysis — SEO, accessibility and answer-readiness checks of the RENDERED
 * page in every editor, focus phrases, cornerstone content, scores in the post lists and the
 * Content screen (zs-001, zs-002, zs-004, zs-006, zs-007, zs-010, zs-011, zs-073, zs-inn-06).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'analysis',
	'name'        => static fn() => __( 'Content analysis', 'zinn-seo' ),
	'description' => static fn() => __( 'Checks each page as visitors see it — blocks, shortcodes and page builders rendered — for its focus phrases, length, links, images, accessibility and how quotable it is, while you write, in every editor. Scores in the post lists.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'requires'    => array( 'templates' ),
	'features'    => array( 'zs-001', 'zs-002', 'zs-004', 'zs-006', 'zs-007', 'zs-010', 'zs-011', 'zs-inn-06' ),
	'meta'        => array(
		'focus'       => array(
			'type'        => 'text',
			'objects'     => array( 'post' ),
			'max'         => 4000,
			'description' => static fn() => __( 'Focus phrases, one per line; synonyms after a | separated by commas.', 'zinn-seo' ),
		),
		'related'     => array(
			'type'        => 'text',
			'objects'     => array( 'post' ),
			'max'         => 1000,
			'description' => static fn() => __( 'Related phrases the text should mention, separated by commas.', 'zinn-seo' ),
		),
		'cornerstone' => array(
			'type'        => 'bool',
			'objects'     => array( 'post' ),
			'description' => static fn() => __( 'Cornerstone content: the most important page on its topic, checked more strictly and suggested first as a link target.', 'zinn-seo' ),
		),
	),
	'files'       => array( 'class-loader.php' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Analysis\Loader::classes();
		require_once __DIR__ . '/class-render.php';
		require_once __DIR__ . '/class-analysis.php';
		\ZinnDigital\ZinnSEO\Analysis\Analysis::boot( $context );
		if ( in_array( $context, array( 'admin', 'rest', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-analysis-rest.php';
			\ZinnDigital\ZinnSEO\Analysis\Analysis_Rest::init();
		}
		if ( 'admin' === $context ) {
			require_once __DIR__ . '/class-analysis-editor.php';
			\ZinnDigital\ZinnSEO\Analysis\Analysis_Editor::boot();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-analysis-cli.php';
			\WP_CLI::add_command( 'zinn-seo analysis', \ZinnDigital\ZinnSEO\Analysis\Analysis_Cli::class );
		}
	},
);
