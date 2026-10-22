<?php
/**
 * What Zinn® has to tell this site: credit running low, checks paused and resumed, alerts.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Notices;

use ZinnDigital\ZinnSEO\Admin\Admin;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The same news reaches the site owner three ways: Zinn®'s own e-mail to the account, a list
 * inside this plugin's screens (with a count on its menu entry and ONE notice on its own screens,
 * never on the rest of wp-admin), and an e-mail sent by THIS site to whoever runs it — so the
 * person who looks after the site hears that a check was paused even when the account belongs
 * to their agency.
 *
 * The list is read from Zinn® at most every fifteen minutes per language (with the list's
 * `ETag`, so an unchanged list costs an empty answer) and kept in an option that is not
 * autoloaded. Twice a day one scheduled event does the same and sends the site's e-mails; the
 * event exists only while the site is connected.
 *
 * ⛔ Each notice is e-mailed once: the ids already sent are remembered, and a notice older than
 * the connection (or than three days) is never sent — connecting a site must not replay last
 * month's news.
 */
final class Notices {

	/** The cached lists and the ids already e-mailed. */
	public const OPTION = 'zseo_notices';

	/** Seconds a list is kept. */
	public const TTL = 900;

	/** The twice-daily event. */
	public const CRON = 'zseo_notices_sync';

	/** Notices older than this are not e-mailed. */
	public const MAIL_WINDOW = 3 * DAY_IN_SECONDS;

	/** The admin-post action that dismisses from the notice. */
	public const DISMISS = 'zseo_notice_dismiss';

	/**
	 * A notice that says a condition is over => the kinds of notice it ends, and whether it ends
	 * them only for the same domain (a guard incident is about one site; credit is the account's).
	 * ⛔ A resolved condition's notice never outranks its resolution (Q346, DEMO1 D4: a
	 * critical `credit_hold` of 2026-10-12 outranked the `credit_resumed` 48 s later, so admins saw
	 * "SEO checks paused" and a count of 5 days after the checks had resumed).
	 */
	public const RESOLVES = array(
		'credit_resumed' => array(
			'kinds'       => array( 'low_balance', 'forecast_short', 'credit_hold' ),
			'same_domain' => false,
		),
		'guard_resolved' => array(
			'kinds'       => array( 'guard_incident' ),
			'same_domain' => true,
		),
	);

	/**
	 * The kinds of notice, each with whether it is about money (so the credit copy is refreshed).
	 *
	 * @return array<string, bool>
	 */
	public static function kinds(): array {
		return array(
			'low_balance'        => true,
			'forecast_short'     => true,
			'credit_hold'        => true,
			'credit_resumed'     => true,
			'rank_drop'          => false,
			'link_lost'          => false,
			'deindexed'          => false,
			'ai_mention_lost'    => false,
			'ai_mention_gained'  => false,
			'brand_mention'      => false,
			'guard_incident'     => false,
			'guard_resolved'     => false,
			// Stage C2: a metric from the site owner's own SEO data account moved past their
			// alert rule; scheduled checks from that account paused (allowance used up / key refused).
			'metric_change'      => false,
			'data_paused'        => false,
			// The research toolbox's review watch (C1, zs-521).
			'review_new'         => false,
			'review_rating_drop' => false,
		);
	}

	/**
	 * The stored record.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(): array {
		$row = get_option( self::OPTION, array() );
		$row = is_array( $row ) ? $row : array();

		return $row + array(
			'by'     => array(),
			'mailed' => array(),
		);
	}

	/**
	 * One notice, cleaned for the screens (no e-mail body: the mailer reads that fresh).
	 *
	 * @param array<string, mixed> $item The API's notice.
	 * @return array<string, mixed>|null
	 */
	public static function clean( array $item ): ?array {
		$id   = (string) ( $item['id'] ?? '' );
		$kind = sanitize_key( (string) ( $item['kind'] ?? '' ) );
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $id ) || '' === $kind ) {
			return null;
		}
		$severity = (string) ( $item['severity'] ?? 'info' );

		return array(
			'id'       => strtolower( $id ),
			'kind'     => $kind,
			'severity' => in_array( $severity, array( 'info', 'success', 'warning', 'critical' ), true ) ? $severity : 'info',
			'domain'   => sanitize_text_field( (string) ( $item['domain'] ?? '' ) ),
			'at'       => (int) strtotime( (string) ( $item['created_at'] ?? '' ) ),
			'acked'    => ! empty( $item['acked'] ),
			'title'    => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'message'  => sanitize_textarea_field( (string) ( $item['message'] ?? '' ) ),
		);
	}

	/**
	 * The list in a language, kept for fifteen minutes.
	 *
	 * @param bool   $fresh  Ask Zinn® now.
	 * @param string $locale WordPress locale ('' is the reader's).
	 * @return array<string, mixed>|\WP_Error `{connected, unread, items, fetched_at}`.
	 */
	public static function feed( bool $fresh = false, string $locale = '' ) {
		if ( ! Connection::connected() ) {
			return array(
				'connected' => false,
				'unread'    => 0,
				'items'     => array(),
			);
		}
		$locale = '' !== $locale ? $locale : (string) get_user_locale();
		$row    = self::row();
		$kept   = is_array( $row['by'][ $locale ] ?? null ) ? $row['by'][ $locale ] : array();
		if ( ! $fresh && isset( $kept['at'] ) && (int) $kept['at'] > time() - self::TTL ) {
			return self::shape( $kept );
		}
		$fetched = self::fetch( $locale, (string) ( $kept['etag'] ?? '' ), 20 );
		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}
		if ( null === $fetched ) {
			$kept['at'] = time();
		} else {
			$kept = array(
				'at'     => time(),
				'etag'   => $fetched['etag'],
				'unread' => $fetched['unread'],
				'items'  => array_values( array_filter( array_map( array( self::class, 'clean' ), $fetched['items'] ) ) ),
			);
		}
		$row = self::row();
		// A handful of languages at most (the people who use this site's admin).
		$row['by'] = array_slice( array( $locale => $kept ) + (array) $row['by'], 0, 4, true );
		update_option( self::OPTION, $row, false );

		return self::shape( $kept );
	}

	/**
	 * The answer for the screens.
	 *
	 * @param array<string, mixed> $kept A kept list.
	 * @return array<string, mixed>
	 */
	private static function shape( array $kept ): array {
		return array(
			'connected'  => true,
			'unread'     => self::unread_of( $kept ),
			'items'      => array_values( (array) ( $kept['items'] ?? array() ) ),
			'fetched_at' => (int) ( $kept['at'] ?? 0 ),
		);
	}

	/**
	 * Read the list from Zinn®.
	 *
	 * @param string $locale WordPress locale.
	 * @param string $etag   The last list's ETag ('' to read it whole).
	 * @param int    $limit  How many.
	 * @return array{etag: string, unread: int, items: array<int, array<string, mixed>>}|\WP_Error|null Null: unchanged.
	 */
	private static function fetch( string $locale, string $etag, int $limit ) {
		$result = Connection::call(
			'GET',
			'/v1/seo-connection/notices?' . http_build_query(
				array(
					'locale' => $locale,
					'limit'  => $limit,
				)
			),
			null,
			'' !== $etag ? array( 'If-None-Match' => $etag ) : array()
		);
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		if ( 304 === (int) $result['status'] ) {
			return null;
		}
		$items = array();
		foreach ( (array) ( $result['body']['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'etag'   => (string) ( $result['etag'] ?? '' ),
			'unread' => max( 0, (int) ( $result['body']['unread'] ?? 0 ) ),
			'items'  => $items,
		);
	}

	/**
	 * How many notices are unread, from the kept lists only (the menu entry asks on every admin
	 * page: it must never call Zinn®).
	 *
	 * @return int
	 */
	public static function unread(): int {
		$row = self::row();
		$by  = (array) $row['by'];
		$own = $by[ (string) get_user_locale() ] ?? reset( $by );

		return is_array( $own ) ? self::unread_of( $own ) : 0;
	}

	/**
	 * A kept list's unread count: Zinn®'s count, less the unread notices in it that a newer
	 * notice has resolved (see RESOLVES).
	 *
	 * @param array<string, mixed> $kept A kept list.
	 * @return int
	 */
	public static function unread_of( array $kept ): int {
		$items = array_values( array_filter( (array) ( $kept['items'] ?? array() ), 'is_array' ) );
		$gone  = 0;
		foreach ( self::superseded( $items ) as $id => $yes ) {
			foreach ( $items as $item ) {
				if ( (string) ( $item['id'] ?? '' ) === $id && empty( $item['acked'] ) ) {
					++$gone;
					break;
				}
			}
		}

		return max( 0, (int) ( $kept['unread'] ?? 0 ) - $gone );
	}

	/**
	 * The notices a NEWER notice says are over: a low balance, a short forecast or a hold older
	 * than the newest "checks resumed"; a guard incident older than its site's "resolved". Pure.
	 *
	 * @param array<int, array<string, mixed>> $items Cleaned notices.
	 * @return array<string, true> id => true.
	 */
	public static function superseded( array $items ): array {
		$newest = array(); // Resolving kind => domain ('' any) => newest time.
		foreach ( $items as $item ) {
			$kind = is_array( $item ) ? (string) ( $item['kind'] ?? '' ) : '';
			if ( isset( self::RESOLVES[ $kind ] ) ) {
				$domain                     = self::RESOLVES[ $kind ]['same_domain'] ? (string) ( $item['domain'] ?? '' ) : '';
				$newest[ $kind ][ $domain ] = max( (int) ( $newest[ $kind ][ $domain ] ?? 0 ), (int) ( $item['at'] ?? 0 ) );
			}
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( self::RESOLVES as $by => $rule ) {
				if ( ! in_array( (string) ( $item['kind'] ?? '' ), $rule['kinds'], true ) ) {
					continue;
				}
				$domain = $rule['same_domain'] ? (string) ( $item['domain'] ?? '' ) : '';
				// Strictly older: a hold in the same second as a resume is not known to be over.
				if ( isset( $newest[ $by ][ $domain ] ) && (int) ( $item['at'] ?? 0 ) < $newest[ $by ][ $domain ] ) {
					$out[ (string) ( $item['id'] ?? '' ) ] = true;
				}
			}
		}

		return $out;
	}

	/**
	 * Dismiss notices for this site.
	 *
	 * @param array<int, string> $ids Notice ids (empty with `$all` for every one).
	 * @param bool               $all Every notice.
	 * @return array<string, mixed>|\WP_Error The fresh list.
	 */
	public static function dismiss( array $ids, bool $all = false ) {
		$ids = array_values( array_filter( array_map( 'strval', $ids ), static fn( string $id ): bool => 1 === preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) );
		if ( ! $all && ! $ids ) {
			return new \WP_Error( 'zinn_seo_notices_which', __( 'Say which notice to dismiss, or all of them.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/notices', $all ? array( 'all' => true ) : array( 'ids' => array_slice( $ids, 0, 100 ) ) );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		// Every language's kept list is stale now.
		$row       = self::row();
		$row['by'] = array();
		update_option( self::OPTION, $row, false );

		return self::feed( true );
	}

	/**
	 * Should this notice be e-mailed by the site?
	 *
	 * @param array<string, mixed> $item      The API's notice (with `site_email`).
	 * @param string               $choice    The site's setting: important|all|off.
	 * @param array<string, int>   $mailed    Ids already sent.
	 * @param int                  $connected When the site was connected.
	 * @param int                  $now       Now.
	 * @return bool
	 */
	public static function should_mail( array $item, string $choice, array $mailed, int $connected, int $now ): bool {
		$id   = strtolower( (string) ( $item['id'] ?? '' ) );
		$mail = is_array( $item['site_email'] ?? null ) ? $item['site_email'] : array();
		$at   = (int) strtotime( (string) ( $item['created_at'] ?? '' ) );
		if ( 'off' === $choice || '' === $id || isset( $mailed[ $id ] ) || ! empty( $item['acked'] ) ) {
			return false;
		}
		if ( '' === trim( (string) ( $mail['subject'] ?? '' ) ) || ( '' === trim( (string) ( $mail['text'] ?? '' ) ) && '' === trim( (string) ( $mail['html'] ?? '' ) ) ) ) {
			return false;
		}
		if ( $at <= 0 || $at < $connected || $at < $now - self::MAIL_WINDOW ) {
			return false;
		}

		return 'all' === $choice || ! empty( $mail['default_on'] );
	}

	/**
	 * What is new in a list about the account's credit: anything at all (the kept balance is
	 * then stale), and whether paused checks have resumed (a whole-site check that paused here
	 * for credit then carries on). "New" is not seen before and not older than the connection.
	 *
	 * @param array<int, mixed>  $items     The API's notices.
	 * @param array<string, int> $seen      Ids already handled.
	 * @param int                $connected When the site was connected.
	 * @return array{money: bool, resumed: bool}
	 */
	public static function news( array $items, array $seen, int $connected ): array {
		$out = array(
			'money'   => false,
			'resumed' => false,
		);
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $item['kind'] ?? '' ) );
			$id   = strtolower( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id || isset( $seen[ $id ] ) || (int) strtotime( (string) ( $item['created_at'] ?? '' ) ) < $connected || empty( self::kinds()[ $kind ] ) ) {
				continue;
			}
			$out['money']   = true;
			$out['resumed'] = $out['resumed'] || 'credit_resumed' === $kind;
		}

		return $out;
	}

	/**
	 * Who the site's e-mails go to.
	 *
	 * @return string
	 */
	public static function recipient(): string {
		$to = sanitize_email( (string) Settings::get( 'notices_email_to', '' ) );

		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/**
	 * The twice-daily step (and after connecting): read the list in the site's language, send
	 * the e-mails that are due, and tell the rest of the plugin when credit came back.
	 *
	 * @return array{mailed: int, unread: int}|\WP_Error
	 */
	public static function sync() {
		if ( ! Connection::connected() ) {
			wp_clear_scheduled_hook( self::CRON );

			return array(
				'mailed' => 0,
				'unread' => 0,
			);
		}
		$locale  = (string) get_locale();
		$fetched = self::fetch( $locale, '', 50 );
		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}
		$fetched   = (array) $fetched;
		$row       = self::row();
		$mailed    = array_map( 'intval', (array) $row['mailed'] );
		$choice    = (string) Settings::get( 'notices_email', 'important' );
		$connected = (int) Connection::row()['connected_at'];
		$to        = self::recipient();
		$sent      = 0;
		$news      = self::news( (array) $fetched['items'], $mailed, $connected );
		$money     = $news['money'];
		$resumed   = $news['resumed'];
		// Oldest first, so the mails arrive in the order things happened.
		foreach ( array_reverse( (array) $fetched['items'] ) as $item ) {
			if ( ! self::should_mail( $item, $choice, $mailed, $connected, time() ) ) {
				continue;
			}
			/**
			 * Filters whether a due notice is e-mailed (the alert channels may take e-mail off).
			 *
			 * @param bool $mail Whether to e-mail it.
			 */
			if ( apply_filters( 'zinn_seo_alert_mail', true ) && self::mail( $to, array( 'id' => strtolower( (string) $item['id'] ) ) + (array) $item['site_email'] ) ) {
				++$sent;
			}
			/**
			 * Fires for every notice the site's rule says to send, so other channels (Slack,
			 * Teams, Discord, Telegram, a webhook) get the same alerts as e-mail: ONE rule set.
			 *
			 * @param array<string, mixed> $alert `id`, `kind`, `subject`, `text`, `important`.
			 */
			do_action(
				'zinn_seo_alert',
				array(
					'id'        => strtolower( (string) $item['id'] ),
					'kind'      => sanitize_key( (string) ( $item['kind'] ?? '' ) ),
					'subject'   => wp_strip_all_tags( (string) ( $item['site_email']['subject'] ?? '' ) ),
					'text'      => (string) ( $item['site_email']['text'] ?? '' ),
					'important' => ! empty( $item['site_email']['default_on'] ),
				)
			);
			// Marked whether or not the mailer accepted it: a site whose mail is broken must
			// not retry the same notice twice a day for ever.
			$mailed[ strtolower( (string) $item['id'] ) ] = time();
		}
		// Seen but not mailed (switched off, or too old): never a candidate again.
		foreach ( (array) $fetched['items'] as $item ) {
			$id = strtolower( (string) ( $item['id'] ?? '' ) );
			if ( '' !== $id && ! isset( $mailed[ $id ] ) ) {
				$mailed[ $id ] = 0;
			}
		}
		arsort( $mailed );
		$row           = self::row();
		$row['mailed'] = array_slice( $mailed, 0, 300, true );
		$row['by']     = array(
			$locale => array(
				'at'     => time(),
				'etag'   => (string) $fetched['etag'],
				'unread' => (int) $fetched['unread'],
				'items'  => array_slice( array_values( array_filter( array_map( array( self::class, 'clean' ), (array) $fetched['items'] ) ) ), 0, 20 ),
			),
		);
		update_option( self::OPTION, $row, false );
		if ( $money ) {
			/** Fires when Zinn® has news about the account's credit: the kept balance is stale. */
			do_action( 'zinn_seo_credit_news' );
		}
		if ( $resumed ) {
			/** Fires when credit came back and paused checks are running again. */
			do_action( 'zinn_seo_credit_resumed' );
		}

		return array(
			'mailed' => $sent,
			'unread' => (int) $fetched['unread'],
		);
	}

	/**
	 * Send one notice from this site's own mailer: the HTML Zinn® rendered (the same template
	 * as its own e-mail, in the site's language) with the plain text as the alternative.
	 *
	 * @param string               $to   Recipient.
	 * @param array<string, mixed> $mail `{subject, text, html}`.
	 * @return bool
	 */
	public static function mail( string $to, array $mail ): bool {
		$subject = wp_strip_all_tags( (string) ( $mail['subject'] ?? '' ) );
		$text    = (string) ( $mail['text'] ?? '' );
		$html    = (string) ( $mail['html'] ?? '' );
		if ( ! is_email( $to ) || '' === $subject || strlen( $html ) > 300000 ) {
			return false;
		}
		// Q356: branded (sender, "[Zinn SEO]", footer with the mute link), one e-mail per notice.
		return \ZinnDigital\ZinnSEO\Alert_Mail::send(
			'notices',
			$to,
			$subject,
			$text,
			array(
				'html'      => $html,
				'signature' => (string) ( $mail['id'] ?? '' ),
			)
		);
	}

	/**
	 * Keep the twice-daily event in step with the connection.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( Connection::connected() ) {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'twicedaily', self::CRON );
			}
			return;
		}
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Forget the kept lists (on disconnect).
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * The count on the plugin's own menu entry.
	 *
	 * @return void
	 */
	public static function bubble(): void {
		global $menu;
		$count = self::unread();
		if ( $count < 1 || ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $position => $entry ) {
			if ( is_array( $entry ) && Admin::SLUG === ( $entry[2] ?? '' ) ) {
				/* translators: %s: how many notices. */
				$label = sprintf( _n( '%s notice', '%s notices', $count, 'zinn-seo' ), number_format_i18n( $count ) );
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the documented way to add a count to one's own menu entry.
				$menu[ $position ][0] .= ' <span class="awaiting-mod count-' . (int) $count . '"><span aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
				return;
			}
		}
	}

	/**
	 * ONE notice, on this plugin's own screens only: the most serious thing not yet dismissed.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_' . Admin::SLUG !== $screen->id || ! ( current_user_can( Capabilities::SPEND ) || current_user_can( Capabilities::MANAGE ) ) ) {
			return;
		}
		// The Credit screen shows the whole list already.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen is open, nothing is changed.
		if ( 'credits' === sanitize_key( (string) wp_unslash( $_GET['view'] ?? '' ) ) ) {
			return;
		}
		$feed = self::feed();
		$top  = is_array( $feed ) ? self::top( (array) $feed['items'] ) : null;
		if ( null === $top ) {
			return;
		}
		$class   = in_array( $top['severity'], array( 'critical', 'warning' ), true ) ? 'notice-warning' : ( 'success' === $top['severity'] ? 'notice-success' : 'notice-info' );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS . '&id=' . rawurlencode( $top['id'] ) ), self::DISMISS );
		echo '<div class="notice ' . esc_attr( $class ) . ' zseo-zinn-notice"><p><strong>' . esc_html( $top['title'] ) . '</strong> ' . esc_html( wp_trim_words( $top['message'], 40 ) ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::SLUG . '&view=credits' ) ) . '">' . esc_html__( 'See what to do', 'zinn-seo' ) . '</a> &nbsp;·&nbsp; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'zinn-seo' ) . '</a></p></div>';
	}

	/**
	 * The most serious notice not yet dismissed, newest first within a severity — never one a
	 * newer notice says is over (RESOLVES).
	 *
	 * @param array<int, array<string, mixed>> $items Cleaned notices.
	 * @return array<string, mixed>|null
	 */
	public static function top( array $items ): ?array {
		$over = self::superseded( $items );
		$rank = array(
			'critical' => 3,
			'warning'  => 2,
			'success'  => 1,
			'info'     => 0,
		);
		$best = null;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['acked'] ) || isset( $over[ (string) ( $item['id'] ?? '' ) ] ) ) {
				continue;
			}
			if ( null === $best || ( $rank[ $item['severity'] ] ?? 0 ) > ( $rank[ $best['severity'] ] ?? 0 ) || ( ( $rank[ $item['severity'] ] ?? 0 ) === ( $rank[ $best['severity'] ] ?? 0 ) && (int) $item['at'] > (int) $best['at'] ) ) {
				$best = $item;
			}
		}

		return $best;
	}

	/**
	 * The notice's "Dismiss" link.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		check_admin_referer( self::DISMISS );
		if ( ! ( current_user_can( Capabilities::SPEND ) || current_user_can( Capabilities::MANAGE ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'zinn-seo' ), 403 );
		}
		self::dismiss( array( sanitize_text_field( (string) wp_unslash( $_GET['id'] ?? '' ) ) ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Admin::SLUG ) );
		exit;
	}
}
