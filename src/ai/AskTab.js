/**
 * Ask your SEO data (zs-540): questions about this site's search performance, answered from its
 * own figures — Search Console, rankings, backlinks, audits, AI-answer checks, open issues — with
 * the numbers cited.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, TextareaControl } from '@wordpress/components';

import GuideLink from '../admin/p7/GuideLink';
import { AiFailure, PaidBy, firstFields, useRun } from './parts';

/** Questions to start from. */
const STARTERS = () => [
	__( 'Why did my traffic change in the last four weeks?', 'zinn-seo' ),
	__( 'Which pages should I improve first, and why?', 'zinn-seo' ),
	__( 'Am I mentioned in AI answers for my main topics?', 'zinn-seo' ),
	__( 'What are my biggest technical SEO problems right now?', 'zinn-seo' ),
];

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function AskTab( { data } ) {
	const ai = data.ai;
	const [ question, setQuestion ] = useState( '' );
	const [ turns, setTurns ] = useState( [] );
	const run = useRun();
	const ask = ( q ) => {
		const text = ( q || question ).trim();
		if ( ! text ) {
			return;
		}
		run.run( 'ask', '/ai/ask', {
			question: text,
			history: turns
				.slice( -6 )
				.map( ( t ) => ( { q: t.q, a: t.a.answer } ) ),
		} ).then( ( result ) => {
			const fields = firstFields( result );
			if ( fields ) {
				setTurns( [ ...turns, { q: text, a: fields } ] );
				setQuestion( '' );
			}
		} );
	};
	return (
		<Card>
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h2 className="zseo-card-title">
						{ __( 'Ask your SEO data', 'zinn-seo' ) }
					</h2>
					<GuideLink data={ data } id="ai-ask" />
				</div>
				<p className="zseo-lead">
					{ __(
						"Ask in your own words. The answer comes from this site's own figures — Search Console, rankings, backlinks, audits and AI-answer checks — with the numbers it used, so you can check it.",
						'zinn-seo'
					) }
				</p>
				{ turns.length === 0 && (
					<ul className="zseo-starters">
						{ STARTERS().map( ( s ) => (
							<li key={ s }>
								<Button
									variant="secondary"
									onClick={ () => ask( s ) }
									disabled={ !! run.busy }
								>
									{ s }
								</Button>
							</li>
						) ) }
					</ul>
				) }
				<ol className="zseo-chat" aria-live="polite">
					{ turns.map( ( t, i ) => (
						<li key={ i } className="zseo-chat__turn">
							<p className="zseo-chat__q">{ t.q }</p>
							<div className="zseo-chat__a">
								<p>{ t.a.answer }</p>
								{ t.a.figures.length > 0 && (
									<details>
										<summary>
											{ __( 'Figures used', 'zinn-seo' ) }
										</summary>
										<ul>
											{ t.a.figures.map( ( f ) => (
												<li key={ f }>{ f }</li>
											) ) }
										</ul>
									</details>
								) }
								{ t.a.next.length > 0 && (
									<>
										<p className="zseo-chat__next">
											{ __(
												'What to do next',
												'zinn-seo'
											) }
										</p>
										<ul>
											{ t.a.next.map( ( n ) => (
												<li key={ n }>{ n }</li>
											) ) }
										</ul>
									</>
								) }
							</div>
						</li>
					) ) }
				</ol>
				<AiFailure
					failure={ run.failure }
					buyUrl={ ai.credits.buy_url }
					onRetry={ () => ask() }
					onRemove={ run.clear }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Your question', 'zinn-seo' ) }
					value={ question }
					rows={ 3 }
					onChange={ setQuestion }
				/>
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="primary"
						isBusy={ !! run.busy }
						disabled={ ! question.trim() || !! run.busy }
						onClick={ () => ask() }
					>
						{ __( 'Ask', 'zinn-seo' ) }
					</Button>
					<PaidBy ai={ ai } />
				</div>
			</CardBody>
		</Card>
	);
}
