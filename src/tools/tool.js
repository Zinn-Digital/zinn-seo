/**
 * What every check's tab shares: its recent runs, the "run now" bar with the price shown first,
 * the schedule switch, and the "paused for credit" line.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, ToggleControl } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { Cost, NewTab, Refusal, useAction } from './parts';
import { day, latestRun, money, productUrl } from './pure';
import { runStatus, toolName, unitEach } from './labels';

/**
 * A check's recent runs. While one is running (or one was just started) the list is read again
 * every few seconds, up to ten minutes, then left alone — reading it costs nothing.
 *
 * @param {string}  tool   Tool id.
 * @param {boolean} active Whether to load at all.
 * @return {{audits: Array, latest: Object|null, running: boolean, error: *, loading: boolean, watch: Function, reload: Function}} State.
 */
export function useResults( tool, active = true ) {
	const [ state, setState ] = useState( {
		audits: [],
		loading: active,
		error: null,
	} );
	const until = useRef( 0 );
	const timer = useRef( null );
	const alive = useRef( true );
	const seen = useRef( '' );

	const read = useCallback( () => {
		window.clearTimeout( timer.current );
		return seoFetch( `/tools/${ tool }/results` )
			.then( ( result ) => {
				if ( ! alive.current ) {
					return;
				}
				const audits = result.audits || [];
				setState( { audits, loading: false, error: null } );
				const { running } = latestRun( audits );
				const newest = audits[ 0 ] ? audits[ 0 ].id : '';
				// Keep looking while a run is out, or until the run just started shows up.
				const waiting =
					running ||
					( until.current > Date.now() && newest === seen.current );
				if ( waiting && until.current > Date.now() ) {
					timer.current = window.setTimeout( read, 6000 );
				}
			} )
			.catch( ( error ) => {
				if ( alive.current ) {
					setState( ( s ) => ( { ...s, loading: false, error } ) );
				}
			} );
	}, [ tool ] );

	useEffect( () => {
		alive.current = true;
		if ( active ) {
			until.current = Date.now() + 10 * 60 * 1000;
			read();
		}
		return () => {
			alive.current = false;
			window.clearTimeout( timer.current );
		};
	}, [ active, read ] );

	const watch = useCallback( () => {
		seen.current = state.audits[ 0 ] ? state.audits[ 0 ].id : '';
		until.current = Date.now() + 10 * 60 * 1000;
		timer.current = window.setTimeout( read, 4000 );
	}, [ read, state.audits ] );

	return { ...state, ...latestRun( state.audits ), watch, reload: read };
}

/**
 * "Paused for credit" — with what happens next.
 *
 * @param {Object} props
 * @param {Object} props.state  The tool's state.
 * @param {string} props.buyUrl Where credit is bought.
 * @return {Element|null} Notice.
 */
export function Held( { state, buyUrl } ) {
	if ( ! state || ! state.held_since ) {
		return null;
	}
	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="zseo-heldline"
		>
			{ sprintf(
				/* translators: %s: a date and time. */
				__(
					'Paused since %s because the credit ran out. Nothing is lost: add credit and it carries on by itself.',
					'zinn-seo'
				),
				day( state.held_since, true )
			) }{ ' ' }
			<NewTab href={ buyUrl }>{ __( 'Add credit', 'zinn-seo' ) }</NewTab>
		</Notice>
	);
}

/**
 * A product the account's plan does not hold and that is not sold per use.
 *
 * @param {Object} props
 * @param {string} props.kind   The product.
 * @param {string} props.buyUrl The account's address (the product page is built from it).
 * @param {string} props.text   What it does for the reader.
 * @return {Element} Notice.
 */
export function NeedsPlan( { kind, buyUrl, text } ) {
	return (
		<Notice
			status="info"
			isDismissible={ false }
			className="zseo-needsplan"
		>
			{ text }{ ' ' }
			<NewTab href={ productUrl( buyUrl, kind ) }>
				{ sprintf(
					/* translators: %s: the name of a product, e.g. "Brand mentions". */
					__( 'Add %s to your Zinn® account', 'zinn-seo' ),
					toolName( kind )
				) }
			</NewTab>
		</Notice>
	);
}

/**
 * "Run now": the price first, a confirmation when it costs money, then the run.
 *
 * @param {Object}   props
 * @param {string}   props.tool     Tool id.
 * @param {Object}   props.state    The tool's state (`unit`, `in_plan`, `unit_price`).
 * @param {number}   props.units    Units this run spends.
 * @param {Object}   props.ctx      `{ account, canSpend, buyUrl, refresh }`.
 * @param {string}   props.label    The button's words.
 * @param {Object}   props.body     Extra request fields.
 * @param {string}   props.path     Another route to post to.
 * @param {boolean}  props.disabled Nothing to run yet.
 * @param {string}   props.why      Why it is disabled.
 * @param {boolean}  props.running  A run is out.
 * @param {Function} props.onStart  Called once the run started.
 * @return {Element} Bar.
 */
export function RunBar( {
	tool,
	state,
	units,
	ctx,
	label,
	body,
	path,
	disabled,
	why,
	running,
	onStart,
} ) {
	const action = useAction();
	const [ asking, setAsking ] = useState( false );
	// Included in the plan: nothing to pay. No price known: not known (never shown as free).
	let cost = null;
	if ( state.in_plan ) {
		cost = 0;
	} else if ( state.unit_price !== null && state.unit_price !== undefined ) {
		cost = state.unit_price * units;
	}
	const balance = ctx.account ? ctx.account.balance_micros : null;
	const go = () => {
		setAsking( false );
		action
			.run(
				'run',
				() =>
					seoFetch( path || `/tools/${ tool }/run`, {
						method: 'POST',
						data: body || {},
					} ),
				__(
					'Started. The result appears here in a moment — you can leave this page.',
					'zinn-seo'
				)
			)
			.then( ( started ) => {
				if ( started ) {
					ctx.refresh();
					if ( onStart ) {
						onStart( started );
					}
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
			{ action.done && (
				<Notice status="success" onRemove={ action.clear }>
					{ action.done }
				</Notice>
			) }
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
							disabled={ disabled || running || !! action.busy }
							isBusy={ running || 'run' === action.busy }
							onClick={ () =>
								cost ? setAsking( true ) : go()
							}
						>
							{ running ? runStatus( 'running' ) : label }
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
							// With nothing to run yet, the price of ONE is what there is to say.
							cost={
								0 === units && ! state.in_plan
									? state.unit_price
									: cost
							}
							balance={ 0 === units ? null : balance }
							inPlan={ state.in_plan }
							each={ unitEach( state.unit ) }
							unit={ state.unit_price }
						/>
					) }
				</div>
			) }
			{ disabled && why && <p className="zseo-muted">{ why }</p> }
		</div>
	);
}

/**
 * Put a check on the schedule, or take it off — saying what each run costs.
 *
 * @param {Object} props
 * @param {string} props.tool  Tool id.
 * @param {Object} props.state The tool's state.
 * @param {number} props.units Units one run spends.
 * @param {Object} props.ctx   `{ target, canSpend, buyUrl, refresh }`.
 * @param {string} props.label The switch's words.
 * @return {Element} Switch.
 */
export function Schedule( { tool, state, units, ctx, label } ) {
	const action = useAction();
	const days = ctx.target ? ctx.target.cadence_days : 7;
	let how = __( 'every week', 'zinn-seo' );
	if ( 1 === days ) {
		how = __( 'every day', 'zinn-seo' );
	} else if ( 14 === days ) {
		how = __( 'every two weeks', 'zinn-seo' );
	} else if ( 30 === days ) {
		how = __( 'every month', 'zinn-seo' );
	} else if ( 0 === days ) {
		how = __( 'paused', 'zinn-seo' );
	}
	const each =
		state.in_plan || state.unit_price === null
			? null
			: state.unit_price * units;
	let help = __( 'Included in your Zinn® plan.', 'zinn-seo' );
	if ( ! state.in_plan ) {
		help =
			null === each
				? ''
				: sprintf(
						/* translators: 1: how often, e.g. "every week", 2: an amount of money. */
						__(
							'Runs %1$s; each run costs %2$s at today’s list. You are told before the credit runs low, and a run that cannot be paid for waits — it is never lost.',
							'zinn-seo'
						),
						how,
						money( each )
					);
	}
	return (
		<div className="zseo-schedule">
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				checked={ !! state.on }
				disabled={ ! ctx.canSpend || !! action.busy }
				onChange={ ( on ) =>
					action
						.run( 'schedule', () =>
							seoFetch( `/tools/${ tool }/schedule`, {
								method: 'PUT',
								data: { on },
							} )
						)
						.then( ( done ) => done && ctx.refresh() )
				}
			/>
		</div>
	);
}

/**
 * The line above a result: when it was measured, and what it cost.
 *
 * @param {Object} props
 * @param {Object} props.run A run.
 * @return {Element|null} Line.
 */
export function RunLine( { run } ) {
	if ( ! run ) {
		return null;
	}
	return (
		<p className="zseo-runline zseo-muted">
			<span className={ `zseo-tag is-${ run.status }` }>
				{ runStatus( run.status ) }
			</span>{ ' ' }
			{ day( run.finished_at || run.started_at, true ) }
			{ run.charged_micros > 0 && (
				<>
					{ ' · ' }
					{ sprintf(
						/* translators: %s: an amount of money. */
						__( 'cost %s', 'zinn-seo' ),
						money( run.charged_micros )
					) }
				</>
			) }
			{ 'failed' === run.status && (
				<>
					{ ' · ' }
					{ __(
						'Nothing was charged for a check that gave no answer.',
						'zinn-seo'
					) }
				</>
			) }
		</p>
	);
}

/**
 * An empty result, said plainly.
 *
 * @param {Object} props
 * @param {string} props.children What will appear here.
 * @return {Element} Line.
 */
export function Empty( { children } ) {
	return <p className="zseo-empty zseo-muted">{ children }</p>;
}
