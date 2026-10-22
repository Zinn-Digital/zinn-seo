<?php
/**
 * The one way Zinn SEO changes a page's content: in every place the page is RENDERED from
 * (Q328 / ZH6-9).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Content;

use ZinnDigital\ZinnSEO\Analysis\Render;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ A PAGE BUILDER RENDERS FROM ITS OWN DATA, NOT FROM THE CONTENT BOX.
 *
 * ZH6 (zinnhub, 0.17.39) applied 11 link fixes to 10 Elementor pages: `post_content` changed, the
 * rows said "applied", and visitors saw the old links, because Elementor draws the page from
 * `_elementor_data`. Every feature that edits page content (the links fix zs-778, the link plan
 * zs-763, outbound edits zs-779, broken links, incoming links, AI anchors, internal link updates
 * zs-730) now goes through this class:
 *
 * - `open()` reads the content box AND each builder's stored layout: Elementor (`_elementor_data`,
 *   JSON), Bricks (`_bricks_page_content_2`), Beaver Builder (`_fl_builder_data` and its draft),
 *   Oxygen (`_ct_builder_json`, `ct_builder_shortcodes`), Breakdance (`_breakdance_data`, JSON
 *   inside JSON) and Thrive (`tve_updated_post`). Divi and WPBakery keep their shortcodes in the
 *   content box, with addresses in shortcode attributes (`button_url="…"`,
 *   `link="url:<encoded>|…"`); block-editor and classic pages are the content box.
 * - `apply()` runs ONE HTML change over all of them: every HTML piece of a layout, and every bare
 *   address a layout keeps in a setting (a button's link), which is changed exactly as the same
 *   change would change `<a href="that address">`.
 * - `save()` writes what changed — the layout first, then the content box as an ordinary update
 *   (one revision; the builder copies its layout into it). A layout-only change makes the revision
 *   without `wp_update_post()`, so other plugins' save hooks (zinn-translate re-slugs on every
 *   save, Q329) do not run for nothing. Elementor's element cache and CSS, and Beaver Builder's
 *   asset cache, are refreshed.
 * - `render_before()` / `render_after()` are the page as the analysis renders it (the builder's
 *   own output); a caller reports "applied" only when the change shows there, and `rollback()`s
 *   a save that changed nothing a visitor sees.
 * - `snapshot()` / `restore()` are the run-level undo: the content box and each layout, byte for
 *   byte, while the page is still as the run left it (`state_hash()`).
 */
final class Writer {

	/** Builder layouts kept in post meta, and the builder each belongs to. */
	public const BUILDER_KEYS = array(
		'_elementor_data'        => 'elementor',
		'_bricks_page_content_2' => 'bricks',
		'_fl_builder_data'       => 'beaver',
		'_fl_builder_draft'      => 'beaver',
		'_ct_builder_json'       => 'oxygen',
		'ct_builder_shortcodes'  => 'oxygen',
		'_breakdance_data'       => 'breakdance',
		'tve_updated_post'       => 'thrive',
	);

	/** The words inside the stand-in link `via_html()` hands to a change. */
	private const MARK = 'zseo-writer-mark';

	/** A bare address kept in a builder setting. */
	private const URL = '#^(?:https?:)?//[^\s<>"\']+$|^/[^\s<>"\']*$#i';

	/** Shortcode builders whose attributes hold addresses (Divi, WPBakery). */
	private const SHORTCODE = '#\[(et_pb_[a-z0-9_]+|vc_[a-z0-9_]+)\b([^\]]*)\]#i';

	/** One attribute of such a shortcode: name="value". */
	private const ATTR = '#(\s[a-z0-9_-]+=)(["\'])([^"\']*)\2#i';

	/**
	 * Post.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $post;

	/**
	 * Content box now.
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Content box as opened.
	 *
	 * @var string
	 */
	private string $content_before;

	/**
	 * Layouts now, key => value as get_post_meta() gives it.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta;

	/**
	 * Layouts as opened.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta_before;

	/**
	 * Rendered page as opened (lazy).
	 *
	 * @var string|null
	 */
	private ?string $render_before = null;

	/**
	 * Rendered page after save (lazy).
	 *
	 * @var string|null
	 */
	private ?string $render_after = null;

	/**
	 * Open a page.
	 *
	 * @param int $id Post.
	 * @return self|null Null when there is no such post.
	 */
	public static function open( int $id ): ?self {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$w                 = new self();
		$w->post           = $post;
		$w->content        = (string) $post->post_content;
		$w->content_before = $w->content;
		$w->meta           = self::layouts( $id );
		$w->meta_before    = $w->meta;

		return $w;
	}

	/**
	 * The layout meta keys read (filterable for another builder that keeps HTML in post meta).
	 *
	 * @return array<string, string> key => builder.
	 */
	public static function keys(): array {
		/**
		 * Filters the post meta keys that hold a page builder's layout (key => builder name).
		 *
		 * @param array<string, string> $keys Keys.
		 */
		$keys = apply_filters( 'zinn_seo_content_writer_keys', self::BUILDER_KEYS );

		return is_array( $keys ) ? $keys : self::BUILDER_KEYS;
	}

	/**
	 * Each layout this page holds.
	 *
	 * @param int $id Post.
	 * @return array<string, mixed>
	 */
	private static function layouts( int $id ): array {
		$out = array();
		foreach ( array_keys( self::keys() ) as $key ) {
			if ( ! metadata_exists( 'post', $id, $key ) ) {
				continue;
			}
			$value = get_post_meta( $id, $key, true );
			if ( '' !== $value && null !== $value && array() !== $value ) {
				$out[ $key ] = $value;
			}
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Which builder made this page ('' for block-editor or classic content).
	 *
	 * @param int $id Post.
	 * @return string
	 */
	public static function builder( int $id ): string {
		$keys = self::keys();
		foreach ( array_keys( self::layouts( $id ) ) as $key ) {
			return (string) $keys[ $key ];
		}
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		if ( 1 === preg_match( '#\[et_pb_#', $content ) ) {
			return 'divi';
		}

		return 1 === preg_match( '#\[vc_row\b#', $content ) ? 'wpbakery' : '';
	}

	/**
	 * The post.
	 *
	 * @return \WP_Post
	 */
	public function post(): \WP_Post {
		return $this->post;
	}

	/**
	 * The content box now.
	 *
	 * @return string
	 */
	public function content(): string {
		return $this->content;
	}

	/**
	 * Run one HTML change over every place the page renders from.
	 *
	 * @param callable $change `function ( string $html ): ?string` (null: nothing to change).
	 * @param bool     $once   At most one piece per place changes (adding ONE link: the content
	 *                         box gets it once, and each layout gets it once).
	 * @return int Pieces changed (0: the change found nothing anywhere).
	 */
	public function apply( callable $change, bool $once = false ): int {
		$n             = 0;
		$next          = self::content_with( $this->content, $change, $once, $n );
		$this->content = $next;
		foreach ( $this->meta as $key => $value ) {
			$c                  = 0;
			$this->meta[ $key ] = self::value_with( $value, $change, $once, $c );
			$n                 += $c;
		}

		return $n;
	}

	/**
	 * The content box with a change run over it: the HTML, then a shortcode builder's address
	 * attributes. Pure.
	 *
	 * @param string   $content Content.
	 * @param callable $change  Change.
	 * @param bool     $once    See apply().
	 * @param int      $n       Out (added to): pieces changed.
	 * @return string
	 */
	public static function content_with( string $content, callable $change, bool $once, int &$n ): string {
		$next = self::html( $change, $content );
		if ( $next !== $content ) {
			++$n;
			if ( $once ) {
				return $next;
			}
		}
		if ( false === strpos( $next, '[et_pb_' ) && false === strpos( $next, '[vc_' ) ) {
			return $next;
		}
		$found = 0;
		$out   = (string) preg_replace_callback(
			self::SHORTCODE,
			static function ( array $m ) use ( $change, &$found ): string {
				$attrs = (string) preg_replace_callback(
					self::ATTR,
					static function ( array $a ) use ( $change, &$found ): string {
						$value = $a[3];
						if ( 0 === strpos( $value, 'url:' ) ) {
							// WPBakery's vc_link: url:<rawurlencoded>|title:…|target:… (the address is the first part).
							$parts = explode( '|', $value );
							$url   = rawurldecode( substr( $parts[0], 4 ) );
							$to    = self::via_html( $change, $url );
							if ( $to === $url ) {
								return $a[0];
							}
							++$found;
							$parts[0] = 'url:' . rawurlencode( $to );
							return $a[1] . $a[2] . implode( '|', $parts ) . $a[2];
						}
						if ( 1 !== preg_match( self::URL, $value ) ) {
							return $a[0];
						}
						$to = self::via_html( $change, $value );
						if ( $to === $value ) {
							return $a[0];
						}
						++$found;
						return $a[1] . $a[2] . $to . $a[2];
					},
					$m[2]
				);
				return '[' . $m[1] . $attrs . ']';
			},
			$next
		);
		if ( $found > 0 ) {
			++$n;
		}

		return $out;
	}

	/**
	 * A stored layout with a change run over every HTML piece and every bare address in it —
	 * arrays, objects (Beaver Builder's nodes) and JSON kept as text (Elementor; Breakdance's
	 * JSON inside JSON), re-encoded the way it was written. Pure; the value given is not altered.
	 *
	 * @param mixed    $value  Value.
	 * @param callable $change Change.
	 * @param bool     $once   See apply().
	 * @param int      $n      Out (added to): pieces changed.
	 * @return mixed
	 */
	public static function value_with( $value, callable $change, bool $once, int &$n ) {
		if ( $once && $n > 0 ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::value_with( $v, $change, $once, $n );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			$copy = clone $value;
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$copy->{$k} = self::value_with( $v, $change, $once, $n );
			}
			return $copy;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$first = substr( ltrim( $value ), 0, 1 );
		if ( '{' === $first || '[' === $first ) {
			$data = json_decode( $value );
			if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $data ) || is_object( $data ) ) ) {
				$before = $n;
				$data   = self::value_with( $data, $change, $once, $n );
				return $n > $before ? self::encode_like( $data, $value ) : $value;
			}
		}
		if ( 1 === preg_match( self::URL, $value ) ) {
			$next = self::via_html( $change, $value );
		} elseif ( false !== strpos( $value, '<' ) ) {
			$next = self::html( $change, $value );
		} else {
			return $value; // Plain words or a setting: never turned into HTML.
		}
		if ( $next !== $value ) {
			++$n;
		}

		return $next;
	}

	/**
	 * JSON written the way the original was (escaped slashes and unicode, or not). Pure.
	 *
	 * @param mixed  $data Data.
	 * @param string $like The original text.
	 * @return string
	 */
	private static function encode_like( $data, string $like ): string {
		$flags = 0;
		if ( false === strpos( $like, '\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( false === strpos( $like, '\u' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}
		$out = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the builder's own encoding is kept byte for byte; wp_json_encode() would add its own.

		return false === $out ? $like : $out;
	}

	/**
	 * A change run over HTML (null and non-text answers: unchanged). Pure.
	 *
	 * @param callable $change Change.
	 * @param string   $html   HTML.
	 * @return string
	 */
	private static function html( callable $change, string $html ): string {
		$out = $change( $html );

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * A bare address changed exactly as the change would change `<a href="address">`: the new
	 * address, '' when the change unlinks it, or the address itself. Pure.
	 *
	 * @param callable $change Change.
	 * @param string   $url    Address.
	 * @return string
	 */
	public static function via_html( callable $change, string $url ): string {
		$wrap = '<a href="' . htmlspecialchars( $url, ENT_QUOTES ) . '">' . self::MARK . '</a>';
		$out  = self::html( $change, $wrap );
		if ( $out === $wrap ) {
			return $url;
		}
		if ( self::MARK === $out ) {
			return '';
		}
		if ( 1 === preg_match( '#^<a\b[^>]*?\shref\s*=\s*(["\'])(.*?)\1[^>]*>' . self::MARK . '</a>$#s', $out, $m ) ) {
			return htmlspecialchars_decode( $m[2], ENT_QUOTES );
		}

		return $url; // Something else (a rel, words added): a bare address cannot hold it.
	}

	/**
	 * How many links in some HTML point at an address, matched as internal link updates match
	 * them (any form of the address: absolute, relative, with or without the trailing slash), the
	 * address itself only.
	 *
	 * @param string $html HTML.
	 * @param string $url  Address.
	 * @return int
	 */
	public static function links_to( string $html, string $url ): int {
		$n = 0;
		if ( '' === $url || '' === $html ) {
			return 0;
		}
		\ZinnDigital\ZinnSEO\LinkUpdates\Link_Updates::rewrite( $html, $url, home_url( '/zseo-count/' ), home_url( '/' ), $n, true );

		return $n;
	}

	/**
	 * Did anything change since open()?
	 *
	 * @return bool
	 */
	public function changed(): bool {
		return $this->content !== $this->content_before || $this->changed_keys() !== array();
	}

	/**
	 * Layout keys whose value changed.
	 *
	 * @return array<int, string>
	 */
	private function changed_keys(): array {
		$out = array();
		foreach ( $this->meta as $key => $value ) {
			if ( serialize( $value ) !== serialize( $this->meta_before[ $key ] ?? null ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a byte comparison of two values, never stored.
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * The page as rendered when opened.
	 *
	 * @return string
	 */
	public function render_before(): string {
		if ( null === $this->render_before ) {
			$this->render_before = self::render( $this->post->ID );
		}

		return $this->render_before;
	}

	/**
	 * The page as rendered now (after save()).
	 *
	 * @return string
	 */
	public function render_after(): string {
		if ( null === $this->render_after ) {
			$this->render_after = self::render( $this->post->ID );
		}

		return $this->render_after;
	}

	/**
	 * A page as the analysis renders it: the builder's own output for a built page.
	 *
	 * @param int $id Post.
	 * @return string
	 */
	public static function render( int $id ): string {
		clean_post_cache( $id );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! class_exists( Render::class ) ) {
			return '';
		}
		$html = Render::fresh( $post );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Write what changed: layouts, then the content box (one revision either way).
	 *
	 * @return true|\WP_Error
	 */
	public function save() {
		$keys = $this->changed_keys();
		$box  = $this->content !== $this->content_before;
		if ( ! $box && array() === $keys ) {
			return true;
		}
		$saved = self::write( $this->post->ID, $box ? $this->content : null, array_intersect_key( $this->meta, array_flip( $keys ) ), $this->meta_before );
		if ( ! is_wp_error( $saved ) ) {
			$this->render_after = null;
			$post               = get_post( $this->post->ID );
			if ( $post instanceof \WP_Post ) {
				$this->post = $post;
			}
		}

		return $saved;
	}

	/**
	 * Save, and keep the save only when the page as visitors get it shows one more link to each
	 * address (interactive "Add link" tools). Otherwise the page goes back as it was.
	 *
	 * @param self   $w     Writer with the links added.
	 * @param string ...$hrefs Addresses linked.
	 * @return true|\WP_Error
	 */
	public static function save_shown( self $w, string ...$hrefs ) {
		$before = array();
		foreach ( $hrefs as $i => $href ) {
			$before[ $i ] = self::links_to( $w->render_before(), $href );
		}
		$saved = $w->save();
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		foreach ( $hrefs as $i => $href ) {
			if ( self::links_to( $w->render_after(), $href ) <= $before[ $i ] ) {
				$w->rollback();
				return new \WP_Error( 'zinn_seo_not_rendered', __( 'The page as visitors see it did not change (it may be built with a page builder Zinn® SEO cannot edit), so nothing was changed. Open the page in its editor and add the link there.', 'zinn-seo' ), array( 'status' => 409 ) );
			}
		}

		return true;
	}

	/**
	 * Put the page back as it was opened (a save that showed nothing to visitors).
	 *
	 * @return true|\WP_Error
	 */
	public function rollback() {
		$keys  = $this->changed_keys();
		$box   = $this->content !== $this->content_before;
		$saved = self::write( $this->post->ID, $box ? $this->content_before : null, array_intersect_key( $this->meta_before, array_flip( $keys ) ), $this->meta );
		if ( ! is_wp_error( $saved ) ) {
			$this->content = $this->content_before;
			$this->meta    = $this->meta_before;
		}

		return $saved;
	}

	/**
	 * The page as opened, for undo: `content` (the content box) and `layouts` (packed).
	 *
	 * @return array{content: string, layouts: string}
	 */
	public function snapshot(): array {
		return array(
			'content' => $this->content_before,
			'layouts' => self::pack_layouts( $this->meta_before ),
		);
	}

	/**
	 * A fingerprint of the page's content box and layouts now: undo restores only while the page
	 * is still exactly as a run left it.
	 *
	 * @param int $id Post.
	 * @return string
	 */
	public static function state_hash( int $id ): string {
		clean_post_cache( $id );

		return md5( (string) get_post_field( 'post_content', $id, 'raw' ) . "\0" . serialize( self::layouts( $id ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint, never stored as data.
	}

	/**
	 * Put a page back: the content box, and each layout the snapshot holds.
	 *
	 * @param int         $id      Post.
	 * @param string      $content Content box.
	 * @param string|null $layouts Packed layouts (null: rows written before layouts were kept).
	 * @return true|\WP_Error
	 */
	public static function restore( int $id, string $content, ?string $layouts ) {
		$w = self::open( $id );
		if ( null === $w ) {
			return new \WP_Error( 'zinn_seo_gone', __( 'That page no longer exists.', 'zinn-seo' ) );
		}
		$w->content = $content;
		$meta       = null === $layouts ? array() : self::unpack_layouts( $layouts );
		if ( null === $meta ) {
			return new \WP_Error( 'zinn_seo_undo_data', __( 'The saved copy of this page could not be read.', 'zinn-seo' ) );
		}
		foreach ( $meta as $key => $value ) {
			$w->meta[ (string) $key ] = $value;
		}

		return $w->save();
	}

	/**
	 * Layouts packed for a row. Pure.
	 *
	 * @param array<string, mixed> $layouts Layouts.
	 * @return string
	 */
	public static function pack_layouts( array $layouts ): string {
		if ( array() === $layouts ) {
			return '';
		}
		$raw = serialize( $layouts ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a builder's own stored value (arrays and stdClass), read back with allowed_classes limited to stdClass.
		$z   = function_exists( 'gzdeflate' ) ? gzdeflate( $raw, 6 ) : false;

		return false === $z ? 'raw:' . base64_encode( $raw ) : 'z:' . base64_encode( $z ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a binary-safe copy of the page's own layout for undo, never code.
	}

	/**
	 * Back from pack_layouts(). Pure.
	 *
	 * @param string $packed Packed.
	 * @return array<string, mixed>|null
	 */
	public static function unpack_layouts( string $packed ): ?array {
		if ( '' === $packed ) {
			return array();
		}
		$raw = false;
		if ( 0 === strpos( $packed, 'raw:' ) ) {
			$raw = base64_decode( substr( $packed, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see pack_layouts().
		} elseif ( 0 === strpos( $packed, 'z:' ) && function_exists( 'gzinflate' ) ) {
			$z   = base64_decode( substr( $packed, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see pack_layouts().
			$raw = false === $z ? false : gzinflate( $z );
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		// Q215 semgrep: our own packed copy of a value, and allowed_classes is stdClass only, so no object of any other class can be made. Re-check if allowed_classes is widened or $raw can come from outside the site.
		// nosemgrep: php.lang.security.unserialize-use.unserialize-use -- the reason is on the line above.
		$out = @unserialize( $raw, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- our own packed copy; only stdClass objects are allowed back.

		return is_array( $out ) ? $out : null;
	}

	/**
	 * Write layouts and the content box, then refresh the builders' caches.
	 *
	 * @param int                  $id      Post.
	 * @param string|null          $content Content box (null: unchanged).
	 * @param array<string, mixed> $layouts Layouts to write.
	 * @param array<string, mixed> $undo    Their values to put back if the content save fails.
	 * @return true|\WP_Error
	 */
	private static function write( int $id, ?string $content, array $layouts, array $undo ) {
		foreach ( $layouts as $key => $value ) {
			update_metadata( 'post', $id, (string) $key, self::slash( $value ) );
		}
		if ( null !== $content ) {
			$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			// The page's own content with its links changed, not new input: running kses over a
			// page saved by someone who holds unfiltered_html would strip what they put there
			// (the same rule as zs-730).
			if ( $kses ) {
				kses_remove_filters();
			}
			try {
				// HARDEN1: inside a bulk job, other plugins' post-save actions are held for this
				// save (Quiet_Save): zinnhub's 2,403-page apply made them queue ~13,900 actions.
				$saved = Quiet_Save::run(
					$id,
					static fn() => wp_update_post(
						array(
							'ID'           => $id,
							'post_content' => wp_slash( $content ),
						),
						true
					)
				);
			} finally {
				if ( $kses ) {
					kses_init_filters();
				}
			}
			if ( is_wp_error( $saved ) ) {
				foreach ( array_keys( $layouts ) as $key ) {
					if ( array_key_exists( $key, $undo ) ) {
						update_metadata( 'post', $id, (string) $key, self::slash( $undo[ $key ] ) );
					}
				}
				return $saved;
			}
		} elseif ( function_exists( 'wp_save_post_revision' ) && post_type_supports( (string) get_post_type( $id ), 'revisions' ) ) {
			// A layout-only change: the revision without wp_update_post() and every plugin's
			// save hooks (the builder copies its layout into the revision itself).
			add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
			try {
				wp_save_post_revision( $id );
			} finally {
				remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
			}
		}
		self::refresh( $id, array_keys( $layouts ) );

		return true;
	}

	/**
	 * Builders' caches of a page whose layout changed.
	 *
	 * @param int                $id   Post.
	 * @param array<int, string> $keys Layout keys written.
	 * @return void
	 */
	private static function refresh( int $id, array $keys ): void {
		clean_post_cache( $id );
		if ( in_array( '_elementor_data', $keys, true ) ) {
			// Elementor 3.22+ keeps rendered widgets in an element cache: without this the page
			// keeps showing the old links until the cache expires.
			delete_post_meta( $id, '_elementor_element_cache' );
			$css = '\Elementor\Core\Files\CSS\Post';
			if ( class_exists( $css ) && method_exists( $css, 'create' ) ) {
				try {
					$file = $css::create( $id );
					if ( is_object( $file ) && method_exists( $file, 'update' ) ) {
						$file->update();
					}
				} catch ( \Throwable $e ) {
					delete_post_meta( $id, '_elementor_css' ); // Elementor writes it again on the next view.
				}
			}
		}
		if ( array() !== array_intersect( $keys, array( '_fl_builder_data', '_fl_builder_draft' ) ) && class_exists( '\FLBuilderModel' ) ) {
			try {
				if ( method_exists( '\FLBuilderModel', 'delete_all_asset_cache' ) ) {
					\FLBuilderModel::delete_all_asset_cache( $id );
				}
				// Beaver Builder keeps the layout it read in this request (private statics); the
				// render check that follows must read the layout just written, as a visitor's
				// request does.
				$forget = \Closure::bind(
					static function ( int $post ): void {
						unset( self::$published_layout_data[ $post ], self::$draft_layout_data[ $post ] );
					},
					null,
					'\FLBuilderModel'
				);
				if ( $forget instanceof \Closure ) {
					$forget( $id );
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // An older Beaver Builder: its next request reads the new layout anyway.
			}
		}
		/**
		 * Fires after Zinn SEO changed a page's content (page caches may purge it here).
		 *
		 * @param int                $id   Post.
		 * @param array<int, string> $keys Builder layout keys written.
		 */
		do_action( 'zinn_seo_content_written', $id, $keys );
	}

	/**
	 * A value slashed for update_metadata(), which unslashes it (strings inside arrays and objects
	 * too), so a layout is stored exactly as given. Pure.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function slash( $value ) {
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'slash' ), $value );
		}
		if ( is_object( $value ) ) {
			$copy = clone $value;
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$copy->{$k} = self::slash( $v );
			}
			return $copy;
		}

		return $value;
	}
}
