/**
 * Pure helpers of the Redirects and Protection screens (unit-tested).
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The status codes a rule may use, with their names.
 *
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function codeChoices() {
	return [
		{ value: '301', label: __( '301 Moved permanently', 'zinn-seo' ) },
		{ value: '302', label: __( '302 Found (temporary)', 'zinn-seo' ) },
		{
			value: '307',
			label: __( '307 Temporary (keeps the method)', 'zinn-seo' ),
		},
		{
			value: '308',
			label: __( '308 Permanent (keeps the method)', 'zinn-seo' ),
		},
		{
			value: '410',
			label: __( '410 Gone (deleted on purpose)', 'zinn-seo' ),
		},
		{
			value: '451',
			label: __( '451 Unavailable for legal reasons', 'zinn-seo' ),
		},
	];
}

/**
 * Does a code send the visitor somewhere (and so need a target)?
 *
 * @param {number|string} code Code.
 * @return {boolean} Moves.
 */
export function moves( code ) {
	return [ 301, 302, 307, 308 ].includes( Number( code ) );
}

/**
 * Check a rule in the browser before it is sent (the server checks again).
 *
 * @param {Object} rule Rule (source, target, code, regex).
 * @return {string} '' when fine, else the reason.
 */
export function ruleProblem( rule ) {
	const source = ( rule.source || '' ).trim();
	if ( ! source ) {
		return __( 'Enter the address to redirect from.', 'zinn-seo' );
	}
	// A pattern is checked by the server (PHP's PCRE accepts more than JavaScript's RegExp).
	if ( moves( rule.code ) && ! ( rule.target || '' ).trim() ) {
		return __( 'Enter the address to send visitors to.', 'zinn-seo' );
	}
	if (
		moves( rule.code ) &&
		/^[a-z][a-z0-9+.-]*:/i.test( rule.target.trim() ) &&
		! /^https?:\/\//i.test( rule.target.trim() )
	) {
		return __(
			'The target must be an address starting with http:// or https://, or a path on this site.',
			'zinn-seo'
		);
	}
	return '';
}

/**
 * The words for a rule's origin.
 *
 * @param {string} origin Origin as stored.
 * @return {string} Words.
 */
export function originLabel( origin ) {
	const o = origin || '';
	const map = {
		manual: __( 'Added here', 'zinn-seo' ),
		auto: __( 'Automatic', 'zinn-seo' ),
		migration: __( 'Address change', 'zinn-seo' ),
		404: __( 'From the 404 list', 'zinn-seo' ),
		csv: __( 'Spreadsheet', 'zinn-seo' ),
		cli: __( 'Command line', 'zinn-seo' ),
		'import-server': __( 'Server rules', 'zinn-seo' ),
	};
	if ( map[ o ] ) {
		return map[ o ];
	}
	if ( o.startsWith( 'import-' ) ) {
		/* translators: %s: another plugin's id, e.g. redirection. */
		return sprintf( __( 'Imported (%s)', 'zinn-seo' ), o.slice( 7 ) );
	}
	return o;
}

/**
 * The words for a problem flag.
 *
 * @param {string} problem Problem.
 * @return {string} Words.
 */
export function problemLabel( problem ) {
	return (
		{
			chain: __( 'Goes through another redirect', 'zinn-seo' ),
			loop: __( 'Goes round in a circle', 'zinn-seo' ),
			missing: __( 'Target is missing', 'zinn-seo' ),
			shadows: __( 'Hides a live page', 'zinn-seo' ),
		}[ problem ] || ''
	);
}

/**
 * A date-time from the server (UTC, `Y-m-d H:i:s`) in the viewer's words.
 *
 * @param {string} value Value.
 * @return {string} Words ('' for none).
 */
export function when( value ) {
	if ( ! value || value.startsWith( '0000' ) ) {
		return '';
	}
	const d = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return isNaN( d.getTime() ) ? value : d.toLocaleString();
}

/**
 * Common permalink structures to pick from.
 *
 * @return {Array<{value: string, label: string}>} Structures.
 */
export function structures() {
	return [
		{ value: '/%postname%/', label: __( 'Post name', 'zinn-seo' ) },
		{
			value: '/%category%/%postname%/',
			label: __( 'Category and post name', 'zinn-seo' ),
		},
		{
			value: '/%year%/%monthnum%/%postname%/',
			label: __( 'Month and name', 'zinn-seo' ),
		},
		{
			value: '/%year%/%monthnum%/%day%/%postname%/',
			label: __( 'Day and name', 'zinn-seo' ),
		},
		{ value: '/archives/%post_id%', label: __( 'Numeric', 'zinn-seo' ) },
	];
}

/**
 * Split the alias text box into host names.
 *
 * @param {string} text One host per line (or comma-separated).
 * @return {Array<string>} Hosts.
 */
export function hosts( text ) {
	return Array.from(
		new Set(
			( text || '' )
				.split( /[\s,]+/ )
				.map( ( h ) =>
					h
						.trim()
						.toLowerCase()
						.replace( /^https?:\/\//, '' )
						.replace( /\/.*$/, '' )
				)
				.filter( ( h ) => /^[a-z0-9.-]+\.[a-z0-9-]+$/.test( h ) )
		)
	);
}
