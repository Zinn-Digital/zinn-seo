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
 * - **Moved to the bin / deleted / unpublished** (posts, pages, WooCommerce products — any public
 *   type; unpublished = back to draft, pending, scheduled or private): by the setting
 *   `redirects_on_trash`: `parent` (the parent page, else the main category, else the type's
 *   archive, else the home page), `410` (gone) or `none`. Publishing it again removes that rule.
 * - **A category or tag deleted** (any public taxonomy): `parent` → its parent term, or 410 when it
 *   has none; `410`; `none` (0.17.65).
 * - **Every language** (0.17.65): a translation plugin that gives the same page an address per
 *   language ({@see Translated}) gets the same rule for each of those addresses.
 * - **The address is live again** (a new page published where an old one redirected): a redirect
 *   this module wrote for that address is removed, so a rule never hides a live page; one written by
 *   a person is kept and flagged (`problem` = `shadows`) on the Redirects screen and in Fix it.
 * - Every rule written here carries `object_ref` (`post:<id>` / `term:<id>`), so it can be found
 *   and undone, and collapses chains: rules that pointed at the old address now point at the new.
 */
final class Auto {

	/**
	 * Addresses of posts about to be moved to the bin: post id => language => path.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $trashing = array();

	/**
	 * Term addresses before an edit: term id => address.
	 *
	 * @var array<int, string>
	 */
	private static array $terms = array();

	/**
	 * A published post's addresses in every language, read before an update: id => lang => path.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $before = array();

	/**
	 * Terms about to be deleted: id => [paths by language, parent term id, taxonomy].
	 *
	 * @var array<int, array{paths: array<string, string>, parent: int, taxonomy: string}>
	 */
	private static array $deleting = array();

	/** Statuses that take a published post off the site without the bin. */
	public const UNPUBLISHED = array( 'draft', 'pending', 'private', 'future' );

	/**
	 * Hooks (admin, REST, CLI, cron — wherever content changes).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'pre_post_update', array( self::class, 'before_update' ), 10, 1 );
		add_action( 'post_updated', array( self::class, 'post_updated' ), 20, 3 );
		add_action( 'transition_post_status', array( self::class, 'transition' ), 20, 3 );
		add_action( 'wp_trash_post', array( self::class, 'before_trash' ), 10, 1 );
		add_action( 'trashed_post', array( self::class, 'trashed' ), 10, 1 );
		add_action( 'before_delete_post', array( self::class, 'before_delete' ), 10, 2 );
		add_action( 'edit_terms', array( self::class, 'before_term_edit' ), 10, 2 );
		add_action( 'edited_term', array( self::class, 'term_edited' ), 10, 3 );
		add_action( 'pre_delete_term', array( self::class, 'before_term_delete' ), 10, 2 );
		add_action( 'delete_term', array( self::class, 'term_deleted' ), 10, 3 );
	}

	/**
	 * Remember a published post's addresses (every language) before it is changed.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function before_update( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && self::public_post( $post ) ) {
			self::$before[ (int) $post_id ] = Translated::paths( (string) get_permalink( $post ) );
		}
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
		$snapshot = self::$before[ (int) $post_id ] ?? null;
		unset( self::$before[ (int) $post_id ] );
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || ! Settings::get( 'redirects_auto', true ) ) {
			return;
		}
		if ( ! self::public_post( $before ) || ! self::public_post( $after ) ) {
			return;
		}
		$olds = $snapshot ?? array( '' => self::path( (string) get_permalink( $before ) ) );
		$news = Translated::paths( (string) get_permalink( $after ) );
		$kids = null;
		foreach ( $olds as $lang => $old ) {
			$new = (string) ( $news[ $lang ] ?? '' );
			if ( '' === $old || '' === $new || Path::key( $old, Store::base() ) === Path::key( $new, Store::base() ) ) {
				continue;
			}
			self::write( $old, $new, 301, 'post:' . (int) $post_id );
			$kids = $kids ?? ( is_post_type_hierarchical( $after->post_type ) && self::has_children( (int) $post_id, $after->post_type ) );
			if ( $kids ) {
				self::write_prefix( $old, $new, 'post:' . (int) $post_id );
			}
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
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'publish' === $old_status && in_array( (string) $new_status, self::UNPUBLISHED, true ) ) {
			// 0.17.65 (ZHORD6): taken off the site without the bin — the same rule as the bin.
			$paths = self::$before[ (int) $post->ID ] ?? array();
			if ( array() !== $paths && is_post_type_viewable( $post->post_type ) && 'attachment' !== $post->post_type ) {
				self::removed( $post, $paths, 'unpublish' );
			}
			return;
		}
		if ( 'publish' !== $new_status || ! self::public_post( $post ) || ! Store::ready() ) {
			return;
		}
		foreach ( Translated::paths( (string) get_permalink( $post ) ) as $path ) {
			self::live_again( $path );
		}
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
			self::$trashing[ (int) $post_id ] = Translated::paths( (string) get_permalink( $post ) );
		}
	}

	/**
	 * The post is in the bin: write its rule.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function trashed( $post_id ): void {
		$old = self::$trashing[ (int) $post_id ] ?? array();
		unset( self::$trashing[ (int) $post_id ] );
		$post = get_post( (int) $post_id );
		if ( array() !== $old && $post instanceof \WP_Post ) {
			self::removed( $post, $old, 'trash' );
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
			self::removed( $post, Translated::paths( (string) get_permalink( $post ) ), 'delete' );
		}
	}

	/**
	 * Write the rule for a removed post, in every language it had an address in.
	 *
	 * @param \WP_Post              $post  Post.
	 * @param array<string, string> $old   Its paths: language => path ('' = the site's language).
	 * @param string                $event trash|delete|unpublish.
	 * @return void
	 */
	private static function removed( \WP_Post $post, array $old, string $event ): void {
		$mode = self::gone_action( 'post:' . $post->post_type, $post, $event );
		if ( 'none' === $mode || array() === array_filter( $old ) ) {
			return;
		}
		$to = '410' === $mode ? array() : Translated::paths( self::parent_of( $post ) );
		foreach ( $old as $lang => $path ) {
			if ( '' === $path ) {
				continue;
			}
			if ( '410' === $mode ) {
				self::write( $path, '', 410, 'post:' . $post->ID, 'trash' );
				continue;
			}
			$target = (string) ( $to[ $lang ] ?? ( $to[''] ?? '' ) );
			self::write( $path, '' === $target ? '/' : $target, 301, 'post:' . $post->ID, 'trash' );
		}
	}

	/**
	 * What happens to the address of a page, product or term that is gone (0.17.65): the choice for
	 * its content type (`redirects_on_trash_by`), else the site's (`redirects_on_trash`), then the
	 * `zinn_seo_gone_action` filter.
	 *
	 * @param string            $key    `post:<type>` or `term:all`.
	 * @param \WP_Post|\WP_Term $item The post or term.
	 * @param string            $event  trash|delete|unpublish|term_delete.
	 * @return string parent|410|none
	 */
	public static function gone_action( string $key, $item, string $event ): string {
		$by     = (array) Settings::get( 'redirects_on_trash_by', array() );
		$action = (string) ( $by[ $key ]['action'] ?? '' );
		if ( '' === $action ) {
			$action = (string) Settings::get( 'redirects_on_trash', 'parent' );
		}

		/**
		 * What to do with the address of a post, page, product or term that is gone: `parent`
		 * (a permanent redirect to its parent page, category or archive), `410` (gone) or `none`.
		 *
		 * @param string            $action parent|410|none, from the settings.
		 * @param \WP_Post|\WP_Term $item The post or term.
		 * @param string            $event  `trash`, `delete`, `unpublish` (back to draft, pending,
		 *                                  scheduled or private) or `term_delete`.
		 */
		$filtered = (string) apply_filters( 'zinn_seo_gone_action', $action, $item, $event );

		return in_array( $filtered, array( 'parent', '410', 'none' ), true ) ? $filtered : $action;
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
			self::$terms[ (int) $term_id ] = Translated::paths( $link );
		}
	}

	/**
	 * Remember a public term's addresses (every language) and its parent before it is deleted.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function before_term_delete( $term_id, $taxonomy ): void {
		$tax  = get_taxonomy( (string) $taxonomy );
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $tax || ! $tax->public || ! $term instanceof \WP_Term ) {
			return;
		}
		$link = get_term_link( $term );
		if ( is_string( $link ) ) {
			self::$deleting[ (int) $term_id ] = array(
				'paths'    => Translated::paths( $link ),
				'parent'   => (int) $term->parent,
				'taxonomy' => (string) $taxonomy,
			);
		}
	}

	/**
	 * A category or tag was deleted (0.17.65, ZHORD6): `parent` sends its visitors to the parent
	 * term (or answers 410 when it had none), `410` answers gone, `none` leaves it.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function term_deleted( $term_id, $tt_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the action's signature.
		$was = self::$deleting[ (int) $term_id ] ?? null;
		unset( self::$deleting[ (int) $term_id ] );
		if ( null === $was ) {
			return;
		}
		$term = new \WP_Term(
			(object) array(
				'term_id'  => (int) $term_id,
				'taxonomy' => (string) $taxonomy,
				'parent'   => (int) $was['parent'],
			)
		);
		$mode = self::gone_action( 'term:all', $term, 'term_delete' );
		if ( 'none' === $mode ) {
			return;
		}
		$to = array();
		if ( 'parent' === $mode && $was['parent'] > 0 ) {
			$link = get_term_link( (int) $was['parent'], (string) $taxonomy );
			$to   = is_string( $link ) ? Translated::paths( $link ) : array();
		}
		foreach ( $was['paths'] as $lang => $path ) {
			$target = (string) ( $to[ $lang ] ?? ( $to[''] ?? '' ) );
			if ( '' === $target ) {
				self::write( $path, '', 410, 'term:' . (int) $term_id, 'trash' );
			} else {
				self::write( $path, $target, 301, 'term:' . (int) $term_id, 'trash' );
			}
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
		$olds = self::$terms[ (int) $term_id ] ?? array();
		unset( self::$terms[ (int) $term_id ] );
		$old = (string) ( $olds[''] ?? '' );
		if ( '' === $old || ! Settings::get( 'redirects_auto', true ) ) {
			return;
		}
		clean_term_cache( (int) $term_id, (string) $taxonomy );
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		$news = is_string( $link ) ? Translated::paths( $link ) : array();
		$new  = (string) ( $news[''] ?? '' );
		// Every other language's address of the term (0.17.65).
		foreach ( $olds as $lang => $was ) {
			$now = (string) ( $news[ $lang ] ?? '' );
			if ( '' !== $lang && '' !== $was && '' !== $now && Path::key( $was, Store::base() ) !== Path::key( $now, Store::base() ) ) {
				self::write( $was, $now, 301, 'term:' . (int) $term_id );
			}
		}
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
