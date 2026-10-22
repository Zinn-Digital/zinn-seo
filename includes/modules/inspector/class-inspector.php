<?php
/**
 * The admin-bar SEO inspector on the live page, for editors only (zs-430).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Inspector;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ ZERO BYTES FOR VISITORS. Every hook here returns at once unless the admin bar is showing
 * AND the person may see SEO data; the script and style are added only then, to WordPress's own
 * `admin-bar` handles, so a logged-out page carries nothing of this — measured by the stage
 * harness on a real page fetch.
 *
 * What it shows is read from the LIVE page in the browser (the `<title>`, every meta and link
 * tag, each JSON-LD script, hreflang alternates, the heading outline, images without alt text),
 * so it reports what is really printed — including another plugin's or the theme's tags — and
 * flags doubles (two titles, two canonicals, two structured-data graphs). Beside it, what Zinn®
 * SEO meant to print and the page's stored scores, with links to edit the page and to check its
 * structured data.
 */
final class Inspector {

	/**
	 * Hooks (front end).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_bar_menu', array( self::class, 'node' ), 90 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 99 );
	}

	/**
	 * May this person see the inspector on this page?
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		return is_user_logged_in() && is_admin_bar_showing() && ( current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::EDIT ) ) && ! is_admin();
	}

	/**
	 * The admin bar item.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function node( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! self::allowed() ) {
			return;
		}
		$score = null;
		$post  = get_queried_object();
		if ( $post instanceof \WP_Post ) {
			$stored = get_post_meta( (int) $post->ID, '_zseo_score', true );
			$score  = '' === $stored ? null : (int) $stored;
		}
		$label = __( 'SEO', 'zinn-seo' ) . ( null === $score ? '' : ' ' . $score );
		$bar->add_node(
			array(
				'id'    => 'zinn-seo-inspector',
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html( $label ) . '</span>',
				'href'  => '#zinn-seo-inspector',
				'meta'  => array(
					'class' => 'zseo-ab' . ( null === $score ? '' : ' zseo-ab--' . ( $score >= 75 ? 'good' : ( $score >= 50 ? 'ok' : 'bad' ) ) ),
					'title' => __( 'Inspect this page\'s SEO', 'zinn-seo' ),
				),
			)
		);
	}

	/**
	 * The script and style, on WordPress's own admin-bar handles.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::allowed() || ! wp_script_is( 'admin-bar', 'registered' ) ) {
			return;
		}
		// Files, as WordPress.org asks (never script built from a variable); only the data is
		// inline, encoded where it prints. Both ride after WordPress's own admin-bar handles.
		wp_enqueue_script( 'zinn-seo-inspector', plugins_url( 'inspector.js', __FILE__ ), array( 'admin-bar' ), ZINN_SEO_VERSION, true );
		wp_add_inline_script( 'zinn-seo-inspector', 'window.zinnSeoInspector = ' . wp_json_encode( self::data() ) . ';', 'before' );
		wp_enqueue_style( 'zinn-seo-inspector', plugins_url( 'inspector.css', __FILE__ ), array( 'admin-bar' ), ZINN_SEO_VERSION );
	}

	/**
	 * What the panel needs from the server.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$post = get_queried_object();
		$out  = array(
			'strings' => self::strings(),
			'rtl'     => is_rtl(),
			'page'    => null,
			'check'   => admin_url( 'admin.php?page=zinn-seo&view=schema#/check' ),
		);
		if ( $post instanceof \WP_Post ) {
			$score       = get_post_meta( (int) $post->ID, '_zseo_score', true );
			$read        = get_post_meta( (int) $post->ID, '_zseo_rscore', true );
			$out['page'] = array(
				'id'          => (int) $post->ID,
				'edit'        => current_user_can( 'edit_post', (int) $post->ID ) ? (string) get_edit_post_link( (int) $post->ID, 'raw' ) : '',
				'score'       => '' === $score ? null : (int) $score,
				'readability' => '' === $read ? null : (int) $read,
				'issues'      => array_values( array_filter( explode( ',', (string) get_post_meta( (int) $post->ID, '_zseo_issues', true ) ) ) ),
				'focus'       => (string) strtok( (string) get_post_meta( (int) $post->ID, '_zseo_focus', true ), "\n" ),
			);
		}

		return $out;
	}

	/**
	 * The panel's words (translated here, so the script needs no translation file).
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'title'       => __( 'SEO inspector', 'zinn-seo' ),
			'close'       => __( 'Close', 'zinn-seo' ),
			'head'        => __( 'What search engines read', 'zinn-seo' ),
			'pageTitle'   => __( 'Title', 'zinn-seo' ),
			'desc'        => __( 'Meta description', 'zinn-seo' ),
			'canonical'   => __( 'Canonical address', 'zinn-seo' ),
			'robots'      => __( 'Robots', 'zinn-seo' ),
			'none'        => __( 'None', 'zinn-seo' ),
			'indexable'   => __( 'Indexable (no robots tag)', 'zinn-seo' ),
			'schema'      => __( 'Structured data', 'zinn-seo' ),
			'schemaBad'   => __( 'A JSON-LD script could not be read.', 'zinn-seo' ),
			'hreflang'    => __( 'Language versions (hreflang)', 'zinn-seo' ),
			'social'      => __( 'When shared', 'zinn-seo' ),
			'headings'    => __( 'Headings', 'zinn-seo' ),
			'images'      => __( 'Images without alt text', 'zinn-seo' ),
			/* translators: %d: how many of a tag the page has. */
			'double'      => __( 'This tag appears %d times; search engines expect it once. Another plugin or the theme prints it too.', 'zinn-seo' ),
			'skip'        => __( 'Heading level skipped', 'zinn-seo' ),
			'scores'      => __( 'Scores', 'zinn-seo' ),
			'seo'         => __( 'SEO', 'zinn-seo' ),
			'readability' => __( 'Readability', 'zinn-seo' ),
			'notScored'   => __( 'Not analysed yet', 'zinn-seo' ),
			'focus'       => __( 'Focus phrase', 'zinn-seo' ),
			'edit'        => __( 'Edit this page', 'zinn-seo' ),
			'check'       => __( 'Check structured data', 'zinn-seo' ),
			/* translators: %d: number of characters. */
			'chars'       => __( '%d characters', 'zinn-seo' ),
			'copy'        => __( 'Copy as text', 'zinn-seo' ),
			'copied'      => __( 'Copied', 'zinn-seo' ),
		);
	}
}
