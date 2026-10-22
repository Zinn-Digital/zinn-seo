<?php
/**
 * The 404 monitor: aggregated counters, never a row per hit (zs-093).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Notfound;

use ZinnDigital\ZinnSEO\Counters;
use ZinnDigital\ZinnSEO\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reads of the posts table by its indexed post_name.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ The Redirection plugin writes one row per 404 and per redirect; on a busy site that log is the
 * thing that fills the database (catalogue A4.4, wordpress.org support threads). Here a missing
 * address is ONE row in `{prefix}zseo_counters` (kind `404`; robots apart as `404b`): hits, first
 * and last seen, the last referrer. At most 10,000 rows per kind (least recently seen go first), so
 * a scanner hammering random addresses churns the table without growing it.
 *
 * A visitor's request pays nothing unless the page is a 404; a 404 pays one upsert at shutdown.
 * Ignored: addresses matching `notfound_ignore` (wildcards `*`; defaults: PHP files and dot-files,
 * which are scanners), and robots unless `notfound_bots` is `count`. Optional weekly e-mail of new
 * addresses (`notfound_email`), scheduled only while switched on.
 */
final class Notfound {

	/** Weekly digest cron hook. */
	public const DIGEST = 'zseo_notfound_digest';

	/**
	 * This request's address and referrer, when it is a 404 to count.
	 *
	 * @var array{0: string, 1: string, 2: string}|null [kind, address, referrer]
	 */
	private static ?array $pending = null;

	/**
	 * Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		add_action( 'template_redirect', array( self::class, 'note' ), 99 );
	}

	/**
	 * Is this a 404 worth counting? Note it for shutdown.
	 *
	 * @return void
	 */
	public static function note(): void {
		// A 410/451 the redirect manager answered is not "missing": only a real 404 is counted.
		if ( ! is_404() || 404 !== (int) http_response_code() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by address() below.
		$key = self::address( $uri );
		if ( '' === $key || self::ignored( $key, (string) Settings::get( 'notfound_ignore', '' ) ) ) {
			return;
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot   = Counters::is_bot( $agent );
		if ( $bot && 'skip' === Settings::get( 'notfound_bots', 'apart' ) ) {
			return;
		}
		$ref           = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : '';
		self::$pending = array( $bot ? '404b' : '404', $key, self::referrer( $ref ) );
		add_action( 'shutdown', array( self::class, 'count' ) );
	}

	/**
	 * Count it (shutdown).
	 *
	 * @return void
	 */
	public static function count(): void {
		if ( null === self::$pending ) {
			return;
		}
		Counters::hit( self::$pending[0], self::$pending[1], self::$pending[2] );
		self::$pending = null;
	}

	/**
	 * The address as stored: the path, decoded, without the host or query (WordPress answers by
	 * path; a query would let a scanner make endless "different" addresses); at most 191 characters.
	 *
	 * @param string $uri Request URI.
	 * @return string
	 */
	public static function address( string $uri ): string {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return '';
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $path, 'UTF-8' ) ) {
			return '';
		}
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );

		return Counters::cut( $path );
	}

	/**
	 * The referrer kept with a 404: this site's own pages in full (they hold a broken link to
	 * fix), other sites as their host and path (never the query: it can carry personal data).
	 *
	 * @param string $ref Referrer.
	 * @return string
	 */
	public static function referrer( string $ref ): string {
		if ( '' === $ref ) {
			return '';
		}
		$host = (string) wp_parse_url( $ref, PHP_URL_HOST );
		$path = (string) wp_parse_url( $ref, PHP_URL_PATH );

		return '' === $host ? '' : Counters::cut( $host . $path );
	}

	/**
	 * Does an address match one of the ignore patterns (one per line, `*` = anything)?
	 *
	 * @param string $address Address.
	 * @param string $rules   Patterns.
	 * @return bool
	 */
	public static function ignored( string $address, string $rules ): bool {
		$path = (string) strtok( $address, '?' );
		foreach ( (array) preg_split( '/\R/', $rules ) as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			$re = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';
			if ( 1 === preg_match( $re, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A page this missing address probably meant: the published page whose slug is the address's
	 * last part (or starts with it). One indexed query on `post_name`.
	 *
	 * @param string $address Address.
	 * @return array{id: int, title: string, url: string}|null
	 */
	public static function suggest( string $address ): ?array {
		global $wpdb;
		$path = trim( (string) strtok( $address, '?' ), '/' );
		$last = sanitize_title( (string) basename( (string) preg_replace( '/\.(html?|php|aspx?)$/i', '', $path ) ) );
		if ( '' === $last || strlen( $last ) < 3 ) {
			return null;
		}
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$types = array_values( array_diff( $types, array( 'attachment' ) ) );
		if ( array() === $types ) {
			return null;
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE %s AND post_status = 'publish' AND post_type IN ({$in}) ORDER BY (post_name = %s) DESC, ID DESC LIMIT 1", array_merge( array( $wpdb->esc_like( $last ) . '%' ), $types, array( $last ) ) ) );
		if ( $id < 1 ) {
			return null;
		}

		return array(
			'id'    => $id,
			'title' => (string) get_the_title( $id ),
			'url'   => (string) get_permalink( $id ),
		);
	}

	/**
	 * Keep the weekly e-mail scheduled exactly while it is switched on.
	 *
	 * @return void
	 */
	public static function sync_digest(): void {
		$on   = (bool) Settings::get( 'notfound_email', false );
		$next = wp_next_scheduled( self::DIGEST );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::DIGEST );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::DIGEST );
		}
	}

	/**
	 * Send the weekly e-mail: addresses first seen since the last one.
	 *
	 * @return bool Sent.
	 */
	public static function digest(): bool {
		if ( ! Settings::get( 'notfound_email', false ) || ! Counters::ready() ) {
			return false;
		}
		global $wpdb;
		$since = (string) get_option( 'zseo_notfound_digest_at', gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT item_key, hits, sample_ref FROM %i WHERE kind = %s AND first_seen > %s ORDER BY hits DESC LIMIT 25', Counters::table(), '404', $since ), ARRAY_A );
		update_option( 'zseo_notfound_digest_at', gmdate( 'Y-m-d H:i:s' ), false );
		if ( array() === $rows ) {
			return false;
		}
		$lines = array();
		foreach ( $rows as $row ) {
			/* translators: 1: an address, 2: number of visits, 3: the page that linked to it. */
			$lines[] = '• ' . sprintf( __( '%1$s — %2$d visits%3$s', 'zinn-seo' ), home_url( (string) $row['item_key'] ), (int) $row['hits'], '' === (string) $row['sample_ref'] ? '' : ' — ' . sprintf( /* translators: %s: a page address. */ __( 'linked from %s', 'zinn-seo' ), (string) $row['sample_ref'] ) );
		}
		$body = self::digest_text( $lines );

		return \ZinnDigital\ZinnSEO\Alert_Mail::send(
			'notfound',
			(string) get_option( 'admin_email' ),
			/* translators: %s: the site's name. */
			sprintf( __( '[%s] Visitors looked for pages that do not exist this week', 'zinn-seo' ), (string) get_bloginfo( 'name' ) ),
			$body,
			array( 'signature' => md5( $body ) )
		);
	}

	/**
	 * The weekly e-mail's words (Q407).
	 *
	 * @param array<int, string> $lines One line per missing address.
	 * @return string
	 */
	public static function digest_text( array $lines ): string {
		return \ZinnDigital\ZinnSEO\Alert_Mail::plain(
			__( 'This week, visitors (or search engines) asked your site for these addresses and found nothing there, so they saw a "page not found" error. Usually a page was moved or renamed, or a link somewhere has a typing mistake.', 'zinn-seo' ),
			__( 'What to do: on the Redirects screen, send each address to the right page in one click, or fix the link that points to it. You can ignore an address nobody needs.', 'zinn-seo' ),
			admin_url( 'admin.php?page=zinn-seo&view=redirects#/notfound' ),
			implode( "\n", $lines )
		);
	}
}
