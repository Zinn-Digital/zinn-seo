/* Generated from wp/packages/zinn-admin-kit/src/js/theme.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { useEffect, useState } from '@wordpress/element';

/**
 * Light or dark. `auto` follows the operating system; the accent colour always follows the
 * user's WordPress admin colour scheme (the shell reads it from the boot data).
 *
 * @param {string}  preference `auto`, `light` or `dark`.
 * @param {boolean} systemDark Whether the operating system prefers dark.
 * @return {string} `light` or `dark`.
 */
export function resolveMode( preference, systemDark ) {
	if ( 'dark' === preference ) {
		return 'dark';
	}
	if ( 'light' === preference ) {
		return 'light';
	}
	return systemDark ? 'dark' : 'light';
}

/**
 * @return {MediaQueryList|null} The dark-scheme query, where the browser has one.
 */
function darkQuery() {
	return typeof window !== 'undefined' && window.matchMedia
		? window.matchMedia( '(prefers-color-scheme: dark)' )
		: null;
}

/**
 * The mode in force, updated when the operating system switches.
 *
 * @param {string} preference The user's choice.
 * @return {string} `light` or `dark`.
 */
export function useColorMode( preference ) {
	const [ systemDark, setSystemDark ] = useState( () => {
		const query = darkQuery();
		return query ? query.matches : false;
	} );

	useEffect( () => {
		const query = darkQuery();
		if ( ! query ) {
			return undefined;
		}
		const onChange = ( event ) => setSystemDark( event.matches );
		query.addEventListener( 'change', onChange );
		return () => query.removeEventListener( 'change', onChange );
	}, [] );

	return resolveMode( preference, systemDark );
}
