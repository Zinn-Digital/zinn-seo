/**
 * The Zinn® services list without React: grouping, what the account has, the price line.
 */

/** Contexts the screen gives their own tab (the rest are listed under "Everything"). */
export const OWN_TABS = [ 'update_guard', 'webmaster', 'reseller' ];

/**
 * Products grouped by the context they are used in, keeping Zinn®'s own order. A context this
 * plugin has never heard of still shows (a new service needs no plugin release).
 *
 * @param {Array<Object>} products Rows from `/catalogue`.
 * @return {Array<{context: string, products: Array<Object>}>} Groups.
 */
export function groups( products ) {
	const out = [];
	const at = {};
	( products || [] ).forEach( ( p ) => {
		const key = p.context || 'more';
		if ( at[ key ] === undefined ) {
			at[ key ] = out.length;
			out.push( { context: key, products: [] } );
		}
		out[ at[ key ] ].products.push( p );
	} );
	return out;
}

/**
 * The products for some contexts.
 *
 * @param {Array<Object>} products Rows.
 * @param {Array<string>} contexts Contexts.
 * @return {Array<Object>} Rows.
 */
export function inContexts( products, contexts ) {
	return ( products || [] ).filter( ( p ) => contexts.includes( p.context ) );
}

/**
 * Whether the account holds a product.
 *
 * @param {Array<Object>} owned Held purchases.
 * @param {string}        code  Product code.
 * @return {boolean} Held.
 */
export function holds( owned, code ) {
	return ( owned || [] ).some( ( o ) => o.code === code );
}

/**
 * A price from whole minor units and its currency. Never a float until printed; an unknown
 * price is a dash, never "free".
 *
 * @param {number} minor    Minor units.
 * @param {string} currency ISO code.
 * @param {string} locale   Language tag.
 * @return {string} Price.
 */
export function amount( minor, currency, locale ) {
	if ( typeof minor !== 'number' || ! currency ) {
		return '—';
	}
	let digits;
	try {
		digits =
			new Intl.NumberFormat( 'en', {
				style: 'currency',
				currency,
			} ).resolvedOptions().maximumFractionDigits ?? 2;
	} catch {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( minor / 10 ** digits );
	} catch {
		return `${ ( minor / 10 ** digits ).toFixed( digits ) } ${ currency }`;
	}
}
