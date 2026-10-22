<?php
/**
 * Module: spelling, grammar and tone of voice in the content analysis, in every language, on the
 * site's own LanguageTool server (or any LanguageTool-compatible one) or the owner's own AI key;
 * on Zinn® credits with Pro (zs-721).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'analysis-extra',
	'name'        => static fn() => __( 'Spelling, grammar and tone', 'zinn-seo' ),
	'description' => static fn() => __( 'Check spelling and grammar in any language before you publish, and whether the text sounds the way you want it to — friendly, formal, confident. Use a LanguageTool server or your own AI key; Pro can use Zinn® credits.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'features'    => array( 'zs-721' ),
	'settings'    => require __DIR__ . '/settings.php',
	'files'       => array( 'class-proofread.php' ),
	// Only where a person asks for it.
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		$register = static function (): void {
			\ZinnDigital\ZinnSEO\AnalysisExtra\Proofread::register_tasks();
		};
		if ( did_action( 'zinn_seo_ai_tasks' ) ) {
			$register();
		} else {
			add_action( 'zinn_seo_ai_tasks', $register );
		}
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( \ZinnDigital\ZinnSEO\AnalysisExtra\Proofread::class, 'routes' ) );
		}
		if ( 'admin' === $context ) {
			$data = static function ( $data ): array {
				$data              = (array) $data;
				$data['proofread'] = \ZinnDigital\ZinnSEO\AnalysisExtra\Proofread::state();
				return $data;
			};
			add_filter( 'zinn_seo_admin_data', $data );
			add_filter( 'zinn_seo_editor_data', $data );
		}
	},
);
