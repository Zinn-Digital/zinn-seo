import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';

import { errorText, markStep, seoFetch } from './api';
import { IdentityFields, SiteTypeFields } from './SiteFields';
import { FootprintPanel, OtherPlugins, VisibilityPanel } from './Panels';
import { AppearanceFields, SocialFields } from './StepFields';
import DiscoveryFields from './discovery/StepDiscovery';
import { TransferPanel } from './ToolsScreen';

/**
 * A step whose answers are settings: the fields edit a draft, and Next saves the draft and marks
 * the step done. A save that fails keeps the person on the step with the reason.
 *
 * @param {Object}   props
 * @param {Function} props.Fields   The fields component (`value`, `onChange`, `found`).
 * @param {Object}   props.found    What the setup detected.
 * @param {Object}   props.defaults Values to save when the person changed nothing.
 * @param {Object}   props.handle   `{ save }`, set by this component so the step's onNext can call it.
 * @return {Element} The step body.
 */
function SettingsStep( { Fields, found, defaults, handle } ) {
	const [ settings, setSettings ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		seoFetch( '/settings' )
			.then( ( r ) => setSettings( r.settings ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );

	// The step's onNext saves whatever is on screen now.
	handle.save = () => {
		const changes = { ...defaults( settings || {} ), ...draft };
		return seoFetch( '/settings', {
			method: 'PUT',
			data: { settings: changes },
		} ).catch( ( e ) => {
			setError( errorText( e ) );
			throw e;
		} );
	};

	if ( ! settings ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	return (
		<>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<Fields
				value={ { ...settings, ...draft } }
				onChange={ ( part ) => setDraft( { ...draft, ...part } ) }
				found={ found }
			/>
		</>
	);
}

/**
 * The first step: the site's kind, or start from another site's settings (owner, 2026-10-15). The
 * import is the Tools screen's own panel (preview, then apply), so there is one import path; once
 * applied, the step's fields are read again so Next saves the imported answer, not the old one.
 *
 * @param {Object} props
 * @param {Object} props.found  What the setup detected.
 * @param {Object} props.handle `{ save }` for the step's onNext.
 * @return {Element} The step body.
 */
function SiteStep( { found, handle } ) {
	const [ round, setRound ] = useState( 0 );
	return (
		<>
			<SettingsStep
				key={ round }
				Fields={ SiteTypeFields }
				found={ found }
				defaults={ ( s ) => ( {
					site_type: s.site_type || found.site_type || 'other',
				} ) }
				handle={ handle }
			/>
			<details className="zseo-setup-import">
				<summary>
					{ __(
						"Start from another site's settings (import a settings file)",
						'zinn-seo'
					) }
				</summary>
				<TransferPanel
					mode="import"
					onApplied={ () => setRound( round + 1 ) }
				/>
			</details>
		</>
	);
}

/**
 * The guided setup's steps for the admin kit's wizard (zs-240, zs-241). Each step is stored on the
 * server as done or skipped, so the wizard opens at the first step not done, and every step has
 * its own "Skip this step".
 *
 * @param {Object} data Boot data (`setup`, `isAdmin`, `readingUrl`).
 * @return {Array<Object>} Steps: `{ id, title, done, render, onNext, onSkip }`.
 */
export default function setupSteps( data ) {
	const setup = data.setup || { steps: [], found: {} };
	const found = setup.found || {};
	const status = {};
	( setup.steps || [] ).forEach( ( s ) => {
		status[ s.id ] = s.status;
	} );
	const answered = ( id ) =>
		'done' === status[ id ] || 'skipped' === status[ id ];
	const handles = {
		site: {},
		identity: {},
		appearance: {},
		social: {},
		discovery: {},
	};
	const has = ( id ) => ( setup.steps || [] ).some( ( st ) => st.id === id );
	const skip = ( id ) => () => markStep( id, 'skip' );

	return [
		{
			id: 'site',
			title: __( 'Your site', 'zinn-seo' ),
			done: answered( 'site' ),
			render: () => <SiteStep found={ found } handle={ handles.site } />,
			onNext: () =>
				handles.site.save().then( () => markStep( 'site', 'done' ) ),
			onSkip: skip( 'site' ),
		},
		{
			id: 'identity',
			title: __( 'Who is behind it', 'zinn-seo' ),
			done: answered( 'identity' ),
			render: () => (
				<SettingsStep
					Fields={ IdentityFields }
					found={ found }
					defaults={ ( s ) => ( {
						identity_name: s.identity_name || found.site_name || '',
						identity_logo: s.identity_logo || found.logo || 0,
					} ) }
					handle={ handles.identity }
				/>
			),
			onNext: () =>
				handles.identity
					.save()
					.then( () => markStep( 'identity', 'done' ) ),
			onSkip: skip( 'identity' ),
		},
		{
			id: 'plugins',
			title: __( 'Other SEO plugins', 'zinn-seo' ),
			done: answered( 'plugins' ),
			render: () => <OtherPlugins plugins={ found.seo_plugins } />,
			onNext: () => markStep( 'plugins', 'done' ),
			onSkip: skip( 'plugins' ),
		},
		{
			id: 'visibility',
			title: __( 'Search engines', 'zinn-seo' ),
			done: answered( 'visibility' ),
			render: () => <VisibilityStep data={ data } found={ found } />,
			onNext: () => markStep( 'visibility', 'done' ),
			onSkip: skip( 'visibility' ),
		},
		{
			id: 'privacy',
			title: __( 'Footprint', 'zinn-seo' ),
			done: answered( 'privacy' ),
			render: () => <FootprintPanel />,
			onNext: () => markStep( 'privacy', 'done' ),
			onSkip: skip( 'privacy' ),
		},
		...( has( 'appearance' )
			? [
					{
						id: 'appearance',
						title: __( 'Titles in search results', 'zinn-seo' ),
						done: answered( 'appearance' ),
						render: () => (
							<SettingsStep
								Fields={ AppearanceFields }
								found={ found }
								defaults={ ( st ) => ( {
									sep: st.sep || '–',
								} ) }
								handle={ handles.appearance }
							/>
						),
						onNext: () =>
							handles.appearance
								.save()
								.then( () => markStep( 'appearance', 'done' ) ),
						onSkip: skip( 'appearance' ),
					},
				]
			: [] ),
		...( has( 'social' )
			? [
					{
						id: 'social',
						title: __( 'Sharing on social networks', 'zinn-seo' ),
						done: answered( 'social' ),
						render: () => (
							<SettingsStep
								Fields={ SocialFields }
								found={ found }
								defaults={ () => ( {} ) }
								handle={ handles.social }
							/>
						),
						onNext: () =>
							handles.social
								.save()
								.then( () => markStep( 'social', 'done' ) ),
						onSkip: skip( 'social' ),
					},
				]
			: [] ),
		...( has( 'discovery' )
			? [
					{
						id: 'discovery',
						title: __( 'Sitemaps and AI crawlers', 'zinn-seo' ),
						done: answered( 'discovery' ),
						render: () => (
							<SettingsStep
								Fields={ DiscoveryFields }
								found={ found }
								defaults={ () => ( {} ) }
								handle={ handles.discovery }
							/>
						),
						onNext: () =>
							handles.discovery
								.save()
								.then( () => markStep( 'discovery', 'done' ) ),
						onSkip: skip( 'discovery' ),
					},
				]
			: [] ),
	];
}

/**
 * The search engines step (its own state: the switch saves at once).
 *
 * @param {Object} props
 * @param {Object} props.data  Boot data.
 * @param {Object} props.found What the setup detected.
 * @return {Element} The step body.
 */
function VisibilityStep( { data, found } ) {
	const [ discouraged, setDiscouraged ] = useState( !! found.discouraged );
	return (
		<VisibilityPanel
			discouraged={ discouraged }
			isAdmin={ !! data.isAdmin }
			readingUrl={ data.readingUrl }
			onChange={ setDiscouraged }
		/>
	);
}
