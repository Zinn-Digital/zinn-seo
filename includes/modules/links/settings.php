<?php
/**
 * The links module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'links_external_rel'     => array(
		'type'    => 'enum',
		'default' => '',
		'choices' => array( '', 'nofollow', 'sponsored' ),
		'group'   => 'links',
	),
	'links_external_newtab'  => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'links',
	),
	'links_rules'            => array(
		'type'    => 'text',
		'default' => '',
		'max'     => 3000,
		'group'   => 'links',
	),
	// Breadcrumbs, site-wide (the trail visitors see and the one search engines read). Empty:
	// the plugin's own wording. A move from Yoast SEO, All in One SEO, SmartCrawl… brings the
	// site's wording over (importer certification, queue Q254). `%s` stands for the name, the
	// search or the page number.
	// The plugins whose breadcrumb template functions, shortcodes and blocks print this trail
	// after a move from them (Pro): set by the import.
	'crumb_compat'           => array(
		'type'    => 'list',
		'default' => array(),
		'choices' => array( 'yoast', 'aioseo', 'seopress', 'rankmath', 'smartcrawl', 'slimseo', 'tsf' ),
		'group'   => 'links',
	),
	'crumb_home'             => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 60,
		'group'   => 'links',
	),
	'crumb_sep'              => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 10,
		'group'   => 'links',
	),
	'crumb_prefix'           => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 100,
		'group'   => 'links',
	),
	'crumb_archive'          => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 100,
		'group'   => 'links',
	),
	'crumb_term'             => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 100,
		'group'   => 'links',
	),
	'crumb_search'           => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 100,
		'group'   => 'links',
	),
	'crumb_404'              => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 100,
		'group'   => 'links',
	),
	// A date archive's trail: '' its archive title, `tree` year › month › day (March, 01),
	// `tree-numbers` the same in numbers (03, 01), `tree-archive` each step in the archive
	// wording (Rank Math: Archives for 2026 › Archives for March).
	'crumb_date'             => array(
		'type'    => 'enum',
		'default' => '',
		'choices' => array( '', 'tree', 'tree-numbers', 'tree-archive' ),
		'group'   => 'links',
	),
	// Whether a term's trail shows its parent terms (Home › News › Local); Rank Math's
	// "Show category(s) ancestors" off: Home › Local.
	'crumb_ancestors'        => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'links',
	),
	// Taxonomies whose name is a step of its own before their terms (Rank Math: Home › Genres ›
	// Mystery).
	'crumb_tax_name'         => array(
		'type'    => 'list',
		'default' => array(),
		'group'   => 'links',
	),
	// The taxonomies whose terms' trails run through their content type's archive
	// (Home › Books › Mystery; All in One SEO: Home › Shop › Mugs).
	'crumb_tax_archive'      => array(
		'type'    => 'list',
		'default' => array(),
		'group'   => 'links',
	),
	// A content type's archive page names itself with this wording (`%s` the type's name;
	// All in One SEO: "Archives for %s").
	'crumb_ptarchive'        => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 100,
		'group'   => 'links',
	),
	// Whether an item's trail shows its content type's archive (Home › Books › The River Book).
	'crumb_singular_archive' => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'links',
	),
	// Taxonomies whose term archives keep their plain name, whatever crumb_term says.
	'crumb_term_plain'       => array(
		'type'    => 'list',
		'default' => array(),
		'group'   => 'links',
	),
	'crumb_paged'            => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 60,
		'group'   => 'links',
	),
	// Per content type: the name of its archive in a trail, and which taxonomy its items' trail
	// runs through (`none` for no terms at all, as Yoast SEO shows products: Home › Shop › Mug).
	'crumb_types'            => array(
		'type'    => 'dict',
		'default' => array(),
		'group'   => 'links',
		'fields'  => array(
			'label'   => array(
				'type' => 'string',
				'max'  => 60,
			),
			'tax'     => array(
				'type'    => 'string',
				'max'     => 64,
				'pattern' => '/^[a-z0-9_-]*$/',
			),
			// `show` or `hide` the type's archive (the shop) in its items' trail; empty: the
			// site-wide crumb_singular_archive (zs-758).
			'archive' => array(
				'type'    => 'enum',
				'choices' => array( '', 'show', 'hide' ),
			),
		),
	),
);
