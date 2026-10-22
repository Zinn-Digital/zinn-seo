<?php
/**
 * Zinn® Chat, our chat and help-desk plugin (zs-137).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

use ZinnDigital\ZinnSEO\Integrations\Family\Family;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The assistant in the chat window answers from passages of the site it finds for the question.
 * What it could not find that way is what no single page says well: who runs the site, the
 * phone number, the opening hours, which pages are the important ones. This plugin holds exactly
 * that, already checked, for search engines (the knowledge graph) and for AI assistants
 * (llms.txt), and hands it to Zinn® Chat with every question, in the visitor's language.
 *
 * Read only when a visitor asks something: the filter runs inside Zinn® Chat's answer, never on
 * a page view.
 */
require_once dirname( __DIR__ ) . '/class-family.php';

return static function ( string $context, bool $again = false ): void {
	unset( $context );
	if ( $again || Family::off( 'zinn-chat' ) ) {
		return;
	}
	add_filter(
		'zinn_chat_site_facts',
		static function ( $facts, $language = '' ) {
			$facts = is_array( $facts ) ? $facts : array();
			foreach ( Family::site_facts( is_string( $language ) ? $language : '' ) as $fact ) {
				$facts[] = $fact;
			}

			return $facts;
		},
		10,
		2
	);
};
