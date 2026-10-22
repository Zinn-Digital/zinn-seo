/**
 * Works with your plugins (stage P7): which integrations run, your business details, the shop's
 * search settings and Merchant readiness, communities / marketplaces / courses / memberships,
 * and the addresses a headless front end or a network uses.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import ProTeaser from '../ProTeaser';
import { Stats } from '../discovery/shared';
import { RunButton, Table } from '../p4/shared';
import GuideLink from './GuideLink';
import {
	arrange,
	badHours,
	changed,
	goodPosition,
	hasGroup,
	readyShare,
	sellerCounts,
	sellersCsv,
	withDuplicates,
} from './pure';
import './p7.scss';
import IntegrationsMoreTab from '../x1c/IntegrationsMoreTab';

/**
 * What a group of plugins is called.
 *
 * @param {string} group Group id.
 * @return {string} Name.
 */
function groupName( group ) {
	const names = {
		local: __( 'Local business', 'zinn-seo' ),
		shop: __( 'Online shops', 'zinn-seo' ),
		marketplace: __( 'Marketplaces', 'zinn-seo' ),
		community: __( 'Forums and communities', 'zinn-seo' ),
		courses: __( 'Courses', 'zinn-seo' ),
		members: __( 'Memberships', 'zinn-seo' ),
		listings: __(
			'Events, jobs, directories, recipes, property and rentals',
			'zinn-seo'
		),
		search: __( 'Site search', 'zinn-seo' ),
		media: __( 'Podcasts and video', 'zinn-seo' ),
		languages: __( 'Translation', 'zinn-seo' ),
		fields: __( 'Custom fields', 'zinn-seo' ),
		builders: __( 'Page builders', 'zinn-seo' ),
		network: __( 'Networks and headless sites', 'zinn-seo' ),
		site: __( 'Speed and chat', 'zinn-seo' ),
	};
	return names[ group ] || group;
}

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function integrationsTabs( data ) {
	const items = ( ( data && data.integrations ) || {} ).items || [];
	const audiences = [ 'marketplace', 'community', 'courses', 'members' ];
	return [
		{ name: 'plugins', title: __( 'Your plugins', 'zinn-seo' ) },
		{ name: 'local', title: __( 'Local business', 'zinn-seo' ) },
		...( hasGroup( items, 'shop' )
			? [ { name: 'shop', title: __( 'Shop', 'zinn-seo' ) } ]
			: [] ),
		...( audiences.some( ( g ) => hasGroup( items, g ) )
			? [
					{
						name: 'people',
						title: __( 'Sellers, members and forums', 'zinn-seo' ),
					},
				]
			: [] ),
		{
			name: 'developers',
			title: __( 'Headless and networks', 'zinn-seo' ),
		},
		{
			name: 'more',
			title: __( 'Search, listings and more', 'zinn-seo' ),
		},
	];
}

/**
 * One integration: what it does here, and its switch.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Integration.
 * @param {boolean}  props.pro      Pro is active.
 * @param {boolean}  props.canEdit  May switch it.
 * @param {Function} props.onSwitch (id, on) => Promise.
 * @return {Element} Row.
 */
function Integration( { item, pro, canEdit, onSwitch } ) {
	const [ busy, setBusy ] = useState( false );
	const needsPro = item.tier === 'pro' && ! pro;
	let state = __( 'Not installed on this site', 'zinn-seo' );
	if ( item.detected && needsPro ) {
		state = __( 'Found — included in Pro', 'zinn-seo' );
	} else if ( item.detected && item.off ) {
		state = __( 'Found — switched off', 'zinn-seo' );
	} else if ( item.detected ) {
		state = __( 'Working', 'zinn-seo' );
	}
	return (
		<li
			className={ `zseo-w-item${ item.on ? ' is-on' : '' }${
				item.detected ? ' is-found' : ''
			}` }
		>
			<div className="zseo-w-item__text">
				<h4 className="zseo-w-item__name">
					{ item.name }
					<span className="zseo-w-item__state">{ state }</span>
				</h4>
				<p>{ item.does }</p>
				{ item.pro_part && item.pro_does && (
					<p className="zseo-w-item__more">
						<span className="zseo-w-tag">
							{ __( 'Pro', 'zinn-seo' ) }
						</span>{ ' ' }
						{ item.pro_does }
					</p>
				) }
				{ item.proof === 'fixture' && (
					<p className="zseo-muted">
						{ sprintf(
							/* translators: %s: a plugin's name. */
							__(
								'Built from %s’s published developer documentation and checked against a stand-in for it. If anything looks wrong on your site, tell us from the Help screen and we will fix it.',
								'zinn-seo'
							),
							item.name
						) }
					</p>
				) }
			</div>
			{ item.detected && ! item.core && ! needsPro && (
				<ToggleControl
					__nextHasNoMarginBottom
					className="zseo-w-item__switch"
					label={ sprintf(
						/* translators: %s: a plugin's name. */
						__( 'Work with %s', 'zinn-seo' ),
						item.name
					) }
					checked={ ! item.off }
					disabled={ busy || ! canEdit }
					onChange={ ( on ) => {
						setBusy( true );
						onSwitch( item.id, on ).finally( () =>
							setBusy( false )
						);
					} }
				/>
			) }
		</li>
	);
}

/**
 * Your plugins.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function PluginsTab( { data } ) {
	const [ payload, setPayload ] = useState( data.integrations || null );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		seoFetch( '/integrations' )
			.then( setPayload )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	const sorted = useMemo(
		() => arrange( payload ? payload.items : [] ),
		[ payload ]
	);
	if ( ! payload ) {
		return error ? <Notice status="error">{ error }</Notice> : <Spinner />;
	}
	const onSwitch = ( id, on ) =>
		seoFetch( `/integrations/${ id }`, { method: 'POST', data: { on } } )
			.then( setPayload )
			.catch( ( e ) => setError( errorText( e ) ) );
	const list = ( groups ) =>
		groups.map( ( g ) => (
			<section key={ g.group } className="zseo-w-group">
				<h3>{ groupName( g.group ) }</h3>
				<ul className="zseo-w-list">
					{ g.items.map( ( item ) => (
						<Integration
							key={ item.id }
							item={ item }
							pro={ !! payload.pro }
							canEdit={ !! data.canManage }
							onSwitch={ onSwitch }
						/>
					) ) }
				</ul>
			</section>
		) );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Zinn® SEO notices the plugins you use and works with each one: the right details in search results, the right pages kept out of them, and nothing printed twice. A plugin you do not use costs your site nothing — its part is never loaded.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="integrations" />
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<Stats
				items={ [
					{
						label: __( 'Found on this site', 'zinn-seo' ),
						value: sorted.counts.found,
					},
					{
						label: __( 'Working now', 'zinn-seo' ),
						value: sorted.counts.on,
					},
					{
						label: __( 'Supported in all', 'zinn-seo' ),
						value: sorted.counts.total,
					},
				] }
			/>
			{ list( sorted.found ) }
			{ sorted.rest.length > 0 && (
				<details className="zseo-w-rest">
					<summary>
						{ sprintf(
							/* translators: %d: a number of plugins. */
							_n(
								'%d more plugin is supported the moment you install it',
								'%d more plugins are supported the moment you install them',
								sorted.counts.total - sorted.counts.found,
								'zinn-seo'
							),
							sorted.counts.total - sorted.counts.found
						) }
					</summary>
					{ list( sorted.rest ) }
				</details>
			) }
		</div>
	);
}

/**
 * A settings form: loads the settings, edits a draft of some keys, saves what changed.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Boot data.
 * @param {Function} props.children ( draft, set, settings ) => fields.
 * @param {Function} props.problem  ( draft ) => a sentence when the draft cannot be saved, else ''.
 * @return {Element} Form.
 */
function SettingsForm( { data, children, problem = () => '' } ) {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ note, setNote ] = useState( null );
	if ( ! settings ) {
		return error ? <Notice status="error">{ error }</Notice> : <Spinner />;
	}
	const now = { ...settings, ...draft };
	const set = ( key, value ) => {
		setNote( null );
		setDraft( { ...draft, [ key ]: value } );
	};
	const changes = changed( settings, draft );
	const wrong = problem( now );
	return (
		<form
			className="zseo-stack"
			onSubmit={ ( event ) => {
				event.preventDefault();
			} }
		>
			{ children( now, set, settings ) }
			{ note && (
				<Notice
					status={ note.status }
					onRemove={ () => setNote( null ) }
				>
					{ note.text }
				</Notice>
			) }
			{ wrong && (
				<Notice status="warning" isDismissible={ false }>
					{ wrong }
				</Notice>
			) }
			{ data.canManage && (
				<div className="zseo-actions zseo-actions--start">
					<RunButton
						variant="primary"
						disabled={ ! Object.keys( changes ).length || !! wrong }
						onError={ ( text ) =>
							setNote( { status: 'error', text } )
						}
						run={ () =>
							save( changes ).then( () => {
								setDraft( {} );
								setNote( {
									status: 'success',
									text: __( 'Saved.', 'zinn-seo' ),
								} );
							} )
						}
					>
						{ __( 'Save changes', 'zinn-seo' ) }
					</RunButton>
				</div>
			) }
		</form>
	);
}

/**
 * The kinds of business offered first (any schema.org LocalBusiness type can be typed).
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
function businessTypes() {
	return [
		{ value: '', label: __( 'Local business (general)', 'zinn-seo' ) },
		{ value: 'Restaurant', label: __( 'Restaurant', 'zinn-seo' ) },
		{ value: 'CafeOrCoffeeShop', label: __( 'Café', 'zinn-seo' ) },
		{ value: 'Store', label: __( 'Shop', 'zinn-seo' ) },
		{ value: 'Dentist', label: __( 'Dentist', 'zinn-seo' ) },
		{ value: 'MedicalClinic', label: __( 'Medical clinic', 'zinn-seo' ) },
		{ value: 'LegalService', label: __( 'Legal service', 'zinn-seo' ) },
		{
			value: 'AccountingService',
			label: __( 'Accountant', 'zinn-seo' ),
		},
		{
			value: 'RealEstateAgent',
			label: __( 'Estate agent', 'zinn-seo' ),
		},
		{ value: 'AutoRepair', label: __( 'Car repair', 'zinn-seo' ) },
		{ value: 'HairSalon', label: __( 'Hair salon', 'zinn-seo' ) },
		{ value: 'BeautySalon', label: __( 'Beauty salon', 'zinn-seo' ) },
		{
			value: 'HealthClub',
			label: __( 'Gym or health club', 'zinn-seo' ),
		},
		{ value: 'Hotel', label: __( 'Hotel', 'zinn-seo' ) },
		{ value: 'Plumber', label: __( 'Plumber', 'zinn-seo' ) },
		{ value: 'Electrician', label: __( 'Electrician', 'zinn-seo' ) },
		{
			value: 'HomeAndConstructionBusiness',
			label: __( 'Builder or home services', 'zinn-seo' ),
		},
		{
			value: 'ProfessionalService',
			label: __( 'Professional service', 'zinn-seo' ),
		},
		{ value: 'TravelAgency', label: __( 'Travel agency', 'zinn-seo' ) },
		{ value: 'VeterinaryCare', label: __( 'Vet', 'zinn-seo' ) },
	];
}

/**
 * Local business.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function LocalTab( { data } ) {
	const pro = !! ( data.integrations || {} ).pro;
	const text = ( now, set, key, label, extra = {} ) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ now[ key ] || '' }
			onChange={ ( v ) => set( key, v ) }
			{ ...extra }
		/>
	);
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Tell search engines and AI assistants where you are and when you are open, so “near me” searches and map results can show the right address, phone number and hours.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="local" />
			</p>
			<SettingsForm
				data={ data }
				problem={ ( now ) => {
					if ( ! now.local_on ) {
						return '';
					}
					if ( ! goodPosition( now.local_lat, now.local_lng ) ) {
						return __(
							'The map position needs both numbers, for example 51.5072 and -0.1276 — or leave both empty.',
							'zinn-seo'
						);
					}
					const bad = badHours( now.local_hours );
					return bad.length
						? sprintf(
								/* translators: %s: lines of text the person typed. */
								__(
									'These opening-hours lines cannot be read and would be left out: %s',
									'zinn-seo'
								),
								bad.join( ' · ' )
							)
						: '';
				} }
			>
				{ ( now, set ) => (
					<>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'This site is for a business people visit or that serves a local area',
								'zinn-seo'
							) }
							checked={ !! now.local_on }
							onChange={ ( v ) => set( 'local_on', v ) }
						/>
						{ now.local_on && (
							<>
								<Card>
									<CardHeader>
										<h3 className="zseo-card-title">
											{ __( 'The business', 'zinn-seo' ) }
										</h3>
									</CardHeader>
									<CardBody className="zseo-w-fields">
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Kind of business',
												'zinn-seo'
											) }
											value={
												businessTypes().some(
													( t ) =>
														t.value ===
														( now.local_type || '' )
												)
													? now.local_type || ''
													: '__other'
											}
											options={ [
												...businessTypes(),
												{
													value: '__other',
													label: __(
														'Another kind (type it)',
														'zinn-seo'
													),
												},
											] }
											onChange={ ( v ) =>
												set(
													'local_type',
													v === '__other'
														? 'LocalBusiness'
														: v
												)
											}
										/>
										{ ! businessTypes().some(
											( t ) =>
												t.value ===
												( now.local_type || '' )
										) &&
											text(
												now,
												set,
												'local_type',
												__(
													'schema.org type',
													'zinn-seo'
												),
												{
													help: __(
														'One word from schema.org’s list of local businesses, for example Bakery or Florist.',
														'zinn-seo'
													),
												}
											) }
										{ text(
											now,
											set,
											'local_name',
											__( 'Business name', 'zinn-seo' ),
											{
												help: __(
													'Leave empty to use the name from Settings → Site details.',
													'zinn-seo'
												),
											}
										) }
										{ text(
											now,
											set,
											'local_phone',
											__( 'Phone', 'zinn-seo' ),
											{ type: 'tel' }
										) }
										{ text(
											now,
											set,
											'local_email',
											__( 'Email', 'zinn-seo' ),
											{ type: 'email' }
										) }
										{ text(
											now,
											set,
											'local_price_range',
											__( 'Price range', 'zinn-seo' ),
											{
												help: __(
													'For example ££ or £10–£40.',
													'zinn-seo'
												),
											}
										) }
										{ text(
											now,
											set,
											'local_area',
											__( 'Area served', 'zinn-seo' ),
											{
												help: __(
													'Towns or regions, separated by commas — for a business that travels to its customers.',
													'zinn-seo'
												),
											}
										) }
									</CardBody>
								</Card>
								<Card>
									<CardHeader>
										<h3 className="zseo-card-title">
											{ __( 'Address', 'zinn-seo' ) }
										</h3>
									</CardHeader>
									<CardBody className="zseo-w-fields">
										{ text(
											now,
											set,
											'local_street',
											__( 'Street', 'zinn-seo' )
										) }
										{ text(
											now,
											set,
											'local_locality',
											__( 'Town or city', 'zinn-seo' )
										) }
										{ text(
											now,
											set,
											'local_region',
											__( 'County or region', 'zinn-seo' )
										) }
										{ text(
											now,
											set,
											'local_postcode',
											__( 'Postcode', 'zinn-seo' )
										) }
										{ text(
											now,
											set,
											'local_country',
											__( 'Country', 'zinn-seo' ),
											{
												help: __(
													'Two letters, for example GB, US or AE.',
													'zinn-seo'
												),
												maxLength: 2,
											}
										) }
										{ text(
											now,
											set,
											'local_lat',
											__( 'Latitude', 'zinn-seo' ),
											{ inputMode: 'decimal' }
										) }
										{ text(
											now,
											set,
											'local_lng',
											__( 'Longitude', 'zinn-seo' ),
											{ inputMode: 'decimal' }
										) }
									</CardBody>
								</Card>
								<Card>
									<CardHeader>
										<h3 className="zseo-card-title">
											{ __(
												'Opening hours',
												'zinn-seo'
											) }
										</h3>
										<GuideLink
											data={ data }
											id="local-hours"
										/>
									</CardHeader>
									<CardBody>
										<TextareaControl
											__nextHasNoMarginBottom
											className="zseo-w-hours"
											label={ __(
												'One line for each group of days',
												'zinn-seo'
											) }
											help={ __(
												'Days as two letters: Mo Tu We Th Fr Sa Su. Write “closed” for a closed day, or 24/7 if you never close.',
												'zinn-seo'
											) }
											rows={ 5 }
											placeholder={
												'Mo-Fr 09:00-17:30\nSa 10:00-14:00\nSu closed'
											}
											value={ now.local_hours || '' }
											onChange={ ( v ) =>
												set( 'local_hours', v )
											}
										/>
									</CardBody>
								</Card>
							</>
						) }
					</>
				) }
			</SettingsForm>
			{ pro ? (
				<Card>
					<CardHeader>
						<h3 className="zseo-card-title">
							{ __( 'More than one location', 'zinn-seo' ) }
						</h3>
						<GuideLink data={ data } id="local-locations" />
					</CardHeader>
					<CardBody className="zseo-stack">
						<p>
							{ __(
								'Give each branch a page of its own with its address, hours and map position. Visitors find the nearest one with the store finder, and search engines get a locations sitemap.',
								'zinn-seo'
							) }
						</p>
						<div className="zseo-actions zseo-actions--start">
							<Button
								variant="secondary"
								href={ `${ data.adminUrl }edit.php?post_type=zseo_location` }
							>
								{ __( 'Manage locations', 'zinn-seo' ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) : (
				<ProTeaser
					title={ __( 'More than one location', 'zinn-seo' ) }
					points={ [
						__(
							'A page for every branch, with its own address, hours and map position.',
							'zinn-seo'
						),
						__(
							'A store finder your visitors can use — no map script slowing the page.',
							'zinn-seo'
						),
						__(
							'A locations sitemap and a KML file for map services.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}

/**
 * How ready the products are for Google's Merchant listings.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function Readiness( { data } ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	// The check goes through every product a few seconds at a time and carries on from where
	// it stopped, so a shop of any size is read in full.
	const run = async () => {
		setBusy( true );
		setError( '' );
		let total = null;
		let after = 0;
		try {
			do {
				const step = await seoFetch(
					`/commerce/readiness?after=${ after }`
				);
				total = total
					? {
							...step,
							checked: total.checked + step.checked,
							ready: total.ready + step.ready,
							items: [ ...total.items, ...step.items ].slice(
								0,
								200
							),
							counts: Object.keys( {
								...total.counts,
								...step.counts,
							} ).reduce(
								( all, key ) => ( {
									...all,
									[ key ]:
										( total.counts[ key ] || 0 ) +
										( step.counts[ key ] || 0 ),
								} ),
								{}
							),
						}
					: step;
				after = step.next;
				setResult( { ...total, running: after > 0 } );
			} while ( after > 0 );
		} catch ( e ) {
			setError( errorText( e ) );
		}
		setBusy( false );
	};
	useEffect( () => {
		run();
	}, [] );
	const labels = ( result && result.labels ) || {};
	const problems = result
		? Object.keys( result.counts || {} ).filter(
				( k ) => result.counts[ k ] > 0
			)
		: [];
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'Ready for Google’s product listings?', 'zinn-seo' ) }
				</h3>
				<GuideLink data={ data } id="commerce-readiness" />
			</CardHeader>
			<CardBody className="zseo-stack">
				{ error && <Notice status="error">{ error }</Notice> }
				{ ! result && ! error && <Spinner /> }
				{ result && (
					<>
						<Stats
							items={ [
								{
									label: __( 'Products checked', 'zinn-seo' ),
									value: result.checked,
								},
								{
									label: __( 'Ready', 'zinn-seo' ),
									value: `${ result.ready } (${ readyShare(
										result.ready,
										result.checked
									) }%)`,
								},
								{
									label: __( 'Delivery shown', 'zinn-seo' ),
									value: ( result.policies || {} ).shipping
										? __( 'Yes', 'zinn-seo' )
										: __( 'Not set', 'zinn-seo' ),
								},
								{
									label: __( 'Returns shown', 'zinn-seo' ),
									value: ( result.policies || {} ).returns
										? __( 'Yes', 'zinn-seo' )
										: __( 'Not set', 'zinn-seo' ),
								},
							] }
						/>
						{ problems.length > 0 && (
							<ul className="zseo-w-problems">
								{ problems.map( ( k ) => (
									<li key={ k }>
										<strong>{ result.counts[ k ] }</strong>{ ' ' }
										{ labels[ k ] || k }
									</li>
								) ) }
							</ul>
						) }
						{ ! result.running &&
							result.checked > 0 &&
							! problems.length && (
								<p>
									{ __(
										'Every product has what Google asks for.',
										'zinn-seo'
									) }
								</p>
							) }
						{ ! result.running && result.checked === 0 && (
							<p className="zseo-muted">
								{ __(
									'No published products yet.',
									'zinn-seo'
								) }
							</p>
						) }
						<Table
							label={ __( 'Products to complete', 'zinn-seo' ) }
							empty=""
							columns={ [
								{
									id: 'name',
									label: __( 'Product', 'zinn-seo' ),
									render: ( row ) =>
										row.edit ? (
											<a href={ row.edit }>
												{ row.name }
											</a>
										) : (
											row.name
										),
								},
								{
									id: 'missing',
									label: __( 'To complete', 'zinn-seo' ),
									render: ( row ) =>
										[
											...( row.missing || [] ),
											...( row.improve || [] ),
										]
											.map( ( k ) => labels[ k ] || k )
											.join( ', ' ),
								},
							] }
							rows={ ( result.items || [] ).slice( 0, 50 ) }
						/>
						{ ( result.items || [] ).length > 50 && (
							<p className="zseo-muted">
								{ __(
									'Showing the first 50. Complete these and check again to see the next ones.',
									'zinn-seo'
								) }
							</p>
						) }
					</>
				) }
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ run }
					>
						{ __( 'Check again', 'zinn-seo' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * What to do with a product nobody can buy.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
function stockChoices() {
	return [
		{
			value: 'keep',
			label: __( 'Keep it in search results', 'zinn-seo' ),
		},
		{
			value: 'noindex',
			label: __( 'Take it out of search results', 'zinn-seo' ),
		},
		{
			value: 'redirect',
			label: __(
				'Send visitors to its category (or its replacement)',
				'zinn-seo'
			),
		},
		{
			value: 'gone',
			label: __( 'Tell search engines it is gone for good', 'zinn-seo' ),
		},
	];
}

/**
 * Shop.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function ShopTab( { data } ) {
	const pro = !! ( data.integrations || {} ).pro;
	const [ feed, setFeed ] = useState( null );
	useEffect( () => {
		if ( pro ) {
			seoFetch( '/commerce/readiness?after=999999999' )
				.then( ( r ) => setFeed( r.feed ) )
				.catch( () => {} );
		}
	}, [ pro ] );
	const text = ( now, set, key, label, extra = {} ) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ String( now[ key ] ?? '' ) }
			onChange={ ( v ) => set( key, v ) }
			{ ...extra }
		/>
	);
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Your products already show in search with price, stock and rating. Here you decide what else shoppers see before they click — and which shop pages stay out of search.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="commerce" />
			</p>
			<SettingsForm data={ data }>
				{ ( now, set ) => (
					<>
						<Card>
							<CardHeader>
								<h3 className="zseo-card-title">
									{ __( 'Shop pages in search', 'zinn-seo' ) }
								</h3>
							</CardHeader>
							<CardBody className="zseo-stack">
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Keep filtered and sorted shop pages out of search results',
										'zinn-seo'
									) }
									help={ __(
										'Pages such as “blue, size 9, cheapest first” repeat your category pages; leaving them out keeps search engines on the pages that matter.',
										'zinn-seo'
									) }
									checked={ !! now.shop_noindex_filters }
									onChange={ ( v ) =>
										set( 'shop_noindex_filters', v )
									}
								/>
								{ text(
									now,
									set,
									'shop_brand',
									__(
										'Brand for products without one',
										'zinn-seo'
									),
									{
										help: __(
											'Used when a product has no brand of its own.',
											'zinn-seo'
										),
									}
								) }
							</CardBody>
						</Card>
						{ pro && (
							<>
								<Card>
									<CardHeader>
										<h3 className="zseo-card-title">
											{ __(
												'Sold-out and discontinued products',
												'zinn-seo'
											) }
										</h3>
										<GuideLink
											data={ data }
											id="commerce-stock"
										/>
									</CardHeader>
									<CardBody className="zseo-w-fields">
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'A product that is out of stock',
												'zinn-seo'
											) }
											value={ now.shop_out_of_stock }
											options={ stockChoices() }
											onChange={ ( v ) =>
												set( 'shop_out_of_stock', v )
											}
										/>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'A product you marked as discontinued',
												'zinn-seo'
											) }
											value={ now.shop_discontinued }
											options={ stockChoices() }
											onChange={ ( v ) =>
												set( 'shop_discontinued', v )
											}
										/>
									</CardBody>
								</Card>
								<Card>
									<CardHeader>
										<h3 className="zseo-card-title">
											{ __(
												'Delivery and returns in search results',
												'zinn-seo'
											) }
										</h3>
										<GuideLink
											data={ data }
											id="commerce-delivery"
										/>
									</CardHeader>
									<CardBody className="zseo-w-fields">
										{ text(
											now,
											set,
											'shop_ship_country',
											__(
												'You deliver to (country)',
												'zinn-seo'
											),
											{
												help: __(
													'Two letters, for example GB.',
													'zinn-seo'
												),
												maxLength: 2,
											}
										) }
										{ text(
											now,
											set,
											'shop_ship_rate',
											__( 'Delivery price', 'zinn-seo' ),
											{
												help: __(
													'In your shop’s currency; 0 for free delivery.',
													'zinn-seo'
												),
												inputMode: 'decimal',
											}
										) }
										{ text(
											now,
											set,
											'shop_ship_free_over',
											__(
												'Free delivery over',
												'zinn-seo'
											),
											{ inputMode: 'decimal' }
										) }
										{ text(
											now,
											set,
											'shop_handling_days',
											__(
												'Days to dispatch',
												'zinn-seo'
											),
											{
												help: __(
													'For example 0–1.',
													'zinn-seo'
												),
											}
										) }
										{ text(
											now,
											set,
											'shop_transit_days',
											__(
												'Days in the post',
												'zinn-seo'
											),
											{
												help: __(
													'For example 1–3.',
													'zinn-seo'
												),
											}
										) }
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											type="number"
											min={ 0 }
											max={ 365 }
											label={ __(
												'Days to return an order',
												'zinn-seo'
											) }
											help={ __(
												'Leave at 0 to say nothing about returns.',
												'zinn-seo'
											) }
											value={ String(
												now.shop_return_days ?? 0
											) }
											onChange={ ( v ) =>
												set(
													'shop_return_days',
													Math.max(
														0,
														Math.min(
															365,
															parseInt( v, 10 ) ||
																0
														)
													)
												)
											}
										/>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Who pays for a return',
												'zinn-seo'
											) }
											value={ now.shop_return_fees || '' }
											options={ [
												{
													value: '',
													label: __(
														'Do not say',
														'zinn-seo'
													),
												},
												{
													value: 'free',
													label: __(
														'Returns are free',
														'zinn-seo'
													),
												},
												{
													value: 'customer',
													label: __(
														'The customer pays',
														'zinn-seo'
													),
												},
											] }
											onChange={ ( v ) =>
												set( 'shop_return_fees', v )
											}
										/>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'How an order is returned',
												'zinn-seo'
											) }
											value={
												now.shop_return_method || ''
											}
											options={ [
												{
													value: '',
													label: __(
														'Do not say',
														'zinn-seo'
													),
												},
												{
													value: 'mail',
													label: __(
														'By post',
														'zinn-seo'
													),
												},
												{
													value: 'store',
													label: __(
														'In a shop',
														'zinn-seo'
													),
												},
												{
													value: 'kiosk',
													label: __(
														'At a drop-off point',
														'zinn-seo'
													),
												},
											] }
											onChange={ ( v ) =>
												set( 'shop_return_method', v )
											}
										/>
									</CardBody>
								</Card>
								<Card>
									<CardHeader>
										<h3 className="zseo-card-title">
											{ __(
												'Product feed for Google Merchant Center',
												'zinn-seo'
											) }
										</h3>
										<GuideLink
											data={ data }
											id="commerce-feed"
										/>
									</CardHeader>
									<CardBody className="zseo-stack">
										<ToggleControl
											__nextHasNoMarginBottom
											label={ __(
												'Publish a product feed',
												'zinn-seo'
											) }
											help={ __(
												'A list of every product in the format Google Merchant Center, Bing and Meta read. Add its address there once; it stays up to date by itself.',
												'zinn-seo'
											) }
											checked={ !! now.shop_feed }
											onChange={ ( v ) =>
												set( 'shop_feed', v )
											}
										/>
										{ now.shop_feed && feed && feed.url && (
											<p className="zseo-w-address">
												<span>
													{ __(
														'Feed address:',
														'zinn-seo'
													) }
												</span>{ ' ' }
												<a
													href={ feed.url }
													target="_blank"
													rel="noopener noreferrer"
												>
													{ feed.url }
												</a>
											</p>
										) }
									</CardBody>
								</Card>
							</>
						) }
					</>
				) }
			</SettingsForm>
			{ pro ? (
				<Readiness data={ data } />
			) : (
				<ProTeaser
					title={ __( 'Sell more from search', 'zinn-seo' ) }
					points={ [
						__(
							'Every size and colour listed as its own offer, so the exact product a shopper searched for shows.',
							'zinn-seo'
						),
						__(
							'Delivery cost, delivery time and returns shown right in search results.',
							'zinn-seo'
						),
						__(
							'A rule for sold-out and discontinued products, so shoppers never land on a dead end.',
							'zinn-seo'
						),
						__(
							'A product feed for Google Merchant Center, and a check that shows which products still need a detail.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}

/**
 * Every seller's store, what is missing on it, and fixes for several at once (zs-622).
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function Sellers( { data } ) {
	const [ list, setList ] = useState( null );
	const [ meta, setMeta ] = useState( {} );
	const [ picked, setPicked ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	// The list is read a few seconds at a time, from where the last answer stopped, until every
	// seller has been read: a marketplace of any size is listed in full.
	const load = async () => {
		setError( '' );
		let all = [];
		let after = 0;
		try {
			do {
				const step = await seoFetch(
					`/marketplace/sellers?after=${ after }`
				);
				all = [ ...all, ...( step.items || [] ) ];
				after = step.next;
				setMeta( {
					labels: step.labels || {},
					plugin: step.plugin,
					least: step.least,
				} );
				setList( withDuplicates( all ) );
			} while ( after > 0 );
		} catch ( e ) {
			setError( errorText( e ) );
		}
	};
	useEffect( () => {
		load();
	}, [] );
	const labels = meta.labels || {};
	const counts = sellerCounts( list || [] );
	const bulk = ( action ) =>
		seoFetch( '/marketplace/sellers', {
			method: 'POST',
			data: { action, ids: picked },
		} ).then( ( r ) => {
			setNote(
				sprintf(
					/* translators: %d: a number of stores. */
					_n(
						'%d store changed.',
						'%d stores changed.',
						r.changed,
						'zinn-seo'
					),
					r.changed
				)
			);
			setPicked( [] );
			return load();
		} );
	const download = () => {
		const csv = sellersCsv( list || [], labels, [
			__( 'Store', 'zinn-seo' ),
			__( 'Address', 'zinn-seo' ),
			__( 'Products', 'zinn-seo' ),
			__( 'In search results', 'zinn-seo' ),
			__( 'To fix', 'zinn-seo' ),
			__( 'Search title', 'zinn-seo' ),
		] );
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL(
			new window.Blob( [ '\ufeff', csv ], {
				type: 'text/csv;charset=utf-8',
			} )
		);
		link.download = 'sellers-seo.csv';
		link.click();
		URL.revokeObjectURL( link.href );
	};
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'Your sellers’ stores in search', 'zinn-seo' ) }
				</h3>
				<GuideLink data={ data } id="marketplace-sellers" />
			</CardHeader>
			<CardBody className="zseo-stack">
				<p className="zseo-muted">
					{ __(
						'See at a glance which stores search engines can find and what each one still needs. Fix several at once, or download the list to send your sellers.',
						'zinn-seo'
					) }
				</p>
				{ error && <Notice status="error">{ error }</Notice> }
				{ note && (
					<Notice status="success" onRemove={ () => setNote( '' ) }>
						{ note }
					</Notice>
				) }
				{ ! list && ! error && <Spinner /> }
				{ list && (
					<>
						<Stats
							items={ [
								{
									label: __( 'Stores', 'zinn-seo' ),
									value: counts.stores,
								},
								{
									label: __(
										'In search results',
										'zinn-seo'
									),
									value: counts.inSearch,
								},
								{
									label: __(
										'With something to fix',
										'zinn-seo'
									),
									value: counts.toFix,
								},
							] }
						/>
						<Table
							label={ __( 'Sellers’ stores', 'zinn-seo' ) }
							empty={ __(
								'No seller has a store yet.',
								'zinn-seo'
							) }
							columns={ [
								...( data.canManage
									? [
											{
												id: 'pick',
												label: __(
													'Choose',
													'zinn-seo'
												),
												render: ( row ) => (
													<CheckboxControl
														__nextHasNoMarginBottom
														label={ sprintf(
															/* translators: %s: a store's name. */
															__(
																'Choose %s',
																'zinn-seo'
															),
															row.name
														) }
														className="zseo-w-pick"
														checked={ picked.includes(
															row.id
														) }
														onChange={ ( on ) =>
															setPicked(
																on
																	? [
																			...picked,
																			row.id,
																		]
																	: picked.filter(
																			(
																				i
																			) =>
																				i !==
																				row.id
																		)
															)
														}
													/>
												),
											},
										]
									: [] ),
								{
									id: 'name',
									label: __( 'Store', 'zinn-seo' ),
									render: ( row ) => (
										<a
											href={ row.url }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ row.name || row.url }
										</a>
									),
								},
								{
									id: 'products',
									label: __( 'Products', 'zinn-seo' ),
									render: ( row ) => row.products,
								},
								{
									id: 'search',
									label: __(
										'In search results',
										'zinn-seo'
									),
									render: ( row ) => {
										if ( row.in_search ) {
											return __( 'Yes', 'zinn-seo' );
										}
										return row.hidden
											? __(
													'No — hidden by choice',
													'zinn-seo'
												)
											: __( 'Not yet', 'zinn-seo' );
									},
								},
								{
									id: 'fix',
									label: __( 'To fix', 'zinn-seo' ),
									render: ( row ) =>
										( row.problems || [] ).length
											? row.problems
													.map(
														( p ) =>
															labels[ p ] || p
													)
													.join( ', ' )
											: __( 'Nothing', 'zinn-seo' ),
								},
							] }
							rows={ list }
						/>
						{ data.canManage && list.length > 0 && (
							<div className="zseo-actions zseo-actions--start">
								<RunButton
									disabled={ ! picked.length }
									onError={ setError }
									run={ () => bulk( 'hide' ) }
								>
									{ __(
										'Hide from search results',
										'zinn-seo'
									) }
								</RunButton>
								<RunButton
									disabled={ ! picked.length }
									onError={ setError }
									run={ () => bulk( 'show' ) }
								>
									{ __(
										'Show in search results',
										'zinn-seo'
									) }
								</RunButton>
								<RunButton
									disabled={ ! picked.length }
									onError={ setError }
									run={ () => bulk( 'reset' ) }
								>
									{ __(
										'Use the store’s own name and “about” text',
										'zinn-seo'
									) }
								</RunButton>
								<Button variant="tertiary" onClick={ download }>
									{ __(
										'Download the list (CSV)',
										'zinn-seo'
									) }
								</Button>
							</div>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Sellers, members and forums.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function PeopleTab( { data } ) {
	const items = ( data.integrations || {} ).items || [];
	const has = ( g ) => hasGroup( items, g );
	const number = ( now, set, key, label, help ) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type="number"
			min={ 0 }
			max={ 50 }
			label={ label }
			help={ help }
			value={ String( now[ key ] ?? 0 ) }
			onChange={ ( v ) =>
				set(
					key,
					Math.max( 0, Math.min( 50, parseInt( v, 10 ) || 0 ) )
				)
			}
		/>
	);
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Pages written by your sellers, members and forum users can be your best content — or thousands of near-empty pages. These rules keep the good ones in search and the empty ones out.',
					'zinn-seo'
				) }
			</p>
			<SettingsForm data={ data }>
				{ ( now, set ) => (
					<>
						{ has( 'marketplace' ) && (
							<Card>
								<CardHeader>
									<h3 className="zseo-card-title">
										{ __(
											'Marketplace sellers',
											'zinn-seo'
										) }
									</h3>
									<GuideLink data={ data } id="marketplace" />
								</CardHeader>
								<CardBody className="zseo-stack">
									{ number(
										now,
										set,
										'vendor_thin_products',
										__(
											'Keep a seller’s shop page out of search until it has this many products',
											'zinn-seo'
										),
										__(
											'0 lets every seller page into search, even an empty one.',
											'zinn-seo'
										)
									) }
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __(
											'Let sellers write the search title and description of their own shop and products',
											'zinn-seo'
										) }
										help={ __(
											'They get two plain fields in their dashboard. Nothing else of your site’s SEO is open to them.',
											'zinn-seo'
										) }
										checked={ !! now.vendor_fields }
										onChange={ ( v ) =>
											set( 'vendor_fields', v )
										}
									/>
								</CardBody>
							</Card>
						) }
						{ has( 'community' ) && (
							<Card>
								<CardHeader>
									<h3 className="zseo-card-title">
										{ __(
											'Forums and communities',
											'zinn-seo'
										) }
									</h3>
									<GuideLink data={ data } id="community" />
								</CardHeader>
								<CardBody className="zseo-stack">
									{ number(
										now,
										set,
										'forum_thin_replies',
										__(
											'Keep a topic out of search until it has this many replies',
											'zinn-seo'
										),
										__(
											'0 lets every topic into search. Questions nobody answered rarely help a searcher.',
											'zinn-seo'
										)
									) }
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __(
											'Keep member profile pages out of search results',
											'zinn-seo'
										) }
										checked={ !! now.forum_noindex_users }
										onChange={ ( v ) =>
											set( 'forum_noindex_users', v )
										}
									/>
								</CardBody>
							</Card>
						) }
						{ has( 'courses' ) && (
							<Card>
								<CardHeader>
									<h3 className="zseo-card-title">
										{ __( 'Courses', 'zinn-seo' ) }
									</h3>
									<GuideLink data={ data } id="courses" />
								</CardHeader>
								<CardBody>
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __(
											'Keep lessons and quizzes out of search results',
											'zinn-seo'
										) }
										help={ __(
											'Searchers land on the course page — the one that sells it — instead of a lesson they cannot open.',
											'zinn-seo'
										) }
										checked={ !! now.lms_noindex_lessons }
										onChange={ ( v ) =>
											set( 'lms_noindex_lessons', v )
										}
									/>
								</CardBody>
							</Card>
						) }
						{ has( 'members' ) && (
							<Card>
								<CardHeader>
									<h3 className="zseo-card-title">
										{ __(
											'Memberships and paid content',
											'zinn-seo'
										) }
									</h3>
									<GuideLink data={ data } id="members" />
								</CardHeader>
								<CardBody className="zseo-stack">
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __(
											'Tell search engines which content is for members',
											'zinn-seo'
										) }
										help={ __(
											'Google asks for this on pages where visitors see a preview and members see the rest; without it a paywall can be mistaken for hiding content.',
											'zinn-seo'
										) }
										checked={ !! now.member_markup }
										onChange={ ( v ) =>
											set( 'member_markup', v )
										}
									/>
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __(
											'Keep account, login and checkout pages out of search results',
											'zinn-seo'
										) }
										checked={ !! now.member_noindex_pages }
										onChange={ ( v ) =>
											set( 'member_noindex_pages', v )
										}
									/>
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										label={ __(
											'Where the members-only part sits in your theme (optional)',
											'zinn-seo'
										) }
										help={ __(
											'A CSS selector, for example .entry-content. Leave empty unless your theme uses another name.',
											'zinn-seo'
										) }
										value={ now.member_selector || '' }
										onChange={ ( v ) =>
											set( 'member_selector', v )
										}
									/>
								</CardBody>
							</Card>
						) }
					</>
				) }
			</SettingsForm>
			{ has( 'marketplace' ) && <Sellers data={ data } /> }
		</div>
	);
}

/**
 * Headless and networks.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function DevelopersTab( { data } ) {
	const pro = !! ( data.integrations || {} ).pro;
	const items = ( data.integrations || {} ).items || [];
	const graphql = items.some( ( i ) => i.id === 'wpgraphql' && i.on );
	const network = data.network || null;
	const rest = `${ data.restRoot || `${ data.homeUrl }wp-json/` }zinn-seo/v1/head`;
	if ( ! pro ) {
		return (
			<ProTeaser
				title={ __( 'Headless sites and networks', 'zinn-seo' ) }
				points={ [
					__(
						'A Next.js, Astro or app front end gets every page’s finished title, description, tags, structured data, breadcrumbs and redirects from one request.',
						'zinn-seo'
					),
					__(
						'The same data on every post, term and author in WPGraphQL.',
						'zinn-seo'
					),
					__(
						'On a multisite network: set SEO once for every site, lock what sites may change, and give search engines one sitemap for the whole network.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	return (
		<div className="zseo-stack">
			<Card>
				<CardHeader>
					<h3 className="zseo-card-title">
						{ __(
							'A front end that is not a WordPress theme',
							'zinn-seo'
						) }
					</h3>
					<GuideLink data={ data } id="headless" />
				</CardHeader>
				<CardBody className="zseo-stack">
					<p>
						{ __(
							'Your front end asks WordPress for a page’s SEO and prints it. It is the same title, description, tags and structured data a WordPress theme would print, so nothing is worked out twice.',
							'zinn-seo'
						) }
					</p>
					<dl className="zseo-w-endpoints">
						<dt>{ __( 'By the page’s address', 'zinn-seo' ) }</dt>
						<dd>
							<code>{ `${ rest }?url=/about/` }</code>
						</dd>
						<dt>{ __( 'By ID', 'zinn-seo' ) }</dt>
						<dd>
							<code>{ `${ rest }/post/123` }</code>{ ' ' }
							<code>{ `${ rest }/term/45` }</code>{ ' ' }
							<code>{ `${ rest }/user/6` }</code>
						</dd>
						<dt>
							{ __(
								'With WordPress’s own REST API',
								'zinn-seo'
							) }
						</dt>
						<dd>
							<code>?_fields=id,title,zinn_seo</code>
						</dd>
						<dt>WPGraphQL</dt>
						<dd>
							{ graphql ? (
								<code>
									{
										'{ zinnSeo { title description canonical html schema } }'
									}
								</code>
							) : (
								__(
									'Install WPGraphQL and a zinnSeo field appears on every post, page, term and author.',
									'zinn-seo'
								)
							) }
						</dd>
					</dl>
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h3 className="zseo-card-title">
						{ __( 'A network of sites', 'zinn-seo' ) }
					</h3>
					<GuideLink data={ data } id="network" />
				</CardHeader>
				<CardBody className="zseo-stack">
					{ network ? (
						<>
							<p>
								{ network.locked && network.locked.length
									? __(
											'Some settings of this site are set for the whole network and cannot be changed here.',
											'zinn-seo'
										)
									: __(
											'This site is part of a network. SEO can be set once for every site, and one sitemap can cover them all.',
											'zinn-seo'
										) }
							</p>
							{ network.url && (
								<div className="zseo-actions zseo-actions--start">
									<Button
										variant="secondary"
										href={ network.url }
									>
										{ __(
											'Open the network’s SEO settings',
											'zinn-seo'
										) }
									</Button>
								</div>
							) }
						</>
					) : (
						<p className="zseo-muted">
							{ __(
								'This site is not part of a WordPress multisite network. If you turn it into one, network-wide settings and a network sitemap appear here.',
								'zinn-seo'
							) }
						</p>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function IntegrationsScreen( { data } ) {
	const tabs = integrationsTabs( data );
	const wanted = window.location.hash.replace( /^#\/?/, '' );
	return (
		<div className="zseo-works">
			<TabPanel
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === wanted ) ? wanted : 'plugins'
				}
			>
				{ ( tab ) => (
					<div className="zseo-tab">
						{ tab.name === 'plugins' && (
							<PluginsTab data={ data } />
						) }
						{ tab.name === 'local' && <LocalTab data={ data } /> }
						{ tab.name === 'shop' && <ShopTab data={ data } /> }
						{ tab.name === 'people' && <PeopleTab data={ data } /> }
						{ tab.name === 'more' && (
							<IntegrationsMoreTab data={ data } />
						) }
						{ tab.name === 'developers' && (
							<DevelopersTab data={ data } />
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
