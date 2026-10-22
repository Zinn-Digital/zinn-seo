<?php
/**
 * The clean-up module's settings (data only: read whether the module is on or off, so a switched-off
 * module keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zinn_seo_bool = static fn( bool $on ): array => array(
	'type'    => 'bool',
	'default' => $on,
	'group'   => 'cleanup',
);

return array(
	// zs-701: what WordPress prints in every page head and which feeds exist.
	'clean_shortlink'     => $zinn_seo_bool( false ),
	'clean_rsd'           => $zinn_seo_bool( false ),
	'clean_wlw'           => $zinn_seo_bool( false ),
	'clean_rest_links'    => $zinn_seo_bool( false ),
	'clean_oembed_links'  => $zinn_seo_bool( false ),
	'clean_generator'     => $zinn_seo_bool( false ),
	'clean_feed_comments' => $zinn_seo_bool( false ),
	'clean_feed_author'   => $zinn_seo_bool( false ),
	'clean_feed_search'   => $zinn_seo_bool( false ),
	'clean_feed_terms'    => $zinn_seo_bool( false ),
	'clean_feed_types'    => $zinn_seo_bool( false ),
	'clean_params'        => $zinn_seo_bool( false ),
	'clean_params_allow'  => array(
		'type'    => 'text',
		'default' => '',
		'max'     => 2000,
		'group'   => 'cleanup',
	),
	'clean_search_spam'   => $zinn_seo_bool( false ),
	'clean_search_robots' => $zinn_seo_bool( false ),
	'clean_search_max'    => array(
		'type'    => 'int',
		'default' => 100,
		'min'     => 10,
		'max'     => 500,
		'group'   => 'cleanup',
	),
	// zs-714: addresses and titles.
	'url_category_base'   => $zinn_seo_bool( false ),
	'url_product_base'    => $zinn_seo_bool( false ),
	'url_attachment'      => array(
		'type'    => 'enum',
		'default' => '',
		'choices' => array( '', 'parent', 'file' ),
		'group'   => 'cleanup',
	),
	// Archives switched off: sent to the home page (301). `author` every author archive,
	// `author_single` an author archive on a one-author site or with no posts (Slim SEO),
	// `date` every date archive (Yoast SEO, SureRank; kept after a move — queue Q254).
	'archives_off'        => array(
		'type'    => 'list',
		'default' => array(),
		'choices' => array( 'author', 'author_single', 'date' ),
		'group'   => 'cleanup',
	),
	'url_host'            => $zinn_seo_bool( false ),
	'url_slash'           => $zinn_seo_bool( false ),
	'url_replytocom'      => $zinn_seo_bool( false ),
	'title_case'          => array(
		'type'    => 'dict',
		'default' => array(),
		'group'   => 'cleanup',
		'fields'  => array(
			'style' => array(
				'type'    => 'enum',
				'choices' => array( '', 'title', 'first' ),
			),
		),
	),
	// zs-727: link previews (oEmbed) in Discord, Slack, LinkedIn and Mastodon.
	'oembed_title'        => $zinn_seo_bool( false ),
	'oembed_author'       => array(
		'type'    => 'enum',
		'default' => '',
		'choices' => array( '', 'site', 'hide' ),
		'group'   => 'cleanup',
	),
	'oembed_image'        => $zinn_seo_bool( false ),
);
