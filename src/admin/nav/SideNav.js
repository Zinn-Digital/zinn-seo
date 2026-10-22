import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { viewHref } from '../../admin-kit/router';
import { locate } from './sections';

/** Below this width WordPress's own admin turns its menu into a drawer, and so does this one. */
export const MOBILE_QUERY = '(max-width: 782px)';
const STORE = 'zseo.nav.open';

/**
 * The sections the reader opened last time (this browser only; nothing is saved on the server).
 *
 * @return {Array<string>|null} Section ids, or null when nothing was kept.
 */
function keptOpen() {
	try {
		const kept = JSON.parse(
			window.localStorage.getItem( STORE ) || 'null'
		);
		return Array.isArray( kept ) ? kept.map( String ) : null;
	} catch {
		return null;
	}
}

/**
 * @param {Array<string>} ids Section ids to keep open.
 */
function keepOpen( ids ) {
	try {
		window.localStorage.setItem( STORE, JSON.stringify( ids ) );
	} catch {
		// Private windows and blocked storage: the sections simply start closed next time.
	}
}

/**
 * Whether the screen is phone- or tablet-sized.
 *
 * @return {boolean} True below WordPress's own mobile width.
 */
function useMobile() {
	const query = useRef(
		typeof window.matchMedia === 'function'
			? window.matchMedia( MOBILE_QUERY )
			: null
	);
	const [ mobile, setMobile ] = useState(
		() => !! ( query.current && query.current.matches )
	);
	useEffect( () => {
		const q = query.current;
		if ( ! q ) {
			return undefined;
		}
		const change = () => setMobile( q.matches );
		q.addEventListener( 'change', change );
		return () => q.removeEventListener( 'change', change );
	}, [] );
	return mobile;
}

/**
 * The plugin's side navigation (UI1): its screens in sections, each section opening and closing;
 * on a phone or tablet a drawer opened from a bar that names the current screen.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.sections From arrange().
 * @param {string}        props.current  The screen on show.
 * @param {Function}      props.go       Open a screen by id.
 * @param {string}        props.guide    This screen's guide on zinnseo.com ('' for none).
 * @return {Element} The navigation.
 */
export default function SideNav( { sections, current, go, guide = '' } ) {
	const mobile = useMobile();
	const here = locate( sections, current );
	const [ open, setOpen ] = useState( () => {
		const kept = keptOpen();
		return new Set( kept || ( here ? [ here.section.id ] : [] ) );
	} );
	const [ drawer, setDrawer ] = useState( false );
	const panel = useRef( null );
	const toggle = useRef( null );

	// The section of the screen on show is always open, however the reader left it.
	const isOpen = ( id ) =>
		open.has( id ) || ( here && here.section.id === id );

	const flip = ( id ) => {
		const next = new Set( open );
		if ( isOpen( id ) ) {
			next.delete( id );
		} else {
			next.add( id );
		}
		setOpen( next );
		keepOpen( [ ...next ] );
	};

	const close = useCallback( ( focusToggle = true ) => {
		setDrawer( false );
		if ( focusToggle && toggle.current ) {
			toggle.current.focus();
		}
	}, [] );

	// The drawer: the page behind it does not scroll, Escape closes it, Tab stays inside it, and
	// it opens with the current screen's link focused.
	useEffect( () => {
		if ( ! mobile || ! drawer ) {
			return undefined;
		}
		const doc = document.documentElement;
		doc.classList.add( 'zseo-nav-locked' );
		const box = panel.current;
		const focusable = () =>
			box
				? [ ...box.querySelectorAll( 'a[href], button' ) ].filter(
						( el ) => ! el.closest( '[hidden]' )
					)
				: [];
		const first = box && box.querySelector( '[aria-current="page"]' );
		( first || focusable()[ 0 ] || box )?.focus();
		const owner = box ? box.ownerDocument : document;
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				close();
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			const list = focusable();
			if ( ! list.length ) {
				return;
			}
			const last = list[ list.length - 1 ];
			if ( e.shiftKey && owner.activeElement === list[ 0 ] ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && owner.activeElement === last ) {
				e.preventDefault();
				list[ 0 ].focus();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => {
			doc.classList.remove( 'zseo-nav-locked' );
			document.removeEventListener( 'keydown', onKey );
		};
	}, [ mobile, drawer, close ] );

	// The current screen's entry is kept in view inside a long navigation (desktop: the panel
	// scrolls on its own; the page itself is never scrolled for it).
	useEffect( () => {
		const box = panel.current;
		const link = box && box.querySelector( '[aria-current="page"]' );
		if ( ! link || box.scrollHeight <= box.clientHeight ) {
			return;
		}
		const top =
			link.getBoundingClientRect().top -
			box.getBoundingClientRect().top +
			box.scrollTop;
		if (
			top < box.scrollTop ||
			top + link.offsetHeight > box.scrollTop + box.clientHeight
		) {
			box.scrollTop = Math.max( 0, top - box.clientHeight / 3 );
		}
	}, [ current, drawer ] );

	// Leaving phone width with the drawer open closes it.
	useEffect( () => {
		if ( ! mobile ) {
			setDrawer( false );
		}
	}, [ mobile ] );

	const follow = ( e, id ) => {
		// A new tab or window keeps the browser's own behaviour.
		if (
			e.defaultPrevented ||
			e.button !== 0 ||
			e.metaKey ||
			e.ctrlKey ||
			e.shiftKey ||
			e.altKey
		) {
			return;
		}
		e.preventDefault();
		go( id );
		if ( mobile ) {
			close( false );
			const main = document.getElementById( 'zseo-screen' );
			if ( main ) {
				main.focus( { preventScroll: true } );
			}
		}
	};

	const skip = ( e ) => {
		const main = document.getElementById( 'zseo-screen' );
		if ( main ) {
			e.preventDefault();
			main.focus();
			main.scrollIntoView( { block: 'start' } );
		}
	};

	const where = here
		? `${ here.section.label } › ${ here.item.label }`
		: __( 'Menu', 'zinn-seo' );

	return (
		<div
			className={ `zseo-nav${ drawer ? ' is-open' : '' }${
				mobile ? ' is-mobile' : ''
			}` }
		>
			<a className="zseo-nav__skip" href="#zseo-screen" onClick={ skip }>
				{ __( 'Skip to the screen', 'zinn-seo' ) }
			</a>
			<button
				ref={ toggle }
				type="button"
				className="zseo-nav__toggle"
				aria-expanded={ drawer }
				aria-controls="zseo-nav-panel"
				onClick={ () => setDrawer( ! drawer ) }
			>
				<span
					className="dashicons dashicons-menu-alt"
					aria-hidden="true"
				/>
				<span className="zseo-nav__toggle-text">
					<span className="screen-reader-text">
						{ __( 'Menu', 'zinn-seo' ) }:{ ' ' }
					</span>
					{ where }
				</span>
			</button>
			{ /* A click target behind the drawer, out of the tab order and hidden from screen readers:
				Escape and the close button do the same for them. */ }
			<button
				type="button"
				className="zseo-nav__backdrop"
				tabIndex={ -1 }
				aria-hidden="true"
				onClick={ () => close() }
			/>
			<div
				ref={ panel }
				id="zseo-nav-panel"
				className="zseo-nav__panel"
				role={ mobile ? 'dialog' : undefined }
				aria-modal={ mobile && drawer ? 'true' : undefined }
				aria-label={ mobile ? __( 'Menu', 'zinn-seo' ) : undefined }
				tabIndex={ -1 }
				data-zak-tour="nav"
			>
				<div className="zseo-nav__panel-head">
					<span className="zseo-nav__panel-title">
						{ __( 'Menu', 'zinn-seo' ) }
					</span>
					<button
						type="button"
						className="zseo-nav__close"
						onClick={ () => close() }
					>
						<span
							className="dashicons dashicons-no-alt"
							aria-hidden="true"
						/>
						<span className="screen-reader-text">
							{ __( 'Close the menu', 'zinn-seo' ) }
						</span>
					</button>
				</div>
				<nav aria-label={ __( 'Plugin screens', 'zinn-seo' ) }>
					<ul className="zseo-nav__sections">
						{ sections.map( ( section ) => {
							const shown = isOpen( section.id );
							const listId = `zseo-nav-${ section.id }`;
							const holdsCurrent =
								here && here.section.id === section.id;
							return (
								<li
									key={ section.id }
									className={ `zseo-nav__section${
										holdsCurrent ? ' has-current' : ''
									}` }
								>
									<button
										type="button"
										className="zseo-nav__heading"
										data-zseo-section={ section.id }
										aria-expanded={ shown }
										aria-controls={ listId }
										onClick={ () => flip( section.id ) }
									>
										<span className="zseo-nav__heading-text">
											{ section.label }
										</span>
										<span
											className="zseo-nav__chevron"
											aria-hidden="true"
										/>
									</button>
									<ul
										id={ listId }
										className="zseo-nav__items"
										hidden={ ! shown }
									>
										{ section.items.map( ( item ) => (
											<li key={ item.id }>
												<a
													href={ viewHref( item.id ) }
													className={ `zseo-nav__item${
														item.id === current
															? ' is-current'
															: ''
													}` }
													aria-current={
														item.id === current
															? 'page'
															: undefined
													}
													data-zseo-nav={ item.id }
													onClick={ ( e ) =>
														follow( e, item.id )
													}
												>
													<span
														className={ `zseo-nav__icon dashicons dashicons-${ item.icon }` }
														aria-hidden="true"
													/>
													<span className="zseo-nav__label">
														{ item.label }
													</span>
													{ item.badge && (
														<span className="zseo-nav__badge">
															<span aria-hidden="true">
																{ item.badge.count.toLocaleString() }
															</span>
															<span className="screen-reader-text">
																{
																	item.badge
																		.label
																}
															</span>
														</span>
													) }
													{ item.pro && (
														<span className="zseo-nav__pro">
															{ __(
																'Pro',
																'zinn-seo'
															) }
														</span>
													) }
												</a>
											</li>
										) ) }
									</ul>
								</li>
							);
						} ) }
					</ul>
				</nav>
				{ /* Q270 (DOC1, owner 2026-10-15): every screen shows where its guide is, not only
				   behind the Help menu — the same words and address as Help → Guide to this screen. */ }
				{ guide && (
					<p className="zseo-nav__guide">
						<a
							href={ guide }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Guide to this screen', 'zinn-seo' ) }
							<span className="screen-reader-text">
								{ __( '(opens in a new tab)', 'zinn-seo' ) }
							</span>
						</a>
					</p>
				) }
			</div>
		</div>
	);
}
