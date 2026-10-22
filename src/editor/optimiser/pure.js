/**
 * The live content optimiser's score (zs-766), worked out in the editor on every pause in typing:
 * no request per keystroke. The same sum as Optimiser::score() in PHP (MCP, WP-CLI, the page-builder
 * panel) — the two are held equal by one shared fixture (__tests__/parity.json), and the text
 * helpers mirror the analysis module's Text class (norm, words, same_word, contains, count).
 */

export const WEIGHTS = {
	terms: 45,
	entities: 15,
	questions: 15,
	headings: 15,
	length: 10,
};

const UNSPACED =
	/[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Thai}\p{Script=Lao}\p{Script=Khmer}\p{Script=Myanmar}\p{Script=Tibetan}]/gu;

/**
 * Length in characters (code points), as PHP's mb_strlen.
 *
 * @param {string} s Text.
 * @return {number} Length.
 */
const len = ( s ) => Array.from( s ).length;

/**
 * Lower case, unified punctuation, no Arabic/Hebrew vowel marks or tatweel, single spaces.
 *
 * @param {string} text Text.
 * @return {string} Normalised.
 */
export function norm( text ) {
	let t = String( text || '' ).toLowerCase();
	t = t
		.replace( /[\u2019\u2018`\u00b4]/g, "'" )
		.replace( /[\u2010\u2011\u2013\u2014]/g, '-' )
		.replace( /\u0640/g, '' );
	// Arabic harakat + Quranic marks, Hebrew niqqud: written sometimes, never part of a match.
	t = t.replace( /[\u064B-\u065F\u0670\u06D6-\u06ED\u0591-\u05C7]/g, '' );
	// Alef forms read as one letter when matching Arabic.
	t = t.replace( /[\u0623\u0625\u0622]/g, '\u0627' );
	return t.replace( /[\s\u00A0\u200B\u202F\u2007]+/gu, ' ' ).trim();
}

/**
 * Is the text mostly in a script written without spaces?
 *
 * @param {string} text Text.
 * @return {boolean} Unspaced.
 */
export function unspaced( text ) {
	const sample = Array.from( String( text || '' ) )
		.slice( 0, 2000 )
		.join( '' );
	const letters = ( sample.match( /\p{L}/gu ) || [] ).length;
	if ( ! letters ) {
		return false;
	}
	const closed = ( sample.match( UNSPACED ) || [] ).length;
	return closed / letters > 0.3;
}

/**
 * The words of a text (for an unspaced script: its letters).
 *
 * @param {string}       text Text.
 * @param {boolean|null} unsp Measure in characters (null: decide from the text).
 * @return {string[]} Words.
 */
export function words( text, unsp = null ) {
	const t = String( text || '' );
	const u = unsp === null ? unspaced( t ) : unsp;
	if ( u ) {
		return t.match( /[\p{L}\p{N}]\p{M}*/gu ) || [];
	}
	return ( t.match( /[\p{L}\p{N}][\p{L}\p{M}\p{N}'’-]*/gu ) || [] )
		.map( ( w ) => w.replace( /[-'’]+$/u, '' ) )
		.filter( ( w ) => w !== '' );
}

/**
 * A word's stem: the word less its last two letters (at least four kept).
 *
 * @param {string} word Normalised word.
 * @return {string} Stem.
 */
export function stem( word ) {
	const chars = Array.from( word );
	if ( chars.length <= 4 ) {
		return word;
	}
	return chars.slice( 0, Math.max( 4, chars.length - 2 ) ).join( '' );
}

/**
 * Is a text word a form of a phrase word?
 *
 * @param {string} textWord   Normalised word from the text.
 * @param {string} phraseWord Normalised word from the phrase.
 * @return {boolean} Same.
 */
export function sameWord( textWord, phraseWord ) {
	if ( textWord === phraseWord ) {
		return true;
	}
	if ( len( phraseWord ) <= 3 ) {
		return false;
	}
	const s = stem( phraseWord );
	return textWord.startsWith( s ) && len( textWord ) - len( s ) <= 5;
}

/**
 * Escape text for a regular expression.
 *
 * @param {string} s Text.
 * @return {string} Escaped.
 */
const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\/]/g, '\\$&' );

/**
 * Does a text contain a phrase (exact, normalised; with `forms`, every word by stem)?
 *
 * @param {string}  haystack Text.
 * @param {string}  phrase   Phrase.
 * @param {boolean} forms    Accept word forms and any order.
 * @return {boolean} Contains.
 */
export function contains( haystack, phrase, forms = true ) {
	const h = norm( haystack );
	const p = norm( phrase );
	if ( ! p || ! h ) {
		return false;
	}
	if ( unspaced( p ) ) {
		return h.includes( p.replace( / /g, '' ) ) || h.includes( p );
	}
	const re = new RegExp(
		`(?<![\\p{L}\\p{N}])${ escapeRe( p ) }(?![\\p{L}\\p{N}])`,
		'u'
	);
	if ( re.test( h ) ) {
		return true;
	}
	if ( ! forms ) {
		return false;
	}
	const have = [ ...new Set( words( h, false ) ) ];
	return words( p, false ).every( ( w ) =>
		have.some( ( t ) => sameWord( t, w ) )
	);
}

/**
 * How many times a phrase occurs (exact; word forms by stem for one-word phrases).
 *
 * @param {string} haystack Text.
 * @param {string} phrase   Phrase.
 * @return {number} Count.
 */
export function count( haystack, phrase ) {
	const h = norm( haystack );
	const p = norm( phrase );
	if ( ! p || ! h ) {
		return 0;
	}
	if ( unspaced( p ) ) {
		const needle = p.replace( / /g, '' );
		return needle ? h.split( needle ).length - 1 : 0;
	}
	const re = new RegExp(
		`(?<![\\p{L}\\p{N}])${ escapeRe( p ) }(?![\\p{L}\\p{N}])`,
		'gu'
	);
	const exact = ( h.match( re ) || [] ).length;
	const ws = words( p, false );
	if ( ws.length !== 1 ) {
		return exact;
	}
	const forms = words( h, false ).filter( ( w ) =>
		sameWord( w, ws[ 0 ] )
	).length;
	return Math.max( exact, forms );
}

/**
 * A text's content words: normalised, not common, not numbers.
 *
 * @param {string} text Text.
 * @param {Set}    stop Common words.
 * @return {string[]} Words.
 */
export function contentWords( text, stop ) {
	const out = [];
	words( norm( text ), false ).forEach( ( w ) => {
		if (
			! stop.has( w ) &&
			! /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test( w ) &&
			len( w ) >= 2 &&
			! out.includes( w )
		) {
			out.push( w );
		}
	} );
	return out;
}

/**
 * The share of `want` found in `have`.
 *
 * @param {string[]} want Words looked for.
 * @param {string[]} have Words there.
 * @return {number} 0..1.
 */
export function overlap( want, have ) {
	if ( ! want.length ) {
		return 0;
	}
	const found = want.filter( ( w ) =>
		have.some( ( h ) => sameWord( h, w ) || sameWord( w, h ) )
	).length;
	return found / want.length;
}

/**
 * PHP's round(): half away from zero.
 *
 * @param {number} n Number.
 * @return {number} Rounded.
 */
const round = ( n ) => Math.sign( n ) * Math.round( Math.abs( n ) );

/**
 * The score of a draft against a model, with what to add and what is overused.
 *
 * @param {Object}   model    The kept model.
 * @param {Object}   draft    `{ text, headings: [{ level, text }] }`.
 * @param {string[]} stopList The language's common words.
 * @return {Object} `{ score, parts, words, target, terms, entities, questions, headings }`.
 */
export function score( model, draft, stopList = [] ) {
	const stop = new Set( stopList.map( norm ) );
	const text = String( ( draft && draft.text ) || '' );
	const n = norm( text );
	const length = words( text ).length;
	const median = Number( model.median_words || 0 );
	const f = median > 0 ? Math.max( 0.25, Math.min( 1, length / median ) ) : 1;
	const read = Math.max( 1, Number( model.read || 1 ) );
	const parts = {};

	let sum = 0;
	let total = 0;
	const terms = ( model.terms || [] ).map( ( t ) => {
		const c = count( n, t.term );
		const lo = Math.max( 1, round( t.min * f ) );
		const hi = Math.max( lo, round( t.max * f ) );
		const w = t.pages / read;
		let status = 'good';
		let credit = 1;
		if ( c === 0 ) {
			status = 'missing';
			credit = 0;
		} else if ( c < lo ) {
			status = 'under';
			credit = c / lo;
		} else if ( c > hi + 1 ) {
			status = 'over';
			credit = 0.6;
		}
		sum += w * credit;
		total += w;
		return {
			term: t.term,
			count: c,
			low: lo,
			high: hi,
			pages: t.pages,
			status,
		};
	} );
	if ( total > 0 ) {
		parts.terms = sum / total;
	}

	sum = 0;
	total = 0;
	const entities = ( model.entities || [] ).map( ( e ) => {
		const present = contains( n, e.name, false );
		sum += present ? e.pages : 0;
		total += e.pages;
		return { name: e.name, pages: e.pages, present };
	} );
	if ( total > 0 ) {
		parts.entities = sum / total;
	}

	const heads = ( ( draft && draft.headings ) || [] ).map( ( h ) =>
		contentWords( h.text, stop )
	);
	const have = contentWords( text, stop );
	let got = 0;
	const questions = ( model.questions || [] ).map( ( q ) => {
		const ws = contentWords( q, stop );
		let state = 'missing';
		if ( ws.length && heads.some( ( h ) => overlap( ws, h ) >= 0.6 ) ) {
			state = 'answered';
		} else if ( ws.length && overlap( ws, have ) >= 1 ) {
			state = 'mentioned';
		}
		got += { answered: 2, mentioned: 1, missing: 0 }[ state ];
		return { question: q, state };
	} );
	if ( questions.length ) {
		parts.questions = got / ( 2 * questions.length );
	}

	sum = 0;
	total = 0;
	const headings = ( model.headings || [] ).map( ( g ) => {
		const present = heads.some( ( h ) => overlap( g.words, h ) >= 0.5 );
		sum += present ? g.pages : 0;
		total += g.pages;
		return { text: g.text, pages: g.pages, present };
	} );
	if ( total > 0 ) {
		parts.headings = sum / total;
	}

	if ( median > 0 ) {
		const r = length / median;
		if ( r < 0.8 ) {
			parts.length = r / 0.8;
		} else if ( r > 1.5 ) {
			parts.length = Math.max( 0.6, 1.5 / r );
		} else {
			parts.length = 1;
		}
	}

	let weight = 0;
	let points = 0;
	Object.keys( parts ).forEach( ( k ) => {
		weight += WEIGHTS[ k ];
		points += WEIGHTS[ k ] * parts[ k ];
	} );
	const rounded = {};
	Object.keys( parts ).forEach( ( k ) => {
		rounded[ k ] = round( 100 * parts[ k ] );
	} );

	return {
		score: weight > 0 ? round( ( 100 * points ) / weight ) : null,
		parts: rounded,
		words: length,
		target: {
			median: median || null,
			low: model.words_low ?? null,
			high: model.words_high ?? null,
		},
		terms,
		entities,
		questions,
		headings,
	};
}

/**
 * A draft as the score reads it, from the editor's HTML: every block's text (headings
 * included, one block per line, as the analysis module's Doc::text()) and the headings.
 *
 * @param {string} html Content (block markup or HTML).
 * @return {{text: string, headings: Array<{level: number, text: string}>}} Draft.
 */
export function draftFromHtml( html ) {
	const out = { text: '', headings: [] };
	if ( typeof window === 'undefined' || ! window.DOMParser ) {
		return out;
	}
	const doc = new window.DOMParser().parseFromString(
		`<body>${ String( html || '' ) }</body>`,
		'text/html'
	);
	doc.querySelectorAll( 'script,style,noscript,template' ).forEach( ( el ) =>
		el.remove()
	);
	const blocks = [];
	const blockTags =
		'p,h1,h2,h3,h4,h5,h6,li,blockquote,figcaption,td,th,dt,dd,pre';
	doc.body.querySelectorAll( blockTags ).forEach( ( el ) => {
		// The innermost block only: a list item's paragraph is read once.
		if ( el.querySelector( blockTags ) ) {
			return;
		}
		const t = norm( el.textContent || '' );
		if ( ! t ) {
			return;
		}
		blocks.push( t );
		const m = /^h([1-6])$/i.exec( el.tagName );
		if ( m ) {
			out.headings.push( {
				level: Number( m[ 1 ] ),
				text: ( el.textContent || '' ).trim(),
			} );
		}
	} );
	if ( ! blocks.length ) {
		const all = norm( doc.body.textContent || '' );
		if ( all ) {
			blocks.push( all );
		}
	}
	out.text = blocks.join( '\n' );
	return out;
}

/**
 * The lists the panel shows: what to add (most-shared first), what is overused.
 *
 * @param {Object} result A score().
 * @return {{add: Object[], under: Object[], over: Object[], good: Object[]}} Lists.
 */
export function lists( result ) {
	const by = ( a, b ) => b.pages - a.pages || a.term.localeCompare( b.term );
	const t = result.terms || [];
	return {
		add: t.filter( ( x ) => x.status === 'missing' ).sort( by ),
		under: t.filter( ( x ) => x.status === 'under' ).sort( by ),
		over: t.filter( ( x ) => x.status === 'over' ).sort( by ),
		good: t.filter( ( x ) => x.status === 'good' ).sort( by ),
	};
}
