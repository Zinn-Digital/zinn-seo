<?php
/**
 * The smart robots defaults' settings (data only, read whether the module is on or off — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zinn_seo_rule = array(
	'type'    => 'bool',
	'default' => true,
	'group'   => 'robots_defaults',
);

return array(
	'robots_def_password'      => $zinn_seo_rule,
	'robots_def_empty_terms'   => $zinn_seo_rule,
	'robots_def_comment_pages' => $zinn_seo_rule,
	'robots_def_feeds'         => $zinn_seo_rule,
	'robots_def_paging'        => $zinn_seo_rule,
);
