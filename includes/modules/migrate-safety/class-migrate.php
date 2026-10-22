<?php
/**
 * Address changes made safe: permalink structure, category/tag base, domain (zs-333).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Migrate;

use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset reads of the posts and terms tables by primary key.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ REDIRECTS FIRST, THEN THE SWITCH. Changing the permalink structure (or the category or tag
 * base) moves every post's address at once; WordPress itself redirects none of them.
 *
 * 1. **Plan** — `preview()` computes each published page's address under the CURRENT and the
 *    PLANNED structure (by answering WordPress's own option reads with the planned values and
 *    re-initialising the rewrite object — nothing is saved), so the screen can page through EVERY
 *    address that will move.
 * 2. **Write, then switch** — the `address-change` job walks every published page and term (keyset,
 *    resumes after a kill, no cap), writes a 301 for each moved address (group "Address change",
 *    origin `migration`), and only when the last one is written saves the new structure and
 *    flushes the rewrite rules.
 * 3. **Changed elsewhere** (Settings → Permalinks, WP-CLI, another plugin): the same job starts at
 *    once with the OLD structure as the starting point, so the redirects follow within seconds.
 *
 * Domain moves: the old host is added to `redirect_aliases` the moment the home address changes
 * (and any host can be added by hand), so a request still reaching this site under an old name is
 * sent — before the theme loads — to the same path on the new one; `domain_rules()` gives the
 * server snippets for the OLD server when it no longer runs this site.
 */
final class Migrate {

	/** Job id. */
	public const JOB = 'address-change';

	/**
	 * True while the job itself is saving the new structure (so the change hook does not run).
	 *
	 * @var bool
	 */
	private static bool $switching = false;

	/**
	 * Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * Front: answer requests for an old host name.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		$aliases = (array) Settings::get( 'redirect_aliases', array() );
		if ( array() === $aliases ) {
			return;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) ) : '';
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $host || $host === $home || ! in_array( $host, array_map( 'strtolower', $aliases ), true ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- appended to our own home address and passed through wp_redirect's own sanitising.
		$to  = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) . ( wp_parse_url( home_url( '/' ), PHP_URL_PORT ) ? ':' . (int) wp_parse_url( home_url( '/' ), PHP_URL_PORT ) : '' ) . '/' . ltrim( $uri, '/' );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- to this site's own home host.
		if ( wp_redirect( $to, 301, 'WordPress' ) ) {
			exit;
		}
	}

	/**
	 * Admin, REST, CLI, cron.
	 *
	 * @param string $context Context.
	 * @return void
	 */
	public static function boot( string $context ): void {
		Jobs::register( self::JOB, static fn() => __( 'Writing redirects for the new addresses', 'zinn-seo' ), array( self::class, 'step' ) );
		foreach ( array( 'permalink_structure', 'category_base', 'tag_base' ) as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'changed_elsewhere' ), 10, 3 );
		}
		add_action( 'update_option_home', array( self::class, 'home_changed' ), 10, 2 );
		if ( 'admin' === $context ) {
			add_action( 'admin_notices', array( self::class, 'permalink_notice' ) );
		}
	}

	/**
	 * The current structure values.
	 *
	 * @return array{structure: string, category_base: string, tag_base: string}
	 */
	public static function current(): array {
		return array(
			'structure'     => (string) get_option( 'permalink_structure', '' ),
			'category_base' => (string) get_option( 'category_base', '' ),
			'tag_base'      => (string) get_option( 'tag_base', '' ),
		);
	}

	/**
	 * Clean a planned structure.
	 *
	 * @param array<string, mixed> $in structure, category_base, tag_base.
	 * @return array{structure: string, category_base: string, tag_base: string}|\WP_Error
	 */
	public static function clean( array $in ) {
		$cur = self::current();
		$out = array(
			'structure'     => isset( $in['structure'] ) ? trim( (string) $in['structure'] ) : $cur['structure'],
			'category_base' => isset( $in['category_base'] ) ? trim( (string) $in['category_base'] ) : $cur['category_base'],
			'tag_base'      => isset( $in['tag_base'] ) ? trim( (string) $in['tag_base'] ) : $cur['tag_base'],
		);
		if ( '' !== $out['structure'] ) {
			$out['structure'] = '/' . ltrim( (string) preg_replace( '#/+#', '/', $out['structure'] ), '/' );
			if ( 1 !== preg_match( '/%(postname|post_id)%/', $out['structure'] ) ) {
				return new \WP_Error( 'zinn_seo_structure', __( 'A structure must contain %postname% or %post_id%, or every address would be the same for many posts.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
			if ( 1 === preg_match( '/[^A-Za-z0-9%\/_.\-]/', $out['structure'] ) ) {
				return new \WP_Error( 'zinn_seo_structure', __( 'Use only letters, numbers, slashes and the %tags% WordPress knows.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
		}
		foreach ( array( 'category_base', 'tag_base' ) as $k ) {
			$out[ $k ] = '' === $out[ $k ] ? '' : '/' . trim( sanitize_title_with_dashes( $out[ $k ] ), '/' );
			$out[ $k ] = '/' === $out[ $k ] ? '' : $out[ $k ];
		}

		return $out;
	}

	/**
	 * Run something with WordPress answering as if the structure were `$s` (nothing saved).
	 *
	 * @param array{structure: string, category_base: string, tag_base: string} $s  Structure.
	 * @param callable                                                          $run What to run.
	 * @return mixed
	 */
	public static function with_structure( array $s, callable $run ) {
		global $wp_rewrite;
		$cur     = self::current();
		$filters = array();
		foreach ( array(
			'permalink_structure' => $s['structure'],
			'category_base'       => $s['category_base'],
			'tag_base'            => $s['tag_base'],
		) as $option => $value ) {
			$filters[ $option ] = static fn() => $value;
			add_filter( 'pre_option_' . $option, $filters[ $option ], 999 );
		}
		$saved_extra = $wp_rewrite->extra_permastructs;
		$old_front   = $wp_rewrite->front;
		$wp_rewrite->init();
		$new_front = $wp_rewrite->front;
		$bases     = array(
			'category' => array( '' === $cur['category_base'] ? '/category' : $cur['category_base'], '' === $s['category_base'] ? '/category' : $s['category_base'] ),
			'post_tag' => array( '' === $cur['tag_base'] ? '/tag' : $cur['tag_base'], '' === $s['tag_base'] ? '/tag' : $s['tag_base'] ),
		);
		foreach ( $wp_rewrite->extra_permastructs as $name => $ps ) {
			$struct = (string) ( $ps['struct'] ?? '' );
			if ( ! empty( $ps['with_front'] ) && $old_front !== $new_front && 0 === strncmp( $struct, $old_front, strlen( $old_front ) ) ) { // strncmp: '' (plain permalinks) starts every struct; PHP 7 strpos() warns on an empty needle.
				$struct = $new_front . substr( $struct, strlen( $old_front ) );
			}
			if ( isset( $bases[ $name ] ) && $bases[ $name ][0] !== $bases[ $name ][1] ) {
				$struct = (string) preg_replace( '#' . preg_quote( $bases[ $name ][0], '#' ) . '/%#', $bases[ $name ][1] . '/%', $struct, 1 );
			}
			$wp_rewrite->extra_permastructs[ $name ]['struct'] = $struct;
		}
		try {
			$out = $run();
		} finally {
			foreach ( $filters as $option => $cb ) {
				remove_filter( 'pre_option_' . $option, $cb, 999 );
			}
			$wp_rewrite->init();
			$wp_rewrite->extra_permastructs = $saved_extra;
		}

		return $out;
	}

	/**
	 * The public types whose addresses move.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array_values(
			array_filter(
				get_post_types( array( 'public' => true ) ),
				static fn( $t ) => 'attachment' !== $t && is_post_type_viewable( (string) $t )
			)
		);
	}

	/**
	 * Published post ids after a cursor.
	 *
	 * @param int $after Cursor.
	 * @param int $n     How many.
	 * @return array<int, int>
	 */
	private static function post_ids( int $after, int $n ): array {
		global $wpdb;
		$types = self::types();
		if ( array() === $types ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ({$in}) ORDER BY ID ASC LIMIT %d", array_merge( array( $after ), $types, array( $n ) ) ) ) );
	}

	/**
	 * Term ids of the public taxonomies after a cursor.
	 *
	 * @param int $after Cursor (term_taxonomy_id).
	 * @param int $n     How many.
	 * @return array<int, array{0: int, 1: int, 2: string}> [tt_id, term_id, taxonomy].
	 */
	private static function terms_after( int $after, int $n ): array {
		global $wpdb;
		$taxes = array_values( get_taxonomies( array( 'public' => true ) ) );
		if ( array() === $taxes ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d AND taxonomy IN ({$in}) ORDER BY term_taxonomy_id ASC LIMIT %d", array_merge( array( $after ), $taxes, array( $n ) ) ), ARRAY_A );

		return array_map( static fn( $r ) => array( (int) $r['term_taxonomy_id'], (int) $r['term_id'], (string) $r['taxonomy'] ), $rows );
	}

	/**
	 * Old and new addresses of a set of posts and terms.
	 *
	 * @param array<string, string> $from  Structure the addresses are moving from.
	 * @param array<string, string> $to    Structure they move to.
	 * @param array<int, int>       $posts Post ids.
	 * @param array<int, array>     $terms Terms ([tt_id, term_id, taxonomy]).
	 * @return array<int, array{kind: string, id: int, from: string, to: string}> Only addresses that move.
	 */
	public static function moves( array $from, array $to, array $posts, array $terms ): array {
		if ( array() !== $posts ) {
			_prime_post_caches( $posts, false, false );
		}
		$read = static function () use ( $posts, $terms ): array {
			$out = array();
			foreach ( $posts as $id ) {
				$out[ 'post:' . $id ] = (string) get_permalink( $id );
			}
			foreach ( $terms as $t ) {
				$link                   = get_term_link( (int) $t[1], (string) $t[2] );
				$out[ 'term:' . $t[1] ] = is_string( $link ) ? $link : '';
			}
			return $out;
		};
		$cur  = self::current();
		$old  = $from === $cur ? $read() : self::with_structure( $from, $read );
		$new  = $to === $cur ? $read() : self::with_structure( $to, $read );
		$out  = array();
		foreach ( $old as $key => $url ) {
			$o = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
			$n = (string) wp_parse_url( (string) ( $new[ $key ] ?? '' ), PHP_URL_PATH ) . ( wp_parse_url( (string) ( $new[ $key ] ?? '' ), PHP_URL_QUERY ) ? '?' . wp_parse_url( (string) $new[ $key ], PHP_URL_QUERY ) : '' );
			if ( '' === $o || '' === $n || untrailingslashit( $o ) === untrailingslashit( $n ) ) {
				continue;
			}
			list( $kind, $id ) = explode( ':', $key );
			$out[]             = array(
				'kind' => $kind,
				'id'   => (int) $id,
				'from' => $o,
				'to'   => $n,
			);
		}

		return $out;
	}

	/**
	 * The next page of posts, then terms, after a cursor.
	 *
	 * @param string $after Cursor `post:<id>` or `term:<tt_id>`.
	 * @param int    $per   Rows.
	 * @return array{posts: array<int, int>, terms: array<int, array>, next: string}
	 */
	private static function page_ids( string $after, int $per ): array {
		$kind  = 0 === strpos( $after, 'term:' ) ? 'term' : 'post';
		$id    = (int) substr( $after, 5 );
		$posts = 'post' === $kind ? self::post_ids( $id, $per ) : array();
		$terms = 'term' === $kind || count( $posts ) < $per ? self::terms_after( 'term' === $kind ? $id : 0, $per - count( $posts ) ) : array();
		$next  = '';
		if ( count( $posts ) + count( $terms ) >= $per ) {
			$next = array() !== $terms ? 'term:' . end( $terms )[0] : 'post:' . end( $posts );
		}

		return array(
			'posts' => $posts,
			'terms' => $terms,
			'next'  => $next,
		);
	}

	/**
	 * A page of the addresses a planned change would move (posts, then terms; keyset), and the
	 * totals looked at.
	 *
	 * @param array<string, string> $to    Planned structure.
	 * @param string                $after Cursor `post:<id>` or `term:<tt_id>`.
	 * @param int                   $per   Rows to look at.
	 * @return array{items: array<int, array<string, mixed>>, next: string, totals: array<string, int>}
	 */
	public static function preview( array $to, string $after = '', int $per = 100 ): array {
		global $wpdb;
		$page  = self::page_ids( $after, $per );
		$types = self::types();
		$in    = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );

		return array(
			'items'  => self::moves( self::current(), $to, $page['posts'], $page['terms'] ),
			'next'   => $page['next'],
			'totals' => array(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
				'posts' => array() === $types ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})", $types ) ),
				'terms' => (int) wp_count_terms(
					array(
						'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
						'hide_empty' => false,
					)
				),
			),
		);
	}

	/**
	 * Start writing the redirects (then switch, when `$switch`).
	 *
	 * @param array<string, string> $from   Moving from.
	 * @param array<string, string> $to     Moving to.
	 * @param bool                  $and_switch Save `$to` when every redirect is written.
	 * @return true|\WP_Error
	 */
	public static function start( array $from, array $to, bool $and_switch ) {
		if ( ! Modules::enabled( 'redirects' ) || ! class_exists( '\ZinnDigital\ZinnSEO\Redirects\Store' ) ) {
			return new \WP_Error( 'zinn_seo_redirects_off', __( 'Switch the Redirects module on first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		\ZinnDigital\ZinnSEO\Redirects\Store::maybe_install();

		return Jobs::start(
			self::JOB,
			array(
				'from'    => $from,
				'to'      => $to,
				'switch'  => $and_switch,
				'after'   => 'post:0',
				'written' => 0,
				'group'   => sprintf( /* translators: %s: a date. */ __( 'Address change %s', 'zinn-seo' ), gmdate( 'Y-m-d' ) ),
			)
		);
	}

	/**
	 * One step of the job.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$after   = (string) ( $state['after'] ?? 'post:0' );
		$written = (int) ( $state['written'] ?? 0 );
		$done    = false;
		\ZinnDigital\ZinnSEO\Redirects\Index::defer();
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$page = self::page_ids( $after, 200 );
			foreach ( self::moves( (array) $state['from'], (array) $state['to'], $page['posts'], $page['terms'] ) as $move ) {
				$saved = \ZinnDigital\ZinnSEO\Redirects\Store::save(
					array(
						'source' => $move['from'],
						'target' => $move['to'],
						'code'   => 301,
						'origin' => 'migration',
						'object' => $move['kind'] . ':' . $move['id'],
						'group'  => (string) $state['group'],
					),
					true
				);
				if ( is_wp_error( $saved ) ) {
					continue;
				}
				++$written;
				// The new address is live: a rule written FROM it by an earlier change (one now
				// undone) would hide the page, and rules that pointed at the old address now
				// point at the new one (no chains).
				\ZinnDigital\ZinnSEO\Redirects\Auto::live_again( $move['to'] );
				\ZinnDigital\ZinnSEO\Redirects\Checker::collapse( $move['from'], $move['to'] );
			}
			if ( '' === $page['next'] ) {
				$done = true;
				break;
			}
			$after = $page['next'];
		}
		\ZinnDigital\ZinnSEO\Redirects\Index::flush();
		if ( $done && ! empty( $state['switch'] ) ) {
			self::$switching = true;
			$to              = (array) $state['to'];
			global $wp_rewrite;
			// Through the rewrite object, as Settings → Permalinks does: it saves the option AND
			// re-reads it, so the rules flushed below are the NEW structure's.
			$wp_rewrite->set_permalink_structure( $to['structure'] );
			$wp_rewrite->set_category_base( $to['category_base'] );
			$wp_rewrite->set_tag_base( $to['tag_base'] );
			flush_rewrite_rules( false );
			self::$switching = false;
		}
		if ( $done ) {
			update_option(
				'zseo_address_change',
				array(
					'at'      => time(),
					'from'    => $state['from'],
					'to'      => $state['to'],
					'written' => $written,
				),
				false
			);
		}

		return array(
			'state'    => array_merge(
				$state,
				array(
					'after'   => $after,
					'written' => $written,
				)
			),
			'done'     => $done,
			'progress' => array(
				'done'  => $written,
				'total' => null,
			),
		);
	}

	/**
	 * The structure was changed somewhere else: write the redirects now, from the old one.
	 *
	 * @param mixed  $old    Old value.
	 * @param mixed  $value  New value.
	 * @param string $option Option.
	 * @return void
	 */
	public static function changed_elsewhere( $old, $value, $option ): void {
		if ( self::$switching || (string) $old === (string) $value || ! Modules::enabled( 'redirects' ) ) {
			return;
		}
		$to                               = self::current();
		$from                             = $to;
		$map                              = array(
			'permalink_structure' => 'structure',
			'category_base'       => 'category_base',
			'tag_base'            => 'tag_base',
		);
		$from[ $map[ (string) $option ] ] = (string) $old;
		$job                              = Jobs::get( self::JOB );
		if ( 'pending' === $job['status'] && isset( $job['state']['from'] ) && empty( $job['state']['switch'] ) ) {
			// A second option of the same save (Settings → Permalinks saves three): the running
			// job's starting point already holds this option's old value.
			$from = (array) $job['state']['from'];
		}
		// A plain-address site (no structure) had no pretty addresses to move.
		if ( '' === $from['structure'] ) {
			return;
		}
		$started = self::start( $from, $to, false );
		// WP-CLI chains no loopback request: finish the job in this process, at its end (after
		// the other options of the same change are saved).
		if ( true === $started && defined( 'WP_CLI' ) && WP_CLI && ! has_action( 'shutdown', array( self::class, 'run_now' ) ) ) {
			add_action( 'shutdown', array( self::class, 'run_now' ) );
		}
	}

	/**
	 * Finish the job in this process (WP-CLI).
	 *
	 * @return void
	 */
	public static function run_now(): void {
		Jobs::run( self::JOB );
	}

	/**
	 * The home address changed: keep answering the old host name.
	 *
	 * @param mixed $old   Old.
	 * @param mixed $value New.
	 * @return void
	 */
	public static function home_changed( $old, $value ): void {
		$o = strtolower( (string) wp_parse_url( (string) $old, PHP_URL_HOST ) );
		$n = strtolower( (string) wp_parse_url( (string) $value, PHP_URL_HOST ) );
		if ( '' === $o || $o === $n || in_array( $o, array( 'localhost', '127.0.0.1' ), true ) ) {
			return;
		}
		$aliases = (array) Settings::get( 'redirect_aliases', array() );
		if ( ! in_array( $o, $aliases, true ) ) {
			$aliases[] = $o;
			Settings::save( array( 'redirect_aliases' => array_values( array_diff( $aliases, array( $n ) ) ) ) );
		}
	}

	/**
	 * Server snippets that send a whole old domain to the same paths on this site.
	 *
	 * @param string $old_host Old host name.
	 * @return array<string, string> nginx, apache, caddy.
	 */
	public static function domain_rules( string $old_host ): array {
		$old = strtolower( (string) preg_replace( '/[^a-z0-9.\-]/i', '', $old_host ) );
		$new = rtrim( home_url(), '/' );

		return array(
			'nginx'  => "server {\n    listen 80;\n    listen 443 ssl;\n    server_name {$old} www.{$old};\n    return 301 {$new}\$request_uri;\n}\n",
			'apache' => "<VirtualHost *:80>\n    ServerName {$old}\n    ServerAlias www.{$old}\n    Redirect permanent / {$new}/\n</VirtualHost>\n",
			'caddy'  => "{$old}, www.{$old} {\n    redir {$new}{uri} permanent\n}\n",
		);
	}

	/**
	 * On Settings → Permalinks: say that redirects follow, and where to plan the change first.
	 *
	 * @return void
	 */
	public static function permalink_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-permalink' !== $screen->id || ! Modules::enabled( 'redirects' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Zinn® SEO writes a redirect for every address a change here moves. To see every address that will move and have the redirects written before the switch, plan the change on the Address changes screen.', 'zinn-seo' ),
			esc_url( admin_url( 'admin.php?page=zinn-seo&view=redirects#/address' ) ),
			esc_html__( 'Plan the change', 'zinn-seo' )
		);
	}
}
