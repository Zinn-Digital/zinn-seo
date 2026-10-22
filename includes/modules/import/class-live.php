<?php
/**
 * Side-by-side reading: the head answers from the other plugin's data until the copy has it
 * (zs-261).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * While a move is under way (the setting `import_live` names its source), a read of one of OUR
 * fields that finds nothing stored is answered with what the other plugin stored for that page —
 * through the same Source the copy job uses, so the answer during the import is the answer
 * after it.
 *
 * ⛔ No double head: this changes what our tags SAY, never whether they are printed. While the
 * other plugin is active the head module stands down as always (one set of tags on the page);
 * the moment it is switched off, ours appear already saying the same thing.
 *
 * ⭐ Cost on a visitor's page: nothing is hooked unless a move is under way. Then: no query for a
 * plugin that keeps its data in post meta (the row is already in the cache the main query
 * primed); one query a page for the two that keep their own table.
 */
final class Live {

	/**
	 * The source being read.
	 *
	 * @var Source|null
	 */
	private static ?Source $source = null;

	/**
	 * Re-entry guard: we read our own field inside the filter.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	/**
	 * Per-request answers: `post:12` => fields.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $memo = array();

	/**
	 * Hook in when a move is under way.
	 *
	 * @param string $id Source id (the `import_live` setting).
	 * @return void
	 */
	public static function boot( string $id ): void {
		$source = Import::source( $id );
		if ( null === $source || ! $source->has_pages() ) {
			return;
		}
		self::$source = $source;
		add_filter( 'get_post_metadata', array( self::class, 'post' ), 10, 4 );
		add_filter( 'get_term_metadata', array( self::class, 'term' ), 10, 4 );
		add_filter( 'get_user_metadata', array( self::class, 'user' ), 10, 4 );
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'template_redirect', array( self::class, 'redirect' ), 1 );
		}
	}

	/**
	 * A redirect the other plugin kept on the page itself (The SEO Framework, SEOPress, Squirrly)
	 * is served while the move is under way, as that plugin served it; the copy turns it into a
	 * rule of the redirects module. (Found by the side-by-side check: the page answered 200.)
	 *
	 * @return void
	 */
	public static function redirect(): void {
		if ( null === self::$source || ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id < 1 ) {
			return;
		}
		self::$busy = true;
		self::$source->prime_posts( array( $id ) );
		$fields     = self::$source->post( $id );
		self::$busy = false;
		$to         = is_array( $fields['redirect'] ?? null ) ? trim( (string) ( $fields['redirect']['target'] ?? '' ) ) : '';
		if ( '' === $to || 1 !== preg_match( '#^(https?://|/)#i', $to ) ) {
			return;
		}
		$code = (int) ( $fields['redirect']['code'] ?? 301 );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the site's own redirect, as the other plugin stored it; it may point to another site.
		if ( wp_redirect( $to, in_array( $code, array( 301, 302, 307, 308 ), true ) ? $code : 301, 'WordPress' ) ) {
			exit;
		}
	}

	/**
	 * Stop (tests, and the end of a copy in the same request).
	 *
	 * @return void
	 */
	public static function stop(): void {
		self::$source = null;
		self::$memo   = array();
		remove_filter( 'get_post_metadata', array( self::class, 'post' ), 10 );
		remove_filter( 'get_term_metadata', array( self::class, 'term' ), 10 );
		remove_filter( 'get_user_metadata', array( self::class, 'user' ), 10 );
	}

	/**
	 * A post's field.
	 *
	 * @param mixed  $value  What another filter answered (null: nothing yet).
	 * @param int    $id     Post.
	 * @param string $key    Meta key.
	 * @param bool   $single One value.
	 * @return mixed
	 */
	public static function post( $value, $id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's signature.
		return self::answer( 'post', $value, (int) $id, (string) $key );
	}

	/**
	 * A term's field.
	 *
	 * @param mixed  $value  What another filter answered.
	 * @param int    $id     Term.
	 * @param string $key    Meta key.
	 * @param bool   $single One value.
	 * @return mixed
	 */
	public static function term( $value, $id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's signature.
		return self::answer( 'term', $value, (int) $id, (string) $key );
	}

	/**
	 * A user's field (the author's page, and the profiles on the user record).
	 *
	 * @param mixed  $value  What another filter answered.
	 * @param int    $id     User.
	 * @param string $key    Meta key.
	 * @param bool   $single One value.
	 * @return mixed
	 */
	public static function user( $value, $id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the filter's signature.
		return self::answer( 'user', $value, (int) $id, (string) $key );
	}

	/**
	 * Answer one read.
	 *
	 * @param string $kind  post|term|user.
	 * @param mixed  $value What another filter answered.
	 * @param int    $id    Object.
	 * @param string $key   Meta key.
	 * @return mixed `$value` untouched, or a one-item list holding the other plugin's value.
	 */
	private static function answer( string $kind, $value, int $id, string $key ) {
		if ( null !== $value || self::$busy || null === self::$source || $id < 1 || 0 !== strpos( $key, Meta::PREFIX ) ) {
			return $value;
		}
		$name = substr( $key, strlen( Meta::PREFIX ) );
		$def  = Meta::fields()[ $name ] ?? null;
		if ( null === $def || ! in_array( $kind, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
			return $value;
		}
		self::$busy = true;
		$own        = get_metadata_raw( $kind, $id, $key, true );
		self::$busy = false;
		if ( null !== $own && '' !== $own && false !== $own && array() !== $own ) {
			return $value;
		}
		$memo = $kind . ':' . $id;
		if ( ! isset( self::$memo[ $memo ] ) ) {
			self::$busy = true;
			if ( 'post' === $kind ) {
				self::$source->prime_posts( array( $id ) );
				self::$memo[ $memo ] = self::$source->post( $id );
			} elseif ( 'user' === $kind ) {
				$user                = get_userdata( $id );
				self::$memo[ $memo ] = $user instanceof \WP_User ? self::$source->user( $user ) : array();
			} else {
				$term = get_term( $id );
				self::$source->prime_terms( array( $id ) );
				self::$memo[ $memo ] = $term instanceof \WP_Term ? self::$source->term( $term ) : array();
			}
			self::$busy = false;
		}
		if ( ! array_key_exists( $name, self::$memo[ $memo ] ) ) {
			return $value;
		}
		$clean = Meta::sanitize( $def, self::$memo[ $memo ][ $name ] );
		if ( is_wp_error( $clean ) || Meta::empty_value( $def ) === $clean ) {
			return $value;
		}

		// WordPress unwraps a one-item list for a single read and returns it whole otherwise.
		return array( $clean );
	}
}
