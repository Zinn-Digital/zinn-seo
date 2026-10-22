<?php
/**
 * The virtual robots.txt: WordPress's defaults, the AI-crawler choices, the owner's own rules and
 * the sitemap address (zs-060, zs-064, zs-067, zs-332).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Robots;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress answers `/robots.txt` itself when no file of that name exists in the site's folder,
 * and lets plugins change the text through the `robots_txt` filter. This module composes the
 * whole text there, in this order:
 *
 * 1. the `*` group: WordPress's defaults (keep the admin out, leave admin-ajax open — themes
 *    load content through it) and the content-signal line when switched on;
 * 2. one group for the AI crawlers the owner blocked;
 * 3. the owner's own rules, as written (kept in their own option, read only for this request);
 * 4. the rules OTHER plugins added through the same filter (WooCommerce keeps crawlers out of
 *    its logs and its add-to-cart links): merged in, never dropped (found by the importer
 *    certification, queue Q254: every move from another SEO plugin lost WooCommerce's lines);
 * 5. a `Sitemap:` line for each sitemap index.
 *
 * Groups for the same crawler are merged and a rule is printed once, so a rule present both in
 * the owner's text and in another plugin's lines (a site moved from Rank Math, whose text held
 * WooCommerce's rules) is not repeated.
 *
 * ⛔ A site set to "discourage search engines", and a staging copy, get `Disallow: /` for
 * everyone and nothing else can open it again. A real `robots.txt` file wins over all of this
 * (WordPress never runs) — the screen says so and offers to take its rules over.
 */
final class Robots {

	/** The owner's own rules (not autoloaded). */
	public const CUSTOM = 'zseo_robots_txt';

	/**
	 * Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'robots_txt', array( self::class, 'filter' ), 20, 2 );
	}

	/**
	 * The owner's own rules.
	 *
	 * @return string
	 */
	public static function custom(): string {
		$text = get_option( self::CUSTOM, '' );

		return is_string( $text ) ? $text : '';
	}

	/**
	 * Clean the owner's rules for storage. Not sanitize_textarea_field(): it removes anything that
	 * looks like a percent-encoded byte, and `Disallow: /%C3%A4/` is a valid rule.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( string $text ): string {
		$text = wp_check_invalid_utf8( $text );
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		return trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	}

	/**
	 * `robots_txt`: replace WordPress's text with ours.
	 *
	 * @param string $output WordPress's text.
	 * @param bool   $is_public Whether search engines are welcome (Settings, Reading).
	 * @return string
	 */
	public static function filter( $output, $is_public ): string {
		/**
		 * Fires when robots.txt is requested (the AI-crawler log counts bots here).
		 */
		do_action( 'zinn_seo_served_robots' );

		return self::compose( self::custom(), (bool) $is_public && ! Staging::is_staging(), (string) $output );
	}

	/**
	 * The complete text, from the owner's own rules (or a draft of them).
	 *
	 * @param string $custom The owner's rules.
	 * @param bool   $open   Search engines are welcome (not discouraged, not staging).
	 * @param string $core   What WordPress would print (kept for a discouraged site).
	 * @return string
	 */
	public static function compose( string $custom, bool $open, string $core = '' ): string {
		if ( ! $open ) {
			return "User-agent: *\nDisallow: /\n";
		}
		/**
		 * Filters the lines of the `*` group after its rules (the crawler module adds the
		 * content-signal line).
		 *
		 * @param array<int, string> $lines Lines.
		 */
		$star = (array) apply_filters( 'zinn_seo_robots_star', array() );
		/**
		 * Filters extra groups, each a list of lines (the crawler module adds the blocked AI
		 * crawlers).
		 *
		 * @param array<int, array<int, string>> $groups Groups.
		 */
		$groups = (array) apply_filters( 'zinn_seo_robots_groups', array() );
		/**
		 * Filters the sitemap addresses robots.txt names.
		 *
		 * @param array<int, string> $sitemaps Addresses.
		 */
		$sitemaps = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'zinn_seo_robots_sitemaps', array() ) ) ) );

		$blocks = array();
		if ( (bool) Settings::get( 'robots_defaults', true ) || array() !== $star ) {
			$lines = array( 'User-agent: *' );
			if ( (bool) Settings::get( 'robots_defaults', true ) ) {
				$admin   = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
				$lines[] = 'Disallow: ' . $admin;
				$lines[] = 'Allow: ' . $admin . 'admin-ajax.php';
			} else {
				$lines[] = 'Disallow:';
			}
			$blocks[] = implode( "\n", array_merge( $lines, array_map( 'strval', $star ) ) );
		}
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && array() !== $group ) {
				$blocks[] = implode( "\n", array_map( 'strval', $group ) );
			}
		}
		$custom = trim( str_replace( array( "\r\n", "\r" ), "\n", $custom ) );
		if ( '' !== $custom ) {
			$blocks[] = $custom;
		}
		$blocks[] = self::foreign( $core );
		if ( array() !== $sitemaps ) {
			$blocks[] = implode(
				"\n",
				array_map( static fn( string $url ): string => 'Sitemap: ' . $url, $sitemaps )
			);
		}

		return self::merge( implode( "\n\n", array_filter( $blocks, static fn( string $b ): bool => '' !== trim( $b ) ) ) );
	}

	/**
	 * What other plugins added to WordPress's text: every group and rule except WordPress's own
	 * defaults (the `*` group keeping the admin out) and the sitemap lines (ours are printed
	 * instead). A group left with no rule goes; it never runs into the next group. Pure, for the
	 * tests.
	 *
	 * @param string $core The text the `robots_txt` filter received.
	 * @return string
	 */
	public static function foreign( string $core ): string {
		$admin  = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
		$own    = array( 'disallow: ' . $admin, 'allow: ' . $admin . 'admin-ajax.php' );
		$parsed = self::groups( $core );
		$groups = array();
		foreach ( $parsed['groups'] as $group ) {
			if ( array( '*' ) === array_keys( $group['agents'] ) ) {
				$group['rules'] = array_diff_key( $group['rules'], array_flip( $own ) );
			}
			$groups[] = $group;
		}

		return self::text( $parsed['preamble'], $groups, array() );
	}

	/**
	 * One group per crawler (or set of crawlers), each rule once, the sitemap lines last.
	 * Comments stay with the group they were written in. Pure, for the tests.
	 *
	 * @param string $text robots.txt text.
	 * @return string
	 */
	public static function merge( string $text ): string {
		$parsed = self::groups( $text );
		$merged = array();
		foreach ( $parsed['groups'] as $group ) {
			$names = array_keys( $group['agents'] );
			sort( $names );
			$key = implode( "\n", $names );
			if ( isset( $merged[ $key ] ) ) {
				$merged[ $key ]['rules'] += $group['rules'];
			} else {
				$merged[ $key ] = $group;
			}
		}

		return self::text( $parsed['preamble'], array_values( $merged ), $parsed['sitemaps'] );
	}

	/**
	 * The robots.txt text read as crawlers read it: a group is the run of `User-agent` lines (blank lines
	 * between them included) and the rules after them, up to the next `User-agent` line.
	 *
	 * @param string $text Text.
	 * @return array{preamble: array<string, string>, groups: array<int, array{agents: array<string, string>, rules: array<string, string>}>, sitemaps: array<string, bool>}
	 */
	private static function groups( string $text ): array {
		$out     = array(
			'preamble' => array(),
			'groups'   => array(),
			'sitemaps' => array(),
		);
		$current = null;
		$reading = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 1 === preg_match( '/^sitemap\s*:/i', $line ) ) {
				$out['sitemaps'][ (string) preg_replace( '/^sitemap\s*:\s*/i', 'Sitemap: ', $line ) ] = true;
				continue;
			}
			if ( 1 === preg_match( '/^user-agent\s*:\s*(.*)$/i', $line, $m ) ) {
				if ( ! $reading ) {
					if ( null !== $current ) {
						$out['groups'][] = $current;
					}
					$current = array(
						'agents' => array(),
						'rules'  => array(),
					);
				}
				$current['agents'][ strtolower( trim( $m[1] ) ) ] = 'User-agent: ' . trim( $m[1] );
				$reading = true;
				continue;
			}
			$reading = false;
			if ( null === $current ) {
				$out['preamble'][ $line ] = $line;
				continue;
			}
			// The directive's name is read in any case; its path is case-sensitive (`/Private/` is
			// not `/private/`).
			$key                      = 1 === preg_match( '/^([^:]+):\s*(.*)$/', $line, $r ) ? strtolower( trim( $r[1] ) ) . ': ' . $r[2] : $line;
			$current['rules'][ $key ] = $line;
		}
		if ( null !== $current ) {
			$out['groups'][] = $current;
		}

		return $out;
	}

	/**
	 * Groups back to text: blocks apart by a blank line, a group with no rule left out.
	 *
	 * @param array<string, string>                                                          $preamble Lines before any group.
	 * @param array<int, array{agents: array<string, string>, rules: array<string, string>}> $groups   Groups.
	 * @param array<string, bool>                                                            $sitemaps Sitemap lines.
	 * @return string
	 */
	private static function text( array $preamble, array $groups, array $sitemaps ): string {
		$blocks = array();
		if ( array() !== $preamble ) {
			$blocks[] = implode( "\n", $preamble );
		}
		foreach ( $groups as $group ) {
			if ( array() === $group['rules'] ) {
				continue;
			}
			$blocks[] = implode( "\n", array_merge( array_values( $group['agents'] ), array_values( $group['rules'] ) ) );
		}
		if ( array() !== $sitemaps ) {
			$blocks[] = implode( "\n", array_keys( $sitemaps ) );
		}

		return array() === $blocks ? '' : implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Is there a real robots.txt file in the site's folder (which WordPress cannot override)?
	 *
	 * @return bool
	 */
	public static function physical_file(): bool {
		return file_exists( self::physical_path() );
	}

	/**
	 * Where a real robots.txt would be.
	 *
	 * @return string
	 */
	public static function physical_path(): string {
		return trailingslashit( function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH ) . 'robots.txt';
	}
}
