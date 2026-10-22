<?php
/**
 * Bring redirects in: other plugins, a spreadsheet, server rules (zs-099, zs-094).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Jobs;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reading other plugins' own tables, read-only; every value is a placeholder.
// phpcs:disable WordPress.WP.AlternativeFunctions -- the uploaded spreadsheet is read by byte offset so a big file resumes where it stopped.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sources, each read from where that plugin keeps its rules (installed or not — the data stays in
 * the database after a plugin is deactivated):
 *
 * | Source                | Where                                                      |
 * |-----------------------|------------------------------------------------------------|
 * | Redirection           | `{prefix}redirection_items` (+ `_groups`); URL rules only  |
 * | Yoast SEO Premium     | option `wpseo-premium-redirects-base` (or the two exports)  |
 * | Rank Math             | `{prefix}rank_math_redirections` (serialised sources)       |
 * | All in One SEO        | `{prefix}aioseo_redirects`                                  |
 * | SEOPress Pro          | posts of type `seopress_404` with a target                  |
 * | Safe Redirect Manager | posts of type `redirect_rule`                               |
 * | SmartCrawl            | `{prefix}smartcrawl_redirects` (URL Redirection tool)       |
 * | Spreadsheet (CSV)     | an uploaded file; header names recognised in any order      |
 * | Server rules          | pasted `Redirect`, `RedirectMatch`, `RewriteRule … [R=…]`   |
 *
 * ⭐ A dry run first (counts of new, replacing, unchanged and refused rows, with the first refusals
 * and why), then the import as the `redirects-import` job: keyset through the source, resumes after
 * a kill, no row cap; the index is written once per step. Rules whose matching we cannot express
 * (Redirection's referrer/agent/cookie conditions, Rank Math's "contains" with case rules…) are
 * converted to the nearest exact or pattern rule or refused with the reason — never silently
 * dropped. Re-running an import is safe: the same source replaces its own rule.
 */
final class Importer {

	/** Job id. */
	public const JOB = 'redirects-import';

	/** Rows read per database query. */
	private const BATCH = 500;

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Importing redirects', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * The plugin sources and how many rules each holds on this site.
	 *
	 * @return array<int, array{id: string, name: string, count: int}>
	 */
	public static function sources(): array {
		$out = array();
		foreach ( self::names() as $id => $name ) {
			$out[] = array(
				'id'    => $id,
				'name'  => $name,
				'count' => self::count( $id ),
			);
		}

		return $out;
	}

	/**
	 * Source ids and names.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			'redirection' => 'Redirection',
			'yoast'       => 'Yoast SEO Premium',
			'rankmath'    => 'Rank Math',
			'aioseo'      => 'All in One SEO',
			'seopress'    => 'SEOPress',
			'srm'         => 'Safe Redirect Manager',
			'smartcrawl'  => 'SmartCrawl',
			's301'        => 'Simple 301 Redirects',
			'seoredir'    => 'SEO Redirection',
			'quickppr'    => 'Quick Page/Post Redirect',
			'slimseo'     => 'Slim SEO',
		);
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Full name.
	 * @return bool
	 */
	private static function has_table( string $table ): bool {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * How many rules a source holds.
	 *
	 * @param string $id Source.
	 * @return int
	 */
	public static function count( string $id ): int {
		global $wpdb;
		switch ( $id ) {
			case 'redirection':
				$t = $wpdb->prefix . 'redirection_items';
				return self::has_table( $t ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ) : 0;
			case 'rankmath':
				$t = $wpdb->prefix . 'rank_math_redirections';
				return self::has_table( $t ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'trashed'", $t ) ) : 0;
			case 'aioseo':
				$t = $wpdb->prefix . 'aioseo_redirects';
				return self::has_table( $t ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ) : 0;
			case 'yoast':
				return count( self::yoast_rows() );
			case 'seopress':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_seopress_redirections_type' WHERE p.post_type = %s AND p.post_status <> 'trash'", 'seopress_404' ) );
			case 'srm':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft')", 'redirect_rule' ) );
			case 'smartcrawl':
				$t = $wpdb->prefix . 'smartcrawl_redirects';
				return self::has_table( $t ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ) : 0;
			case 's301':
				return count( self::option_rows( 's301' ) );
			case 'quickppr':
				return count( self::option_rows( 'quickppr' ) );
			case 'slimseo':
				return count( self::slim_rows( get_option( 'ss_redirects', array() ) ) );
			case 'seoredir':
				$t = $wpdb->prefix . 'WP_SEO_Redirection';
				return self::has_table( $t ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ) : 0;
		}

		return 0;
	}

	/**
	 * Rules kept in one option as "old address => new address" (zs-725): Simple 301 Redirects
	 * (`301_redirects`; a `*` is a wildcard when its `301_redirects_wildcard` switch is on) and
	 * Quick Page/Post Redirect's quick list (`quickppr_redirects`, where `*` is always one).
	 *
	 * @param string $id s301 or quickppr.
	 * @return array<int, array<string, mixed>> Rules as `in()` takes them.
	 */
	public static function option_rows( string $id ): array {
		$map  = get_option( 's301' === $id ? '301_redirects' : 'quickppr_redirects', array() );
		$wild = 'quickppr' === $id || 'true' === (string) get_option( '301_redirects_wildcard', '' );
		$out  = array();
		foreach ( is_array( $map ) ? $map : array() as $from => $to ) {
			$from = trim( (string) $from );
			$to   = trim( (string) $to );
			if ( '' === $from || '' === $to ) {
				continue;
			}
			$out[] = self::wildcard_rule( $from, $to, $wild, 301 );
		}

		return $out;
	}

	/**
	 * Slim SEO's redirect manager (option `ss_redirects`, id => `type`, `condition`, `from`,
	 * `to`, `enable`) as rules — missed by every move from Slim SEO until the importer
	 * certification (queue Q254). Pure, for the tests.
	 *
	 * @param mixed $stored The option.
	 * @return array<int, array<string, mixed>>
	 */
	public static function slim_rows( $stored ): array {
		$map = array(
			'exact-match' => 'exact',
			'contain'     => 'contains',
			'start-with'  => 'start',
			'end-with'    => 'end',
			'regex'       => 'regex',
		);
		$out = array();
		foreach ( is_array( $stored ) ? $stored : array() as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$from = trim( (string) ( $r['from'] ?? '' ) );
			$to   = trim( (string) ( $r['to'] ?? '' ) );
			if ( '' === $from || '' === $to ) {
				continue;
			}
			$code  = in_array( (int) ( $r['type'] ?? 301 ), array( 301, 302, 307, 308, 410, 451 ), true ) ? (int) $r['type'] : 301;
			$path  = (string) wp_parse_url( $from, PHP_URL_PATH );
			$out[] = self::rankmath_rule( '' !== $path ? $path : $from, $map[ (string) ( $r['condition'] ?? 'exact-match' ) ] ?? 'exact', $to, $code, ! empty( $r['enable'] ) );
		}

		return $out;
	}

	/**
	 * One "old => new" pair as a rule; with wildcards on, `/old/*` → `/new/*` becomes a pattern.
	 *
	 * @param string $from Old address (may hold `*`).
	 * @param string $to   New address (may hold `*`).
	 * @param bool   $wild Wildcards on.
	 * @param int    $code Code.
	 * @return array<string, mixed>
	 */
	public static function wildcard_rule( string $from, string $to, bool $wild, int $code ): array {
		$path = (string) wp_parse_url( $from, PHP_URL_PATH );
		$path = '' === $path ? $from : $path;
		if ( $wild && false !== strpos( $path, '*' ) ) {
			$parts = explode( '*', $path );
			$regex = '^' . implode( '(.*)', array_map( static fn( $p ) => preg_quote( $p, '#' ), $parts ) ) . '$';
			$n     = 0;
			$tgt   = (string) preg_replace_callback(
				'/\*/',
				static function () use ( &$n ): string {
					++$n;
					return '$' . $n;
				},
				$to
			);
			return array(
				'source' => $regex,
				'target' => $tgt,
				'code'   => $code,
				'regex'  => true,
			);
		}

		return array(
			'source' => $path,
			'target' => $to,
			'code'   => $code,
			'regex'  => false,
		);
	}

	/**
	 * One row of SEO Redirection's table as a rule (release 9.19): a page, a folder (that address
	 * and everything below it) or a pattern; its `redirect_type` is the code.
	 *
	 * @param array<string, mixed> $r The row.
	 * @return array<string, mixed>
	 */
	public static function seoredir_rule( array $r ): array {
		$type = (string) ( $r['redirect_from_type'] ?? 'Page' );
		$from = trim( (string) ( $r['redirect_from'] ?? '' ) );
		$to   = trim( (string) ( $r['redirect_to'] ?? '' ) );
		$code = in_array( (int) ( $r['redirect_type'] ?? 301 ), array( 301, 302, 307, 308 ), true ) ? (int) $r['redirect_type'] : 301;
		$base = array(
			'target'  => $to,
			'code'    => $code,
			'enabled' => '1' === (string) ( $r['enabled'] ?? '1' ),
		);
		if ( 'Regex' === $type ) {
			$pattern = trim( (string) ( $r['regex'] ?? '' ) );
			return $base + array(
				'source' => '' !== $pattern ? $pattern : $from,
				'regex'  => true,
			);
		}
		if ( 'Folder' === $type ) {
			$folder = '/' . trim( (string) wp_parse_url( $from, PHP_URL_PATH ), '/' );
			return $base + array(
				'source' => '^' . preg_quote( $folder, '#' ) . '(/.*)?$',
				'regex'  => true,
			);
		}

		return $base + array(
			'source' => $from,
			'regex'  => false,
		);
	}

	/**
	 * Yoast's rules (stored in one option; read whole — Yoast itself does the same on every page).
	 *
	 * @return array<int, array<string, mixed>> Rules as `in()` takes them.
	 */
	public static function yoast_rows(): array {
		$out  = array();
		$base = get_option( 'wpseo-premium-redirects-base', null );
		if ( is_array( $base ) ) {
			foreach ( $base as $r ) {
				if ( is_array( $r ) && isset( $r['origin'] ) ) {
					$out[] = self::yoast_rule( (string) $r['origin'], (string) ( $r['url'] ?? '' ), (int) ( $r['type'] ?? 301 ), 'regex' === ( $r['format'] ?? 'plain' ) );
				}
			}
			return $out;
		}
		foreach ( array(
			'plain' => false,
			'regex' => true,
		) as $format => $regex ) {
			$rows = get_option( 'wpseo-premium-redirects-export-' . $format, array() );
			foreach ( is_array( $rows ) ? $rows : array() as $origin => $r ) {
				$out[] = self::yoast_rule( (string) $origin, (string) ( is_array( $r ) ? ( $r['url'] ?? '' ) : '' ), (int) ( is_array( $r ) ? ( $r['type'] ?? 301 ) : 301 ), $regex );
			}
		}

		return $out;
	}

	/**
	 * One Yoast rule.
	 *
	 * @param string $origin Origin (no leading slash in Yoast).
	 * @param string $url    Target.
	 * @param int    $type   Code.
	 * @param bool   $regex  Pattern.
	 * @return array<string, mixed>
	 */
	private static function yoast_rule( string $origin, string $url, int $type, bool $regex ): array {
		return array(
			'source' => $regex ? self::slash_anchor( $origin ) : '/' . ltrim( $origin, '/' ),
			'target' => '' === $url || 1 === preg_match( '#^https?://#i', $url ) ? $url : '/' . ltrim( $url, '/' ),
			'code'   => $type,
			'regex'  => $regex,
		);
	}

	/**
	 * Read rows of a source after a cursor, as rules (`in()`-shaped; `refused` holds a reason).
	 *
	 * @param string $id    Source.
	 * @param int    $after Cursor (row id, or index for option-based sources).
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read( string $id, int $after ): array {
		global $wpdb;
		$rows = array();
		$next = $after;
		switch ( $id ) {
			case 'redirection':
				$t = $wpdb->prefix . 'redirection_items';
				$g = $wpdb->prefix . 'redirection_groups';
				if ( ! self::has_table( $t ) ) {
					break;
				}
				$join = self::has_table( $g ) ? $wpdb->prepare( 'LEFT JOIN %i g ON g.id = i.group_id', $g ) : '';
				$sel  = '' !== $join ? ', g.name AS grp' : ", '' AS grp";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $join is prepared, $sel a literal.
				$res = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.url, i.regex, i.status, i.action_type, i.action_code, i.action_data, i.match_type, i.title{$sel} FROM %i i {$join} WHERE i.id > %d ORDER BY i.id ASC LIMIT %d", $t, $after, self::BATCH ), ARRAY_A );
				foreach ( $res as $r ) {
					$next = (int) $r['id'];
					if ( 'url' !== (string) $r['match_type'] || ! in_array( (string) $r['action_type'], array( 'url', 'error' ), true ) ) {
						$rows[] = array(
							'source'  => (string) $r['url'],
							/* translators: %s: Redirection's condition type, e.g. referrer. */
							'refused' => sprintf( __( 'Matches on "%s", which a redirect here cannot test.', 'zinn-seo' ), (string) $r['match_type'] . '/' . (string) $r['action_type'] ),
						);
						continue;
					}
					$code = (int) $r['action_code'];
					$data = (string) $r['action_data'];
					if ( 'error' === (string) $r['action_type'] ) {
						if ( 410 !== $code ) {
							$rows[] = array(
								'source'  => (string) $r['url'],
								/* translators: %d: an HTTP status. */
								'refused' => sprintf( __( 'Answers %d, which WordPress already does for a missing page.', 'zinn-seo' ), $code ),
							);
							continue;
						}
						$data = '';
					}
					$rows[] = array(
						'source'  => (string) $r['url'],
						'target'  => $data,
						'code'    => $code,
						'regex'   => (bool) (int) $r['regex'],
						'enabled' => 'enabled' === (string) $r['status'],
						'group'   => (string) $r['grp'],
						'note'    => (string) $r['title'],
						'id_from' => (int) $r['id'],
					);
				}
				break;
			case 'rankmath':
				$t = $wpdb->prefix . 'rank_math_redirections';
				if ( ! self::has_table( $t ) ) {
					break;
				}
				$res = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code, status FROM %i WHERE id > %d AND status <> 'trashed' ORDER BY id ASC LIMIT %d", $t, $after, self::BATCH ), ARRAY_A );
				foreach ( $res as $r ) {
					$next    = (int) $r['id'];
					$sources = maybe_unserialize( (string) $r['sources'] );
					foreach ( is_array( $sources ) ? $sources : array() as $src ) {
						if ( ! is_array( $src ) || ! isset( $src['pattern'] ) ) {
							continue;
						}
						$rows[] = self::rankmath_rule( (string) $src['pattern'], (string) ( $src['comparison'] ?? 'exact' ), (string) $r['url_to'], (int) $r['header_code'], 'active' === (string) $r['status'] );
					}
				}
				break;
			case 'aioseo':
				$t = $wpdb->prefix . 'aioseo_redirects';
				if ( ! self::has_table( $t ) ) {
					break;
				}
				$res = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $t, $after, self::BATCH ), ARRAY_A );
				foreach ( $res as $r ) {
					$next   = (int) $r['id'];
					$rows[] = array(
						'source'  => (string) $r['source_url'],
						'target'  => (string) ( $r['target_url'] ?? '' ),
						'code'    => (int) ( $r['type'] ?? 301 ),
						'regex'   => ! empty( $r['regex'] ),
						'enabled' => ! isset( $r['enabled'] ) || ! empty( $r['enabled'] ),
						'group'   => (string) ( $r['group'] ?? '' ),
					);
				}
				break;
			case 'yoast':
				$all = self::yoast_rows();
				foreach ( array_slice( $all, $after, self::BATCH ) as $i => $r ) {
					$rows[] = $r;
					$next   = $after + $i + 1;
				}
				break;
			case 'seopress':
			case 'srm':
				$type = 'seopress' === $id ? 'seopress_404' : 'redirect_rule';
				$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash' AND ID > %d ORDER BY ID ASC LIMIT %d", $type, $after, self::BATCH ) ) );
				if ( array() !== $ids ) {
					update_meta_cache( 'post', $ids );
				}
				foreach ( $ids as $pid ) {
					$next = $pid;
					$post = get_post( $pid );
					if ( ! $post instanceof \WP_Post ) {
						continue;
					}
					if ( 'seopress' === $id ) {
						$code = (int) get_post_meta( $pid, '_seopress_redirections_type', true );
						if ( 0 === $code ) {
							continue; // A 404 log entry, not a rule.
						}
						$rows[] = array(
							'source'  => '/' . ltrim( (string) $post->post_title, '/' ),
							'target'  => (string) get_post_meta( $pid, '_seopress_redirections_value', true ),
							'code'    => $code,
							'regex'   => 'yes' === get_post_meta( $pid, '_seopress_redirections_enabled_regex', true ),
							'enabled' => 'yes' === get_post_meta( $pid, '_seopress_redirections_enabled', true ),
						);
						continue;
					}
					$from   = (string) get_post_meta( $pid, '_redirect_rule_from', true );
					$regex  = (bool) get_post_meta( $pid, '_redirect_rule_from_regex', true );
					$rows[] = array(
						'source'  => $regex ? self::srm_pattern( $from ) : $from,
						'target'  => (string) get_post_meta( $pid, '_redirect_rule_to', true ),
						'code'    => (int) get_post_meta( $pid, '_redirect_rule_status_code', true ),
						'regex'   => $regex,
						'enabled' => 'publish' === $post->post_status,
						'note'    => (string) get_post_meta( $pid, '_redirect_rule_notes', true ),
					);
				}
				break;
			case 's301':
			case 'quickppr':
			case 'slimseo':
				// One option, read whole (as each plugin does on every page): a single batch.
				if ( 0 === $after ) {
					$rows = 'slimseo' === $id ? self::slim_rows( get_option( 'ss_redirects', array() ) ) : self::option_rows( $id );
					$next = PHP_INT_MAX;
				}
				break;
			case 'seoredir':
				$t = $wpdb->prefix . 'WP_SEO_Redirection';
				if ( ! self::has_table( $t ) ) {
					break;
				}
				$res = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, enabled, redirect_from, redirect_from_type, redirect_to, regex, redirect_type FROM %i WHERE ID > %d ORDER BY ID ASC LIMIT %d', $t, $after, self::BATCH ), ARRAY_A );
				foreach ( $res as $r ) {
					$next   = (int) $r['ID'];
					$rows[] = self::seoredir_rule( $r );
				}
				break;
			case 'smartcrawl':
				$t = $wpdb->prefix . 'smartcrawl_redirects';
				if ( ! self::has_table( $t ) ) {
					break;
				}
				// Its rules answer only while its "URL Redirection" tool is switched on.
				$tool = get_option( 'wds-advanced', array() );
				$on   = is_array( $tool ) && ! empty( $tool['redirects']['active'] );
				$res  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, source, path, destination, type, options, rules FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $t, $after, self::BATCH ), ARRAY_A );
				foreach ( $res as $r ) {
					$next   = (int) $r['id'];
					$rows[] = self::smartcrawl_rule( $r, $on );
				}
				break;
		}

		return array(
			'rows' => $rows,
			'next' => $next,
		);
	}

	/**
	 * One row of SmartCrawl's URL Redirection table as a rule (read from its release 3.16.4).
	 *
	 * Its destination is JSON: an address, or `{"id": <post>}` for "a page of this site" (the
	 * page's address today is written down — a rule here follows a page by its address through
	 * the automatic redirects). A pattern there is tested against the WHOLE address, scheme and
	 * host included, and must match all of it; here patterns are tested against the path. So a
	 * pattern that starts with this site's address becomes the same pattern on the path, one that
	 * starts with `.*` matches from anywhere, and any other never matched there at all — it is
	 * refused with that reason rather than brought to life. Rules that choose a destination by the
	 * visitor's country are refused: a redirect here cannot test that.
	 *
	 * @param array<string, mixed> $r  The row.
	 * @param bool                 $on Whether its redirection tool is switched on.
	 * @return array<string, mixed>
	 */
	public static function smartcrawl_rule( array $r, bool $on ): array {
		$source = trim( (string) ( $r['source'] ?? '' ) );
		$code   = (int) ( $r['type'] ?? 0 );
		$regex  = in_array( 'regex', array_map( 'trim', explode( ',', (string) ( $r['options'] ?? '' ) ) ), true ) || 'regex' === (string) ( $r['path'] ?? '' );
		$rules  = json_decode( (string) ( $r['rules'] ?? '' ), true );
		if ( is_array( $rules ) && array() !== $rules ) {
			return array(
				'source'  => $source,
				'refused' => __( 'Chooses its destination by the visitor\'s country, which a redirect here cannot test.', 'zinn-seo' ),
			);
		}
		$to = json_decode( (string) ( $r['destination'] ?? '' ), true );
		if ( is_array( $to ) ) {
			$link = isset( $to['id'] ) ? get_permalink( (int) $to['id'] ) : false;
			$to   = is_string( $link ) ? $link : '';
			if ( '' === $to && ! in_array( $code, array( 410, 451 ), true ) ) {
				return array(
					'source'  => $source,
					'refused' => __( 'Points to a page that no longer exists.', 'zinn-seo' ),
				);
			}
		}
		// (Stored as JSON text — `"\/new\/"`; an address stored bare by an older release is taken as it is.)
		$raw = trim( (string) ( $r['destination'] ?? '' ) );
		$to  = is_string( $to ) ? trim( $to ) : ( null === $to && '' !== $raw && false === strpos( '{["', $raw[0] ) ? $raw : '' );
		if ( $regex ) {
			$pattern = (string) preg_replace( '/^\^|\$$/', '', $source );
			$home    = (string) preg_replace( '#^https?://#i', '', untrailingslashit( home_url() ) );
			$host    = (string) strtok( $home, '/' );
			// "http", "https" or "https?", then the host with or without "www." and with its dots escaped or not.
			$parts  = array_map( static fn( string $part ): string => preg_quote( $part, '#' ), explode( '.', (string) preg_replace( '/^www\./i', '', $host ) ) );
			$starts = '#^https?\??://(?:www\\\\?\.)?' . implode( '\\\\?\.', $parts ) . '(?=/|$)#i';
			if ( 1 === preg_match( $starts, $pattern ) ) {
				$pattern = '^' . (string) preg_replace( $starts, '', $pattern ) . '$';
				$pattern = '^$' === $pattern ? '^/$' : $pattern;
			} elseif ( 0 === strpos( $pattern, '.*' ) ) {
				$pattern = substr( $pattern, 2 ) . '$';
			} else {
				return array(
					'source'  => $source,
					'refused' => __( 'Its pattern is tested against the whole address there and does not begin with this site\'s address or ".*", so it never matched a page.', 'zinn-seo' ),
					'inert'   => true,
				);
			}
			$source = $pattern;
		}

		return array(
			'source'  => $source,
			'target'  => $to,
			'code'    => 0 === $code ? 302 : $code,
			'regex'   => $regex,
			'enabled' => $on,
			'note'    => (string) ( $r['title'] ?? '' ),
			'id_from' => (int) ( $r['id'] ?? 0 ),
		);
	}

	/**
	 * Yoast and Rank Math match their patterns against the path WITHOUT its leading slash; ours run
	 * against the path with it, so an anchored `^old/(.*)` becomes `^/old/(.*)`.
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	public static function slash_anchor( string $pattern ): string {
		return 0 === strpos( $pattern, '^' ) && 0 !== strpos( $pattern, '^/' ) ? '^/' . substr( $pattern, 1 ) : $pattern;
	}

	/**
	 * Safe Redirect Manager matches its pattern anywhere in the path unless anchored.
	 *
	 * @param string $from Pattern.
	 * @return string
	 */
	private static function srm_pattern( string $from ): string {
		return 0 === strpos( $from, '^' ) ? $from : '^' . $from;
	}

	/**
	 * One Rank Math source as a rule.
	 *
	 * @param string $pattern    Pattern (no leading slash in Rank Math).
	 * @param string $comparison exact, contains, start, end or regex.
	 * @param string $to         Target.
	 * @param int    $code       Code.
	 * @param bool   $on         Active.
	 * @return array<string, mixed>
	 */
	public static function rankmath_rule( string $pattern, string $comparison, string $to, int $code, bool $on ): array {
		$plain = '/' . ltrim( $pattern, '/' );
		$quote = preg_quote( ltrim( $pattern, '/' ), '#' );
		switch ( $comparison ) {
			case 'regex':
				$source = self::slash_anchor( $pattern );
				$regex  = true;
				break;
			case 'start':
				$source = '^/' . $quote;
				$regex  = true;
				break;
			case 'end':
				$source = $quote . '$';
				$regex  = true;
				break;
			case 'contains':
				$source = $quote;
				$regex  = true;
				break;
			default:
				$source = $plain;
				$regex  = false;
		}

		return array(
			'source'  => $source,
			'target'  => '' === $to || 1 === preg_match( '#^https?://#i', $to ) ? $to : '/' . ltrim( $to, '/' ),
			'code'    => $code,
			'regex'   => $regex,
			'enabled' => $on,
		);
	}

	/**
	 * Parse a spreadsheet's rows (the header row names the columns, in any order; without one:
	 * source, target, code). Redirection's, Rank Math's and this plugin's own exports are read.
	 *
	 * @param array<int, string>      $cells  One row's cells.
	 * @param array<string, int>|null $header Column name => index (null: no header).
	 * @return array<string, mixed>|null Null for a blank row.
	 */
	public static function csv_rule( array $cells, ?array $header ): ?array {
		$cells = array_map( 'trim', array_map( 'strval', $cells ) );
		if ( array() === array_filter( $cells, static fn( $c ) => '' !== $c ) ) {
			return null;
		}
		$get     = static function ( string $name, int $fallback ) use ( $cells, $header ): string {
			if ( null === $header ) {
				return $cells[ $fallback ] ?? '';
			}
			return isset( $header[ $name ] ) ? (string) ( $cells[ $header[ $name ] ] ?? '' ) : '';
		};
		$code    = $get( 'code', 2 );
		$enabled = strtolower( $get( 'enabled', -1 ) );

		return array(
			'source'  => $get( 'source', 0 ),
			'target'  => $get( 'target', 1 ),
			'code'    => '' === $code ? 301 : (int) $code,
			'regex'   => in_array( strtolower( $get( 'regex', -1 ) ), array( '1', 'yes', 'true', 'regex' ), true ),
			'enabled' => '' === $enabled || in_array( $enabled, array( '1', 'yes', 'true', 'enabled', 'active' ), true ),
			'group'   => $get( 'group', -1 ),
			'note'    => $get( 'note', -1 ),
		);
	}

	/**
	 * Recognise a header row.
	 *
	 * @param array<int, string> $cells First row.
	 * @return array<string, int>|null Column name => index, null when it is not a header.
	 */
	public static function csv_header( array $cells ): ?array {
		$names = array(
			'source'  => array( 'source', 'from', 'url', 'old', 'old url', 'origin', 'source url', 'redirect from' ),
			'target'  => array( 'target', 'to', 'destination', 'new', 'new url', 'action_data', 'target url', 'url_to', 'redirect to' ),
			'code'    => array( 'code', 'type', 'status code', 'http code', 'action_code', 'header_code', 'redirect type' ),
			'regex'   => array( 'regex', 'is_regex', 'regular expression' ),
			'enabled' => array( 'enabled', 'status', 'active' ),
			'group'   => array( 'group', 'category' ),
			'note'    => array( 'note', 'title', 'notes' ),
		);
		$map   = array();
		foreach ( $cells as $i => $cell ) {
			$cell = strtolower( trim( (string) $cell, " \t\n\r\0\x0B\xEF\xBB\xBF\"" ) );
			foreach ( $names as $name => $aliases ) {
				if ( ! isset( $map[ $name ] ) && in_array( $cell, $aliases, true ) ) {
					// Redirection's export has both `code` and `type` (= "url"): `code` wins.
					$map[ $name ] = (int) $i;
					break;
				}
			}
		}

		return isset( $map['source'] ) ? $map : null;
	}

	/**
	 * Parse pasted server rules into rules.
	 *
	 * @param string $text `.htaccess` / Apache lines.
	 * @return array<int, array<string, mixed>>
	 */
	public static function server_rules( string $text ): array {
		$out = array();
		foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$codes = array(
				'permanent' => 301,
				'temp'      => 302,
				'seeother'  => 303,
				'gone'      => 410,
			);
			if ( 1 === preg_match( '/^Redirect(Match)?\s+(?:(\d{3}|permanent|temp|seeother|gone)\s+)?(\S+)(?:\s+(\S+))?$/i', $line, $m ) ) {
				$code  = '' === $m[2] ? 302 : ( ctype_digit( $m[2] ) ? (int) $m[2] : $codes[ strtolower( $m[2] ) ] );
				$out[] = array(
					'source' => trim( $m[3], '"' ),
					'target' => trim( (string) ( $m[4] ?? '' ), '"' ),
					'code'   => $code,
					'regex'  => '' !== $m[1],
				);
				continue;
			}
			if ( 1 === preg_match( '/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]$/i', $line, $m ) ) {
				$flags = strtoupper( $m[3] );
				if ( 1 === preg_match( '/(?:^|,)G(?:,|$)/', $flags ) ) {
					$code = 410;
				} elseif ( 1 === preg_match( '/R=(\d{3})/', $flags, $c ) ) {
					$code = (int) $c[1];
				} elseif ( 1 === preg_match( '/(?:^|,)R(?:,|$)/', $flags ) ) {
					$code = 302;
				} else {
					continue; // An internal rewrite, not a redirect.
				}
				$pattern = (string) $m[1];
				// In .htaccess the path has no leading slash; ours does.
				$pattern = 0 === strpos( $pattern, '^' ) && 0 !== strpos( $pattern, '^/' ) ? '^/' . substr( $pattern, 1 ) : $pattern;
				$out[]   = array(
					'source' => $pattern,
					'target' => '-' === $m[2] ? '' : (string) $m[2],
					'code'   => $code,
					'regex'  => true,
				);
			}
		}

		return $out;
	}

	/**
	 * Store one imported rule (or say why not).
	 *
	 * @param array<string, mixed> $rule   Rule.
	 * @param string               $origin Origin.
	 * @param bool                 $dry    Only check.
	 * @param string               $why    Set to the reason when refused.
	 * @return string new, replace, same or refused.
	 */
	public static function in( array $rule, string $origin, bool $dry, string &$why = '' ): string {
		if ( isset( $rule['refused'] ) ) {
			$why = (string) $rule['refused'];
			return 'refused';
		}
		$rule['origin'] = $origin;
		if ( 410 === (int) ( $rule['code'] ?? 0 ) || 451 === (int) ( $rule['code'] ?? 0 ) ) {
			$rule['target'] = '';
		}
		$clean = Store::clean( $rule );
		if ( is_wp_error( $clean ) ) {
			$why = $clean->get_error_message();
			return 'refused';
		}
		$existing = Store::by_hash( (string) $clean['hash'] );
		$kind     = null === $existing ? 'new' : ( (string) $existing['target'] === (string) $clean['target'] && (int) $existing['code'] === (int) $clean['code'] ? 'same' : 'replace' );
		if ( $dry || 'same' === $kind ) {
			return $kind;
		}
		$saved = Store::save( $rule, true );
		if ( is_wp_error( $saved ) ) {
			$why = $saved->get_error_message();
			return 'refused';
		}

		return $kind;
	}

	/**
	 * A dry run over a whole source (counts only; nothing stored).
	 *
	 * @param string $id Source id, or `csv:<token>`, or `server`.
	 * @param string $text Pasted text (server rules).
	 * @return array<string, mixed>
	 */
	public static function preview( string $id, string $text = '' ): array {
		$counts = array(
			'new'     => 0,
			'replace' => 0,
			'same'    => 0,
			'refused' => 0,
		);
		$why    = array();
		$sample = array();
		$each   = static function ( array $rule ) use ( &$counts, &$why, &$sample ): void {
			$reason = '';
			$kind   = self::in( $rule, 'preview', true, $reason );
			++$counts[ $kind ];
			if ( 'refused' === $kind && count( $why ) < 20 ) {
				$why[] = array(
					'source' => (string) ( $rule['source'] ?? '' ),
					'reason' => $reason,
				);
			} elseif ( 'refused' !== $kind && count( $sample ) < 10 ) {
				$sample[] = array(
					'source' => (string) $rule['source'],
					'target' => (string) ( $rule['target'] ?? '' ),
					'code'   => (int) ( $rule['code'] ?? 301 ),
					'regex'  => ! empty( $rule['regex'] ),
					'kind'   => $kind,
				);
			}
		};
		if ( 'server' === $id ) {
			foreach ( self::server_rules( $text ) as $rule ) {
				$each( $rule );
			}
		} elseif ( 0 === strpos( $id, 'csv:' ) ) {
			$state = array( 'offset' => 0 );
			do {
				$page  = self::csv_page( substr( $id, 4 ), $state );
				$state = $page['state'];
				foreach ( $page['rows'] as $rule ) {
					$each( $rule );
				}
			} while ( ! $page['done'] );
		} else {
			$after = 0;
			do {
				$page  = self::read( $id, $after );
				$moved = $page['next'] !== $after;
				$after = $page['next'];
				foreach ( $page['rows'] as $rule ) {
					$each( $rule );
				}
			} while ( $moved );
		}

		return array(
			'counts'  => $counts,
			'refused' => $why,
			'sample'  => $sample,
		);
	}

	/**
	 * Keep an uploaded spreadsheet in the private folder until it is imported.
	 *
	 * @param string $body File content.
	 * @return string|\WP_Error Token.
	 */
	public static function keep_upload( string $body ) {
		$dir = Index::dir( true );
		if ( '' === $dir ) {
			return new \WP_Error( 'zinn_seo_uploads', __( 'The uploads folder cannot be written, so the file cannot be kept for importing.', 'zinn-seo' ), array( 'status' => 500 ) );
		}
		$token = strtolower( wp_generate_password( 32, false, false ) );
		file_put_contents( dirname( $dir ) . '/' . $token . '.csv', $body );

		return $token;
	}

	/**
	 * Read up to BATCH spreadsheet rows from a byte offset.
	 *
	 * @param string               $token Upload token.
	 * @param array<string, mixed> $state offset, header.
	 * @return array{rows: array<int, array<string, mixed>>, state: array<string, mixed>, done: bool}
	 */
	public static function csv_page( string $token, array $state ): array {
		$file = dirname( Index::dir( true ) ) . '/' . preg_replace( '/[^a-z0-9]/', '', $token ) . '.csv';
		$fh   = is_readable( $file ) ? fopen( $file, 'rb' ) : false;
		if ( false === $fh ) {
			return array(
				'rows'  => array(),
				'state' => $state,
				'done'  => true,
			);
		}
		fseek( $fh, (int) ( $state['offset'] ?? 0 ) );
		$header = $state['header'] ?? null;
		$first  = 0 === (int) ( $state['offset'] ?? 0 );
		$delim  = (string) ( $state['delim'] ?? '' );
		$rows   = array();
		$n      = 0;
		while ( $n < self::BATCH && ! feof( $fh ) ) {
			$pos  = ftell( $fh );
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			if ( '' === $delim ) {
				$delim = substr_count( $line, ';' ) > substr_count( $line, ',' ) ? ';' : ( substr_count( $line, "\t" ) > substr_count( $line, ',' ) ? "\t" : ',' );
			}
			fseek( $fh, (int) $pos );
			$cells = fgetcsv( $fh, 0, $delim, '"', '\\' );
			if ( ! is_array( $cells ) ) {
				continue;
			}
			if ( $first ) {
				$first  = false;
				$header = self::csv_header( $cells );
				if ( null !== $header ) {
					continue;
				}
			}
			$rule = self::csv_rule( $cells, is_array( $header ) ? $header : null );
			if ( null !== $rule ) {
				$rows[] = $rule;
				++$n;
			}
		}
		$state = array(
			'offset' => (int) ftell( $fh ),
			'header' => $header,
			'delim'  => $delim,
		);
		$done  = feof( $fh );
		fclose( $fh );

		return array(
			'rows'  => $rows,
			'state' => $state,
			'done'  => $done,
		);
	}

	/**
	 * Start an import.
	 *
	 * @param string $id   Source id, `csv:<token>` or `server`.
	 * @param string $text Pasted server rules.
	 * @return true|\WP_Error
	 */
	public static function start( string $id, string $text = '' ) {
		Store::maybe_install();

		return Jobs::start(
			self::JOB,
			array(
				'source' => $id,
				'after'  => 0,
				'csv'    => array( 'offset' => 0 ),
				'rules'  => 'server' === $id ? self::server_rules( $text ) : array(),
				'counts' => array(
					'new'     => 0,
					'replace' => 0,
					'same'    => 0,
					'refused' => 0,
				),
			)
		);
	}

	/**
	 * One step of an import.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$id     = (string) ( $state['source'] ?? '' );
		$counts = (array) ( $state['counts'] ?? array() );
		$origin = 0 === strpos( $id, 'csv:' ) ? 'csv' : ( 'server' === $id ? 'import-server' : 'import-' . $id );
		$done   = false;
		Index::defer();
		while ( ! $done && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			if ( 'server' === $id ) {
				$rules = (array) ( $state['rules'] ?? array() );
				foreach ( array_splice( $rules, 0, self::BATCH ) as $rule ) {
					$kind            = self::in( (array) $rule, $origin, false );
					$counts[ $kind ] = (int) ( $counts[ $kind ] ?? 0 ) + 1;
				}
				$state['rules'] = $rules;
				$done           = array() === $rules;
				continue;
			}
			if ( 0 === strpos( $id, 'csv:' ) ) {
				$page         = self::csv_page( substr( $id, 4 ), (array) ( $state['csv'] ?? array() ) );
				$state['csv'] = $page['state'];
				$done         = $page['done'];
				$rows         = $page['rows'];
			} else {
				$page           = self::read( $id, (int) ( $state['after'] ?? 0 ) );
				$done           = (int) ( $state['after'] ?? 0 ) === $page['next'];
				$state['after'] = $page['next'];
				$rows           = $page['rows'];
			}
			foreach ( $rows as $rule ) {
				$kind            = self::in( $rule, $origin, false );
				$counts[ $kind ] = (int) ( $counts[ $kind ] ?? 0 ) + 1;
			}
		}
		Index::flush();
		$state['counts'] = $counts;
		if ( $done ) {
			if ( 0 === strpos( $id, 'csv:' ) ) {
				wp_delete_file( dirname( Index::dir( true ) ) . '/' . preg_replace( '/[^a-z0-9]/', '', substr( $id, 4 ) ) . '.csv' );
			}
			update_option(
				'zseo_redirects_imported',
				array(
					'at'     => time(),
					'source' => $id,
					'counts' => $counts,
				),
				false
			);
		}

		return array(
			'state'    => $state,
			'done'     => $done,
			'progress' => array(
				'done'  => array_sum( array_map( 'intval', $counts ) ),
				'total' => null,
			),
		);
	}
}
