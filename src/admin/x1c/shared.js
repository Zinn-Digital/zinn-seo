/**
 * Shared pieces of the stage X1C screens (writing, rich results, more integrations).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * Is a module switched on (the boot data's module map; absent = on)?
 *
 * @param {Object} data Boot data.
 * @param {string} id   Module id.
 * @return {boolean} On.
 */
export function moduleOn( data, id ) {
	const mods = ( data && data.modules ) || {};
	return id in mods ? !! mods[ id ] : false;
}

/**
 * Run an AI task and return its first item's fields, or throw the item's failure sentence.
 *
 * @param {string} task    Task id.
 * @param {Object} options Task options.
 * @param {Array}  ids     Items (default: none).
 * @return {Promise<Object>} `{fields, run}`.
 */
export function runTask( task, options, ids = [ 0 ] ) {
	return seoFetch( '/ai/run', {
		method: 'POST',
		data: { task, ids, options },
	} ).then( ( run ) => {
		const item = ( run.items || [] )[ 0 ] || {};
		if ( ! item.ok ) {
			throw new Error(
				( item.failure && item.failure.message ) ||
					__( 'The AI did not answer. Try again.', 'zinn-seo' )
			);
		}
		return { fields: item.fields || {}, run };
	} );
}

/**
 * Money in micros as dollars ("$0.0185" under ten cents).
 *
 * @param {number} micros Millionths of a dollar.
 * @return {string} Text.
 */
export function money( micros ) {
	const n = ( Number( micros ) || 0 ) / 1000000;
	return '$' + ( n < 0.1 ? n.toFixed( 4 ) : n.toFixed( 2 ) );
}

/**
 * What a paid AI run cost, in one line (nothing on the owner's own key).
 *
 * @param {Object} run The AI run.
 * @return {string} Line.
 */
export function costLine( run ) {
	if ( ! run || ! run.credits ) {
		return '';
	}
	return sprintf(
		/* translators: %s: an amount of money such as $0.0120. */
		__( 'Paid with Zinn® credits: %s.', 'zinn-seo' ),
		money( run.cost_micros )
	);
}

/**
 * An error line.
 *
 * @param {Object} props
 * @param {string} props.text Sentence ('' shows nothing).
 * @return {Element|null} Notice.
 */
export function ErrorLine( { text } ) {
	if ( ! text ) {
		return null;
	}
	return (
		<Notice status="error" isDismissible={ false }>
			{ text }
		</Notice>
	);
}

/**
 * A list read a page at a time by keyset (`after`): every row reachable, none capped.
 *
 * @param {string} path Route (with its own query, without `after`).
 * @return {Object} `{items, error, loading, more, hasMore, reload}`.
 */
export function usePaged( path ) {
	const [ state, setState ] = useState( {
		path: '',
		items: [],
		next: 0,
		error: '',
		loading: true,
	} );
	const load = ( after, base ) => {
		const sep = path.includes( '?' ) ? '&' : '?';
		setState( ( s ) => ( { ...s, loading: true } ) );
		seoFetch( `${ path }${ sep }after=${ after }` )
			.then( ( r ) =>
				setState( () => ( {
					path,
					items: [ ...( after ? base : [] ), ...( r.items || [] ) ],
					next: r.next || 0,
					error: '',
					loading: false,
					extra: r,
				} ) )
			)
			.catch( ( e ) =>
				setState( ( s ) => ( {
					...s,
					error: errorText( e ),
					loading: false,
				} ) )
			);
	};
	useEffect( () => {
		load( 0, [] );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ path ] );
	return {
		items: state.items,
		extra: state.extra || {},
		error: state.error,
		loading: state.loading,
		hasMore: state.next > 0,
		more: () => load( state.next, state.items ),
		reload: () => load( 0, [] ),
	};
}

/**
 * "Show more" for a paged list.
 *
 * @param {Object} props
 * @param {Object} props.list usePaged() result.
 * @return {Element|null} Button.
 */
export function MoreButton( { list } ) {
	if ( ! list.hasMore ) {
		return null;
	}
	return (
		<Button
			variant="secondary"
			isBusy={ list.loading }
			disabled={ list.loading }
			onClick={ list.more }
		>
			{ __( 'Show more', 'zinn-seo' ) }
		</Button>
	);
}
