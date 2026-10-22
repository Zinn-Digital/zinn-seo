<?php
/**
 * Module: AI agents (MCP) — Claude, ChatGPT, Cursor and other AI assistants work on the site's SEO
 * through the site's own MCP server, with every write checked, logged and, if the owner wants,
 * held for approval (zs-210…215, zs-inn-03, zs-inn-18).
 *
 * Off by default (zs-210). The server itself boots from class-mcp.php before `plugins_loaded`
 * (the MCP adapter loads at priority 5, before modules boot at 20); this file is the switch and
 * the description the Features screen shows.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'mcp',
	'name'        => static fn() => __( 'AI agents (MCP)', 'zinn-seo' ),
	'description' => static fn() => __( 'Let Claude, ChatGPT, Cursor or another AI assistant check and improve your SEO for you. Every change it makes is logged, can wait for your approval, and can be tried first without changing anything.', 'zinn-seo' ),
	'default'     => false,
	'tier'        => 'free',
	'area'        => 'tools',
	'features'    => array( 'zs-210', 'zs-211', 'zs-213', 'zs-214', 'zs-215' ),
	'settings'    => require __DIR__ . '/settings.php',
	'contexts'    => array(),
	// Its classes stay loadable while it is off: the AI agents tab (switch, approvals, log) is
	// served by bootstrap.php's Mcp class whether or not the switch is on.
	'shared'      => true,
);
