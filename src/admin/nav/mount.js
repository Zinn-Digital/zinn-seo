import { createRoot, flushSync } from '@wordpress/element';

import { viewHref } from '../../admin-kit/router';
import SideNav from './SideNav';
import { arrange, kitScreens } from './sections';
import './nav.scss';

/**
 * An address written as `?page=zinn-seo&view=<screen>&tab=<tab>` opens the same screen and tab
 * as the shell's own `?view=<screen>#/<tab>`: the tab becomes the hash before the shell reads it.
 *
 * @param {string} href The address.
 * @return {string|null} The address to use instead, or null when it is fine as it is.
 */
export function normaliseAddress( href ) {
	const url = new URL( href );
	const tab = url.searchParams.get( 'tab' );
	if ( ! tab ) {
		return null;
	}
	url.searchParams.delete( 'tab' );
	if ( ! url.hash || url.hash === '#' ) {
		url.hash = `#/${ tab.replace( /^#?\/?/, '' ) }`;
	}
	return url.toString();
}

/**
 * Open a screen: the same address change the shell makes, then the shell is told (it listens to
 * the browser's history, so Back and Forward keep working).
 *
 * @param {string} id Screen id.
 */
export function openScreen( id ) {
	window.history.pushState( { view: id }, '', viewHref( id ) );
	window.dispatchEvent(
		new window.PopStateEvent( 'popstate', { state: { view: id } } )
	);
	window.scrollTo( 0, 0 );
}

/**
 * Draw the shell with the side navigation beside it (UI1, Q269).
 *
 * The admin kit's shell is shared with the other Zinn plugins, so it is not changed: it is drawn
 * as before (synchronously, so both appear in the same frame and nothing moves), its own row of
 * buttons is hidden by this plugin's stylesheet, and the side navigation is drawn into a slot
 * beside the header. The screen on show is read from the shell itself (`data-zak-route`), so a
 * screen opened from a card, a palette command or the browser's Back button is marked as well.
 *
 * @param {Element}       root   The mount point.
 * @param {Function}      mount  Draws the shell (calls mountAdminKit()).
 * @param {Array<Object>} routes The plugin's own screens, as given to the shell.
 * @param {Object}        kit    The kit's boot data.
 * @param {Object}        badges Screen id → `{ count, label }`.
 * @return {boolean} Whether the side navigation was drawn.
 */
export function mountWithSideNav( root, mount, routes, kit, badges ) {
	const fixed = normaliseAddress( window.location.href );
	if ( fixed ) {
		window.history.replaceState( window.history.state, '', fixed );
	}
	root.classList.add( 'zseo-has-nav' );
	let mounted = false;
	flushSync( () => {
		mounted = mount();
	} );
	const shell = root.querySelector( '.zak-shell' );
	const main = shell && shell.querySelector( '.zak-main' );
	const header = shell && shell.querySelector( '.zak-header' );
	if ( ! mounted || ! main || ! header ) {
		root.classList.remove( 'zseo-has-nav' );
		return false;
	}
	// The skip link's target, focusable by script only.
	main.id = 'zseo-screen';
	main.tabIndex = -1;

	const all = [
		...kitScreens( kit ).filter( ( r ) => r.id === 'overview' ),
		...routes,
		...kitScreens( kit ).filter( ( r ) => r.id !== 'overview' ),
	];
	const sections = arrange( all, badges );
	const slot = document.createElement( 'div' );
	slot.className = 'zseo-nav-slot';
	header.after( slot );
	const navRoot = createRoot( slot );
	const guides = ( kit && kit.help && kit.help.guides ) || {};
	const draw = () => {
		const current = main.getAttribute( 'data-zak-route' ) || '';
		navRoot.render(
			<SideNav
				sections={ sections }
				current={ current }
				go={ openScreen }
				guide={ guides[ current ] || '' }
			/>
		);
	};
	flushSync( draw );
	new window.MutationObserver( draw ).observe( main, {
		attributes: true,
		attributeFilter: [ 'data-zak-route' ],
	} );
	return true;
}
