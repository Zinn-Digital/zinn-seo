/**
 * Links → Link plan (Pro, zs-763): the whole site's internal links planned like an SEO expert,
 * reviewed (all, by cluster or one by one), applied as revisions, undone in one click.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import { JobLine, Pill, RunButton, useJob } from '../p4/shared';
import GuideLink from '../p7/GuideLink';
import { confirmBulk } from '../bulk/confirmBulk';
import './ail1.scss';

/**
 * The query string of an items request (pure).
 *
 * @param {number} run    Run id.
 * @param {Object} filter `{status, cluster, kind}`.
 * @param {number} after  Keyset cursor.
 * @return {string} Path.
 */
export function itemsPath( run, filter, after = 0 ) {
	const q = new URLSearchParams();
	q.set( 'status', filter.status || '' );
	q.set(
		'cluster',
		filter.cluster === undefined || filter.cluster === null
			? '-1'
			: String( filter.cluster )
	);
	q.set( 'kind', filter.kind || '' );
	q.set( 'after', String( after || 0 ) );
	q.set( 'per', '50' );
	return `/links/plan/${ run }/items?${ q.toString() }`;
}

/**
 * A sentence split around its link words, for highlighting (pure).
 *
 * @param {string} sentence Sentence.
 * @param {string} anchor   Link words.
 * @return {Array<string>} [before, words, after]; words '' when not found.
 */
export function splitAnchor( sentence, anchor ) {
	const s = sentence || '';
	const a = ( anchor || '' ).trim();
	if ( ! a ) {
		return [ s, '', '' ];
	}
	const at = s.toLowerCase().indexOf( a.toLowerCase() );
	if ( at < 0 ) {
		return [ s, '', '' ];
	}
	return [
		s.slice( 0, at ),
		s.slice( at, at + a.length ),
		s.slice( at + a.length ),
	];
}

/**
 * A price in micro-USD as dollars (pure).
 *
 * @param {number} micros Micro-USD.
 * @return {string} "$0.42".
 */
export function dollars( micros ) {
	const v = Math.max( 0, Number( micros ) || 0 ) / 1e6;
	return `$${ v < 0.01 && v > 0 ? v.toFixed( 4 ) : v.toFixed( 2 ) }`;
}

/**
 * Why a link is proposed, in plain words.
 *
 * @param {string} kind Kind.
 * @return {string} Words.
 */
export function kindText( kind ) {
	switch ( kind ) {
		case 'orphan':
			return __( 'Links a page nothing linked to', 'zinn-seo' );
		case 'hub':
			return __( 'Up to the hub of its topic', 'zinn-seo' );
		case 'spoke':
			return __( 'From the hub to a page of its topic', 'zinn-seo' );
		case 'new_in':
			return __( 'To a new page', 'zinn-seo' );
		case 'new_out':
			return __( 'From a new page', 'zinn-seo' );
		default:
			return __( 'Closely related page', 'zinn-seo' );
	}
}

/**
 * A status in plain words.
 *
 * @param {string} status Status.
 * @return {string} Words.
 */
export function statusText( status ) {
	switch ( status ) {
		case 'proposed':
			return __( 'To review', 'zinn-seo' );
		case 'approved':
			return __( 'Approved', 'zinn-seo' );
		case 'applied':
			return __( 'On the page', 'zinn-seo' );
		case 'rejected':
			return __( 'Rejected', 'zinn-seo' );
		case 'ai':
			return __( 'Waiting for the AI to choose words', 'zinn-seo' );
		case 'failed':
			return __( 'Not possible', 'zinn-seo' );
		case 'undone':
			return __( 'Undone', 'zinn-seo' );
		default:
			return status;
	}
}

/**
 * Why a link could not be added, in plain words.
 *
 * @param {string} note Note code.
 * @return {string} Words.
 */
export function noteText( note ) {
	switch ( note ) {
		case 'target_unfit':
			return __(
				'The page it pointed to is no longer published, is hidden from search or redirects elsewhere.',
				'zinn-seo'
			);
		case 'already_linked':
			return __( 'The page already links there.', 'zinn-seo' );
		case 'words_gone':
			return __(
				'The words are no longer in the page (it was edited).',
				'zinn-seo'
			);
		case 'no_place':
			return __(
				'The AI found no sentence where this link reads naturally.',
				'zinn-seo'
			);
		case 'kept_changed':
			return __(
				'Kept: someone changed this link after it was added.',
				'zinn-seo'
			);
		case 'source_gone':
			return __( 'The page is no longer published.', 'zinn-seo' );
		case 'ai':
			return __( 'Words chosen by the AI.', 'zinn-seo' );
		default:
			return note || '';
	}
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function LinkPlanTab( { data } ) {
	const [ overview, setOverview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ runId, setRunId ] = useState( null );
	const [ n, setN ] = useState( 0 );
	const reload = () => setN( ( x ) => x + 1 );
	const [ build, setBuild ] = useJob(
		() => seoFetch( '/links/plan' ).then( ( r ) => r.jobs.build ),
		reload
	);
	useEffect( () => {
		seoFetch( '/links/plan' )
			.then( ( r ) => {
				setOverview( r );
				setBuild( r.jobs.build );
				if ( runId === null ) {
					setRunId( r.latest ? r.latest.id : 0 );
				}
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ n ] );
	if ( error && ! overview ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! overview ) {
		return <Spinner />;
	}
	const health = overview.health;
	const building = build && build.status === 'pending';
	return (
		<div className="zseo-stack zseo-plan">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'A plan for the internal links of the whole site, made the way an SEO expert works: each topic gets a hub page, pages link up to their hub and across to the pages they are closest to, every page nothing links to gets a link, each page gets the number of links its length carries, and the link words are varied, natural and taken from your own sentences. Nothing changes until you approve it; every page changed keeps a revision, and a whole run undoes in one click.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="linkplan" />
			</p>
			<div className="zseo-plan__health">
				<div>
					<span className="zseo-plan__label">
						{ __( 'Site link health', 'zinn-seo' ) }
					</span>
					{ health ? (
						<Pill
							score={ health.score }
							label={ String( health.score ) }
						/>
					) : (
						<span className="zseo-muted">
							{ __( 'Build a plan to measure it.', 'zinn-seo' ) }
						</span>
					) }
				</div>
				{ health && health.sums && (
					<p className="zseo-muted">
						{ sprintf(
							/* translators: 1: pages, 2: orphan pages, 3: dead-end pages. */
							__(
								'Measured on %1$d pages: %2$d with no link pointing to them, %3$d linking nowhere.',
								'zinn-seo'
							),
							health.sums.pages || 0,
							health.sums.orphans || 0,
							health.sums.deadends || 0
						) }
					</p>
				) }
			</div>
			{ ! overview.indexBuilt && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Build the internal-link index first (Overview tab). The plan reads it.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ data.canManage && overview.indexBuilt && (
				<p className="zseo-plan__actions">
					<RunButton
						variant={ overview.latest ? 'secondary' : 'primary' }
						onError={ setError }
						disabled={ building }
						run={ () =>
							seoFetch( '/links/plan', { method: 'POST' } ).then(
								( r ) => {
									setBuild( r.job );
									setRunId( r.run );
									reload();
								}
							)
						}
					>
						{ overview.latest
							? __( 'Build a new plan', 'zinn-seo' )
							: __( 'Build the link plan', 'zinn-seo' ) }
					</RunButton>
					<span className="zseo-muted">
						{ sprintf(
							/* translators: %d: number of pages. */
							_n(
								'%d page will be read. It runs in the background and costs nothing.',
								'%d pages will be read. It runs in the background and costs nothing.',
								overview.pages,
								'zinn-seo'
							),
							overview.pages
						) }
					</span>
				</p>
			) }
			<JobLine job={ build } />
			{ ( overview.runs.length > 0 ||
				( overview.newPages &&
					Object.values( overview.newPages.counts || {} ).some(
						( x ) => x > 0
					) ) ) && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Plan', 'zinn-seo' ) }
					value={ String( runId ) }
					onChange={ ( v ) => setRunId( Number( v ) ) }
					options={ [
						...overview.runs.map( ( r ) => ( {
							value: String( r.id ),
							label: sprintf(
								/* translators: 1: run number, 2: date. */
								__( 'Plan %1$d, %2$s', 'zinn-seo' ),
								r.id,
								new Date( r.created * 1000 ).toLocaleString()
							),
						} ) ),
						{
							value: '0',
							label: __( 'New pages (kept current)', 'zinn-seo' ),
						},
					] }
				/>
			) }
			{ runId !== null && ( overview.runs.length > 0 || runId === 0 ) && (
				<Run
					key={ `${ runId }-${ n }` }
					run={ runId }
					data={ data }
					onError={ setError }
					onChange={ reload }
				/>
			) }
			{ data.canManage && <PlanSettings /> }
		</div>
	);
}

/**
 * One run: counts, clusters, the AI step, the proposals.
 *
 * @param {Object}   props
 * @param {number}   props.run      Run id.
 * @param {Object}   props.data     Boot data.
 * @param {Function} props.onError  Error.
 * @param {Function} props.onChange Something changed.
 * @return {Element} Run.
 */
function Run( { run, data, onError, onChange } ) {
	const [ view, setView ] = useState( null );
	const [ filter, setFilter ] = useState( { status: 'proposed' } );
	const [ k, setK ] = useState( 0 );
	const refresh = () => setK( ( x ) => x + 1 );
	const [ apply, setApply ] = useJob(
		() => seoFetch( '/links/plan' ).then( ( r ) => r.jobs.apply ),
		refresh
	);
	const [ words, setWords ] = useJob(
		() => seoFetch( '/links/plan' ).then( ( r ) => r.jobs.words ),
		refresh
	);
	const [ price, setPrice ] = useState( null );
	useEffect( () => {
		seoFetch( `/links/plan/${ run }` )
			.then( setView )
			.catch( ( e ) => onError( errorText( e ) ) );
		seoFetch( '/links/plan' )
			.then( ( r ) => {
				setApply( r.jobs.apply );
				setWords( r.jobs.words );
			} )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ run, k ] );
	if ( ! view ) {
		return <Spinner />;
	}
	const c = view.counts;
	const busy =
		( apply && apply.status === 'pending' ) ||
		( words && words.status === 'pending' );
	const review = ( body ) =>
		seoFetch( `/links/plan/${ run }/review`, {
			method: 'POST',
			data: body,
		} ).then( () => {
			refresh();
			onChange();
		} );
	return (
		<div className="zseo-stack">
			<dl className="zseo-totals zseo-plan__counts">
				{ [
					'proposed',
					'approved',
					'applied',
					'ai',
					'rejected',
					'failed',
					'undone',
				].map(
					( s ) =>
						( c[ s ] > 0 ||
							[ 'proposed', 'approved', 'applied' ].includes(
								s
							) ) && (
							<div key={ s }>
								<dt>{ statusText( s ) }</dt>
								<dd>{ c[ s ] }</dd>
							</div>
						)
				) }
			</dl>
			{ run > 0 && view.run.stuck > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of pages. */
						_n(
							'%d page shares no category or tag with any other page, so no page is related enough to link to it. Give it a category, then build the plan again.',
							'%d pages share no category or tag with any other page, so no page is related enough to link to them. Give them a category, then build the plan again.',
							view.run.stuck,
							'zinn-seo'
						),
						view.run.stuck
					) }
				</Notice>
			) }
			{ data.canManage && view.words.links > 0 && (
				<div className="zseo-plan__ai">
					<p>
						{ sprintf(
							/* translators: 1: links, 2: pages. */
							__(
								'%1$d important links (to hub pages and pages nothing links to) have no natural words in the page yet. The AI can choose the best sentence and words on %2$d pages; you review them before anything changes.',
								'zinn-seo'
							),
							view.words.links,
							view.words.pages
						) }
					</p>
					{ price && (
						<p className="zseo-muted">
							{ price.credits
								? sprintf(
										/* translators: %s: a price in dollars. */
										__(
											'About %s from your Zinn® credits.',
											'zinn-seo'
										),
										dollars( price.price * price.pages )
									)
								: __(
										'Billed by your own AI provider, on your key.',
										'zinn-seo'
									) }
						</p>
					) }
					{ ! price ? (
						<RunButton
							onError={ onError }
							run={ () =>
								seoFetch( `/links/plan/${ run }/words`, {
									method: 'POST',
									data: { dry: true },
								} ).then( setPrice )
							}
						>
							{ __( 'See what it costs', 'zinn-seo' ) }
						</RunButton>
					) : (
						<RunButton
							variant="primary"
							disabled={ busy || ! price.ready }
							onError={ onError }
							run={ () =>
								seoFetch( `/links/plan/${ run }/words`, {
									method: 'POST',
								} ).then( ( r ) => setWords( r.job ) )
							}
						>
							{ __( 'Let the AI choose the words', 'zinn-seo' ) }
						</RunButton>
					) }
					{ price && ! price.ready && (
						<p className="zseo-muted">
							<a href={ data.aiSettingsUrl }>
								{ __(
									'Connect AI first: Zinn® credits or your own key.',
									'zinn-seo'
								) }
							</a>
						</p>
					) }
				</div>
			) }
			<JobLine job={ words } />
			{ words && words.status === 'paused' && words.error && (
				<Notice status="warning" isDismissible={ false }>
					{ words.error }
				</Notice>
			) }
			{ data.canManage && (
				<p className="zseo-plan__actions">
					<RunButton
						variant="secondary"
						disabled={ busy || c.proposed === 0 }
						onError={ onError }
						run={ () => review( { action: 'approve', all: true } ) }
					>
						{ sprintf(
							/* translators: %d: number of links. */
							__( 'Approve all %d', 'zinn-seo' ),
							c.proposed
						) }
					</RunButton>
					<RunButton
						variant="primary"
						disabled={ busy || c.approved === 0 }
						onError={ onError }
						run={ () =>
							confirmBulk( view.pages?.approved ?? 0 )
								? seoFetch( `/links/plan/${ run }/apply`, {
										method: 'POST',
									} ).then( ( r ) => setApply( r.job ) )
								: undefined
						}
					>
						{ sprintf(
							/* translators: %d: number of links. */
							_n(
								'Add %d approved link to the pages',
								'Add %d approved links to the pages',
								c.approved,
								'zinn-seo'
							),
							c.approved
						) }
					</RunButton>
					<RunButton
						variant="tertiary"
						isDestructive
						disabled={ busy || c.applied === 0 }
						onError={ onError }
						run={ () =>
							seoFetch( `/links/plan/${ run }/undo`, {
								method: 'POST',
							} ).then( ( r ) => setApply( r.job ) )
						}
					>
						{ sprintf(
							/* translators: %d: number of links. */
							_n(
								'Undo this run (%d link)',
								'Undo this run (%d links)',
								c.applied,
								'zinn-seo'
							),
							c.applied
						) }
					</RunButton>
				</p>
			) }
			<JobLine job={ apply } />
			{ view.clusters.length > 0 && (
				<Clusters
					clusters={ view.clusters }
					canManage={ data.canManage }
					onReview={ ( id, action ) =>
						review( { action, cluster: id } ).catch( ( e ) =>
							onError( errorText( e ) )
						)
					}
					onShow={ ( id ) => setFilter( { ...filter, cluster: id } ) }
				/>
			) }
			<Items
				run={ run }
				filter={ filter }
				setFilter={ setFilter }
				canManage={ data.canManage }
				onReview={ ( body ) =>
					review( body ).catch( ( e ) => onError( errorText( e ) ) )
				}
				version={ k }
			/>
		</div>
	);
}

/**
 * The clusters (topics) with their hubs.
 *
 * @param {Object}   props
 * @param {Array}    props.clusters  Clusters.
 * @param {boolean}  props.canManage May change.
 * @param {Function} props.onReview  (cluster, action).
 * @param {Function} props.onShow    Filter to a cluster.
 * @return {Element} Table.
 */
function Clusters( { clusters, canManage, onReview, onShow } ) {
	return (
		<details className="zseo-plan__clusters">
			<summary>
				{ sprintf(
					/* translators: %d: number of topics. */
					_n(
						'%d topic and its hub page',
						'%d topics and their hub pages',
						clusters.length,
						'zinn-seo'
					),
					clusters.length
				) }
			</summary>
			<div className="zseo-table-wrap">
				<table className="widefat striped zseo-table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Topic', 'zinn-seo' ) }</th>
							<th scope="col">
								{ __( 'Hub page', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'To review', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'Approved', 'zinn-seo' ) }
							</th>
							<th scope="col">{ __( 'Actions', 'zinn-seo' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ clusters.map( ( cl ) => (
							<tr key={ cl.id }>
								<td>{ cl.name }</td>
								<td>{ cl.hubTitle || '—' }</td>
								<td>{ cl.counts.proposed }</td>
								<td>{ cl.counts.approved }</td>
								<td className="zseo-plan__row-actions">
									<Button
										variant="link"
										onClick={ () => onShow( cl.id ) }
									>
										{ __( 'Show', 'zinn-seo' ) }
									</Button>
									{ canManage && cl.counts.proposed > 0 && (
										<Button
											variant="link"
											onClick={ () =>
												onReview( cl.id, 'approve' )
											}
										>
											{ __(
												'Approve topic',
												'zinn-seo'
											) }
										</Button>
									) }
									{ canManage && cl.counts.proposed > 0 && (
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												onReview( cl.id, 'reject' )
											}
										>
											{ __( 'Reject topic', 'zinn-seo' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</details>
	);
}

/**
 * The proposals, keyset-paged, to review one by one.
 *
 * @param {Object}   props
 * @param {number}   props.run       Run.
 * @param {Object}   props.filter    Filter.
 * @param {Function} props.setFilter Change the filter.
 * @param {boolean}  props.canManage May change.
 * @param {Function} props.onReview  Review a set.
 * @param {number}   props.version   Reload when it changes.
 * @return {Element} List.
 */
function Items( { run, filter, setFilter, canManage, onReview, version } ) {
	const [ rows, setRows ] = useState( null );
	const [ next, setNext ] = useState( 0 );
	const [ picked, setPicked ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const load = ( after ) =>
		seoFetch( itemsPath( run, filter, after ) )
			.then( ( r ) => {
				setRows( after ? [ ...( rows || [] ), ...r.items ] : r.items );
				setNext( r.next );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		setPicked( [] );
		load( 0 );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ run, filter.status, filter.cluster, filter.kind, version ] );
	const toggle = ( id, on ) =>
		setPicked(
			on ? [ ...picked, id ] : picked.filter( ( x ) => x !== id )
		);
	return (
		<div className="zseo-stack">
			<div className="zseo-plan__filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Show', 'zinn-seo' ) }
					value={ filter.status || '' }
					onChange={ ( v ) => setFilter( { ...filter, status: v } ) }
					options={ [
						'proposed',
						'approved',
						'applied',
						'ai',
						'rejected',
						'failed',
						'undone',
					].map( ( s ) => ( { value: s, label: statusText( s ) } ) ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Why', 'zinn-seo' ) }
					value={ filter.kind || '' }
					onChange={ ( v ) => setFilter( { ...filter, kind: v } ) }
					options={ [
						{ value: '', label: __( 'Every reason', 'zinn-seo' ) },
						...[
							'orphan',
							'hub',
							'spoke',
							'related',
							'new_in',
							'new_out',
						].map( ( x ) => ( {
							value: x,
							label: kindText( x ),
						} ) ),
					] }
				/>
				{ filter.cluster !== undefined && filter.cluster !== null && (
					<Button
						variant="tertiary"
						onClick={ () =>
							setFilter( { ...filter, cluster: null } )
						}
					>
						{ __( 'Every topic', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ ! rows && <Spinner /> }
			{ rows && rows.length === 0 && (
				<p className="zseo-muted">
					{ __( 'Nothing here.', 'zinn-seo' ) }
				</p>
			) }
			{ canManage && picked.length > 0 && (
				<p className="zseo-plan__actions">
					<Button
						variant="secondary"
						onClick={ () =>
							onReview( { action: 'approve', ids: picked } )
						}
					>
						{ sprintf(
							/* translators: %d: number of links. */
							__( 'Approve %d picked', 'zinn-seo' ),
							picked.length
						) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () =>
							onReview( { action: 'reject', ids: picked } )
						}
					>
						{ sprintf(
							/* translators: %d: number of links. */
							__( 'Reject %d picked', 'zinn-seo' ),
							picked.length
						) }
					</Button>
				</p>
			) }
			{ rows && rows.length > 0 && (
				<ul className="zseo-plan__items">
					{ rows.map( ( r ) => {
						const [ a, w, b ] = splitAnchor( r.sentence, r.anchor );
						return (
							<li key={ r.id } className="zseo-plan__item">
								{ canManage &&
									[ 'proposed', 'approved' ].includes(
										r.status
									) && (
										<input
											type="checkbox"
											className="zseo-plan__pick"
											aria-label={ sprintf(
												/* translators: 1: page, 2: page. */
												__(
													'Pick: %1$s to %2$s',
													'zinn-seo'
												),
												r.sourceTitle,
												r.targetTitle
											) }
											checked={ picked.includes( r.id ) }
											onChange={ ( e ) =>
												toggle( r.id, e.target.checked )
											}
										/>
									) }
								<div className="zseo-plan__item-body">
									<p className="zseo-plan__pages">
										<a href={ r.sourceEdit }>
											{ r.sourceTitle }
										</a>
										<span aria-hidden="true"> → </span>
										<span className="screen-reader-text">
											{ __( 'links to', 'zinn-seo' ) }
										</span>
										<a
											href={ r.targetUrl }
											target="_blank"
											rel="noreferrer"
										>
											{ r.targetTitle }
										</a>
									</p>
									{ r.sentence ? (
										<blockquote className="zseo-plan__sentence">
											{ a }
											{ w && <mark>{ w }</mark> }
											{ b }
										</blockquote>
									) : (
										<p className="zseo-muted">
											{ statusText( 'ai' ) }
										</p>
									) }
									<p className="zseo-plan__meta">
										<span className="zseo-tag">
											{ kindText( r.kind ) }
										</span>{ ' ' }
										<span className="zseo-tag">
											{ statusText( r.status ) }
										</span>
										{ r.note && (
											<span className="zseo-muted">
												{ ' ' }
												{ noteText( r.note ) }
											</span>
										) }
									</p>
								</div>
								{ canManage && r.status === 'proposed' && (
									<span className="zseo-plan__row-actions">
										<Button
											variant="secondary"
											size="compact"
											onClick={ () =>
												onReview( {
													action: 'approve',
													ids: [ r.id ],
												} )
											}
										>
											{ __( 'Approve', 'zinn-seo' ) }
										</Button>
										<Button
											variant="tertiary"
											size="compact"
											isDestructive
											onClick={ () =>
												onReview( {
													action: 'reject',
													ids: [ r.id ],
												} )
											}
										>
											{ __( 'Reject', 'zinn-seo' ) }
										</Button>
									</span>
								) }
							</li>
						);
					} ) }
				</ul>
			) }
			{ next > 0 && (
				<Button variant="secondary" onClick={ () => load( next ) }>
					{ __( 'Show more', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * The plan's settings.
 *
 * @return {Element} Card.
 */
function PlanSettings() {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	useEffect( () => {
		if ( settings && ! draft ) {
			setDraft( {
				linkplan_words_per_link: settings.linkplan_words_per_link,
				linkplan_max_links: settings.linkplan_max_links,
				linkplan_exact_share: settings.linkplan_exact_share,
				linkplan_auto_new: settings.linkplan_auto_new,
			} );
		}
	}, [ settings, draft ] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! draft ) {
		return null;
	}
	const set = ( part ) => {
		setSaved( false );
		setDraft( { ...draft, ...part } );
	};
	return (
		<details className="zseo-plan__settings">
			<summary>{ __( 'Plan settings', 'zinn-seo' ) }</summary>
			<div className="zseo-stack">
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 50 }
					max={ 1000 }
					label={ __( 'Words of text per link', 'zinn-seo' ) }
					help={ __(
						'A page gets one link for every this many words: a short page a couple, a long guide more.',
						'zinn-seo'
					) }
					value={ String( draft.linkplan_words_per_link ) }
					onChange={ ( v ) =>
						set( { linkplan_words_per_link: Number( v ) } )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 1 }
					max={ 50 }
					label={ __( 'Most links on one page', 'zinn-seo' ) }
					value={ String( draft.linkplan_max_links ) }
					onChange={ ( v ) =>
						set( { linkplan_max_links: Number( v ) } )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					max={ 100 }
					label={ __(
						'Most links to one page that repeat its main phrase exactly (%)',
						'zinn-seo'
					) }
					help={ __(
						'Varied link words read naturally; the same exact phrase on every link looks over-optimised.',
						'zinn-seo'
					) }
					value={ String( draft.linkplan_exact_share ) }
					onChange={ ( v ) =>
						set( { linkplan_exact_share: Number( v ) } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Add the links for new pages automatically',
						'zinn-seo'
					) }
					help={ __(
						'When you publish a page, its links in and out are planned straight away. With this on they are added without a review (each one a revision you can undo); with it off they wait in "New pages" for you.',
						'zinn-seo'
					) }
					checked={ !! draft.linkplan_auto_new }
					onChange={ ( v ) => set( { linkplan_auto_new: v } ) }
				/>
				<p>
					<Button
						variant="primary"
						onClick={ () =>
							save( draft ).then( () => setSaved( true ) )
						}
					>
						{ __( 'Save', 'zinn-seo' ) }
					</Button>
					{ saved && (
						<span className="zseo-muted" role="status">
							{ ' ' }
							{ __( 'Saved.', 'zinn-seo' ) }
						</span>
					) }
				</p>
			</div>
		</details>
	);
}
