<?php
/**
 * Link attributes by rule: nofollow, sponsored, ugc and new tab, per domain (zs-106).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings (in the one autoloaded option, so a visitor's page reads nothing extra):
 *
 * - `links_external_rel`: rel added to every link to another site (`''`, `nofollow`, `sponsored`);
 * - `links_external_newtab`: open links to other sites in a new tab (with `noopener`);
 * - `links_rules`: one rule per line — `<domain or /path/> <rel words…> [newtab] [follow]` —
 *   e.g. `amazon.com sponsored nofollow newtab`, `/go/ sponsored` (a cloaked affiliate path on
 *   this site counts as outbound), `partner.example follow` (never add the site-wide rel there).
 *
 * Applied to post content on output (`the_content`, after shortcodes and blocks) with WordPress's
 * HTML tag processor — no regex over HTML, existing rel words kept. Nothing runs, and nothing is
 * read, when no rule and neither switch is set.
 */
final class Attributes {

	/**
	 * Parsed rules for this request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $rules = null;

	/**
	 * The settings' schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * Hook the front end when there is anything to do.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		if ( '' === (string) Settings::get( 'links_external_rel', '' ) && ! Settings::get( 'links_external_newtab', false ) && '' === trim( (string) Settings::get( 'links_rules', '' ) ) ) {
			return;
		}
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return;
		}
		add_filter( 'the_content', array( self::class, 'filter' ), 99 );
	}

	/**
	 * Parse the rules text.
	 *
	 * @param string $text Rules.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( string $text ): array {
		$out   = array();
		$lines = preg_split( '/\R/', $text );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$words = preg_split( '/\s+/', strtolower( trim( $line ) ) );
			if ( ! is_array( $words ) || '' === $words[0] || '#' === $words[0][0] ) {
				continue;
			}
			$match = array_shift( $words );
			$rule  = array(
				'match'  => $match,
				'path'   => '/' === $match[0],
				'rel'    => array_values( array_intersect( $words, array( 'nofollow', 'sponsored', 'ugc', 'noopener', 'noreferrer' ) ) ),
				'newtab' => in_array( 'newtab', $words, true ),
				'follow' => in_array( 'follow', $words, true ),
			);
			if ( ! $rule['path'] ) {
				$rule['match'] = (string) preg_replace( '#^(https?://)?(www\.)?#', '', rtrim( $match, '/' ) );
			}
			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * The attributes one link gets.
	 *
	 * @param string                           $href    Link.
	 * @param string                           $host    This site's host.
	 * @param array<int, array<string, mixed>> $rules   Rules.
	 * @param string                           $ext_rel Rel for every outbound link.
	 * @param bool                             $newtab  Outbound links open in a new tab.
	 * @return array{rel: array<int, string>, newtab: bool}|null Null when the link is left alone.
	 */
	public static function decide( string $href, string $host, array $rules, string $ext_rel, bool $newtab ): ?array {
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
			return null;
		}
		$link_host = strtolower( (string) preg_replace( '/^www\./', '', (string) ( $parts['host'] ?? '' ) ) );
		$own       = strtolower( (string) preg_replace( '/^www\./', '', $host ) );
		$path      = (string) ( $parts['path'] ?? '' );
		$outbound  = '' !== $link_host && $link_host !== $own;
		$rel       = array();
		$tab       = false;
		$follow    = false;
		$matched   = false;
		foreach ( $rules as $rule ) {
			$hit = $rule['path']
				? ( ( '' === $link_host || $link_host === $own ) && 0 === strpos( $path, (string) $rule['match'] ) )
				: ( '' !== $link_host && ( $link_host === $rule['match'] || substr( $link_host, -strlen( '.' . $rule['match'] ) ) === '.' . $rule['match'] ) );
			if ( ! $hit ) {
				continue;
			}
			$matched = true;
			$rel     = array_merge( $rel, (array) $rule['rel'] );
			$tab     = $tab || $rule['newtab'];
			$follow  = $follow || $rule['follow'];
			if ( $rule['path'] ) {
				$outbound = true; // A cloaked path leaves the site.
			}
		}
		if ( $outbound && ! $follow && '' !== $ext_rel ) {
			$rel[] = $ext_rel;
		}
		if ( $outbound && $newtab ) {
			$tab = true;
		}
		if ( ! $matched && ! $outbound ) {
			return null;
		}
		if ( $tab ) {
			$rel[] = 'noopener';
		}
		$rel = array_values( array_unique( $rel ) );

		return array() === $rel && ! $tab ? null : array(
			'rel'    => $rel,
			'newtab' => $tab,
		);
	}

	/**
	 * Apply the rules to some HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function apply( string $html ): string {
		if ( false === stripos( $html, '<a' ) ) {
			return $html;
		}
		if ( null === self::$rules ) {
			self::$rules = self::parse( (string) Settings::get( 'links_rules', '' ) );
		}
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$ext_rel = (string) Settings::get( 'links_external_rel', '' );
		$newtab  = (bool) Settings::get( 'links_external_newtab', false );
		$p       = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( 'a' ) ) {
			$href = $p->get_attribute( 'href' );
			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}
			$do = self::decide( $href, $host, self::$rules, $ext_rel, $newtab );
			if ( null === $do ) {
				continue;
			}
			$have = $p->get_attribute( 'rel' );
			$have = is_string( $have ) ? preg_split( '/\s+/', strtolower( trim( $have ) ) ) : array();
			$rel  = array_values( array_unique( array_filter( array_merge( (array) $have, $do['rel'] ) ) ) );
			if ( array() !== $rel ) {
				$p->set_attribute( 'rel', implode( ' ', $rel ) );
			}
			if ( $do['newtab'] && null === $p->get_attribute( 'target' ) ) {
				$p->set_attribute( 'target', '_blank' );
			}
		}

		return $p->get_updated_html();
	}

	/**
	 * `the_content` filter.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function filter( $content ): string {
		return self::apply( (string) $content );
	}
}
