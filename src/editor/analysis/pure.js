/**
 * Pure helpers of the analysis panel (unit-tested): focus phrase text, search-result truncation
 * by pixel width, and linking words inside block markup.
 */

/**
 * Parse the stored focus text: one phrase per line, `phrase | synonym, synonym`.
 *
 * @param {string} text Stored text.
 * @return {Array<{phrase: string, synonyms: string}>} Phrases.
 */
export function parseFocus( text ) {
	return String( text || '' )
		.split( /\r?\n/ )
		.map( ( line ) => {
			const [ phrase, synonyms = '' ] = line.split( '|' );
			return { phrase: phrase.trim(), synonyms: synonyms.trim() };
		} )
		.filter( ( f ) => f.phrase );
}

/**
 * Serialise phrases back to the stored text.
 *
 * @param {Array<{phrase: string, synonyms: string}>} list Phrases.
 * @return {string} Text.
 */
export function stringifyFocus( list ) {
	return ( list || [] )
		.filter( ( f ) => f && String( f.phrase || '' ).trim() )
		.map( ( f ) =>
			String( f.synonyms || '' ).trim()
				? `${ f.phrase.trim() } | ${ f.synonyms.trim() }`
				: f.phrase.trim()
		)
		.join( '\n' );
}

/**
 * Cut a text to what fits in a width, at a word boundary, with an ellipsis — the way search
 * results cut titles and descriptions (by pixels, not characters).
 *
 * @param {string}   text    Text.
 * @param {number}   max     Width in pixels.
 * @param {Function} measure Text -> width in pixels.
 * @return {{text: string, cut: boolean}} The shown text.
 */
export function fitText( text, max, measure ) {
	const t = String( text || '' ).trim();
	if ( measure( t ) <= max ) {
		return { text: t, cut: false };
	}
	let lo = 0;
	let hi = t.length;
	while ( lo < hi ) {
		const mid = Math.ceil( ( lo + hi ) / 2 );
		if ( measure( t.slice( 0, mid ) + ' …' ) <= max ) {
			lo = mid;
		} else {
			hi = mid - 1;
		}
	}
	let cut = t.slice( 0, lo );
	const space = cut.lastIndexOf( ' ' );
	if ( space > lo * 0.6 ) {
		cut = cut.slice( 0, space );
	}
	return { text: cut.replace( /[\s,.;:–—-]+$/, '' ) + ' …', cut: true };
}

/**
 * Escape text for a regular expression.
 *
 * @param {string} s Text.
 * @return {string} Escaped.
 */
function escapeRe( s ) {
	return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

/**
 * Link the first occurrence of some words in markup, outside links, headings, code and buttons.
 *
 * @param {string} html   Markup.
 * @param {string} anchor Words.
 * @param {string} url    Address.
 * @return {string|null} New markup, or null when the words are not in a linkable place.
 */
export function linkFirst( html, anchor, url ) {
	const words = escapeRe( String( anchor || '' ).trim() ).replace(
		/\s+/g,
		'\\s+'
	);
	if ( ! words ) {
		return null;
	}
	const parts = String( html || '' ).split( /(<!--[\s\S]*?-->|<[^>]*>)/ );
	// nosemgrep: javascript.lang.security.audit.detect-non-literal-regexp.detect-non-literal-regexp -- every character of the anchor passes escapeRe() and only \s+ is re-inserted, so the pattern is linear; re-check if escapeRe is removed or the anchor is used unescaped.
	const re = new RegExp(
		`(^|[^\\p{L}\\p{N}])(${ words })(?![\\p{L}\\p{N}])`,
		'iu'
	);
	let depth = 0;
	for ( let i = 0; i < parts.length; i++ ) {
		const p = parts[ i ];
		if ( p.startsWith( '<' ) ) {
			const m = p.match(
				/^<(\/?)(a|h[1-6]|code|pre|script|style|button|textarea|figcaption)\b/i
			);
			if ( m ) {
				depth = Math.max( 0, depth + ( m[ 1 ] ? -1 : 1 ) );
			}
			continue;
		}
		if ( depth === 0 && re.test( p ) ) {
			const safe = String( url ).replace( /"/g, '&quot;' );
			parts[ i ] = p.replace(
				re,
				( all, before, words2 ) =>
					`${ before }<a href="${ safe }">${ words2 }</a>`
			);
			return parts.join( '' );
		}
	}
	return null;
}

/**
 * The words a reader sees in some markup: tags gone, entities decoded.
 *
 * Parsed into an inert document (DOMParser runs no script and loads nothing), so markup that
 * a regular expression would only half-strip - `<scr<script>ipt>` - cannot survive as a tag.
 *
 * @param {string} html Markup.
 * @return {string} Plain text.
 */
export function plainText( html ) {
	const doc = new window.DOMParser().parseFromString(
		String( html || '' ),
		'text/html'
	);
	return doc.body ? doc.body.textContent || '' : '';
}

/**
 * The rating of a score.
 *
 * @param {number|null} score Score.
 * @return {string} good, ok, bad or none.
 */
export function rating( score ) {
	if ( score === null || score === undefined ) {
		return 'none';
	}
	if ( score >= 75 ) {
		return 'good';
	}
	return score >= 50 ? 'ok' : 'bad';
}
