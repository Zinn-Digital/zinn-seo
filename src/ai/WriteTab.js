/**
 * Write with AI: suggestions for the whole site (Pro) and the review queue where a person
 * accepts, edits or throws away each one (zs-190, zs-191, zs-433, zs-119, zs-063, zs-200).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { seoFetch, errorText } from '../admin/api';
import ProTeaser from '../admin/ProTeaser';
import GuideLink from '../admin/p7/GuideLink';
import { money } from '../tools/pure';
import { AiFailure, failureOf, PaidBy } from './parts';

/** Tasks a whole-site run can do, in the order shown. */
const BULK = [ 'meta', 'alt', 'product', 'llms' ];

/**
 * A task's name.
 *
 * @param {Object} ai   AI state.
 * @param {string} task Task id.
 * @return {string} Label.
 */
function taskLabel( ai, task ) {
	const def = ( ai.tasks || [] ).find( ( t ) => t.id === task );
	return def ? def.label : task;
}

/**
 * What a field is called on the review screen.
 *
 * @param {string} name Field.
 * @return {string} Label.
 */
function fieldLabel( name ) {
	const labels = {
		title: __( 'SEO title', 'zinn-seo' ),
		desc: __( 'Meta description', 'zinn-seo' ),
		alt: __( 'Alt text', 'zinn-seo' ),
		name: __( 'Product name', 'zinn-seo' ),
		short: __( 'Short description', 'zinn-seo' ),
		long: __( 'Description', 'zinn-seo' ),
	};
	return labels[ name ] || name;
}

/**
 * One suggestion: what the page has now, what the AI wrote (editable), accept or discard.
 *
 * @param {Object}   props
 * @param {Object}   props.row    Queue row.
 * @param {string}   props.task   Task id.
 * @param {Function} props.onDone Called after accept or discard.
 * @return {Element} Row.
 */
function Suggestion( { row, task, onDone } ) {
	const [ fields, setFields ] = useState( row.fields );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const decide = ( action ) => {
		setBusy( action );
		setError( '' );
		seoFetch( `/ai/suggestions/${ row.id }`, {
			method: 'POST',
			data: {
				task,
				action,
				...( action === 'accept' ? { fields } : {} ),
			},
		} )
			.then( () => onDone( row.id, action ) )
			.catch( ( e ) => {
				setError( errorText( e ) );
				setBusy( '' );
			} );
	};
	const editable = Object.keys( fields ).filter(
		( k ) => typeof fields[ k ] === 'string'
	);
	return (
		<li className="zseo-sugg">
			<div className="zseo-sugg__head">
				{ row.thumb && (
					<img
						className="zseo-sugg__thumb"
						src={ row.thumb }
						alt=""
						width="48"
						height="48"
					/>
				) }
				<a href={ row.edit } className="zseo-sugg__title">
					{ row.title || __( '(no title)', 'zinn-seo' ) }
				</a>
			</div>
			{ task === 'llms' && fields.summaries && (
				<ul className="zseo-sugg__langs">
					{ Object.entries( fields.summaries ).map(
						( [ lang, t ] ) => (
							<li key={ lang }>
								<strong>{ lang }</strong> { t }
							</li>
						)
					) }
				</ul>
			) }
			{ editable.map( ( name ) => {
				const now = ( row.current || {} )[ name ];
				const Control =
					( fields[ name ] || '' ).length > 120
						? TextareaControl
						: TextControl;
				return (
					<div key={ name } className="zseo-sugg__field">
						<Control
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ fieldLabel( name ) }
							value={ fields[ name ] }
							onChange={ ( v ) =>
								setFields( { ...fields, [ name ]: v } )
							}
							help={
								now
									? sprintf(
											/* translators: %s: the text the page has now. */
											__( 'Now: %s', 'zinn-seo' ),
											now
										)
									: __( 'Now: none of its own', 'zinn-seo' )
							}
						/>
					</div>
				);
			} ) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="zseo-actions zseo-actions--start">
				<Button
					variant="primary"
					isBusy={ busy === 'accept' }
					disabled={ !! busy }
					onClick={ () => decide( 'accept' ) }
				>
					{ __( 'Use this', 'zinn-seo' ) }
				</Button>
				<Button
					variant="tertiary"
					isBusy={ busy === 'reject' }
					disabled={ !! busy }
					onClick={ () => decide( 'reject' ) }
				>
					{ __( 'Discard', 'zinn-seo' ) }
				</Button>
			</div>
		</li>
	);
}

/**
 * The review queue of one task.
 *
 * @param {Object}   props
 * @param {string}   props.task      Task id.
 * @param {boolean}  props.pro       Pro (can use all at once).
 * @param {Function} props.onUseAll  Start "use them all".
 * @param {number}   props.reloadKey Changes to reload.
 * @return {Element} Queue.
 */
function Queue( { task, pro, onUseAll, reloadKey } ) {
	const [ page, setPage ] = useState( null );
	const [ error, setError ] = useState( '' );
	const load = useCallback(
		( after = 0 ) =>
			seoFetch( `/ai/suggestions?task=${ task }&after=${ after }` )
				.then( ( p ) =>
					setPage( ( old ) =>
						after > 0 && old
							? { ...p, items: [ ...old.items, ...p.items ] }
							: p
					)
				)
				.catch( ( e ) => setError( errorText( e ) ) ),
		[ task ]
	);
	useEffect( () => {
		setPage( null );
		load();
	}, [ load, reloadKey ] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! page ) {
		return null;
	}
	const done = ( id ) =>
		setPage( {
			...page,
			total: page.total - 1,
			items: page.items.filter( ( r ) => r.id !== id ),
		} );
	return (
		<div className="zseo-stack">
			<div className="zseo-cardhead">
				<h3 className="zseo-card-title">
					{ page.total > 0
						? sprintf(
								/* translators: %d: how many suggestions wait for review. */
								_n(
									'%d suggestion to review',
									'%d suggestions to review',
									page.total,
									'zinn-seo'
								),
								page.total
							)
						: __( 'Nothing waiting for review', 'zinn-seo' ) }
				</h3>
				{ pro && page.total > 0 && (
					<Button variant="secondary" onClick={ onUseAll }>
						{ sprintf(
							/* translators: %d: how many suggestions. */
							_n(
								'Use %d suggestion',
								'Use all %d suggestions',
								page.total,
								'zinn-seo'
							),
							page.total
						) }
					</Button>
				) }
			</div>
			{ page.total > 0 && (
				<p className="zseo-muted">
					{ __(
						'Nothing on your site changes until you choose "Use this". Edit any suggestion first if you like.',
						'zinn-seo'
					) }
				</p>
			) }
			<ul className="zseo-suggs">
				{ page.items.map( ( row ) => (
					<Suggestion
						key={ row.id }
						row={ row }
						task={ task }
						onDone={ done }
					/>
				) ) }
			</ul>
			{ page.next > 0 && page.items.length < page.total && (
				<div>
					<Button
						variant="secondary"
						onClick={ () => load( page.next ) }
					>
						{ __( 'Show more', 'zinn-seo' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

/**
 * A job's progress and controls.
 *
 * @param {Object}   props
 * @param {Object}   props.job   Job status.
 * @param {string}   props.id    Job id.
 * @param {Object}   props.ai    AI state.
 * @param {Function} props.onCtl Pause, resume, cancel.
 * @param {string}   props.label What it is doing.
 * @return {Element|null} Panel.
 */
export function JobPanel( { job, id, ai, onCtl, label } ) {
	if ( ! job || job.status === 'idle' ) {
		return null;
	}
	const total = job.progress.total;
	const done = job.progress.done || 0;
	const pct = total
		? Math.min( 100, Math.round( ( done / total ) * 100 ) )
		: 0;
	const running = job.status === 'pending';
	const words = {
		pending: __( 'Working', 'zinn-seo' ),
		paused: __( 'Paused', 'zinn-seo' ),
		done: __( 'Finished', 'zinn-seo' ),
		cancelled: __( 'Stopped', 'zinn-seo' ),
		failed: __( 'Stopped by an error', 'zinn-seo' ),
	};
	const reasonAction = {
		top_up: 'top_up',
		account: 'account',
		connect: 'connect',
		fix_key: 'settings',
		settings: 'settings',
	}[ job.reason ];
	return (
		<div className="zseo-job" aria-live="polite">
			<p className="zseo-job__line">
				<strong>{ words[ job.status ] || job.status }</strong>
				{ ' · ' }
				{ label }
				{ ' · ' }
				{ total !== null
					? sprintf(
							/* translators: 1: items done, 2: items in all. */
							__( '%1$d of %2$d', 'zinn-seo' ),
							done,
							total
						)
					: done }
				{ job.cost > 0 &&
					' · ' +
						sprintf(
							/* translators: %s: an amount of money. */
							__( 'spent %s', 'zinn-seo' ),
							money( job.cost )
						) }
			</p>
			{ running && job.waiting && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %s: the name of the background job running now. */
						__(
							'Waiting for “%s” to finish: one background job runs at a time. This one starts by itself.',
							'zinn-seo'
						),
						job.waiting
					) }
				</p>
			) }
			{ total !== null && (
				<div
					className="zseo-bar"
					role="progressbar"
					aria-label={ __( 'Progress', 'zinn-seo' ) }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuenow={ pct }
				>
					<span style={ { inlineSize: `${ pct }%` } } />
				</div>
			) }
			{ job.status === 'paused' && job.error && (
				<AiFailure
					failure={ {
						kind: 'refused',
						message: job.error,
						link:
							reasonAction === 'settings'
								? ai.settings_url
								: ai.credits.buy_url,
						action: reasonAction || 'none',
					} }
					buyUrl={ ai.credits.buy_url }
				/>
			) }
			{ job.status === 'paused' && job.reason === 'top_up' && (
				<p className="zseo-muted">
					{ __(
						'It carries on by itself as soon as credit is added.',
						'zinn-seo'
					) }
				</p>
			) }
			{ job.status === 'failed' && job.error && (
				<Notice status="error" isDismissible={ false }>
					{ job.error }
				</Notice>
			) }
			<div className="zseo-actions zseo-actions--start">
				{ running && (
					<Button
						variant="secondary"
						onClick={ () => onCtl( id, 'pause' ) }
					>
						{ __( 'Pause', 'zinn-seo' ) }
					</Button>
				) }
				{ [ 'paused', 'failed' ].includes( job.status ) && (
					<Button
						variant="secondary"
						onClick={ () => onCtl( id, 'resume' ) }
					>
						{ __( 'Carry on', 'zinn-seo' ) }
					</Button>
				) }
				{ [ 'pending', 'paused', 'failed' ].includes( job.status ) && (
					<Button
						variant="tertiary"
						onClick={ () => onCtl( id, 'cancel' ) }
					>
						{ __( 'Stop', 'zinn-seo' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

/**
 * Keep the jobs' state fresh while one is working.
 *
 * @param {Object} initial Jobs from the boot data.
 * @return {Array} `[ jobs, setJobs, refresh ]`.
 */
export function useJobs( initial ) {
	const [ jobs, setJobs ] = useState( initial || {} );
	const refresh = useCallback(
		() =>
			seoFetch( '/ai/bulk' )
				.then( setJobs )
				.catch( () => {} ),
		[]
	);
	const working = Object.values( jobs ).some(
		( j ) => j.status === 'pending'
	);
	useEffect( () => {
		if ( ! working ) {
			return undefined;
		}
		const timer = window.setInterval( refresh, 3000 );
		return () => window.clearInterval( timer );
	}, [ working, refresh ] );
	return [ jobs, setJobs, refresh ];
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function WriteTab( { data } ) {
	const ai = data.ai;
	const pro = !! ai.pro;
	const tasks = BULK.filter( ( t ) =>
		( ai.tasks || [] ).some( ( d ) => d.id === t )
	);
	// Open on what the last whole-site run wrote, so its suggestions are the ones waiting here.
	const last = ai.jobs && ai.jobs[ 'ai-bulk' ] && ai.jobs[ 'ai-bulk' ].task;
	const [ task, setTask ] = useState(
		tasks.includes( last ) ? last : tasks[ 0 ] || 'meta'
	);
	const types = ai.types || [];
	const [ picked, setPicked ] = useState(
		types.filter( ( t ) => t.name !== 'product' ).map( ( t ) => t.name )
	);
	const [ missing, setMissing ] = useState( true );
	const [ count, setCount ] = useState( null );
	const [ failure, setFailure ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ jobs, setJobs, refresh ] = useJobs( ai.jobs );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	const scope = () => ( {
		task,
		missing,
		...( [ 'meta', 'llms' ].includes( task ) ? { types: picked } : {} ),
	} );
	const post = ( name, body ) => {
		setBusy( name );
		setFailure( null );
		return seoFetch( '/ai/bulk', { method: 'POST', data: body } )
			.then( ( r ) => {
				setBusy( '' );
				return r;
			} )
			.catch( ( e ) => {
				setBusy( '' );
				setFailure( failureOf( e ) );
				return null;
			} );
	};
	const control = ( id, action ) =>
		seoFetch( '/ai/bulk/control', {
			method: 'POST',
			data: { job: id, action },
		} )
			.then( setJobs )
			.catch( ( e ) => setFailure( failureOf( e ) ) );

	const generation = jobs[ 'ai-bulk' ];
	const accepting = jobs[ 'ai-accept' ];
	useEffect( () => {
		if (
			( generation && generation.status === 'done' ) ||
			( accepting && accepting.status === 'done' )
		) {
			setReloadKey( ( k ) => k + 1 );
		}
	}, [ generation && generation.status, accepting && accepting.status ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Let AI write it, then check it',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="ai-write" />
					</div>
					<p className="zseo-lead">
						{ __(
							'Titles and descriptions that earn the click, alt text for every image, product copy and AI summaries in every language — for one page in the editor, or the whole site at once. You review every suggestion before it goes live.',
							'zinn-seo'
						) }
					</p>
					{ ! pro && (
						<ProTeaser
							title={ __(
								'Write the whole site in one go',
								'zinn-seo'
							) }
							points={ [
								__(
									'Titles, descriptions and alt text for thousands of pages and images, ten at a time per request — fast and inexpensive.',
									'zinn-seo'
								),
								__(
									'Stops safely when credit runs out and carries on by itself after a top-up. Nothing is written twice.',
									'zinn-seo'
								),
								__(
									'Product names and descriptions for your whole shop, and AI summaries for llms.txt in every language.',
									'zinn-seo'
								),
							] }
						/>
					) }
					{ pro && (
						<div className="zseo-stack">
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'What to write', 'zinn-seo' ) }
								value={ task }
								options={ tasks.map( ( t ) => ( {
									value: t,
									label: taskLabel( ai, t ),
								} ) ) }
								onChange={ ( v ) => {
									setTask( v );
									setCount( null );
								} }
							/>
							{ [ 'meta', 'llms' ].includes( task ) &&
								types.length > 1 && (
									<fieldset className="zseo-types">
										<legend>
											{ __(
												'For these kinds of content',
												'zinn-seo'
											) }
										</legend>
										{ types.map( ( t ) => (
											<CheckboxControl
												__nextHasNoMarginBottom
												key={ t.name }
												label={ t.label }
												checked={ picked.includes(
													t.name
												) }
												onChange={ ( on ) => {
													setPicked(
														on
															? [
																	...picked,
																	t.name,
																]
															: picked.filter(
																	( n ) =>
																		n !==
																		t.name
																)
													);
													setCount( null );
												} }
											/>
										) ) }
									</fieldset>
								) }
							<ToggleControl
								__nextHasNoMarginBottom
								label={
									'alt' === task
										? __(
												'Only images with no alt text',
												'zinn-seo'
											)
										: __(
												'Only pages without their own',
												'zinn-seo'
											)
								}
								checked={ missing }
								onChange={ ( v ) => {
									setMissing( v );
									setCount( null );
								} }
							/>
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant="secondary"
									isBusy={ busy === 'count' }
									disabled={ !! busy }
									onClick={ () =>
										post( 'count', {
											...scope(),
											dry_run: true,
										} ).then(
											( r ) => r && setCount( r.count )
										)
									}
								>
									{ __( 'Count first', 'zinn-seo' ) }
								</Button>
								<Button
									variant="primary"
									isBusy={ busy === 'start' }
									disabled={
										!! busy ||
										( generation &&
											[ 'pending', 'paused' ].includes(
												generation.status
											) )
									}
									onClick={ () =>
										post( 'start', scope() ).then(
											( r ) => r && refresh()
										)
									}
								>
									{ __( 'Write suggestions', 'zinn-seo' ) }
								</Button>
								<PaidBy ai={ ai } />
							</div>
							{ count !== null && (
								<p className="zseo-count">
									{ sprintf(
										/* translators: %d: how many pages or images. */
										_n(
											'%d item would get a suggestion.',
											'%d items would get a suggestion.',
											count,
											'zinn-seo'
										),
										count
									) }
								</p>
							) }
							<AiFailure
								failure={ failure }
								buyUrl={ ai.credits.buy_url }
								onRemove={ () => setFailure( null ) }
							/>
							<JobPanel
								job={ generation }
								id="ai-bulk"
								ai={ ai }
								onCtl={ control }
								label={ taskLabel(
									ai,
									generation && generation.task
								) }
							/>
							<JobPanel
								job={ accepting }
								id="ai-accept"
								ai={ ai }
								onCtl={ control }
								label={ __( 'Using suggestions', 'zinn-seo' ) }
							/>
							{ accepting &&
								accepting.status === 'done' &&
								accepting.batch && (
									<p className="zseo-muted">
										{ __(
											'Changed your mind? Undo the whole batch from Bulk editor → History.',
											'zinn-seo'
										) }
									</p>
								) }
						</div>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					{ ! pro && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Suggestions for', 'zinn-seo' ) }
							value={ task }
							options={ [ 'meta', 'alt' ].map( ( t ) => ( {
								value: t,
								label: taskLabel( ai, t ),
							} ) ) }
							onChange={ setTask }
						/>
					) }
					<Queue
						task={ task }
						pro={ pro }
						reloadKey={ reloadKey }
						onUseAll={ () =>
							post( 'accept', { task, accept: true } ).then(
								( r ) => r && refresh()
							)
						}
					/>
				</CardBody>
			</Card>
		</div>
	);
}
