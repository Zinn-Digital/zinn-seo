/**
 * The screens an agency can give a client login, by id (the same ids as the routes).
 */
import { __ } from '@wordpress/i18n';

/**
 * Screen id → its name.
 *
 * @return {Object<string, string>} Names.
 */
export function screenNames() {
	return {
		fixit: __( 'Fix it: what to fix across the site', 'zinn-seo' ),
		content: __( 'Content scores', 'zinn-seo' ),
		links: __( 'Links', 'zinn-seo' ),
		search: __( 'Search performance from Google', 'zinn-seo' ),
		checks: __( 'Rankings and checks', 'zinn-seo' ),
		reports: __( 'Client reports', 'zinn-seo' ),
		tasks: __( 'Tasks', 'zinn-seo' ),
	};
}

/**
 * The routes a client login gets: only the allowed ones, in the usual order.
 *
 * @param {Array<Object>} routes All the host routes.
 * @param {Object|null}   client `{screens}` for a client login, or null.
 * @return {Array<Object>} Routes.
 */
export function clientRoutes( routes, client ) {
	if ( ! client ) {
		return routes;
	}
	const allowed = new Set( client.screens || [] );
	return routes.filter( ( r ) => allowed.has( r.id ) );
}
