/**
 * Agency: your brand on the plugin, client logins, and alerts to chat (stage P12: zs-248, zs-360).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ColorPicker,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../admin/api';
import ImagePicker from '../admin/ImagePicker';
import { RunButton } from '../admin/p4/shared';
import { screenNames } from './screens';
import './agency.scss';

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function agencyTabs( data = {} ) {
	return [
		...( data.whitelabel
			? [
					{ name: 'brand', title: __( 'Your brand', 'zinn-seo' ) },
					{
						name: 'clients',
						title: __( 'Client logins', 'zinn-seo' ),
					},
				]
			: [] ),
		...( data.alerts
			? [ { name: 'alerts', title: __( 'Alerts to chat', 'zinn-seo' ) } ]
			: [] ),
	];
}

/**
 * Load and save the white label.
 *
 * @return {Object} {state, error, save, setError}.
 */
function useBrand() {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		seoFetch( '/agency/whitelabel' )
			.then( setState )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	const save = ( changes ) =>
		seoFetch( '/agency/whitelabel', { method: 'PUT', data: changes } )
			.then( setState )
			.catch( ( e ) => setError( errorText( e ) ) );
	return { state, error, save, setError };
}

/**
 * A text field saved when it loses focus.
 *
 * @param {Object}   props
 * @param {string}   props.label  Label.
 * @param {string}   props.help   Help.
 * @param {string}   props.value  Stored value.
 * @param {Function} props.onSave Save.
 * @param {string}   props.type   Input type.
 * @return {Element} Field.
 */
function SavedText( { label, help, value, onSave, type = 'text' } ) {
	const [ text, setText ] = useState( value || '' );
	useEffect( () => setText( value || '' ), [ value ] );
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			type={ type }
			label={ label }
			help={ help }
			value={ text }
			onChange={ setText }
			onBlur={ () => text !== ( value || '' ) && onSave( text ) }
		/>
	);
}

/**
 * Your brand: name, menu, logo, colour, hide ours.
 *
 * @return {Element} Tab.
 */
function BrandTab() {
	const { state, error, save, setError } = useBrand();
	if ( ! state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const s = state.settings;
	return (
		<Card>
			<CardBody className="zseo-stack">
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				<p className="zseo-muted">
					{ __(
						'Your clients see your agency, not ours: your name and logo on the menu and on every screen, in your colour — and the same look on their reports.',
						'zinn-seo'
					) }
				</p>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Use my brand', 'zinn-seo' ) }
					checked={ !! s.enabled }
					onChange={ ( v ) => save( { enabled: v } ) }
				/>
				<div className="zseo-grid-2">
					<SavedText
						label={ __( 'Name shown on the screens', 'zinn-seo' ) }
						help={ __(
							'For example “Brightline SEO”.',
							'zinn-seo'
						) }
						value={ s.name }
						onSave={ ( v ) => save( { name: v } ) }
					/>
					<SavedText
						label={ __( 'Menu label', 'zinn-seo' ) }
						help={ __(
							'The word in the left-hand menu.',
							'zinn-seo'
						) }
						value={ s.menu }
						onSave={ ( v ) => save( { menu: v } ) }
					/>
				</div>
				<ImagePicker
					id="zseo-brand-logo"
					label={ __( 'Logo', 'zinn-seo' ) }
					help={ __(
						'Square works best. Also printed on client reports (up to 256 KB).',
						'zinn-seo'
					) }
					value={ s.logo_id }
					onChange={ ( id ) => save( { logo_id: id } ) }
				/>
				<fieldset className="zseo-brand-colour">
					<legend>{ __( 'Colour', 'zinn-seo' ) }</legend>
					<ColorPicker
						color={ s.colour || '#1e66f5' }
						enableAlpha={ false }
						onChangeComplete={ ( c ) => {
							const hex = ( c && ( c.hex || c ) ) || '';
							if ( typeof hex === 'string' && hex !== s.colour ) {
								save( { colour: hex.slice( 0, 7 ) } );
							}
						} }
					/>
					{ s.colour && (
						<Button
							variant="link"
							onClick={ () => save( { colour: '' } ) }
						>
							{ __( 'Use the admin colour scheme', 'zinn-seo' ) }
						</Button>
					) }
				</fieldset>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Hide our branding from everyone else',
						'zinn-seo'
					) }
					help={ __(
						'Removes the plans, add-ons and help-desk screens, the licence menus and our name on the Plugins screen for every login except yours and the ones you add below.',
						'zinn-seo'
					) }
					checked={ !! s.hide_zinn }
					onChange={ ( v ) => save( { hide_zinn: v } ) }
				/>
				{ s.hide_zinn && (
					<div className="zseo-grid-2">
						<SavedText
							label={ __(
								'Author on the Plugins screen',
								'zinn-seo'
							) }
							value={ s.author }
							onSave={ ( v ) => save( { author: v } ) }
						/>
						<SavedText
							type="url"
							label={ __( 'Your website', 'zinn-seo' ) }
							value={ s.author_url }
							onSave={ ( v ) => save( { author_url: v } ) }
						/>
						<SavedText
							label={ __(
								'Description on the Plugins screen',
								'zinn-seo'
							) }
							value={ s.description }
							onSave={ ( v ) => save( { description: v } ) }
						/>
						<SavedText
							type="url"
							label={ __( 'Where “Help” goes', 'zinn-seo' ) }
							help={ __(
								'Your own help page or contact form.',
								'zinn-seo'
							) }
							value={ s.help_url }
							onSave={ ( v ) => save( { help_url: v } ) }
						/>
					</div>
				) }
				{ s.hide_zinn && (
					<SavedText
						label={ __(
							'Other logins who still see our branding (user IDs, comma separated)',
							'zinn-seo'
						) }
						help={ sprintf(
							/* translators: %d: the current user's ID. */
							__(
								'You are always included (your ID is %d).',
								'zinn-seo'
							),
							state.me
						) }
						value={ ( s.agency_users || [] ).join( ', ' ) }
						onSave={ ( v ) =>
							save( {
								agency_users: v
									.split( /[\s,]+/ )
									.map( ( n ) => parseInt( n, 10 ) )
									.filter( ( n ) => n > 0 ),
							} )
						}
					/>
				) }
				<p className="zseo-muted">
					{ __(
						'Reload the page to see the new name, menu and colour.',
						'zinn-seo'
					) }
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * Client logins: which screens they get.
 *
 * @return {Element} Tab.
 */
function ClientsTab() {
	const { state, error, save, setError } = useBrand();
	if ( ! state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const s = state.settings;
	const names = screenNames();
	const allowed = new Set( s.client_screens || [] );
	return (
		<Card>
			<CardBody className="zseo-stack">
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				<p className="zseo-muted">
					{ __(
						'Give each client their own login with the “SEO Client” role. They see only the screens you tick — never prices, credit, AI keys, connections or settings — and cannot change anything you have not allowed.',
						'zinn-seo'
					) }
				</p>
				<fieldset className="zseo-card-row">
					<legend className="zseo-legend">
						{ __( 'Screens a client sees', 'zinn-seo' ) }
					</legend>
					{ ( state.screens || [] ).map( ( id ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ id }
							label={ names[ id ] || id }
							checked={ allowed.has( id ) }
							onChange={ ( on ) => {
								const next = new Set( allowed );
								if ( on ) {
									next.add( id );
								} else {
									next.delete( id );
								}
								save( { client_screens: [ ...next ] } );
							} }
						/>
					) ) }
				</fieldset>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Let clients edit pages and their SEO titles and descriptions',
						'zinn-seo'
					) }
					help={ __(
						'Off: clients can look but not change. On: they can edit pages and posts, with the SEO panel in the editor.',
						'zinn-seo'
					) }
					checked={ !! s.client_edit }
					onChange={ ( v ) => save( { client_edit: v } ) }
				/>
				<div>
					<Button variant="primary" href={ state.add_client }>
						{ __( 'Add a client login', 'zinn-seo' ) }
					</Button>
				</div>
				<h3 className="zseo-h3">
					{ __( 'Client logins', 'zinn-seo' ) }
				</h3>
				{ ( state.clients || [] ).length ? (
					<ul className="zseo-plain-list">
						{ state.clients.map( ( c ) => (
							<li key={ c.id }>
								<strong>{ c.name }</strong>{ ' ' }
								<span className="zseo-muted">{ c.email }</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="zseo-muted">
						{ __( 'No client logins yet.', 'zinn-seo' ) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The kinds of chat a channel can be.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
function channelTypes() {
	// The services' own names are brands: never translated.
	return [
		{ value: 'slack', label: 'Slack' },
		{ value: 'teams', label: 'Microsoft Teams' },
		{ value: 'discord', label: 'Discord' },
		{ value: 'telegram', label: 'Telegram' },
		{ value: 'webhook', label: __( 'Your own webhook', 'zinn-seo' ) },
	];
}

/**
 * Alerts to chat (zs-360).
 *
 * @return {Element} Tab.
 */
function AlertsTab() {
	const [ state, setState ] = useState( null );
	const [ draft, setDraft ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	useEffect( () => {
		seoFetch( '/agency/alerts' )
			.then( ( s ) => {
				setState( s );
				setDraft( s.channels.map( ( c ) => ( { ...c, secret: '' } ) ) );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	if ( ! state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const put = ( channels, email = state.email ) =>
		seoFetch( '/agency/alerts', {
			method: 'PUT',
			data: { email, channels },
		} ).then( ( s ) => {
			setState( s );
			setDraft( s.channels.map( ( c ) => ( { ...c, secret: '' } ) ) );
			setNote( __( 'Saved.', 'zinn-seo' ) );
		} );
	const change = ( i, patch ) =>
		setDraft(
			draft.map( ( c, n ) => ( n === i ? { ...c, ...patch } : c ) )
		);
	const ruleText = {
		important: __( 'the important alerts', 'zinn-seo' ),
		all: __( 'every alert', 'zinn-seo' ),
		off: __( 'no alerts (switched off in Settings → Notices)', 'zinn-seo' ),
	};
	return (
		<Card>
			<CardBody className="zseo-stack">
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				{ note && (
					<Notice status="success" onRemove={ () => setNote( '' ) }>
						{ note }
					</Notice>
				) }
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %s: which alerts, e.g. "the important alerts". */
						__(
							'Every channel gets %s — the same ones as e-mail, so nothing is missed and nothing is sent twice.',
							'zinn-seo'
						),
						ruleText[ state.rule ] || state.rule
					) }
				</p>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Also send alerts by e-mail', 'zinn-seo' ) }
					checked={ !! state.email }
					onChange={ ( v ) =>
						put( draft, v ).catch( ( e ) =>
							setError( errorText( e ) )
						)
					}
				/>
				{ draft.map( ( c, i ) => (
					<fieldset className="zseo-card-row" key={ i }>
						<legend className="zseo-legend">
							{ c.label ||
								(
									channelTypes().find(
										( t ) => t.value === c.type
									) || {}
								).label }
						</legend>
						<div className="zseo-grid-2">
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Where', 'zinn-seo' ) }
								value={ c.type }
								options={ channelTypes() }
								onChange={ ( v ) => change( i, { type: v } ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Name', 'zinn-seo' ) }
								help={ __(
									'For example “#seo-alerts”.',
									'zinn-seo'
								) }
								value={ c.label }
								onChange={ ( v ) => change( i, { label: v } ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={
									c.type === 'telegram'
										? __( 'Bot token', 'zinn-seo' )
										: __(
												'Incoming webhook address',
												'zinn-seo'
											)
								}
								help={
									c.ends
										? sprintf(
												/* translators: %s: last four characters. */
												__(
													'Saved (ends in …%s). Leave empty to keep it.',
													'zinn-seo'
												),
												c.ends
											)
										: ''
								}
								value={ c.secret }
								onChange={ ( v ) => change( i, { secret: v } ) }
							/>
							{ c.type === 'telegram' && (
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Chat id or @channel',
										'zinn-seo'
									) }
									value={ c.chat }
									onChange={ ( v ) =>
										change( i, { chat: v } )
									}
								/>
							) }
						</div>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'On', 'zinn-seo' ) }
							checked={ !! c.on }
							onChange={ ( v ) => change( i, { on: v } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Only the important ones',
								'zinn-seo'
							) }
							checked={ !! c.important_only }
							onChange={ ( v ) =>
								change( i, { important_only: v } )
							}
						/>
						{ c.failed && (
							<Notice status="warning" isDismissible={ false }>
								{ c.failed }
							</Notice>
						) }
						<div className="zseo-actions">
							{ c.ends && (
								<RunButton
									onError={ setError }
									run={ () =>
										seoFetch( '/agency/alerts/test', {
											method: 'POST',
											data: { index: i },
										} ).then( () =>
											setNote(
												__(
													'Test sent. Check the channel.',
													'zinn-seo'
												)
											)
										)
									}
								>
									{ __( 'Send a test', 'zinn-seo' ) }
								</RunButton>
							) }
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => {
									const next = draft.filter(
										( x, n ) => n !== i
									);
									put( next ).catch( ( e ) =>
										setError( errorText( e ) )
									);
								} }
							>
								{ __( 'Remove', 'zinn-seo' ) }
							</Button>
						</div>
					</fieldset>
				) ) }
				<div className="zseo-actions">
					{ draft.length < 10 && (
						<Button
							variant="secondary"
							onClick={ () =>
								setDraft( [
									...draft,
									{
										type: 'slack',
										label: '',
										on: true,
										important_only: false,
										chat: '',
										secret: '',
									},
								] )
							}
						>
							{ __( 'Add a channel', 'zinn-seo' ) }
						</Button>
					) }
					<RunButton
						variant="primary"
						onError={ setError }
						run={ () => put( draft ) }
					>
						{ __( 'Save channels', 'zinn-seo' ) }
					</RunButton>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function AgencyScreen( { data } ) {
	const tabs = agencyTabs( data );
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	if ( ! tabs.length ) {
		return null;
	}
	return (
		<div className="zseo-admin zseo-agency">
			<TabPanel
				className="zseo-tabs"
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === initial )
						? initial
						: tabs[ 0 ].name
				}
				onSelect={ ( name ) => {
					if (
						! ( window.location.hash || '' ).startsWith(
							`#/${ name }`
						)
					) {
						window.history.replaceState( null, '', `#/${ name }` );
					}
				} }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'clients':
							return <ClientsTab />;
						case 'alerts':
							return <AlertsTab />;
						default:
							return <BrandTab />;
					}
				} }
			</TabPanel>
		</div>
	);
}
