/**
 * Marks on a score's history (zs-651): where on the chart a day falls, and the history as text
 * that pastes straight into Google Sheets.
 */

/**
 * The x position of each mark that falls inside the chart's dates.
 *
 * @param {Object} markers `{changes: [{date, count}], alerts: [{date, kind, title}]}`.
 * @param {Object} frame   `{start, end}` (ms) from `frameOf`.
 * @param {number} w       Chart width.
 * @return {Array<{x: number, kind: string, date: string, label: string}>} Marks.
 */
export function markXs( markers, frame, w ) {
	if ( ! markers || ! frame ) {
		return [];
	}
	const span = Math.max( 1, frame.end - frame.start );
	const place = ( date ) => {
		const t = Date.parse( `${ date }T00:00:00Z` );
		if ( ! Number.isFinite( t ) || t < frame.start || t > frame.end ) {
			return null;
		}
		return Math.round( ( ( t - frame.start ) / span ) * w * 10 ) / 10;
	};
	const out = [];
	( markers.changes || [] ).forEach( ( c ) => {
		const x = place( c.date );
		if ( x !== null ) {
			out.push( {
				x,
				kind: 'change',
				date: c.date,
				label: String( c.count ),
			} );
		}
	} );
	( markers.alerts || [] ).forEach( ( a ) => {
		const x = place( a.date );
		if ( x !== null ) {
			out.push( {
				x,
				kind: 'alert',
				date: a.date,
				label: a.title || '',
			} );
		}
	} );
	return out;
}

/**
 * The history as tab-separated text (one row per day, one column per website), the format
 * Google Sheets and Excel paste into cells.
 *
 * @param {Object} series `{ domain: [ { date, value } ] }`.
 * @return {string} Text.
 */
export function toTsv( series ) {
	const domains = Object.keys( series || {} );
	const days = [
		...new Set(
			domains.flatMap( ( d ) =>
				( series[ d ] || [] ).map( ( p ) => p.date )
			)
		),
	].sort();
	const clean = ( v ) =>
		v === null || v === undefined
			? ''
			: String( v ).replace( /[\t\r\n]+/g, ' ' );
	const lookup = {};
	domains.forEach( ( d ) => {
		lookup[ d ] = {};
		( series[ d ] || [] ).forEach( ( p ) => {
			lookup[ d ][ p.date ] = p.value;
		} );
	} );
	const rows = [ [ 'date', ...domains ].map( clean ).join( '\t' ) ];
	days.forEach( ( day ) =>
		rows.push(
			[ day, ...domains.map( ( d ) => lookup[ d ][ day ] ) ]
				.map( clean )
				.join( '\t' )
		)
	);
	return rows.join( '\n' ) + '\n';
}
