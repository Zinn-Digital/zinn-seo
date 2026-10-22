<?php
/**
 * Internal link suggestions, both ways, and adding a link for the person (zs-100, zs-007).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Links;

use ZinnDigital\ZinnSEO\Analysis\Analysis;
use ZinnDigital\ZinnSEO\Analysis\Doc;
use ZinnDigital\ZinnSEO\Analysis\Text;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local and private: no text leaves the site (the semantic, AI-assisted kind is Pro, zs-101).
 *
 * OUTGOING (in the editor): which other pages this text should link to, and with which words.
 * Candidates are drawn from indexed pools — cornerstone pages, pages sharing a category or tag, and
 * pages whose focus phrase or title IS one of the text's phrases — never by scanning every post's
 * content. A candidate is suggested when one of its phrases (its focus phrases, else its
 * title) is IN this text: the suggestion carries that phrase as the anchor and the sentence it is
 * in, so the person (or "Add link") links exactly there. Cornerstone pages rank first.
 *
 * Free: the best 5 outgoing suggestions per post. Pro (`links-pro`): all of them, and INCOMING ones
 * — pages that mention this page's phrase and do not link to it yet — with "Add link".
 */
final class Suggest {

	/** Outgoing suggestions shown on a free site. */
	public const FREE = 5;

	/** Above this many published pages the title pool is left out (it reads every title once). */
	public const TITLE_POOL_MAX_POSTS = 200000;

	/**
	 * Outgoing suggestions for a text.
	 *
	 * @param \WP_Post           $post    The post being written.
	 * @param Doc                $doc     Its rendered text.
	 * @param array<int, string> $linked Addresses already linked from it.
	 * @return array<int, array<string, mixed>>
	 */
	public static function outgoing( \WP_Post $post, Doc $doc, array $linked = array() ): array {
		$text = implode( "\n", $doc->paragraphs() );
		if ( '' === trim( $text ) ) {
			return array();
		}
		$pool = self::pool( $post, $text );
		unset( $pool[ (int) $post->ID ] );
		if ( array() === $pool ) {
			return array();
		}
		$already = array();
		foreach ( $linked as $href ) {
			$id = Index::resolve( (string) $href );
			if ( $id > 0 ) {
				$already[ $id ] = true;
			}
		}
		_prime_post_caches( array_keys( $pool ), false, true );
		$sentences = array();
		foreach ( $doc->paragraphs() as $p ) {
			foreach ( Text::sentences( $p ) as $s ) {
				$sentences[] = $s;
			}
		}
		$out = array();
		foreach ( $pool as $id => $weight ) {
			if ( isset( $already[ $id ] ) ) {
				continue;
			}
			$target = get_post( $id );
			if ( ! $target instanceof \WP_Post || 'publish' !== $target->post_status ) {
				continue;
			}
			$corner = (bool) Meta::get_post( $id, 'cornerstone' );
			// Its focus phrases (word forms accepted) and its title (only exactly as written).
			$anchors = array();
			foreach ( Analysis::phrases( (string) Meta::get_post( $id, 'focus' ), true ) as $f ) {
				$anchors[ (string) $f['phrase'] ] = true;
			}
			$anchors[ (string) $target->post_title ] = $anchors[ (string) $target->post_title ] ?? false;
			// The longest of its phrases found in the text: "bicycle maintenance basics" over
			// "bicycle maintenance" when both are there.
			$best = null;
			foreach ( $anchors as $anchor => $forms ) {
				$anchor = trim( (string) $anchor );
				if ( '' === $anchor || count( Text::words( $anchor ) ) > 8 || ( null !== $best && strlen( $anchor ) <= strlen( $best['phrase'] ) ) ) {
					continue;
				}
				foreach ( $sentences as $s ) {
					$exact = Text::contains( $s, $anchor, false );
					if ( $exact || ( $forms && Text::contains( $s, $anchor ) ) ) {
						$best = array(
							'phrase'   => $anchor,
							'anchor'   => self::anchor_in( $s, $anchor ),
							'sentence' => $s,
							'exact'    => $exact,
						);
						break;
					}
				}
			}
			if ( null === $best ) {
				continue;
			}
			$score = $weight + ( $best['exact'] ? 3 : 1 ) + ( $corner ? 3 : 0 );
			$out[] = array(
				'id'          => $id,
				'title'       => $target->post_title,
				'url'         => (string) get_permalink( $target ),
				'type'        => $target->post_type,
				'cornerstone' => $corner,
				'anchor'      => $best['anchor'],
				'sentence'    => $best['sentence'],
				'score'       => round( $score, 2 ),
			);
		}
		usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return $out;
	}

	/**
	 * The text's own words for an anchor phrase (as written, for an exact link).
	 *
	 * @param string $sentence Sentence.
	 * @param string $anchor   Phrase.
	 * @return string
	 */
	public static function anchor_in( string $sentence, string $anchor ): string {
		$pattern = '/(?<![\p{L}\p{N}])' . str_replace( '\ ', '\s+', preg_quote( Doc::squash( $anchor ), '/' ) ) . '(?![\p{L}\p{N}])/iu';
		if ( preg_match( $pattern, $sentence, $m ) ) {
			return $m[0];
		}

		return $anchor;
	}

	/**
	 * Candidate pages and a weight each.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $text Its text.
	 * @return array<int, float>
	 */
	public static function pool( \WP_Post $post, string $text ): array {
		global $wpdb;
		$types = Analysis::types();
		$pool  = array();
		// Cornerstone pages (indexed meta lookup).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed meta_key lookup, editor only.
		foreach ( (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zseo_cornerstone' AND meta_value = '1' ORDER BY post_id DESC LIMIT 300" ) as $id ) {
			$pool[ (int) $id ] = ( $pool[ (int) $id ] ?? 0 ) + 1.0;
		}
		// Pages sharing a category, tag or other public term (rarer terms weigh more).
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$weight = 1 / log( 2 + max( 1, (int) $term->count ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed term_relationships lookup, editor only.
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d ORDER BY object_id DESC LIMIT 300", (int) $term->term_taxonomy_id ) ) as $id ) {
					$pool[ (int) $id ] = ( $pool[ (int) $id ] ?? 0 ) + $weight;
				}
			}
		}
		// Pages whose focus phrase or title IS a phrase of this text (n-grams of 1-6 words): the
		// focus phrases through their indexed meta key, titles by one IN() per 500 phrases.
		$grams = self::grams( $text, 3000 );
		// ⛔ zs-044 (FIN1): by the phrases' own meta KEYS (indexed) once they exist; the old
		// `meta_value IN (…)` read every stored focus phrase of the site for each chunk.
		$ready = \ZinnDigital\ZinnSEO\Analysis\Analysis::lookup_ready();
		foreach ( array_chunk( $grams, 500 ) as $chunk ) {
			$in  = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = $ready
				? "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) LIMIT 300"
				: "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zseo_fk' AND meta_value IN ({$in}) LIMIT 300";
			$arg = $ready ? array_map( array( \ZinnDigital\ZinnSEO\Analysis\Analysis::class, 'lookup_key' ), $chunk ) : $chunk;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; indexed meta_key, editor only.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( $sql, $arg ) ) as $id ) {
				$pool[ (int) $id ] = ( $pool[ (int) $id ] ?? 0 ) + 2.0;
			}
		}
		$published = (int) ( wp_count_posts( $post->post_type )->publish ?? 0 );
		if ( $published <= self::TITLE_POOL_MAX_POSTS ) {
			$tin = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			foreach ( array_chunk( array_values( array_filter( $grams, static fn( $g ) => false !== strpos( $g, ' ' ) ) ), 500 ) as $chunk ) {
				$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above; editor only, bounded.
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$tin}) AND post_title IN ({$in}) LIMIT 300", array_merge( $types, $chunk ) ) ) as $id ) {
					$pool[ (int) $id ] = ( $pool[ (int) $id ] ?? 0 ) + 1.5;
				}
			}
		}

		return $pool;
	}

	/**
	 * The distinct phrases of 1 to 6 words of a text, normalised (for the focus-phrase and title
	 * lookups); single words only when long enough to mean something.
	 *
	 * @param string $text Text.
	 * @param int    $max  At most this many.
	 * @return array<int, string>
	 */
	public static function grams( string $text, int $max ): array {
		if ( Text::unspaced( $text ) ) {
			return array();
		}
		$out = array();
		foreach ( Text::sentences( $text ) as $sentence ) {
			$w = Text::words( Text::norm( $sentence ), false );
			$c = count( $w );
			for ( $i = 0; $i < $c; $i++ ) {
				for ( $n = 1; $n <= 6 && $i + $n <= $c; $n++ ) {
					$g = implode( ' ', array_slice( $w, $i, $n ) );
					if ( 1 === $n && ( function_exists( 'mb_strlen' ) ? mb_strlen( $g ) : strlen( $g ) ) < 5 ) {
						continue;
					}
					$out[ $g ] = true;
					if ( count( $out ) >= $max ) {
						return self::keys( $out );
					}
				}
			}
		}

		return self::keys( $out );
	}

	/**
	 * The keys of a text-keyed array, as text.
	 *
	 * ⛔ PHP stores a key that reads as a decimal integer ("10000", "2024") as an INT, and
	 * `array_keys()` hands it back as one: under strict_types the first `strpos()` on it is a
	 * TypeError that killed a whole link-plan build on the first page with a 5-digit number in it
	 * (ZH5-1, zinnhub, Q313).
	 *
	 * @param array<int|string, mixed> $map Map keyed by text.
	 * @return array<int, string>
	 */
	public static function keys( array $map ): array {
		return array_map( 'strval', array_keys( $map ) );
	}

	/**
	 * The first sentence of some markup that contains a phrase outside links and headings.
	 *
	 * @param string $markup Content.
	 * @param string $phrase Phrase.
	 * @return string
	 */
	public static function sentence_with( string $markup, string $phrase ): string {
		foreach ( self::text_segments( $markup ) as $segment ) {
			foreach ( Text::sentences( html_entity_decode( $segment, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $s ) {
				if ( Text::contains( $s, $phrase, false ) ) {
					return $s;
				}
			}
		}

		return '';
	}

	/**
	 * Text runs of markup where a link may go (outside links, headings, code, scripts, buttons).
	 *
	 * @param string $markup Markup.
	 * @return array<int, string>
	 */
	public static function text_segments( string $markup ): array {
		$out = array();
		foreach ( self::walk( $markup ) as $part ) {
			if ( 'text' === $part[0] && $part[2] ) {
				$out[] = $part[1];
			}
		}

		return $out;
	}

	/**
	 * Split markup into tags and text, marking text where a link may go.
	 *
	 * @param string $markup Markup.
	 * @return array<int, array{0: string, 1: string, 2: bool}> [kind tag|text, the piece, linkable].
	 */
	public static function walk( string $markup ): array {
		$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/s', $markup, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$out   = array();
		$block = 0;
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<(/?)(a|h[1-6]|code|pre|script|style|button|textarea|select|figcaption|kbd|title)\b#i', $part, $m ) ) {
					$block = max( 0, $block + ( '/' === $m[1] ? -1 : 1 ) );
				}
				$out[] = array( 'tag', $part, false );
				continue;
			}
			$out[] = array( 'text', $part, 0 === $block );
		}

		return $out;
	}

	/**
	 * Link the first linkable occurrence of a phrase in some markup.
	 *
	 * @param string $markup Markup (post content, block markup included).
	 * @param string $phrase The words to link, as written.
	 * @param string $url    Destination.
	 * @return string|null The new markup, or null when no linkable occurrence exists.
	 */
	public static function link_first( string $markup, string $phrase, string $url ): ?string {
		$pattern = '/(?<![\p{L}\p{N}])(' . str_replace( '\ ', '\s+', preg_quote( Doc::squash( $phrase ), '/' ) ) . ')(?![\p{L}\p{N}])/iu';
		$parts   = self::walk( $markup );
		foreach ( $parts as $i => $part ) {
			if ( 'text' !== $part[0] || ! $part[2] || ! preg_match( $pattern, $part[1] ) ) {
				continue;
			}
			$parts[ $i ][1] = (string) preg_replace( $pattern, '<a href="' . esc_url( $url ) . '">$1</a>', $part[1], 1 );

			return implode( '', array_map( static fn( $p ) => $p[1], $parts ) );
		}

		return null;
	}

	/**
	 * Limit outgoing suggestions to the free number (Pro shows them all).
	 *
	 * @param array<int, array<string, mixed>> $found Suggestions.
	 * @return array{items: array<int, array<string, mixed>>, more: int}
	 */
	public static function limit( array $found ): array {
		/**
		 * Filters how many outgoing link suggestions are shown (0: all; Pro lifts the limit).
		 *
		 * @param int $limit Suggestions.
		 */
		$limit = max( 0, (int) apply_filters( 'zinn_seo_link_suggestion_limit', self::FREE ) );
		if ( 0 === $limit ) {
			return array(
				'items' => $found,
				'more'  => 0,
			);
		}

		return array(
			'items' => array_slice( $found, 0, $limit ),
			'more'  => max( 0, count( $found ) - $limit ),
		);
	}
}
