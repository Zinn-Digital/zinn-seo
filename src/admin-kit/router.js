/* Generated from wp/packages/zinn-admin-kit/src/js/router.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * The shell's screen lives in the `view` query argument, so every screen has its own address
 * (bookmarkable, and the browser's back button works) and the host's own hash deep links
 * (`#/engines`) keep working inside its settings screen.
 */

/**
 * @param {string} hashRoute The route a bare `#/…` deep link belongs to (the host's own screen),
 *                           so a link written before the shell existed still opens that screen.
 * @return {string} The requested view, or an empty string.
 */
export function requestedView( hashRoute = '' ) {
	const view = new URLSearchParams( window.location.search ).get( 'view' );
	if ( view ) {
		return view;
	}
	return hashRoute && window.location.hash.startsWith( '#/' )
		? hashRoute
		: '';
}

/**
 * Which route to show.
 *
 * @param {string}        requested The `view` argument.
 * @param {Array<Object>} routes    Routes, each with an `id`.
 * @param {string}        wizard    Wizard state: `new`, `done` or `skipped`.
 * @return {string} A route id that exists.
 */
export function resolveView( requested, routes, wizard ) {
	const ids = routes.map( ( route ) => route.id );
	if ( requested && ids.includes( requested ) ) {
		return requested;
	}
	if ( 'new' === wizard && ids.includes( 'setup' ) ) {
		return 'setup';
	}
	return ids.includes( 'overview' ) ? 'overview' : ids[ 0 ] || '';
}

/**
 * The address of a view.
 *
 * @param {string} view Route id.
 * @param {string} href Current address.
 * @return {string} Address.
 */
export function viewHref( view, href = window.location.href ) {
	const url = new URL( href );
	url.searchParams.set( 'view', view );
	url.hash = '';
	return url.toString();
}

/**
 * The current view, and a function to change it.
 *
 * @param {Array<Object>} routes    Routes.
 * @param {string}        wizard    Wizard state.
 * @param {string}        hashRoute See requestedView().
 * @return {Array} `[ view, navigate ]`.
 */
export function useView( routes, wizard, hashRoute = '' ) {
	const [ view, setView ] = useState( () =>
		resolveView( requestedView( hashRoute ), routes, wizard )
	);

	useEffect( () => {
		const onPop = () =>
			setView(
				resolveView( requestedView( hashRoute ), routes, wizard )
			);
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ routes, wizard, hashRoute ] );

	const navigate = useCallback( ( next ) => {
		window.history.pushState( { view: next }, '', viewHref( next ) );
		setView( next );
		window.scrollTo( 0, 0 );
	}, [] );

	return [ view, navigate ];
}
