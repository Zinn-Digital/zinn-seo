/**
 * Redirects: the list, add and edit, test an address, chains and loops (zs-094, zs-098).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { guideUrl } from '../p7/pure';
import ConditionsEditor, {
	loadConditions,
	saveConditions,
} from '../x1a/Conditions';
import { hashParams, JobLine, RunButton, Table, useJob } from '../p4/shared';
import {
	codeChoices,
	moves,
	originLabel,
	problemLabel,
	ruleProblem,
	when,
} from './pure';

const EMPTY = {
	id: 0,
	source: '',
	target: '',
	code: '301',
	regex: false,
	group: '',
	note: '',
};

/**
 * The add / edit form.
 *
 * @param {Object}   props
 * @param {Object}   props.rule     Rule being edited (id 0 = new).
 * @param {Array}    props.groups   Groups in use.
 * @param {Function} props.onSaved  Called with the stored rule.
 * @param {Function} props.onCancel Leave edit mode.
 * @param {string}   props.guide    The conditions guide's address ('' for none).
 * @return {Element} Form.
 */
function RuleForm( { rule, groups, onSaved, onCancel, guide } ) {
	const [ draft, setDraft ] = useState( rule );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ conds, setConds ] = useState( null );
	const withConditions = !! ( window.zinnSeoAdmin || {} ).redirectConditions;
	useEffect( () => setDraft( rule ), [ rule ] );
	useEffect( () => {
		if ( withConditions ) {
			loadConditions( rule.id )
				.then( setConds )
				.catch( () => setConds( { match: 'all', rules: [] } ) );
		}
	}, [ rule.id, withConditions ] );
	const set = ( k ) => ( v ) => setDraft( { ...draft, [ k ]: v } );
	const save = () => {
		const problem = ruleProblem( draft );
		if ( problem ) {
			setError( problem );
			return;
		}
		setBusy( true );
		setError( '' );
		const data = {
			source: draft.source.trim(),
			target: moves( draft.code ) ? draft.target.trim() : '',
			code: Number( draft.code ),
			regex: !! draft.regex,
			group: draft.group,
			note: draft.note,
		};
		seoFetch( draft.id ? `/redirects/${ draft.id }` : '/redirects', {
			method: draft.id ? 'PUT' : 'POST',
			data,
		} )
			.then( ( saved ) =>
				// Conditions (Pro, zs-734) belong to the stored rule: saved after it.
				withConditions && conds && ( conds.rules.length || draft.id )
					? saveConditions( saved.id, conds ).then( () => saved )
					: saved
			)
			.then( ( saved ) => {
				onSaved( saved );
				setDraft( EMPTY );
				setConds( { match: 'all', rules: [] } );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ draft.id
						? __( 'Edit the redirect', 'zinn-seo' )
						: __( 'Add a redirect', 'zinn-seo' ) }
				</h3>
			</CardHeader>
			<CardBody>
				<form
					className="zseo-stack"
					onSubmit={ ( e ) => {
						e.preventDefault();
						save();
					} }
				>
					{ error && (
						<Notice
							status="error"
							onRemove={ () => setError( '' ) }
						>
							{ error }
						</Notice>
					) }
					<div className="zseo-row zseo-row--top">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={
								draft.regex
									? __(
											'From (a regular expression)',
											'zinn-seo'
										)
									: __( 'From', 'zinn-seo' )
							}
							className="zseo-address"
							value={ draft.source }
							placeholder={
								draft.regex ? '^/old/(.*)$' : '/old-address/'
							}
							help={
								draft.regex
									? __(
											'Matched against the address path, e.g. ^/shop/(.*)$. Use $1, $2… in the target.',
											'zinn-seo'
										)
									: __(
											'A path on this site, or a full address of it. Letters case and a trailing slash do not matter.',
											'zinn-seo'
										)
							}
							onChange={ set( 'source' ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Type', 'zinn-seo' ) }
							value={ String( draft.code ) }
							options={ codeChoices() }
							onChange={ set( 'code' ) }
						/>
						{ moves( draft.code ) && (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'To', 'zinn-seo' ) }
								className="zseo-address"
								value={ draft.target }
								placeholder="/new-address/"
								help={ __(
									'A path on this site or any full address.',
									'zinn-seo'
								) }
								onChange={ set( 'target' ) }
							/>
						) }
					</div>
					<div className="zseo-row zseo-row--top">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Group (optional)', 'zinn-seo' ) }
							value={ draft.group }
							list="zseo-redirect-groups"
							onChange={ set( 'group' ) }
						/>
						<datalist id="zseo-redirect-groups">
							{ groups.map( ( g ) => (
								<option key={ g } value={ g } />
							) ) }
						</datalist>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Note (optional)', 'zinn-seo' ) }
							value={ draft.note }
							onChange={ set( 'note' ) }
						/>
					</div>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'The address is a regular expression',
							'zinn-seo'
						) }
						checked={ !! draft.regex }
						onChange={ set( 'regex' ) }
					/>
					<ConditionsEditor
						value={ conds }
						onChange={ setConds }
						guide={ guide }
					/>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="primary"
							type="submit"
							isBusy={ busy }
							disabled={ busy }
						>
							{ draft.id
								? __( 'Save the redirect', 'zinn-seo' )
								: __( 'Add the redirect', 'zinn-seo' ) }
						</Button>
						{ draft.id > 0 && (
							<Button
								variant="tertiary"
								onClick={ () => {
									setDraft( EMPTY );
									onCancel();
								} }
							>
								{ __( 'Cancel', 'zinn-seo' ) }
							</Button>
						) }
					</div>
				</form>
			</CardBody>
		</Card>
	);
}

/**
 * Test an address: what a visitor gets, hop by hop.
 *
 * @return {Element} Card.
 */
function Tester() {
	const [ url, setUrl ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const run = () =>
		seoFetch( `/redirects/test?url=${ encodeURIComponent( url ) }` )
			.then( setResult )
			.catch( ( e ) => setError( errorText( e ) ) );
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'Test an address', 'zinn-seo' ) }
				</h3>
			</CardHeader>
			<CardBody>
				<form
					className="zseo-stack"
					onSubmit={ ( e ) => {
						e.preventDefault();
						setError( '' );
						run();
					} }
				>
					<div className="zseo-row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Address', 'zinn-seo' ) }
							className="zseo-address"
							value={ url }
							placeholder="/old-address/"
							onChange={ setUrl }
						/>
						<div>
							<Button
								variant="secondary"
								type="submit"
								disabled={ ! url.trim() }
							>
								{ __( 'Test', 'zinn-seo' ) }
							</Button>
						</div>
					</div>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ result && ! result.match && (
						<p role="status">
							{ __(
								'No redirect answers this address: WordPress shows it as usual.',
								'zinn-seo'
							) }
						</p>
					) }
					{ result && result.match && (
						<div role="status" className="zseo-stack">
							<p>
								{ moves( result.match.code )
									? sprintf(
											/* translators: 1: status code, 2: address. */
											__(
												'Answered with %1$d and sent to %2$s',
												'zinn-seo'
											),
											result.match.code,
											result.match.location
										)
									: sprintf(
											/* translators: %d: status code. */
											__(
												'Answered with %d and the not-found page.',
												'zinn-seo'
											),
											result.match.code
										) }
							</p>
							{ result.status === 'chain' && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ sprintf(
										/* translators: 1: number of hops, 2: final address. */
										_n(
											'That address redirects again (%1$d more hop) before it ends at %2$s.',
											'That address redirects again (%1$d more hops) before it ends at %2$s.',
											result.hops.length - 1,
											'zinn-seo'
										),
										result.hops.length - 1,
										result.final || '410'
									) }
								</Notice>
							) }
							{ result.status === 'loop' && (
								<Notice status="error" isDismissible={ false }>
									{ __(
										'These redirects go round in a circle: no browser can open the address.',
										'zinn-seo'
									) }
								</Notice>
							) }
							<ol className="zseo-hops">
								{ result.hops.map( ( h ) => (
									<li key={ h.id }>
										<span className="zseo-address">
											{ h.source }
										</span>{ ' ' }
										→{ ' ' }
										<span className="zseo-address">
											{ h.target || String( h.code ) }
										</span>{ ' ' }
										<span className="zseo-muted">
											({ h.code })
										</span>
									</li>
								) ) }
							</ol>
						</div>
					) }
				</form>
			</CardBody>
		</Card>
	);
}

/**
 * Chains and loops: check and fix.
 *
 * @param {Object}   props
 * @param {Object}   props.health    /redirects/health.
 * @param {boolean}  props.canManage May change.
 * @param {Function} props.onChange  After a fix.
 * @return {Element} Card.
 */
function Checks( { health, canManage, onChange } ) {
	const [ targets, setTargets ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ fixed, setFixed ] = useState( null );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/redirects/health' ).then( ( r ) => r.check ),
		onChange
	);
	useEffect( () => {
		if ( health ) {
			setJob( health.check );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ health ] );
	const found = health && health.checked ? health.checked.found : null;
	const problems = found ? ( found.chain || 0 ) + ( found.loop || 0 ) : 0;
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'Chains and loops', 'zinn-seo' ) }
				</h3>
			</CardHeader>
			<CardBody className="zseo-stack">
				<p className="zseo-muted">
					{ __(
						'A redirect to an address that redirects again costs every visitor another round trip, and search engines give up after a few. New redirects never make one: the old ones are pointed at the new address for you. This check finds any already there.',
						'zinn-seo'
					) }
				</p>
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				{ found && (
					<p role="status">
						{ sprintf(
							/* translators: 1: chains, 2: loops, 3: missing targets, 4: date. */
							__(
								'Last check: %1$d chains, %2$d loops, %3$d missing targets (%4$s).',
								'zinn-seo'
							),
							found.chain || 0,
							found.loop || 0,
							found.missing || 0,
							new Date(
								health.checked.at * 1000
							).toLocaleString()
						) }
					</p>
				) }
				{ fixed && (
					<Notice
						status="success"
						onRemove={ () => setFixed( null ) }
					>
						{ sprintf(
							/* translators: 1: chains fixed, 2: loops switched off. */
							__(
								'%1$d chains now go straight to their final address; %2$d loops were switched off for you to look at.',
								'zinn-seo'
							),
							fixed.chains,
							fixed.loops
						) }
					</Notice>
				) }
				{ canManage && (
					<>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Also ask every target on this site whether it answers (slower)',
								'zinn-seo'
							) }
							checked={ targets }
							onChange={ setTargets }
						/>
						<div className="zseo-actions zseo-actions--start">
							<RunButton
								onError={ setError }
								disabled={ job && job.status === 'pending' }
								run={ () =>
									seoFetch( '/redirects/check', {
										method: 'POST',
										data: { targets },
									} ).then( setJob )
								}
							>
								{ __( 'Check every redirect', 'zinn-seo' ) }
							</RunButton>
							{ problems > 0 && (
								<RunButton
									variant="primary"
									onError={ setError }
									run={ () =>
										seoFetch( '/redirects/fix', {
											method: 'POST',
										} ).then( ( r ) => {
											setFixed( r );
											onChange();
										} )
									}
								>
									{ __( 'Fix them', 'zinn-seo' ) }
								</RunButton>
							) }
						</div>
					</>
				) }
				<JobLine job={ job } />
			</CardBody>
		</Card>
	);
}

/**
 * The redirects tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function RedirectsTab( { data } ) {
	const conf = data.redirects || {};
	const canManage = !! conf.canManage;
	const params = hashParams();
	const [ filters, setFilters ] = useState( {
		search: '',
		group: '',
		code: '',
		problem: params.get( 'problem' ) || '',
	} );
	const [ rows, setRows ] = useState( [] );
	const [ next, setNext ] = useState( 0 );
	const [ totals, setTotals ] = useState( null );
	const [ groups, setGroups ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ editing, setEditing ] = useState( EMPTY );
	const [ picked, setPicked ] = useState( [] );
	const [ health, setHealth ] = useState( null );
	const load = ( before, replace ) => {
		setLoading( true );
		const q = new URLSearchParams( {
			per: '50',
			before: String( before ),
		} );
		Object.keys( filters ).forEach( ( k ) => {
			if ( filters[ k ] ) {
				q.set( k, filters[ k ] );
			}
		} );
		return seoFetch( `/redirects?${ q.toString() }` )
			.then( ( r ) => {
				setRows( replace ? r.items : [ ...rows, ...r.items ] );
				setNext( r.next );
				if ( r.totals ) {
					setTotals( r.totals );
					setGroups( r.groups || [] );
				}
				if ( replace ) {
					setPicked( [] );
				}
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setLoading( false ) );
	};
	const loadHealth = () =>
		seoFetch( '/redirects/health' )
			.then( setHealth )
			.catch( () => {} );
	const reload = () => {
		load( 0, true );
		loadHealth();
	};
	useEffect( () => {
		// Typing in the search box waits for a pause before asking the server.
		const t = setTimeout( () => load( 0, true ), 300 );
		return () => clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ filters ] );
	useEffect( () => {
		loadHealth();
	}, [] );
	const bulk = ( action ) =>
		seoFetch( '/redirects/bulk', {
			method: 'POST',
			data: { ids: picked, action },
		} )
			.then( ( r ) => {
				setNotice(
					sprintf(
						/* translators: %d: number of redirects. */
						_n(
							'%d redirect changed.',
							'%d redirects changed.',
							r.changed,
							'zinn-seo'
						),
						r.changed
					)
				);
				reload();
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	const index = health ? health.index : null;
	const columns = [
		...( canManage
			? [
					{
						id: 'pick',
						label: __( 'Select', 'zinn-seo' ),
						render: ( r ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={
									<span className="screen-reader-text">
										{ sprintf(
											/* translators: %s: an address. */
											__( 'Select %s', 'zinn-seo' ),
											r.source
										) }
									</span>
								}
								checked={ picked.includes( r.id ) }
								onChange={ ( on ) =>
									setPicked(
										on
											? [ ...picked, r.id ]
											: picked.filter(
													( x ) => x !== r.id
												)
									)
								}
							/>
						),
					},
				]
			: [] ),
		{
			id: 'source',
			label: __( 'From', 'zinn-seo' ),
			render: ( r ) => (
				<span className="zseo-cell-title">
					<span className="zseo-address">{ r.source }</span>
					{ r.regex && (
						<span className="zseo-tag">
							{ __( 'pattern', 'zinn-seo' ) }
						</span>
					) }
					{ ! r.enabled && (
						<span className="zseo-tag">
							{ __( 'off', 'zinn-seo' ) }
						</span>
					) }
					{ r.problem && (
						<span className="zseo-tag is-bad">
							{ problemLabel( r.problem ) }
						</span>
					) }
				</span>
			),
		},
		{
			id: 'target',
			label: __( 'To', 'zinn-seo' ),
			render: ( r ) =>
				moves( r.code ) ? (
					<span className="zseo-address">{ r.target }</span>
				) : (
					<span className="zseo-muted">
						{ __( '(the not-found page)', 'zinn-seo' ) }
					</span>
				),
		},
		{
			id: 'code',
			label: __( 'Type', 'zinn-seo' ),
			render: ( r ) => String( r.code ),
		},
		{
			id: 'hits',
			label: __( 'Used', 'zinn-seo' ),
			render: ( r ) => (
				<span>
					{ r.hits }
					{ r.last_hit && (
						<span className="zseo-muted zseo-small">
							{ ' ' }
							{ when( r.last_hit ) }
						</span>
					) }
				</span>
			),
		},
		{
			id: 'origin',
			label: __( 'Made by', 'zinn-seo' ),
			render: ( r ) => (
				<span>
					{ originLabel( r.origin ) }
					{ r.group && r.group !== originLabel( r.origin ) && (
						<span className="zseo-muted zseo-small">
							{ ' · ' }
							{ r.group }
						</span>
					) }
				</span>
			),
		},
		...( canManage
			? [
					{
						id: 'do',
						label: __( 'Actions', 'zinn-seo' ),
						render: ( r ) => (
							<span className="zseo-actions zseo-actions--start">
								<Button
									variant="link"
									onClick={ () => {
										setEditing( {
											id: r.id,
											source: r.source,
											target: r.target,
											code: String( r.code ),
											regex: r.regex,
											group: r.group,
											note: r.note,
										} );
										window.scrollTo( 0, 0 );
									} }
								>
									{ __( 'Edit', 'zinn-seo' ) }
								</Button>
								<Button
									variant="link"
									isDestructive
									onClick={ () =>
										seoFetch( `/redirects/${ r.id }`, {
											method: 'DELETE',
										} )
											.then( reload )
											.catch( ( e ) =>
												setError( errorText( e ) )
											)
									}
								>
									{ __( 'Delete', 'zinn-seo' ) }
								</Button>
							</span>
						),
					},
				]
			: [] ),
	];
	return (
		<div className="zseo-stack">
			{ ( error || notice ) && (
				<Notice
					status={ error ? 'error' : 'success' }
					onRemove={ () => {
						setError( '' );
						setNotice( '' );
					} }
				>
					{ error || notice }
				</Notice>
			) }
			{ totals && (
				<dl className="zseo-totals">
					<div>
						<dt>{ __( 'Redirects', 'zinn-seo' ) }</dt>
						<dd>{ totals.all }</dd>
					</div>
					<div>
						<dt>{ __( 'Switched on', 'zinn-seo' ) }</dt>
						<dd>{ totals.enabled }</dd>
					</div>
					<div>
						<dt>{ __( 'Patterns', 'zinn-seo' ) }</dt>
						<dd>{ totals.regex }</dd>
					</div>
					<div>
						<dt>{ __( 'Times used', 'zinn-seo' ) }</dt>
						<dd>{ totals.hits }</dd>
					</div>
					<div>
						<dt>{ __( 'Need a look', 'zinn-seo' ) }</dt>
						<dd>{ totals.problems }</dd>
					</div>
				</dl>
			) }
			{ index && index.store === 'db' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The uploads folder cannot be written, so each visitor’s request looks redirects up in the database instead of the fast index. Make wp-content/uploads writable, then rebuild the index.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ index && index.regex > index.early && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: number of pattern redirects, 2: how many are tried on every request. */
						__(
							'%1$d pattern redirects: the first %2$d are tried on every request and the rest only when a page is missing. Turn pattern redirects that match one address into plain ones to keep every page fast.',
							'zinn-seo'
						),
						index.regex,
						index.early
					) }
				</Notice>
			) }
			{ canManage && (
				<RuleForm
					rule={ editing }
					groups={ groups }
					guide={ guideUrl( data, 'redirect-conditions' ) }
					onCancel={ () => setEditing( EMPTY ) }
					onSaved={ ( saved ) => {
						setNotice(
							sprintf(
								/* translators: %s: an address. */
								__( 'Saved: %s', 'zinn-seo' ),
								saved.source
							)
						);
						setEditing( EMPTY );
						reload();
					} }
				/>
			) }
			<Tester />
			<Card>
				<CardHeader>
					<h3 className="zseo-card-title">
						{ __( 'All redirects', 'zinn-seo' ) }
					</h3>
				</CardHeader>
				<CardBody className="zseo-stack">
					<div className="zseo-filters">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Search', 'zinn-seo' ) }
							type="search"
							value={ filters.search }
							onChange={ ( v ) =>
								setFilters( { ...filters, search: v } )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Type', 'zinn-seo' ) }
							value={ filters.code }
							options={ [
								{ value: '', label: __( 'All', 'zinn-seo' ) },
								...codeChoices(),
							] }
							onChange={ ( v ) =>
								setFilters( { ...filters, code: v } )
							}
						/>
						{ groups.length > 0 && (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Group', 'zinn-seo' ) }
								value={ filters.group }
								options={ [
									{
										value: '',
										label: __( 'All', 'zinn-seo' ),
									},
									...groups.map( ( g ) => ( {
										value: g,
										label: g,
									} ) ),
								] }
								onChange={ ( v ) =>
									setFilters( { ...filters, group: v } )
								}
							/>
						) }
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Show', 'zinn-seo' ) }
							value={ filters.problem }
							options={ [
								{ value: '', label: __( 'All', 'zinn-seo' ) },
								{
									value: 'any',
									label: __( 'Needing a look', 'zinn-seo' ),
								},
								...[
									'chain',
									'loop',
									'missing',
									'shadows',
								].map( ( p ) => ( {
									value: p,
									label: problemLabel( p ),
								} ) ),
							] }
							onChange={ ( v ) =>
								setFilters( { ...filters, problem: v } )
							}
						/>
					</div>
					{ canManage && picked.length > 0 && (
						<div className="zseo-actions zseo-actions--start">
							<span>
								{ sprintf(
									/* translators: %d: number selected. */
									__( '%d selected', 'zinn-seo' ),
									picked.length
								) }
							</span>
							<Button
								variant="secondary"
								onClick={ () => bulk( 'enable' ) }
							>
								{ __( 'Switch on', 'zinn-seo' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => bulk( 'disable' ) }
							>
								{ __( 'Switch off', 'zinn-seo' ) }
							</Button>
							<Button
								variant="secondary"
								isDestructive
								onClick={ () => bulk( 'delete' ) }
							>
								{ __( 'Delete', 'zinn-seo' ) }
							</Button>
						</div>
					) }
					{ loading && rows.length === 0 ? (
						<Spinner />
					) : (
						<Table
							columns={ columns }
							rows={ rows }
							label={ __( 'Redirects', 'zinn-seo' ) }
							empty={ __(
								'No redirect yet. Add one above, import them from another plugin or a spreadsheet, or let the plugin write them when a page’s address changes.',
								'zinn-seo'
							) }
						/>
					) }
					{ next > 0 && (
						<p>
							<Button
								variant="secondary"
								isBusy={ loading }
								onClick={ () => load( next, false ) }
							>
								{ __( 'Show more', 'zinn-seo' ) }
							</Button>
						</p>
					) }
				</CardBody>
			</Card>
			<Checks
				health={ health }
				canManage={ canManage }
				onChange={ reload }
			/>
		</div>
	);
}
