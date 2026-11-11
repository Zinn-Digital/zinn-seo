<?php
/**
 * Module: Agent Ready — one score for how ready the site is for AI agents and answer engines,
 * with the fix for every gap (zs-784).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'agent-ready',
	'name'        => static fn() => __( 'Agent Ready', 'zinn-seo' ),
	'description' => static fn() => __( 'Score how ready the site is for AI agents and answer engines (crawler access, llms.txt, sitemaps, structured data, Markdown and MCP) and fix each gap in one click.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'ai',
	'requires'    => array(),
	'features'    => array( 'zs-784' ),
	'settings'    => array(),
	'files'       => array( 'class-agent-ready.php' ),
	'contexts'    => array( 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		if ( in_array( $context, array( 'admin', 'rest' ), true ) ) {
			require_once __DIR__ . '/class-agent-ready-rest.php';
			\ZinnDigital\ZinnSEO\AgentReady\Agent_Ready_Rest::init();
		}
	},
);
