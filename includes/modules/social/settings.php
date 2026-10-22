<?php
/**
 * The social module's settings (data only: read whether the module is on or off, so switching it
 * off loads none of its code and keeps its choices — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'og'                   => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'social',
	),
	'x_cards'              => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'social',
	),
	'x_card'               => array(
		'type'    => 'enum',
		'default' => 'summary_large_image',
		'choices' => array( 'summary_large_image', 'summary' ),
		'group'   => 'social',
	),
	'x_site'               => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 16,
		'pattern' => '/^(@?[A-Za-z0-9_]{1,15})?$/',
		'group'   => 'social',
	),
	'fb_app_id'            => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 32,
		'pattern' => '/^\d*$/',
		'group'   => 'social',
	),
	'og_default_image'     => array(
		'type'    => 'int',
		'default' => 0,
		'min'     => 0,
		'group'   => 'social',
	),
	// The default image's address and size, kept beside its ID so a visitor's page never
	// reads the attachment (two queries) to print the site's fallback image.
	'og_default_image_url' => array(
		'type'    => 'url',
		'default' => '',
		'group'   => 'social',
		'export'  => false,
	),
	'og_default_image_w'   => array(
		'type'    => 'int',
		'default' => 0,
		'min'     => 0,
		'group'   => 'social',
		'export'  => false,
	),
	'og_default_image_h'   => array(
		'type'    => 'int',
		'default' => 0,
		'min'     => 0,
		'group'   => 'social',
		'export'  => false,
	),
	'og_default_image_alt' => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 300,
		'group'   => 'social',
		'export'  => false,
	),
	'og_content_image'     => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'social',
	),
	// Off for a site whose shared links never showed the featured image (a plugin it
	// moved from was set that way): the page's own image, then the defaults, only.
	'og_featured_image'    => array(
		'type'    => 'bool',
		'default' => true,
		'group'   => 'social',
	),
	'fediverse_creator'    => array(
		'type'    => 'string',
		'default' => '',
		'max'     => 120,
		'pattern' => '/^(@?[^@\s]+@[^@\s]+\.[^@\s]+)?$/',
		'group'   => 'social',
	),
	'nopin'                => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'social',
	),
	// The full set of sharing tags Rank Math printed (X title, text and image even where Open
	// Graph says the same; the image's type; a product's price, stock and brand; "Written by" and
	// "Time to read"), so a site that moved from it shares exactly as before (set by the importer).
	'social_rank_math'     => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'social',
	),
	// Single extra sharing tags another SEO plugin printed, kept after a move from it (importer
	// certification, queue Q254): the Facebook page as `article:publisher`, the image's type,
	// "Written by" for Slack and X, the date a post was last changed, a post's tags, a product's
	// price and stock.
	// The site's name on shared links (`og:site_name`): a template; empty = the site title. All in
	// One SEO's "Site Name" (`#site_title #separator_sa #tagline`) is kept after a move (Q254).
	// The picture X shows for a page with no X picture of its own, when it differs from the
	// shared one (Slim SEO's "default Twitter image", kept after a move — queue Q254).
	// With no X title or description of its own, X shows the page's search title and
	// description instead of the shared (Open Graph) ones (SmartCrawl's rule, kept after a
	// move — queue Q254).
	'x_page_text'          => array(
		'type'    => 'bool',
		'default' => false,
		'group'   => 'social',
	),
	'x_default_image'      => array(
		'type'    => 'int',
		'default' => 0,
		'min'     => 0,
		'group'   => 'social',
	),
	'og_site_name'         => array(
		'type'    => 'template',
		'default' => '',
		'max'     => 200,
		'group'   => 'social',
	),
	'social_extras'        => array(
		'type'    => 'list',
		'default' => array(),
		'choices' => array( 'publisher', 'publisher_all', 'image_type', 'written_by', 'written_by_pages', 'updated_time', 'article_tags', 'product', 'product_labels' ),
		'group'   => 'social',
	),
);
