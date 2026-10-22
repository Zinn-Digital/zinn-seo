<?php
/**
 * Module: the headline analyser — a click-through score for a title, per language, with what to
 * change and, when AI is set up, rewrites to choose from (zs-723).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'headline',
	'name'        => static fn() => __( 'Headline analyser', 'zinn-seo' ),
	'description' => static fn() => __( 'Score any title for how likely people are to click it: its length, the balance of common, emotional and power words, numbers and its form, in your language. See what to change, and get rewrites to choose from when AI is set up.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'features'    => array( 'zs-723' ),
	'files'       => array( 'class-headline.php' ),
	// Never on a visitor's page: a person asks for the score while writing.
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		$register = static function (): void {
			\ZinnDigital\ZinnSEO\Headline\Headline::register_task();
		};
		// The AI module fires this once it has registered its own tasks; when it already has, now.
		if ( did_action( 'zinn_seo_ai_tasks' ) ) {
			$register();
		} else {
			add_action( 'zinn_seo_ai_tasks', $register );
		}
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-headline-rest.php';
			\ZinnDigital\ZinnSEO\Headline\Headline_Rest::init();
		}
		if ( 'admin' === $context ) {
			$data = static function ( $data ): array {
				$data             = (array) $data;
				$data['headline'] = array( 'language' => \ZinnDigital\ZinnSEO\Headline\Headline::site_language() );
				return $data;
			};
			add_filter( 'zinn_seo_admin_data', $data );
			add_filter( 'zinn_seo_editor_data', $data );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'zinn-seo headline',
				static function ( array $args, array $assoc ): void {
					$result = \ZinnDigital\ZinnSEO\Headline\Headline::analyse( (string) ( $args[0] ?? '' ), (string) ( $assoc['lang'] ?? '' ) );
					\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
				}
			);
		}
	},
);
