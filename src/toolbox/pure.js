/**
 * Logic of the research toolbox with no React in it (tested on its own).
 */

/** The grid widths Zinn® accepts. */
export const GRID_SIZES = [ 3, 5, 7, 9 ];

/** Days between scheduled runs. */
export const CADENCES = [ 1, 7, 30 ];

/**
 * Lines of a text box: trimmed, without empties or repeats (case-insensitive), at most `max`.
 *
 * @param {string} text Text.
 * @param {number} max  Most lines kept.
 * @return {Array<string>} Lines.
 */
export function lines( text, max = 100 ) {
	const seen = new Set();
	const out = [];
	for ( const raw of String( text || '' ).split( /\r?\n/ ) ) {
		const line = raw.trim().replace( /\s+/g, ' ' );
		if ( line && ! seen.has( line.toLowerCase() ) ) {
			seen.add( line.toLowerCase() );
			out.push( line );
		}
	}
	return out.slice( 0, max );
}

/**
 * How many units a run of a tool spends — the same count Zinn® bills (`catalogue.PRODUCTS`).
 *
 * @param {string} product Tool code.
 * @param {Object} params  Parameters.
 * @return {number} Units.
 */
export function unitsOf( product, params ) {
	const p = params || {};
	switch ( product ) {
		case 'geo_grid':
			return GRID_SIZES.includes( Number( p.size ) )
				? Number( p.size ) ** 2
				: 0;
		case 'reviews':
			return ( p.profiles || [] ).length;
		case 'shopping':
		case 'amazon':
			return ( p.products || [] ).length;
		case 'youtube':
		case 'snapshot':
		case 'sentiment':
			return ( p.phrases || [] ).length;
		case 'traffic':
		case 'technology':
		case 'domain_overview':
			return ( p.domains || [] ).length;
		case 'visibility':
			return ( p.phrases || [] ).length;
		case 'trends':
			return ( p.phrases || [] ).length ? 1 : 0;
		case 'questions':
		case 'brief':
			return p.phrase ? 1 : 0;
		default:
			return 0;
	}
}

/**
 * The colour band of a map position: top three, first page of the map, further down, absent.
 *
 * @param {number|null} position 1-based, null when not found.
 * @param {boolean}     answered Whether the point was measured at all.
 * @return {string} Band: `top`, `good`, `low`, `none` or `unknown`.
 */
export function band( position, answered = true ) {
	if ( ! answered ) {
		return 'unknown';
	}
	if ( position === null || position === undefined ) {
		return 'none';
	}
	if ( position <= 3 ) {
		return 'top';
	}
	if ( position <= 10 ) {
		return 'good';
	}
	return 'low';
}

/**
 * Rows of a grid run, north row first, as the grid shows them.
 *
 * @param {Array<Object>} points The run's points (row by row).
 * @return {Array<Array<Object>>} Rows.
 */
export function gridRows( points ) {
	const list = points || [];
	const size = Math.round( Math.sqrt( list.length ) );
	if ( ! size || size * size !== list.length ) {
		return [];
	}
	const rows = [];
	for ( let r = 0; r < size; r++ ) {
		rows.push( list.slice( r * size, ( r + 1 ) * size ) );
	}
	return rows;
}

/**
 * A Tripadvisor page address or path reduced to what Zinn® reads (`Hotel_Review-g…-d…-…html`).
 *
 * @param {string} value What was pasted.
 * @return {string} The page name, or '' when it is not a Tripadvisor review page.
 */
export function tripadvisorPage( value ) {
	const text = String( value || '' ).trim();
	const last = text
		.split( '?' )[ 0 ]
		.split( '#' )[ 0 ]
		.replace( /\/+$/, '' )
		.split( '/' )
		.pop();
	return /^[A-Za-z_]+-g\d+-d\d+-[\w.-]+$/.test( last || '' ) ? last : '';
}

/**
 * A domain from what was typed (`https://www.Example.com/x` → `example.com`), or ''.
 *
 * @param {string} value Text.
 * @return {string} Domain.
 */
export function domainOf( value ) {
	let text = String( value || '' )
		.trim()
		.toLowerCase();
	if ( ! text ) {
		return '';
	}
	if ( ! text.includes( '://' ) ) {
		text = `https://${ text }`;
	}
	try {
		const host = new URL( text ).hostname.replace( /^www\./, '' );
		return /^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/.test( host )
			? host
			: '';
	} catch {
		return '';
	}
}

/**
 * Shares of a sentiment reading, as whole percentages of everything rated.
 *
 * @param {Object} row `{positive, neutral, negative}`.
 * @return {{positive: number, neutral: number, negative: number}} Percentages.
 */
export function shares( row ) {
	const p = Number( row?.positive ) || 0;
	const n = Number( row?.neutral ) || 0;
	const g = Number( row?.negative ) || 0;
	const total = p + n + g;
	if ( ! total ) {
		return { positive: 0, neutral: 0, negative: 0 };
	}
	const positive = Math.round( ( 100 * p ) / total );
	const negative = Math.round( ( 100 * g ) / total );
	return {
		positive,
		negative,
		neutral: Math.max( 0, 100 - positive - negative ),
	};
}

/**
 * Whether a run is still going.
 *
 * @param {Object|null} run Run.
 * @return {boolean} Going.
 */
export function going( run ) {
	return !! run && 'queued' === run.status;
}

/**
 * A price in cents (minor units) of a currency as money in the reader's notation.
 *
 * @param {number|null} minor    Minor units.
 * @param {string}      currency ISO code.
 * @param {string}      locale   Language tag.
 * @return {string} Money, or an em dash when there is no price.
 */
export function shelfPrice( minor, currency, locale ) {
	if ( minor === null || minor === undefined || ! currency ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( minor / 100 );
	} catch {
		return `${ ( minor / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}
