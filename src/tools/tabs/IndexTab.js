/**
 * Google index checker: which pages Google has, which it does not — one page, a few, or the
 * whole site in the background.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SearchControl,
	SelectControl,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import {
	Cost,
	Loaded,
	NewTab,
	Refusal,
	Table,
	useAction,
	useLoad,
} from '../parts';
import { count, day, lines, money } from '../pure';
import { Empty } from '../tool';
import { unitEach } from '../labels';

const STATES = {
	yes: { cls: 'is-success' },
	no: { cls: 'is-critical' },
	unknown: { cls: '' },
	never: { cls: '' },
};

/**
 * A page's last answer, in words.
 *
 * @param {string} state yes|no|unknown|never.
 * @return {string} Words.
 */
function stateWords( state ) {
	const words = {
		yes: __( 'In Google', 'zinn-seo' ),
		no: __( 'Not in Google', 'zinn-seo' ),
		unknown: __( 'Google gave no answer', 'zinn-seo' ),
		never: __( 'Not checked yet', 'zinn-seo' ),
	};
	return words[ state ] || state;
}

/**
 * The whole-site check: its price, its progress, pause and resume.
 *
 * @param {Object}   props
 * @param {Object}   props.overview The index checker's overview.
 * @param {Object}   props.ctx      The checks' shared state.
 * @param {Function} props.reload   Read the overview again.
 * @return {Element} Card.
 */
function SiteCheck( { overview, ctx, reload } ) {
	const action = useAction();
	const [ asking, setAsking ] = useState( false );
	const job = overview.job || {};
	const live = [ 'pending' ].includes( job.status );
	const timer = useRef( null );
	useEffect( () => {
		if ( live ) {
			timer.current = window.setTimeout( reload, 5000 );
		}
		return () => window.clearTimeout( timer.current );
	}, [ live, job.done, reload ] );
	const send = ( what ) =>
		action
			.run( what, () =>
				seoFetch( '/tools/index/site', {
					method: 'POST',
					data: { action: what },
				} )
			)
			.then( () => {
				setAsking( false );
				ctx.refresh();
				reload();
			} );
	const balance = ctx.account ? ctx.account.balance_micros : null;
	return (
		<Card className="zseo-sitecheck">
			<CardBody className="zseo-stack">
				<h2 className="zseo-card-title">
					{ __( 'Check every page', 'zinn-seo' ) }
				</h2>
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %s: a number of pages. */
						_n(
							'Your site has %s published page. The check runs in the background, however big the site; you can leave this screen.',
							'Your site has %s published pages. The check runs in the background, however big the site; you can leave this screen.',
							overview.pages,
							'zinn-seo'
						),
						count( overview.pages )
					) }
				</p>
				<Refusal
					error={ action.error }
					buyUrl={ ctx.buyUrl }
					onRemove={ action.clear }
				/>
				{ 'paused' === job.status && (
					<Notice
						status="warning"
						isDismissible={ false }
						className="zseo-heldline"
					>
						{ job.held
							? __(
									'Paused: the credit ran out part-way. Nothing is lost and nothing was charged for the pages not checked yet — add credit and it carries on from where it stopped.',
									'zinn-seo'
								)
							: __(
									'Paused. It carries on from where it stopped.',
									'zinn-seo'
								) }{ ' ' }
						{ job.reason && (
							<span className="zseo-small">{ job.reason } </span>
						) }
						{ job.held && (
							<NewTab href={ ctx.buyUrl }>
								{ __( 'Add credit', 'zinn-seo' ) }
							</NewTab>
						) }
					</Notice>
				) }
				{ [ 'pending', 'paused', 'done' ].includes( job.status ) &&
					job.total > 0 && (
						<div className="zseo-progress">
							<progress
								max={ job.total }
								value={ Math.min( job.done, job.total ) }
								aria-label={ __( 'Pages checked', 'zinn-seo' ) }
							/>
							<span role="status">
								{ sprintf(
									/* translators: 1: pages checked, 2: pages in all. */
									__(
										'%1$s of %2$s pages checked',
										'zinn-seo'
									),
									count( job.done ),
									count( job.total )
								) }
								{ job.charged > 0 &&
									` · ${ sprintf(
										/* translators: %s: an amount of money. */
										__( 'cost so far %s', 'zinn-seo' ),
										money( job.charged - job.refunded )
									) }` }
							</span>
						</div>
					) }
				{ 'done' === job.status && (
					<p className="zseo-ok">
						{ sprintf(
							/* translators: 1: pages in Google, 2: pages not in Google, 3: a date. */
							__(
								'Finished %3$s: %1$s in Google, %2$s not.',
								'zinn-seo'
							),
							count( job.indexed ),
							count( job.missing ),
							day( job.finished, true )
						) }
					</p>
				) }
				{ ctx.canSpend && (
					<div className="zseo-actions zseo-actions--start">
						{ ! live && 'paused' !== job.status && ! asking && (
							<>
								<Button
									variant="primary"
									disabled={
										! overview.pages || !! action.busy
									}
									onClick={ () => setAsking( true ) }
								>
									{ __( 'Check every page', 'zinn-seo' ) }
								</Button>
								<Cost
									cost={ overview.site_cost }
									balance={ balance }
									each={ unitEach( 'index_check' ) }
									unit={ overview.unit_price }
								/>
							</>
						) }
						{ asking && (
							<>
								<span className="zseo-ask">
									{ sprintf(
										/* translators: 1: an amount of money, 2: a number of pages. */
										__(
											'This uses up to %1$s of your credit for %2$s pages. Pages Google cannot answer for are refunded. Start?',
											'zinn-seo'
										),
										money( overview.site_cost ),
										count( overview.pages )
									) }
								</span>
								<Button
									variant="primary"
									isBusy={ 'start' === action.busy }
									onClick={ () => send( 'start' ) }
								>
									{ __( 'Yes, start', 'zinn-seo' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => setAsking( false ) }
								>
									{ __( 'Cancel', 'zinn-seo' ) }
								</Button>
							</>
						) }
						{ live && (
							<Button
								variant="secondary"
								isBusy={ 'pause' === action.busy }
								onClick={ () => send( 'pause' ) }
							>
								{ __( 'Pause', 'zinn-seo' ) }
							</Button>
						) }
						{ 'paused' === job.status && (
							<>
								<Button
									variant="primary"
									isBusy={ 'resume' === action.busy }
									onClick={ () => send( 'resume' ) }
								>
									{ __( 'Carry on', 'zinn-seo' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => send( 'cancel' ) }
								>
									{ __( 'Stop here', 'zinn-seo' ) }
								</Button>
							</>
						) }
					</div>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function IndexTab( { data, ctx } ) {
	const overview = useLoad( '/tools/index' );
	const [ search, setSearch ] = useState( '' );
	const [ filter, setFilter ] = useState( '' );
	const [ pages, setPages ] = useState( {
		items: [],
		next: 0,
		loading: true,
	} );
	const [ picked, setPicked ] = useState( new Set() );
	const [ other, setOther ] = useState( '' );
	const [ run, setRun ] = useState( null );
	const action = useAction();
	const poll = useRef( null );
	const price = ctx.tools.index ? ctx.tools.index.unit_price : null;
	const unitPrice =
		null !== price && undefined !== price
			? price
			: overview.data && overview.data.unit_price;

	const loadPages = ( before = 0 ) => {
		setPages( ( p ) => ( { ...p, loading: true } ) );
		seoFetch(
			`/tools/pages?search=${ encodeURIComponent(
				search
			) }&state=${ filter }&before=${ before }`
		)
			.then( ( got ) =>
				setPages( ( p ) => ( {
					items: before ? [ ...p.items, ...got.items ] : got.items,
					next: got.next,
					loading: false,
				} ) )
			)
			.catch( () => setPages( ( p ) => ( { ...p, loading: false } ) ) );
	};
	useEffect( () => {
		const t = window.setTimeout( () => loadPages( 0 ), 250 );
		return () => window.clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ search, filter ] );
	useEffect( () => () => window.clearTimeout( poll.current ), [] );

	const follow = ( id ) => {
		window.clearTimeout( poll.current );
		seoFetch( `/tools/index/run/${ id }` )
			.then( ( got ) => {
				setRun( got );
				if ( 'queued' === got.status ) {
					poll.current = window.setTimeout(
						() => follow( id ),
						5000
					);
				} else {
					overview.reload();
					loadPages( 0 );
					ctx.refresh();
				}
			} )
			.catch( () => {
				poll.current = window.setTimeout( () => follow( id ), 10000 );
			} );
	};
	const urls = [
		...pages.items
			.filter( ( p ) => picked.has( p.id ) )
			.map( ( p ) => p.url ),
		...lines( other ),
	];
	const check = () =>
		action
			.run( 'check', () =>
				seoFetch( '/tools/index/check', {
					method: 'POST',
					data: { urls },
				} )
			)
			.then( ( started ) => {
				if ( started ) {
					setRun( started );
					setPicked( new Set() );
					setOther( '' );
					ctx.refresh();
					poll.current = window.setTimeout(
						() => follow( started.id ),
						4000
					);
				}
			} );
	const toggle = ( id, on ) => {
		const next = new Set( picked );
		if ( on ) {
			next.add( id );
		} else {
			next.delete( id );
		}
		setPicked( next );
	};
	const cost =
		null === unitPrice || undefined === unitPrice
			? null
			: unitPrice * urls.length;

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Make sure Google has your pages',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-index" />
					</div>
					<p className="zseo-lead">
						{ __(
							'A page Google has not indexed cannot be found, however good it is. Check any page — or all of them — and see exactly which ones are missing.',
							'zinn-seo'
						) }
					</p>
					<Loaded load={ overview } buyUrl={ ctx.buyUrl }>
						{ ( o ) => (
							<dl className="zseo-stats">
								<div className="zseo-stats__item">
									<dt>{ stateWords( 'yes' ) }</dt>
									<dd className="zseo-ok">
										{ count( o.counts.yes ) }
									</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ stateWords( 'no' ) }</dt>
									<dd>{ count( o.counts.no ) }</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ stateWords( 'never' ) }</dt>
									<dd>
										{ count(
											Math.max(
												0,
												o.pages -
													o.counts.yes -
													o.counts.no -
													o.counts.unknown
											)
										) }
									</dd>
								</div>
							</dl>
						) }
					</Loaded>
				</CardBody>
			</Card>
			{ overview.data && (
				<SiteCheck
					overview={ overview.data }
					ctx={ ctx }
					reload={ () => {
						overview.reload();
					} }
				/>
			) }
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'Check some pages', 'zinn-seo' ) }
					</h2>
					<Refusal
						error={ action.error }
						buyUrl={ ctx.buyUrl }
						onRemove={ action.clear }
					/>
					{ run && (
						<Notice
							status={
								'queued' === run.status ? 'info' : 'success'
							}
							isDismissible={ 'queued' !== run.status }
							onRemove={ () => setRun( null ) }
							className="zseo-indexrun"
						>
							{ 'queued' === run.status
								? sprintf(
										/* translators: %s: a number of pages. */
										_n(
											'Asking Google about %s page… this takes about a minute.',
											'Asking Google about %s pages… this takes about a minute.',
											run.urls,
											'zinn-seo'
										),
										count( run.urls )
									)
								: sprintf(
										/* translators: 1: pages in Google, 2: pages not in Google, 3: pages with no answer, 4: an amount of money. */
										__(
											'Done: %1$s in Google, %2$s not, %3$s with no answer (refunded). Cost %4$s.',
											'zinn-seo'
										),
										count( run.indexed ),
										count( run.not_indexed ),
										count( run.unknown ),
										money(
											run.charged_micros -
												run.refunded_micros
										)
									) }
						</Notice>
					) }
					<div className="zseo-row">
						<SearchControl
							__nextHasNoMarginBottom
							label={ __( 'Find a page', 'zinn-seo' ) }
							value={ search }
							onChange={ setSearch }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Show', 'zinn-seo' ) }
							value={ filter }
							options={ [
								{
									value: '',
									label: __( 'Every page', 'zinn-seo' ),
								},
								{ value: 'no', label: stateWords( 'no' ) },
								{ value: 'yes', label: stateWords( 'yes' ) },
								{
									value: 'never',
									label: stateWords( 'never' ),
								},
								{
									value: 'unknown',
									label: stateWords( 'unknown' ),
								},
							] }
							onChange={ setFilter }
						/>
					</div>
					{ pages.items.length > 0 ? (
						<Table
							label={ __( 'Pages of this site', 'zinn-seo' ) }
							rowKey={ ( p ) => p.id }
							rows={ pages.items }
							columns={ [
								{
									id: 'page',
									label: __( 'Page', 'zinn-seo' ),
									render: ( p ) => (
										<CheckboxControl
											__nextHasNoMarginBottom
											label={ p.title || p.url }
											checked={ picked.has( p.id ) }
											disabled={
												p.hidden || ! ctx.canSpend
											}
											onChange={ ( on ) =>
												toggle( p.id, on )
											}
										/>
									),
								},
								{
									id: 'state',
									label: __( 'Last answer', 'zinn-seo' ),
									render: ( p ) =>
										p.hidden ? (
											<span className="zseo-muted">
												{ __(
													'Hidden from search engines by you',
													'zinn-seo'
												) }
											</span>
										) : (
											<>
												<span
													className={ `zseo-tag ${
														STATES[ p.state ].cls
													}` }
												>
													{ stateWords( p.state ) }
												</span>
												{ p.at > 0 && (
													<span className="zseo-muted zseo-small">
														{ ' ' }
														{ day( p.at ) }
													</span>
												) }
											</>
										),
								},
								{
									id: 'do',
									label: __( 'Open', 'zinn-seo' ),
									render: ( p ) => (
										<span className="zseo-inline">
											<a href={ p.url }>
												{ __( 'View', 'zinn-seo' ) }
											</a>
											{ p.edit && (
												<a href={ p.edit }>
													{ __( 'Edit', 'zinn-seo' ) }
												</a>
											) }
										</span>
									),
								},
							] }
						/>
					) : (
						! pages.loading && (
							<Empty>
								{ __( 'No page matches.', 'zinn-seo' ) }
							</Empty>
						)
					) }
					{ pages.next > 0 && (
						<div>
							<Button
								variant="secondary"
								isBusy={ pages.loading }
								onClick={ () => loadPages( pages.next ) }
							>
								{ __( 'Show more pages', 'zinn-seo' ) }
							</Button>
						</div>
					) }
					{ ctx.canSpend && (
						<>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __(
									'Or paste addresses of this site (one per line)',
									'zinn-seo'
								) }
								rows={ 2 }
								value={ other }
								onChange={ setOther }
							/>
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant="primary"
									disabled={
										! urls.length ||
										!! action.busy ||
										( run && 'queued' === run.status )
									}
									isBusy={ 'check' === action.busy }
									onClick={ check }
								>
									{ sprintf(
										/* translators: %s: a number of pages. */
										_n(
											'Check %s page',
											'Check %s pages',
											urls.length || 1,
											'zinn-seo'
										),
										count( urls.length || 1 )
									) }
								</Button>
								<Cost
									cost={ urls.length ? cost : unitPrice }
									balance={
										ctx.account
											? ctx.account.balance_micros
											: null
									}
									each={
										urls.length
											? ''
											: unitEach( 'index_check' )
									}
								/>
							</div>
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
