/**
 * The SEO agent in the editor's AI panel: the person asks in their own words, the agent replies
 * and PROPOSES a new SEO title, meta description or focus phrase. Each proposal has its own
 * Apply; nothing changes until the person applies one and saves the page (zs-200). When the
 * site's AI provider is down the server asks the next one the site has a key for, and the answer
 * says which one replied.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, TextareaControl } from '@wordpress/components';

import { AiFailure, firstFields, useRun } from '../ai/parts';

/** The only fields the agent may propose, in the order they are shown. */
export const AGENT_FIELDS = [ 'title', 'desc', 'focus' ];

/**
 * The proposals in an answer: a non-empty value for an allowed field that differs from the
 * page's current one. Anything else the server sent is ignored.
 *
 * @param {Object|null} fields  The answer's fields.
 * @param {Object}      current The page's current `{title, desc, focus}`.
 * @return {Array<{name: string, value: string}>} Proposals.
 */
export function proposals( fields, current ) {
	if ( ! fields ) {
		return [];
	}
	return AGENT_FIELDS.filter(
		( name ) =>
			typeof fields[ name ] === 'string' &&
			fields[ name ].trim() !== '' &&
			fields[ name ] !== ( current[ name ] || '' )
	).map( ( name ) => ( { name, value: fields[ name ] } ) );
}

/**
 * The agent box.
 *
 * @param {Object}   props
 * @param {number}   props.postId    The post.
 * @param {Object}   props.current   The page's unsaved `{title, desc, focus}`.
 * @param {Function} props.setFields `( task, fields, done )`: puts fields into the editor.
 * @param {string}   props.buyUrl    Where to add Zinn® credit.
 * @return {Element} Section.
 */
export default function AgentBox( { postId, current, setFields, buyUrl } ) {
	const run = useRun();
	const [ ask, setAsk ] = useState( '' );
	const [ applied, setApplied ] = useState( {} );
	const labels = {
		title: __( 'SEO title', 'zinn-seo' ),
		desc: __( 'Meta description', 'zinn-seo' ),
		focus: __( 'Focus phrase', 'zinn-seo' ),
	};
	const send = () => {
		if ( ask.trim() === '' ) {
			return;
		}
		setApplied( {} );
		run.run( 'agent', '/ai/agent', {
			id: postId,
			ask,
			title: current.title || '',
			desc: current.desc || '',
			focus: current.focus || '',
		} );
	};
	const answer = firstFields( run.result );
	const tried = ( run.result && run.result.tried ) || [];
	const list = proposals( answer, current );

	return (
		<section className="zseo-ai-ed__feature zseo-ai-ed__agent">
			<h3>{ __( 'SEO agent', 'zinn-seo' ) }</h3>
			<p className="zseo-ai-ed__help">
				{ __(
					'Ask for help with this page in your own words. The agent suggests changes; nothing changes until you apply one and save the page.',
					'zinn-seo'
				) }
			</p>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'What would you like to do?', 'zinn-seo' ) }
				hideLabelFromVision
				placeholder={ __(
					'For example: make the title more specific to the topic',
					'zinn-seo'
				) }
				value={ ask }
				onChange={ setAsk }
				rows={ 3 }
				maxLength={ 600 }
			/>
			<Button
				variant="secondary"
				size="compact"
				isBusy={ !! run.busy }
				disabled={ !! run.busy || ask.trim() === '' }
				onClick={ send }
			>
				{ __( 'Ask the agent', 'zinn-seo' ) }
			</Button>
			<AiFailure
				failure={ run.failure }
				buyUrl={ buyUrl }
				onRetry={ send }
				onRemove={ run.clear }
			/>
			{ run.failure && (
				<p className="zseo-ai-ed__help">
					{ __(
						'The single tools below still work when your provider answers again.',
						'zinn-seo'
					) }
				</p>
			) }
			{ answer && (
				<div className="zseo-ai-ed__answer">
					{ answer.reply && <p>{ answer.reply }</p> }
					{ tried.length > 1 && (
						<p className="zseo-ai-ed__help">
							{ sprintf(
								/* translators: 1: the AI provider that answered, 2: the ones that did not, comma-separated. */
								__(
									'Answered by %1$s because %2$s did not answer.',
									'zinn-seo'
								),
								tried[ tried.length - 1 ],
								tried.slice( 0, -1 ).join( ', ' )
							) }
						</p>
					) }
					{ list.map( ( p ) => (
						<div key={ p.name } className="zseo-ai-ed__proposal">
							<p>
								<strong>{ labels[ p.name ] }</strong>
								{ ': ' }
								{ p.value }
							</p>
							<Button
								variant="primary"
								size="compact"
								disabled={ !! applied[ p.name ] }
								onClick={ () => {
									setFields(
										'agent',
										{ [ p.name ]: p.value },
										__( 'Applied.', 'zinn-seo' )
									);
									setApplied( {
										...applied,
										[ p.name ]: true,
									} );
								} }
							>
								{ applied[ p.name ]
									? __( 'Applied', 'zinn-seo' )
									: __( 'Apply', 'zinn-seo' ) }
							</Button>
						</div>
					) ) }
				</div>
			) }
		</section>
	);
}
