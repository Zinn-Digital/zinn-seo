<?php
/**
 * Title, description and robots templates for every kind of page (zs-020, zs-021).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Templates;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stored shape is ONE setting, `templates`: key => record, where the key is a context
 * (`home`, `post:<type>`, `archive:<type>`, `tax:<taxonomy>`, `author`, `date`, `search`, `404`)
 * and the record holds only what the site changed. An empty field means "the default", and the
 * defaults are computed here at run time — so they are translated into the site's language and
 * a new post type has sensible titles the moment it is registered, without a migration.
 */
final class Templates {

	/**
	 * The robots values a content type's template may add (and a page may set): Google's three, and
	 * Bing's `nocache` (Q44, FIX14: Copilot shows only the title, address and snippet).
	 */
	public const EXTRA_ROBOTS = array( 'noarchive', 'nosnippet', 'noimageindex', 'nocache' );

	/**
	 * The fields of one record (the `templates` setting's dict `fields`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function record_fields(): array {
		return array(
			'title'    => array(
				'type' => 'template',
				'max'  => 300,
			),
			'desc'     => array(
				'type' => 'template',
				'max'  => 1000,
			),
			'index'    => array(
				'type'    => 'enum',
				'choices' => array( '', 'index', 'noindex' ),
			),
			'follow'   => array(
				'type'    => 'enum',
				'choices' => array( '', 'follow', 'nofollow' ),
			),
			'extra'    => array(
				'type'    => 'list',
				'choices' => self::EXTRA_ROBOTS,
			),
			'og_title' => array(
				'type' => 'template',
				'max'  => 300,
			),
			'og_desc'  => array(
				'type' => 'template',
				'max'  => 1000,
			),
			'og_image' => array(
				'type' => 'int',
				'min'  => 0,
			),
		);
	}

	/**
	 * The module's settings (read by Settings::schema() from the module definition).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array(
			'sep'                            => array(
				'type'    => 'enum',
				'default' => '–',
				'choices' => self::separators(),
				'group'   => 'appearance',
			),
			// How an automatic description is shortened. `rankmath`: as Rank Math did — a page's
			// own excerpt in full, a template's text cut at 160 characters at the last space with
			// nothing added — so a site that moved from Rank Math keeps every description it had
			// (set by the importer).
			'desc_rules'                     => array(
				'type'    => 'enum',
				'default' => 'zinn',
				'choices' => array( 'zinn', 'rankmath' ),
				'group'   => 'appearance',
			),
			// Text after a description on page 2 and on of a list or a split post, e.g.
			// `%page(-n)%` ("… - Page 2"). Empty: descriptions are not numbered (the title is).
			// A site that moved from SEO Ultimate keeps its numbering (set by the importer, IMP2).
			'desc_paged'                     => array(
				'type'    => 'template',
				'default' => '',
				'max'     => 100,
				'group'   => 'appearance',
			),
			// Per content type (`post:<type>`): whether its items are scored and analysed (Q277,
			// zs-768). '' = by how the type was registered (Content_Types::scored()).
			'content_types'                  => array(
				'type'     => 'dict',
				'default'  => array(),
				'fields'   => array(
					'scores' => array(
						'type'    => 'enum',
						'choices' => array( '', 'on', 'off' ),
					),
				),
				'max_keys' => 400,
				'group'    => 'appearance',
			),
			'templates'                      => array(
				'type'     => 'dict',
				'default'  => array(),
				'fields'   => self::record_fields(),
				'max_keys' => 400,
				'group'    => 'appearance',
			),
			// Page 2 and on of archives / of posts split into pages: kept out of search (off by
			// default; a site moving from Rank Math keeps its choice).
			'robots_noindex_archive_pages'   => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
			// Page 2 and on of the home page: '' as the other lists, `yes` or `no` apart.
			'robots_noindex_home_pages'      => array(
				'type'    => 'enum',
				'default' => '',
				'choices' => array( '', 'yes', 'no' ),
				'group'   => 'appearance',
			),
			'robots_nofollow_archive_pages'  => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
			'robots_noindex_hidden_products' => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
			'robots_noindex_post_pages'      => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'appearance',
			),
			'robots_max_snippet'             => array(
				'type'    => 'string',
				'default' => '-1',
				'max'     => 5,
				// `off`: no limit printed at all (SureRank and SmartCrawl print none).
				'pattern' => '/^(-1|\d{1,4}|off)?$/',
				'group'   => 'appearance',
			),
			// `off` prints no image limit at all (SureRank and SmartCrawl print none).
			'robots_max_image'               => array(
				'type'    => 'enum',
				'default' => 'large',
				'choices' => array( 'off', 'none', 'standard', 'large' ),
				'group'   => 'appearance',
			),
			'robots_max_video'               => array(
				'type'    => 'string',
				'default' => '-1',
				'max'     => 5,
				// `off`: no limit printed at all (SureRank and SmartCrawl print none).
				'pattern' => '/^(-1|\d{1,4}|off)?$/',
				'group'   => 'appearance',
			),
		);
	}

	/**
	 * Separator characters on offer.
	 *
	 * @return array<int, string>
	 */
	public static function separators(): array {
		return array( '–', '-', '—', '|', '·', '•', '»', '/', ':', '~', '*', '>' );
	}

	/**
	 * The per-object fields this module adds: robots fine-tuning (zs-021).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		return array(
			'max_snippet' => array(
				'type'        => 'string',
				'objects'     => array( 'post', 'term' ),
				'max'         => 5,
				'pattern'     => '/^(-1|\d{1,4})?$/',
				'description' => static fn() => __( 'Longest text snippet search engines may show, in characters (-1: no limit). Empty: the site default.', 'zinn-seo' ),
			),
			'max_image'   => array(
				'type'        => 'enum',
				'objects'     => array( 'post', 'term' ),
				'choices'     => array( '', 'none', 'standard', 'large' ),
				'description' => static fn() => __( 'Largest image preview search engines may show. Empty: the site default.', 'zinn-seo' ),
			),
			'max_video'   => array(
				'type'        => 'string',
				'objects'     => array( 'post', 'term' ),
				'max'         => 5,
				'pattern'     => '/^(-1|\d{1,4})?$/',
				'description' => static fn() => __( 'Longest video preview, in seconds (-1: no limit). Empty: the site default.', 'zinn-seo' ),
			),
		);
	}

	/**
	 * Every context the site has, for the screen: key => {label, group}. Public post types and
	 * taxonomies only (what a visitor can reach); a post type archive only when it has one.
	 *
	 * @return array<string, array{label: string, group: string}>
	 */
	public static function contexts(): array {
		$out = array(
			'home' => array(
				'label' => __( 'Home page', 'zinn-seo' ),
				'group' => 'home',
			),
		);
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name && ! self::attachment_pages() ) {
				continue;
			}
			$out[ 'post:' . $type->name ] = array(
				'label' => (string) $type->labels->singular_name,
				'group' => 'post',
			);
		}
		foreach ( get_post_types(
			array(
				'public'      => true,
				'has_archive' => true,
			),
			'objects'
		) as $type ) {
			$label = sprintf(
				/* translators: %s: a content type's plural name, e.g. "Products". */
				__( '%s archive', 'zinn-seo' ),
				(string) $type->labels->name
			);
			$out[ 'archive:' . $type->name ] = array(
				'label' => $label,
				'group' => 'archive',
			);
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			$out[ 'tax:' . $tax->name ] = array(
				'label' => (string) $tax->labels->singular_name,
				'group' => 'tax',
			);
		}
		$out['author'] = array(
			'label' => __( 'Author archives', 'zinn-seo' ),
			'group' => 'author',
		);
		$out['date']   = array(
			'label' => __( 'Date archives', 'zinn-seo' ),
			'group' => 'date',
		);
		$out['search'] = array(
			'label' => __( 'Search results', 'zinn-seo' ),
			'group' => 'search',
		);
		$out['404']    = array(
			'label' => __( 'Page not found', 'zinn-seo' ),
			'group' => '404',
		);

		return $out;
	}

	/**
	 * Does this site show attachment pages? WordPress 6.4 turned them off for new sites (option
	 * `wp_attachment_pages_enabled` = 0); sites that are older keep them (1, or no option).
	 *
	 * @return bool
	 */
	public static function attachment_pages(): bool {
		return '0' !== (string) get_option( 'wp_attachment_pages_enabled', '1' );
	}

	/**
	 * The built-in defaults of a context, in the site's language.
	 *
	 * @param string $key Context key.
	 * @return array{title: string, desc: string, index: string, follow: string, extra: array<int, string>, og_title: string, og_desc: string, og_image: int}
	 */
	public static function defaults( string $key ): array {
		$group = false === strpos( $key, ':' ) ? $key : substr( $key, 0, (int) strpos( $key, ':' ) );
		$out   = array(
			'title'    => '%title% %page% %sep% %sitename%',
			'desc'     => '',
			'index'    => 'index',
			'follow'   => 'follow',
			'extra'    => array(),
			'og_title' => '',
			'og_desc'  => '',
			'og_image' => 0,
		);
		switch ( $group ) {
			case 'home':
				$out['title'] = '%sitename% %page% %sep% %tagline%';
				$out['desc']  = '%tagline%';
				break;
			case 'post':
				$out['desc'] = '%excerpt%';
				break;
			case 'tax':
				$out['title'] = '%term% %page% %sep% %sitename%';
				$out['desc']  = '%term_description%';
				break;
			case 'archive':
				$out['title'] = '%posttype_plural% %page% %sep% %sitename%';
				break;
			case 'author':
				$out['title'] = '%author% %page% %sep% %sitename%';
				$out['desc']  = '%excerpt%';
				break;
			case 'date':
				$out['title'] = '%date% %page% %sep% %sitename%';
				break;
			case 'search':
				/* translators: %s: the words searched for (a template variable, keep it as is). */
				$out['title'] = sprintf( __( 'Search results for %s', 'zinn-seo' ), '"%search%"' ) . ' %page% %sep% %sitename%';
				// A results page is a near-duplicate of the pages it lists (Google's guidance).
				$out['index'] = 'noindex';
				break;
			case '404':
				$out['title'] = __( 'Page not found', 'zinn-seo' ) . ' %sep% %sitename%';
				$out['index'] = 'noindex';
				break;
		}

		return $out;
	}

	/**
	 * The record that applies to a context: the defaults with the site's changes on top.
	 *
	 * @param string $key Context key.
	 * @return array<string, mixed>
	 */
	public static function effective( string $key ): array {
		$stored = (array) Settings::get( 'templates', array() );
		$own    = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
		$out    = self::defaults( $key );
		foreach ( $own as $name => $value ) {
			if ( array_key_exists( (string) $name, $out ) && '' !== $value && array() !== $value && 0 !== $value ) {
				$out[ (string) $name ] = $value;
			}
		}

		/**
		 * Filters the record that applies to a context (the defaults with the site's changes on
		 * top). A translation plugin's integration answers with the title and description
		 * templates in the language being viewed.
		 *
		 * @param array<string, mixed> $out The record.
		 * @param string               $key The context: `home`, `post:<type>`, `tax:<taxonomy>`, `author`…
		 */
		return (array) apply_filters( 'zinn_seo_template', $out, $key );
	}
}
