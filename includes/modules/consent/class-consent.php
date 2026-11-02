<?php
/**
 * Cookie consent banner with Google Consent Mode v2 (zs-783, rival SEOPress 10.4).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Consent;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⚖️ Owner (catalogue 17): "Fast, no frontend load ... and no blaot" excluded a banner; the owner's
 * 2026-10-30 rival list (row 71b) asks for one. The coordinator's ruling joins them: the module is
 * OFF on every site, and while it is off this file is never read (zs-715), so not one byte reaches
 * a page. Switched on, a page carries one small inline script (the Consent Mode default, which
 * must come before any tag), one inline style and one small script FILE (the banner, ~3 KB, in the
 * footer). The banner's code is a file, not inlined, because WordPress.org review refuses an
 * inline script built from a variable (wp-dotorg-standards D008, caught at push 2026-11-01):
 *
 *  - in the head, FIRST (priority 1, before any Google tag), Consent Mode v2's default: every
 *    signal (ad_storage, analytics_storage, ad_user_data, ad_personalization) denied, then, for a
 *    visitor who already chose, the update from the remembered choice, so a returning visitor's
 *    tags start with the right answer;
 *  - in the footer, the banner (hidden until the script finds no remembered choice) with a separate
 *    analytics and advertising switch, Reject all / Save / Accept all of equal weight, and a link to
 *    the privacy policy; and a small "Privacy choices" button to re-open it.
 *
 * Every page is the same for every visitor (the script, not the server, reads the choice), so a
 * full-page cache never serves one visitor's answer to another. Nothing in the markup names this
 * plugin (no-trace mode, zs-747); the banner script's address is the plugin's own folder, as every
 * enqueued WordPress plugin file is. The choice is a first-party cookie, `site_consent=a<0|1>.d<0|1>`, kept
 * for 180 days. Any link to `#site-consent`, an element with `data-consent-open`, or the
 * `[zinn_seo_consent_link]` shortcode re-opens the banner.
 */
final class Consent {

	/** The cookie that remembers the choice (necessary, so it needs no consent of its own). */
	public const COOKIE = 'site_consent';

	/** How long the choice is remembered, in days. */
	public const DAYS = 180;

	/** Script and style handle (the style is inline; the script is assets/consent/consent.js). */
	public const HANDLE = 'site-consent';

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		add_action( 'wp_head', array( self::class, 'head' ), 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_footer', array( self::class, 'footer' ), 5 );
		add_shortcode( 'zinn_seo_consent_link', array( self::class, 'shortcode' ) );
	}

	/**
	 * Whether this response is a page a visitor sees (not a feed, an embed or an AMP page).
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		if ( is_admin() || is_feed() || is_embed() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return false;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}

		/**
		 * Filters whether the consent banner and Consent Mode defaults are printed on this page.
		 *
		 * @param bool $applies True on an ordinary front-end page.
		 */
		return (bool) apply_filters( 'zinn_seo_consent_applies', true );
	}

	/**
	 * Whether the banner asks about advertising (when not, advertising stays denied).
	 *
	 * @return bool
	 */
	public static function asks_ads(): bool {
		return (bool) Settings::get( 'consent_ads', true );
	}

	/**
	 * Consent Mode v2's default (all denied) and the remembered choice's update, as JavaScript.
	 *
	 * @param bool $ads Whether advertising can be granted at all.
	 * @return string
	 */
	public static function default_script( bool $ads ): string {
		$ad_grant = $ads ? "m[2]==='d1'?'granted':'denied'" : "'denied'";
		return 'window.dataLayer=window.dataLayer||[];'
			. 'function gtag(){dataLayer.push(arguments);}'
			. "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});"
			. "gtag('set','ads_data_redaction',true);"
			. '(function(){var m=document.cookie.match(/(?:^|;\s*)' . self::COOKIE . '=(a[01])\.(d[01])(?:;|$)/);'
			. "if(!m){return;}var a=m[1]==='a1'?'granted':'denied',d=" . $ad_grant . ';'
			. "gtag('consent','update',{analytics_storage:a,ad_storage:d,ad_user_data:d,ad_personalization:d});})();";
	}

	/**
	 * The banner's style: logical properties only, so it mirrors itself on a right-to-left page.
	 *
	 * @return string
	 */
	public static function style(): string {
		return '.site-consent[hidden],.site-consent-open[hidden]{display:none!important}'
			. '.site-consent{position:fixed;z-index:99999;inset-block-end:1rem;inset-inline:1rem;max-inline-size:40rem;margin-inline:auto;'
			. 'box-sizing:border-box;padding:1rem 1.25rem;background:#fff;color:#1e1e1e;border:1px solid #c3c4c7;border-radius:8px;'
			. 'box-shadow:0 4px 24px rgba(0,0,0,.18);font:inherit;font-size:15px;line-height:1.5}'
			. '.site-consent .screen-reader-text{position:absolute;inline-size:1px;block-size:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
			. '.site-consent__title{margin:0 0 .25rem;font-weight:700}'
			. '.site-consent__text{margin:0 0 .75rem}'
			. '.site-consent__text a{color:inherit;text-decoration:underline}'
			. '.site-consent__opts{display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;margin:0 0 .75rem;padding:0;border:0}'
			. '.site-consent__opts label{display:inline-flex;align-items:center;gap:.4rem}'
			. '.site-consent__btns{display:flex;flex-wrap:wrap;gap:.5rem}'
			. '.site-consent__btns button,.site-consent-open{font:inherit;cursor:pointer;border-radius:6px;border:1px solid #1e1e1e;background:#fff;color:#1e1e1e;padding:.45rem .9rem}'
			. '.site-consent__btns button:focus-visible,.site-consent-open:focus-visible{outline:2px solid #2271b1;outline-offset:2px}'
			. '.site-consent-open{position:fixed;z-index:99998;inset-block-end:.75rem;inset-inline-start:.75rem;font-size:13px;padding:.3rem .7rem;opacity:.9}';
	}

	/**
	 * The banner and the re-open button, escaped and translated.
	 *
	 * @param bool   $ads     Whether advertising is asked about.
	 * @param bool   $reopen  Whether the floating "Privacy choices" button is shown after a choice.
	 * @param string $privacy The privacy policy address ('' when the site has none).
	 * @return string
	 */
	public static function markup( bool $ads, bool $reopen, string $privacy ): string {
		$config = wp_json_encode(
			array(
				'ads'    => $ads,
				'reopen' => $reopen,
				'days'   => self::DAYS,
				'cookie' => self::COOKIE,
			)
		);
		$text   = $ads
			? __( 'We would like to use cookies to understand how this site is used and, if you agree, to show you relevant advertising. Choose what you allow; you can change your choice at any time.', 'zinn-seo' )
			: __( 'We would like to use cookies to understand how this site is used. Choose what you allow; you can change your choice at any time.', 'zinn-seo' );
		$link   = '' !== $privacy
			? ' <a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy policy', 'zinn-seo' ) . '</a>'
			: '';
		$opts   = '<label><input type="checkbox" checked disabled> ' . esc_html__( 'Necessary (always on)', 'zinn-seo' ) . '</label>'
			. '<label><input type="checkbox" name="analytics"> ' . esc_html__( 'Analytics', 'zinn-seo' ) . '</label>'
			. ( $ads ? '<label><input type="checkbox" name="ads"> ' . esc_html__( 'Advertising', 'zinn-seo' ) . '</label>' : '' );

		return '<div id="site-consent" class="site-consent" role="dialog" aria-modal="false" aria-labelledby="site-consent-title" aria-describedby="site-consent-text" hidden data-config="' . esc_attr( (string) $config ) . '">'
			. '<p id="site-consent-title" class="site-consent__title">' . esc_html__( 'Your privacy choices', 'zinn-seo' ) . '</p>'
			. '<p id="site-consent-text" class="site-consent__text">' . esc_html( $text ) . $link . '</p>'
			. '<fieldset class="site-consent__opts"><legend class="screen-reader-text">' . esc_html__( 'Cookies you allow', 'zinn-seo' ) . '</legend>' . $opts . '</fieldset>'
			. '<div class="site-consent__btns">'
			. '<button type="button" data-act="none">' . esc_html__( 'Reject all', 'zinn-seo' ) . '</button>'
			. '<button type="button" data-act="save">' . esc_html__( 'Save choices', 'zinn-seo' ) . '</button>'
			. '<button type="button" data-act="all">' . esc_html__( 'Accept all', 'zinn-seo' ) . '</button>'
			. '</div></div>'
			. '<button type="button" class="site-consent-open" data-consent-open hidden>' . esc_html__( 'Privacy choices', 'zinn-seo' ) . '</button>';
	}

	/**
	 * Print Consent Mode's default first in the head, before any tag can read it.
	 *
	 * @return void
	 */
	public static function head(): void {
		if ( ! self::applies() ) {
			return;
		}
		wp_print_inline_script_tag( self::default_script( self::asks_ads() ) );
	}

	/**
	 * The banner's inline style and its script file (in the footer, after the banner it drives).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::applies() ) {
			return;
		}
		wp_register_style( self::HANDLE, false, array(), ZINN_SEO_VERSION );
		wp_add_inline_style( self::HANDLE, self::style() );
		wp_enqueue_style( self::HANDLE );

		wp_enqueue_script( self::HANDLE, ZINN_SEO_URL . 'assets/consent/consent.js', array(), ZINN_SEO_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * Print the banner (hidden; the script shows it when no choice is remembered).
	 *
	 * @return void
	 */
	public static function footer(): void {
		if ( ! self::applies() ) {
			return;
		}
		echo self::markup( self::asks_ads(), (bool) Settings::get( 'consent_reopen', true ), (string) get_privacy_policy_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped in markup().
	}

	/**
	 * `[zinn_seo_consent_link]` (optional `text`): a link that re-opens the banner.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'text' => __( 'Privacy choices', 'zinn-seo' ) ), (array) $atts, 'zinn_seo_consent_link' );
		return '<a href="#site-consent" data-consent-open>' . esc_html( (string) $atts['text'] ) . '</a>';
	}
}
