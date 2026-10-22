import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TabPanel,
	ToggleControl,
} from '@wordpress/components';

import { errorText, useSettings } from './api';
import { IdentityFields, SiteTypeFields } from './SiteFields';
import { FootprintPanel, VisibilityPanel } from './Panels';
import FeaturesTab from './x1a/FeaturesTab';
import VersionTab from './x1a/VersionTab';
import AgentsTab from './mcp/AgentsTab';

/**
 * The tabs, in order. Exported for the command palette, so every tab can be jumped to.
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function settingsTabs() {
	return [
		{ name: 'site', title: __( 'Your site', 'zinn-seo' ) },
		{ name: 'search', title: __( 'Search engines', 'zinn-seo' ) },
		{ name: 'footprint', title: __( 'Footprint', 'zinn-seo' ) },
		{ name: 'data', title: __( 'Data', 'zinn-seo' ) },
		{ name: 'jobs', title: __( 'Jobs', 'zinn-seo' ) },
		{ name: 'alerts', title: __( 'Alert e-mails', 'zinn-seo' ) },
		{ name: 'features', title: __( 'Features', 'zinn-seo' ) },
		{ name: 'agents', title: __( 'AI agents', 'zinn-seo' ) },
		{ name: 'version', title: __( 'Version', 'zinn-seo' ) },
	];
}

/**
 * The hours of a day, as choices ("00:00" … "23:00").
 *
 * @return {Array<{label: string, value: string}>} Choices.
 */
function hourChoices() {
	return Array.from( { length: 24 }, ( _, h ) => ( {
		label: `${ String( h ).padStart( 2, '0' ) }:00`,
		value: String( h ),
	} ) );
}

/**
 * Settings › Jobs: the pace of background jobs and the site's quiet hours (Q337).
 *
 * @param {Object}                    props
 * @param {Object}                    props.value    Settings with the draft applied.
 * @param {(changes: Object) => void} props.onChange Called with changed keys.
 * @param {() => void}                props.onSave   Saves the draft.
 * @param {boolean}                   props.busy     Saving.
 * @param {boolean}                   props.dirty    Unsaved changes.
 * @return {Element} The tab.
 */
export function JobsTab( { value, onChange, onSave, busy, dirty } ) {
	return (
		<div className="zseo-stack">
			<PanelBody title={ __( 'Background jobs', 'zinn-seo' ) }>
				<p>
					{ __(
						'Long tasks (building the link plan, checking links, analysing every page) run in the background in short steps of about five seconds. When your pages start answering slowly or the server is busy, the steps get shorter and your site rests between them. Nothing is skipped: every job still finishes, it just takes longer on a busy site.',
						'zinn-seo'
					) }
				</p>
			</PanelBody>
			<PanelBody title={ __( 'Quiet hours', 'zinn-seo' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Keep background jobs quiet during set hours',
						'zinn-seo'
					) }
					help={ __(
						'During these hours (your site’s time zone) jobs take one short step a minute, so your busiest hours stay fast. They still finish.',
						'zinn-seo'
					) }
					checked={ !! value.jobs_quiet }
					disabled={ busy }
					onChange={ ( on ) => onChange( { jobs_quiet: on } ) }
				/>
				{ !! value.jobs_quiet && (
					<>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'From', 'zinn-seo' ) }
							value={ String( value.jobs_quiet_from ?? 9 ) }
							options={ hourChoices() }
							disabled={ busy }
							onChange={ ( h ) =>
								onChange( { jobs_quiet_from: Number( h ) } )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Until', 'zinn-seo' ) }
							value={ String( value.jobs_quiet_to ?? 17 ) }
							options={ hourChoices() }
							disabled={ busy }
							onChange={ ( h ) =>
								onChange( { jobs_quiet_to: Number( h ) } )
							}
						/>
					</>
				) }
			</PanelBody>
			<PanelBody title={ __( 'Changes to many pages', 'zinn-seo' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Let other plugins react to every page a bulk change saves',
						'zinn-seo'
					) }
					help={ __(
						'Off (best for big sites): when the link plan, Fix it on autopilot or image text change many pages, each page is saved without other plugins’ save actions, so they do not queue work (translations, search indexes, AI) for every page. Turn it on if another plugin must update its own copy each time a page changes. Either way the jobs slow down when other plugins queue work.',
						'zinn-seo'
					) }
					checked={ !! value.jobs_full_saves }
					disabled={ busy }
					onChange={ ( on ) => onChange( { jobs_full_saves: on } ) }
				/>
				<p className="zak-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! dirty }
						onClick={ onSave }
					>
						{ __( 'Save changes', 'zinn-seo' ) }
					</Button>
				</p>
			</PanelBody>
		</div>
	);
}

/**
 * The kind a mute link from an e-mail just stopped ('' none): `?zseo_muted=<kind>`.
 *
 * @param {string} search `window.location.search`.
 * @return {string} Kind.
 */
export function mutedByLink( search ) {
	const m = /[?&]zseo_muted=([a-z0-9_]+)/.exec( search || '' );
	return m ? m[ 1 ] : '';
}

/**
 * Settings › Alert e-mails (Q356): how often a repeated alert is e-mailed, and which kinds of
 * e-mail this site sends at all (each e-mail's footer links here, and to a one-click mute).
 *
 * @param {Object}                    props
 * @param {Object}                    props.value    Settings with the draft applied.
 * @param {Object<string, string>}    props.kinds    Kind id => label.
 * @param {string}                    props.siteName The site's name.
 * @param {string}                    props.muted    The kind a mute link just stopped ('' none).
 * @param {(changes: Object) => void} props.onChange Called with changed keys.
 * @param {() => void}                props.onSave   Saves the draft.
 * @param {boolean}                   props.busy     Saving.
 * @param {boolean}                   props.dirty    Unsaved changes.
 * @return {Element} The tab.
 */
export function AlertsTab( {
	value,
	kinds,
	siteName,
	muted,
	onChange,
	onSave,
	busy,
	dirty,
} ) {
	const off = Array.isArray( value.alert_mail_muted )
		? value.alert_mail_muted
		: [];
	const list = Object.entries( kinds || {} );
	const sender = sprintf(
		/* translators: %s: the site's name. */
		__( 'Zinn® SEO on %s', 'zinn-seo' ),
		siteName || ''
	);
	return (
		<div className="zseo-stack">
			{ muted && kinds && kinds[ muted ] && (
				<Notice status="success" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: a kind of alert. */
						__(
							'E-mails about "%s" are stopped. Switch them back on below whenever you like.',
							'zinn-seo'
						),
						kinds[ muted ]
					) }
				</Notice>
			) }
			<PanelBody title={ __( 'Alert e-mails', 'zinn-seo' ) }>
				<p>
					{ sprintf(
						/* translators: %s: the sender's name, e.g. "Zinn® SEO on My Site". */
						__(
							'Every e-mail this plugin sends says so: it comes from "%s", its subject starts with [Zinn® SEO], and its last lines link here and to a one-click stop for that kind of e-mail.',
							'zinn-seo'
						),
						sender
					) }
				</p>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'The same alert again', 'zinn-seo' ) }
					help={ __(
						'When the same thing happens again (the same error, the same finding), it is not e-mailed each time: it is counted, and one e-mail at the end of the period says how many times it happened.',
						'zinn-seo'
					) }
					value={ value.alert_mail_digest || 'day' }
					options={ [
						{
							value: 'hour',
							label: __(
								'One e-mail an hour at most',
								'zinn-seo'
							),
						},
						{
							value: 'day',
							label: __( 'One e-mail a day at most', 'zinn-seo' ),
						},
						{
							value: 'week',
							label: __(
								'One e-mail a week at most',
								'zinn-seo'
							),
						},
					] }
					disabled={ busy }
					onChange={ ( v ) => onChange( { alert_mail_digest: v } ) }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Which e-mails to send', 'zinn-seo' ) }>
				<p>
					{ __(
						'A kind switched off is not e-mailed. It is still shown on its screen, and sent to the alert channels you set up.',
						'zinn-seo'
					) }
				</p>
				{ list.map( ( [ id, label ] ) => (
					<ToggleControl
						__nextHasNoMarginBottom
						key={ id }
						label={ label }
						checked={ ! off.includes( id ) }
						disabled={ busy }
						onChange={ ( on ) =>
							onChange( {
								alert_mail_muted: on
									? off.filter( ( k ) => k !== id )
									: [
											...off.filter( ( k ) => k !== id ),
											id,
										],
							} )
						}
					/>
				) ) }
				<p className="zak-actions">
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! dirty }
						onClick={ onSave }
					>
						{ __( 'Save changes', 'zinn-seo' ) }
					</Button>
				</p>
			</PanelBody>
		</div>
	);
}

/**
 * The Settings screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data printed by Admin::data().
 * @return {Element} The screen.
 */
export default function SettingsScreen( { data } ) {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ discouraged, setDiscouraged ] = useState(
		!! ( data.setup && data.setup.found && data.setup.found.discouraged )
	);
	const found = ( data.setup && data.setup.found ) || {};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! settings ) {
		return <Spinner />;
	}

	const value = { ...settings, ...draft };
	const change = ( part ) => setDraft( { ...draft, ...part } );
	const dirty = Object.keys( draft ).length > 0;
	const store = ( changes ) => {
		setBusy( true );
		setNotice( null );
		return save( changes )
			.then( () => {
				setDraft( {} );
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'zinn-seo' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const initial = ( window.location.hash || '' ).replace( '#/', '' );

	return (
		<div className="zseo-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<TabPanel
				className="zseo-tabs"
				tabs={ settingsTabs() }
				initialTabName={
					settingsTabs().some( ( t ) => t.name === initial )
						? initial
						: 'site'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					if ( 'site' === tab.name ) {
						return (
							<div className="zseo-stack">
								<PanelBody
									title={ __( 'Kind of site', 'zinn-seo' ) }
								>
									<SiteTypeFields
										value={ value }
										onChange={ change }
										found={ found }
									/>
								</PanelBody>
								<PanelBody
									title={ __(
										'Who is behind it',
										'zinn-seo'
									) }
								>
									<IdentityFields
										value={ value }
										onChange={ change }
										found={ found }
									/>
								</PanelBody>
								<p className="zak-actions">
									<Button
										variant="primary"
										isBusy={ busy }
										disabled={ busy || ! dirty }
										onClick={ () => store( draft ) }
									>
										{ __( 'Save changes', 'zinn-seo' ) }
									</Button>
								</p>
							</div>
						);
					}
					if ( 'search' === tab.name ) {
						return (
							<PanelBody
								title={ __( 'Search engines', 'zinn-seo' ) }
							>
								<VisibilityPanel
									discouraged={ discouraged }
									isAdmin={ !! data.isAdmin }
									readingUrl={ data.readingUrl }
									onChange={ setDiscouraged }
								/>
							</PanelBody>
						);
					}
					if ( 'jobs' === tab.name ) {
						return (
							<JobsTab
								value={ value }
								onChange={ change }
								onSave={ () => store( draft ) }
								busy={ busy }
								dirty={ dirty }
							/>
						);
					}
					if ( 'alerts' === tab.name ) {
						return (
							<AlertsTab
								value={ value }
								kinds={ data.alertKinds || {} }
								siteName={ data.siteName }
								muted={ mutedByLink( window.location.search ) }
								onChange={ change }
								onSave={ () => store( draft ) }
								busy={ busy }
								dirty={ dirty }
							/>
						);
					}
					if ( 'features' === tab.name ) {
						return <FeaturesTab data={ data } />;
					}
					if ( 'agents' === tab.name ) {
						return <AgentsTab data={ data } />;
					}
					if ( 'version' === tab.name ) {
						return <VersionTab data={ data } />;
					}
					if ( 'footprint' === tab.name ) {
						return (
							<PanelBody
								title={ __( 'No-trace mode', 'zinn-seo' ) }
							>
								<FootprintPanel />
							</PanelBody>
						);
					}
					return (
						<PanelBody
							title={ __(
								'When the plugin is deleted',
								'zinn-seo'
							) }
						>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Keep my SEO data if the plugin is deleted',
									'zinn-seo'
								) }
								help={ __(
									'Off: deleting Zinn® SEO removes every setting, page field, table and role it created. On: they stay, ready for a reinstall.',
									'zinn-seo'
								) }
								checked={ !! value.keep_data }
								disabled={ busy }
								onChange={ ( keep ) =>
									store( { keep_data: keep } )
								}
							/>
						</PanelBody>
					);
				} }
			</TabPanel>
		</div>
	);
}
