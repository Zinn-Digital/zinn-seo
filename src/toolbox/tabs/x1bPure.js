/**
 * The X1B research tabs' logic, without React (tested on its own).
 */

/**
 * A month's name in the reader's language (1 = January).
 *
 * @param {number} month  1-12.
 * @param {string} locale Language tag.
 * @return {string} Name.
 */
export function monthName( month, locale ) {
	const m = Number( month );
	if ( ! ( m >= 1 && m <= 12 ) ) {
		return '—';
	}
	try {
		return new Intl.DateTimeFormat( locale, {
			month: 'long',
			timeZone: 'UTC',
		} ).format( new Date( Date.UTC( 2024, m - 1, 15 ) ) );
	} catch {
		return String( m );
	}
}

/**
 * Polyline point strings for a 0-100 series, broken where Google has no data (a missing week is
 * a gap, never a drop to zero).
 *
 * @param {Array<{date: string, value: ?number}>} points Series.
 * @param {number}                                w      Width.
 * @param {number}                                h      Height.
 * @return {Array<string>} One `x,y x,y` string per unbroken run.
 */
export function sparkPoints( points, w, h ) {
	const list = points || [];
	if ( list.length < 2 ) {
		return [];
	}
	const step = w / ( list.length - 1 );
	const out = [];
	let current = [];
	list.forEach( ( p, i ) => {
		if ( p.value === null || p.value === undefined ) {
			if ( current.length > 1 ) {
				out.push( current.join( ' ' ) );
			}
			current = [];
			return;
		}
		const y = h - ( Math.max( 0, Math.min( 100, p.value ) ) / 100 ) * h;
		current.push( `${ Math.round( i * step ) },${ Math.round( y ) }` );
	} );
	if ( current.length > 1 ) {
		out.push( current.join( ' ' ) );
	}
	return out;
}

/**
 * The site's own visibility from each finished run, oldest first (unanswered runs are a gap).
 *
 * @param {Array<Object>} runs Visibility runs, newest first.
 * @return {Array<{at: string, visibility: ?number}>} Series.
 */
export function visibilitySeries( runs ) {
	return ( runs || [] )
		.filter( ( r ) => r.status === 'complete' || r.status === 'partial' )
		.map( ( r ) => {
			const own = ( ( r.result && r.result.domains ) || [] ).find(
				( d ) => d.own
			);
			return {
				at: r.finished_at || r.created_at,
				visibility: own ? own.visibility : null,
			};
		} )
		.reverse();
}
