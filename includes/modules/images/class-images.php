<?php
/**
 * Image SEO rules (zs-710).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Images;

use ZinnDigital\ZinnSEO\Cleanup\Cleanup;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alt text tells search engines (and screen readers) what an image shows; most sites leave it
 * empty. The rules here:
 *
 * - on upload: a clean file name (`My Photo_FINAL (2).JPG` → `my-photo-final-2.jpg`), and the alt
 *   text, title and caption filled from templates — `%filename%` (the cleaned name in words),
 *   `%post_title%` (the post it was uploaded to), `%site_name%`, `%category%` — in the case the site
 *   chose, only where empty, never over what a person wrote;
 * - in every Tranzly language: the same templates in that language, with the translated post's
 *   title, written into Tranzly's own media translations;
 * - for the whole library, in the background (resumable, at any size): fill empty fields, find and
 *   replace, change case, strip punctuation — each previewed with counts and examples first;
 * - optionally on the page: an image printed with an empty `alt` gets its library alt text.
 */
final class Images {

	/** Background job id. */
	public const JOB = 'images';

	/** Fields and where WordPress keeps them. */
	public const FIELDS = array( 'alt', 'title', 'caption' );

	/**
	 * Hooks.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		// The page as visitors get it, also where the content analysis renders it (admin, REST,
		// WP-CLI, background jobs): otherwise "Images without alt text" stayed on Fix it for an
		// image the page shows WITH its library alt (found by AUTO1's harness, 2026-10-16).
		if ( (bool) Settings::get( 'img_alt_output', false ) ) {
			add_filter( 'wp_content_img_tag', array( self::class, 'content_img' ), 9, 3 );
			add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'image_attributes' ), 9, 2 );
		}
		if ( 'front' !== $context ) {
			if ( (bool) Settings::get( 'img_clean_names', false ) ) {
				add_filter( 'wp_handle_upload_prefilter', array( self::class, 'clean_upload' ) );
				add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'clean_upload' ) );
			}
			add_action( 'add_attachment', array( self::class, 'on_upload' ) );
			Jobs::register( self::JOB, static fn() => __( 'Updating image text in the media library', 'zinn-seo' ), array( self::class, 'step' ) );
		}
	}

	// ── File names ──────────────────────────────────────────────────────────────────────────

	/**
	 * A clean, readable file name: lower case, accents removed, words joined by hyphens.
	 *
	 * @param string $name File name with extension.
	 * @return string
	 */
	public static function clean_name( string $name ): string {
		$dot  = strrpos( $name, '.' );
		$ext  = false === $dot ? '' : strtolower( substr( $name, $dot + 1 ) );
		$base = false === $dot ? $name : substr( $name, 0, $dot );
		$base = strtolower( remove_accents( $base ) );
		$base = (string) preg_replace( '/[^a-z0-9]+/', '-', $base );
		$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );
		if ( '' === $base ) {
			$base = 'image';
		}
		$ext = (string) preg_replace( '/[^a-z0-9]/', '', $ext );

		return $base . ( '' !== $ext ? '.' . ( 'jpeg' === $ext ? 'jpg' : $ext ) : '' );
	}

	/**
	 * Clean an uploaded image's file name.
	 *
	 * @param array<string, mixed> $file The upload.
	 * @return array<string, mixed>
	 */
	public static function clean_upload( $file ): array {
		$file = (array) $file;
		// A sideload (the REST API, a URL import) carries no `type`: the name's extension decides.
		$type = (string) ( $file['type'] ?? '' );
		if ( '' === $type && isset( $file['name'] ) && is_string( $file['name'] ) ) {
			$type = (string) ( wp_check_filetype( $file['name'] )['type'] ?? '' );
		}
		if ( isset( $file['name'] ) && is_string( $file['name'] ) && 0 === strpos( $type, 'image/' ) ) {
			$file['name'] = self::clean_name( $file['name'] );
		}

		return $file;
	}

	/**
	 * A file name as words: `my-photo-final-2.jpg` → `my photo final`. Camera names and trailing
	 * numbers (IMG_1234, DSC0042, -2, -scaled, -1024x768) are dropped.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function words_from_name( string $name ): string {
		$base = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', basename( $name ) );
		$base = (string) preg_replace( '/-(scaled|rotated|e\d{10,})$|-\d{2,5}x\d{2,5}$/i', '', $base );
		$base = (string) preg_replace( '/\b(img|dsc|dscn|dcim|pxl|image|photo|screenshot|whatsapp)[\s_-]*\d+\b/i', '', $base );
		$base = (string) preg_replace( '/[_\-.]+/', ' ', $base );
		$base = (string) preg_replace( '/(\s\d{1,3})+$/', '', $base );

		return trim( (string) preg_replace( '/\s+/', ' ', $base ) );
	}

	// ── Templates ───────────────────────────────────────────────────────────────────────────

	/**
	 * The variables for one image.
	 *
	 * @param \WP_Post      $image  Attachment.
	 * @param \WP_Post|null $owner The post it belongs to (a translation, for another language).
	 * @return array<string, string>
	 */
	public static function variables( \WP_Post $image, ?\WP_Post $owner ): array {
		$category = '';
		if ( $owner instanceof \WP_Post ) {
			$terms    = get_the_terms( $owner, 'category' );
			$category = is_array( $terms ) && isset( $terms[0] ) ? (string) $terms[0]->name : '';
		}
		$file = (string) get_post_meta( $image->ID, '_wp_attached_file', true );

		return array(
			'%filename%'   => self::words_from_name( '' !== $file ? $file : (string) $image->post_name ),
			'%post_title%' => $owner instanceof \WP_Post ? wp_strip_all_tags( (string) get_the_title( $owner ) ) : '',
			'%site_name%'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'%category%'   => $category,
		);
	}

	/**
	 * Fill a template, then apply the site's case and punctuation rules.
	 *
	 * @param string                $template Template.
	 * @param array<string, string> $vars     Variables.
	 * @param string                $lang     Two-letter language.
	 * @return string
	 */
	public static function fill( string $template, array $vars, string $lang = '' ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}
		$text = strtr( $template, $vars );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/%[a-z_]+%/', '', $text ) ), " \t-–|:," );

		return self::shape( $text, (string) Settings::get( 'img_case', '' ), (bool) Settings::get( 'img_strip_punct', false ), $lang );
	}

	/**
	 * Case and punctuation.
	 *
	 * @param string $text  Text.
	 * @param string $style  title, first, lower or ''.
	 * @param bool   $strip Remove punctuation.
	 * @param string $lang  Two-letter language.
	 * @return string
	 */
	public static function shape( string $text, string $style, bool $strip, string $lang = '' ): string {
		if ( $strip ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s\'’-]+/u', ' ', $text ) ) );
		}
		if ( 'lower' === $style ) {
			return mb_strtolower( $text );
		}
		if ( in_array( $style, array( 'title', 'first' ), true ) ) {
			return class_exists( '\ZinnDigital\ZinnSEO\Cleanup\Cleanup' )
				? Cleanup::capitalise( $text, $style, '' !== $lang ? $lang : 'en' )
				: mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
		}

		return $text;
	}

	/**
	 * An image's current text.
	 *
	 * @param \WP_Post $image Attachment.
	 * @param string   $field alt, title or caption.
	 * @return string
	 */
	public static function current( \WP_Post $image, string $field ): string {
		if ( 'alt' === $field ) {
			return (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
		}

		return 'title' === $field ? (string) $image->post_title : (string) $image->post_excerpt;
	}

	/**
	 * Store an image's text.
	 *
	 * @param \WP_Post $image Attachment.
	 * @param string   $field alt, title or caption.
	 * @param string   $text  Text.
	 * @return void
	 */
	public static function store( \WP_Post $image, string $field, string $text ): void {
		if ( 'alt' === $field ) {
			update_post_meta( $image->ID, '_wp_attachment_image_alt', sanitize_text_field( $text ) );
			return;
		}
		$column = 'title' === $field ? 'post_title' : 'post_excerpt';
		// HARDEN1: a bulk run over the media library holds other plugins' save actions.
		\ZinnDigital\ZinnSEO\Content\Quiet_Save::run(
			$image->ID,
			static fn() => wp_update_post(
				array(
					'ID'    => $image->ID,
					$column => sanitize_text_field( $text ),
				)
			)
		);
		clean_post_cache( $image->ID );
	}

	/**
	 * Is a title just WordPress's copy of the file name (what it puts there on upload)?
	 *
	 * @param \WP_Post $image Attachment.
	 * @return bool
	 */
	public static function title_is_filename( \WP_Post $image ): bool {
		$file  = (string) get_post_meta( $image->ID, '_wp_attached_file', true );
		$base  = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', basename( $file ) );
		$plain = static fn( string $s ): string => strtolower( (string) preg_replace( '/[\s_\-.]+/', '', $s ) );

		return '' === trim( $image->post_title ) || $plain( $image->post_title ) === $plain( $base ) || $plain( $image->post_title ) === $plain( (string) preg_replace( '/-(scaled|\d+)$/', '', $base ) );
	}

	/**
	 * A new image: fill its empty fields, in its language and every Tranzly language.
	 *
	 * @param int $id Attachment.
	 * @return void
	 */
	public static function on_upload( $id ): void {
		$image = get_post( (int) $id );
		if ( ! $image instanceof \WP_Post || ! wp_attachment_is_image( $image ) ) {
			return;
		}
		$parent = $image->post_parent > 0 ? get_post( (int) $image->post_parent ) : null;
		self::fill_empty( $image, $parent instanceof \WP_Post ? $parent : null, true );
		self::fill_languages( $image, $parent instanceof \WP_Post ? $parent : null );
	}

	/**
	 * Fill an image's empty fields from the templates. Returns the fields changed.
	 *
	 * @param \WP_Post      $image   Attachment.
	 * @param \WP_Post|null $owner  The post it belongs to.
	 * @param bool          $write   Store (false: only report).
	 * @return array<string, array{0: string, 1: string}> Field => [before, after].
	 */
	public static function fill_empty( \WP_Post $image, ?\WP_Post $owner, bool $write ): array {
		$vars    = self::variables( $image, $owner );
		$changes = array();
		foreach ( self::FIELDS as $field ) {
			$template = (string) Settings::get( 'img_' . $field, '' );
			$now      = self::current( $image, $field );
			$empty    = 'title' === $field ? self::title_is_filename( $image ) : '' === trim( $now );
			if ( '' === trim( $template ) || ! $empty ) {
				continue;
			}
			$text = self::fill( $template, $vars, self::site_language() );
			if ( '' !== $text && $text !== $now ) {
				$changes[ $field ] = array( $now, $text );
				if ( $write ) {
					self::store( $image, $field, $text );
				}
			}
		}

		return $changes;
	}

	/**
	 * The site's own language, two letters.
	 *
	 * @return string
	 */
	public static function site_language(): string {
		return strtolower( substr( (string) get_locale(), 0, 2 ) );
	}

	/**
	 * In every other Tranzly language that has templates: the same fields, written into Tranzly's
	 * media translations, with the TRANSLATED post's title.
	 *
	 * @param \WP_Post      $image  Attachment.
	 * @param \WP_Post|null $owner The post it belongs to.
	 * @return int Fields written.
	 */
	public static function fill_languages( \WP_Post $image, ?\WP_Post $owner ): int {
		$per = (array) Settings::get( 'img_lang', array() );
		if ( array() === $per || ! function_exists( 'tranzly_languages' ) || ! class_exists( '\ZinnDigital\Tranzly\Core\Strings' ) ) {
			return 0;
		}
		$written = 0;
		foreach ( (array) tranzly_languages() as $language ) {
			$code  = (string) ( is_array( $language ) ? ( $language['code'] ?? '' ) : $language );
			$short = strtolower( substr( $code, 0, 2 ) );
			$tpl   = $per[ $short ] ?? ( $per[ strtolower( $code ) ] ?? null );
			if ( ! is_array( $tpl ) || '' === $code ) {
				continue;
			}
			$translated = $owner instanceof \WP_Post && function_exists( 'tranzly_get_translation' ) ? tranzly_get_translation( (int) $owner->ID, $code ) : null;
			$vars       = self::variables( $image, $translated ? get_post( (int) $translated ) : $owner );
			foreach ( self::FIELDS as $field ) {
				$template = (string) ( $tpl[ $field ] ?? '' );
				if ( '' === $template || null !== \ZinnDigital\Tranzly\Core\Strings::media( (int) $image->ID, $code, $field ) ) {
					continue;
				}
				$text = self::fill( $template, $vars, $short );
				if ( '' !== $text && true === \ZinnDigital\Tranzly\Core\Strings::set_media( (int) $image->ID, $code, $field, $text ) ) {
					++$written;
				}
			}
		}

		return $written;
	}

	// ── On the page ─────────────────────────────────────────────────────────────────────────

	/**
	 * An image in content with an empty or missing `alt`: its library alt text, else the alt
	 * text rule filled for the page being shown (and an empty `title` from the title rule).
	 *
	 * ⛔ WHY THE RULE, AND THE PAGE SHOWN. Rank Math's "add missing alt" filled every empty alt
	 * as the page was shown, from its template and the title of the page the image appears on.
	 * A site moved from it lost that text on every such image (zinnhub.com, 2026-10-15: 6 of 15
	 * images on a blog post printed `alt=""` after the switch). The library alt still wins.
	 *
	 * @param string $tag           The `<img>` tag.
	 * @param string $context       Where.
	 * @param int    $attachment_id Attachment (0 when unknown).
	 * @return string
	 */
	public static function content_img( $tag, $context, $attachment_id ): string {
		unset( $context );
		$tag   = (string) $tag;
		$image = (int) $attachment_id > 0 ? get_post( (int) $attachment_id ) : null;
		$alt   = 1 === preg_match( '/\salt=(["\'])\s*[^"\'\s]/', $tag ) ? '' : self::output_text( 'alt', $image instanceof \WP_Post ? $image : null, self::tag_source( $tag ) );
		$title = 1 === preg_match( '/\stitle=(["\'])\s*[^"\'\s]/', $tag ) ? '' : self::output_text( 'title', $image instanceof \WP_Post ? $image : null, self::tag_source( $tag ) );
		if ( '' !== $alt ) {
			$tag = self::with_alt( $tag, $alt );
		}

		return '' === $title ? $tag : self::with_attribute( $tag, 'title', $title );
	}

	/**
	 * `wp_get_attachment_image()`: an empty alt gets the library's (or the rule's) text, and an
	 * empty title the title rule's.
	 *
	 * @param array<string, string> $attr  Attributes.
	 * @param \WP_Post|mixed        $image Attachment.
	 * @return array<string, string>
	 */
	public static function image_attributes( $attr, $image ): array {
		$attr = (array) $attr;
		if ( ! $image instanceof \WP_Post ) {
			return $attr;
		}
		foreach ( array( 'alt', 'title' ) as $field ) {
			if ( isset( $attr[ $field ] ) && '' !== trim( (string) $attr[ $field ] ) ) {
				continue;
			}
			$text = self::output_text( $field, $image, '' );
			if ( '' !== $text ) {
				$attr[ $field ] = $text;
			}
		}

		return $attr;
	}

	/**
	 * The text an image gets on the page for an empty field: the library's alt text, else the
	 * field's rule filled with the title of the page being shown (`%count%` numbers the images
	 * of one page, 1, 2, 3 …). '' when there is nothing to add.
	 *
	 * @param string        $field  alt or title.
	 * @param \WP_Post|null $image  Attachment (null: an image the library does not know).
	 * @param string        $source The image's address (for `%filename%` when it is not in the library).
	 * @return string
	 */
	public static function output_text( string $field, ?\WP_Post $image, string $source ): string {
		$library  = 'alt' === $field && $image instanceof \WP_Post ? (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true ) : '';
		$template = (string) Settings::get( 'img_' . $field, '' );
		if ( '' !== trim( $library ) || '' === trim( $template ) ) {
			return self::pick( $library, $template, array() );
		}
		$shown           = get_post();
		$owner           = $shown instanceof \WP_Post ? $shown : ( $image instanceof \WP_Post && $image->post_parent > 0 ? get_post( (int) $image->post_parent ) : null );
		$owner           = $owner instanceof \WP_Post ? $owner : null;
		$vars            = $image instanceof \WP_Post ? self::variables( $image, $owner ) : self::source_variables( $source, $owner );
		$vars['%count%'] = (string) self::count( null !== $owner ? (int) $owner->ID : 0, $field );

		return self::pick( $library, $template, $vars );
	}

	/**
	 * The library's text when there is some, else the rule filled ('' with neither). Pure.
	 *
	 * @param string                $library  The library's alt text ('' for none or a title).
	 * @param string                $template The field's rule.
	 * @param array<string, string> $vars     Variables.
	 * @return string
	 */
	public static function pick( string $library, string $template, array $vars ): string {
		if ( '' !== trim( $library ) ) {
			return trim( $library );
		}

		return '' === trim( $template ) ? '' : self::fill( $template, $vars, self::site_language() );
	}

	/**
	 * The variables for an image that is not in the media library (only its address is known).
	 *
	 * @param string        $source Address.
	 * @param \WP_Post|null $owner  The page it is shown on.
	 * @return array<string, string>
	 */
	public static function source_variables( string $source, ?\WP_Post $owner ): array {
		$category = '';
		if ( $owner instanceof \WP_Post ) {
			$terms    = get_the_terms( $owner, 'category' );
			$category = is_array( $terms ) && isset( $terms[0] ) ? (string) $terms[0]->name : '';
		}
		$path = (string) wp_parse_url( $source, PHP_URL_PATH );

		return array(
			'%filename%'   => '' !== $path ? self::words_from_name( $path ) : '',
			'%post_title%' => $owner instanceof \WP_Post ? wp_strip_all_tags( (string) get_the_title( $owner ) ) : '',
			'%site_name%'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'%category%'   => $category,
		);
	}

	/**
	 * The next number for `%count%` on one page (each field numbers on its own, from 1).
	 *
	 * @param int    $post  The page shown (0: none).
	 * @param string $field alt or title.
	 * @return int
	 */
	public static function count( int $post, string $field ): int {
		static $seen  = array();
		$key          = $post . ':' . $field;
		$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;

		return $seen[ $key ];
	}

	/**
	 * The address an `<img>` tag shows (a lazy loader's `data-src` first).
	 *
	 * @param string $tag The tag.
	 * @return string
	 */
	public static function tag_source( string $tag ): string {
		foreach ( array( 'data-src', 'data-lazy-src', 'src' ) as $name ) {
			if ( 1 === preg_match( '/\s' . preg_quote( $name, '/' ) . '=(["\'])([^"\']+)\1/i', $tag, $m ) && 0 !== strpos( $m[2], 'data:' ) ) {
				return $m[2];
			}
		}

		return '';
	}

	/**
	 * Put an attribute on a tag (replacing an empty one).
	 *
	 * @param string $tag  `<img …>`.
	 * @param string $name Attribute.
	 * @param string $text Value.
	 * @return string
	 */
	public static function with_attribute( string $tag, string $name, string $text ): string {
		$attr = ' ' . $name . '="' . esc_attr( $text ) . '"';
		$re   = '/\s' . preg_quote( $name, '/' ) . '=(["\'])\s*\1/';
		if ( 1 === preg_match( $re, $tag ) ) {
			return (string) preg_replace( $re, $attr, $tag, 1 );
		}

		return (string) preg_replace( '/^<img\b/i', '<img' . $attr, $tag, 1 );
	}

	/**
	 * Put an alt attribute on a tag (replacing an empty one).
	 *
	 * @param string $tag `<img …>`.
	 * @param string $alt Text.
	 * @return string
	 */
	public static function with_alt( string $tag, string $alt ): string {
		$attr = ' alt="' . esc_attr( $alt ) . '"';
		if ( 1 === preg_match( '/\salt=(["\'])\s*\1/', $tag ) ) {
			return (string) preg_replace( '/\salt=(["\'])\s*\1/', $attr, $tag, 1 );
		}

		return (string) preg_replace( '/^<img\b/i', '<img' . $attr, $tag, 1 );
	}

	// ── The whole library ───────────────────────────────────────────────────────────────────

	/**
	 * What one bulk action does to one image's fields: field => [before, after], changed only.
	 *
	 * @param array<string, string> $now    Field => current text.
	 * @param array<string, mixed>  $action The action: kind (replace, case, punct), fields, find,
	 *                                      replace, match_case, case.
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function transform( array $now, array $action ): array {
		$out = array();
		foreach ( (array) ( $action['fields'] ?? array() ) as $field ) {
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				continue;
			}
			$before = (string) ( $now[ $field ] ?? '' );
			$after  = $before;
			switch ( (string) ( $action['kind'] ?? '' ) ) {
				case 'replace':
					$find = (string) ( $action['find'] ?? '' );
					if ( '' !== $find ) {
						$after = ! empty( $action['match_case'] ) ? str_replace( $find, (string) ( $action['replace'] ?? '' ), $before ) : str_ireplace( $find, (string) ( $action['replace'] ?? '' ), $before );
						$after = trim( (string) preg_replace( '/\s+/', ' ', $after ) );
					}
					break;
				case 'case':
					$after = self::shape( $before, (string) ( $action['case'] ?? '' ), false, self::site_language() );
					break;
				case 'punct':
					$after = self::shape( $before, '', true );
					break;
			}
			if ( $after !== $before ) {
				$out[ $field ] = array( $before, $after );
			}
		}

		return $out;
	}

	/**
	 * Check a bulk action sent by the screen, REST or an agent.
	 *
	 * @param array<string, mixed> $action Action.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function check_action( array $action ) {
		$kind   = (string) ( $action['kind'] ?? '' );
		$fields = array_values( array_intersect( (array) ( $action['fields'] ?? array() ), self::FIELDS ) );
		if ( ! in_array( $kind, array( 'fill', 'replace', 'case', 'punct' ), true ) ) {
			return new \WP_Error( 'zinn_seo_bad_action', __( 'Choose what to do: fill empty text, find and replace, change case or remove punctuation.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( 'fill' !== $kind && array() === $fields ) {
			return new \WP_Error( 'zinn_seo_bad_action', __( 'Choose at least one of alt text, title and caption.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( 'replace' === $kind && '' === (string) ( $action['find'] ?? '' ) ) {
			return new \WP_Error( 'zinn_seo_bad_action', __( 'Type the text to find.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( 'case' === $kind && ! in_array( (string) ( $action['case'] ?? '' ), array( 'title', 'first', 'lower' ), true ) ) {
			return new \WP_Error( 'zinn_seo_bad_action', __( 'Choose a case.', 'zinn-seo' ), array( 'status' => 400 ) );
		}

		return array(
			'kind'       => $kind,
			'fields'     => $fields,
			'find'       => sanitize_text_field( (string) ( $action['find'] ?? '' ) ),
			'replace'    => sanitize_text_field( (string) ( $action['replace'] ?? '' ) ),
			'match_case' => ! empty( $action['match_case'] ),
			'case'       => (string) ( $action['case'] ?? '' ),
		);
	}

	/**
	 * The changes an action would make to one image.
	 *
	 * @param \WP_Post             $image  Attachment.
	 * @param array<string, mixed> $action Checked action.
	 * @param bool                 $write  Store them.
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function apply( \WP_Post $image, array $action, bool $write ): array {
		if ( 'fill' === $action['kind'] ) {
			$parent = $image->post_parent > 0 ? get_post( (int) $image->post_parent ) : null;
			return self::fill_empty( $image, $parent instanceof \WP_Post ? $parent : null, $write );
		}
		$now = array();
		foreach ( self::FIELDS as $field ) {
			$now[ $field ] = self::current( $image, $field );
		}
		$changes = self::transform( $now, $action );
		if ( $write ) {
			foreach ( $changes as $field => $pair ) {
				self::store( $image, $field, $pair[1] );
			}
		}

		return $changes;
	}

	/**
	 * Image ids after a cursor (keyset).
	 *
	 * @param int $after Last id seen.
	 * @param int $limit How many.
	 * @return array<int, int>
	 */
	public static function next_ids( int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset walk of the media library.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID ASC LIMIT %d", $after, 'image/%', $limit ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Preview: walk the library (time-boxed) counting images that would change, with examples.
	 *
	 * @param array<string, mixed> $action  Checked action.
	 * @param float                $seconds Time budget.
	 * @return array{checked: int, changes: int, examples: array<int, array<string, mixed>>, complete: bool}
	 */
	public static function preview( array $action, float $seconds = 8.0 ): array {
		$deadline = microtime( true ) + $seconds;
		$after    = 0;
		$checked  = 0;
		$changes  = 0;
		$examples = array();
		$complete = false;
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$ids = self::next_ids( $after, 200 );
			if ( array() === $ids ) {
				$complete = true;
				break;
			}
			_prime_post_caches( $ids, false, true );
			foreach ( $ids as $id ) {
				$after = $id;
				++$checked;
				$image = get_post( $id );
				if ( ! $image instanceof \WP_Post ) {
					continue;
				}
				$diff = self::apply( $image, $action, false );
				if ( array() !== $diff ) {
					++$changes;
					if ( count( $examples ) < 12 ) {
						$examples[] = array(
							'id'      => $id,
							'file'    => basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
							'changes' => $diff,
						);
					}
				}
			}
		}

		return array(
			'checked'  => $checked,
			'changes'  => $changes,
			'examples' => $examples,
			'complete' => $complete,
		);
	}

	/**
	 * Start the background run.
	 *
	 * @param array<string, mixed> $action Checked action.
	 * @return true|\WP_Error
	 */
	public static function start( array $action ) {
		return Jobs::start(
			self::JOB,
			array(
				'action'  => $action,
				'after'   => 0,
				'done'    => 0,
				'changed' => 0,
			)
		);
	}

	/**
	 * One step of the background run.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param float                $deadline Deadline.
	 * @return array<string, mixed>
	 */
	public static function step( array $state, float $deadline ): array {
		$action  = (array) ( $state['action'] ?? array() );
		$after   = (int) ( $state['after'] ?? 0 );
		$done    = (int) ( $state['done'] ?? 0 );
		$changed = (int) ( $state['changed'] ?? 0 );
		$total   = $state['total'] ?? null;
		if ( null === $total ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- once per run.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s", 'image/%' ) );
		}
		$finished = false;
		while ( ! \ZinnDigital\ZinnSEO\Jobs::over( $deadline ) ) {
			$ids = self::next_ids( $after, 100 );
			if ( array() === $ids ) {
				$finished = true;
				break;
			}
			_prime_post_caches( $ids, false, true );
			foreach ( $ids as $id ) {
				$image = get_post( $id );
				if ( $image instanceof \WP_Post && array() !== self::apply( $image, $action, true ) ) {
					++$changed;
				}
				$after = $id;
				++$done;
			}
			wp_cache_flush_runtime();
		}

		return array(
			'state'    => array(
				'action'  => $action,
				'after'   => $after,
				'done'    => $done,
				'changed' => $changed,
				'total'   => $total,
			),
			'done'     => $finished,
			'progress' => array(
				'done'  => $done,
				'total' => $total,
			),
		);
	}
}
