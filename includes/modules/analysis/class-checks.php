<?php
/**
 * The SEO and accessibility checks (zs-001, zs-004, zs-007, zs-010).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure: a rendered page (Doc) and what the editor knows about it (`$in`) in, results out.
 *
 * `$in`: `title` (the SEO title as shown), `desc` (the meta description as shown), `slug`,
 * `focus` (list of `{phrase, synonyms[]}`; the first is the main one), `related` (phrases),
 * `cornerstone` (bool), `host` (this site's host), `noindex` (bool), `inbound` (internal links
 * pointing here, from the link index; null when not indexed), `used_by` (phrase => other post
 * titles with the same focus phrase), `modified` (unix time), `author_bio` (bool),
 * `quick_answer` (the page has a Quick Answer block), `type` (post type), `locale`.
 */
final class Checks {

	/** Words a page needs before it is "long enough" (pages, posts, cornerstone). */
	public const MIN_WORDS = array(
		'default'     => 300,
		'page'        => 200,
		'product'     => 200,
		'cornerstone' => 900,
	);

	/**
	 * Every SEO check.
	 *
	 * @param Doc                  $doc Page.
	 * @param array<string, mixed> $in  See the class.
	 * @return array<int, array<string, mixed>>
	 */
	public static function seo( Doc $doc, array $in ): array {
		$out      = array();
		$text     = implode( "\n", $doc->paragraphs() );
		$unspaced = Text::unspaced( $text . ' ' . (string) ( $in['title'] ?? '' ) );
		$words    = Text::length( $text, $unspaced );
		$focus    = array_values( array_filter( (array) ( $in['focus'] ?? array() ), static fn( $f ) => is_array( $f ) && '' !== trim( (string) ( $f['phrase'] ?? '' ) ) ) );

		if ( ! empty( $in['noindex'] ) ) {
			$out[] = Result::make( 'noindex', 'bad', __( 'This page is hidden from search engines (noindex), so it cannot rank. Untick "Hide from search results" if that is not what you want.', 'zinn-seo' ), 5, 'robots' );
		}
		if ( array() === $focus ) {
			$out[] = Result::make( 'focus_set', 'ok', __( 'Focus phrase: add the phrase people would search to find this page, and every check below measures against it.', 'zinn-seo' ), 0, 'focus' );
		}
		foreach ( $focus as $i => $f ) {
			foreach ( self::phrase_checks( $doc, $in, $f, 0 === $i, $text, $words, $unspaced ) as $c ) {
				$out[] = $c;
			}
		}
		$out[] = self::title_length( (string) ( $in['title'] ?? '' ) );
		$out[] = self::desc_length( (string) ( $in['desc'] ?? '' ) );
		$out[] = self::text_length( $words, $unspaced, $in );
		foreach ( self::link_checks( $doc, $in ) as $c ) {
			$out[] = $c;
		}
		$out[] = array() === $doc->images
			? Result::make( 'images', 'ok', __( 'Images: there are none. An image (with a good alt text) helps readers and image search.', 'zinn-seo' ), 1, 'content' )
			: Result::make( 'images', 'good', __( 'Images: the page has images. Good.', 'zinn-seo' ), 1, 'content' );
		foreach ( (array) ( $in['related'] ?? array() ) as $phrase ) {
			$phrase = trim( (string) $phrase );
			if ( '' === $phrase ) {
				continue;
			}
			$out[] = Text::contains( $text, $phrase )
				/* translators: %s: a related phrase. */
				? Result::make( 'related', 'good', sprintf( __( 'Related phrase "%s" is in the text. Good.', 'zinn-seo' ), $phrase ), 1, 'content' )
				/* translators: %s: a related phrase. */
				: Result::make( 'related', 'ok', sprintf( __( 'Related phrase "%s" is not in the text yet.', 'zinn-seo' ), $phrase ), 1, 'content' );
		}
		if ( ! empty( $in['cornerstone'] ) ) {
			$out[] = self::cornerstone_links( $in );
		}

		return $out;
	}

	/**
	 * The checks of one focus phrase (the main one counts in full, others half).
	 *
	 * @param Doc                  $doc      Page.
	 * @param array<string, mixed> $in       Input.
	 * @param array<string, mixed> $f        `{phrase, synonyms}`.
	 * @param bool                 $main     The main phrase.
	 * @param string               $text     The prose.
	 * @param int                  $words    Its length.
	 * @param bool                 $unspaced Measured in characters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function phrase_checks( Doc $doc, array $in, array $f, bool $main, string $text, int $words, bool $unspaced ): array {
		$phrase = trim( (string) $f['phrase'] );
		$all    = array_values( array_filter( array_merge( array( $phrase ), array_map( 'trim', array_map( 'strval', (array) ( $f['synonyms'] ?? array() ) ) ) ) ) );
		$w      = $main ? 2 : 1;
		$id     = $main ? '' : '_extra';
		$has    = static function ( string $haystack ) use ( $all ): bool {
			foreach ( $all as $p ) {
				if ( Text::contains( $haystack, $p ) ) {
					return true;
				}
			}
			return false;
		};
		$out    = array();
		$label  = $main ? __( 'Focus phrase', 'zinn-seo' ) : $phrase;

		// In the SEO title, ideally near the start.
		$title = (string) ( $in['title'] ?? '' );
		if ( $has( $title ) ) {
			$start = Text::contains( implode( ' ', array_slice( Text::words( Text::norm( $title ), $unspaced ), 0, $unspaced ? 12 : 4 + count( Text::words( $phrase, $unspaced ) ) ) ), $phrase );
			/* translators: %s: "Focus phrase" or the phrase itself. */
			$out[] = Result::make( 'phrase_title' . $id, $start ? 'good' : 'ok', sprintf( $start ? __( '%s in the SEO title, near the start. Good.', 'zinn-seo' ) : __( '%s is in the SEO title; moving it to the start helps.', 'zinn-seo' ), $label ), 3 * $w, 'title' );
		} else {
			/* translators: %s: "Focus phrase" or the phrase itself. */
			$out[] = Result::make( 'phrase_title' . $id, 'bad', sprintf( __( '%s is not in the SEO title. Put it there, close to the start.', 'zinn-seo' ), $label ), 3 * $w, 'title' );
		}
		// In the meta description.
		/* translators: %s: "Focus phrase" or the phrase itself. */
		$out[] = $has( (string) ( $in['desc'] ?? '' ) ) ? Result::make( 'phrase_desc' . $id, 'good', sprintf( __( '%s in the meta description. Good.', 'zinn-seo' ), $label ), 2 * $w, 'desc' )
			/* translators: %s: "Focus phrase" or the phrase itself. */
			: Result::make( 'phrase_desc' . $id, 'bad', sprintf( __( '%s is not in the meta description.', 'zinn-seo' ), $label ), 2 * $w, 'desc' );
		// In the address.
		if ( $main ) {
			$slug  = Text::slug_text( (string) ( $in['slug'] ?? '' ) );
			$out[] = '' !== $slug && $has( $slug ) ? Result::make( 'phrase_slug', 'good', __( 'Focus phrase in the page address. Good.', 'zinn-seo' ), 2, 'slug' )
				: Result::make( 'phrase_slug', 'ok', __( 'The page address (slug) does not contain the focus phrase. Change it only before the page is published or linked.', 'zinn-seo' ), 2, 'slug' );
		}
		// In the introduction.
		/* translators: %s: "Focus phrase" or the phrase itself. */
		$out[] = $has( $doc->intro() ) ? Result::make( 'phrase_intro' . $id, 'good', sprintf( __( '%s in the first paragraph. Good.', 'zinn-seo' ), $label ), 2 * $w, 'content' )
			/* translators: %s: "Focus phrase" or the phrase itself. */
			: Result::make( 'phrase_intro' . $id, 'bad', sprintf( __( '%s is not in the first paragraph. Say what the page is about straight away.', 'zinn-seo' ), $label ), 2 * $w, 'content' );
		// In subheadings (not every one: that reads as stuffing).
		$subs = array_values( array_filter( $doc->headings, static fn( $h ) => (int) $h['level'] >= 2 ) );
		if ( array() !== $subs ) {
			$with = count( array_filter( $subs, static fn( $h ) => $has( (string) $h['text'] ) ) );
			if ( 0 === $with ) {
				/* translators: %s: "Focus phrase" or the phrase itself. */
				$out[] = Result::make( 'phrase_headings' . $id, 'ok', sprintf( __( '%s is in none of the subheadings; use it (or a synonym) in one or two.', 'zinn-seo' ), $label ), $w, 'content' );
			} elseif ( count( $subs ) >= 4 && $with / count( $subs ) > 0.75 ) {
				/* translators: %s: "Focus phrase" or the phrase itself. */
				$out[] = Result::make( 'phrase_headings' . $id, 'ok', sprintf( __( '%s is in almost every subheading; vary them so they read naturally.', 'zinn-seo' ), $label ), $w, 'content' );
			} else {
				/* translators: %s: "Focus phrase" or the phrase itself. */
				$out[] = Result::make( 'phrase_headings' . $id, 'good', sprintf( __( '%s in the subheadings. Good.', 'zinn-seo' ), $label ), $w, 'content' );
			}
		}
		// In an image's alt text.
		if ( $main && array() !== $doc->images ) {
			$alt   = implode( ' ', array_map( static fn( $i ) => (string) $i['alt'], $doc->images ) );
			$out[] = $has( $alt ) ? Result::make( 'phrase_alt', 'good', __( 'Focus phrase in an image\'s alt text. Good.', 'zinn-seo' ), 1, 'content' )
				: Result::make( 'phrase_alt', 'ok', __( 'No image\'s alt text mentions the focus phrase; describe one image with it if it fits.', 'zinn-seo' ), 1, 'content' );
		}
		// Density: present enough, never stuffed.
		if ( $words >= ( $unspaced ? 200 : 100 ) ) {
			$n = 0;
			foreach ( $all as $p ) {
				$n += Text::count( $text, $p );
			}
			$per   = $unspaced ? 1000 : 100;
			$rate  = $n * $per / max( 1, $words );
			$shown = round( $rate, 1 );
			if ( 0 === $n ) {
				/* translators: %s: "Focus phrase" or the phrase itself. */
				$out[] = Result::make( 'phrase_density' . $id, 'bad', sprintf( __( '%s does not appear in the text at all.', 'zinn-seo' ), $label ), 2 * $w, 'content' );
			} elseif ( $rate > ( $unspaced ? 30 : 3 ) ) {
				/* translators: 1: "Focus phrase" or the phrase itself, 2: times used, 3: rate. */
				$out[] = Result::make( 'phrase_density' . $id, 'bad', sprintf( $unspaced ? __( '%1$s is used %2$d times (%3$s per 1,000 characters): that reads as stuffing. Use it less.', 'zinn-seo' ) : __( '%1$s is used %2$d times (%3$s%% of words): that reads as stuffing. Use it less.', 'zinn-seo' ), $label, $n, (string) $shown ), 2 * $w, 'content' );
			} elseif ( $rate < ( $unspaced ? 3 : 0.3 ) && $words >= 300 ) {
				/* translators: 1: "Focus phrase" or the phrase itself, 2: times used. */
				$out[] = Result::make( 'phrase_density' . $id, 'ok', sprintf( _n( '%1$s is used %2$d time; a few more in this length of text would help.', '%1$s is used %2$d times; a few more in this length of text would help.', $n, 'zinn-seo' ), $label, $n ), 2 * $w, 'content' );
			} else {
				/* translators: 1: "Focus phrase" or the phrase itself, 2: times used. */
				$out[] = Result::make( 'phrase_density' . $id, 'good', sprintf( _n( '%1$s is used %2$d time. Good.', '%1$s is used %2$d times. Good.', $n, 'zinn-seo' ), $label, $n ), 2 * $w, 'content' );
			}
		}
		// Phrase length.
		if ( $main && ! $unspaced && count( Text::words( $phrase, false ) ) > 5 ) {
			$out[] = Result::make( 'phrase_length', 'ok', __( 'The focus phrase is long; people rarely search more than four or five words.', 'zinn-seo' ), 1, 'focus' );
		}
		// Used by another page (cannibalisation, zs-009).
		$used = (array) ( $in['used_by'][ Text::norm( $phrase ) ] ?? array() );
		if ( array() !== $used ) {
			/* translators: 1: "Focus phrase" or the phrase itself, 2: titles of other pages. */
			$out[] = Result::make( 'phrase_used' . $id, 'bad', sprintf( __( '%1$s is also the focus of: %2$s. Two pages chasing one search compete with each other; give each page its own phrase or merge them.', 'zinn-seo' ), $label, implode( ', ', array_slice( array_map( 'strval', $used ), 0, 3 ) ) ), 2 * $w, 'focus', array_map( 'strval', $used ) );
		}

		return $out;
	}

	/**
	 * SEO title length.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	public static function title_length( string $title ): array {
		$n = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		if ( 0 === $n ) {
			return Result::make( 'title_length', 'bad', __( 'The page has no SEO title.', 'zinn-seo' ), 2, 'title' );
		}
		if ( $n > 65 ) {
			/* translators: %d: characters. */
			return Result::make( 'title_length', 'ok', sprintf( __( 'SEO title: %d characters; search results cut titles at about 60. Shorten it, or put the important words first.', 'zinn-seo' ), $n ), 2, 'title' );
		}
		if ( $n < 30 ) {
			/* translators: %d: characters. */
			return Result::make( 'title_length', 'ok', sprintf( __( 'SEO title: %d characters; there is room to say more (up to about 60).', 'zinn-seo' ), $n ), 2, 'title' );
		}

		/* translators: %d: characters. */
		return Result::make( 'title_length', 'good', sprintf( __( 'SEO title length: %d characters. Good.', 'zinn-seo' ), $n ), 2, 'title' );
	}

	/**
	 * Meta description length.
	 *
	 * @param string $desc Description.
	 * @return array<string, mixed>
	 */
	public static function desc_length( string $desc ): array {
		$n = function_exists( 'mb_strlen' ) ? mb_strlen( $desc, 'UTF-8' ) : strlen( $desc );
		if ( 0 === $n ) {
			return Result::make( 'desc_length', 'bad', __( 'No meta description: search engines will pick text from the page. Write one that makes people want to click.', 'zinn-seo' ), 2, 'desc' );
		}
		if ( $n > 160 ) {
			/* translators: %d: characters. */
			return Result::make( 'desc_length', 'ok', sprintf( __( 'Meta description: %d characters; about 155 are shown.', 'zinn-seo' ), $n ), 2, 'desc' );
		}
		if ( $n < 110 ) {
			/* translators: %d: characters. */
			return Result::make( 'desc_length', 'ok', sprintf( __( 'Meta description: %d characters; use up to about 155 to say more.', 'zinn-seo' ), $n ), 2, 'desc' );
		}

		/* translators: %d: characters. */
		return Result::make( 'desc_length', 'good', sprintf( __( 'Meta description length: %d characters. Good.', 'zinn-seo' ), $n ), 2, 'desc' );
	}

	/**
	 * Text length.
	 *
	 * @param int                  $words    Words (or characters).
	 * @param bool                 $unspaced Characters.
	 * @param array<string, mixed> $in       Input.
	 * @return array<string, mixed>
	 */
	public static function text_length( int $words, bool $unspaced, array $in ): array {
		$type = ! empty( $in['cornerstone'] ) ? 'cornerstone' : (string) ( $in['type'] ?? 'default' );
		$min  = self::MIN_WORDS[ $type ] ?? self::MIN_WORDS['default'];
		$min  = $unspaced ? $min * 2 : $min;
		if ( $words >= $min ) {
			/* translators: 1: words or characters in the text. */
			$text = sprintf( $unspaced ? _n( 'Text length: %1$d character. Good.', 'Text length: %1$d characters. Good.', $words, 'zinn-seo' ) : _n( 'Text length: %1$d word. Good.', 'Text length: %1$d words. Good.', $words, 'zinn-seo' ), $words );
			return Result::make( 'text_length', 'good', $text, 3, 'content' );
		}
		if ( ! empty( $in['cornerstone'] ) ) {
			/* translators: 1: words in the text, 2: the minimum. */
			$text = sprintf( $unspaced ? __( 'Text length: %1$d characters. A cornerstone page should be thorough: at least %2$d.', 'zinn-seo' ) : __( 'Text length: %1$d words. A cornerstone page should be thorough: at least %2$d.', 'zinn-seo' ), $words, $min );
		} else {
			/* translators: 1: words in the text, 2: the minimum. */
			$text = sprintf( $unspaced ? __( 'Text length: %1$d characters; at least %2$d gives search engines enough to understand the page.', 'zinn-seo' ) : __( 'Text length: %1$d words; at least %2$d gives search engines enough to understand the page.', 'zinn-seo' ), $words, $min );
		}

		return Result::make( 'text_length', $words >= $min * 2 / 3 ? 'ok' : 'bad', $text, 3, 'content' );
	}

	/**
	 * Is a link to this site?
	 *
	 * @param string $href Link.
	 * @param string $host This site's host.
	 * @return bool|null True internal, false outbound, null not a page link (anchor, mailto…).
	 */
	public static function internal( string $href, string $host ): ?bool {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] || 1 === preg_match( '/^(mailto|tel|sms|javascript|data|ftp):/i', $href ) ) {
			return null;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $href ) : parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure code outside WordPress in tests.
		if ( ! is_array( $parts ) ) {
			return null;
		}
		if ( empty( $parts['host'] ) ) {
			return true;
		}

		return self::same_host( (string) $parts['host'], $host );
	}

	/**
	 * Same site (with or without `www.`).
	 *
	 * @param string $a Host.
	 * @param string $b Host.
	 * @return bool
	 */
	public static function same_host( string $a, string $b ): bool {
		$strip = static fn( $h ) => (string) preg_replace( '/^www\./', '', strtolower( $h ) );

		return '' !== $b && $strip( $a ) === $strip( $b );
	}

	/**
	 * Internal and outbound links.
	 *
	 * @param Doc                  $doc Page.
	 * @param array<string, mixed> $in  Input.
	 * @return array<int, array<string, mixed>>
	 */
	public static function link_checks( Doc $doc, array $in ): array {
		$host     = (string) ( $in['host'] ?? '' );
		$internal = 0;
		$outbound = 0;
		$followed = 0;
		foreach ( $doc->links as $l ) {
			$kind = self::internal( (string) $l['href'], $host );
			if ( true === $kind ) {
				++$internal;
			} elseif ( false === $kind ) {
				++$outbound;
				if ( ! array_intersect( array( 'nofollow', 'sponsored', 'ugc' ), (array) $l['rel'] ) ) {
					++$followed;
				}
			}
		}
		$need = ! empty( $in['cornerstone'] ) ? 3 : 1;
		$out  = array();
		if ( $internal >= $need ) {
			/* translators: %d: number of links. */
			$out[] = Result::make( 'internal_links', 'good', sprintf( _n( 'Internal links: %d to other pages of this site. Good.', 'Internal links: %d to other pages of this site. Good.', $internal, 'zinn-seo' ), $internal ), 2, 'content' );
		} else {
			/* translators: 1: links now, 2: links advised. */
			$out[] = Result::make( 'internal_links', 0 === $internal ? 'bad' : 'ok', sprintf( __( 'Internal links: %1$d; link to at least %2$d related page(s) of this site. The suggestions below help.', 'zinn-seo' ), $internal, $need ), 2, 'content' );
		}
		$out[] = $outbound > 0
			/* translators: %d: number of links. */
			? Result::make( 'outbound_links', 'good', sprintf( _n( 'Outbound links: %d to another site. Good.', 'Outbound links: %d to other sites. Good.', $outbound, 'zinn-seo' ), $outbound ), 1, 'content' )
			: Result::make( 'outbound_links', 'ok', __( 'Outbound links: none. Linking to a trustworthy source supports what you say.', 'zinn-seo' ), 1, 'content' );

		return $out;
	}

	/**
	 * Cornerstone: other pages must point here.
	 *
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function cornerstone_links( array $in ): array {
		if ( ! isset( $in['inbound'] ) || null === $in['inbound'] ) {
			return Result::make( 'cornerstone_inbound', 'na', __( 'Cornerstone: links to this page are counted once the link index is built (Links screen).', 'zinn-seo' ), 0 );
		}
		$n = (int) $in['inbound'];
		if ( $n >= 5 ) {
			/* translators: %d: number of pages. */
			return Result::make( 'cornerstone_inbound', 'good', sprintf( __( 'Cornerstone: %d pages of this site link here. Good.', 'zinn-seo' ), $n ), 3, 'links' );
		}

		/* translators: %d: number of pages. */
		return Result::make( 'cornerstone_inbound', $n >= 2 ? 'ok' : 'bad', sprintf( _n( 'Cornerstone: only %d page links here. Link to it from your related posts (the Links screen lists candidates).', 'Cornerstone: only %d pages link here. Link to it from your related posts (the Links screen lists candidates).', $n, 'zinn-seo' ), $n ), 3, 'links' );
	}

	/**
	 * Link texts that say nothing about where they go, in a language.
	 *
	 * @return array<int, string>
	 */
	public static function vague_link_texts(): array {
		/* translators: Link texts that say nothing about where a link goes, in YOUR language, lower case, separated by commas (as people write them on buttons and links). */
		$list = __( 'click here, here, read more, more, learn more, this, link, this link, continue, go, details, more info, see more, find out more', 'zinn-seo' );

		return array_values( array_filter( array_map( array( Text::class, 'norm' ), Text::split( '/[,،、，]+/u', $list ) ) ) );
	}

	/**
	 * Accessibility checks that are also SEO checks (zs-010).
	 *
	 * @param Doc $doc Page.
	 * @return array<int, array<string, mixed>>
	 */
	public static function a11y( Doc $doc ): array {
		$out = array();
		// Heading order: going down, never skip a level.
		$skips = array();
		$prev  = 1;
		$empty = 0;
		foreach ( $doc->headings as $h ) {
			$level = (int) $h['level'];
			if ( '' === trim( (string) $h['text'] ) ) {
				++$empty;
			}
			if ( $level > $prev + 1 ) {
				/* translators: 1: heading level jumped from, 2: heading level jumped to, 3: heading text. */
				$skips[] = sprintf( __( 'H%1$d to H%2$d at "%3$s"', 'zinn-seo' ), $prev, $level, (string) $h['text'] );
			}
			$prev = $level;
		}
		if ( array() === $doc->headings ) {
			$out[] = Result::make( 'heading_order', 'na', __( 'Heading order: the text has no headings.', 'zinn-seo' ), 0 );
		} elseif ( array() === $skips ) {
			$out[] = Result::make( 'heading_order', 'good', __( 'Heading order: no level is skipped. Good.', 'zinn-seo' ), 2, 'content' );
		} else {
			/* translators: %s: where heading levels are skipped. */
			$out[] = Result::make( 'heading_order', 'bad', sprintf( __( 'Heading order skips a level (%s). Screen readers and search engines read headings as an outline; use the next level down.', 'zinn-seo' ), implode( '; ', array_slice( $skips, 0, 3 ) ) ), 2, 'content', $skips );
		}
		$h1 = count( array_filter( $doc->headings, static fn( $h ) => 1 === (int) $h['level'] ) );
		if ( $h1 > 0 ) {
			/* translators: %d: number of top-level headings. */
			$out[] = Result::make( 'content_h1', 'ok', sprintf( _n( 'The text has %d top-level heading (H1). The page title is normally the only H1; use H2 for sections.', 'The text has %d top-level headings (H1). The page title is normally the only H1; use H2 for sections.', $h1, 'zinn-seo' ), $h1 ), 1, 'content' );
		}
		if ( $empty > 0 ) {
			/* translators: %d: number of headings. */
			$out[] = Result::make( 'empty_headings', 'bad', sprintf( _n( '%d heading is empty.', '%d headings are empty.', $empty, 'zinn-seo' ), $empty ), 1, 'content' );
		}
		// Links: no name at all, or a name that says nothing.
		$vague    = array_flip( self::vague_link_texts() );
		$nameless = 0;
		$says     = array();
		foreach ( $doc->links as $l ) {
			$name = trim( (string) $l['text'] );
			if ( '' === $name && '' === trim( (string) $l['label'] ) && '' === trim( (string) $l['image_alt'] ) ) {
				++$nameless;
				continue;
			}
			if ( '' === trim( (string) $l['label'] ) && isset( $vague[ Text::norm( $name ) ] ) ) {
				$says[] = $name;
			}
		}
		if ( $nameless > 0 ) {
			/* translators: %d: number of links. */
			$out[] = Result::make( 'empty_links', 'bad', sprintf( _n( '%d link has no text a screen reader or search engine can read (an icon or an image without alt text). Give it one.', '%d links have no text a screen reader or search engine can read (icons, or images without alt text). Give them one.', $nameless, 'zinn-seo' ), $nameless ), 2, 'content' );
		}
		if ( array() !== $says ) {
			/* translators: %s: link texts. */
			$out[] = Result::make( 'vague_links', 'ok', sprintf( __( 'Some link texts say nothing about where they go ("%s"). Describe the destination instead.', 'zinn-seo' ), implode( '", "', array_slice( array_unique( $says ), 0, 4 ) ) ), 1, 'content', array_unique( $says ) );
		} elseif ( array() !== $doc->links && 0 === $nameless ) {
			$out[] = Result::make( 'link_names', 'good', __( 'Links: every link says where it goes. Good.', 'zinn-seo' ), 1, 'content' );
		}
		// Images: alt text present and meaningful.
		$missing   = array();
		$filename  = array();
		$long      = 0;
		$redundant = array();
		foreach ( $doc->images as $img ) {
			$alt = $img['alt'];
			$src = (string) $img['src'];
			if ( null === $alt ) {
				$missing[] = basename( (string) strtok( $src, '?' ) );
				continue;
			}
			if ( '' === $alt ) {
				continue; // A deliberate decorative image.
			}
			if ( 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', $alt ) || 1 === preg_match( '/^(img|dsc|dscn|image|photo|screenshot|pxl|wp)[\s_-]?\d+/i', $alt ) ) {
				$filename[] = $alt;
			} elseif ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $alt ) : strlen( $alt ) ) > 150 ) {
				++$long;
			} elseif ( 1 === preg_match( '/^(an?\s+)?(image|picture|photo|photograph|graphic) of\b/i', $alt ) ) {
				$redundant[] = $alt;
			}
		}
		if ( array() !== $doc->images ) {
			if ( array() !== $missing ) {
				/* translators: %d: number of images. */
				$out[] = Result::make( 'alt_missing', 'bad', sprintf( _n( '%d image has no alt text. Describe what it shows (or mark it decorative with an empty alt).', '%d images have no alt text. Describe what they show (or mark them decorative with an empty alt).', count( $missing ), 'zinn-seo' ), count( $missing ) ), 3, 'content', $missing );
			} else {
				$out[] = Result::make( 'alt_missing', 'good', __( 'Every image has alt text. Good.', 'zinn-seo' ), 3, 'content' );
			}
			if ( array() !== $filename ) {
				/* translators: %s: alt texts. */
				$out[] = Result::make( 'alt_quality', 'bad', sprintf( __( 'Some alt texts are file names ("%s"); describe the picture instead.', 'zinn-seo' ), implode( '", "', array_slice( $filename, 0, 3 ) ) ), 2, 'content', $filename );
			} elseif ( $long > 0 ) {
				/* translators: %d: number of images. */
				$out[] = Result::make( 'alt_quality', 'ok', sprintf( _n( '%d alt text is very long; keep alt text to a sentence and put details in a caption.', '%d alt texts are very long; keep alt text to a sentence and put details in a caption.', $long, 'zinn-seo' ), $long ), 1, 'content' );
			} elseif ( array() !== $redundant ) {
				$out[] = Result::make( 'alt_quality', 'ok', __( 'Some alt texts start with "image of"; screen readers already say it is an image.', 'zinn-seo' ), 1, 'content', $redundant );
			}
		}
		$untitled = count( array_filter( $doc->frames, static fn( $f ) => '' === $f['title'] ) );
		if ( $untitled > 0 ) {
			/* translators: %d: number of embedded frames. */
			$out[] = Result::make( 'frame_titles', 'bad', sprintf( _n( '%d embedded frame (a video or map) has no title; add one that says what it is.', '%d embedded frames (videos or maps) have no title; add one that says what each is.', $untitled, 'zinn-seo' ), $untitled ), 1, 'content' );
		}
		if ( $doc->tables_without_headers > 0 ) {
			/* translators: %d: number of tables. */
			$out[] = Result::make( 'table_headers', 'ok', sprintf( _n( '%d table has no header row; mark its first row or column as headers.', '%d tables have no header row; mark the first row or column as headers.', $doc->tables_without_headers, 'zinn-seo' ), $doc->tables_without_headers ), 1, 'content' );
		}

		return $out;
	}
}
