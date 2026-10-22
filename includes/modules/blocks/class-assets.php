<?php
/**
 * Block stylesheets, served from a neutral path and only on pages that use the block.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ zs-152 + §2.22: a page with no SEO block gets NO stylesheet from this plugin; a page with
 * blocks gets the styles of THOSE blocks and nothing else.
 *
 * How they arrive is WordPress core's own rule for block styles: small styles are printed inline
 * in the head as ONE `<style>` (no extra request; core inlines block styles up to 20 KB), and only
 * when a page's blocks need more than that are they linked as files. The files are copies in
 * `uploads/zd-css/<sha1-12>.css` (the Tranzly/PBS pattern of docs/adr/0033), so no page ever names
 * `/plugins/zinn-seo/`; the handles are `<prefix>-<hash>`. The copies are written in wp-admin when
 * the version or prefix changed (a front-end request never writes to disk); a missing copy is
 * printed inline instead. A block rendered after the head (outside the post, in a classic theme)
 * still gets its style, printed by WordPress with the late styles.
 */
final class Assets {

	/** The folder under uploads. */
	public const FOLDER = 'zd-css';

	/**
	 * The folder under uploads this site's copies live in: `zd-css`, or `<prefix>-css` when the
	 * site has a prefix of its own (no-trace mode, zs-747), so no address is shared across sites.
	 *
	 * @return string
	 */
	public static function folder(): string {
		$prefix = Html::prefix();

		return 'zd' === $prefix ? self::FOLDER : $prefix . '-css';
	}

	/** Not autoloaded: the version and prefix the copies were written for (read in wp-admin only). */
	public const OPTION = 'zseo_block_css';

	/** The token a source uses where the class prefix belongs. */
	private const PREFIX_TOKEN = '__PREFIX__';

	/**
	 * Bodies computed in this request: file => [body, hash].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $bodies = array();

	/**
	 * Blocks whose style this page already has.
	 *
	 * @var array<string, bool>
	 */
	private static array $done = array();

	/**
	 * Blocks rendered before the head, waiting for the page's set.
	 *
	 * @var array<string, Block>
	 */
	private static array $pending = array();

	/**
	 * Republish in wp-admin when the copies were written for another version or prefix.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_publish' ) );
	}

	/**
	 * Republish when needed.
	 *
	 * @return void
	 */
	public static function maybe_publish(): void {
		$stamp = ZINN_SEO_VERSION . '|' . Html::prefix();
		if ( get_option( self::OPTION ) !== $stamp ) {
			self::publish();
			update_option( self::OPTION, $stamp, false );
		}
	}

	/**
	 * Write every block's stylesheet and remove copies no block uses any more.
	 *
	 * @return int Files written or already current.
	 */
	public static function publish(): int {
		$dir = self::dir();
		$fs  = self::filesystem();
		if ( '' === $dir || null === $fs || ! wp_mkdir_p( $dir ) ) {
			return 0;
		}
		$keep = array();
		foreach ( Library::all() as $block ) {
			if ( ! $block->has_style() ) {
				continue;
			}
			$css = self::body( $block->style_file() );
			if ( null === $css ) {
				continue;
			}
			$name   = $css[1] . '.css';
			$keep[] = $name;
			$path   = $dir . '/' . $name;
			if ( ! is_file( $path ) ) {
				$fs->put_contents( $path, $css[0], FS_CHMOD_FILE );
			}
		}
		foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
			$base = basename( (string) $file );
			if ( 1 === preg_match( '/^[a-f0-9]{12}\.css$/', $base ) && ! in_array( $base, $keep, true ) ) {
				wp_delete_file( (string) $file );
			}
		}

		return count( $keep );
	}

	/** Inline at most this many bytes in a page (WordPress core's `styles_inline_size_limit`). */
	public const INLINE_LIMIT = 20000;

	/**
	 * Enqueue the styles of the blocks a page holds, before its head prints: one inline style for
	 * all of them while they are small, else one file each.
	 *
	 * @param array<int, Block> $blocks Blocks on the page.
	 * @return void
	 */
	public static function enqueue_set( array $blocks ): void {
		// Blocks a block theme rendered before the head joined the set (see enqueue()).
		foreach ( self::$pending as $block ) {
			$blocks[] = $block;
		}
		self::$pending = array();
		$bodies        = array();
		foreach ( $blocks as $block ) {
			if ( isset( self::$done[ $block->name() ] ) ) {
				continue;
			}
			if ( $block->has_style() ) {
				$css = self::body( $block->style_file() );
				if ( null !== $css ) {
					$bodies[ $block->name() ] = $css;
				}
			}
		}
		if ( array() === $bodies ) {
			return;
		}
		$total = (int) array_sum( array_map( static fn( array $c ): int => strlen( $c[0] ), $bodies ) );

		/**
		 * Filters the most CSS the blocks print inline in one page (bytes; 0 = always files).
		 *
		 * @param int $limit Bytes.
		 */
		if ( $total <= (int) apply_filters( 'zinn_seo_block_css_inline_limit', self::INLINE_LIMIT ) ) {
			$handle = Html::prefix() . '-' . substr( sha1( implode( '', array_keys( $bodies ) ) ), 0, 8 );
			wp_register_style( $handle, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, wp_strip_all_tags( \ZinnDigital\ZinnSEO\Plugin::css_for_inline( implode( '', array_column( $bodies, 0 ) ) ) ) );
			foreach ( array_keys( $bodies ) as $name ) {
				self::$done[ $name ] = true;
			}
			return;
		}
		foreach ( $blocks as $block ) {
			self::enqueue( $block, true );
		}
	}

	/**
	 * Enqueue one block's style (once a page): its file when one is published and the style is
	 * large, else inline. Used for blocks found only when they render.
	 *
	 * A block theme renders its templates BEFORE the head: a block rendered then joins the page's
	 * one set, enqueued when the head's styles are (`wp_enqueue_scripts`).
	 *
	 * @param Block $block Block.
	 * @param bool  $now   Enqueue now (the set is being enqueued).
	 * @return void
	 */
	public static function enqueue( Block $block, bool $now = false ): void {
		if ( isset( self::$done[ $block->name() ] ) || ! $block->has_style() ) {
			return;
		}
		if ( ! $now && ! did_action( 'wp_enqueue_scripts' ) && ! doing_action( 'wp_enqueue_scripts' ) ) {
			if ( array() === self::$pending ) {
				add_action(
					'wp_enqueue_scripts',
					static function (): void {
						self::enqueue_set( array() );
					},
					21
				);
			}
			self::$pending[ $block->name() ] = $block;
			return;
		}
		$css = self::body( $block->style_file() );
		if ( null === $css ) {
			return;
		}
		self::$done[ $block->name() ] = true;
		$handle                       = Html::prefix() . '-' . substr( $css[1], 0, 8 );
		$dir                          = self::dir();
		if ( strlen( $css[0] ) > 4096 && '' !== $dir && is_file( $dir . '/' . $css[1] . '.css' ) ) {
			$uploads = wp_upload_dir( null, false );
			wp_enqueue_style( $handle, trailingslashit( (string) $uploads['baseurl'] ) . self::folder() . '/' . $css[1] . '.css', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the file name IS the content hash; a ?ver= would only add bytes.
			return;
		}
		wp_register_style( $handle, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline only.
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, wp_strip_all_tags( \ZinnDigital\ZinnSEO\Plugin::css_for_inline( $css[0] ) ) );
	}

	/**
	 * Forget what this request enqueued (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$done    = array();
		self::$pending = array();
	}

	/**
	 * Delete every copy (uninstall).
	 *
	 * @return void
	 */
	public static function remove_all(): void {
		$dir = self::dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
			if ( 1 === preg_match( '/^[a-f0-9]{12}\.css$/', basename( (string) $file ) ) ) {
				wp_delete_file( (string) $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only when empty; a non-empty folder stays.
		delete_option( self::OPTION );
	}

	/**
	 * A stylesheet with the prefix in place, and its content hash.
	 *
	 * @param string $file Source path.
	 * @return array{0: string, 1: string}|null
	 */
	public static function body( string $file ): ?array {
		$key = $file . '|' . Html::prefix();
		if ( isset( self::$bodies[ $key ] ) ) {
			return self::$bodies[ $key ];
		}
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.
		if ( false === $raw ) {
			return null;
		}
		// Comments and spacing out: they are bytes every visitor would download.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $raw );
		$css = trim( (string) preg_replace( array( '/\s+/', '/\s*([{}:;,>])\s*/', '/;}/' ), array( ' ', '$1', '}' ), $css ) );
		$css = str_replace( self::PREFIX_TOKEN, Html::prefix(), $css );

		self::$bodies[ $key ] = array( $css, substr( sha1( $css ), 0, 12 ) );

		return self::$bodies[ $key ];
	}

	/**
	 * The folder's path ('' when uploads are unavailable).
	 *
	 * @return string
	 */
	private static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( (string) $uploads['basedir'] ) . self::folder();
	}

	/**
	 * A direct-access filesystem, or null when writing would need credentials.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem(): ?\WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return null;
		}
		global $wp_filesystem;

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}
}
