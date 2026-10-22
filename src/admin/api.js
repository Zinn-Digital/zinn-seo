import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Call one of the plugin's own REST routes (`/zinn-seo/v1/…`). The screens use exactly the routes
 * an integration or an AI agent uses, so they can never disagree.
 *
 * @param {string} path    Route under the namespace, e.g. `/settings`.
 * @param {Object} options apiFetch options (method, data).
 * @return {Promise<*>} The response body.
 */
export function seoFetch( path, options = {} ) {
	const base =
		( window.zinnSeoAdmin && window.zinnSeoAdmin.restPath ) ||
		'/zinn-seo/v1';
	return apiFetch( { path: `${ base }${ path }`, ...options } );
}

/**
 * The sentence to show for a failed call: the route's own message when it sent one.
 *
 * @param {*} error What apiFetch rejected with.
 * @return {string} A sentence.
 */
export function errorText( error ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return __( 'Something went wrong. Please try again.', 'zinn-seo' );
}

/**
 * The site-wide settings, loaded once, and a function that saves a change.
 *
 * @return {{settings: Object|null, error: string, save: Function}} State.
 */
export function useSettings() {
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		seoFetch( '/settings' )
			.then( ( result ) => setSettings( result.settings ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );

	const save = useCallback(
		( changes ) =>
			seoFetch( '/settings', {
				method: 'PUT',
				data: { settings: changes },
			} ).then( ( result ) => {
				setSettings( result.settings );
				return result.settings;
			} ),
		[]
	);

	return { settings, setSettings, error, save };
}

/**
 * Mark a setup step done or skipped.
 *
 * @param {string} step   Step id.
 * @param {string} action `done` or `skip`.
 * @return {Promise<Object>} The setup state.
 */
export function markStep( step, action ) {
	return seoFetch( '/setup', { method: 'PUT', data: { step, action } } );
}
