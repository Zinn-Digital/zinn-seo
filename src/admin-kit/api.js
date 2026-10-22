/* Generated from wp/packages/zinn-admin-kit/src/js/api.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import apiFetch from '@wordpress/api-fetch';

/**
 * Call one of the kit's own REST routes (`/<host namespace>/kit/<path>`). The browser only ever
 * talks to this WordPress site; the site talks to Zinn Digital from the server.
 *
 * @param {Object} kit     Boot data (`window.zinnAdminKit[slug]`).
 * @param {string} path    Route under `kit/`, e.g. `support/ticket`.
 * @param {Object} options apiFetch options (method, data).
 * @return {Promise<*>} The response body.
 */
export function kitFetch( kit, path, options = {} ) {
	return apiFetch( {
		path: `/${ kit.restNamespace }/kit/${ path }`,
		...options,
	} );
}

/**
 * The message to show for a failed call: our route's own sentence when it sent one.
 *
 * @param {*}      error    What apiFetch rejected with.
 * @param {string} fallback Sentence to use otherwise.
 * @return {string} A sentence for the screen.
 */
export function errorMessage( error, fallback ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return fallback;
}
