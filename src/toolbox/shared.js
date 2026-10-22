/**
 * What every tool of the research toolbox shares: its runs (read again while one is going), the
 * "run now" bar with the price shown first, the schedule, and a run's money line.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, SelectControl } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { Cost, Refusal, useAction } from '../tools/parts';
import { day, money } from '../tools/pure';
import { unitEach } from '../tools/labels';
import { CADENCES, going } from './pure';

/** How often a going run is read again, and for how long at most. */
const POLL_MS = 4000;
const POLL_FOR_MS = 15 * 60 * 1000;

/**
 * A tool's recent runs and the one being looked at. A going run is read again every few
 * seconds (reading costs nothing) until it finishes.
 *
 * @param {string} product Tool code.
 * @return {Object} `{runs, run, show, add, loading, error, reload}`.
 */
export function useRuns( product ) {
	const [ state, setState ] = useState( {
		runs: [],
		run: null,
		loading: true,
		error: null,
	} );
	const alive = useRef( true );
	const started = useRef( 0 );
	useEffect( () => () => ( alive.current = false ), [] );

	const reload = useCallback(
		() =>
			seoFetch( `/toolbox/runs?tool=${ product }` )
				.then( ( body ) => {
					if ( alive.current ) {
						const runs = ( body && body.runs ) || [];
						setState( ( s ) => ( {
							...s,
							runs,
							run: s.run || runs[ 0 ] || null,
							loading: false,
							error: null,
						} ) );
					}
				} )
				.catch( ( error ) => {
					if ( alive.current ) {
						setState( ( s ) => ( {
							...s,
							loading: false,
							error,
						} ) );
					}
				} ),
		[ product ]
	);
	useEffect( () => {
		reload();
	}, [ reload ] );

	const run = state.run;
	useEffect( () => {
		if (
			! going( run ) &&
			! ( run && 'snapshot' === run.product && ! run.pictures_read )
		) {
			return undefined;
		}
		if ( ! started.current ) {
			started.current = Date.now();
		}
		if ( Date.now() - started.current > POLL_FOR_MS ) {
			return undefined;
		}
		const timer = window.setTimeout(
			() => {
				seoFetch( `/toolbox/run/${ run.id }` )
					.then( ( fresh ) => {
						if ( ! alive.current || ! fresh ) {
							return;
						}
						const done = ! going( fresh );
						setState( ( s ) => ( {
							...s,
							run: { ...fresh, pictures_read: done },
							runs: s.runs.map( ( r ) =>
								r.id === fresh.id ? fresh : r
							),
						} ) );
						if ( done ) {
							started.current = 0;
						}
					} )
					.catch( () => {} );
			},
			going( run ) ? POLL_MS : 0
		);
		return () => window.clearTimeout( timer );
	}, [ run ] );

	const show = useCallback(
		( id ) =>
			setState( ( s ) => ( {
				...s,
				run: s.runs.find( ( r ) => r.id === id ) || s.run,
			} ) ),
		[]
	);
	const add = useCallback(
		( fresh ) =>
			setState( ( s ) => ( {
				...s,
				run: fresh,
				runs: [ fresh, ...s.runs.filter( ( r ) => r.id !== fresh.id ) ],
			} ) ),
		[]
	);
	return { ...state, reload, show, add };
}

/**
 * Run the tool now, with its price first and a "yes" before anything is spent.
 *
 * @param {Object}   props
 * @param {string}   props.product   Tool code.
 * @param {Object}   props.params    Parameters.
 * @param {number}   props.units     Units this run spends.
 * @param {Object}   props.ctx       `{ prices, units, balance, canSpend, buyUrl, refresh }`.
 * @param {string}   props.label     The button's words.
 * @param {string}   props.why       Why it cannot run yet ('' when it can).
 * @param {boolean}  props.running   A run is going.
 * @param {Function} props.onStarted Called with the new run.
 * @return {Element} Bar.
 */
export function RunNow( {
	product,
	params,
	units,
	ctx,
	label,
	why,
	running,
	onStarted,
} ) {
	const action = useAction();
	const [ asking, setAsking ] = useState( false );
	const unit = ctx.units[ product ];
	const price = ctx.prices[ unit ];
	const cost = price === undefined || price === null ? null : price * units;
	const go = () => {
		setAsking( false );
		action
			.run( 'run', () =>
				seoFetch( `/toolbox/${ product }/run`, {
					method: 'POST',
					data: { params },
				} )
			)
			.then( ( run ) => {
				if ( run ) {
					ctx.refresh();
					onStarted( run );
				}
			} );
	};
	return (
		<div className="zseo-runbar">
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			{ ! ctx.canSpend ? (
				<p className="zseo-muted">
					{ __(
						'Only someone allowed to spend the site’s credit can run this.',
						'zinn-seo'
					) }
				</p>
			) : (
				<div className="zseo-actions zseo-actions--start">
					{ ! asking ? (
						<Button
							variant="primary"
							disabled={ !! why || running || !! action.busy }
							isBusy={ running || 'run' === action.busy }
							onClick={ () =>
								cost ? setAsking( true ) : go()
							}
						>
							{ running
								? __( 'Working on it…', 'zinn-seo' )
								: label }
						</Button>
					) : (
						<>
							<span className="zseo-ask">
								{ sprintf(
									/* translators: %s: an amount of money. */
									__(
										'This uses %s of your credit. Run it?',
										'zinn-seo'
									),
									money( cost )
								) }
							</span>
							<Button variant="primary" onClick={ go }>
								{ __( 'Yes, run it', 'zinn-seo' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setAsking( false ) }
							>
								{ __( 'Cancel', 'zinn-seo' ) }
							</Button>
						</>
					) }
					{ ! asking && (
						<Cost
							cost={ units ? cost : price }
							balance={ units ? ctx.balance : null }
							each={ unitEach( unit ) }
							unit={ price }
						/>
					) }
				</div>
			) }
			{ why && <p className="zseo-muted">{ why }</p> }
		</div>
	);
}

/**
 * The words for a schedule period.
 *
 * @param {number} days Days.
 * @return {string} Words.
 */
export function cadenceName( days ) {
	if ( 1 === days ) {
		return __( 'Every day', 'zinn-seo' );
	}
	if ( 30 === days ) {
		return __( 'Every month', 'zinn-seo' );
	}
	return __( 'Every week', 'zinn-seo' );
}

/**
 * Run the tool on a schedule — with what each run costs and what a month costs.
 *
 * @param {Object} props
 * @param {string} props.product Tool code.
 * @param {Object} props.params  Parameters of a new schedule.
 * @param {number} props.units   Units one run spends.
 * @param {Object} props.ctx     Shared state (`watches`, `prices`, `units`, `canSpend`).
 * @param {string} props.why     Why a schedule cannot be set yet.
 * @param {string} props.label   The button's words.
 * @return {Element} Schedule.
 */
export function Schedule( { product, params, units, ctx, why, label } ) {
	const action = useAction();
	const [ every, setEvery ] = useState( 7 );
	const mine = ( ctx.watches || [] ).filter( ( w ) => w.product === product );
	const price = ctx.prices[ ctx.units[ product ] ];
	const perRun = price === undefined ? null : price * units;
	const change = ( watch, data ) =>
		action
			.run( watch.id, () =>
				seoFetch( `/toolbox/watches/${ watch.id }`, {
					method: 'PATCH',
					data,
				} )
			)
			.then( ( ok ) => ok && ctx.refresh() );
	const remove = ( watch ) =>
		action
			.run( watch.id, () =>
				seoFetch( `/toolbox/watches/${ watch.id }`, {
					method: 'DELETE',
				} )
			)
			.then( ( ok ) => ok && ctx.refresh() );
	const add = () =>
		action
			.run(
				'add',
				() =>
					seoFetch( '/toolbox/watches', {
						method: 'POST',
						data: { tool: product, params, every },
					} ),
				__(
					'Scheduled. The first check runs within the hour; after that you get an alert when something changes.',
					'zinn-seo'
				)
			)
			.then( ( ok ) => ok && ctx.refresh() );
	return (
		<div className="zseo-schedule zseo-stack">
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			{ action.done && (
				<Notice status="success" onRemove={ action.clear }>
					{ action.done }
				</Notice>
			) }
			{ mine.map( ( w ) => (
				<div key={ w.id } className="zseo-watch">
					<p>
						<strong>{ cadenceName( w.cadence_days ) }</strong>
						{ ' · ' }
						{ w.held_since
							? __(
									'Paused: waiting for credit. It carries on by itself when you add credit.',
									'zinn-seo'
								)
							: sprintf(
									/* translators: 1: a date, 2: an amount of money. */
									__(
										'Next check %1$s · about %2$s a month',
										'zinn-seo'
									),
									day( w.next_due_at, true ),
									money( w.cost_30d_micros )
								) }
						{ ! w.active && (
							<span className="zseo-tag is-wait">
								{ ' ' }
								{ __( 'Switched off', 'zinn-seo' ) }
							</span>
						) }
					</p>
					<p className="zseo-muted">{ watchSummary( w ) }</p>
					{ ctx.canSpend && (
						<div className="zseo-actions zseo-actions--start">
							<Button
								variant="secondary"
								isBusy={ action.busy === w.id }
								onClick={ () =>
									change( w, { active: ! w.active } )
								}
							>
								{ w.active
									? __( 'Switch off', 'zinn-seo' )
									: __( 'Switch on', 'zinn-seo' ) }
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => remove( w ) }
							>
								{ __( 'Remove', 'zinn-seo' ) }
							</Button>
						</div>
					) }
				</div>
			) ) }
			{ ctx.canSpend && (
				<div className="zseo-actions zseo-actions--start">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'How often', 'zinn-seo' ) }
						value={ String( every ) }
						options={ CADENCES.map( ( d ) => ( {
							value: String( d ),
							label: cadenceName( d ),
						} ) ) }
						onChange={ ( v ) => setEvery( Number( v ) ) }
					/>
					<Button
						variant="secondary"
						disabled={ !! why || ! units }
						isBusy={ 'add' === action.busy }
						onClick={ add }
					>
						{ label }
					</Button>
					{ perRun !== null && units > 0 && (
						<span className="zseo-cost">
							{ sprintf(
								/* translators: 1: an amount of money, 2: an amount of money. */
								__(
									'%1$s each check · about %2$s a month',
									'zinn-seo'
								),
								money( perRun ),
								money( perRun * Math.ceil( 30 / every ) )
							) }
						</span>
					) }
				</div>
			) }
		</div>
	);
}

/**
 * What a schedule checks, in a few words.
 *
 * @param {Object} w Schedule.
 * @return {string} Words.
 */
function watchSummary( w ) {
	const p = w.params || {};
	if ( p.phrase ) {
		return p.phrase;
	}
	if ( p.phrases ) {
		return p.phrases.join( ', ' );
	}
	if ( p.products ) {
		return p.products.map( ( x ) => x.title || x.asin ).join( ', ' );
	}
	if ( p.profiles ) {
		return p.profiles.map( ( x ) => x.name || x.profile_id ).join( ', ' );
	}
	return '';
}

/**
 * The money line under a run: charged, refunded, when.
 *
 * @param {Object} props
 * @param {Object} props.run The run.
 * @return {Element|null} Line.
 */
export function RunMoney( { run } ) {
	if ( ! run ) {
		return null;
	}
	return (
		<p className="zseo-muted zseo-small">
			{ day( run.created_at, true ) }
			{ ' · ' }
			{ sprintf(
				/* translators: %s: an amount of money. */
				__( 'Cost %s', 'zinn-seo' ),
				money( run.charged_micros - run.refunded_micros )
			) }
			{ run.refunded_micros > 0 &&
				' · ' +
					sprintf(
						/* translators: %s: an amount of money. */
						__(
							'%s refunded for answers we could not get',
							'zinn-seo'
						),
						money( run.refunded_micros )
					) }
		</p>
	);
}

/**
 * Earlier runs of the tool, to look back at.
 *
 * @param {Object}   props
 * @param {Array}    props.runs Runs.
 * @param {Object}   props.run  The one shown.
 * @param {Function} props.show Show another.
 * @return {Element|null} Picker.
 */
export function History( { runs, run, show } ) {
	if ( ! runs || runs.length < 2 ) {
		return null;
	}
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ sprintf(
				/* translators: %d: how many earlier results. */
				_n(
					'Earlier result (%d)',
					'Earlier results (%d)',
					runs.length,
					'zinn-seo'
				),
				runs.length
			) }
			value={ run ? run.id : '' }
			options={ runs.map( ( r ) => ( {
				value: r.id,
				label: day( r.created_at, true ),
			} ) ) }
			onChange={ show }
		/>
	);
}

/**
 * A run's state when it has not produced an answer to show.
 *
 * @param {Object} props
 * @param {Object} props.run The run.
 * @return {Element|null} Line.
 */
export function RunState( { run } ) {
	if ( ! run ) {
		return null;
	}
	if ( going( run ) ) {
		return (
			<p className="zseo-waiting">
				{ __(
					'Working on it. This usually takes under a minute; you can leave this page and come back.',
					'zinn-seo'
				) }
			</p>
		);
	}
	if ( 'failed' === run.status ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'We could not get an answer this time, so nothing was charged. Please try again in a few minutes.',
					'zinn-seo'
				) }
			</Notice>
		);
	}
	if ( 'partial' === run.status ) {
		return (
			<p className="zseo-muted">
				{ __(
					'Some answers could not be fetched; those were refunded and are marked below.',
					'zinn-seo'
				) }
			</p>
		);
	}
	return null;
}
