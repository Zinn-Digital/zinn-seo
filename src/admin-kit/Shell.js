/* Generated from wp/packages/zinn-admin-kit/src/js/Shell.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __ } from '@wordpress/i18n';
import { useCallback, useMemo, useState } from '@wordpress/element';
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	MenuItemsChoice,
	Notice,
} from '@wordpress/components';

import { kitFetch, errorMessage } from './api';
import { badgeText } from './plans';
import { useView } from './router';
import { useColorMode } from './theme';
import kitTours from './tours';
import Tour from './Tour';
import Overview from './Overview';
import Plans from './PlansScreen';
import Addons from './Addons';
import Support from './Support';
import Wizard from './Wizard';

/**
 * The admin shell every Zinn plugin screen sits in (features adm-1, adm-2, adm-3).
 *
 * The host hands over its own screens as routes (`{ id, label, render, tour, wizard }`); the kit
 * adds Overview, Setup, Plans, Add-ons, Help and Feedback around them. Nothing here is drawn
 * outside the plugin's own admin page: the shell IS that page.
 *
 * @param {Object}        props
 * @param {Object}        props.kit        Boot data (`window.zinnAdminKit[slug]`).
 * @param {Array<Object>} props.hostRoutes The host plugin's own screens.
 * @param {Array<Object>} props.wizard     Host setup steps: `{ id, title, render }`.
 * @return {Element} The shell.
 */
export default function Shell( { kit, hostRoutes = [], wizard = [] } ) {
	const [ prefs, setPrefs ] = useState( kit.prefs || {} );
	const [ wizardState, setWizardState ] = useState(
		( kit.wizard && kit.wizard.state ) || 'new'
	);
	const [ touring, setTouring ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const mode = useColorMode( prefs.theme || 'auto' );
	const tours = useMemo( () => kitTours(), [] );

	// A host may hide the kit's own screens (an agency's white label hides Plans, Add-ons and
	// the help desk; a client login sees only what the agency allowed). Hidden means not drawn
	// and not reachable by `?view=` — the host's server refuses their data as well.
	const hidden = useMemo( () => new Set( kit.hidden || [] ), [ kit.hidden ] );
	const routes = useMemo(
		() =>
			[
				{ id: 'overview', label: __( 'Overview', 'zinn-seo' ) },
				...hostRoutes,
				{ id: 'setup', label: __( 'Setup', 'zinn-seo' ) },
				{
					id: 'plans',
					label: __( 'Plans and licence', 'zinn-seo' ),
				},
				{ id: 'addons', label: __( 'Add-ons', 'zinn-seo' ) },
				{ id: 'help', label: __( 'Get help', 'zinn-seo' ) },
				{ id: 'feedback', label: __( 'Feedback', 'zinn-seo' ) },
			].filter( ( r ) => ! hidden.has( r.id ) ),
		[ hostRoutes, hidden ]
	);
	const brand = kit.brand || null;
	const [ view, navigate ] = useView(
		routes,
		wizardState,
		hostRoutes[ 0 ] ? hostRoutes[ 0 ].id : ''
	);
	const route = routes.find( ( r ) => r.id === view ) || routes[ 0 ];
	const steps = route.tour || tours[ route.id ] || null;
	const guide = ( kit.help.guides || {} )[ route.id ] || kit.help.docsRoot;

	const savePrefs = useCallback(
		( change ) =>
			kitFetch( kit, 'prefs', { method: 'POST', data: change } )
				.then( setPrefs )
				.catch( ( error ) =>
					setNotice(
						errorMessage(
							error,
							__(
								'Your choice could not be saved.',
								'zinn-seo'
							)
						)
					)
				),
		[ kit ]
	);

	const finishWizard = ( state ) =>
		kitFetch( kit, 'wizard', { method: 'POST', data: { state } } )
			.then( ( result ) => {
				setWizardState( result.state );
				navigate( 'overview' );
			} )
			.catch( ( error ) =>
				setNotice(
					errorMessage(
						error,
						__(
							'The setup state could not be saved.',
							'zinn-seo'
						)
					)
				)
			);

	const themeLabels = {
		auto: __( 'Match my computer', 'zinn-seo' ),
		light: __( 'Light', 'zinn-seo' ),
		dark: __( 'Dark', 'zinn-seo' ),
	};

	const body = () => {
		switch ( route.id ) {
			case 'overview':
				return (
					<Overview
						kit={ kit }
						routes={ routes }
						navigate={ navigate }
						prefs={ prefs }
						savePrefs={ savePrefs }
					/>
				);
			case 'setup':
				return (
					<Wizard
						kit={ kit }
						steps={ wizard }
						onDone={ () => finishWizard( 'done' ) }
						onSkip={ () => finishWizard( 'skipped' ) }
						navigate={ navigate }
					/>
				);
			case 'plans':
				return <Plans kit={ kit } />;
			case 'addons':
				return (
					<Addons
						kit={ kit }
						prefs={ prefs }
						savePrefs={ savePrefs }
					/>
				);
			case 'help':
				return <Support kit={ kit } mode="help" />;
			case 'feedback':
				return <Support kit={ kit } mode="feedback" />;
			default:
				return route.render ? (
					<div className="zak-host">{ route.render() }</div>
				) : null;
		}
	};

	return (
		<div
			className={ `zak-shell is-${ mode }${ brand ? ' is-branded' : '' }` }
			data-zak-mode={ mode }
			dir={ kit.rtl ? 'rtl' : 'ltr' }
			style={ brand && brand.colours ? brand.colours : undefined }
		>
			{ /* ⛔ Plain divs, not <header>/<main>: the shell renders INSIDE WordPress admin's own
				main landmark, so a second banner and main here are nested and duplicated landmarks
				(axe, L06 2026-09-28; the kit e2e now scans the whole page). */ }
			<div className="zak-header">
				<div className="zak-header__title">
					{ brand && brand.logo && (
						<img
							className="zak-header__logo"
							src={ brand.logo }
							alt=""
							height="32"
						/>
					) }
					<h1>{ kit.name }</h1>
					{ ! ( brand && brand.plain ) && (
						<>
							<span className="zak-badge" data-zak-tour="status">
								{ badgeText( kit.licence ) }
							</span>
							<span className="zak-version">{ kit.version }</span>
						</>
					) }
				</div>
				<div className="zak-header__tools">
					<span data-zak-tour="theme">
						<DropdownMenu
							icon="admin-appearance"
							label={ __( 'Colour scheme', 'zinn-seo' ) }
						>
							{ ( { onClose } ) => (
								<MenuGroup>
									{ /* ⛔ MenuItemsChoice, not MenuItem + isSelected: the latter sets
										aria-checked but draws NO tick, so the only visible mark was
										the focus ring on the first item — the owner read "Match my
										computer" as chosen while "Light" was (2026-09-28). */ }
									<MenuItemsChoice
										choices={ Object.keys(
											themeLabels
										).map( ( key ) => ( {
											value: key,
											label: themeLabels[ key ],
										} ) ) }
										value={ prefs.theme || 'auto' }
										onSelect={ ( key ) => {
											savePrefs( { theme: key } );
											onClose();
										} }
									/>
								</MenuGroup>
							) }
						</DropdownMenu>
					</span>
					<span data-zak-tour="help">
						<DropdownMenu
							icon="editor-help"
							label={ __( 'Help', 'zinn-seo' ) }
						>
							{ ( { onClose } ) => (
								<MenuGroup>
									<MenuItem
										href={ guide }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ __(
											'Guide to this screen',
											'zinn-seo'
										) }
									</MenuItem>
									{ steps && (
										<MenuItem
											onClick={ () => {
												setTouring( true );
												onClose();
											} }
										>
											{ __(
												'Take the tour',
												'zinn-seo'
											) }
										</MenuItem>
									) }
									{ ! hidden.has( 'help' ) && (
										<MenuItem
											onClick={ () => {
												navigate( 'help' );
												onClose();
											} }
										>
											{ __(
												'Get help or report a bug',
												'zinn-seo'
											) }
										</MenuItem>
									) }
								</MenuGroup>
							) }
						</DropdownMenu>
					</span>
				</div>
			</div>
			<nav
				className="zak-nav"
				aria-label={ __( 'Plugin screens', 'zinn-seo' ) }
				data-zak-tour="nav"
			>
				{ routes.map( ( r ) => (
					<Button
						key={ r.id }
						className="zak-nav__item"
						variant={ r.id === route.id ? 'primary' : 'tertiary' }
						aria-current={ r.id === route.id ? 'page' : undefined }
						onClick={ () => navigate( r.id ) }
					>
						{ r.label }
					</Button>
				) ) }
			</nav>
			{ notice && (
				<Notice status="error" onRemove={ () => setNotice( null ) }>
					{ notice }
				</Notice>
			) }
			<div className="zak-main" data-zak-route={ route.id }>
				{ body() }
			</div>
			{ touring && steps && (
				<Tour
					steps={ steps }
					onClose={ () => {
						setTouring( false );
						savePrefs( { tour: route.id } );
					} }
				/>
			) }
		</div>
	);
}
