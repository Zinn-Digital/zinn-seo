/**
 * Pieces every AI screen shares: how a failure is shown (zs-199), running a task, and the
 * "suggested, not applied" row (zs-200).
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';

import { viewHref } from '../admin-kit/router';
import { seoFetch } from '../admin/api';
import { NewTab } from '../tools/parts';

/**
 * The failure carried by a rejected call: the route's `data.failure` (a classified AI failure),
 * or a plain one built from the message.
 *
 * @param {*} error What apiFetch rejected with.
 * @return {Object} `{ kind, provider, message, link, action }`.
 */
export function failureOf( error ) {
	if ( error && error.data && error.data.failure ) {
		return error.data.failure;
	}
	return {
		kind: 'unknown',
		provider: '',
		message:
			( error && error.message ) ||
			__( 'Something went wrong. Please try again.', 'zinn-seo' ),
		link: '',
		action: 'retry',
	};
}

/**
 * The one button a failure offers, by its class: add credit, open the provider's status page,
 * fix your own key, open the AI settings, connect to Zinn®, or try again. Never a provider's
 * name on a failure that is ours.
 *
 * @param {Object}   props
 * @param {Object}   props.failure  `{ kind, message, link, action }`.
 * @param {string}   props.buyUrl   Where Zinn® credit is bought.
 * @param {Function} props.onRetry  Try again.
 * @param {Function} props.onRemove Dismiss.
 * @return {Element|null} Notice.
 */
export function AiFailure( { failure, buyUrl, onRetry, onRemove } ) {
	if ( ! failure ) {
		return null;
	}
	const link = failure.link || '';
	let action = null;
	switch ( failure.action ) {
		case 'top_up':
			action = (
				<NewTab href={ buyUrl || link }>
					{ __( 'Add credit', 'zinn-seo' ) }
				</NewTab>
			);
			if ( ! buyUrl && ! link.startsWith( 'https://' ) ) {
				action = (
					<a href={ viewHref( 'credits' ) }>
						{ __( 'Add credit', 'zinn-seo' ) }
					</a>
				);
			}
			break;
		case 'account':
			action = (
				<NewTab href={ buyUrl || link }>
					{ __( 'Open your Zinn® account', 'zinn-seo' ) }
				</NewTab>
			);
			break;
		case 'status':
			action = (
				<NewTab href={ link }>
					{ __( 'See their status page', 'zinn-seo' ) }
				</NewTab>
			);
			break;
		case 'fix_key':
			action = (
				<NewTab href={ link }>
					{ __( 'Fix it with your provider', 'zinn-seo' ) }
				</NewTab>
			);
			break;
		case 'settings':
			action = link ? (
				<a href={ link }>
					{ __( 'Open the AI settings', 'zinn-seo' ) }
				</a>
			) : null;
			break;
		case 'connect':
			action = (
				<a href={ viewHref( 'credits' ) }>
					{ __( 'Connect to Zinn®', 'zinn-seo' ) }
				</a>
			);
			break;
		case 'retry':
			action = onRetry ? (
				<Button variant="link" onClick={ onRetry }>
					{ __( 'Try again', 'zinn-seo' ) }
				</Button>
			) : null;
			break;
	}
	const soft = [ 'refused', 'rate_limited' ].includes( failure.kind );
	return (
		<Notice
			status={ soft ? 'warning' : 'error' }
			isDismissible={ !! onRemove }
			onRemove={ onRemove }
			className={ `zseo-aifail is-${ failure.kind || 'unknown' }` }
		>
			<span>{ failure.message }</span>
			{ action && <span className="zseo-aifail__do"> { action }</span> }
		</Notice>
	);
}

/**
 * Run an AI task through the plugin's route and keep its outcome.
 *
 * @return {Object} `{ busy, failure, result, run, clear }`.
 */
export function useRun() {
	const [ state, setState ] = useState( {
		busy: '',
		failure: null,
		result: null,
	} );
	const run = useCallback( ( name, path, data ) => {
		setState( { busy: name, failure: null, result: null } );
		return seoFetch( path, { method: 'POST', data } )
			.then( ( result ) => {
				const items = ( result && result.items ) || [];
				const failed = items.find( ( i ) => ! i.ok );
				setState( {
					busy: '',
					failure:
						failed && items.length === 1 ? failed.failure : null,
					result,
				} );
				return result;
			} )
			.catch( ( error ) => {
				setState( {
					busy: '',
					failure: failureOf( error ),
					result: null,
				} );
				return null;
			} );
	}, [] );
	const clear = useCallback(
		() => setState( { busy: '', failure: null, result: null } ),
		[]
	);
	return { ...state, run, clear };
}

/**
 * The first answered item of a run.
 *
 * @param {Object|null} result A run.
 * @return {Object|null} Its fields.
 */
export function firstFields( result ) {
	const item = result && result.items && result.items.find( ( i ) => i.ok );
	return item ? item.fields : null;
}

/**
 * How AI is paid here, in a few words, under a button.
 *
 * @param {Object} props
 * @param {Object} props.ai The AI state.
 * @return {Element|null} Line.
 */
export function PaidBy( { ai } ) {
	if ( ! ai ) {
		return null;
	}
	return (
		<span className="zseo-paidby">
			{ ai.on_credits
				? __( 'Paid from your Zinn® credit', 'zinn-seo' )
				: __( 'Uses your own AI key', 'zinn-seo' ) }
		</span>
	);
}

/**
 * The "not set up" card: own key, or Zinn® credits.
 *
 * @param {Object} props
 * @param {Object} props.ai The AI state.
 * @return {Element} Card content.
 */
export function SetUp( { ai } ) {
	return (
		<div className="zseo-stack">
			<p className="zseo-lead">
				{ __(
					'Choose how AI is paid for: use your own key from OpenAI, Anthropic, Google Gemini or another provider (you pay them directly), or pay as you go with Zinn® credits — no key needed.',
					'zinn-seo'
				) }
			</p>
			<div className="zseo-actions zseo-actions--start">
				<Button variant="primary" href={ ai.settings_url }>
					{ __( 'Add your own AI key', 'zinn-seo' ) }
				</Button>
				<Button variant="secondary" href={ viewHref( 'credits' ) }>
					{ ai.credits && ai.credits.connected
						? __( 'Use Zinn® credits', 'zinn-seo' )
						: __( 'Connect to use Zinn® credits', 'zinn-seo' ) }
				</Button>
			</div>
		</div>
	);
}
