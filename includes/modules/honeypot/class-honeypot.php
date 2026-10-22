<?php
/**
 * Comment-spam honeypot (zs-728): server-side checks, no script, no captcha.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Honeypot;

use ZinnDigital\ZinnSEO\Counters;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spam comments are thin, link-stuffed text added to real pages — the pages search engines judge.
 * Three checks catch the bots that post them, all on the server, with nothing for a visitor to
 * solve and nothing loaded in the browser:
 *
 * 1. a field people never see (the `hidden` attribute): a bot fills every field it finds;
 * 2. a signed time stamp: a comment sent sooner than a person can type one is a bot;
 * 3. the stamp must be there at all: bots that post straight to `wp-comments-post.php`, without
 *    loading the page, have none.
 *
 * ⭐ The field names are made from this site's own secret keys, so they differ on every site and
 * name nothing (no-trace mode needs no exception). Logged-in people, pingbacks and trackbacks are
 * never checked, and a comment form a plugin draws itself without WordPress's hooks (Jetpack
 * Comments, which posts from its own iframe) is left alone.
 */
final class Honeypot {

	/** Counter kind of caught comments, per day. */
	public const COUNTER = 'honeypot';

	/**
	 * Front-end and comment-post hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'comment_form_after_fields', array( self::class, 'fields' ) );
		add_filter( 'preprocess_comment', array( self::class, 'check' ), 1 );
	}

	/**
	 * This site's field names: [the trap, the stamp].
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function names(): array {
		$hash = wp_hash( 'zinn-seo-honeypot', 'nonce' );

		return array( 'c' . substr( $hash, 0, 7 ), 'c' . substr( $hash, 7, 7 ) );
	}

	/**
	 * A signed stamp of a moment.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function stamp( int $time ): string {
		return $time . '.' . substr( hash_hmac( 'sha256', (string) $time, wp_salt( 'nonce' ) . '|zseo-hp' ), 0, 16 );
	}

	/**
	 * The moment a valid stamp was made, or null.
	 *
	 * @param string $stamp Stamp.
	 * @return int|null
	 */
	public static function stamped( string $stamp ): ?int {
		if ( 1 !== preg_match( '/^(\d{9,11})\.([a-f0-9]{16})$/', $stamp, $m ) ) {
			return null;
		}

		return hash_equals( self::stamp( (int) $m[1] ), $stamp ) ? (int) $m[1] : null;
	}

	/**
	 * Print the two fields (for visitors who are not logged in).
	 *
	 * @return void
	 */
	public static function fields(): void {
		list( $trap, $stamp ) = self::names();
		printf(
			'<p hidden aria-hidden="true"><label>%1$s <input type="text" name="%2$s" value="" tabindex="-1" autocomplete="off"></label><input type="hidden" name="%3$s" value="%4$s"></p>',
			esc_html__( 'Leave this field empty', 'zinn-seo' ),
			esc_attr( $trap ),
			esc_attr( $stamp ),
			esc_attr( self::stamp( time() ) )
		);
	}

	/**
	 * Why a submission is spam, or '' when it passes.
	 *
	 * @param array<string, mixed> $post        The posted fields.
	 * @param int                  $now         Now.
	 * @param int                  $min_seconds Fewest seconds a person needs.
	 * @return string trap, missing, forged or fast.
	 */
	public static function verdict( array $post, int $now, int $min_seconds ): string {
		list( $trap, $stamp ) = self::names();
		if ( isset( $post[ $trap ] ) && '' !== trim( (string) $post[ $trap ] ) ) {
			return 'trap';
		}
		if ( ! isset( $post[ $stamp ] ) ) {
			return 'missing';
		}
		$at = self::stamped( (string) $post[ $stamp ] );
		if ( null === $at ) {
			return 'forged';
		}
		if ( $now - $at < $min_seconds ) {
			return 'fast';
		}

		return '';
	}

	/**
	 * Is this comment one the checks apply to?
	 *
	 * @param array<string, mixed> $comment The comment being saved.
	 * @return bool
	 */
	public static function applies( array $comment ): bool {
		if ( is_user_logged_in() || ! empty( $comment['user_id'] ) || ! empty( $comment['user_ID'] ) ) {
			return false;
		}
		if ( in_array( (string) ( $comment['comment_type'] ?? '' ), array( 'pingback', 'trackback' ), true ) ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		// Jetpack Comments posts from its own iframe, without WordPress's comment form hooks.
		if ( class_exists( '\Jetpack' ) && method_exists( '\Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'comments' ) ) {
			return false;
		}

		/**
		 * Filters whether the honeypot checks a comment (a plugin with its own comment form can
		 * opt out).
		 *
		 * @param bool                 $applies Whether to check.
		 * @param array<string, mixed> $comment The comment.
		 */
		return (bool) apply_filters( 'zinn_seo_honeypot_applies', true, $comment );
	}

	/**
	 * Check a comment before it is saved.
	 *
	 * @param array<string, mixed> $comment The comment.
	 * @return array<string, mixed>
	 */
	public static function check( $comment ): array {
		$comment = (array) $comment;
		if ( ! self::applies( $comment ) ) {
			return $comment;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- a public comment form has no nonce; the signed stamp is this check's own.
		$why = self::verdict( wp_unslash( $_POST ), time(), (int) Settings::get( 'honeypot_min_seconds', 3 ) );
		if ( '' === $why ) {
			return $comment;
		}
		Counters::hit( self::COUNTER, $why );
		if ( 'reject' === (string) Settings::get( 'honeypot_action', 'spam' ) ) {
			wp_die(
				esc_html__( 'Your comment could not be sent. Please go back and try again.', 'zinn-seo' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		add_filter( 'pre_comment_approved', array( self::class, 'as_spam' ), 99 );

		return $comment;
	}

	/**
	 * The comment goes to the spam folder (where a person can still rescue it).
	 *
	 * @return string
	 */
	public static function as_spam(): string {
		remove_filter( 'pre_comment_approved', array( self::class, 'as_spam' ), 99 );

		return 'spam';
	}

	/**
	 * Comments caught since the honeypot was switched on, by reason.
	 *
	 * @return array<string, int>
	 */
	public static function caught(): array {
		$out = array();
		foreach ( array( 'trap', 'missing', 'forged', 'fast' ) as $why ) {
			$row         = Counters::ready() ? Counters::get( self::COUNTER, $why ) : null;
			$out[ $why ] = is_array( $row ) ? (int) ( $row['hits'] ?? 0 ) : 0;
		}

		return $out;
	}
}
