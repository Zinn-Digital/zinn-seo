<?php
/**
 * Global functions for developers and themes (zs-246). Loaded once: the main file returns before
 * bootstrap when another copy (free or premium) is already running.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Zinn® SEO API (see ZinnDigital\ZinnSEO\Api).
 *
 * @return \ZinnDigital\ZinnSEO\Api
 */
function zinn_seo(): \ZinnDigital\ZinnSEO\Api {
	return \ZinnDigital\ZinnSEO\Api::instance();
}

/**
 * A post's SEO field (shortcut for templates).
 *
 * @param string   $field   Field name.
 * @param int|null $post_id Post ID; the current post when omitted.
 * @return mixed
 */
function zinn_seo_get_field( string $field, ?int $post_id = null ) {
	$post_id = $post_id ?? (int) get_the_ID();

	return $post_id > 0 ? \ZinnDigital\ZinnSEO\Meta::get_post( $post_id, $field ) : null;
}

/**
 * The SEO fields a translation plugin translates with a post or a term, and the per-page
 * settings a new translation takes from its original unchanged (Tranzly asks this).
 *
 * @param string $kind `post` or `term`.
 * @return array{translate: array<string, string>, copy: array<int, string>} `translate`: field
 *         name => `template` (may hold `%variables%`, never to be translated), `line` or `text`
 *         (several lines); `copy`: field names. A field is stored under `_zseo_<name>`; write it
 *         with `zinn_seo()->set_post_field()` or `set_term_field()`.
 */
function zinn_seo_translation_fields( string $kind = 'post' ): array {
	require_once __DIR__ . '/integrations/family/class-family.php';

	return \ZinnDigital\ZinnSEO\Integrations\Family\Family::translation_fields( $kind );
}

/**
 * The site-wide texts that should exist in each language: title and description patterns, the
 * llms.txt summary, the description of the organisation. Answer with a translation on the
 * `zinn_seo_template` filter (patterns) and the `zinn_seo_site_text` filter (the others).
 *
 * @return array<int, string>
 */
function zinn_seo_translatable_texts(): array {
	require_once __DIR__ . '/integrations/family/class-family.php';

	return \ZinnDigital\ZinnSEO\Integrations\Family\Family::site_texts();
}

/**
 * Who runs the site, how to reach them and which pages matter, as short plain texts for an
 * assistant to answer from: the same facts the page's structured data and llms.txt give.
 *
 * @param string $language A language of the site (a WordPress locale); '' for its main language.
 * @return array<int, array{title: string, url: string, text: string}>
 */
function zinn_seo_site_facts( string $language = '' ): array {
	require_once __DIR__ . '/integrations/family/class-family.php';

	return \ZinnDigital\ZinnSEO\Integrations\Family\Family::site_facts( $language );
}
