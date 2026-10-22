<?php
/**
 * The page as a visitor gets it, for the analysis (zs-002, zs-inn-06).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Other SEO plugins analyse the SAVED markup in the browser, where a block binding, a query loop,
 * a server-rendered block, a shortcode or a page builder's widget is still empty. Page Builder
 * Sandwich solved that for them (`/pbs/v1/seo/rendered`); this is the same idea, generalised and
 * on the server, where the checks run:
 *
 * - the editor's CURRENT content (or the saved content) is run through `the_content` exactly as
 *   the page shows it, with the post set up as the current post, so bindings and loops resolve;
 * - a page builder that keeps its layout outside `post_content` answers
 *   `zinn_seo_rendered_content` (Elementor does, stage P3: its SAVED layout, so Elementor's panel
 *   re-checks after each save);
 * - scripts, styles and templates are removed; the result is cached for ten minutes by the hash
 *   of what was rendered, so typing does not re-render the same text.
 *
 * ⛔ Admin, REST and CLI only — never a visitor's request.
 */
final class Render {

	/** Largest content rendered, in bytes (the editor sends the whole post). */
	public const MAX = 4194304;

	/**
	 * Inside a whole-site sweep (`sweeping( true )`): render, but read and keep no cached copy.
	 *
	 * @var bool
	 */
	private static bool $sweeping = false;

	/** The pages whose rendering ended the request: `{id: {m: modified, at, why}}` (Q335). */
	public const UNREADABLE = 'zseo_render_unreadable';

	/** Most pages kept in that record (the oldest go first); far above any real site's count. */
	private const UNREADABLE_MAX = 5000;

	/**
	 * The page being rendered now (0 none): what the shutdown guard records when the request
	 * ends inside a render.
	 *
	 * @var int
	 */
	private static int $rendering = 0;

	/**
	 * Output buffers open before the render, so the guard drops only what the page printed.
	 *
	 * @var int
	 */
	private static int $buffers = 0;

	/**
	 * The renders this one is inside: `[page, buffers]` of each (a page builder may render
	 * another page while one renders).
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	private static array $stack = array();

	/**
	 * Whether the shutdown guard is registered in this process.
	 *
	 * @var bool
	 */
	private static bool $guarded = false;

	/**
	 * The page whose render ended this request (0 none), once the guard has run.
	 *
	 * @var int
	 */
	private static int $died = 0;

	/**
	 * Pages refused in this process because they ended an earlier request, taken by the job
	 * that asked for them (`take_skipped()`).
	 *
	 * @var array<int, true>
	 */
	private static array $skipped = array();

	/**
	 * Pages recorded as ending the request that changed since, so they are read again: a job
	 * no longer counts them as unreadable (`take_cleared()`).
	 *
	 * @var array<int, true>
	 */
	private static array $cleared = array();

	/**
	 * A job that renders EVERY page once (the link index, the broken-link scan) says so here, so
	 * no copy is cached for each page. ⛔ Measured 2026-10-12 (stage T1, 1,000,000 posts): one
	 * link-index build left 2,000,004 rows (102 MB) of 10-minute transients in wp_options — a copy
	 * nobody would read again, on a table every request touches, until WP-cron happened to sweep
	 * it (never, on a host without cron).
	 *
	 * @param bool $on Whether a sweep is running.
	 * @return void
	 */
	public static function sweeping( bool $on ): void {
		self::$sweeping = $on;
	}

	/**
	 * The page rendered now, neither read from nor kept in the 10-minute copy: a check that a
	 * change shows (the content writer, Q328) must see the page as it is, not a copy made a
	 * moment before the change.
	 *
	 * @param \WP_Post $subject Post.
	 * @return string|\WP_Error
	 */
	public static function fresh( \WP_Post $subject ) {
		$was            = self::$sweeping;
		self::$sweeping = true;
		try {
			return self::html( $subject );
		} finally {
			self::$sweeping = $was;
		}
	}

	/**
	 * Rendered HTML of a post.
	 *
	 * @param \WP_Post    $subject The post (a copy carrying the editor's unsaved title etc.).
	 * @param string|null $content The editor's current content; null for the saved content.
	 * @return string|\WP_Error
	 */
	public static function html( \WP_Post $subject, ?string $content = null ) {
		$content = null === $content ? (string) $subject->post_content : $content;
		if ( strlen( $content ) > self::MAX ) {
			return new \WP_Error( 'zinn_seo_too_large', __( 'The page is too large to analyse.', 'zinn-seo' ), array( 'status' => 413 ) );
		}
		$refused = self::refused( $subject );
		if ( null !== $refused ) {
			return $refused;
		}
		$key = 'zseo_r_' . md5( $subject->ID . '|' . $subject->post_title . '|' . $content . '|' . self::builder_stamp( $subject ) );
		// A sweep neither reads nor keeps a copy: each page is rendered once, and every missed
		// read grew the process by ~0.4 KB that no cache flush gave back (measured, T1).
		$cached = self::$sweeping ? false : get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		global $post;
		$previous           = $post;
		$copy               = clone $subject;
		$copy->post_content = $content;
		$post               = $copy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below; the_content filters read the current post.
		setup_postdata( $copy );
		$html = '';
		// ⛔ Q335 (zinnhub ZH7-1): a shortcode that sends guests to a login page and then exits
		// ended every job step that read its page — the job stayed pending for ever, its lock
		// held. Its redirect is refused here, and if it exits (or dies) anyway, the shutdown
		// guard records the page so it is never rendered in a sweep again until it changes.
		self::begin( $subject );
		ob_start();
		try {
			/**
			 * Filters the rendered content of a post for the analysis, before WordPress's own
			 * filters (a page builder returns its own HTML here; Elementor does).
			 *
			 * @param string   $content Content.
			 * @param \WP_Post $post    Post.
			 */
			$given = apply_filters( 'zinn_seo_rendered_content', $content, $copy );
			$html  = $given !== $content ? (string) $given : (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter: the page as visitors get it.
		} catch ( \Throwable $e ) {
			$html = do_blocks( $content );
		} finally {
			// A shortcode that echoes instead of returning still belongs to the page.
			$echoed = (string) ob_get_clean();
			self::end();
			$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
			if ( $previous instanceof \WP_Post ) {
				setup_postdata( $previous );
			} else {
				wp_reset_postdata();
			}
		}
		$html = $echoed . $html;
		$html = (string) preg_replace( '#<(script|style|template|noscript)\b.*?</\1>#is', '', $html );
		if ( ! self::$sweeping ) {
			set_transient( $key, $html, 10 * MINUTE_IN_SECONDS );
		}

		return $html;
	}

	/**
	 * `wp_redirect` while a page renders for us: refused. The request is ours (a job step, the
	 * editor's check), never the visitor's, so a page that would send a visitor elsewhere must
	 * not send our request elsewhere.
	 *
	 * @param mixed $location Where to.
	 * @return false
	 */
	public static function refuse_redirect( $location ): bool {
		unset( $location );

		return false;
	}

	/**
	 * A page starts rendering for us (Q335): its redirects are refused, and the shutdown guard
	 * knows which page to blame if the request ends before `end()`. Anything else that renders
	 * a page's content outside `html()` (llms-full.txt) brackets it the same way, after asking
	 * `refused()`.
	 *
	 * @param \WP_Post $subject Post.
	 * @return void
	 */
	public static function begin( \WP_Post $subject ): void {
		self::$stack[]   = array( self::$rendering, self::$buffers );
		self::$rendering = (int) $subject->ID;
		self::$buffers   = ob_get_level();
		self::guard();
		if ( 1 === count( self::$stack ) ) {
			add_filter( 'wp_redirect', array( self::class, 'refuse_redirect' ), PHP_INT_MAX );
		}
	}

	/**
	 * The page rendered: back to whatever rendered around it.
	 *
	 * @return void
	 */
	public static function end(): void {
		$outer = array_pop( self::$stack );
		if ( ! is_array( $outer ) ) {
			return;
		}
		self::$rendering = (int) $outer[0];
		self::$buffers   = (int) $outer[1];
		if ( array() === self::$stack ) {
			remove_filter( 'wp_redirect', array( self::class, 'refuse_redirect' ), PHP_INT_MAX );
		}
	}

	/**
	 * Register the shutdown guard once per process.
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( self::$guarded ) {
			return;
		}
		self::$guarded = true;
		// Q366: in WordPress's `shutdown` action first (right after the jobs' own guard), while a
		// persistent object cache still takes writes; the registered function is the net.
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( self::class, 'on_shutdown' ), PHP_INT_MIN );
		}
		register_shutdown_function( array( self::class, 'on_shutdown' ) );
	}

	/**
	 * The request is ending. Inside a render, the page ended it (an `exit` after a redirect, a
	 * `die()`, a fatal error): what it printed is dropped, and the page is recorded with why, so
	 * the next step skips it with a named reason instead of dying on it again. Safe to call more
	 * than once (the jobs' own guard calls it first).
	 *
	 * @return int The page that ended the request (0: none).
	 */
	public static function on_shutdown(): int {
		if ( 0 === self::$rendering ) {
			return self::$died;
		}
		$id              = self::$rendering;
		self::$rendering = 0;
		self::$died      = $id;
		while ( ob_get_level() > self::$buffers && ob_end_clean() ) {
			// The page's own output (and ours): nothing of it reaches whoever asked.
			continue;
		}
		$last  = error_get_last();
		$fatal = is_array( $last ) && in_array( (int) $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		self::remember( $id, $fatal ? 'fatal' : 'exit' );

		return $id;
	}

	/**
	 * The page to blame for a request that is ending now: the one being rendered, or the one
	 * whose render already ended it (0: none). Changes nothing, so the jobs' shutdown guard can
	 * ask it before anything else (Q349).
	 *
	 * @return int
	 */
	public static function culprit(): int {
		return self::$rendering > 0 ? self::$rendering : self::$died;
	}

	/**
	 * The page whose render ended this request (0: none, or not ended yet).
	 *
	 * @return int
	 */
	public static function died(): int {
		return self::$died;
	}

	/**
	 * Record a page that ended a request while it rendered.
	 *
	 * @param int    $id  Post.
	 * @param string $why exit|fatal.
	 * @return void
	 */
	public static function remember( int $id, string $why ): void {
		$all = get_option( self::UNREADABLE, array() );
		$all = is_array( $all ) ? $all : array();
		$p   = get_post( $id );
		unset( $all[ $id ] );
		$all[ $id ] = array(
			'm'   => $p instanceof \WP_Post ? (string) $p->post_modified_gmt : '',
			'at'  => time(),
			'why' => $why,
		);
		if ( count( $all ) > self::UNREADABLE_MAX ) {
			$all = array_slice( $all, -self::UNREADABLE_MAX, null, true );
		}
		update_option( self::UNREADABLE, $all, false );
	}

	/**
	 * A page recorded as ending the request, while it is unchanged, in a request that is not a
	 * person's own look at it: refused with a named reason. A person in the editor still gets it
	 * rendered (their page may show them what it hides from guests); a change to the page clears
	 * the record, so a fixed page is read again.
	 *
	 * @param \WP_Post $subject Post.
	 * @return \WP_Error|null
	 */
	public static function refused( \WP_Post $subject ): ?\WP_Error {
		$id  = (int) $subject->ID;
		$all = $id > 0 ? get_option( self::UNREADABLE, array() ) : array();
		if ( ! is_array( $all ) || ! isset( $all[ $id ] ) || ! is_array( $all[ $id ] ) ) {
			return null;
		}
		$saved = get_post( $id );
		if ( $saved instanceof \WP_Post && (string) ( $all[ $id ]['m'] ?? '' ) !== (string) $saved->post_modified_gmt ) {
			unset( $all[ $id ] );
			update_option( self::UNREADABLE, $all, false );
			self::$cleared[ $id ] = true;
			return null;
		}
		if ( ! self::$sweeping && ! self::in_job() && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return null;
		}
		self::$skipped[ $id ] = true;

		return new \WP_Error(
			'zinn_seo_render_ends_request',
			'fatal' === ( $all[ $id ]['why'] ?? '' )
				/* translators: %d: a post ID. */
				? sprintf( __( 'Page %d could not be read: it stops PHP with an error while it is shown, so it is skipped until it changes.', 'zinn-seo' ), $id )
				/* translators: %d: a post ID. */
				: sprintf( __( 'Page %d could not be read: something on it (a shortcode or a filter) redirects visitors or ends the request while it is shown, so it is skipped until it changes.', 'zinn-seo' ), $id ),
			array(
				'status' => 422,
				'post'   => $id,
			)
		);
	}

	/**
	 * Is a background job's step running in this process?
	 *
	 * @return bool
	 */
	private static function in_job(): bool {
		return class_exists( '\\ZinnDigital\\ZinnSEO\\Jobs', false ) && '' !== \ZinnDigital\ZinnSEO\Jobs::current();
	}

	/**
	 * The pages refused since the last call (and forget them): a job reports them as pages it
	 * could not read.
	 *
	 * @return array<int, int>
	 */
	public static function take_skipped(): array {
		$ids           = array_map( 'intval', array_keys( self::$skipped ) );
		self::$skipped = array();

		return $ids;
	}

	/**
	 * The pages read again since the last call because they changed (and forget them).
	 *
	 * @return array<int, int>
	 */
	public static function take_cleared(): array {
		$ids           = array_map( 'intval', array_keys( self::$cleared ) );
		self::$cleared = array();

		return $ids;
	}

	/**
	 * What else the rendered page depends on besides its title and content: a page builder
	 * keeps the page in post meta, and custom fields hold words of the page — both change
	 * without the content changing. Everything the post stores (but our own bookkeeping and
	 * WordPress's edit lock) is part of the stamp, so a save in a builder or a changed field is
	 * read at once, not when the cached copy runs out.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function builder_stamp( \WP_Post $post ): string {
		$meta = get_post_meta( $post->ID );
		$meta = is_array( $meta ) ? $meta : array();
		foreach ( array_keys( $meta ) as $key ) {
			if ( 0 === strpos( (string) $key, '_zseo_' ) || 0 === strpos( (string) $key, '_edit_' ) || 0 === strpos( (string) $key, '_wp_old_' ) ) {
				unset( $meta[ $key ] );
			}
		}
		ksort( $meta );

		/**
		 * Filters what the cached rendering of a post is keyed on (an integration adds what its
		 * output depends on outside the post: its own tables, its settings).
		 *
		 * @param string   $stamp Stamp.
		 * @param \WP_Post $post  Post.
		 */
		return (string) apply_filters( 'zinn_seo_render_stamp', array() === $meta ? '' : md5( (string) wp_json_encode( $meta ) ), $post );
	}

	/**
	 * Has the saved content got a block, shortcode or widget of ours with this name?
	 *
	 * @param \WP_Post $post  Post (with the editor's content).
	 * @param string   $block Block name (e.g. quick-answer).
	 * @return bool
	 */
	public static function has_block( \WP_Post $post, string $block ): bool {
		$content = (string) $post->post_content;
		if ( false !== strpos( $content, 'wp:zinn-seo/' . $block ) || false !== strpos( $content, 'block="' . $block . '"' ) ) {
			return true;
		}
		$data = get_post_meta( $post->ID, '_elementor_data', true );

		return is_string( $data ) && false !== strpos( $data, '"zinn-seo-' . $block . '"' );
	}
}
