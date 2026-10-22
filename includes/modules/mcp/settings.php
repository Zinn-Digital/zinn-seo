<?php
/**
 * The AI agents' settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// What happens when an AI agent changes something (zs-214): `apply` straight away (logged and
	// undoable in the change history), `approve` waits for a person on the AI agents tab, `off`
	// lets agents read and plan but never change anything.
	'mcp_writes' => array(
		'type'    => 'enum',
		'default' => 'apply',
		'choices' => array( 'apply', 'approve', 'off' ),
		'group'   => 'mcp',
	),
);
