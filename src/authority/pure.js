/**
 * Pure helpers of the Site authority screen (no WordPress, no DOM): tested in __tests__/pure.test.js.
 */
import { readerLocale } from '../tools/pure';

/**
 * A score in the reader's notation, up to two decimals ("42", "4.63"); an em dash for none.
 * ⛔ "—" is "the vendor did not say", never 0, which is a real score.
 *
 * @param {string|number|null} value  The API's decimal text.
 * @param {string}             locale Language tag.
 * @return {string} Text.
 */
export function score( value, locale = readerLocale() ) {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	const n = Number( value );
	if ( ! Number.isFinite( n ) ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale, {
			maximumFractionDigits: 2,
		} ).format( n );
	} catch {
		return String( Math.round( n * 100 ) / 100 );
	}
}

/**
 * Which way a score moved, and whether that is good news (lower is better for Spam Score and
 * rank positions). `flat` when unchanged or not comparable.
 *
 * @param {string|null} now    This reading.
 * @param {string|null} before The reading before.
 * @param {string}      better `higher` or `lower`.
 * @return {{dir: string, good: boolean|null, by: number}} Movement.
 */
export function moved( now, before, better = 'higher' ) {
	const a = Number( now );
	const b = Number( before );
	if (
		now === null ||
		before === null ||
		now === undefined ||
		before === undefined ||
		! Number.isFinite( a ) ||
		! Number.isFinite( b ) ||
		a === b
	) {
		return { dir: 'flat', good: null, by: 0 };
	}
	const up = a > b;
	return {
		dir: up ? 'up' : 'down',
		good: better === 'lower' ? ! up : up,
		by: Math.round( Math.abs( a - b ) * 100 ) / 100,
	};
}

/**
 * The cards: every SHOWN metric's latest value for the site's own domain, per source.
 *
 * @param {Object} state The `/authority` answer.
 * @return {Array<Object>} `{ source, metric, name, better, value, previous, on, attribution, attributionUrl }`.
 */
export function cards( state ) {
	const out = [];
	const own = ( state && state.site ) || '';
	const sources = ( state && state.sources ) || [];
	for ( const feed of ( state && state.feeds ) || [] ) {
		const source = sources.find( ( s ) => s.key === feed.vendor );
		if ( ! source ) {
			continue;
		}
		for ( const key of feed.show || [] ) {
			const spec = ( source.metrics || [] ).find(
				( m ) => m.key === key
			);
			const cell =
				( ( ( state.latest || {} )[ feed.vendor ] || {} )[ key ] ||
					{} )[ own ] || {};
			out.push( {
				source: feed.vendor,
				metric: key,
				name: spec ? spec.name : key,
				better: spec ? spec.better : 'higher',
				value: cell.value ?? null,
				previous: cell.previous ?? null,
				on: cell.on || '',
				attribution: source.attribution,
				attributionUrl: source.attribution_url,
			} );
		}
	}
	return out;
}

/**
 * Every tracked (source, metric) pair, for the history picker.
 *
 * @param {Object} state The `/authority` answer.
 * @return {Array<{value: string, label: string, source: string, metric: string}>} Options.
 */
export function trackedOptions( state ) {
	const sources = ( state && state.sources ) || [];
	const out = [];
	for ( const feed of ( state && state.feeds ) || [] ) {
		const source = sources.find( ( s ) => s.key === feed.vendor );
		for ( const key of feed.track || [] ) {
			const spec = ( ( source && source.metrics ) || [] ).find(
				( m ) => m.key === key
			);
			out.push( {
				value: `${ feed.vendor }:${ key }`,
				label: `${ spec ? spec.name : key } — ${
					source ? source.name : feed.vendor
				}`,
				source: feed.vendor,
				metric: key,
			} );
		}
	}
	return out;
}

/**
 * The series as a CSV the reader can open in a spreadsheet: one row per day, one column per domain.
 *
 * @param {Object} series `{ domain: [ { date, value } ] }`.
 * @return {string} CSV text (RFC 4180 quoting).
 */
export function toCsv( series ) {
	const domains = Object.keys( series || {} );
	const days = [
		...new Set(
			domains.flatMap( ( d ) =>
				( series[ d ] || [] ).map( ( p ) => p.date )
			)
		),
	].sort();
	const quote = ( v ) => {
		const text = v === null || v === undefined ? '' : String( v );
		return /[",\n\r]/.test( text )
			? `"${ text.replace( /"/g, '""' ) }"`
			: text;
	};
	const lookup = {};
	for ( const d of domains ) {
		lookup[ d ] = {};
		for ( const p of series[ d ] || [] ) {
			lookup[ d ][ p.date ] = p.value;
		}
	}
	const rows = [ [ 'date', ...domains ].map( quote ).join( ',' ) ];
	for ( const day of days ) {
		rows.push(
			[ day, ...domains.map( ( d ) => lookup[ d ][ day ] ?? '' ) ]
				.map( quote )
				.join( ',' )
		);
	}
	return rows.join( '\n' ) + '\n';
}

/**
 * Points of one series on a shared scale, for an SVG polyline. Days are placed by DATE (a gap in
 * readings is a gap in time, not squeezed out); a `null` value breaks the line.
 *
 * @param {Array<Object>} points `[ { date, value } ]` oldest first.
 * @param {Object}        frame  `{ start, end, min, max, w, h }` (dates as ms).
 * @return {Array<string>} One `x,y x,y` string per unbroken run.
 */
export function runs( points, frame ) {
	const span = Math.max( 1, frame.end - frame.start );
	const range = frame.max - frame.min || 1;
	const out = [];
	let cur = [];
	for ( const p of points || [] ) {
		const v =
			p.value === null || p.value === undefined ? NaN : Number( p.value );
		if ( ! Number.isFinite( v ) ) {
			if ( cur.length ) {
				out.push( cur.join( ' ' ) );
			}
			cur = [];
			continue;
		}
		const t = Date.parse( `${ p.date }T00:00:00Z` );
		const x = ( ( t - frame.start ) / span ) * frame.w;
		const y = frame.h - ( ( v - frame.min ) / range ) * frame.h;
		cur.push( `${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }` );
	}
	if ( cur.length ) {
		out.push( cur.join( ' ' ) );
	}
	return out;
}

/**
 * The frame every series of one chart shares.
 *
 * @param {Object} series `{ domain: [ { date, value } ] }`.
 * @return {{start: number, end: number, min: number, max: number}|null} Frame (null: nothing to draw).
 */
export function frameOf( series ) {
	let start = Infinity;
	let end = -Infinity;
	let min = Infinity;
	let max = -Infinity;
	for ( const points of Object.values( series || {} ) ) {
		for ( const p of points || [] ) {
			const v = Number( p.value );
			if ( p.value === null || ! Number.isFinite( v ) ) {
				continue;
			}
			const t = Date.parse( `${ p.date }T00:00:00Z` );
			start = Math.min( start, t );
			end = Math.max( end, t );
			min = Math.min( min, v );
			max = Math.max( max, v );
		}
	}
	if ( ! Number.isFinite( start ) ) {
		return null;
	}
	// A flat line sits mid-chart rather than on the floor; a small move stays visible.
	const pad = min === max ? 1 : ( max - min ) * 0.1;
	const floor = min >= 0 ? Math.max( 0, min - pad ) : min - pad;
	return { start, end, min: floor, max: max + pad };
}

/**
 * Whether a key looks pasteable (no spaces inside, not absurdly long) — the vendor decides
 * whether it is RIGHT; this only stops an obvious copy slip before a round trip.
 *
 * @param {string} key Pasted key.
 * @return {boolean} Plausible.
 */
export function plausibleKey( key ) {
	const k = String( key || '' ).trim();
	return k.length >= 8 && k.length <= 400 && ! /\s/.test( k );
}

/**
 * A domain as the API takes it: lower case, no scheme, no path, no `www.`.
 *
 * @param {string} value Typed text.
 * @return {string} Domain ('' when nothing usable).
 */
export function bareDomain( value ) {
	let text = String( value || '' )
		.trim()
		.toLowerCase();
	text = text.replace( /^https?:\/\//, '' );
	text = text.split( /[/?#]/ )[ 0 ].split( ':' )[ 0 ].replace( /\.$/, '' );
	text = text.replace( /^www\./, '' );
	return /^[a-z0-9.-]+\.[a-z]{2,}$/.test( text ) ? text : '';
}
