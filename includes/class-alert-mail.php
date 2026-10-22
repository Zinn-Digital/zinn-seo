<?php
/**
 * Every e-mail Zinn® SEO sends: branded, grouped, and each kind can be muted (Q356).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔ Q356 (owner, 2026-10-19, zinnhub): the site-care "PHP stopped building a page" e-mail did
 * not say Zinn® SEO sent it, and it came every hour for the same out-of-memory error. So every
 * e-mail the plugin sends goes through here:
 *
 * - it says who sent it: the sender's name is "Zinn® SEO on <site>", the subject starts with
 *   "[Zinn® SEO]", and a footer names the plugin, links to the alert settings and to a one-click
 *   mute of that kind of e-mail;
 * - a repeat of the same alert (the same kind and signature — for a fatal error, the error's own
 *   signature) inside the period (Settings › Alert e-mails: an hour, a day or a week) is not sent
 *   again: it is counted, and ONE e-mail at the end of the period says how many times it
 *   happened;
 * - a kind the site muted is not e-mailed at all (the alert channels and the screens still
 *   show it).
 */
final class Alert_Mail {

	/** What was sent and what waits, per kind and signature (not autoloaded, at most LOG_MAX). */
	public const LOG = 'zseo_alert_mail_log';

	/** Entries kept in LOG. */
	public const LOG_MAX = 200;

	/** The one-off event that sends the grouped repeats when their period ends. */
	public const DIGEST = 'zseo_alert_mail_digest';

	/** The subject's prefix (the plugin's name, never translated). */
	public const PREFIX = '[Zinn® SEO]';

	/** Query arguments of the mute link. */
	public const MUTE = 'zseo_mute';

	/** Its token. */
	public const MUTE_TOKEN = 'zseo_mute_t';

	/** The screen says which kind a link muted. */
	public const MUTED = 'zseo_muted';

	/** Periods (Settings › Alert e-mails), in seconds. */
	public const PERIODS = array(
		'hour' => HOUR_IN_SECONDS,
		'day'  => DAY_IN_SECONDS,
		'week' => WEEK_IN_SECONDS,
	);

	/** Most bytes of an HTML body kept to send later (a bigger one is sent as text). */
	private const HTML_KEPT = 100000;

	/**
	 * The kinds of e-mail, with what the settings and the footer call them.
	 *
	 * ⭐ The ids are also the choices of the `alert_mail_muted` setting (class-settings.php
	 * writes them out so a visitor's page never loads this class); a test keeps both the same.
	 *
	 * @return array<string, string> id => label.
	 */
	public static function kinds(): array {
		return array(
			'fatal_error'            => __( 'Fatal PHP errors on the site', 'zinn-seo' ),
			'fatal_error_background' => __( 'Errors in background jobs on your server (not pages visitors see)', 'zinn-seo' ),
			'fix_auto_off'           => __( 'A code fix switched off by itself', 'zinn-seo' ),
			'malware_found'          => __( 'Malware found by the scan', 'zinn-seo' ),
			'spam_found'             => __( 'Spam a hack may have added', 'zinn-seo' ),
			'guard_round'            => __( 'Safe updates: a round of updates', 'zinn-seo' ),
			'guard_rollback'         => __( 'Safe updates: an update rolled back', 'zinn-seo' ),
			'broken_links'           => __( 'New broken links', 'zinn-seo' ),
			'notfound'               => __( 'Missing pages visitors asked for', 'zinn-seo' ),
			'content_copy'           => __( 'Your content copied on another site', 'zinn-seo' ),
			'link_spike'             => __( 'A sudden wave of spammy links to your site', 'zinn-seo' ),
			'background_backlog'     => __( 'Background tasks running late (e-mails, orders)', 'zinn-seo' ),
			'autopilot_report'       => __( 'Autopilot reports', 'zinn-seo' ),
			'task'                   => __( 'SEO tasks given to you, and their comments', 'zinn-seo' ),
			'tasks_due'              => __( 'SEO tasks that are due', 'zinn-seo' ),
			'notices'                => __( 'Notices from Zinn® (credit, paused checks)', 'zinn-seo' ),
		);
	}

	/**
	 * The one shape of every alert's words (Q407, owner 2026-10-21: "make them also a bit clear
	 * english - like this error means x or y as non tech people wont understand them"):
	 *
	 * 1. what happened and what it means for the site and its visitors, in plain words;
	 * 2. the things it is about (pages, links, updates), when there are any;
	 * 3. whether the reader must do anything, and the ONE next step, with its link;
	 * 4. below a fold, the technical details for a developer (codes, file:line, PHP's words).
	 *
	 * Nothing technical goes above the fold. Care::fatal_mails (0.17.61) set the pattern; this is
	 * it, shared, so every other e-mail reads the same way.
	 *
	 * @param string $what What happened and what it means (no jargon).
	 * @param string $act  Whether to act, and the one next step.
	 * @param string $link Where to do it ('' none).
	 * @param string $items The things it is about, one per line ('' none).
	 * @param string $tech Technical details for a developer ('' none).
	 * @return string
	 */
	public static function plain( string $what, string $act, string $link = '', string $items = '', string $tech = '' ): string {
		$parts = array( trim( $what ) );
		if ( '' !== trim( $items ) ) {
			$parts[] = rtrim( $items );
		}
		$parts[] = trim( $act ) . ( '' !== $link ? "\n" . $link : '' );
		if ( '' !== trim( $tech ) ) {
			$parts[] = __( '--- Technical details (for you or your developer) ---', 'zinn-seo' ) . "\n" . rtrim( $tech );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Hooks.
	 *
	 * @param string $context front, admin, rest, cli or cron.
	 * @return void
	 */
	public static function boot( string $context ): void {
		add_action( self::DIGEST, array( self::class, 'digest' ) );
		if ( 'admin' === $context ) {
			add_action( 'admin_init', array( self::class, 'handle_mute' ) );
		}
	}

	/**
	 * Send one alert e-mail, or count it when the same one went out inside the period.
	 *
	 * @param string               $kind    One of kinds().
	 * @param string               $to      Recipient.
	 * @param string               $subject Subject (without the prefix).
	 * @param string               $text    Plain text.
	 * @param array<string, mixed> $o       `signature` (what makes two alerts "the same"; default:
	 *                                      the subject with its numbers set aside), `html` (an HTML
	 *                                      body; the text is then its alternative).
	 * @return bool Sent now, or counted toward the period's e-mail.
	 */
	public static function send( string $kind, string $to, string $subject, string $text, array $o = array() ): bool {
		if ( ! is_email( $to ) || '' === trim( $subject ) || self::muted( $kind ) ) {
			return false;
		}
		$sig  = (string) ( $o['signature'] ?? '' );
		$sig  = '' !== $sig ? $sig : self::signature_of( $subject );
		$key  = substr( md5( $kind . '|' . $sig ), 0, 16 );
		$html = (string) ( $o['html'] ?? '' );
		$now  = time();
		$log  = self::log();
		$row  = $log[ $key ] ?? null;
		$mail = array(
			'to'      => $to,
			'subject' => $subject,
			'text'    => $text,
			'html'    => strlen( $html ) > self::HTML_KEPT ? '' : $html,
		);
		if ( is_array( $row ) && $now - (int) $row['sent'] < self::period() ) {
			// The same alert inside the period: counted, sent once when the period ends.
			$row['held']  = (int) $row['held'] + 1;
			$row['first'] = (int) $row['first'] > 0 ? (int) $row['first'] : $now;
			$row['mail']  = $mail; // The newest occurrence's words.
			$log[ $key ]  = $row;
			self::save( $log );
			self::schedule( (int) $row['sent'] + self::period() );
			return true;
		}
		// Repeats the period's e-mail never sent (no cron ran) are counted in this one.
		$held        = is_array( $row ) ? (int) $row['held'] : 0;
		$first       = is_array( $row ) ? (int) $row['first'] : 0;
		$log[ $key ] = array(
			'kind'  => $kind,
			'sent'  => $now,
			'held'  => 0,
			'first' => 0,
			'mail'  => null,
		);
		// Recorded BEFORE the mailer runs: a mailer that fails, or another plugin's mail hook that
		// throws, must not turn every repeat into a new e-mail.
		self::save( $log );

		return self::deliver( $kind, $to, $subject, $text, $html, $held, $first );
	}

	/**
	 * The period's e-mails: each alert that repeated since it was sent, once, with the count.
	 *
	 * @param int|null $now Timestamp (tests).
	 * @return int E-mails sent.
	 */
	public static function digest( ?int $now = null ): int {
		$now    = $now ?? time();
		$period = self::period();
		$log    = self::log();
		$due    = array();
		$next   = 0;
		foreach ( $log as $key => $row ) {
			if ( (int) $row['held'] < 1 || ! is_array( $row['mail'] ) ) {
				continue;
			}
			$at = (int) $row['sent'] + $period;
			if ( $at > $now ) {
				$next = 0 === $next ? $at : min( $next, $at );
				continue;
			}
			$due[]       = $row;
			$log[ $key ] = array(
				'kind'  => (string) $row['kind'],
				'sent'  => $now,
				'held'  => 0,
				'first' => 0,
				'mail'  => null,
			);
		}
		// Recorded first, as in send(): a mailer that throws never sends the same e-mail twice.
		self::save( $log );
		if ( $next > 0 ) {
			self::schedule( $next );
		}
		$sent = 0;
		foreach ( $due as $row ) {
			$m = (array) $row['mail'];
			if ( ! self::muted( (string) $row['kind'] ) && self::deliver( (string) $row['kind'], (string) $m['to'], (string) $m['subject'], (string) $m['text'], (string) $m['html'], (int) $row['held'], (int) $row['first'] ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Send it from the site's mailer, branded.
	 *
	 * @param string $kind    Kind.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $text    Text.
	 * @param string $html    HTML body ('' none).
	 * @param int    $held    Repeats counted since the last e-mail of this alert (0: none).
	 * @param int    $first   When the first of them happened.
	 * @return bool
	 */
	private static function deliver( string $kind, string $to, string $subject, string $text, string $html, int $held, int $first ): bool {
		$subject = self::subject( $subject, $held );
		$count   = self::count_line( $held, $first );
		$text    = ( '' !== $count ? $count . "\n\n" : '' ) . rtrim( $text ) . "\n\n" . self::footer( $kind );
		$from    = static fn(): string => self::from_name();
		add_filter( 'wp_mail_from_name', $from, 99 );
		try {
			if ( '' === trim( $html ) ) {
				return (bool) wp_mail( $to, $subject, $text );
			}
			$html = self::html_with( $html, $count, self::footer_html( $kind ) );
			$alt  = static function ( $mailer ) use ( $text ): void {
				if ( is_object( $mailer ) ) {
					$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
				}
			};
			add_action( 'phpmailer_init', $alt );
			try {
				return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
			} finally {
				remove_action( 'phpmailer_init', $alt );
			}
		} finally {
			remove_filter( 'wp_mail_from_name', $from, 99 );
		}
	}

	/**
	 * The sender's name: "Zinn® SEO on <site>".
	 *
	 * @return string
	 */
	public static function from_name(): string {
		/* translators: %s: the site's name. */
		return sprintf( __( 'Zinn® SEO on %s', 'zinn-seo' ), self::site() );
	}

	/**
	 * The subject, "[Zinn® SEO] …", with the count of a grouped e-mail.
	 *
	 * @param string $subject Subject.
	 * @param int    $held    Repeats counted (0: none).
	 * @return string
	 */
	public static function subject( string $subject, int $held = 0 ): string {
		// MAIL1: a subject is plain text, so "Oak &amp; Co" (get_bloginfo()'s escaped name) reads "Oak & Co".
		$subject = trim( wp_specialchars_decode( wp_strip_all_tags( $subject ), ENT_QUOTES ) );
		if ( $held > 0 ) {
			/* translators: 1: the alert's subject, 2: how many times it happened since the last e-mail. */
			$subject = sprintf( _n( '%1$s (%2$d time)', '%1$s (%2$d times)', $held, 'zinn-seo' ), $subject, $held );
		}

		return 0 === strpos( $subject, self::PREFIX ) ? $subject : self::PREFIX . ' ' . $subject;
	}

	/**
	 * What a grouped e-mail says first.
	 *
	 * @param int $held  Repeats counted.
	 * @param int $first When the first happened.
	 * @return string '' when nothing was counted.
	 */
	private static function count_line( int $held, int $first ): string {
		if ( $held < 1 ) {
			return '';
		}
		$when = $first > 0 ? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $first ) : '';

		/* translators: 1: how many times, 2: a date and time. */
		return sprintf( _n( 'This happened %1$d time since the last e-mail about it (first at %2$s). Repeats of the same alert are grouped into one e-mail per period.', 'This happened %1$d times since the last e-mail about it (first at %2$s). Repeats of the same alert are grouped into one e-mail per period.', $held, 'zinn-seo' ), $held, (string) $when );
	}

	/**
	 * The footer: which plugin sent it, where the alert settings are, how to mute this kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function footer( string $kind ): string {
		$lines = array(
			'--',
			/* translators: 1: the site's name, 2: the site's address. */
			sprintf( __( 'This e-mail was sent by the Zinn® SEO plugin on %1$s (%2$s).', 'zinn-seo' ), self::site(), home_url( '/' ) ),
			/* translators: %s: the address of the alert settings. */
			sprintf( __( 'Choose which alerts it e-mails and how often repeats are grouped: %s', 'zinn-seo' ), self::settings_url() ),
		);
		$label = self::kinds()[ $kind ] ?? '';
		if ( '' !== $label ) {
			/* translators: 1: a kind of alert, 2: the link that stops it. */
			$lines[] = sprintf( __( 'Stop e-mails about "%1$s": %2$s', 'zinn-seo' ), $label, self::mute_url( $kind ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * The footer for an HTML e-mail.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function footer_html( string $kind ): string {
		$label = self::kinds()[ $kind ] ?? '';
		$out   = '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0 12px"><p style="font-size:12px;color:#555;line-height:1.5">'
			/* translators: 1: the site's name, 2: the site's address. */
			. esc_html( sprintf( __( 'This e-mail was sent by the Zinn® SEO plugin on %1$s (%2$s).', 'zinn-seo' ), self::site(), home_url( '/' ) ) )
			. '<br><a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Choose which alerts it e-mails', 'zinn-seo' ) . '</a>';
		if ( '' !== $label ) {
			/* translators: %s: a kind of alert. */
			$out .= ' · <a href="' . esc_url( self::mute_url( $kind ) ) . '">' . esc_html( sprintf( __( 'Stop e-mails about "%s"', 'zinn-seo' ), $label ) ) . '</a>';
		}

		return $out . '</p>';
	}

	/**
	 * An HTML body with the count first and the footer last.
	 *
	 * @param string $html   Body.
	 * @param string $count  Count line ('' none).
	 * @param string $footer Footer HTML.
	 * @return string
	 */
	private static function html_with( string $html, string $count, string $footer ): string {
		if ( '' !== $count ) {
			$lead = '<p style="font-size:14px;background:#fff8e5;padding:8px 12px">' . esc_html( $count ) . '</p>';
			$html = preg_match( '/<body[^>]*>/i', $html ) ? (string) preg_replace( '/(<body[^>]*>)/i', '$1' . $lead, $html, 1 ) : $lead . $html;
		}
		$pos = strripos( $html, '</body>' );

		return false === $pos ? $html . $footer : substr( $html, 0, $pos ) . $footer . substr( $html, $pos );
	}

	/**
	 * Settings › Alert e-mails.
	 *
	 * @return string
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=zinn-seo&view=settings#/alerts' );
	}

	/**
	 * The one-click mute of a kind (it needs a signed-in manager of the plugin, and this token).
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function mute_url( string $kind ): string {
		return admin_url(
			'admin.php?page=zinn-seo&view=settings&' . self::MUTE . '=' . rawurlencode( $kind ) . '&' . self::MUTE_TOKEN . '=' . self::token( $kind )
		);
	}

	/**
	 * The mute link's token: a keyed hash of the kind, so a link names one kind of this site only.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function token( string $kind ): string {
		return substr( hash_hmac( 'sha256', 'zseo-alert-mute|' . $kind, wp_salt( 'auth' ) ), 0, 24 );
	}

	/**
	 * A mute link was opened (admin_init): mute that kind, then show the alert settings.
	 *
	 * @return void
	 */
	public static function handle_mute(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorised by the keyed token below and the capability.
		if ( ! isset( $_GET[ self::MUTE ], $_GET[ self::MUTE_TOKEN ] ) ) {
			return;
		}
		$kind  = sanitize_key( wp_unslash( (string) $_GET[ self::MUTE ] ) );
		$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::MUTE_TOKEN ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::kinds()[ $kind ] ) || ! hash_equals( self::token( $kind ), $token ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return; // The settings screen opens as usual; nothing changed.
		}
		self::mute( $kind );
		wp_safe_redirect( admin_url( 'admin.php?page=zinn-seo&view=settings&' . self::MUTED . '=' . rawurlencode( $kind ) . '#/alerts' ) );
		exit;
	}

	/**
	 * Mute one kind.
	 *
	 * @param string $kind Kind.
	 * @return void
	 */
	public static function mute( string $kind ): void {
		$muted = (array) Settings::get( 'alert_mail_muted', array() );
		if ( ! in_array( $kind, $muted, true ) ) {
			$muted[] = $kind;
			Settings::save( array( 'alert_mail_muted' => array_values( $muted ) ) );
		}
	}

	/**
	 * Whether this site muted a kind.
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	public static function muted( string $kind ): bool {
		return in_array( $kind, (array) Settings::get( 'alert_mail_muted', array() ), true );
	}

	/**
	 * Seconds inside which a repeat is counted, not sent.
	 *
	 * @return int
	 */
	public static function period(): int {
		$choice = (string) Settings::get( 'alert_mail_digest', 'day' );

		return self::PERIODS[ $choice ] ?? DAY_IN_SECONDS;
	}

	/**
	 * What makes two alerts "the same" when the caller names nothing: the subject, its numbers
	 * set aside ("3 new broken links" and "5 new broken links" are the same alert).
	 *
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function signature_of( string $subject ): string {
		return md5( (string) preg_replace( '/\d+/', 'N', strtolower( trim( $subject ) ) ) );
	}

	/**
	 * The log.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function log(): array {
		$log = get_option( self::LOG, array() );

		return is_array( $log ) ? array_filter( $log, 'is_array' ) : array();
	}

	/**
	 * Save the log, newest LOG_MAX entries.
	 *
	 * @param array<string, array<string, mixed>> $log Log.
	 * @return void
	 */
	private static function save( array $log ): void {
		if ( count( $log ) > self::LOG_MAX ) {
			uasort( $log, static fn( $a, $b ): int => (int) $b['sent'] <=> (int) $a['sent'] );
			$log = array_slice( $log, 0, self::LOG_MAX, true );
		}
		update_option( self::LOG, $log, false );
	}

	/**
	 * The period's e-mail is sent by a one-off event when the first period ends.
	 *
	 * @param int $at Timestamp.
	 * @return void
	 */
	private static function schedule( int $at ): void {
		$next = wp_next_scheduled( self::DIGEST );
		if ( false !== $next && (int) $next <= $at ) {
			return;
		}
		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::DIGEST );
		}
		wp_schedule_single_event( max( time() + 60, $at ), self::DIGEST );
	}

	/**
	 * The site's name as e-mails show it.
	 *
	 * @return string
	 */
	private static function site(): string {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return '' !== trim( $name ) ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
}
