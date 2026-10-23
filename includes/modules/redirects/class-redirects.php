<?php
/**
 * The redirect manager's glue: settings, download, sitemap and Fix-it hooks.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Capabilities;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; every value is a placeholder.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings (all in the one autoloaded option):
 * `redirects_case_sensitive`, `redirects_pass_query`, `redirects_auto` (address changes),
 * `redirects_on_trash` (parent/410/none), `redirects_collapse` (no chains when a person adds a
 * rule), `redirects_404_fallback` (''/home/url) + `redirects_404_target`; and two the index keeps
 * for itself — `redirects_store` and `redirects_regex` — so a visitor's request knows, from the
 * option WordPress already loaded, whether there is anything to look up at all.
 */
final class Redirects {

	/** The admin-post action of the spreadsheet download. */
	public const EXPORT = 'zseo_redirects_csv';

	/**
	 * The module's settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array(
			'redirects_store'          => array(
				'type'    => 'enum',
				'default' => '',
				'choices' => array( '', 'files', 'db' ),
				'group'   => 'redirects',
				'export'  => false,
			),
			'redirects_regex'          => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'group'   => 'redirects',
				'export'  => false,
			),
			'redirects_case_sensitive' => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'redirects',
			),
			'redirects_pass_query'     => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'redirects',
			),
			'redirects_auto'           => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'redirects',
			),
			'redirects_on_trash'       => array(
				'type'    => 'enum',
				'default' => 'parent',
				'choices' => array( 'parent', '410', 'none' ),
				'group'   => 'redirects',
			),
			// 0.17.65: per content type (`post:<type>`) and for categories and tags (`term:all`);
			// an action left empty follows `redirects_on_trash`.
			'redirects_on_trash_by'    => array(
				'type'    => 'dict',
				'default' => array(),
				'fields'  => array(
					'action' => array(
						'type'    => 'enum',
						'default' => '',
						'choices' => array( '', 'parent', '410', 'none' ),
					),
				),
				'group'   => 'redirects',
			),
			'redirects_collapse'       => array(
				'type'    => 'bool',
				'default' => true,
				'group'   => 'redirects',
			),
			'redirects_404_fallback'   => array(
				'type'    => 'enum',
				'default' => '',
				'choices' => array( '', 'home', 'url' ),
				'group'   => 'redirects',
			),
			'redirects_404_target'     => array(
				'type'    => 'url',
				'default' => '',
				'group'   => 'redirects',
			),
		);
	}

	/**
	 * Admin, REST, CLI and cron hooks.
	 *
	 * @param string $context Context.
	 * @return void
	 */
	public static function boot( string $context ): void {
		Index::boot();
		Checker::boot();
		Importer::boot();
		Auto::boot();
		add_filter( 'zinn_seo_sitemap_entry', array( self::class, 'sitemap_entry' ), 5 );
		add_filter( 'zinn_seo_fixit_sources', array( self::class, 'fixit_sources' ) );
		add_action( 'zinn_seo_fixit_actions', array( self::class, 'fixit_actions' ) );
		// Deactivation removes the Pro must-use file whatever the licence says by then.
		add_action( 'deactivate_' . plugin_basename( ZINN_SEO_FILE ), array( self::class, 'remove_dropin' ) );
		if ( 'admin' === $context ) {
			self::orphan_dropin();
			add_action( 'admin_post_' . self::EXPORT, array( self::class, 'download' ) );
			add_filter( 'zinn_seo_admin_data', array( self::class, 'admin_data' ) );
		}
	}

	/**
	 * The Pro "fast redirects" must-use file left behind when Pro no longer runs here (licence
	 * ended, module off): remove it, so redirects are answered by what is running.
	 *
	 * @return void
	 */
	public static function orphan_dropin(): void {
		if ( ! \ZinnDigital\ZinnSEO\Modules::enabled( 'redirects-pro' ) ) {
			self::remove_dropin();
			// The same for the rules Pro kept in .htaccess (§2.62): left there, the web server went on
			// answering redirects the Redirects screen had changed or deleted since.
			self::clear_htaccess();
		}
	}

	/**
	 * Remove the redirects block Pro wrote into `.htaccess` (only that block, only when it is
	 * there; the rest of the file is the site's own and is left byte for byte).
	 *
	 * @return bool Whether a block was removed.
	 */
	public static function clear_htaccess(): bool {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = trailingslashit( (string) get_home_path() ) . '.htaccess';
		if ( ! is_file( $file ) || ! is_writable( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- one local file.
			return false;
		}
		$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the site's own .htaccess, read to find our block.
		if ( 1 !== preg_match( '/^# BEGIN ZD redirects\R.*?^# END ZD redirects\R?\R?/ms', $body, $m ) ) {
			return false;
		}

		return false !== file_put_contents( $file, str_replace( $m[0], '', $body ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
	}

	/**
	 * Remove the Pro must-use file (only the one this plugin wrote).
	 *
	 * @return void
	 */
	public static function remove_dropin(): void {
		$file = trailingslashit( WPMU_PLUGIN_DIR ) . 'zd-redirects.php';
		if ( file_exists( $file ) && false !== strpos( (string) file_get_contents( $file ), 'redirects-pro/early.php' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local file.
			wp_delete_file( $file );
		}
	}

	/**
	 * The spreadsheet download (admin-post, nonce-checked).
	 *
	 * @return void
	 */
	public static function download(): void {
		if ( ! current_user_can( Capabilities::REDIRECTS ) || ! check_admin_referer( self::EXPORT ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'zinn-seo' ), 403 );
		}
		Export::send_csv();
	}

	/**
	 * A page that redirects does not belong in the sitemap (P2's builder asks here).
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return array<string, mixed> Empty to leave it out.
	 */
	public static function sitemap_entry( $entry ): array {
		$entry = (array) $entry;
		if ( '' === Index::store() || empty( $entry['loc'] ) || ! Store::is_local( (string) $entry['loc'] ) ) {
			return $entry;
		}
		$path  = (string) wp_parse_url( (string) $entry['loc'], PHP_URL_PATH );
		$found = Index::find( Path::lookups( '' === $path ? '/' : $path, Store::base(), Store::sensitive() ) );

		return null === $found ? $entry : array();
	}

	/**
	 * The Redirects screen's boot data.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data              = (array) $data;
		$data['redirects'] = array(
			'canManage' => current_user_can( Capabilities::REDIRECTS ),
			'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT ), self::EXPORT ),
			'early'     => Index::REGEX_EARLY,
			'codes'     => Store::CODES,
			// The public content types, for the per-type "deleted or unpublished" choice.
			'types'     => array_values(
				array_map(
					static fn( \WP_Post_Type $t ): array => array(
						'name'  => $t->name,
						'label' => (string) $t->labels->name,
					),
					array_filter( get_post_types( array( 'public' => true ), 'objects' ), static fn( $t ): bool => 'attachment' !== $t->name )
				)
			),
		);

		return $data;
	}

	/**
	 * Fix-it sources.
	 *
	 * @param array<string, callable> $sources Sources.
	 * @return array<string, callable>
	 */
	public static function fixit_sources( $sources ): array {
		$sources              = (array) $sources;
		$sources['redirects'] = array( self::class, 'issues' );

		return $sources;
	}

	/**
	 * The one-click fix.
	 *
	 * @return void
	 */
	public static function fixit_actions(): void {
		if ( class_exists( '\ZinnDigital\ZinnSEO\Fixit\Fixit' ) ) {
			\ZinnDigital\ZinnSEO\Fixit\Fixit::action(
				'fix-redirect-chains',
				static function () {
					Checker::fix();
					return true;
				}
			);
		}
	}

	/**
	 * Redirect problems for the Fix-it inbox.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function issues(): array {
		if ( ! Store::ready() ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT problem, COUNT(*) AS n FROM %i WHERE problem <> '' GROUP BY problem", Store::table() ), ARRAY_A );
		$by   = array_column( $rows, 'n', 'problem' );
		$url  = admin_url( 'admin.php?page=zinn-seo&view=redirects' );
		$out  = array();
		$fix  = (int) ( $by['chain'] ?? 0 ) + (int) ( $by['loop'] ?? 0 );
		if ( $fix > 0 ) {
			$out[] = array(
				'id'       => 'redirect-chains',
				'area'     => 'technical',
				'priority' => 70,
				/* translators: %d: number of redirects. */
				'title'    => sprintf( _n( '%d redirect goes through another redirect or round in a circle', '%d redirects go through another redirect or round in a circle', $fix, 'zinn-seo' ), $fix ),
				'detail'   => __( 'Each extra hop slows visitors down and search engines give up after a few; a loop cannot be opened at all. Fixing points every chain straight at its final address and switches loops off for you to look at.', 'zinn-seo' ),
				'count'    => $fix,
				'minutes'  => 1,
				'fix'      => array(
					'kind'  => 'action',
					'id'    => 'fix-redirect-chains',
					'label' => __( 'Fix the chains', 'zinn-seo' ),
				),
			);
		}
		foreach ( array( 'shadows', 'missing' ) as $kind ) {
			$n = (int) ( $by[ $kind ] ?? 0 );
			if ( $n < 1 ) {
				continue;
			}
			$out[] = array(
				'id'       => 'redirect-' . $kind,
				'area'     => 'technical',
				'priority' => 'shadows' === $kind ? 65 : 55,
				'title'    => 'shadows' === $kind
					/* translators: %d: number of redirects. */
					? sprintf( _n( '%d redirect hides a page that is published again', '%d redirects hide pages that are published again', $n, 'zinn-seo' ), $n )
					/* translators: %d: number of redirects. */
					: sprintf( _n( '%d redirect sends visitors to a missing page', '%d redirects send visitors to missing pages', $n, 'zinn-seo' ), $n ),
				'detail'   => 'shadows' === $kind
					? __( 'Visitors asking for these addresses are sent away from a live page. Delete the redirect if the page should be seen.', 'zinn-seo' )
					: __( 'The address these redirects point at answers "not found". Point them somewhere that exists.', 'zinn-seo' ),
				'count'    => $n,
				'minutes'  => 3,
				'fix'      => array(
					'kind'  => 'link',
					'url'   => $url . '#/redirects?problem=' . $kind,
					'label' => __( 'Open the redirects', 'zinn-seo' ),
				),
			);
		}

		return $out;
	}
}
