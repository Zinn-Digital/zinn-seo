<?php
/**
 * Module: readability in every language WordPress ships, and the inclusive-language check
 * (zs-003, zs-432).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'readability',
	'name'        => static fn() => __( 'Readability', 'zinn-seo' ),
	'description' => static fn() => __( 'Sentence and paragraph length, subheadings, transition words, passive voice, sentence variety, reading ease and inclusive language, in the language each page is written in.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'content',
	'requires'    => array( 'analysis' ),
	'features'    => array( 'zs-003', 'zs-432' ),
	// Loaded by the analysis (Analysis\Loader) when it runs; nothing of its own to hook.
	'contexts'    => array(),
);
