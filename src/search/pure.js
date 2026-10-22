/**
 * Pure helpers of the Search screen (no WordPress, no DOM): tested in __tests__/pure.test.js.
 */
import { readerLocale } from '../tools/pure';

/**
 * A share as a percentage in the reader's notation ("4.2%"); an em dash for nothing measured.
 *
 * @param {number|null} share  0-1.
 * @param {number}      digits Decimals.
 * @param {string}      locale Language tag.
 * @return {string} Text.
 */
export function pct( share, digits = 1, locale = readerLocale() ) {
	if ( share === null || share === undefined || Number.isNaN( share ) ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale, {
			style: 'percent',
			minimumFractionDigits: digits,
			maximumFractionDigits: digits,
		} ).format( share );
	} catch {
		return `${ ( share * 100 ).toFixed( digits ) }%`;
	}
}

/**
 * A position, one decimal ("11.4"); an em dash for none.
 *
 * @param {number|null} p      Position.
 * @param {string}      locale Language tag.
 * @return {string} Text.
 */
export function position( p, locale = readerLocale() ) {
	if ( ! p ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1,
		} ).format( p );
	} catch {
		return Number( p ).toFixed( 1 );
	}
}

/**
 * How a figure moved against the period before: the share of change and whether that is good
 * (for position, LOWER is better). Null when there is nothing to compare.
 *
 * @param {number}  now           This period.
 * @param {number}  before        The period before.
 * @param {boolean} lowerIsBetter Position-like figures.
 * @return {{share: number, good: boolean, flat: boolean}|null} Change.
 */
export function change( now, before, lowerIsBetter = false ) {
	if ( ! before || now === null || now === undefined ) {
		return null;
	}
	const share = ( now - before ) / before;
	const flat = Math.abs( share ) < 0.005;
	return {
		share,
		flat,
		good: flat ? true : share < 0 === lowerIsBetter,
	};
}

/**
 * The points of a line chart over `width` × `height`, the first value on the left. Pure maths;
 * the chart is always drawn left to right (time), in every language.
 *
 * @param {Array<number>} values Values.
 * @param {number}        width  Width.
 * @param {number}        height Height.
 * @param {boolean}       invert Higher values lower on the chart (position: 1 is the top).
 * @return {string} SVG `points`.
 */
export function points( values, width, height, invert = false ) {
	const n = values.length;
	if ( n === 0 ) {
		return '';
	}
	const max = Math.max( ...values );
	const min = Math.min( ...values );
	const range = max - min || 1;
	return values
		.map( ( v, i ) => {
			const x = n === 1 ? width / 2 : ( i / ( n - 1 ) ) * width;
			const unit = ( v - min ) / range;
			const y = invert ? unit * height : height - unit * height;
			return `${ Math.round( x * 10 ) / 10 },${ Math.round( y * 10 ) / 10 }`;
		} )
		.join( ' ' );
}

/**
 * The plain-words verdict of a title test.
 *
 * @param {Object} stat `significance()` from the plugin: ctr_a, ctr_b, p, enough, significant, winner.
 * @return {string} winner-b | winner-a | no-difference | not-enough
 */
export function verdict( stat ) {
	if ( ! stat ) {
		return 'not-enough';
	}
	if ( ! stat.enough ) {
		return 'not-enough';
	}
	if ( stat.significant ) {
		return stat.winner === 'B' ? 'winner-b' : 'winner-a';
	}
	return 'no-difference';
}

/**
 * Confidence as people say it: "95% sure" from a p-value (1 - p), floored to a whole percent.
 *
 * @param {number} p P-value.
 * @return {number} 0-100.
 */
export function confidence( p ) {
	if ( p === null || p === undefined || Number.isNaN( p ) ) {
		return 0;
	}
	return Math.max( 0, Math.min( 100, Math.floor( ( 1 - p ) * 100 ) ) );
}

/**
 * Where a test is now: phase n of m, the live wording, and the time to the next switch.
 *
 * @param {Object} test Test (`phases`, `status`).
 * @param {number} now  Seconds since 1970.
 * @return {{index: number, total: number, variant: string, left: number}} Progress.
 */
export function testProgress( test, now ) {
	const phases = ( test && test.phases ) || [];
	const i = phases.findIndex( ( p ) => now >= p.start && now < p.end );
	if ( i >= 0 ) {
		return {
			index: i + 1,
			total: phases.length,
			variant: phases[ i ].variant,
			left: phases[ i ].end - now,
		};
	}
	const before = phases.length > 0 && now < phases[ 0 ].start;
	return {
		index: before ? 0 : phases.length,
		total: phases.length,
		variant: '',
		left: 0,
	};
}

/**
 * Rows filtered by a search box (case-insensitive, any of the fields). Pure.
 *
 * @param {Array<Object>} rows   Rows.
 * @param {string}        search Text.
 * @param {Array<string>} fields Fields to look in.
 * @return {Array<Object>} Rows.
 */
export function filterRows( rows, search, fields ) {
	const q = String( search || '' )
		.trim()
		.toLowerCase();
	if ( ! q ) {
		return rows || [];
	}
	return ( rows || [] ).filter( ( r ) =>
		fields.some( ( f ) =>
			String( r[ f ] || '' )
				.toLowerCase()
				.includes( q )
		)
	);
}

/**
 * A page address shown short: the path only when it is on this site.
 *
 * @param {string} url  Address.
 * @param {string} home This site's home address.
 * @return {string} Text.
 */
export function shortUrl( url, home ) {
	try {
		const u = new URL( url );
		const h = new URL( home );
		if (
			u.host.replace( /^www\./, '' ) === h.host.replace( /^www\./, '' )
		) {
			return decodeURI( u.pathname + u.search ) || '/';
		}
	} catch {
		// Not an address: shown as it is.
	}
	return String( url || '' );
}
