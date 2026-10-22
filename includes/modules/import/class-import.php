<?php
/**
 * Moving from another SEO plugin: read, count, copy, and take it all back (zs-260, zs-261).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Bulk\History;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The order of a move, each step its own action so a person can stop after any of them:
 *
 * 1. **Count** (`dry`): the copy job run with writing switched off — the SAME code path, so the
 *    numbers it shows are the numbers the copy will produce, not an estimate from a COUNT query.
 * 2. **Begin**: the other plugin's site-wide settings and templates are written (the values they
 *    replace are kept), and the side-by-side reader is switched on: from this moment our head
 *    answers from the other plugin's rows for any page the copy has not reached.
 * 3. **Copy** (`apply`): posts, then terms, then redirects, by keyset in small chunks.
 * 4. **Compare** (Check), **switch the other plugin off** (Switcher), or **undo**.
 *
 * ⛔⛔ NEVER ONE REQUEST OVER ALL POSTS (CLAUDE.md §2.10). The copy is a job of the shared runner:
 * a step works until its deadline, a chunk is 100 objects, and the cursor is saved after EVERY
 * chunk, so a run killed by the host continues at the chunk it was in.
 *
 * ⭐ A killed run re-does nothing, on any database engine: each chunk FIRST writes its history
 * rows (what it is about to change, and from what) and THEN changes the fields. A chunk that is
 * run a second time finds either the field already holding the value (nothing to do) or the
 * history row already there (not written twice). The applied counts are read back from those
 * rows at the end — counted, never tallied — so they cannot drift from what was done.
 *
 * Nothing of the other plugin is changed or deleted, ever.
 */
final class Import {

	/** The copy (and count) job. */
	public const JOB = 'import';

	/** The undo job. */
	public const JOB_UNDO = 'import-undo';

	/** Non-autoloaded state of the move. */
	public const OPTION = 'zseo_import';

	/** Non-autoloaded checkpoint of the running job, saved after every chunk. */
	public const RUN = 'zseo_import_run';

	/** Objects per chunk. */
	public const CHUNK = 100;

	/** Post types that never carry a page's SEO data. */
	private const SKIP_TYPES = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'seopress_404', 'seopress_bot', 'redirect_rule', 'rank_math_schema' );

	/**
	 * The sources, built once.
	 *
	 * @var array<string, Source>|null
	 */
	private static ?array $sources = null;

	/**
	 * The module's settings: which source the head reads side by side ('' when none).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * Every source: id => reader.
	 *
	 * @return array<string, Source>
	 */
	public static function sources(): array {
		if ( null !== self::$sources ) {
			return self::$sources;
		}
		$dir = __DIR__ . '/sources/';
		$all = array();
		foreach ( array(
			'yoast'       => 'Yoast',
			'rankmath'    => 'Rank_Math',
			'aioseo'      => 'Aioseo',
			'seopress'    => 'Seopress',
			'tsf'         => 'Tsf',
			'slimseo'     => 'Slim_Seo',
			'squirrly'    => 'Squirrly',
			'smartcrawl'  => 'Smartcrawl',
			// The long tail (zs-725).
			'surerank'    => 'Surerank',
			'seoultimate' => 'Seo_Ultimate',
			'wpmetaseo'   => 'Wp_Meta_Seo',
			'psp'         => 'Premium_Seo_Pack',
			'quickppr'    => 'Quick_Redirects',
			'schemapro'   => 'Schema_Pro',
			'saswp'       => 'Saswp',
			'aiosrs'      => 'Aio_Schema',
			'linkwhisper' => 'Link_Whisper',
		) as $id => $class ) {
			$file = $dir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				$fqcn       = __NAMESPACE__ . '\\Sources\\' . $class;
				$all[ $id ] = new $fqcn();
			}
		}
		require_once $dir . 'class-redirects-only.php';
		$all['redirection'] = new Sources\Redirects_Only( 'redirection', 'Redirection', array( 'redirection/redirection.php' ) );
		$all['srm']         = new Sources\Redirects_Only( 'srm', 'Safe Redirect Manager', array( 'safe-redirect-manager/safe-redirect-manager.php' ) );
		$all['s301']        = new Sources\Redirects_Only( 's301', 'Simple 301 Redirects', array( 'simple-301-redirects/wp-simple-301-redirects.php' ) );
		$all['seoredir']    = new Sources\Redirects_Only( 'seoredir', 'SEO Redirection', array( 'seo-redirection/seo-redirection.php' ) );

		/**
		 * Filters the import sources (id => a Source). Another plugin can add its own reader.
		 *
		 * @param array<string, Source> $all Sources.
		 */
		$all           = (array) apply_filters( 'zinn_seo_import_sources', $all );
		self::$sources = array_filter( $all, static fn( $s ): bool => $s instanceof Source );

		return self::$sources;
	}

	/**
	 * One source.
	 *
	 * @param string $id Id.
	 * @return Source|null
	 */
	public static function source( string $id ): ?Source {
		return self::sources()[ $id ] ?? null;
	}

	/**
	 * Forget the per-request caches (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$sources = null;
	}

	/**
	 * Register the jobs and the hooks of the non-visitor contexts.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Jobs::register( self::JOB, static fn() => __( 'Importing from another SEO plugin', 'zinn-seo' ), array( self::class, 'step' ) );
		Jobs::register( self::JOB_UNDO, static fn() => __( 'Undoing an import', 'zinn-seo' ), array( self::class, 'undo_step' ) );
		Check::boot();
		Verify::boot();
		Switcher::boot();
		add_action( 'deactivated_plugin', array( self::class, 'other_plugin_switched_off' ), 10, 1 );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( self::class, 'notice' ) );
		}
	}

	/**
	 * After the other plugin was switched off by hand: say, on the Plugins screen, that its data
	 * is being read and where to copy it in. (Not an advertisement: it reports something this
	 * plugin started doing because of what the person just did.)
	 *
	 * @return void
	 */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( \ZinnDigital\ZinnSEO\Capabilities::MANAGE ) ) {
			return;
		}
		$state  = self::state();
		$source = self::source( (string) $state['source'] );
		if ( null === $source || empty( $state['auto'] ) || 0 === (int) $state['begun'] || null !== $state['applied'] || 0 !== (int) $state['undone'] ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the name of another SEO plugin. */
					__( 'Zinn® SEO is now reading the titles, descriptions and settings %s stored, so your pages say what they said before it was switched off.', 'zinn-seo' ),
					$source->name()
				)
			),
			esc_url( admin_url( 'admin.php?page=zinn-seo&view=import' ) ),
			esc_html__( 'Copy them into Zinn® SEO', 'zinn-seo' )
		);
	}

	/**
	 * The stored state of the move.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return $stored + array(
			'source'    => '',
			'overwrite' => false,
			'begun'     => 0,
			'auto'      => false,
			'settings'  => array(
				'before'  => array(),
				'written' => array(),
			),
			'dry'       => null,
			'applied'   => null,
			'check'     => null,
			'switched'  => null,
			'undone'    => 0,
			'redirects' => 0,
			'preflight' => null,
			'proof'     => null,
			'switching' => null,
		);
	}

	/**
	 * Store the state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	public static function put( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * The history class of the bulk editor (its table holds every change an import makes).
	 *
	 * @return bool Whether it is available.
	 */
	public static function history(): bool {
		if ( ! class_exists( History::class ) ) {
			$file = dirname( __DIR__ ) . '/bulk/class-history.php';
			if ( ! is_readable( $file ) ) {
				return false;
			}
			require_once $file;
		}

		return true;
	}

	/**
	 * Fields this module brings to the registry, so what is carried over from another plugin is
	 * written, recorded and undone like every other field:
	 *
	 * - on a user: the profiles the social tags and the author's structured data already read
	 *   (the keys are those of the Social and Expertise sections of the profile screen);
	 * - on a post: the page's structured-data types as the other plugin held them
	 *   (`schema_import`, a JSON list of `{ type, values }` in the shape of the Structured data
	 *   panel). Zinn® SEO Pro prints them and lets them be edited; without it they are kept.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		return require __DIR__ . '/meta.php';
	}

	/**
	 * Empty counts.
	 *
	 * @return array<string, mixed>
	 */
	public static function zero(): array {
		return array(
			'posts'     => 0,
			'terms'     => 0,
			'users'     => 0,
			'fields'    => array(),
			'kept'      => 0,
			'same'      => 0,
			'refused'   => 0,
			'unknown'   => array(),
			'settings'  => 0,
			'redirects' => array(
				'new'     => 0,
				'replace' => 0,
				'same'    => 0,
				'refused' => 0,
			),
		);
	}

	/**
	 * The settings a source would change here, and how many.
	 *
	 * ⭐ By default only what this site has NOT set itself: a setting still at its default, and a
	 * template field nobody filled in. `$overwrite` replaces them all. A value the settings
	 * schema refuses is left out (and counted), so the rest can still be stored.
	 *
	 * @param Source $source    Source.
	 * @param bool   $overwrite Replace what is already set here.
	 * @return array{changes: array<string, mixed>, count: int, refused: int}
	 */
	public static function settings_plan( Source $source, bool $overwrite ): array {
		$schema   = Settings::schema();
		$defaults = Settings::defaults();
		$now      = Settings::all();
		$changes  = array();
		$count    = 0;
		$refused  = 0;
		foreach ( $source->settings() as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$def = $schema[ $key ];
			if ( 'dict' === $def['type'] ) {
				$current = is_array( $now[ $key ] ?? null ) ? $now[ $key ] : array();
				$next    = $current;
				$touched = 0;
				foreach ( (array) $value as $record_key => $record ) {
					$one = Settings::coerce( $def, array( (string) $record_key => (array) $record ) );
					if ( is_wp_error( $one ) ) {
						++$refused;
						continue;
					}
					$record  = (array) ( $one[ (string) $record_key ] ?? array() );
					$mine    = is_array( $current[ $record_key ] ?? null ) ? $current[ $record_key ] : array();
					$changed = false;
					foreach ( $record as $name => $v ) {
						$has = array_key_exists( $name, $mine ) && '' !== $mine[ $name ] && array() !== $mine[ $name ] && 0 !== $mine[ $name ];
						if ( $has && ! $overwrite ) {
							continue;
						}
						if ( ! $has || $mine[ $name ] !== $v ) {
							$mine[ $name ] = $v;
							$changed       = true;
						}
					}
					if ( $changed ) {
						$next[ $record_key ] = $mine;
						++$touched;
					}
				}
				if ( $touched > 0 ) {
					$changes[ $key ] = $next;
					$count          += $touched;
				}
				continue;
			}
			$clean = Settings::coerce( $def, $value );
			if ( is_wp_error( $clean ) ) {
				++$refused;
				continue;
			}
			$current = $now[ $key ] ?? null;
			if ( $current === $clean ) {
				continue;
			}
			$untouched = array_key_exists( $key, $defaults ) && $defaults[ $key ] === $current;
			// An empty value from the other plugin never clears something set here.
			$empty = '' === $clean || array() === $clean || 0 === $clean;
			if ( ( $untouched && ! $empty ) || ( $overwrite && ! $empty ) || ( $untouched && is_bool( $clean ) ) ) {
				$changes[ $key ] = $clean;
				++$count;
			}
		}

		return array(
			'changes' => $changes,
			'count'   => $count,
			'refused' => $refused,
		);
	}

	/**
	 * Begin a move: write the other plugin's settings (keeping what they replace) and switch the
	 * side-by-side reader on.
	 *
	 * @param string $id        Source.
	 * @param bool   $overwrite Replace what is already set here.
	 * @param bool   $auto      Begun by the plugin itself (the other plugin was switched off).
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public static function begin( string $id, bool $overwrite = false, bool $auto = false ) {
		$source = self::source( $id );
		if ( null === $source || ! $source->present() ) {
			return new \WP_Error( 'zinn_seo_no_source', __( 'There is nothing from that plugin on this site.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$state = self::state();
		if ( '' !== (string) $state['source'] && $state['source'] !== $id && 0 === (int) $state['undone'] && ( $state['begun'] > 0 ) ) {
			return new \WP_Error( 'zinn_seo_import_busy', __( 'Another import was started here. Undo it first, or finish it.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		if ( (int) $state['begun'] > 0 && $state['source'] === $id && 0 === (int) $state['undone'] ) {
			return $state;
		}
		$plan   = self::settings_plan( $source, $overwrite );
		$before = array();
		$now    = Settings::all();
		foreach ( array_keys( $plan['changes'] ) as $key ) {
			$before[ $key ] = $now[ $key ] ?? null;
		}
		$live  = $source->has_pages() ? array( 'import_live' => $id ) : array();
		$saved = Settings::save( $plan['changes'] + $live, 'import' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$written = array();
		foreach ( array_keys( $plan['changes'] ) as $key ) {
			$written[ $key ] = $saved[ $key ] ?? null;
		}
		$state = array_merge(
			self::state(),
			array(
				'source'    => $id,
				'overwrite' => $overwrite,
				'begun'     => time(),
				'auto'      => $auto,
				'settings'  => array(
					'before'  => $before,
					'written' => $written,
					'count'   => $plan['count'],
				),
				'applied'   => null,
				'check'     => null,
				'switched'  => null,
				'undone'    => 0,
			)
		);
		self::put( $state );

		/**
		 * Fires when a move from another SEO plugin begins (its settings are in, the head reads
		 * its data side by side).
		 *
		 * @param string $id    Source.
		 * @param array  $state State.
		 */
		do_action( 'zinn_seo_import_begun', $id, $state );

		return $state;
	}

	/**
	 * Another plugin was switched off by hand: when it is a known SEO plugin with data here and
	 * nothing was imported, begin reading its data at once, so the head keeps saying what it
	 * said (zs-261). The screen then offers to copy it in, or to stop.
	 *
	 * @param string $plugin The plugin's main file.
	 * @return void
	 */
	public static function other_plugin_switched_off( $plugin ): void {
		$state = self::state();
		if ( (int) $state['begun'] > 0 && 0 === (int) $state['undone'] ) {
			return;
		}
		foreach ( self::sources() as $id => $source ) {
			if ( in_array( (string) $plugin, $source->plugins(), true ) && $source->has_pages() && $source->present() ) {
				// Its other edition may still be active (free + premium): wait for the last one.
				if ( array() !== array_diff( $source->active(), array( (string) $plugin ) ) ) {
					return;
				}
				self::begin( (string) $id, false, true );
				return;
			}
		}
	}

	/**
	 * Start the count (dry) or the copy.
	 *
	 * @param string $id        Source.
	 * @param bool   $dry       Count only: nothing is written.
	 * @param bool   $overwrite Replace values already set here.
	 * @return true|\WP_Error
	 */
	public static function start( string $id, bool $dry, bool $overwrite = false ) {
		$source = self::source( $id );
		if ( null === $source || ! $source->present() ) {
			return new \WP_Error( 'zinn_seo_no_source', __( 'There is nothing from that plugin on this site.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( ! self::history() ) {
			return new \WP_Error( 'zinn_seo_no_history', __( 'The change history is not available, so an import could not be undone. Switch the bulk editor on first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$running = Jobs::get( self::JOB );
		if ( 'pending' === $running['status'] ) {
			return new \WP_Error( 'zinn_seo_import_running', __( 'An import is already running.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$counts = self::zero();
		$batch  = null;
		if ( $dry ) {
			$plan  = self::settings_plan( $source, $overwrite );
			$state = self::state();
			// Already begun (the side-by-side reader is on): the settings are in, and the copy
			// will report the number that was written then.
			$begun              = (int) $state['begun'] > 0 && $state['source'] === $id && 0 === (int) $state['undone'];
			$counts['settings'] = $begun ? (int) ( $state['settings']['count'] ?? 0 ) : $plan['count'];
		} else {
			// Look before anything is written (queue Q263, point 1), every time (one request): a
			// site that cannot read its own pages now could never prove the move.
			$preflight = Preflight::run( $id );
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
			$stop = Preflight::refusal( $preflight );
			if ( '' !== $stop ) {
				return new \WP_Error( 'zinn_seo_preflight', $stop, array( 'status' => 409 ) );
			}
			$state = self::begin( $id, $overwrite );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$counts['settings'] = (int) ( $state['settings']['count'] ?? 0 );
			History::maybe_install();
			/* translators: %s: the name of another SEO plugin. */
			History::open( 'post', sprintf( __( 'Imported from %s', 'zinn-seo' ), $source->name() ), 'import' );
			$batch                     = History::suspend();
			$state                     = self::state();
			$state['applied']          = null;
			$state['batch']            = (string) ( $batch['id'] ?? '' );
			$state['overwrite']        = $overwrite;
			$state['redirects_before'] = self::redirects_max_id();
			self::put( $state );
		}
		$run = array(
			'source'    => $id,
			'dry'       => $dry,
			'overwrite' => $overwrite,
			'phase'     => $source->has_pages() ? 'posts' : 'redirects',
			'cursor'    => 0,
			'counts'    => $counts,
			'batch'     => $batch,
			'redirects' => null,
			'total'     => $source->has_pages() ? self::total() : 0,
		);
		update_option( self::RUN, $run, false );

		return Jobs::start( self::JOB, $run );
	}

	/**
	 * How many posts and terms the job will look at (for the progress bar; estimates on InnoDB
	 * are not used — these two are index-only counts).
	 *
	 * @return int
	 */
	public static function total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- two index counts, once per job.
		$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
		$terms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above (this site's members).
		$users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $wpdb->get_blog_prefix() . 'capabilities' ) );

		return $posts + $terms + $users;
	}

	/**
	 * The highest redirect id before an import (what it adds comes after).
	 *
	 * @return int
	 */
	public static function redirects_max_id(): int {
		if ( ! class_exists( '\ZinnDigital\ZinnSEO\Redirects\Store' ) || ! \ZinnDigital\ZinnSEO\Redirects\Store::ready() ) {
			return 0;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, once per import.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', \ZinnDigital\ZinnSEO\Redirects\Store::table() ) );
	}

	/**
	 * The next chunk of post ids after a cursor.
	 *
	 * @param int $after Cursor.
	 * @return array<int, int>
	 */
	public static function post_ids( int $after ): array {
		global $wpdb;
		$skip = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_TYPES ) ) . "'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- keyset on the primary key; $skip is a constant list escaped above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ({$skip}) AND post_status <> 'auto-draft' ORDER BY ID ASC LIMIT %d", $after, self::CHUNK ) ) );
	}

	/**
	 * The next chunk of this site's users after a cursor (author pages and profiles).
	 *
	 * @param int $after Cursor.
	 * @return array<int, int>
	 */
	public static function user_ids( int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset on the user id through the membership key.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", $wpdb->get_blog_prefix() . 'capabilities', $after, self::CHUNK ) ) );
	}

	/**
	 * The next chunk of terms after a cursor (by term_taxonomy_id: a term shared by two
	 * taxonomies is two rows).
	 *
	 * @param int $after Cursor.
	 * @return array<int, array{tt: int, term: \WP_Term}>
	 */
	public static function terms( int $after ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset on the primary key.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, t.name, t.slug FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.term_taxonomy_id > %d ORDER BY tt.term_taxonomy_id ASC LIMIT %d", $after, self::CHUNK ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'tt'   => (int) $row->term_taxonomy_id,
				// Built from the row: the taxonomy may belong to a plugin that is switched off now.
				'term' => new \WP_Term( $row ),
			);
		}

		return $out;
	}

	/**
	 * One step of the count or the copy.
	 *
	 * @param array<string, mixed> $state    Job state (the checkpoint wins when it is newer).
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the source is no longer known (the job is marked failed).
	 */
	public static function step( array $state, float $deadline ): array {
		$run = get_option( self::RUN, array() );
		$run = is_array( $run ) && isset( $run['phase'] ) ? $run : $state;
		$src = self::source( (string) ( $run['source'] ?? '' ) );
		if ( null === $src || ! self::history() ) {
			throw new \RuntimeException( esc_html__( 'The plugin this import reads from is no longer known here.', 'zinn-seo' ) );
		}
		// The copy must see what is really stored here: with the side-by-side reader answering,
		// every field would look as if it already held the other plugin's value and nothing
		// would be written (measured 2026-10-10: "16 the same, 0 copied").
		if ( class_exists( Live::class, false ) ) {
			Live::stop();
		}
		$dry       = ! empty( $run['dry'] );
		$overwrite = ! empty( $run['overwrite'] );
		$done      = false;
		while ( ! $done && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			if ( 'posts' === $run['phase'] ) {
				$ids = self::post_ids( (int) $run['cursor'] );
				if ( array() === $ids ) {
					$run['phase']  = 'terms';
					$run['cursor'] = 0;
				} else {
					// The truth from the database, not from a cache a killed run may have left ahead.
					foreach ( $ids as $id ) {
						wp_cache_delete( $id, 'post_meta' );
					}
					update_meta_cache( 'post', $ids );
					$src->prime_posts( $ids );
					$objects = array();
					foreach ( $ids as $id ) {
						$objects[ $id ] = $src->post( $id );
					}
					$run           = self::chunk( $run, 'post', $objects, $dry, $overwrite );
					$run['cursor'] = (int) end( $ids );
					$run['seen']   = (int) ( $run['seen'] ?? 0 ) + count( $ids );
					History::release( array( 'post' => $ids ) );
				}
			} elseif ( 'terms' === $run['phase'] ) {
				$rows = self::terms( (int) $run['cursor'] );
				if ( array() === $rows ) {
					$run['phase']  = 'users';
					$run['cursor'] = 0;
				} else {
					$ids = array_values( array_unique( array_map( static fn( array $r ): int => (int) $r['term']->term_id, $rows ) ) );
					foreach ( $ids as $id ) {
						wp_cache_delete( $id, 'term_meta' );
					}
					update_meta_cache( 'term', $ids );
					$src->prime_terms( $ids );
					$objects = array();
					foreach ( $rows as $row ) {
						$fields = $src->term( $row['term'] );
						if ( array() !== $fields ) {
							$objects[ (int) $row['term']->term_id ] = $fields + ( $objects[ (int) $row['term']->term_id ] ?? array() );
						}
					}
					$run           = self::chunk( $run, 'term', $objects, $dry, $overwrite );
					$last          = end( $rows );
					$run['cursor'] = (int) $last['tt'];
					$run['seen']   = (int) ( $run['seen'] ?? 0 ) + count( $rows );
					History::release( array( 'term' => $ids ) );
				}
			} elseif ( 'users' === $run['phase'] ) {
				// What an author's page says, and the profiles on their user record.
				$ids = self::user_ids( (int) $run['cursor'] );
				if ( array() === $ids ) {
					$run['phase']  = 'redirects';
					$run['cursor'] = 0;
				} else {
					foreach ( $ids as $id ) {
						wp_cache_delete( $id, 'user_meta' );
					}
					update_meta_cache( 'user', $ids );
					$objects = array();
					foreach ( $ids as $id ) {
						$user = get_userdata( $id );
						if ( $user instanceof \WP_User ) {
							$fields = $src->user( $user );
							if ( array() !== $fields ) {
								$objects[ $id ] = $fields;
							}
						}
					}
					$run           = self::chunk( $run, 'user', $objects, $dry, $overwrite );
					$run['cursor'] = (int) end( $ids );
					$run['seen']   = (int) ( $run['seen'] ?? 0 ) + count( $ids );
					History::release( array( 'user' => $ids ) );
				}
			} elseif ( 'redirects' === $run['phase'] ) {
				$run  = self::redirects_step( $run, $src, $dry, $deadline );
				$done = 'done' === $run['phase'];
			} else {
				$done = true;
			}
			update_option( self::RUN, $run, false );
		}
		if ( $done ) {
			self::finish( $run, $src );
		}

		return array(
			'state'    => $run,
			'done'     => $done,
			'progress' => array(
				'done'  => (int) ( $run['seen'] ?? 0 ),
				'total' => (int) ( $run['total'] ?? 0 ),
			),
		);
	}

	/**
	 * One chunk: decide every field, write the history rows, then the fields.
	 *
	 * @param array<string, mixed>             $run       Checkpoint.
	 * @param string                           $kind      post|term|user.
	 * @param array<int, array<string, mixed>> $objects   Object id => the source's fields.
	 * @param bool                             $dry       Count only.
	 * @param bool                             $overwrite Replace values set here.
	 * @return array<string, mixed> The checkpoint, counts updated.
	 */
	public static function chunk( array $run, string $kind, array $objects, bool $dry, bool $overwrite ): array {
		$counts = (array) $run['counts'];
		$defs   = Meta::fields();
		$writes = array();
		foreach ( $objects as $id => $fields ) {
			$touched = false;
			// A redirect set on the page itself becomes a rule of the redirects module.
			if ( isset( $fields['redirect'] ) ) {
				if ( 'post' === $kind && is_array( $fields['redirect'] ) ) {
					$result                         = self::page_redirect( (int) $id, $fields['redirect'], (string) $run['source'], $dry );
					$counts['redirects'][ $result ] = (int) ( $counts['redirects'][ $result ] ?? 0 ) + 1;
				}
				unset( $fields['redirect'] );
			}
			foreach ( (array) $fields as $name => $value ) {
				$def = $defs[ $name ] ?? null;
				if ( null === $def || ! in_array( $kind, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
					$counts['unknown'][ $name ] = (int) ( $counts['unknown'][ $name ] ?? 0 ) + 1;
					continue;
				}
				$clean = Meta::sanitize( $def, $value );
				if ( is_wp_error( $clean ) ) {
					++$counts['refused'];
					continue;
				}
				if ( Meta::empty_value( $def ) === $clean ) {
					continue;
				}
				$current = Meta::get( $kind, (int) $id, (string) $name );
				$empty   = Meta::empty_value( $def ) === $current;
				if ( ! $empty && History::same( $current, $clean ) ) {
					++$counts['same'];
					continue;
				}
				if ( ! $empty && ! $overwrite ) {
					++$counts['kept'];
					continue;
				}
				$writes[]                  = array( (int) $id, (string) $name, $current, $clean );
				$counts['fields'][ $name ] = (int) ( $counts['fields'][ $name ] ?? 0 ) + 1;
				$touched                   = true;
			}
			if ( $touched ) {
				$counts[ $kind . 's' ] = (int) ( $counts[ $kind . 's' ] ?? 0 ) + 1;
			}
		}
		$run['counts'] = $counts;
		if ( $dry || array() === $writes ) {
			return $run;
		}

		$run['batch'] = self::write( (array) $run['batch'], $kind, $writes );

		return $run;
	}

	/**
	 * The origin stored with the redirects an import adds (what an undo removes).
	 *
	 * @param Source $source Source.
	 * @return string
	 */
	public static function origin( Source $source ): string {
		return 'import-' . ( '' !== $source->redirects() ? $source->redirects() : $source->id() );
	}

	/**
	 * Add (or count) a redirect the other plugin kept on a post.
	 *
	 * @param int                  $post_id  Post.
	 * @param array<string, mixed> $redirect `target`, `code`.
	 * @param string               $source   Source id.
	 * @param bool                 $dry      Count only.
	 * @return string new, replace, same or refused.
	 */
	public static function page_redirect( int $post_id, array $redirect, string $source, bool $dry ): string {
		$importer = '\ZinnDigital\ZinnSEO\Redirects\Importer';
		$src      = self::source( $source );
		$from     = (string) get_permalink( $post_id );
		if ( null === $src || ! class_exists( $importer ) || '' === $from || '' === trim( (string) ( $redirect['target'] ?? '' ) ) ) {
			return 'refused';
		}
		if ( ! $dry ) {
			\ZinnDigital\ZinnSEO\Redirects\Store::maybe_install();
		}
		$why = '';

		return (string) $importer::in(
			array(
				'source' => $from,
				'target' => trim( (string) $redirect['target'] ),
				'code'   => (int) ( $redirect['code'] ?? 301 ),
				'regex'  => false,
			),
			self::origin( $src ),
			$dry,
			$why
		);
	}

	/**
	 * Write fields inside a history batch, write-ahead: the history rows first (skipping any a
	 * killed run already wrote), then the fields. The history listener is unhooked meanwhile so a
	 * field is not recorded a second time.
	 *
	 * @param array<string, mixed>                                     $batch  The batch (History::suspend()).
	 * @param string                                                   $kind   post|term.
	 * @param array<int, array{0: int, 1: string, 2: mixed, 3: mixed}> $writes Object id, field, value now, value to write.
	 * @return array<string, mixed> The batch, suspended again.
	 */
	public static function write( array $batch, string $kind, array $writes ): array {
		if ( array() === $writes ) {
			return $batch;
		}
		$ids     = array_map( static fn( array $w ): int => (int) $w[0], $writes );
		$already = self::recorded( (string) $batch['id'], $kind, min( $ids ), max( $ids ) );
		$hooked  = false !== has_action( 'zinn_seo_field_saved', array( History::class, 'record' ) );
		if ( $hooked ) {
			remove_action( 'zinn_seo_field_saved', array( History::class, 'record' ), 10 );
		}
		History::resume( $batch );
		foreach ( $writes as $w ) {
			if ( ! isset( $already[ $w[0] . ':' . $w[1] ] ) ) {
				History::record( $kind, $w[0], $w[1], $w[2], $w[3], 'import' );
			}
		}
		History::flush();
		foreach ( $writes as $w ) {
			Meta::set( $kind, (int) $w[0], (string) $w[1], $w[3], 'import' );
		}
		$batch = (array) History::suspend();
		if ( $hooked ) {
			History::listen();
		}

		return $batch;
	}

	/**
	 * The history rows a batch already holds for a range of objects: `id:field` => true.
	 *
	 * @param string $batch Batch id.
	 * @param string $kind  post|term.
	 * @param int    $from  Lowest object id.
	 * @param int    $to    Highest object id.
	 * @return array<string, bool>
	 */
	public static function recorded( string $batch, string $kind, int $from, int $to ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its batch index.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, field FROM %i WHERE batch = %s AND object_type = %s AND object_id BETWEEN %d AND %d', History::table(), $batch, $kind, $from, $to ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->object_id . ':' . (string) $row->field ] = true;
		}

		return $out;
	}

	/**
	 * The redirects, through the redirects module's own importer (never a second copy of it).
	 *
	 * @param array<string, mixed> $run      Checkpoint.
	 * @param Source               $src      Source.
	 * @param bool                 $dry      Count only.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function redirects_step( array $run, Source $src, bool $dry, float $deadline ): array {
		$rid      = $src->redirects();
		$importer = '\ZinnDigital\ZinnSEO\Redirects\Importer';
		if ( '' === $rid || ! class_exists( $importer ) || $importer::count( $rid ) < 1 ) {
			$run['phase'] = 'done';
			return $run;
		}
		if ( $dry ) {
			$preview                    = $importer::preview( $rid );
			$run['counts']['redirects'] = array_map( 'intval', (array) $preview['counts'] ) + $run['counts']['redirects'];
			$run['phase']               = 'done';
			return $run;
		}
		if ( ! is_array( $run['redirects'] ) ) {
			\ZinnDigital\ZinnSEO\Redirects\Store::maybe_install();
			$run['redirects'] = array(
				'source' => $rid,
				'after'  => 0,
				'csv'    => array( 'offset' => 0 ),
				'rules'  => array(),
				'counts' => array(
					'new'     => 0,
					'replace' => 0,
					'same'    => 0,
					'refused' => 0,
				),
			);
		}
		$result                     = $importer::step( (array) $run['redirects'], $deadline );
		$run['redirects']           = $result['state'];
		$run['counts']['redirects'] = array_map( 'intval', (array) $result['state']['counts'] ) + $run['counts']['redirects'];
		if ( ! empty( $result['done'] ) ) {
			$run['phase'] = 'done';
		}

		return $run;
	}

	/**
	 * The end of a count or a copy: store the result.
	 *
	 * @param array<string, mixed> $run Checkpoint.
	 * @param Source               $src Source.
	 * @return void
	 */
	public static function finish( array $run, Source $src ): void {
		$state  = self::state();
		$counts = (array) $run['counts'];
		foreach ( $src->unknown_variables() as $name => $n ) {
			$counts['variables'][ $name ] = (int) $n;
		}
		$extras = $src->extras( ! empty( $run['dry'] ) );
		if ( array() !== $extras ) {
			$counts['extras'] = $extras;
		}
		if ( ! empty( $run['dry'] ) ) {
			$state['dry'] = array(
				'source'    => (string) $run['source'],
				'overwrite' => ! empty( $run['overwrite'] ),
				'counts'    => $counts,
				'at'        => time(),
			);
		} else {
			// Counted from what was written, not tallied on the way (a killed and continued run
			// tallies a chunk twice or not at all; the rows are the record).
			$written = self::written( (string) ( $run['batch']['id'] ?? '' ) );
			History::resume( array_merge( (array) $run['batch'], array( 'count' => array_sum( $written['fields'] ) ) ) );
			$batch            = History::close();
			$counts['posts']  = $written['posts'];
			$counts['terms']  = $written['terms'];
			$counts['users']  = $written['users'];
			$counts['fields'] = $written['fields'];
			$state['applied'] = array(
				'source' => (string) $run['source'],
				'counts' => $counts,
				'batch'  => (string) ( $batch['id'] ?? '' ),
				'at'     => time(),
			);
			$state['check']   = null;
			$state['proof']   = null;
			// Every page now holds its own copy: the side-by-side reader has nothing left to add.
			Settings::save( array( 'import_live' => '' ), 'import' );
		}
		self::put( $state );
		delete_option( self::RUN );

		/**
		 * Fires when a count or a copy from another SEO plugin has finished.
		 *
		 * @param string $source Source id.
		 * @param bool   $dry    It was a count.
		 * @param array  $counts Counts.
		 */
		do_action( 'zinn_seo_import_finished', (string) $run['source'], ! empty( $run['dry'] ), $counts );

		if ( empty( $run['dry'] ) ) {
			// Read the copy back before anything else changes (queue Q263, point 2).
			Verify::start();
		}
	}

	/**
	 * What a batch wrote, counted from its rows.
	 *
	 * @param string $batch Batch id.
	 * @return array{posts: int, terms: int, users: int, fields: array<string, int>}
	 */
	public static function written( string $batch ): array {
		$out = array(
			'posts'  => 0,
			'terms'  => 0,
			'users'  => 0,
			'fields' => array(),
		);
		if ( '' === $batch ) {
			return $out;
		}
		global $wpdb;
		$table = History::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its batch index.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT field, COUNT(*) AS n FROM %i WHERE batch = %s GROUP BY field', $table, $batch ) ) as $row ) {
			$out['fields'][ (string) $row->field ] = (int) $row->n;
		}
		ksort( $out['fields'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, COUNT(DISTINCT object_id) AS n FROM %i WHERE batch = %s GROUP BY object_type', $table, $batch ) ) as $row ) {
			$out[ in_array( $row->object_type, array( 'term', 'user' ), true ) ? $row->object_type . 's' : 'posts' ] = (int) $row->n;
		}

		return $out;
	}

	/**
	 * Stop reading the other plugin's data side by side and put the settings back, when nothing
	 * was copied yet. (After a copy, use undo.)
	 *
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public static function stop() {
		$state = self::state();
		if ( null !== $state['applied'] ) {
			return new \WP_Error( 'zinn_seo_import_applied', __( 'Data was already copied. Use "Undo the import" to take it back.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		self::restore_settings( $state );
		$state['begun']    = 0;
		$state['auto']     = false;
		$state['settings'] = array(
			'before'  => array(),
			'written' => array(),
		);
		self::put( $state );

		return self::state();
	}

	/**
	 * Put back the settings an import replaced — each one only while it still holds the value
	 * the import wrote (a setting changed since is the site's own choice now).
	 *
	 * @param array<string, mixed> $state State.
	 * @return int Settings restored.
	 */
	public static function restore_settings( array $state ): int {
		$now     = Settings::all();
		$changes = array( 'import_live' => '' );
		$n       = 0;
		foreach ( (array) ( $state['settings']['written'] ?? array() ) as $key => $wrote ) {
			if ( array_key_exists( $key, $now ) && $now[ $key ] === $wrote ) {
				$changes[ $key ] = $state['settings']['before'][ $key ] ?? Settings::defaults()[ $key ] ?? null;
				++$n;
			} elseif ( is_array( $wrote ) && is_array( $now[ $key ] ?? null ) && is_array( $state['settings']['before'][ $key ] ?? null ) ) {
				// A dict (the templates): restore record by record.
				$next   = $now[ $key ];
				$before = (array) $state['settings']['before'][ $key ];
				foreach ( $wrote as $record_key => $record ) {
					if ( ( $next[ $record_key ] ?? null ) === $record ) {
						if ( isset( $before[ $record_key ] ) ) {
							$next[ $record_key ] = $before[ $record_key ];
						} else {
							unset( $next[ $record_key ] );
						}
						++$n;
					}
				}
				$changes[ $key ] = $next;
			}
		}
		Settings::save( $changes, 'import' );

		return $n;
	}

	/**
	 * Start undoing the import: the other plugin back on (when this plugin switched it off),
	 * every copied value removed where it is still the value that was copied, the settings and
	 * the redirects it added taken back.
	 *
	 * @param bool $rollback Called by the switch's own rollback (its job is the caller: not cancelled).
	 * @return true|\WP_Error
	 */
	public static function undo( bool $rollback = false ) {
		$state = self::state();
		if ( 0 === (int) $state['begun'] && null === $state['applied'] ) {
			return new \WP_Error( 'zinn_seo_nothing_to_undo', __( 'Nothing was imported here.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		foreach ( array( self::JOB, Check::JOB, Verify::JOB, Switcher::JOB ) as $job ) {
			if ( $rollback && Switcher::JOB === $job ) {
				continue;
			}
			if ( 'pending' === Jobs::get( $job )['status'] ) {
				Jobs::control( $job, 'cancel' );
			}
		}
		$batches = array();
		if ( is_array( $state['check'] ) && '' !== (string) ( $state['check']['batch'] ?? '' ) ) {
			$batches[] = (string) $state['check']['batch'];
		}
		if ( is_array( $state['applied'] ) && '' !== (string) ( $state['applied']['batch'] ?? '' ) ) {
			$batches[] = (string) $state['applied']['batch'];
		} elseif ( '' !== (string) ( $state['batch'] ?? '' ) ) {
			// A copy that was stopped part-way: its rows are still the record of what it wrote.
			$batches[] = (string) $state['batch'];
		}

		return Jobs::start(
			self::JOB_UNDO,
			array(
				'phase'    => 'plugin',
				'batches'  => $batches,
				'cursor'   => 0,
				'restored' => 0,
				'kept'     => 0,
				'redirect' => 0,
			)
		);
	}

	/**
	 * One step of the undo.
	 *
	 * @param array<string, mixed> $run      State.
	 * @param float                $deadline Microtime.
	 * @return array<string, mixed>
	 */
	public static function undo_step( array $run, float $deadline ): array {
		$state = self::state();
		self::history();
		$done = false;
		while ( ! $done && ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			switch ( (string) $run['phase'] ) {
				case 'plugin':
					$run['reactivated'] = Switcher::reactivate( $state );
					$run['phase']       = 'fields';
					break;
				case 'fields':
					$batch = (string) ( $run['batches'][0] ?? '' );
					if ( '' === $batch ) {
						$run['phase'] = 'redirects';
						break;
					}
					$result           = History::undo_step( $batch, (int) $run['cursor'], $deadline );
					$run['cursor']    = (int) $result['cursor'];
					$run['restored'] += (int) $result['restored'];
					$run['kept']     += (int) $result['kept'];
					if ( $result['done'] ) {
						History::mark_undone( $batch, 'import-undo' );
						array_shift( $run['batches'] );
						$run['cursor'] = 0;
					}
					break;
				case 'redirects':
					$removed          = self::remove_redirects( $state );
					$run['redirect'] += $removed;
					if ( 0 === $removed ) {
						$run['phase'] = 'settings';
					}
					break;
				default:
					$run['settings'] = self::restore_settings( $state );
					$from            = self::source( (string) ( $state['source'] ?? '' ) );
					$run['extras']   = null !== $from ? $from->undo_extras() : 0;
					$state           = array_merge(
						self::state(),
						array(
							'undone'    => time(),
							'begun'     => 0,
							'auto'      => false,
							'applied'   => null,
							'check'     => null,
							'switched'  => null,
							'proof'     => null,
							'switching' => null,
							'batch'     => '',
							'undo'      => array(
								'restored'    => (int) $run['restored'],
								'kept'        => (int) $run['kept'],
								'redirects'   => (int) $run['redirect'],
								'settings'    => (int) $run['settings'],
								'reactivated' => (array) ( $run['reactivated'] ?? array() ),
								'source'      => (string) $state['source'],
							),
							'settings'  => array(
								'before'  => array(),
								'written' => array(),
							),
						)
					);
					self::put( $state );
					delete_option( Check::DIFFS );
					delete_option( Check::BASELINE );
					delete_option( SelfCheck::BEFORE );
					delete_option( self::RUN );
					$done = true;
			}
		}

		return array(
			'state'    => $run,
			'done'     => $done,
			'progress' => array(
				'done'  => (int) $run['restored'] + (int) $run['kept'],
				'total' => null,
			),
		);
	}

	/**
	 * Remove up to 200 of the redirects this import ADDED (rules it replaced keep the imported
	 * target: the rule they replaced is not kept).
	 *
	 * @param array<string, mixed> $state State.
	 * @return int Removed.
	 */
	public static function remove_redirects( array $state ): int {
		$source = self::source( (string) $state['source'] );
		$store  = '\ZinnDigital\ZinnSEO\Redirects\Store';
		if ( null === $source || ! class_exists( $store ) || ! $store::ready() || ! array_key_exists( 'redirects_before', $state ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, keyset on its primary key.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d AND origin = %s ORDER BY id ASC LIMIT 200', $store::table(), (int) $state['redirects_before'], self::origin( $source ) ) ) );

		return array() === $ids ? 0 : (int) $store::delete( $ids );
	}

	/**
	 * Boot data for the admin app: is there anything to move from?
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data           = (array) $data;
		$state          = self::state();
		$data['import'] = array(
			'live'   => (string) Settings::get( 'import_live', '' ),
			'auto'   => ! empty( $state['auto'] ) && (int) $state['begun'] > 0 && null === $state['applied'],
			'source' => (string) $state['source'],
		);

		return $data;
	}

	/**
	 * Everything the screen, REST and WP-CLI show.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$state   = self::state();
		$sources = array();
		foreach ( self::sources() as $id => $source ) {
			$present = $source->present();
			$active  = $source->active();
			if ( ! $present && array() === $active ) {
				continue;
			}
			$sources[] = array(
				'id'        => (string) $id,
				'name'      => $source->name(),
				'active'    => array() !== $active,
				'present'   => $present,
				'redirects' => ! $source->has_pages(),
				'notes'     => $source->notes(),
			);
		}
		$jobs = array();
		foreach ( array( self::JOB, Verify::JOB, Check::JOB, Switcher::JOB, self::JOB_UNDO ) as $job ) {
			$record       = Jobs::get( $job );
			$jobs[ $job ] = array(
				'status'   => (string) $record['status'],
				'progress' => (array) $record['progress'],
				'error'    => (string) $record['error'],
			);
		}
		$run = get_option( self::RUN, array() );
		// What was stored as a sentence is said again in the reader's language.
		if ( class_exists( Preflight::class ) ) {
			$state['preflight'] = Preflight::localised( $state['preflight'] ?? null );
		}
		if ( class_exists( Switcher::class ) && '' !== (string) ( $state['switch_error_code'] ?? '' ) ) {
			$state['switch_error'] = Switcher::error_text( (string) $state['switch_error_code'] );
		}

		return array(
			'sources'  => $sources,
			'state'    => $state,
			'jobs'     => $jobs,
			'running'  => is_array( $run ) && isset( $run['phase'] ) ? array(
				'dry'   => ! empty( $run['dry'] ),
				'phase' => (string) $run['phase'],
			) : null,
			'live'     => (string) Settings::get( 'import_live', '' ),
			'others'   => \ZinnDigital\ZinnSEO\Head\Head::other_seo_plugins(),
			'both'     => (bool) Settings::get( 'run_with_others', false ),
			'loopback' => (bool) apply_filters( 'zinn_seo_jobs_loopback', true, self::JOB ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- this plugin's own filter (prefix zinn_seo).
		);
	}
}
