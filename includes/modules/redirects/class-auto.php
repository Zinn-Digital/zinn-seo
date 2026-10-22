<?php
/**
 * Redirects written for you: a changed address, a page in the bin, a deleted product (zs-096).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - **Address changed** (slug, parent, date in the address): a 301 from the old address to the new
 *   one. A page with children, or a category while the post addresses contain `%category%`, also
 *   gets ONE pattern rule moving everything below the old address (`^/old/(.*)$` → `/new/$1`), so a
 *   parent with ten thousand children costs one rule, not ten thousand.
 * - **Moved to the bin / deleted** (posts, pages, WooCommerce products — any public type): by the
 *   setting `redirects_on_trash`: `parent` (the parent page, else the main category, else the type's
 *   archive, else the home page), `410` (gone) or `none`. Publishing it again removes that rule.
 * - **The address is live again** (a new page published where an old one redirected): a redirect
 *   this module wrote for that address is removed, so a rule never hides a live page; one written by
 *   a person is kept and flagged (`problem` = `shadows`) on the Redirects screen and in Fix it.
 * - Every rule written here carries `object_ref` (`post:<id>` / `term:<id>`), so it can be found
 *   and undone, and collapses chains: rules that pointed at the old address now point at the new.
 */
final class Auto {

	/**
	 * Addresses of posts about to be moved to the bin: post id => address.
	 *
	 * @var array<int, string>
	 */
	private static array $trashing = array();

	/**
	 * Term addresses before an edit: term id => address.
	 *
	 * @var array<int, string>
	 */
	private static array $terms = array();

	/**
	 * Hooks (admin, REST, CLI, cron — wherever content changes).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'post_updated', array( self::class, 'post_updated' ), 20, 3 );
		add_action( 'transition_post_status', array( self::class, 'transition' ), 20, 3 );
		add_action( 'wp_trash_post', array( self::class, 'before_trash' ), 10, 1 );
		add_action( 'trashed_post', array( self::class, 'trashed' ), 10, 1 );
		add_action( 'before_delete_post', array( self::class, 'before_delete' ), 10, 2 );
		add_action( 'edit_terms', array( self::class, 'before_term_edit' ), 10, 2 );
		add_action( 'edited_term', array( self::class, 'term_edited' ), 10, 3 );
	}

	/**
	 * Is this post's address one people and search engines know?
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function public_post( \WP_Post $post ): bool {
		return 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) && 'attachment' !== $post->post_type;
	}

	/**
	 * The path of an address on this site ('' when it is elsewhere).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function path( string $url ): string {
		if ( '' === $url || ! Store::is_local( $url ) ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$q    = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return '' === $q ? $path : $path . '?' . $q;
	}

	/**
	 * A published post's address changed.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $after   After.
	 * @param \WP_Post $before  Before.
	 * @return void
	 */
	public static function post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || ! Settings::get( 'redirects_auto', true ) ) {
			return;
		}
		if ( ! self::public_post( $before ) || ! self::public_post( $after ) ) {
			return;
		}
		$old = self::path( (string) get_permalink( $before ) );
		$new = self::path( (string) get_permalink( $after ) );
		if ( '' === $old || '' === $new || Path::key( $old, Store::base() ) === Path::key( $new, Store::base() ) ) {
			return;
		}
		self::write( $old, $new, 301, 'post:' . (int) $post_id );
		if ( is_post_type_hierarchical( $after->post_type ) && self::has_children( (int) $post_id, $after->post_type ) ) {
			self::write_prefix( $old, $new, 'post:' . (int) $post_id );
		}
	}

	/**
	 * Does a post have children?
	 *
	 * @param int    $id   Post.
	 * @param string $type Type.
	 * @return bool
	 */
	private static function has_children( int $id, string $type ): bool {
		$kids = get_posts(
			array(
				'post_type'      => $type,
				'post_parent'    => $id,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return array() !== $kids;
	}

	/**
	 * A post becomes published (new or republished): its address is live, so a redirect this
	 * module wrote for it goes, and one a person wrote is flagged.
	 *
	 * @param string   $new_status New.
	 * @param string   $old_status Old.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post || ! self::public_post( $post ) || ! Store::ready() ) {
			return;
		}
		self::live_again( self::path( (string) get_permalink( $post ) ) );
	}

	/**
	 * An address is live: remove the module's own rule for it, flag a person's.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public static function live_again( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		$row = Store::by_source( $path );
		if ( null === $row ) {
			return;
		}
		if ( 'auto' === (string) $row['origin'] || 'migration' === (string) $row['origin'] ) {
			Store::delete( array( (int) $row['id'] ) );
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update( Store::table(), array( 'problem' => 'shadows' ), array( 'id' => (int) $row['id'] ) );
	}

	/**
	 * Remember the address of a post about to go to the bin.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function before_trash( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && self::public_post( $post ) ) {
			self::$trashing[ (int) $post_id ] = self::path( (string) get_permalink( $post ) );
		}
	}

	/**
	 * The post is in the bin: write its rule.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function trashed( $post_id ): void {
		$old = self::$trashing[ (int) $post_id ] ?? '';
		unset( self::$trashing[ (int) $post_id ] );
		$post = get_post( (int) $post_id );
		if ( '' !== $old && $post instanceof \WP_Post ) {
			self::removed( $post, $old );
		}
	}

	/**
	 * A published post is deleted without the bin (e.g. a product deleted through the shop's API
	 * with `force`).
	 *
	 * @param int           $post_id Post.
	 * @param \WP_Post|null $post    Post.
	 * @return void
	 */
	public static function before_delete( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && self::public_post( $post ) ) {
			self::removed( $post, self::path( (string) get_permalink( $post ) ) );
		}
	}

	/**
	 * Write the rule for a removed post.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $old  Its address.
	 * @return void
	 */
	private static function removed( \WP_Post $post, string $old ): void {
		$mode = (string) Settings::get( 'redirects_on_trash', 'parent' );
		if ( 'none' === $mode || '' === $old ) {
			return;
		}
		if ( '410' === $mode ) {
			self::write( $old, '', 410, 'post:' . $post->ID, 'trash' );
			return;
		}
		$to = self::path( self::parent_of( $post ) );
		self::write( $old, '' === $to ? '/' : $to, 301, 'post:' . $post->ID, 'trash' );
	}

	/**
	 * Where a removed post's visitors go: the parent page, the main category, the type's archive,
	 * or the home page.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Address.
	 */
	public static function parent_of( \WP_Post $post ): string {
		if ( $post->post_parent > 0 ) {
			$parent = get_post( (int) $post->post_parent );
			if ( $parent instanceof \WP_Post && self::public_post( $parent ) ) {
				return (string) get_permalink( $parent );
			}
		}
		$tax = 'product' === $post->post_type ? 'product_cat' : ( 'post' === $post->post_type ? 'category' : '' );
		if ( '' === $tax ) {
			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $object ) {
				if ( $object->public && $object->hierarchical ) {
					$tax = $object->name;
					break;
				}
			}
		}
		if ( '' !== $tax ) {
			// The SEO field "main category" (`primary_term`), else the post's first term.
			$primary = (int) get_post_meta( $post->ID, '_zseo_primary_term', true );
			$main    = $primary > 0 ? get_term( $primary, $tax ) : null;
			$terms   = $main instanceof \WP_Term ? array( $main ) : wp_get_post_terms( $post->ID, $tax );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( $term instanceof \WP_Term && 'uncategorized' !== $term->slug ) {
					$link = get_term_link( $term );
					if ( is_string( $link ) ) {
						return $link;
					}
				}
			}
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( is_string( $archive ) && '' !== $archive && 'post' !== $post->post_type ) {
			return $archive;
		}

		return home_url( '/' );
	}

	/**
	 * Remember a term's address before it is edited.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function before_term_edit( $term_id, $taxonomy ): void {
		$tax = get_taxonomy( (string) $taxonomy );
		if ( ! $tax || ! $tax->public ) {
			return;
		}
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( is_string( $link ) ) {
			self::$terms[ (int) $term_id ] = self::path( $link );
		}
	}

	/**
	 * A term was edited: its address may have moved.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function term_edited( $term_id, $tt_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the action's signature.
		$old = self::$terms[ (int) $term_id ] ?? '';
		unset( self::$terms[ (int) $term_id ] );
		if ( '' === $old || ! Settings::get( 'redirects_auto', true ) ) {
			return;
		}
		clean_term_cache( (int) $term_id, (string) $taxonomy );
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		$new  = is_string( $link ) ? self::path( $link ) : '';
		if ( '' === $new || Path::key( $old, Store::base() ) === Path::key( $new, Store::base() ) ) {
			return;
		}
		self::write( $old, $new, 301, 'term:' . (int) $term_id );
		$kids = get_terms(
			array(
				'taxonomy'   => (string) $taxonomy,
				'parent'     => (int) $term_id,
				'number'     => 1,
				'fields'     => 'ids',
				'hide_empty' => false,
			)
		);
		if ( is_array( $kids ) && array() !== $kids ) {
			self::write_prefix( $old, $new, 'term:' . (int) $term_id );
		}
		// Posts whose own address contains the category (`/%category%/%postname%/`).
		$structure = (string) get_option( 'permalink_structure', '' );
		if ( 'category' === $taxonomy && false !== strpos( $structure, '%category%' ) ) {
			$term = get_term( (int) $term_id, 'category' );
			if ( $term instanceof \WP_Term ) {
				$old_seg = self::category_segment( $old );
				if ( '' !== $old_seg ) {
					self::write_prefix( '/' . $old_seg, '/' . (string) get_category_parents( (int) $term_id, false, '/', true ), 'term:' . (int) $term_id );
				}
			}
		}
	}

	/**
	 * The category's own path below the category base (`/category/news/world` → `news/world`).
	 *
	 * @param string $path Term archive path.
	 * @return string
	 */
	private static function category_segment( string $path ): string {
		$base = (string) get_option( 'category_base', '' );
		$base = '' === $base ? 'category' : trim( $base, '/' );
		$path = trim( (string) substr( $path, strlen( Store::base() ) ), '/' );

		return 0 === strpos( $path, $base . '/' ) ? trim( substr( $path, strlen( $base ) + 1 ), '/' ) : '';
	}

	/**
	 * Store a rule this module wrote, and point earlier rules at the new address (no chains).
	 *
	 * @param string $from   Old path.
	 * @param string $to     New path ('' for 410).
	 * @param int    $code   Code.
	 * @param string $ref    Object reference.
	 * @param string $note   Why: moved or trash.
	 * @return void
	 */
	public static function write( string $from, string $to, int $code, string $ref, string $note = 'moved' ): void {
		$existing = Store::by_source( $from );
		if ( null !== $existing && 'auto' !== (string) $existing['origin'] && 'migration' !== (string) $existing['origin'] ) {
			return; // A person's own rule for this address wins.
		}
		$saved = Store::save(
			array(
				'source' => $from,
				'target' => $to,
				'code'   => $code,
				'origin' => 'auto',
				'object' => $ref,
				'group'  => __( 'Automatic', 'zinn-seo' ),
				'note'   => $note,
			),
			true
		);
		if ( is_wp_error( $saved ) || '' === $to ) {
			return;
		}
		Checker::collapse( $from, $to );
		// The new address is live: a rule this module wrote FROM it earlier (a change undone) goes.
		self::live_again( $to );
	}

	/**
	 * One pattern rule for everything below an old address.
	 *
	 * @param string $old    Old path.
	 * @param string $to     New path.
	 * @param string $ref    Object reference.
	 * @return void
	 */
	private static function write_prefix( string $old, string $to, string $ref ): void {
		$old = trim( (string) substr( Path::normal_path( $old, Store::base(), true ), 1 ), '/' );
		$to  = trim( Path::normal_path( $to, Store::base(), true ), '/' );
		if ( '' === $old ) {
			return;
		}
		Store::save(
			array(
				'source' => '^/' . preg_quote( $old, '#' ) . '/(.+)$',
				// The match is made on the path without its trailing slash; give the target the slash
				// the site's addresses end with, or every hop would cost a second redirect.
				'target' => '/' . ( '' === $to ? '' : $to . '/' ) . '$1' . ( '/' === substr( (string) get_option( 'permalink_structure', '' ), -1 ) ? '/' : '' ),
				'code'   => 301,
				'regex'  => true,
				'origin' => 'auto',
				'object' => $ref,
				'group'  => __( 'Automatic', 'zinn-seo' ),
				'note'   => 'moved-children',
			),
			true
		);
	}
}
