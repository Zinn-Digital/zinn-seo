<?php
/**
 * Module: the pay-per-use checks — rank tracker, Google index checker, AI answers, backlink
 * watch, site audit and keyword research, run from WordPress on Zinn® credit (zs-181 … zs-185,
 * zs-inn-04).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'tools',
	'name'        => static fn() => __( 'Rankings and checks', 'zinn-seo' ),
	'description' => static fn() => __( 'Track where you rank, see which pages Google has indexed, find out whether ChatGPT and other AI answers mention you, watch your backlinks and audit the whole site — without leaving WordPress, paying only for what you run.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'requires'    => array( 'connect', 'credits' ),
	'features'    => array( 'zs-181', 'zs-182', 'zs-183', 'zs-184', 'zs-185', 'zs-inn-04' ),
	'contexts'    => array( 'admin', 'rest', 'cli', 'cron' ),
	'boot'        => static function ( string $context ): void {
		require_once __DIR__ . '/class-tools.php';
		require_once __DIR__ . '/class-index.php';
		\ZinnDigital\ZinnSEO\Tools\Index::register();
		// Credit is back: a whole-site check that paused for it carries on by itself.
		add_action( 'zinn_seo_credit_resumed', array( \ZinnDigital\ZinnSEO\Tools\Index::class, 'resume' ) );
		add_action(
			'zinn_seo_credit_state',
			static function ( $state, $before ): void {
				if ( in_array( $before, array( 'held', 'empty' ), true ) && ! in_array( $state, array( 'held', 'empty' ), true ) ) {
					\ZinnDigital\ZinnSEO\Tools\Index::resume();
				}
			},
			10,
			2
		);
		add_action( 'zinn_seo_disconnected', static fn() => delete_option( \ZinnDigital\ZinnSEO\Tools\Tools::OPTION ) );
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_admin_data',
				static function ( $data ) {
					$data          = (array) $data;
					$data['tools'] = array(
						'markets' => \ZinnDigital\ZinnSEO\Tools\Tools::markets(),
						'market'  => \ZinnDigital\ZinnSEO\Tools\Tools::market_for( (string) get_locale() ),
					);
					return $data;
				}
			);
		}
		if ( in_array( $context, array( 'rest', 'admin' ), true ) ) {
			require_once __DIR__ . '/class-tools-rest.php';
			\ZinnDigital\ZinnSEO\Tools\Tools_Rest::init();
		}
		if ( 'cli' === $context && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/class-tools-cli.php';
			\WP_CLI::add_command( 'zinn-seo tools', array( \ZinnDigital\ZinnSEO\Tools\Tools_Cli::class, 'run' ), array( 'shortdesc' => 'The pay-per-use checks: status | schedule <tool> on|off | list <rank|ai|links> <file.json> | run <tool> [--seed=<phrase>] [--page=<url>] | results <tool> | index <url>… | index-site start|pause|resume|cancel|status.' ) );
		}
	},
);
