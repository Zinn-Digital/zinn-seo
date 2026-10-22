/**
 * Pieces the Fix it, Content and Links screens share.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * The colour words of a score.
 *
 * @param {number|null} score Score.
 * @return {string} good|ok|bad|none.
 */
export function rating( score ) {
	if ( score === null || score === undefined ) {
		return 'none';
	}
	if ( score >= 75 ) {
		return 'good';
	}
	return score >= 50 ? 'ok' : 'bad';
}

/**
 * A score pill.
 *
 * @param {Object}      props
 * @param {number|null} props.score Score.
 * @param {string}      props.label Accessible label.
 * @return {Element} Pill.
 */
export function Pill( { score, label } ) {
	const r = rating( score );
	return (
		<span className={ `zseo-pill is-${ r }` }>
			<span className="screen-reader-text">{ label }: </span>
			{ score === null || score === undefined ? '–' : score }
		</span>
	);
}

/**
 * Read the query part of the hash route (`#/tab?rating=bad`).
 *
 * @return {URLSearchParams} Parameters.
 */
export function hashParams() {
	const h = window.location.hash || '';
	const q = h.indexOf( '?' );
	return new URLSearchParams( q >= 0 ? h.slice( q + 1 ) : '' );
}

/**
 * A background job's state, polled every few seconds while it runs.
 *
 * @param {Function} read   () => Promise of the job record (or null).
 * @param {Function} onDone Called when a run finishes.
 * @return {Array} [job, setJob].
 */
export function useJob( read, onDone ) {
	const [ job, setJob ] = useState( null );
	const timer = useRef( null );
	useEffect( () => {
		const tick = () =>
			read()
				.then( ( j ) => {
					setJob( ( prev ) => {
						if (
							prev &&
							prev.status === 'pending' &&
							j &&
							j.status !== 'pending'
						) {
							onDone();
						}
						return j;
					} );
				} )
				.catch( () => {} );
		if ( job && job.status === 'pending' ) {
			timer.current = setTimeout( tick, 3000 );
		}
		return () => clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ job ] );
	return [ job, setJob ];
}

/**
 * A job's progress line.
 *
 * @param {Object} props
 * @param {Object} props.job Job record.
 * @return {Element|null} Line.
 */
export function JobLine( { job } ) {
	if ( ! job || job.status === 'idle' ) {
		return null;
	}
	const p = job.progress || {};
	// ZHJOB1: held by the site's own signals (a queue past due, a slow server) — a calm line.
	if ( job.status === 'pending' && job.paused_note ) {
		return (
			<p className="zseo-job" role="status">
				{ job.paused_note }
			</p>
		);
	}
	if ( job.status === 'pending' && job.waiting ) {
		return (
			<p className="zseo-job" role="status">
				{ sprintf(
					/* translators: %s: the name of the background job running now. */
					__(
						'Waiting for “%s” to finish: one background job runs at a time. This one starts by itself.',
						'zinn-seo'
					),
					job.waiting
				) }
			</p>
		);
	}
	// Q335: a page that ends the request while it is read is named and skipped, never silent.
	const unread =
		job.error && job.unreadable && job.unreadable.length ? (
			<Notice status="warning" isDismissible={ false }>
				{ job.error }
			</Notice>
		) : null;
	if ( job.status === 'pending' ) {
		return (
			<>
				{ unread }
				<p className="zseo-job" role="status">
					<Spinner />
					{ p.total
						? sprintf(
								/* translators: 1: done, 2: total. */
								__(
									'Working in the background: %1$d of %2$d. You can leave this screen.',
									'zinn-seo'
								),
								p.done || 0,
								p.total
							)
						: __(
								'Working in the background. You can leave this screen.',
								'zinn-seo'
							) }
				</p>
			</>
		);
	}
	if ( job.status === 'failed' ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ job.error ||
					__(
						'The background job stopped with an error.',
						'zinn-seo'
					) }
			</Notice>
		);
	}
	if ( job.status === 'done' && job.finished ) {
		return (
			<>
				{ unread }
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %s: a date and time. */
						__( 'Last finished %s.', 'zinn-seo' ),
						new Date( job.finished * 1000 ).toLocaleString()
					) }
				</p>
			</>
		);
	}
	return null;
}

/**
 * Load something from a route, with a spinner and an error.
 *
 * @param {string} path Route.
 * @param {Array}  deps Reload when these change.
 * @return {Object} {data, error, reload, setData}.
 */
export function useRoute( path, deps = [] ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ n, setN ] = useState( 0 );
	useEffect( () => {
		let live = true;
		setError( '' );
		seoFetch( path )
			.then( ( d ) => live && setData( d ) )
			.catch( ( e ) => live && setError( errorText( e ) ) );
		return () => {
			live = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ path, n, ...deps ] );
	return { data, error, setData, reload: () => setN( n + 1 ) };
}

/**
 * A button that starts something and shows it is busy.
 *
 * @param {Object}   props
 * @param {Function} props.run      () => Promise.
 * @param {string}   props.children Label.
 * @param {string}   props.variant  Button variant.
 * @param {boolean}  props.disabled Disabled.
 * @param {Function} props.onError  Error handler.
 * @return {Element} Button.
 */
export function RunButton( {
	run,
	children,
	variant = 'secondary',
	disabled,
	onError = () => {},
} ) {
	const [ busy, setBusy ] = useState( false );
	return (
		<Button
			variant={ variant }
			isBusy={ busy }
			disabled={ busy || disabled }
			onClick={ () => {
				setBusy( true );
				Promise.resolve( run() )
					.catch( ( e ) => onError( errorText( e ) ) )
					.finally( () => setBusy( false ) );
			} }
		>
			{ children }
		</Button>
	);
}

/**
 * A table that becomes one card per row below 600 px (each cell keeps its column's label).
 *
 * @param {Object}        props
 * @param {Array<Object>} props.columns `{id, label, render(row)}`.
 * @param {Array<Object>} props.rows    Rows (each with `id`).
 * @param {string}        props.label   Accessible name.
 * @param {string}        props.empty   Shown when there is no row.
 * @return {Element} Table.
 */
export function Table( { columns, rows, label, empty } ) {
	if ( ! rows.length ) {
		return <p className="zseo-muted">{ empty }</p>;
	}
	return (
		<table className="zseo-table" aria-label={ label }>
			<thead>
				<tr>
					{ columns.map( ( c ) => (
						<th key={ c.id } scope="col">
							{ c.label }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.id }>
						{ columns.map( ( c ) => (
							<td key={ c.id } data-label={ c.label }>
								{ c.render( row ) }
							</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
