<?php
/**
 * Every content type and taxonomy on the site — the site's own and other plugins' — with who
 * registered it, whether it has pages of its own, and the four choices made per type: search
 * engines (index/noindex), the sitemap, content scores and analysis (queue Q277, zs-768).
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
 * ⛔ WHY (owner, 2026-10-15, on zinnhub.com's Content › Scores): "some here like ffw etc are not
 * needed as they do not show, they are just reviews and faqs and things that show on actual pages
 * … we do not want to have to go through 1000s of pages we do not need to". Rank Math lists every
 * type a plugin registered with its own switches; so does this, and adds the one Rank Math lacks:
 * whether the type is scored at all.
 *
 * "No page of its own" is decided from how the type was REGISTERED (not publicly queryable: no
 * single page a visitor or a search engine can open). Such a type is left out of scores, analysis
 * and the sitemap by default. A type whose NAME says it is shown inside other pages (FAQ, review,
 * testimonial, slide…) but that does have pages is only SUGGESTED: a review site's reviews are its
 * main content, so a name alone never changes a default.
 */
final class Content_Types {

	/** The undo record of the last "Hide from search" (one step, this site only). */
	public const UNDO = 'zseo_types_undo';

	/** Core's internal types: never content anyone writes for search. */
	public const INTERNAL = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'link_category', 'nav_menu', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/** Names of types usually shown INSIDE other pages (a suggestion, never a default). */
	private const EMBEDDED = '/(?:^|[-_])(?:faqs?|ufaq|questions?|reviews?|testimonials?|slides?|sliders?|carousels?|accordions?|popups?|modals?|banners?|logos?|snippets?)(?:[-_]|$)/';

	/**
	 * Who registered each type, captured as it is registered: kind:name => file.
	 *
	 * @var array<string, string>
	 */
	private static array $files = array();

	/**
	 * Whether the hooks are on.
	 *
	 * @var bool
	 */
	private static bool $capturing = false;

	/**
	 * Note who registers each type from now on (admin, REST and WP-CLI only: the screen and the
	 * agents ask; a visitor's page never pays for it).
	 *
	 * @return void
	 */
	public static function capture(): void {
		if ( self::$capturing ) {
			return;
		}
		self::$capturing = true;
		add_action( 'registered_post_type', static fn( $name ) => self::note( 'post', (string) $name ), 0 );
		add_action( 'registered_taxonomy', static fn( $name ) => self::note( 'tax', (string) $name ), 0 );
	}

	/**
	 * From the plugin's bootstrap, while WordPress loads: wp-admin, the REST API and WP-CLI only.
	 * ⛔ Not from start.php: under WP-CLI the plugin may start after `init`, when every type is
	 * already registered and no one is left to ask.
	 *
	 * @return void
	 */
	public static function capture_early(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$rest = function_exists( 'rest_get_url_prefix' ) && false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether the request is a REST one (?rest_route=); nothing is read from it.
		$rest = $rest || isset( $_GET['rest_route'] );
		if ( is_admin() || $rest || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::capture();
		}
	}

	/**
	 * Remember the file that called register_post_type() / register_taxonomy().
	 *
	 * @param string $kind `post` or `tax`.
	 * @param string $name Type name.
	 * @return void
	 */
	private static function note( string $kind, string $name ): void {
		$found = 'core';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- naming the plugin or theme that registered a content type is this screen's purpose; arguments are not collected.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 14 ) as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			// The first file outside WordPress itself and outside this class is the one that asked.
			if ( '' === $file || wp_normalize_path( __FILE__ ) === $file || false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
				continue;
			}
			$found = $file;
			break;
		}
		self::$files[ $kind . ':' . $name ] = $found;
	}

	/**
	 * Who a file belongs to. Pure, for the tests.
	 *
	 * @param string                $file    Normalised path, or `core`.
	 * @param array<string, string> $roots   `plugins`, `mu`, `themes` directories (normalised).
	 * @param array<string, string> $plugins Plugin folder (or single file) => plugin name.
	 * @param array<string, string> $themes  Theme folder => theme name.
	 * @return array{kind: string, name: string}
	 */
	public static function owner( string $file, array $roots, array $plugins, array $themes ): array {
		if ( 'core' === $file || '' === $file ) {
			return array(
				'kind' => 'core',
				'name' => 'WordPress',
			);
		}
		foreach ( array(
			'plugins' => 'plugin',
			'mu'      => 'mu-plugin',
			'themes'  => 'theme',
		) as $root => $kind ) {
			$dir = rtrim( (string) ( $roots[ $root ] ?? '' ), '/' ) . '/';
			if ( '/' === $dir || 0 !== strpos( $file, $dir ) ) {
				continue;
			}
			$rest   = substr( $file, strlen( $dir ) );
			$folder = (string) strtok( $rest, '/' );
			$names  = 'theme' === $kind ? $themes : $plugins;
			return array(
				'kind' => $kind,
				'name' => (string) ( $names[ $folder ] ?? $folder ),
			);
		}
		// Anything outside wp-content (wp-settings.php re-registering core's types) is WordPress.
		if ( false === strpos( $file, '/wp-content/' ) ) {
			return array(
				'kind' => 'core',
				'name' => 'WordPress',
			);
		}

		return array(
			'kind' => 'unknown',
			'name' => '',
		);
	}

	/**
	 * Whether a registered type has pages of its own a visitor or a search engine can open
	 * (`is_post_type_viewable()` / `is_taxonomy_viewable()`). Pure on the object, for the tests.
	 *
	 * @param mixed $registered Post type or taxonomy object.
	 * @return bool
	 */
	public static function own_page( $registered ): bool {
		if ( ! is_object( $registered ) ) {
			// A bare name (a caller that only had names): kept, as Analysis::page_types() keeps it.
			return true;
		}
		if ( ! empty( $registered->_builtin ) ) {
			return ! empty( $registered->public );
		}

		return ! empty( $registered->publicly_queryable );
	}

	/**
	 * Whether a type's name or label says it is shown inside other pages (a suggestion only).
	 *
	 * @param string $name  Type name.
	 * @param string $label Its plural label.
	 * @return bool
	 */
	public static function looks_embedded( string $name, string $label = '' ): bool {
		$words = strtolower( $name . ' ' . preg_replace( '/\s+/', '-', $label ) );
		foreach ( preg_split( '/\s+/', $words ) as $word ) {
			if ( 1 === preg_match( self::EMBEDDED, (string) $word ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The stored choices for one type (`post:<type>` or `tax:<taxonomy>`).
	 *
	 * @param string $key Key.
	 * @return array<string, string>
	 */
	public static function stored( string $key ): array {
		$all = (array) Settings::get( 'content_types', array() );

		return array_map( 'strval', (array) ( $all[ $key ] ?? array() ) );
	}

	/**
	 * Whether a post type's posts are scored and analysed: the person's choice, else on for a
	 * type with pages of its own (not a page-builder template, not attachments), off otherwise.
	 *
	 * @param string $type       Post type.
	 * @param bool   $registered What the type's registration says (see Analysis::page_types()).
	 * @return bool
	 */
	public static function scored( string $type, bool $registered ): bool {
		$choice = (string) ( self::stored( 'post:' . $type )['scores'] ?? '' );
		if ( 'on' === $choice ) {
			return true;
		}
		if ( 'off' === $choice ) {
			return false;
		}

		return $registered;
	}

	/**
	 * Whether a post type is hidden: no page of its own, or kept out of search AND out of scores
	 * (what "Hide this type from search" does). The bulk editor and the scores leave it out.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function hidden( string $type ): bool {
		$object = get_post_type_object( $type );
		if ( ! $object || ! self::own_page( $object ) ) {
			return true;
		}

		return 'noindex' === (string) Templates::effective( 'post:' . $type )['index'] && 'off' === ( self::stored( 'post:' . $type )['scores'] ?? '' );
	}

	/**
	 * Post types a person may list here: every registered type with posts a person writes (shown
	 * in wp-admin or public), not core's internal ones.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			$name = (string) $name;
			if ( in_array( $name, self::INTERNAL, true ) || ! is_object( $object ) || ( empty( $object->public ) && empty( $object->show_ui ) ) ) {
				continue;
			}
			$out[ $name ] = $object;
		}

		return $out;
	}

	/**
	 * Taxonomies, the same way.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $object ) {
			$name = (string) $name;
			if ( in_array( $name, self::INTERNAL, true ) || 'post_format' === $name || ! is_object( $object ) || ( empty( $object->public ) && empty( $object->show_ui ) ) ) {
				continue;
			}
			$out[ $name ] = $object;
		}

		return $out;
	}

	/**
	 * Who registered what, by name: kind:name => {kind, name}.
	 *
	 * @return array<string, array{kind: string, name: string}>
	 */
	public static function owners(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( (array) get_plugins() as $file => $data ) {
			$plugins[ (string) strtok( (string) $file, '/' ) ] = (string) ( $data['Name'] ?? '' );
		}
		foreach ( (array) get_mu_plugins() as $file => $data ) {
			$plugins[ (string) $file ] = (string) ( $data['Name'] ?? '' );
		}
		$themes = array();
		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $slug ) {
			$themes[ (string) $slug ] = (string) wp_get_theme( (string) $slug )->get( 'Name' );
		}
		$roots = array(
			'plugins' => wp_normalize_path( WP_PLUGIN_DIR ),
			'mu'      => wp_normalize_path( WPMU_PLUGIN_DIR ),
			'themes'  => wp_normalize_path( get_theme_root() ),
		);
		$out   = array();
		foreach ( self::$files as $key => $file ) {
			$out[ $key ] = self::owner( $file, $roots, $plugins, $themes );
		}

		return $out;
	}

	/**
	 * Every row the screen shows.
	 *
	 * @return array{types: array<int, array<string, mixed>>, taxonomies: array<int, array<string, mixed>>, hidden: int, undo: bool}
	 */
	public static function rows(): array {
		$owners    = self::owners();
		$sm_types  = array_map( 'strval', (array) Settings::get( 'sitemap_exclude_types', array() ) );
		$sm_tax    = array_map( 'strval', (array) Settings::get( 'sitemap_exclude_taxonomies', array() ) );
		$analysed  = class_exists( '\ZinnDigital\ZinnSEO\Analysis\Analysis' ) ? \ZinnDigital\ZinnSEO\Analysis\Analysis::types() : array();
		$types     = array();
		$hidden    = 0;
		$post_list = self::post_types();
		foreach ( $post_list as $name => $object ) {
			$own    = self::own_page( $object );
			$counts = (array) wp_count_posts( $name );
			$items  = 0;
			foreach ( array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ) as $status ) {
				$items += (int) ( $counts[ $status ] ?? 0 );
			}
			$stored = self::stored( 'post:' . $name );
			$scores = in_array( $name, $analysed, true );
			$row    = array(
				'key'        => 'post:' . $name,
				'name'       => $name,
				'label'      => (string) $object->labels->name,
				'by'         => $owners[ 'post:' . $name ] ?? array(
					'kind' => 'unknown',
					'name' => '',
				),
				'own_page'   => $own,
				'suggested'  => ! $own || self::looks_embedded( $name, (string) $object->labels->name ),
				'items'      => $items,
				'index'      => $own && 'noindex' !== (string) Templates::effective( 'post:' . $name )['index'],
				'sitemap'    => $own && ! empty( $object->public ) && ! in_array( $name, $sm_types, true ) && 'noindex' !== (string) Templates::effective( 'post:' . $name )['index'] && ( 'attachment' !== $name || Templates::attachment_pages() ),
				'scores'     => $scores,
				'scores_set' => (string) ( $stored['scores'] ?? '' ),
				'public'     => ! empty( $object->public ),
			);
			if ( ! $scores && ( ! $own || ! $row['index'] ) ) {
				++$hidden;
			}
			$types[] = $row;
		}
		$taxonomies = array();
		foreach ( self::taxonomies() as $name => $object ) {
			$own          = self::own_page( $object );
			$count        = wp_count_terms(
				array(
					'taxonomy'   => $name,
					'hide_empty' => false,
				)
			);
			$taxonomies[] = array(
				'key'       => 'tax:' . $name,
				'name'      => $name,
				'label'     => (string) $object->labels->name,
				'by'        => $owners[ 'tax:' . $name ] ?? array(
					'kind' => 'unknown',
					'name' => '',
				),
				'own_page'  => $own,
				'suggested' => ! $own || self::looks_embedded( $name, (string) $object->labels->name ),
				'items'     => is_numeric( $count ) ? (int) $count : 0,
				'index'     => $own && 'noindex' !== (string) Templates::effective( 'tax:' . $name )['index'],
				'sitemap'   => $own && ! empty( $object->public ) && ! in_array( $name, $sm_tax, true ) && 'noindex' !== (string) Templates::effective( 'tax:' . $name )['index'],
				'public'    => ! empty( $object->public ),
			);
		}

		return array(
			'types'      => $types,
			'taxonomies' => $taxonomies,
			'hidden'     => $hidden,
			'undo'       => is_array( get_option( self::UNDO, null ) ),
		);
	}

	/**
	 * What "Hide this type from search" changes for each key, and how many items it covers.
	 *
	 * @param array<int, string> $keys `post:<type>` / `tax:<taxonomy>`.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function plan( array $keys ) {
		$posts = self::post_types();
		$taxes = self::taxonomies();
		$rows  = array();
		foreach ( self::rows()['types'] as $r ) {
			$rows[ $r['key'] ] = $r;
		}
		foreach ( self::rows()['taxonomies'] as $r ) {
			$rows[ $r['key'] ] = $r;
		}
		$out = array();
		foreach ( array_values( array_unique( array_map( 'strval', $keys ) ) ) as $key ) {
			[ $kind, $name ] = array_pad( explode( ':', $key, 2 ), 2, '' );
			if ( ! ( 'post' === $kind && isset( $posts[ $name ] ) ) && ! ( 'tax' === $kind && isset( $taxes[ $name ] ) ) ) {
				/* translators: %s: a content type's key, e.g. post:product. */
				return new \WP_Error( 'zinn_seo_unknown_type', sprintf( __( 'There is no content type %s on this site.', 'zinn-seo' ), $key ), array( 'status' => 404 ) );
			}
			$row   = $rows[ $key ];
			$out[] = array(
				'key'     => $key,
				'label'   => $row['label'],
				'items'   => $row['items'],
				'changes' => array_values(
					array_filter(
						array(
							$row['index'] ? 'noindex' : '',
							$row['sitemap'] ? 'sitemap' : '',
							'post' === $kind && ( $row['scores'] ?? false ) ? 'scores' : '',
						)
					)
				),
			);
		}

		return $out;
	}

	/**
	 * Hide types from search: noindex, out of the sitemap and (post types) out of scores. The
	 * settings as they were are kept for one Undo.
	 *
	 * @param array<int, string> $keys Keys.
	 * @return array<int, array<string, mixed>>|\WP_Error What changed (the plan), or why not.
	 */
	public static function hide( array $keys ) {
		$plan = self::plan( $keys );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$fields    = array( 'templates', 'sitemap_exclude_types', 'sitemap_exclude_taxonomies', 'content_types' );
		$before    = array();
		$templates = (array) Settings::get( 'templates', array() );
		$sm_types  = array_map( 'strval', (array) Settings::get( 'sitemap_exclude_types', array() ) );
		$sm_tax    = array_map( 'strval', (array) Settings::get( 'sitemap_exclude_taxonomies', array() ) );
		$choices   = (array) Settings::get( 'content_types', array() );
		foreach ( $fields as $field ) {
			$before[ $field ] = Settings::get( $field, array() );
		}
		foreach ( $plan as $row ) {
			[ $kind, $name ]          = explode( ':', (string) $row['key'], 2 );
			$record                   = (array) ( $templates[ $row['key'] ] ?? array() );
			$record['index']          = 'noindex';
			$templates[ $row['key'] ] = $record;
			if ( 'post' === $kind ) {
				$sm_types               = array_values( array_unique( array_merge( $sm_types, array( $name ) ) ) );
				$choice                 = (array) ( $choices[ $row['key'] ] ?? array() );
				$choice['scores']       = 'off';
				$choices[ $row['key'] ] = $choice;
			} else {
				$sm_tax = array_values( array_unique( array_merge( $sm_tax, array( $name ) ) ) );
			}
		}
		$saved = Settings::save(
			array(
				'templates'                  => $templates,
				'sitemap_exclude_types'      => $sm_types,
				'sitemap_exclude_taxonomies' => $sm_tax,
				'content_types'              => $choices,
			)
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		update_option(
			self::UNDO,
			array(
				'at'     => time(),
				'keys'   => array_column( $plan, 'key' ),
				'before' => $before,
			),
			false
		);
		/**
		 * Fires after content types were hidden from search (the sitemap rebuilds, scores refresh).
		 *
		 * @param array<int, string> $keys The keys hidden.
		 */
		do_action( 'zinn_seo_content_types_hidden', array_column( $plan, 'key' ) );

		return $plan;
	}

	/**
	 * Put back the settings the last "Hide from search" changed.
	 *
	 * @return array<int, string>|\WP_Error The keys restored.
	 */
	public static function undo() {
		$record = get_option( self::UNDO, null );
		if ( ! is_array( $record ) || ! is_array( $record['before'] ?? null ) ) {
			return new \WP_Error( 'zinn_seo_nothing_to_undo', __( 'There is nothing to undo.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$saved = Settings::save( (array) $record['before'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		delete_option( self::UNDO );
		do_action( 'zinn_seo_content_types_hidden', array_map( 'strval', (array) ( $record['keys'] ?? array() ) ) );

		return array_map( 'strval', (array) ( $record['keys'] ?? array() ) );
	}
}
