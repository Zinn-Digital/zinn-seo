<?php
/**
 * What Zinn® SEO tells our other plugins — Tranzly, Page Builder Sandwich, Zinn® Cache and
 * Zinn® Chat — so the five work as one (zs-130, zs-131, zs-134 … zs-137, zs-230, zs-231).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations\Family;

use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract, in one place. Each of the other plugins asks through a global function
 * (`includes/functions.php`) or listens to a documented action, and never reads this plugin's
 * storage by itself:
 *
 * - {@see translation_fields()}: which SEO fields of a post or term hold words (so Tranzly sends
 *   them to its engine WITH the post) and which settings a new translation takes as they are.
 * - {@see site_texts()}: the site-wide words — title and description patterns, the llms.txt
 *   summary, the description of the organisation — that Tranzly keeps per language; they come
 *   back through the `zinn_seo_template` and `zinn_seo_site_text` filters.
 * - {@see site_facts()}: who runs the site, how to reach them, where they are, when they are open
 *   and which pages matter, as plain text for an assistant to answer from (Zinn® Chat).
 *
 * Nothing here runs on a visitor's page unless one of those plugins asks.
 */
final class Family {

	/** Fields with words that are not templates: name => `line` or `text` (several lines). */
	private const WORDS = array(
		'crumb'     => 'line',
		'focus'     => 'text',
		'related'   => 'line',
		'takeaways' => 'text',
	);

	/** Per-page settings a new translation takes from its original unchanged. */
	private const COPY = array( 'robots', 'og_image', 'x_image', 'schema_page', 'schema_article', 'cornerstone', 'nopin', 'max_snippet', 'max_image', 'max_video' );

	/** Parts of a title and description pattern that hold words. */
	private const TEMPLATE_PARTS = array( 'title', 'desc', 'og_title', 'og_desc' );

	/** Site-wide settings that hold words. */
	private const SITE_TEXT = array( 'llms_summary', 'llms_details', 'identity_description', 'og_default_image_alt' );

	/** Longest text handed to an assistant, in characters. */
	public const FACTS_MAX = 2400;

	/**
	 * Has the site switched one of the family integrations off (Integrations screen)?
	 *
	 * @param string $id `tranzly`, `page-builder-sandwich`, `zinn-cache` or `zinn-chat`.
	 * @return bool
	 */
	public static function off( string $id ): bool {
		return in_array( $id, array_map( 'strval', (array) Settings::get( 'integrations_off', array() ) ), true );
	}

	/**
	 * The SEO fields of a kind of object a translation plugin works with.
	 *
	 * @param string $kind `post` or `term`.
	 * @return array{translate: array<string, string>, copy: array<int, string>} `translate`: field
	 *         name => `template` (may hold `%variables%`, which are never translated), `line` or
	 *         `text` (several lines). `copy`: field names. The stored key of a field is `_zseo_<name>`.
	 */
	public static function translation_fields( string $kind ): array {
		$out = array(
			'translate' => array(),
			'copy'      => array(),
		);
		if ( ! in_array( $kind, array( 'post', 'term' ), true ) || self::off( 'tranzly' ) ) {
			return $out;
		}
		foreach ( Meta::fields() as $name => $def ) {
			if ( ! in_array( $kind, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
				continue;
			}
			$name = (string) $name;
			if ( 'template' === ( $def['type'] ?? '' ) ) {
				$out['translate'][ $name ] = 'template';
			} elseif ( isset( self::WORDS[ $name ] ) ) {
				$out['translate'][ $name ] = self::WORDS[ $name ];
			} elseif ( 'post' === $kind && in_array( $name, self::COPY, true ) ) {
				$out['copy'][] = $name;
			}
		}

		/**
		 * Filters the SEO fields a translation plugin translates with a post or term, and the
		 * ones a new translation takes unchanged.
		 *
		 * @param array{translate: array<string, string>, copy: array<int, string>} $out  Fields.
		 * @param string                                                            $kind `post` or `term`.
		 */
		return (array) apply_filters( 'zinn_seo_translation_fields', $out, $kind );
	}

	/**
	 * The site-wide texts that should exist in every language, each once.
	 *
	 * @return array<int, string> Texts; one that holds `%variables%` must keep them.
	 */
	public static function site_texts(): array {
		if ( self::off( 'tranzly' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) Settings::get( 'templates', array() ) as $record ) {
			foreach ( self::TEMPLATE_PARTS as $part ) {
				$text = is_array( $record ) && is_string( $record[ $part ] ?? null ) ? trim( $record[ $part ] ) : '';
				if ( self::has_words( $text ) ) {
					$out[ $text ] = true;
				}
			}
		}
		foreach ( self::SITE_TEXT as $key ) {
			$text = trim( (string) Settings::get( $key, '' ) );
			if ( self::has_words( $text ) ) {
				$out[ $text ] = true;
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Does a text hold words besides its `%variables%`?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_words( string $text ): bool {
		return 1 === preg_match( '/\p{L}/u', (string) preg_replace( '/%[a-z][a-z0-9_-]{0,63}(?:\([^)%]{1,80}\))?%/i', ' ', $text ) );
	}

	/**
	 * A site-wide text in a language: what a translation plugin answers on `zinn_seo_site_text`,
	 * else the text as it was written.
	 *
	 * @param string $text The text as written (the site's main language).
	 * @param string $key  Which one: a setting's key (`llms_summary`, `identity_description`…).
	 * @param string $lang The language asked for; '' for the language being viewed.
	 * @return string
	 */
	public static function site_text( string $text, string $key, string $lang = '' ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		/**
		 * Filters a site-wide text for a language. A translation plugin's integration answers
		 * with its translation.
		 *
		 * @param string $text The text as written.
		 * @param string $key  The setting it comes from.
		 * @param string $lang The language asked for; '' for the language being viewed.
		 */
		$given = apply_filters( 'zinn_seo_site_text', $text, $key, $lang );

		return is_string( $given ) && '' !== trim( $given ) ? $given : $text;
	}

	/**
	 * Who runs the site and how to reach them, and the pages that matter, as short plain texts an
	 * assistant can answer from. Built from the knowledge graph and llms.txt: the same facts
	 * search engines and AI assistants are given, so the chat on the site never says otherwise.
	 *
	 * @param string $lang A language of the site ('' for its main language).
	 * @return array<int, array{title: string, url: string, text: string}>
	 */
	public static function site_facts( string $lang = '' ): array {
		if ( self::off( 'zinn-chat' ) ) {
			return array();
		}
		$out   = array();
		$about = self::about_lines();
		if ( count( $about ) > 1 ) {
			$out[] = array(
				/* translators: %s: the site's or the organisation's name. */
				'title' => sprintf( __( 'About %s', 'zinn-seo' ), (string) reset( $about ) ),
				'url'   => home_url( '/' ),
				'text'  => self::clip( implode( "\n", array_slice( $about, 1 ) ) ),
			);
		}
		$llms = '\ZinnDigital\ZinnSEO\Llms\Llms';
		if ( zinn_seo()->module_enabled( 'llms' ) && is_callable( array( $llms, 'text' ) ) ) {
			$languages = (array) call_user_func( array( $llms, 'languages' ) );
			$code      = isset( $languages[ $lang ] ) && call_user_func( array( $llms, 'default_language' ) ) !== $lang ? $lang : '';
			$pages     = self::page_lines( (string) call_user_func( array( $llms, 'text' ), $code, false ) );
			if ( '' !== $pages ) {
				$out[] = array(
					'title' => __( 'The main pages of this site', 'zinn-seo' ),
					// The address an assistant may give a visitor: the home page in that language.
					'url'   => trailingslashit( (string) preg_replace( '#/llms\.txt(\?.*)?$#', '', (string) call_user_func( array( $llms, 'url' ), $code, false ) ) ),
					'text'  => self::clip( $pages ),
				);
			}
		}

		/**
		 * Filters the facts about the site handed to an assistant (Zinn® Chat).
		 *
		 * @param array<int, array{title: string, url: string, text: string}> $out  Facts.
		 * @param string                                                      $lang Language.
		 */
		return array_values( array_filter( (array) apply_filters( 'zinn_seo_site_facts', $out, $lang ), 'is_array' ) );
	}

	/**
	 * The identity of the site as lines of text: its name first, then one fact per line.
	 *
	 * @return array<int, string>
	 */
	public static function about_lines(): array {
		$schema = '\ZinnDigital\ZinnSEO\Schema\Schema';
		if ( ! zinn_seo()->module_enabled( 'schema' ) || ! is_callable( array( $schema, 'identity' ) ) ) {
			return array();
		}
		$node  = (array) call_user_func( array( $schema, 'identity' ) );
		$local = '\ZinnDigital\ZinnSEO\Integrations\Local\Local';
		if ( class_exists( $local ) && is_callable( array( $local, 'site' ) ) && ! in_array( 'Person', (array) ( $node['@type'] ?? array() ), true ) ) {
			$v = (array) call_user_func( array( $local, 'site' ) );
			if ( '' === (string) ( $v['name'] ?? '' ) ) {
				$v['name'] = (string) ( $node['name'] ?? '' );
			}
			$business = (array) call_user_func( array( $local, 'business' ), $v );
			if ( count( $business ) >= 3 ) {
				$node = array_merge( $node, $business );
			}
		}

		return self::lines_of( $node );
	}

	/**
	 * A schema.org Organization, Person or LocalBusiness node as lines of text. Pure.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<int, string> The name, then `Label: value` lines.
	 */
	public static function lines_of( array $node ): array {
		$name = self::plain( $node['name'] ?? '' );
		if ( '' === $name ) {
			return array();
		}
		$out  = array( $name );
		$add  = static function ( string $label, $value ) use ( &$out ): void {
			$value = self::plain( $value );
			if ( '' !== $value ) {
				$out[] = $label . ': ' . $value;
			}
		};
		$type = implode( ', ', array_diff( array_map( 'strval', (array) ( $node['@type'] ?? array() ) ), array( 'Organization', 'Person' ) ) );
		$add( 'Kind of business', $type );
		$add( 'Also known as', $node['alternateName'] ?? '' );
		$add( 'What it is', $node['description'] ?? '' );
		$add( 'Founded', $node['foundingDate'] ?? '' );
		$add( 'Phone', $node['telephone'] ?? '' );
		$add( 'E-mail', $node['email'] ?? '' );
		$address = is_array( $node['address'] ?? null ) ? $node['address'] : array();
		$add(
			'Address',
			implode(
				', ',
				array_filter(
					array_map(
						array( self::class, 'plain' ),
						array( $address['streetAddress'] ?? '', $address['addressLocality'] ?? '', $address['addressRegion'] ?? '', $address['postalCode'] ?? '', $address['addressCountry'] ?? '' )
					)
				)
			)
		);
		$add( 'Area served', is_array( $node['areaServed'] ?? null ) ? ( $node['areaServed']['name'] ?? '' ) : ( $node['areaServed'] ?? '' ) );
		$add( 'Price range', $node['priceRange'] ?? '' );
		$hours = array();
		foreach ( self::list_of( $node['openingHoursSpecification'] ?? array() ) as $spec ) {
			$days = implode( ', ', array_map( static fn( $d ): string => (string) preg_replace( '#^https?://schema\.org/#', '', (string) $d ), (array) ( $spec['dayOfWeek'] ?? array() ) ) );
			if ( '' !== $days && isset( $spec['opens'], $spec['closes'] ) ) {
				$hours[] = $days . ' ' . self::plain( $spec['opens'] ) . '-' . self::plain( $spec['closes'] );
			}
		}
		$add( 'Opening hours', implode( '; ', $hours ) );
		foreach ( self::list_of( $node['contactPoint'] ?? array() ) as $point ) {
			$ways = array_filter( array_map( array( self::class, 'plain' ), array( $point['telephone'] ?? '', $point['email'] ?? '', $point['url'] ?? '' ) ) );
			if ( array() !== $ways ) {
				$add( ucfirst( self::plain( $point['contactType'] ?? 'contact' ) ), implode( ', ', $ways ) );
			}
		}
		$add( 'Profiles', implode( ', ', array_slice( array_map( 'strval', (array) ( $node['sameAs'] ?? array() ) ), 0, 12 ) ) );

		return $out;
	}

	/**
	 * The links of an llms.txt as `Title: address (what it is)` lines, by section. Pure.
	 *
	 * @param string $llms The file.
	 * @return string
	 */
	public static function page_lines( string $llms ): string {
		$out = array();
		foreach ( (array) preg_split( '/\R/', $llms ) as $line ) {
			$line = trim( (string) $line );
			if ( 1 === preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
				$out[] = $m[1] . ':';
			} elseif ( 1 === preg_match( '/^-\s+\[((?:\\\\.|[^\]\\\\])+)\]\(([^)\s]+)\)(?::\s*(.*))?$/', $line, $m ) ) {
				// A page a visitor can read, not a file for machines (another language's llms.txt,
				// the site graph).
				if ( 1 === preg_match( '#\.(txt|json|xml|md)$#i', (string) wp_parse_url( $m[2], PHP_URL_PATH ) ) ) {
					continue;
				}
				$out[] = '- ' . stripslashes( $m[1] ) . ': ' . $m[2] . ( isset( $m[3] ) && '' !== trim( $m[3] ) ? ' (' . trim( $m[3] ) . ')' : '' );
			}
		}
		// A heading with nothing under it says nothing.
		$kept = array();
		foreach ( $out as $i => $line ) {
			if ( ':' === substr( $line, -1 ) && '- ' !== substr( $line, 0, 2 ) && ( ! isset( $out[ $i + 1 ] ) || '- ' !== substr( $out[ $i + 1 ], 0, 2 ) ) ) {
				continue;
			}
			$kept[] = $line;
		}

		return implode( "\n", $kept );
	}

	/**
	 * A value as one line of plain text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function plain( $value ): string {
		return is_scalar( $value ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) ) ) : '';
	}

	/**
	 * One node or a list of nodes, as a list.
	 *
	 * @param mixed $value Value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function list_of( $value ): array {
		if ( ! is_array( $value ) || array() === $value ) {
			return array();
		}
		$list = array_keys( $value ) === range( 0, count( $value ) - 1 ) ? $value : array( $value );

		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * A text cut to what an assistant is given, at the end of a line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clip( string $text ): string {
		if ( mb_strlen( $text ) <= self::FACTS_MAX ) {
			return $text;
		}
		$cut  = mb_substr( $text, 0, self::FACTS_MAX );
		$last = mb_strrpos( $cut, "\n" );

		return false === $last || $last < self::FACTS_MAX / 2 ? $cut : mb_substr( $cut, 0, $last );
	}
}
