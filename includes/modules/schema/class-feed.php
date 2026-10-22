<?php
/**
 * The site graph for AI agents: `/.well-known/site-graph.json` (zs-038).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Blocks\Collector;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Library;
use ZinnDigital\ZinnSEO\Head\Graph;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One address an agent can read the whole site from, as schema.org data (the shape NLWeb and
 * similar agent indexers ingest), instead of crawling and parsing every page:
 *
 * - `/.well-known/site-graph.json` — a DataFeed: who runs the site, the site, and one item per
 *   indexable page (type, address, name, description, dates, language), newest change first is
 *   NOT promised; pages come in ID order, 500 at a time, the next page named in a `Link: rel=next`
 *   header (keyset: `?after=<ID>`, constant time at any size).
 * - `/.well-known/site-graph.json?url=<page address>` — that page's full graph, exactly what the
 *   page prints (blocks included).
 *
 * Only pages a search engine may index are listed (published, no password, not noindex, a public
 * type the sitemap lists). Answers are cached for an hour by browsers and proxies.
 */
final class Feed {

	/** The path under the site's home. */
	public const PATH = '.well-known/site-graph.json';

	/** Items per page. */
	public const PER_PAGE = 500;

	/**
	 * Hook the answer.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'wp_loaded', array( self::class, 'maybe_serve' ) );
		add_filter( 'zinn_seo_llms_sections', array( self::class, 'llms_note' ) );
	}

	/**
	 * Is this request for the feed?
	 *
	 * @return bool
	 */
	public static function requested(): bool {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return rtrim( $home, '/' ) . '/' . self::PATH === $path;
	}

	/**
	 * Answer.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		if ( ! self::requested() || ! (bool) Settings::get( 'schema_feed', true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public, read-only document.
		$url   = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['url'] ) ) : '';
		$after = isset( $_GET['after'] ) ? max( 0, (int) $_GET['after'] ) : 0;
		// phpcs:enable
		if ( '' !== $url ) {
			$data = self::page( $url );
			if ( null === $data ) {
				status_header( 404 );
				header( 'Content-Type: application/json; charset=UTF-8' );
				echo wp_json_encode( array( 'error' => 'not_found' ) );
				exit;
			}
		} else {
			$list = self::list( $after );
			$data = $list['data'];
			if ( null !== $list['next'] ) {
				header( 'Link: <' . esc_url_raw( add_query_arg( 'after', $list['next'], home_url( '/' . self::PATH ) ) ) . '>; rel="next"' );
			}
		}
		status_header( 200 );
		header( 'Content-Type: application/ld+json; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );
		// nosemgrep: php.lang.security.php-permissive-cors.php-permissive-cors -- a public, read-only, cookie-less JSON-LD feed meant for any crawler or AI client to fetch; re-check if it ever serves per-user or authenticated data.
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, sent as application/ld+json; WordPress's escaping kept on (no JSON_UNESCAPED_*, WordPress.org review P0TDX382477HGN).
		exit;
	}

	/**
	 * One page of the list.
	 *
	 * @param int $after The last ID of the previous page.
	 * @return array{data: array<string, mixed>, next: int|null}
	 */
	public static function list( int $after ): array {
		global $wpdb;
		$types = self::types();
		$items = array();
		$next  = null;
		$rows  = array();
		if ( array() !== $types ) {
			$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_password = '' AND post_type IN ($in) ORDER BY ID ASC LIMIT %d", array_merge( array( $after ), $types, array( self::PER_PAGE + 1 ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- keyset over the primary key; placeholders built above.
		}
		$ids = array_map( 'intval', (array) $rows );
		if ( count( $ids ) > self::PER_PAGE ) {
			array_pop( $ids );
			$next = (int) end( $ids );
		}
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$c = Context::for_post( $post );
			if ( ! empty( Resolver::robots( $c )['noindex'] ) ) {
				continue;
			}
			$kinds = Schema::post_types( $post );
			$item  = array(
				'@type'         => '' !== $kinds['article'] ? $kinds['article'] : ( '' !== $kinds['page'] ? $kinds['page'] : 'WebPage' ),
				'url'           => (string) get_permalink( $post ),
				'name'          => Resolver::title( $c ),
				'datePublished' => (string) get_post_time( 'c', true, $post ),
				'dateModified'  => (string) get_post_modified_time( 'c', true, $post ),
			);
			$desc  = Resolver::description( $c );
			if ( '' !== $desc ) {
				$item['description'] = $desc;
			}
			$items[] = array(
				'@type'        => 'DataFeedItem',
				'dateModified' => $item['dateModified'],
				'item'         => $item,
			);
		}
		$home = home_url( '/' );

		return array(
			'data' => array(
				'@context'        => 'https://schema.org',
				'@type'           => 'DataFeed',
				'@id'             => $home . self::PATH,
				'name'            => Html::plain( (string) get_bloginfo( 'name' ) ),
				'url'             => $home,
				'inLanguage'      => Schema::language(),
				'publisher'       => Schema::identity(),
				'dataFeedElement' => $items,
			),
			'next' => $next,
		);
	}

	/**
	 * One page's full graph, or null when it is not a listed page.
	 *
	 * @param string $url Page address.
	 * @return array<string, mixed>|null
	 */
	public static function page( string $url ): ?array {
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			return null;
		}
		$id   = url_to_postid( $url );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, self::types(), true ) ) {
			return null;
		}
		$c = Context::for_post( $post );
		if ( ! empty( Resolver::robots( $c )['noindex'] ) ) {
			return null;
		}

		return self::graph_for( $post );
	}

	/**
	 * A post's full graph as its page prints it (the validator and the editor use this too).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function graph_for( \WP_Post $post ): array {
		$c = Context::for_post( $post );
		Collector::reset();
		$url = (string) get_permalink( $post );
		foreach ( Library::nodes_for( $post, $url ) as $key => $node ) {
			Collector::add_node( (string) $key, $node );
		}
		// Outside a page view the graph's contributors may not be hooked: hook them for this call.
		$added = array();
		foreach ( array(
			array( 'ZinnDigital\ZinnSEO\Social\Social', 'graph', 10, 'social' ),
			array( Schema::class, 'graph', 15, 'schema' ),
			array( 'ZinnDigital\ZinnSEO\Blocks\Blocks', 'join_graph', 20, 'blocks' ),
		) as $hook ) {
			$cb = array( $hook[0], $hook[1] );
			if ( class_exists( $cb[0] ) && \ZinnDigital\ZinnSEO\Modules::enabled( $hook[3] ) && false === has_filter( 'zinn_seo_graph', $cb ) ) {
				add_filter( 'zinn_seo_graph', $cb, $hook[2], 2 );
				$added[] = array( $cb, $hook[2] );
			}
		}
		$nodes = Graph::nodes( $c );
		foreach ( $added as $hook ) {
			remove_filter( 'zinn_seo_graph', $hook[0], $hook[1] );
		}
		Collector::reset();

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		);
	}

	/**
	 * The post types listed: public, viewable, not attachments.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				$out[] = (string) $type;
			}
		}

		/**
		 * Filters the content types the site graph lists.
		 *
		 * @param array<int, string> $out Post types.
		 */
		return array_values( (array) apply_filters( 'zinn_seo_site_graph_types', $out ) );
	}

	/**
	 * Point AI assistants at the graph from llms.txt.
	 *
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @return array<int, array<string, mixed>>
	 */
	public static function llms_note( $sections ): array {
		$sections = (array) $sections;
		if ( (bool) Settings::get( 'schema_feed', true ) ) {
			$sections[] = array(
				'title' => __( 'Structured data', 'zinn-seo' ),
				'posts' => array(),
				'links' => array(
					array(
						'title' => __( 'Site graph (schema.org JSON-LD, every page)', 'zinn-seo' ),
						'url'   => home_url( '/' . self::PATH ),
					),
				),
			);
		}

		return $sections;
	}
}
