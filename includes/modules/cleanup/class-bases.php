<?php
/**
 * Category addresses without `/category/` and shop-category addresses without
 * `/product-category/` (zs-714).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cleanup;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `example.com/category/news/` becomes `example.com/news/`. Three parts, each needed:
 *
 * 1. every link WordPress makes to the category drops the base (`term_link`);
 * 2. the rewrite rules answer the short address — one rule set per category path, built from the
 *    categories themselves and rebuilt when one is added, renamed or deleted;
 * 3. the old address answers with a 301 to the new one, so no link and no ranking is lost.
 *
 * ⛔ A page and a category with the same address cannot both be reached. The screen lists every
 * such clash BEFORE the switch is turned on (collisions()), and the category wins only where the
 * owner accepted that.
 */
final class Bases {

	/** Query variable of an old base address being redirected. */
	public const REDIRECT_VAR = 'zseo_base_redirect';

	/** Categories per alternation in one rewrite rule (keeps each pattern well under PCRE's limit). */
	private const CHUNK = 150;

	/**
	 * Taxonomy => [setting, query variable].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function taxonomies(): array {
		return array(
			'category'    => array( 'url_category_base', 'category_name' ),
			'product_cat' => array( 'url_product_base', 'product_cat' ),
		);
	}

	/**
	 * Is the base removed for a taxonomy?
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function on( string $taxonomy ): bool {
		$map = self::taxonomies();

		return isset( $map[ $taxonomy ] ) && taxonomy_exists( $taxonomy ) && (bool) Settings::get( $map[ $taxonomy ][0], false );
	}

	/**
	 * Hooks for every request type (links and rules are made in wp-admin, cron and WP-CLI too).
	 *
	 * @return void
	 */
	public static function boot_any(): void {
		add_action( 'zinn_seo_settings_saved', array( self::class, 'settings_saved' ), 10, 2 );
		foreach ( array_keys( self::taxonomies() ) as $taxonomy ) {
			if ( ! (bool) Settings::get( self::taxonomies()[ $taxonomy ][0], false ) ) {
				continue;
			}
			// Before a translation plugin's own filter (Polylang, priority 10), which then adds the
			// language folder to these rules too: replaced after it, /fr/<category>/ answered
			// "not found" (importer certification, queue Q254).
			add_filter( $taxonomy . '_rewrite_rules', array( self::class, 'rules_' . $taxonomy ), 5 );
			add_action( 'created_' . $taxonomy, array( self::class, 'flush' ) );
			add_action( 'edited_' . $taxonomy, array( self::class, 'flush' ) );
			add_action( 'delete_' . $taxonomy, array( self::class, 'flush' ) );
		}
		if ( self::on_any() ) {
			add_filter( 'term_link', array( self::class, 'term_link' ), 10, 3 );
			add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		}
	}

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		if ( self::on_any() ) {
			add_action( 'template_redirect', array( self::class, 'redirect_old' ), 1 );
		}
	}

	/**
	 * Is the base removed for any taxonomy?
	 *
	 * @return bool
	 */
	private static function on_any(): bool {
		foreach ( self::taxonomies() as $pair ) {
			if ( (bool) Settings::get( $pair[0], false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rules change when either switch changes.
	 *
	 * @param array<string, mixed> $after  Settings now.
	 * @param array<string, mixed> $before Settings before.
	 * @return void
	 */
	public static function settings_saved( $after, $before ): void {
		foreach ( self::taxonomies() as $pair ) {
			if ( ( $after[ $pair[0] ] ?? false ) !== ( $before[ $pair[0] ] ?? false ) ) {
				// The filters above were registered for the OLD value: re-read and rebuild now.
				self::boot_any_after_change();
				self::flush();
				return;
			}
		}
	}

	/**
	 * After a switch within this request, register the filters for the new value.
	 *
	 * @return void
	 */
	private static function boot_any_after_change(): void {
		foreach ( array_keys( self::taxonomies() ) as $taxonomy ) {
			remove_filter( $taxonomy . '_rewrite_rules', array( self::class, 'rules_' . $taxonomy ), 5 );
		}
		remove_filter( 'term_link', array( self::class, 'term_link' ), 10 );
		remove_action( 'zinn_seo_settings_saved', array( self::class, 'settings_saved' ), 10 );
		self::boot_any();
	}

	/**
	 * Rebuild the rewrite rules (on a category change, or a switch).
	 *
	 * @return void
	 */
	public static function flush(): void {
		flush_rewrite_rules( false );
	}

	/**
	 * The base a taxonomy's addresses carry: `category` or the site's own, `product-category`…
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string Without slashes.
	 */
	public static function base( string $taxonomy ): string {
		if ( 'category' === $taxonomy ) {
			$base = (string) get_option( 'category_base', '' );
			return trim( '' !== $base ? $base : 'category', '/' );
		}
		$tax = get_taxonomy( $taxonomy );
		if ( $tax && is_array( $tax->rewrite ) && ! empty( $tax->rewrite['slug'] ) ) {
			return trim( (string) $tax->rewrite['slug'], '/' );
		}

		return $taxonomy;
	}

	/**
	 * A link without the base.
	 *
	 * @param string        $url      Link.
	 * @param \WP_Term|null $term     Term.
	 * @param string        $taxonomy Taxonomy.
	 * @return string
	 */
	public static function term_link( $url, $term, $taxonomy ): string {
		$url = (string) $url;
		if ( ! self::on( (string) $taxonomy ) ) {
			return $url;
		}

		return self::strip_base( $url, home_url( '/' ), self::base( (string) $taxonomy ) );
	}

	/**
	 * Remove `/<base>/` once, right after the home address (and the permalink front, if any).
	 *
	 * @param string $url  Link.
	 * @param string $home Home address with a trailing slash.
	 * @param string $base Base without slashes.
	 * @return string
	 */
	public static function strip_base( string $url, string $home, string $base ): string {
		if ( '' === $base || 0 !== strpos( $url, $home ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		$at   = strpos( $rest, $base . '/' );
		if ( false === $at || ( $at > 0 && '/' !== $rest[ $at - 1 ] ) ) {
			return $url;
		}

		return $home . substr( $rest, 0, $at ) . substr( $rest, $at + strlen( $base ) + 1 );
	}

	/**
	 * The full path of every term (parent/child), longest first.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, string>
	 */
	public static function paths( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>parent',
				'lang'       => '', // Every language's terms (Polylang reads this; others ignore it).
			)
		);
		$slugs = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>slug',
				'lang'       => '',
			)
		);
		if ( ! is_array( $terms ) || ! is_array( $slugs ) ) {
			return array();
		}

		return self::paths_from( $terms, $slugs );
	}

	/**
	 * Paths from id=>parent and id=>slug maps (pure, for the tests).
	 *
	 * @param array<int, int>    $parents Id => parent id.
	 * @param array<int, string> $slugs   Id => slug.
	 * @return array<int, string>
	 */
	public static function paths_from( array $parents, array $slugs ): array {
		$out = array();
		foreach ( $slugs as $id => $slug ) {
			$path = array( rawurlencode( (string) $slug ) );
			$seen = array( (int) $id => true );
			$up   = (int) ( $parents[ $id ] ?? 0 );
			while ( $up > 0 && isset( $slugs[ $up ] ) && ! isset( $seen[ $up ] ) ) {
				$seen[ $up ] = true;
				array_unshift( $path, rawurlencode( (string) $slugs[ $up ] ) );
				$up = (int) ( $parents[ $up ] ?? 0 );
			}
			$out[] = implode( '/', $path );
		}
		usort( $out, static fn( $a, $b ) => strlen( $a ) === strlen( $b ) ? strcmp( $a, $b ) : strlen( $b ) - strlen( $a ) );

		return array_values( array_unique( $out ) );
	}

	/**
	 * The rules: each term path answers without the base (feeds, pages, embeds too), and the old
	 * based address is kept only to be redirected.
	 *
	 * @param array<int, string> $paths Term paths.
	 * @param string             $query_var   The taxonomy's query variable.
	 * @param string             $base  The old base.
	 * @return array<string, string>
	 */
	public static function rules_for( array $paths, string $query_var, string $base ): array {
		$rules = array();
		foreach ( array_chunk( $paths, self::CHUNK ) as $chunk ) {
			$alt = '(' . implode( '|', array_map( static fn( $p ) => preg_quote( $p, '#' ), $chunk ) ) . ')';
			$rules[ $alt . '/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' ] = 'index.php?' . $query_var . '=$matches[1]&feed=$matches[2]';
			$rules[ $alt . '/embed/?$' ]                              = 'index.php?' . $query_var . '=$matches[1]&embed=true';
			$rules[ $alt . '/page/?([0-9]{1,})/?$' ]                  = 'index.php?' . $query_var . '=$matches[1]&paged=$matches[2]';
			$rules[ $alt . '/?$' ]                                    = 'index.php?' . $query_var . '=$matches[1]';
		}
		if ( '' !== $base ) {
			$rules[ preg_quote( $base, '#' ) . '/(.+?)/?$' ] = 'index.php?' . self::REDIRECT_VAR . '=$matches[1]';
		}

		return $rules;
	}

	/**
	 * Rules for categories.
	 *
	 * @param array<string, string> $rules WordPress's.
	 * @return array<string, string>
	 */
	public static function rules_category( $rules ): array {
		unset( $rules ); // Replaced whole: these addresses have no base, so WordPress's own set no longer matches.
		return self::rules_for( self::paths( 'category' ), 'category_name', self::base( 'category' ) );
	}

	/**
	 * Rules for shop categories.
	 *
	 * @param array<string, string> $rules WordPress's.
	 * @return array<string, string>
	 */
	public static function rules_product_cat( $rules ): array {
		unset( $rules ); // Replaced whole: these addresses have no base, so WordPress's own set no longer matches.
		return self::rules_for( self::paths( 'product_cat' ), 'product_cat', self::base( 'product_cat' ) );
	}

	/**
	 * The redirect variable is a public query variable.
	 *
	 * @param array<int, string> $vars Variables.
	 * @return array<int, string>
	 */
	public static function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::REDIRECT_VAR;

		return $vars;
	}

	/**
	 * An old based address: 301 to the new one (its page number and feed kept).
	 *
	 * @return void
	 */
	public static function redirect_old(): void {
		$old = (string) get_query_var( self::REDIRECT_VAR );
		if ( '' === $old ) {
			return;
		}
		$parts = explode( '/', trim( $old, '/' ) );
		$tail  = '';
		if ( count( $parts ) >= 2 && 'page' === $parts[ count( $parts ) - 2 ] && ctype_digit( (string) end( $parts ) ) ) {
			$tail  = 'page/' . (int) end( $parts ) . '/';
			$parts = array_slice( $parts, 0, -2 );
		} elseif ( in_array( end( $parts ), array( 'feed', 'rss', 'rss2', 'atom', 'rdf' ), true ) ) {
			$tail  = end( $parts ) . '/';
			$parts = array_slice( $parts, 0, -1 );
			if ( 'feed' === end( $parts ) ) {
				$parts = array_slice( $parts, 0, -1 );
			}
		}
		foreach ( array_keys( self::taxonomies() ) as $taxonomy ) {
			if ( ! self::on( $taxonomy ) ) {
				continue;
			}
			$term = get_term_by( 'slug', sanitize_title( (string) end( $parts ) ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					wp_safe_redirect( trailingslashit( (string) $link ) . $tail, 301, 'WordPress' );
					exit;
				}
			}
		}
	}

	/**
	 * Pages, posts and other terms whose address a base-less category address would take over.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, array{path: string, clash: string, link: string}>
	 */
	public static function collisions( string $taxonomy ): array {
		global $wpdb;
		$out   = array();
		$paths = self::paths( $taxonomy );
		if ( array() === $paths ) {
			return $out;
		}
		$tops = array_values( array_unique( array_map( static fn( $p ) => rawurldecode( (string) strtok( $p, '/' ) ), $paths ) ) );
		foreach ( array_chunk( $tops, 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- an on-demand check on the clean-up screen.
				$wpdb->prepare( "SELECT ID, post_name, post_type FROM {$wpdb->posts} WHERE post_parent = 0 AND post_status = 'publish' AND post_type IN ('page','post') AND post_name IN ($in)", $chunk ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
			);
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'path'  => (string) $row->post_name,
					'clash' => (string) $row->post_type,
					'link'  => (string) get_permalink( (int) $row->ID ),
				);
			}
		}

		return $out;
	}
}
