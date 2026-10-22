<?php
/**
 * The side-by-side check: every page as the other plugin renders it against what we would print
 * (zs-260, zs-261, "import, then switch the old one off").
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Bulk\History;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;
use ZinnDigital\ZinnSEO\Templates\Vars;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ The other plugin is still active and we are standing down, so a fetched page IS its head.
 * Beside it we put the head we would print (Signature::ours), field by field (the equality
 * contract in Signature). Then, per page:
 *
 * - equal: nothing to do;
 * - different, and "keep exactly what it showed" is on: the value it rendered is written to the
 *   page's own field — a title it built from its template, a description it cut from the text —
 *   in a SECOND history batch, so "let Zinn® SEO write these itself" is one undo away;
 * - still different: listed, with both values. The switch-off stays closed until that list is
 *   empty or a person has read and accepted it.
 *
 * ⛔⛔ EVERY published page and every term archive is checked — no sample, no cap (CLAUDE.md
 * §2.10): the job works until its deadline, saves its cursor after each page, and carries on in
 * the next step. Only the LIST shown is bounded (the first 500 differences; the count is all).
 *
 * A spread of the pages is kept with the other plugin's signature (the baseline). After the
 * switch-off the same addresses are fetched again — now rendered by this plugin — and compared
 * with it; that proves the worked-out head and the rendered head are the same thing.
 */
final class Check {

	/** The job. */
	public const JOB = 'import-check';

	/** Non-autoloaded: the first differences found. */
	public const DIFFS = 'zseo_import_diffs';

	/** Non-autoloaded: the other plugin's signature of a spread of pages. */
	public const BASELINE = 'zseo_import_baseline';

	/** Differences listed (all are counted). */
	public const LISTED = 500;

	/** Pages kept in the baseline. */
	public const SPREAD = 200;

	/**
	 * Register the job.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Comparing every page with the other SEO plugin', 'zinn-seo' ), array( self::class, 'step' ) );
	}

	/**
	 * Why the check cannot run now, or ''.
	 *
	 * @param array<string, mixed> $state The move's state.
	 * @return string
	 */
	public static function refusal( array $state ): string {
		$source = Import::source( (string) $state['source'] );
		if ( null === $source ) {
			return __( 'Start with an import.', 'zinn-seo' );
		}
		if ( null === $state['applied'] ) {
			return __( 'Copy the data first: the comparison is of what is stored here.', 'zinn-seo' );
		}
		if ( array() === $source->active() ) {
			/* translators: %s: the name of another SEO plugin. */
			return sprintf( __( '%s is already switched off, so there is nothing of its to compare with.', 'zinn-seo' ), $source->name() );
		}
		// Keeping what the other plugin showed writes values: only on a copy proven stored.
		$proof = Verify::refusal( $state );
		if ( '' !== $proof ) {
			return $proof;
		}
		if ( $source->has_pages() && (bool) Settings::get( 'run_with_others', false ) ) {
			return __( 'Both plugins are printing their tags ("Print alongside other SEO plugins" is on). Switch that off first: with two sets of tags on a page there is nothing clean to compare.', 'zinn-seo' );
		}

		return '';
	}

	/**
	 * Start the check.
	 *
	 * @param bool $keep Keep exactly what the other plugin showed where it differs.
	 * @return true|\WP_Error
	 */
	public static function start( bool $keep ) {
		$state  = Import::state();
		$reason = self::refusal( $state );
		if ( '' !== $reason ) {
			return new \WP_Error( 'zinn_seo_check_refused', $reason, array( 'status' => 409 ) );
		}
		$source = Import::source( (string) $state['source'] );
		Import::history();
		History::maybe_install();
		/* translators: %s: the name of another SEO plugin. */
		History::open( 'post', sprintf( __( 'Kept as %s showed it', 'zinn-seo' ), $source->name() ), 'import' );
		$batch = History::suspend();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an index count, once per check.
		$posts          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'" );
		$state['check'] = array(
			'source'    => (string) $state['source'],
			'keep'      => $keep,
			'started'   => time(),
			'finished'  => 0,
			'pages'     => 0,
			'equal'     => 0,
			'kept'      => 0,
			'different' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'redirects' => array(
				'rules'     => 0,
				'equal'     => 0,
				'different' => 0,
			),
			'batch'     => (string) ( $batch['id'] ?? '' ),
			'accepted'  => 0,
			'error'     => '',
		);
		Import::put( $state );
		delete_option( self::DIFFS );
		delete_option( self::BASELINE );

		return Jobs::start(
			self::JOB,
			array(
				'phase'  => $source->has_pages() ? 'special' : 'redirects',
				'cursor' => 0,
				'batch'  => $batch,
				'stride' => max( 1, (int) floor( $posts / max( 1, self::SPREAD - 40 ) ) ),
				'seen'   => 0,
				'total'  => $posts,
				'fails'  => 0,
			)
		);
	}

	/**
	 * Fetch a page of this site as a visitor.
	 *
	 * @param string $url Address.
	 * @return array{code: int, html: string, error: string, location: string}
	 */
	public static function fetch( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
				'headers'     => array(
					'Accept'        => 'text/html',
					'Cache-Control' => 'no-cache',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'code'     => 0,
				'html'     => '',
				'error'    => $response->get_error_message(),
				'location' => '',
			);
		}
		$location = wp_remote_retrieve_header( $response, 'location' );

		return array(
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'html'     => (string) wp_remote_retrieve_body( $response ),
			'error'    => '',
			'location' => is_array( $location ) ? (string) reset( $location ) : (string) $location,
		);
	}

	/**
	 * The pages that are not a post or a term: home, post type archives, a date archive, a
	 * search, a missing page.
	 *
	 * @return array<int, array{key: string, url: string}>
	 */
	public static function special(): array {
		$out = array();
		if ( 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) < 1 ) {
			$out[] = array(
				'key' => 'home',
				'url' => home_url( '/' ),
			);
		}
		foreach ( get_post_types(
			array(
				'public'      => true,
				'has_archive' => true,
			)
		) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( is_string( $link ) && '' !== $link ) {
				$out[] = array(
					'key' => 'archive:' . $type,
					'url' => $link,
				);
			}
		}
		$newest = get_posts(
			array(
				'numberposts'      => 1,
				'post_type'        => 'post',
				'post_status'      => 'publish',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- see the code above.
				'suppress_filters' => true,
			)
		);
		if ( isset( $newest[0] ) ) {
			$y = (int) get_the_date( 'Y', $newest[0] );
			$m = (int) get_the_date( 'n', $newest[0] );
			$d = (int) get_the_date( 'j', $newest[0] );
			// The three kinds of date archive: each has its own way of writing its date.
			$out[] = array(
				'key' => 'date:' . $y . ':' . $m,
				'url' => get_month_link( $y, $m ),
			);
			$out[] = array(
				'key' => 'date:' . $y,
				'url' => get_year_link( $y ),
			);
			$out[] = array(
				'key' => 'date:' . $y . ':' . $m . ':' . $d,
				'url' => get_day_link( $y, $m, $d ),
			);
			$word  = '';
			if ( 1 === preg_match( '/[\p{L}]{3,}/u', (string) $newest[0]->post_title, $w ) ) {
				$word = mb_strtolower( $w[0] );
			}
			if ( '' !== $word ) {
				$out[] = array(
					'key' => 'search:' . $word,
					'url' => home_url( '/?s=' . rawurlencode( $word ) ),
				);
			}
		}
		$out[] = array(
			'key' => '404',
			'url' => home_url( '/zd-' . strtolower( wp_generate_password( 12, false, false ) ) . '/' ),
		);

		return $out;
	}

	/**
	 * The context of a special page.
	 *
	 * @param string $key Key from special(), or `author:<id>`.
	 * @return Context|null
	 */
	public static function context( string $key ): ?Context {
		$parts = explode( ':', $key );
		switch ( $parts[0] ) {
			case 'home':
				return Context::for_key( 'home' );
			case 'archive':
				return Context::for_key( $key );
			case '404':
				return Context::for_key( '404' );
			case 'date':
				$c      = Context::for_key( 'date' );
				$c->ymd = array( (int) ( $parts[1] ?? 0 ), (int) ( $parts[2] ?? 0 ), (int) ( $parts[3] ?? 0 ) );
				return $c;
			case 'search':
				$c         = Context::for_key( 'search' );
				$c->search = (string) ( $parts[1] ?? '' );
				return $c;
			case 'author':
				$user = get_userdata( (int) ( $parts[1] ?? 0 ) );
				if ( ! $user instanceof \WP_User ) {
					return null;
				}
				$c         = Context::for_key( 'author' );
				$c->object = $user;
				return $c;
		}

		return null;
	}

	/**
	 * Make sure the graph a page prints is the graph this request works out (the site's nodes
	 * join it on a visitor's page, in REST and in WP-CLI; a job step runs in none of those).
	 *
	 * @return void
	 */
	public static function front_filters(): void {
		$schema = '\ZinnDigital\ZinnSEO\Schema\Schema';
		if ( class_exists( $schema ) && false === has_filter( 'zinn_seo_graph', array( $schema, 'graph' ) ) ) {
			add_filter( 'zinn_seo_graph', array( $schema, 'graph' ), 15, 2 );
		}
		$social = '\ZinnDigital\ZinnSEO\Social\Social';
		if ( class_exists( $social ) && false === has_filter( 'zinn_seo_graph', array( $social, 'graph' ) ) ) {
			add_filter( 'zinn_seo_graph', array( $social, 'graph' ), 10, 2 );
		}
	}

	/**
	 * One step.
	 *
	 * @param array<string, mixed> $run      Job state.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When nothing can be compared (the job is marked failed with the reason).
	 */
	public static function step( array $run, float $deadline ): array {
		$state = Import::state();
		$check = (array) $state['check'];
		$src   = Import::source( (string) ( $check['source'] ?? '' ) );
		if ( null === $src || ! Import::history() ) {
			throw new \RuntimeException( esc_html__( 'The plugin this comparison reads from is no longer known here.', 'zinn-seo' ) );
		}
		// The checkpoint saved with the counters after every group of pages is newer than the
		// job's own state when a step was killed: counters and cursor always move together.
		if ( is_array( $check['run'] ?? null ) ) {
			$run = $check['run'];
		}
		self::front_filters();
		$done = false;
		// Only the `check` part is this job's: a kept template changed the settings part meanwhile.
		$save = static function () use ( &$check, &$run ): void {
			$check['run']   = $run;
			$fresh          = Import::state();
			$fresh['check'] = $check;
			Import::put( $fresh );
		};
		while ( ! $done && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			switch ( (string) $run['phase'] ) {
				case 'special':
					foreach ( self::special() as $page ) {
						$c = self::context( $page['key'] );
						if ( null !== $c ) {
							self::page( $run, $check, $src, $c, $page['url'], 'special', 0, $page['key'], true );
						}
					}
					$run['phase']  = 'posts';
					$run['cursor'] = 0;
					break;
				case 'posts':
					$ids = self::post_ids( (int) $run['cursor'] );
					if ( array() === $ids ) {
						$run['phase']  = 'terms';
						$run['cursor'] = 0;
						break;
					}
					foreach ( $ids as $id ) {
						$post = get_post( $id );
						if ( $post instanceof \WP_Post ) {
							self::page( $run, $check, $src, Context::for_post( $post ), (string) get_permalink( $post ), 'post', $id, '', 0 === $id % (int) $run['stride'] );
						}
						$run['cursor'] = $id;
						++$run['seen'];
						if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
							break;
						}
					}
					break;
				case 'terms':
					$rows = self::term_rows( (int) $run['cursor'] );
					if ( array() === $rows ) {
						$run['phase']  = 'authors';
						$run['cursor'] = 0;
						break;
					}
					foreach ( $rows as $row ) {
						$term = get_term( (int) $row->term_id, (string) $row->taxonomy );
						if ( $term instanceof \WP_Term ) {
							$link = get_term_link( $term );
							if ( is_string( $link ) ) {
								self::page( $run, $check, $src, Context::for_term( $term ), $link, 'term', (int) $term->term_id, '', (int) ( $run['spread_terms'] ?? 0 ) < 30 );
								$run['spread_terms'] = (int) ( $run['spread_terms'] ?? 0 ) + 1;
							}
						}
						$run['cursor'] = (int) $row->term_taxonomy_id;
						if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
							break;
						}
					}
					break;
				case 'authors':
					$authors = self::author_ids( (int) $run['cursor'] );
					if ( array() === $authors ) {
						$run['phase']  = 'redirects';
						$run['cursor'] = 0;
						break;
					}
					foreach ( $authors as $author ) {
						$c = self::context( 'author:' . $author );
						if ( null !== $c ) {
							self::page( $run, $check, $src, $c, get_author_posts_url( $author ), 'special', 0, 'author:' . $author, (int) ( $run['spread_authors'] ?? 0 ) < 10 );
							$run['spread_authors'] = (int) ( $run['spread_authors'] ?? 0 ) + 1;
						}
						$run['cursor'] = $author;
						if ( \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
							break;
						}
					}
					break;
				case 'redirects':
					$more = self::redirects( $run, $check, $src );
					if ( ! $more ) {
						$run['phase'] = 'done';
					}
					break;
				default:
					$done = true;
			}
			$save();
			// A site that cannot reach its own pages: stop and say so rather than list every page.
			if ( (int) $run['fails'] >= 5 && 0 === (int) $check['equal'] + (int) $check['kept'] + (int) $check['different'] ) {
				$check['error'] = (string) ( $run['last_error'] ?? '' );
				$save();
				/* translators: %s: the error message of a failed request. */
				throw new \RuntimeException( esc_html( sprintf( __( 'This site could not fetch its own pages, so nothing could be compared: %s', 'zinn-seo' ), (string) ( $run['last_error'] ?? '' ) ) ) );
			}
		}
		if ( $done ) {
			$written = Import::written( (string) ( $run['batch']['id'] ?? '' ) );
			History::resume( array_merge( (array) $run['batch'], array( 'count' => array_sum( $written['fields'] ) ) ) );
			History::close();
			$check['kept']     = array_sum( $written['fields'] ) + (int) ( $check['kept_settings'] ?? 0 );
			$check['finished'] = time();
			$save();
			unset( $check['run'] );
			$fresh          = Import::state();
			$fresh['check'] = $check;
			Import::put( $fresh );

			/**
			 * Fires when the side-by-side comparison has finished.
			 *
			 * @param array $check Its result.
			 */
			do_action( 'zinn_seo_import_checked', $check );
		}

		return array(
			'state'    => $run,
			'done'     => $done,
			'progress' => array(
				'done'  => (int) $run['seen'],
				'total' => (int) $run['total'],
			),
		);
	}

	/**
	 * Published posts of the types a visitor can open, after a cursor.
	 *
	 * @param int $after Cursor.
	 * @return array<int, int>
	 */
	public static function post_ids( int $after ): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( ! is_post_type_viewable( $type ) || ( 'attachment' === $type && ! \ZinnDigital\ZinnSEO\Templates\Templates::attachment_pages() ) ) {
				continue;
			}
			$types[] = $type;
		}
		if ( array() === $types ) {
			return array();
		}
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset on the primary key; $in is a list of registered type names escaped above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_password = '' AND post_type IN ({$in}) ORDER BY ID ASC LIMIT 20", $after ) ) );
	}

	/**
	 * Terms with posts in the taxonomies a visitor can open, after a cursor.
	 *
	 * @param int $after Cursor (term_taxonomy_id).
	 * @return array<int, object>
	 */
	public static function term_rows( int $after ): array {
		$taxes = array();
		foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
			if ( 'post_format' !== $tax && is_taxonomy_viewable( $tax ) ) {
				$taxes[] = $tax;
			}
		}
		if ( array() === $taxes ) {
			return array();
		}
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', $taxes ) ) . "'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset on the primary key; $in is a list of registered taxonomy names escaped above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d AND count > 0 AND taxonomy IN ({$in}) ORDER BY term_taxonomy_id ASC LIMIT 20", $after ) );
	}

	/**
	 * Authors with a published post, after a cursor.
	 *
	 * @param int $after Cursor (user id).
	 * @return array<int, int>
	 */
	public static function author_ids( int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset over the author index.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_author > %d AND post_status = 'publish' AND post_type = 'post' ORDER BY post_author ASC LIMIT 20", $after ) ) );
	}

	/**
	 * Compare one page, keep what it showed when asked, record what still differs.
	 *
	 * @param array<string, mixed> $run    Job state (by reference: the keep batch, failures).
	 * @param array<string, mixed> $check  The result so far (by reference).
	 * @param Source               $src    Source.
	 * @param Context              $c      The page.
	 * @param string               $url    Its address.
	 * @param string               $kind   post|term|special.
	 * @param int                  $id     Object id (0 for special pages).
	 * @param string               $key    Special key.
	 * @param bool                 $spread Keep it in the baseline.
	 * @return void
	 */
	public static function page( array &$run, array &$check, Source $src, Context $c, string $url, string $kind, int $id, string $key, bool $spread ): void {
		$got = self::fetch( $url );
		if ( 0 === $got['code'] || $got['code'] >= 500 ) {
			++$check['failed'];
			++$run['fails'];
			$run['last_error'] = '' !== $got['error'] ? $got['error'] : 'HTTP ' . $got['code'];
			return;
		}
		// A refusal is not a page with a head (a membership plugin refuses a visitor): nothing to
		// compare.
		if ( in_array( $got['code'], array( 401, 403 ), true ) || ( 404 === $got['code'] && '404' !== $c->kind ) ) {
			++$check['skipped'];
			return;
		}
		// The other plugin sends this address elsewhere (an archive it switched off, a redirect
		// set on the page). Equal when we redirect it too, or keep it out of search results;
		// an indexable page appearing where there was a redirect is a difference.
		if ( $got['code'] >= 300 && $got['code'] < 400 ) {
			++$check['pages'];
			$serve = '\ZinnDigital\ZinnSEO\Redirects\Serve';
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( null !== wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
			$mine  = class_exists( $serve ) && \ZinnDigital\ZinnSEO\Redirects\Store::ready() ? $serve::match( $path, true ) : null;
			Resolver::flush();
			if ( is_array( $mine ) || in_array( 'noindex', (array) Signature::ours( $c )['robots'], true ) ) {
				++$check['equal'];
				return;
			}
			++$check['different'];
			$list = get_option( self::DIFFS, array() );
			$list = is_array( $list ) ? $list : array();
			if ( isset( $list[ $url ] ) || count( $list ) < self::LISTED ) {
				$list[ $url ] = array(
					'kind'   => $kind,
					'id'     => $id,
					'key'    => $key,
					'url'    => $url,
					'fields' => array( 'redirected' => array( (string) $got['location'], '' ) ),
				);
				update_option( self::DIFFS, $list, false );
			}
			return;
		}
		++$check['pages'];
		$theirs = Signature::from_html( $got['html'] );
		Resolver::flush();
		$diff = Signature::diff( $theirs, Signature::ours( $c ), $c->kind, $url );
		// Twice: what X shows falls back to the shared value, so its own difference is only
		// visible once the shared one has been settled.
		for ( $pass = 0; $pass < 2 && array() !== $diff && ! empty( $check['keep'] ); $pass++ ) {
			if ( ! self::keep( $run, $check, $c, $kind, $id, $key, $theirs, $diff ) ) {
				break;
			}
			Resolver::flush();
			$diff = Signature::diff( $theirs, Signature::ours( 'special' === $kind ? ( self::context( $key ) ?? $c ) : $c ), $c->kind, $url );
		}
		if ( array() === $diff ) {
			++$check['equal'];
		} else {
			++$check['different'];
			$list = get_option( self::DIFFS, array() );
			$list = is_array( $list ) ? $list : array();
			if ( isset( $list[ $url ] ) || count( $list ) < self::LISTED ) {
				$list[ $url ] = array(
					'kind'   => $kind,
					'id'     => $id,
					'key'    => $key,
					'url'    => $url,
					'fields' => $diff,
				);
				update_option( self::DIFFS, $list, false );
			}
		}
		if ( $spread ) {
			$base = get_option( self::BASELINE, array() );
			$base = is_array( $base ) ? $base : array();
			if ( isset( $base[ $url ] ) || count( $base ) < self::SPREAD ) {
				unset( $theirs['doubled'] );
				// One address can be read twice — WooCommerce's shop is a page AND the products'
				// archive: a difference listed by either reading stays open (the second used to
				// clear the first's, and the switch-off then rolled back on a difference a person
				// had accepted; importer certification, queue Q254).
				$open         = array_values( array_unique( array_merge( (array) ( $base[ $url ]['open'] ?? array() ), array_keys( $diff ) ) ) );
				$base[ $url ] = array(
					'url'    => $url,
					'page'   => $c->kind,
					'theirs' => $theirs,
					'open'   => $open,
				);
				update_option( self::BASELINE, $base, false );
			}
		}
	}

	/**
	 * Text that can be stored as a literal: it must not read as one of our variables.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function literal( string $text ): bool {
		return array() === Vars::used( $text );
	}

	/**
	 * Write what the other plugin showed into the page's own fields (or, for the home page, a
	 * post type archive and the not-found page, into that template).
	 *
	 * @param array<string, mixed>                     $run    Job state (by reference: the batch).
	 * @param array<string, mixed>                     $check  Result (by reference).
	 * @param Context                                  $c      The page.
	 * @param string                                   $kind   post|term|special.
	 * @param int                                      $id     Object id.
	 * @param string                                   $key    Special key.
	 * @param array<string, mixed>                     $theirs Their signature.
	 * @param array<string, array{0: mixed, 1: mixed}> $diff   What differs.
	 * @return bool Whether anything was written.
	 */
	public static function keep( array &$run, array &$check, Context $c, string $kind, int $id, string $key, array $theirs, array $diff ): bool {
		$set = self::kept_values( $theirs, $diff, $c );
		if ( 'special' === $kind ) {
			// One address per template only: a literal title is wrong for every other author,
			// month or search.
			if ( ! in_array( $c->kind, array( 'home', 'archive', '404' ), true ) ) {
				return false;
			}
			$record = array_intersect_key(
				array(
					'title'    => $set['title'] ?? null,
					'desc'     => $set['desc'] ?? null,
					'og_title' => $set['og_title'] ?? null,
					'og_desc'  => $set['og_desc'] ?? null,
					'og_image' => $set['og_image'] ?? null,
				),
				$set
			);
			if ( array() === $record ) {
				return false;
			}
			$n                      = Switcher::write_template( $c->key, $record );
			$check['kept_settings'] = (int) ( $check['kept_settings'] ?? 0 ) + $n;

			return $n > 0;
		}
		$defs   = Meta::fields();
		$writes = array();
		foreach ( $set as $name => $value ) {
			$def = $defs[ $name ] ?? null;
			if ( null === $def || ! in_array( $kind, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
				continue;
			}
			$clean = Meta::sanitize( $def, $value );
			if ( is_wp_error( $clean ) ) {
				continue;
			}
			$current = 'post' === $kind ? Meta::get_post( $id, (string) $name ) : Meta::get_term( $id, (string) $name );
			if ( ! History::same( $current, $clean ) ) {
				$writes[] = array( $id, (string) $name, $current, $clean );
			}
		}
		if ( array() === $writes ) {
			return false;
		}
		$run['batch'] = Import::write( (array) $run['batch'], $kind, $writes );
		wp_cache_delete( $id, 'post' === $kind ? 'post_meta' : 'term_meta' );

		return true;
	}

	/**
	 * The field values that would make our head say what theirs said: our field name => value.
	 * A field with no way to say it (an image that is not in the media library, a canonical
	 * that must be absent) is left out and stays a difference.
	 *
	 * @param array<string, mixed>                     $theirs Their signature.
	 * @param array<string, array{0: mixed, 1: mixed}> $diff   What differs.
	 * @param Context                                  $c      The page.
	 * @return array<string, mixed>
	 */
	public static function kept_values( array $theirs, array $diff, Context $c ): array {
		$set = array();
		if ( isset( $diff['title'] ) && '' !== (string) $theirs['title'] && self::literal( (string) $theirs['title'] ) ) {
			$set['title'] = (string) $theirs['title'];
		}
		if ( isset( $diff['description'] ) ) {
			if ( '' === (string) $theirs['description'] ) {
				$set['desc'] = Vars::NONE;
			} elseif ( self::literal( (string) $theirs['description'] ) ) {
				$set['desc'] = (string) $theirs['description'];
			}
		}
		if ( isset( $diff['canonical'] ) ) {
			// Theirs named another address: store it. Theirs named none (or its own) while this
			// page's own field names another: clear the field.
			$set['canonical'] = (string) $theirs['canonical'];
		}
		if ( isset( $diff['robots'] ) ) {
			$set += self::kept_robots( (array) $theirs['robots'], (array) $diff['robots'][1], $c );
		}
		if ( isset( $diff['og_title'] ) && '' !== (string) $theirs['og_title'] && self::literal( (string) $theirs['og_title'] ) ) {
			$set['og_title'] = (string) $theirs['og_title'];
		}
		if ( isset( $diff['og_description'] ) ) {
			if ( '' === (string) $theirs['og_description'] ) {
				$set['og_desc'] = Vars::NONE;
			} elseif ( self::literal( (string) $theirs['og_description'] ) ) {
				$set['og_desc'] = (string) $theirs['og_description'];
			}
		}
		if ( isset( $diff['og_image'] ) && '' !== (string) $theirs['og_image'] ) {
			$image = Source::image_id( (string) $theirs['og_image'] );
			if ( $image > 0 ) {
				$set['og_image'] = $image;
			}
		}
		// X: only where its own value is not simply the shared one.
		if ( isset( $diff['x_title'] ) && ! isset( $diff['og_title'] ) && '' !== (string) $theirs['x_title'] && self::literal( (string) $theirs['x_title'] ) ) {
			$set['x_title'] = (string) $theirs['x_title'];
		}
		if ( isset( $diff['x_description'] ) && ! isset( $diff['og_description'] ) && '' !== (string) $theirs['x_description'] && self::literal( (string) $theirs['x_description'] ) ) {
			$set['x_desc'] = (string) $theirs['x_description'];
		}
		if ( isset( $diff['x_image'] ) && ! isset( $diff['og_image'] ) && '' !== (string) $theirs['x_image'] ) {
			$image = Source::image_id( (string) $theirs['x_image'] );
			if ( $image > 0 ) {
				$set['x_image'] = $image;
			}
		}
		if ( isset( $diff['types'] ) && $c->post() ) {
			$schema = '\ZinnDigital\ZinnSEO\Schema\Schema';
			foreach ( (array) $diff['types'][0] as $type ) {
				if ( class_exists( $schema ) && in_array( $type, $schema::ARTICLE_TYPES, true ) ) {
					$set['schema_article'] = $type;
				} elseif ( class_exists( $schema ) && 'WebPage' !== $type && in_array( $type, $schema::PAGE_TYPES, true ) ) {
					$set['schema_page'] = $type;
				}
			}
		}

		return $set;
	}

	/**
	 * The per-page robots fields that turn our directives into theirs.
	 *
	 * @param array<int, string> $theirs Their directives.
	 * @param array<int, string> $ours   Ours.
	 * @param Context            $c      The page.
	 * @return array<string, mixed>
	 */
	public static function kept_robots( array $theirs, array $ours, Context $c ): array {
		$own  = (array) Resolver::own( $c, 'robots' );
		$list = array_values( array_diff( $own, array( 'index', 'follow' ) ) );
		foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) as $flag ) {
			$has = in_array( $flag, $theirs, true );
			if ( $has && ! in_array( $flag, $list, true ) ) {
				$list[] = $flag;
			} elseif ( ! $has ) {
				$list = array_values( array_diff( $list, array( $flag ) ) );
			}
		}
		// A type set to noindex with this page indexed (or the other way round for follow).
		if ( ! in_array( 'noindex', $theirs, true ) && in_array( 'noindex', $ours, true ) ) {
			$list[] = 'index';
		}
		if ( ! in_array( 'nofollow', $theirs, true ) && in_array( 'nofollow', $ours, true ) ) {
			$list[] = 'follow';
		}
		$set = array( 'robots' => array_values( array_unique( $list ) ) );
		foreach ( array(
			'max-snippet'       => 'max_snippet',
			'max-image-preview' => 'max_image',
			'max-video-preview' => 'max_video',
		) as $directive => $field ) {
			$value = '';
			foreach ( $theirs as $part ) {
				if ( 0 === strpos( $part, $directive . ':' ) ) {
					$value = substr( $part, strlen( $directive ) + 1 );
				}
			}
			// Their limit; or, where they print none, the page's own limit cleared so the
			// site's default (which states no limit) applies again.
			if ( '' !== $value || '' !== (string) Resolver::own( $c, $field ) ) {
				$set[ $field ] = $value;
			}
		}

		return $set;
	}

	/**
	 * Compare the other plugin's redirects with ours, a page of its rules at a time: every rule
	 * it holds must be a rule here with the same target and code.
	 *
	 * @param array<string, mixed> $run   Job state (by reference).
	 * @param array<string, mixed> $check Result (by reference).
	 * @param Source               $src   Source.
	 * @return bool Whether more rules remain.
	 */
	public static function redirects( array &$run, array &$check, Source $src ): bool {
		$rid      = $src->redirects();
		$importer = '\ZinnDigital\ZinnSEO\Redirects\Importer';
		$store    = '\ZinnDigital\ZinnSEO\Redirects\Store';
		if ( '' === $rid || ! class_exists( $importer ) || ! class_exists( $store ) || $importer::count( $rid ) < 1 ) {
			return false;
		}
		$page = $importer::read( $rid, (int) $run['cursor'] );
		foreach ( (array) $page['rows'] as $rule ) {
			++$check['redirects']['rules'];
			$why = '';
			if ( ! empty( $rule['inert'] ) ) {
				// A rule that never answered a request under the other plugin: not having it here is
				// the same behaviour, so it is not a difference (the copy still reports it, with why).
				++$check['redirects']['equal'];
				continue;
			}
			if ( isset( $rule['refused'] ) ) {
				$why = (string) $rule['refused'];
			} else {
				if ( 410 === (int) ( $rule['code'] ?? 0 ) || 451 === (int) ( $rule['code'] ?? 0 ) ) {
					$rule['target'] = '';
				}
				$clean = $store::clean( $rule );
				if ( is_wp_error( $clean ) ) {
					$why = $clean->get_error_message();
				} else {
					$mine = $store::by_hash( (string) $clean['hash'] );
					if ( null === $mine ) {
						$why = __( 'This redirect is not here.', 'zinn-seo' );
					} elseif ( (string) $mine['target'] !== (string) $clean['target'] || (int) $mine['code'] !== (int) $clean['code'] ) {
						/* translators: 1: an address, 2: an HTTP status code. */
						$why = sprintf( __( 'Here it goes to %1$s (%2$d).', 'zinn-seo' ), (string) $mine['target'], (int) $mine['code'] );
					} elseif ( empty( $mine['enabled'] ) && ! empty( $clean['enabled'] ) ) {
						$why = __( 'Here it is switched off.', 'zinn-seo' );
					}
				}
			}
			if ( '' === $why ) {
				++$check['redirects']['equal'];
				// A spread of the plain rules is read again after the switch-off: the address
				// must then answer with this code and this destination.
				if ( empty( $rule['regex'] ) && (int) ( $run['spread_rules'] ?? 0 ) < 40 && isset( $mine['code'] ) && in_array( (int) $mine['code'], $store::MOVES, true ) ) {
					$from  = 1 === preg_match( '#^https?://#i', (string) $clean['source'] ) ? (string) $clean['source'] : home_url( '/' . ltrim( (string) $clean['source'], '/' ) );
					$match = \ZinnDigital\ZinnSEO\Redirects\Serve::match( '/' . ltrim( (string) wp_parse_url( $from, PHP_URL_PATH ), '/' ) . ( null !== wp_parse_url( $from, PHP_URL_QUERY ) ? '?' . wp_parse_url( $from, PHP_URL_QUERY ) : '' ), true );
					$base  = get_option( self::BASELINE, array() );
					$base  = is_array( $base ) ? $base : array();
					if ( is_array( $match ) && '' !== (string) $match['location'] && ( isset( $base[ $from ] ) || count( $base ) < self::SPREAD ) ) {
						$base[ $from ] = array(
							'url'    => $from,
							'page'   => 'redirect',
							'expect' => array(
								'code'     => (int) $match['code'],
								'location' => (string) $match['location'],
							),
						);
						update_option( self::BASELINE, $base, false );
						$run['spread_rules'] = (int) ( $run['spread_rules'] ?? 0 ) + 1;
					}
				}
				continue;
			}
			++$check['redirects']['different'];
			$list = get_option( self::DIFFS, array() );
			$list = is_array( $list ) ? $list : array();
			$at   = 'redirect:' . (string) ( $rule['source'] ?? '' );
			if ( isset( $list[ $at ] ) || count( $list ) < self::LISTED ) {
				$list[ $at ] = array(
					'kind'   => 'redirect',
					'id'     => 0,
					'key'    => '',
					'url'    => (string) ( $rule['source'] ?? '' ),
					'fields' => array( 'redirect' => array( trim( (string) ( $rule['target'] ?? '' ) . ' ' . (string) ( $rule['code'] ?? '' ) ), $why ) ),
				);
				update_option( self::DIFFS, $list, false );
			}
		}
		$more          = (int) $page['next'] !== (int) $run['cursor'];
		$run['cursor'] = (int) $page['next'];

		return $more;
	}

	/**
	 * Does the result allow the switch-off? Equal everywhere, or the differences accepted.
	 *
	 * @param array<string, mixed>|null $check Result.
	 * @return bool
	 */
	public static function passed( $check ): bool {
		if ( ! is_array( $check ) || 0 === (int) ( $check['finished'] ?? 0 ) ) {
			return false;
		}
		$open = (int) $check['different'] + (int) ( $check['redirects']['different'] ?? 0 ) + (int) $check['failed'];

		return 0 === $open || (int) $check['accepted'] > 0;
	}

	/**
	 * Accept the listed differences (a person read them).
	 *
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public static function accept() {
		$state = Import::state();
		if ( ! is_array( $state['check'] ) || 0 === (int) $state['check']['finished'] ) {
			return new \WP_Error( 'zinn_seo_no_check', __( 'Compare the pages first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$state['check']['accepted']    = time();
		$state['check']['accepted_by'] = get_current_user_id();
		Import::put( $state );

		return $state;
	}
}
