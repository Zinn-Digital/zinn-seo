<?php
/**
 * Module: AI — on the site owner's own key or on Zinn® credits; every answer a suggestion a
 * person accepts (zs-190, zs-199, zs-200, zs-201, zs-433, zs-543, zs-545; credits zs-197).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'ai',
	'name'        => static fn() => __( 'AI writing help', 'zinn-seo' ),
	'description' => static fn() => __( 'Let AI write SEO titles, descriptions, image alt text and structured data, and explain any issue in plain words. Use your own OpenAI, Anthropic, Google Gemini or other key, or pay as you go with Zinn® credits. Nothing changes until you accept it.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'ai',
	'features'    => array( 'zs-190', 'zs-197', 'zs-199', 'zs-200', 'zs-201', 'zs-433', 'zs-543', 'zs-545' ),
	// Never on a visitor's page: AI runs only where a person asks for it.
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'settings'    => array(
		'ai_funding' => array(
			'type'    => 'enum',
			'default' => 'auto',
			'choices' => array( 'auto', 'byo', 'credits' ),
			'group'   => 'ai',
		),
		// The model each AI feature uses on the owner's own key, `provider:model` ('' = the
		// site's default for that kind of work). Read through Ai\Model_Picker, which offers only
		// the current models of the signed catalogue (owner, 2026-10-13).
		'ai_models'  => array(
			'type'    => 'dict',
			'default' => array(),
			'group'   => 'ai',
			'fields'  => array(
				'choice'     => array(
					'type' => 'string',
					'max'  => 200,
				),
				// AIM1: the model a retired saved choice was moved from/to, shown until the
				// owner chooses again (Model_Picker::follow()).
				'moved_from' => array(
					'type' => 'string',
					'max'  => 200,
				),
				'moved_to'   => array(
					'type' => 'string',
					'max'  => 200,
				),
			),
		),
	),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-door.php';
		require_once __DIR__ . '/class-tasks.php';
		require_once __DIR__ . '/class-bulk-context.php';
		require_once __DIR__ . '/class-bulk-query.php';
		require_once __DIR__ . '/class-suggestions.php';
		require_once __DIR__ . '/class-usage.php';
		require_once __DIR__ . '/class-model-picker.php';
		require_once __DIR__ . '/class-ai.php';
		require_once __DIR__ . '/class-free-tasks.php';
		\ZinnDigital\ZinnSEO\Ai\Free_Tasks::register();
		/**
		 * Fires when the AI tasks are registered: the Pro module adds its own here.
		 */
		do_action( 'zinn_seo_ai_tasks' );
		// Stage P3's screens and blocks ask whether AI is set up (own key or Zinn® credits).
		add_filter( 'zinn_seo_ai_available', static fn( $on ): bool => (bool) $on || \ZinnDigital\ZinnSEO\Ai\Ai::ready() );
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-ai-rest.php';
			\ZinnDigital\ZinnSEO\Ai\Ai_Rest::init();
		}
		if ( 'admin' === $context ) {
			$data = static function ( $data ) {
				$data       = (array) $data;
				$data['aiState'] = current_user_can( \ZinnDigital\ZinnSEO\Capabilities::AI ) ? \ZinnDigital\ZinnSEO\Ai\Ai_Rest::state() : null;
				return $data;
			};
			add_filter( 'zinn_seo_admin_data', $data );
			add_filter( 'zinn_seo_editor_data', $data );
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-ai-cli.php';
			\WP_CLI::add_command( 'zinn-seo ai', \ZinnDigital\ZinnSEO\Ai\Ai_Cli::class );
		}
	},
);
