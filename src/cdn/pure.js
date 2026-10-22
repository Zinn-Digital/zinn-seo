/**
 * Pure helpers of the CDN screen.
 */
import { __ } from '@wordpress/i18n';

import { readerLocale } from '../tools/pure';

/**
 * Bytes as gigabytes (decimal, as the CDN bills them).
 *
 * @param {number} bytes  Bytes.
 * @param {string} locale Reader's locale.
 * @return {string} e.g. "1.25 GB".
 */
export function gigabytes( bytes, locale = readerLocale() ) {
	const n = Number( bytes ) || 0;
	const gb = n / 1e9;
	let text;
	try {
		text = new Intl.NumberFormat( locale, {
			maximumFractionDigits: gb < 10 ? 3 : 1,
		} ).format( gb );
	} catch {
		text = gb.toFixed( 3 );
	}
	/* translators: the unit after a number of gigabytes, e.g. "1.25 GB". */
	return `${ text } ${ __( 'GB', 'zinn-seo' ) }`;
}

/**
 * The CDN's state in words.
 *
 * @param {string} state `active` | `paused_credit` | `paused_standing` | `off` | `removed` | ''.
 * @return {string} Words.
 */
export function stateWords( state ) {
	switch ( state ) {
		case 'active':
			return __( 'On — your files are served from the CDN.', 'zinn-seo' );
		case 'paused_credit':
			return __(
				'Paused: your Zinn® credit ran out. Your site works as before; add credit and the CDN comes back within the hour.',
				'zinn-seo'
			);
		case 'paused_standing':
			return __(
				'Paused: your Zinn® account has an unpaid bill. Your site works as before; pay it and the CDN comes back within the hour.',
				'zinn-seo'
			);
		case 'off':
			return __(
				'Off. Your site serves its own files again.',
				'zinn-seo'
			);
		default:
			return __( 'Off.', 'zinn-seo' );
	}
}
