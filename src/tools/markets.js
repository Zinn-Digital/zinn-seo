/**
 * Countries a check can be asked in, named in the reader's language.
 */
import { readerLocale } from './pure';

/**
 * A country's name in the reader's language.
 *
 * @param {string} code     Two-letter country code.
 * @param {string} fallback What to show when the browser cannot name it.
 * @return {string} Name.
 */
export function regionName( code, fallback = '' ) {
	try {
		const names = new Intl.DisplayNames( [ readerLocale() || 'en' ], {
			type: 'region',
		} );
		return names.of( code ) || fallback || code;
	} catch {
		return fallback || code;
	}
}

/**
 * The options of a country select.
 *
 * @param {Object} markets Country code => `{ location, language }`.
 * @return {Array<{value: string, label: string}>} Options, by name.
 */
export function marketOptions( markets ) {
	return Object.keys( markets || {} )
		.map( ( code ) => ( {
			value: code,
			label: regionName( code, markets[ code ].location ),
		} ) )
		.sort( ( a, b ) => a.label.localeCompare( b.label ) );
}

/**
 * The reader's name for a stored location ("United Kingdom" → the name in their language).
 *
 * @param {Object} markets  Country code => `{ location, language }`.
 * @param {string} location The stored English name.
 * @return {string} Name ('' when no location was stored).
 */
export function countryName( markets, location ) {
	if ( ! location ) {
		return '';
	}
	const code = Object.keys( markets || {} ).find(
		( c ) =>
			markets[ c ].location.toLowerCase() ===
			String( location ).toLowerCase()
	);
	return code ? regionName( code, location ) : location;
}
