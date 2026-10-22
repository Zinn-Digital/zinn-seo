/* Generated from wp/packages/zinn-admin-kit/src/js/index.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { createRoot } from '@wordpress/element';

import Shell from './Shell';
import './kit.scss';

/**
 * Mount the admin shell on the plugin's admin page.
 *
 * @param {Element}       element    The mount point.
 * @param {string}        slug       The host plugin slug (keys `window.zinnAdminKit`).
 * @param {Array<Object>} hostRoutes The host's own screens: `{ id, label, render, tour }`.
 * @param {Array<Object>} wizard     The host's setup steps: `{ id, title, render }`.
 * @return {boolean} Whether it mounted (false when the boot data is missing).
 */
export function mountAdminKit( element, slug, hostRoutes = [], wizard = [] ) {
	const kit = window.zinnAdminKit && window.zinnAdminKit[ slug ];
	if ( ! element || ! kit ) {
		return false;
	}
	createRoot( element ).render(
		<Shell kit={ kit } hostRoutes={ hostRoutes } wizard={ wizard } />
	);
	return true;
}

export { default as Shell } from './Shell';
