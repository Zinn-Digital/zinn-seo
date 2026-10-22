<?php
/**
 * Spelling, grammar and tone: settings (data only — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Who checks: a LanguageTool-compatible server, or AI (own key; Zinn® credits with Pro).
	'proof_engine'  => array(
		'type'    => 'enum',
		'default' => 'languagetool',
		'choices' => array( 'languagetool', 'ai' ),
		'group'   => 'proofread',
	),
	// The server's address (its API lives under /v2/check). Empty: none set up yet.
	'proof_lt_url'  => array(
		'type'    => 'url',
		'default' => '',
		'group'   => 'proofread',
	),
	'proof_lt_user' => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 200,
		'group'   => 'proofread',
	),
	// How the site wants to sound ('' = no tone check).
	'proof_tone'    => array(
		'type'    => 'enum',
		'default' => '',
		'choices' => array( '', 'friendly', 'formal', 'confident', 'casual', 'professional', 'enthusiastic', 'empathetic', 'neutral' ),
		'group'   => 'proofread',
	),
);
