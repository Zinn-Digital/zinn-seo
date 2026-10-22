/**
 * Small pieces the credit and checks screens share.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';

import { viewHref } from '../admin-kit/router';
import { seoFetch } from '../admin/api';
import { covers, money, refusal } from './pure';

/**
 * A link that opens in a new tab, and says so to a screen reader.
 *
 * @param {Object} props
 * @param {string} props.href      Address.
 * @param {string} props.className Class.
 * @param {*}      props.children  Words.
 * @return {Element|null} Link.
 */
export function NewTab( { href, className, children } ) {
	if ( ! href ) {
		return null;
	}
	return (
		<a
			className={ className }
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ children }
			<span className="screen-reader-text">
				{ ' ' }
				{ __( '(opens in a new tab)', 'zinn-seo' ) }
			</span>
			<span aria-hidden="true"> ↗</span>
		</a>
	);
}

/**
 * Load something from the plugin's REST routes: the data, whether it is loading, the failure,
 * and a function that loads it again (optionally from another path, e.g. `?fresh=1`).
 *
 * @param {string|null} path Route under the namespace; null loads nothing.
 * @return {{data: *, loading: boolean, error: *, reload: Function, setData: Function}} State.
 */
export function useLoad( path ) {
	const [ state, setState ] = useState( {
		data: null,
		loading: !! path,
		error: null,
	} );
	const alive = useRef( true );
	useEffect( () => () => ( alive.current = false ), [] );
	const reload = useCallback(
		( other ) => {
			const from = typeof other === 'string' ? other : path;
			if ( ! from ) {
				return Promise.resolve( null );
			}
			setState( ( s ) => ( { ...s, loading: true, error: null } ) );
			return seoFetch( from )
				.then( ( data ) => {
					if ( alive.current ) {
						setState( { data, loading: false, error: null } );
					}
					return data;
				} )
				.catch( ( error ) => {
					if ( alive.current ) {
						setState( ( s ) => ( {
							...s,
							loading: false,
							error,
						} ) );
					}
					return null;
				} );
		},
		[ path ]
	);
	useEffect( () => {
		reload();
	}, [ reload ] );
	const setData = useCallback(
		( data ) => setState( { data, loading: false, error: null } ),
		[]
	);
	return { ...state, reload, setData };
}

/**
 * A refused or failed call, in plain words, with the one thing to do about it:
 * add credit, connect again, look at the provider's status page, or fix your own key.
 *
 * @param {Object}   props
 * @param {*}        props.error    What apiFetch rejected with.
 * @param {string}   props.buyUrl   Where credit is bought.
 * @param {Function} props.onRemove Dismiss.
 * @return {Element|null} Notice.
 */
export function Refusal( { error, buyUrl, onRemove } ) {
	if ( ! error ) {
		return null;
	}
	const r = refusal( error );
	const said =
		r.message ||
		__( 'Something went wrong. Please try again.', 'zinn-seo' );
	let action = null;
	if ( r.class === 'reconnect' ) {
		action = (
			<a href={ viewHref( 'credits' ) }>
				{ __( 'Connect this site again', 'zinn-seo' ) }
			</a>
		);
	} else if ( r.class === 'refused' && r.code === 'no_credit' ) {
		action = buyUrl ? (
			<NewTab href={ buyUrl }>{ __( 'Add credit', 'zinn-seo' ) }</NewTab>
		) : (
			<a href={ viewHref( 'credits' ) }>
				{ __( 'Add credit', 'zinn-seo' ) }
			</a>
		);
	} else if (
		r.class === 'refused' &&
		[ 'site_limit', 'account_hold' ].includes( r.code )
	) {
		action = (
			<a href={ viewHref( 'credits' ) }>
				{ __( 'See your account', 'zinn-seo' ) }
			</a>
		);
	} else if ( r.class === 'outage' && r.statusUrl ) {
		action = (
			<NewTab href={ r.statusUrl }>
				{ r.vendor
					? sprintf(
							/* translators: %s: the name of an AI company. */
							__( '%s status page', 'zinn-seo' ),
							r.vendor
						)
					: __( 'Status page', 'zinn-seo' ) }
			</NewTab>
		);
	} else if ( r.class === 'own_key' && r.fixUrl ) {
		// ⭐ Q201 (CLAUDE.md §2.57): the door matches the remedy, as on the Zinn® dashboard — a
		// refused key goes to the vendor's API keys page, an unpaid one to its billing page.
		let label = __( 'Fix your key', 'zinn-seo' );
		if ( r.vendor && r.fixKind === 'keys' ) {
			label = sprintf(
				/* translators: %s: a company name, e.g. "Ahrefs". */
				__( 'Get your key from %s', 'zinn-seo' ),
				r.vendor
			);
		} else if ( r.vendor && r.fixKind === 'billing' ) {
			label = sprintf(
				/* translators: %s: a company name, e.g. "Semrush". */
				__( 'Open your %s billing', 'zinn-seo' ),
				r.vendor
			);
		} else if ( r.vendor ) {
			label = sprintf(
				/* translators: %s: the name of an AI company. */
				__( 'Fix it at %s', 'zinn-seo' ),
				r.vendor
			);
		}
		action = <NewTab href={ r.fixUrl }>{ label }</NewTab>;
	}
	return (
		<Notice
			status={
				[ 'refused', 'reconnect', 'busy' ].includes( r.class )
					? 'warning'
					: 'error'
			}
			isDismissible={ !! onRemove }
			onRemove={ onRemove }
			className={ `zseo-refusal is-${ r.class || 'error' }` }
		>
			<span className="zseo-refusal__said">{ said }</span>
			{ action && <span className="zseo-refusal__do"> { action }</span> }
		</Notice>
	);
}

/**
 * Loading and failure states around a screen's content.
 *
 * @param {Object}   props
 * @param {Object}   props.load     What `useLoad()` returned.
 * @param {string}   props.buyUrl   Where credit is bought.
 * @param {Function} props.children Called with the data.
 * @return {Element} Content.
 */
export function Loaded( { load, buyUrl, children } ) {
	if ( load.error && ! load.data ) {
		return (
			<div className="zseo-stack">
				<Refusal error={ load.error } buyUrl={ buyUrl } />
				<div>
					<Button variant="secondary" onClick={ () => load.reload() }>
						{ __( 'Try again', 'zinn-seo' ) }
					</Button>
				</div>
			</div>
		);
	}
	if ( ! load.data ) {
		return <Spinner />;
	}
	return children( load.data );
}

/**
 * The price line under a "run" button: what it costs, and whether the credit covers it.
 *
 * @param {Object}      props
 * @param {number|null} props.cost    Micros (null: not known).
 * @param {number|null} props.balance Micros.
 * @param {boolean}     props.inPlan  The account's plan already includes this check.
 * @param {string}      props.each    How the price is counted ("per phrase").
 * @param {number|null} props.unit    Micros for ONE, shown when the total is for several: "$0.40
 *                                    (per page)" reads as forty cents a page.
 * @return {Element} Line.
 */
export function Cost( { cost, balance, inPlan, each, unit } ) {
	if ( inPlan ) {
		return (
			<span className="zseo-cost is-plan">
				{ __( 'Included in your Zinn® plan', 'zinn-seo' ) }
			</span>
		);
	}
	const enough = covers( balance, cost );
	const several = unit > 0 && cost > 0 && unit !== cost;
	return (
		<span className={ `zseo-cost${ enough === false ? ' is-short' : '' }` }>
			{ cost === null || cost === undefined
				? __( 'Price shown before anything is charged', 'zinn-seo' )
				: sprintf(
						/* translators: 1: an amount of money, 2: how it is counted, e.g. "$0.0185 per phrase". */
						__( 'Costs %1$s%2$s', 'zinn-seo' ),
						money( cost ),
						each
							? ` (${ several ? money( unit ) + ' ' : '' }${ each })`
							: ''
					) }
			{ enough === false && (
				<>
					{ ' · ' }
					<strong>
						{ sprintf(
							/* translators: %s: an amount of money. */
							__( 'your credit is %s', 'zinn-seo' ),
							money( balance )
						) }
					</strong>
				</>
			) }
		</span>
	);
}

/**
 * A table that turns into one small card per row on a phone (the kit's own table styles).
 *
 * @param {Object}        props
 * @param {string}        props.label   Accessible name.
 * @param {Array<Object>} props.columns `{ id, label, render( row ), numeric }`.
 * @param {Array<Object>} props.rows    Rows.
 * @param {Function}      props.rowKey  Row -> key.
 * @return {Element} Table.
 */
export function Table( { label, columns, rows, rowKey } ) {
	return (
		// A wide table scrolls inside its wrap on a mid-sized screen: the wrap is then reachable
		// from the keyboard and named (axe: scrollable-region-focusable).

		<div
			className="zak-table-wrap"
			role="region"
			aria-label={ label }
			tabIndex={ 0 }
		>
			<table className="zak-table zseo-t">
				<thead>
					<tr>
						{ columns.map( ( c ) => (
							<th
								key={ c.id }
								scope="col"
								className={ c.numeric ? 'is-num' : undefined }
							>
								{ c.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, i ) => (
						<tr key={ rowKey ? rowKey( row, i ) : i }>
							{ columns.map( ( c ) => (
								<td
									key={ c.id }
									data-label={ c.label }
									className={
										c.numeric ? 'is-num' : undefined
									}
								>
									{ c.render( row, i ) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Do something through a REST route and report how it went.
 *
 * @return {{busy: string, error: *, done: string, run: Function, clear: Function}} State.
 */
export function useAction() {
	const [ state, setState ] = useState( { busy: '', error: null, done: '' } );
	const run = useCallback( ( name, promise, done = '' ) => {
		setState( { busy: name, error: null, done: '' } );
		return promise()
			.then( ( result ) => {
				setState( { busy: '', error: null, done } );
				return result;
			} )
			.catch( ( error ) => {
				setState( { busy: '', error, done: '' } );
				return null;
			} );
	}, [] );
	const clear = useCallback(
		() => setState( { busy: '', error: null, done: '' } ),
		[]
	);
	return { ...state, run, clear };
}
