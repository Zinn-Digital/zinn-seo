/**
 * The indexing console without React.
 */
import { __ } from '@wordpress/i18n';

/**
 * Addresses from a text box: one per line, http(s) only, no repeats.
 *
 * @param {string} text Text.
 * @return {Array<string>} Addresses.
 */
export function urlsFrom( text ) {
	const out = [];
	String( text || '' )
		.split( /\r?\n/ )
		.map( ( l ) => l.trim() )
		.forEach( ( line ) => {
			if ( /^https?:\/\/\S+$/i.test( line ) && ! out.includes( line ) ) {
				out.push( line );
			}
		} );
	return out;
}

/**
 * The log's query string (empty filters left out).
 *
 * @param {Object} filter `{engine, state, search, page}`.
 * @return {string} Query.
 */
export function query( filter ) {
	const params = new URLSearchParams();
	Object.keys( filter || {} ).forEach( ( k ) => {
		const v = filter[ k ];
		if ( v !== '' && v !== null && v !== undefined ) {
			params.set( k, String( v ) );
		}
	} );
	return params.toString();
}

/**
 * A search engine's name.
 *
 * @param {string} engine Engine id.
 * @return {string} Name.
 */
export function engineLabel( engine ) {
	switch ( engine ) {
		case 'indexnow':
			return __(
				'IndexNow search engines: Bing, Yandex, Seznam and Naver',
				'zinn-seo'
			);
		case 'bing':
			return __( 'Bing (your Webmaster Tools key)', 'zinn-seo' );
		case 'google':
			return __( 'Google (job and livestream pages)', 'zinn-seo' );
		default:
			return engine;
	}
}

/**
 * A result's name.
 *
 * @param {string} state State.
 * @return {string} Name.
 */
export function stateLabel( state ) {
	switch ( state ) {
		case 'sent':
			return __( 'Accepted', 'zinn-seo' );
		case 'failed':
			return __( 'Failed', 'zinn-seo' );
		case 'skipped':
			return __( 'Not sent', 'zinn-seo' );
		case 'retried':
			return __( 'Sent again', 'zinn-seo' );
		default:
			return state;
	}
}

/**
 * The badge colour of a result.
 *
 * @param {string} state State.
 * @return {string} `success`, `error` or `info`.
 */
export function badge( state ) {
	if ( state === 'sent' ) {
		return 'success';
	}
	return state === 'failed' ? 'error' : 'info';
}
