<?php
/**
 * Tranzly, our translation plugin (zs-130, zs-131, zs-134, zs-231, zs-inn-17).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

use ZinnDigital\ZinnSEO\Integrations\Family\Family;
use ZinnDigital\ZinnSEO\Integrations\Family\Tranzly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Who does what, so nothing is printed twice and nothing is missed:
 *
 * - Tranzly owns language: each language's address, hreflang with x-default, `html lang`.
 * - This plugin owns the rest per language: titles, descriptions, Open Graph, structured data,
 *   the sitemap (a file per language, each page naming its translations) and llms.txt (a file per
 *   language). Tranzly's own Open Graph tag and sitemap list stand down while this plugin prints
 *   them, and it adds the page's other languages to the Open Graph tags printed here.
 * - Tranzly asks `zinn_seo_translation_fields()` which SEO fields hold words and translates them
 *   WITH the page; it asks `zinn_seo_translatable_texts()` for the site-wide words (title
 *   patterns, the llms.txt summary) and answers with each language's on `zinn_seo_template` and
 *   `zinn_seo_site_text`.
 *
 * What is left for this side: languages on their own domain belong in the sitemap, the llms.txt
 * files follow a changed translation, and the Fix-it inbox says which translated pages still
 * lack the search title or description their original has.
 */
require_once dirname( __DIR__ ) . '/class-family.php';
require_once __DIR__ . '/class-tranzly.php';

return static function ( string $context, bool $again = false ): void {
	if ( $again || Family::off( 'tranzly' ) ) {
		return;
	}
	Tranzly::boot( $context );
};
