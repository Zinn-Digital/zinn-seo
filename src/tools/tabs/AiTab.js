/**
 * AI answers: do ChatGPT, Perplexity, Gemini and Claude mention the business when a buyer asks?
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Refusal, Table, useAction } from '../parts';
import { count, lines, shareOfVoice } from '../pure';
import { Empty, Held, RunBar, RunLine, Schedule, useResults } from '../tool';
import { engineName } from '../labels';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function AiTab( { data, ctx } ) {
	const state = ctx.tools.ai;
	const results = useResults( 'ai' );
	const action = useAction();
	const [ form, setForm ] = useState( {
		brand: ctx.target.brand || data.siteName || '',
		category: ctx.target.category || '',
		market: ctx.target.market || '',
	} );
	const [ questions, setQuestions ] = useState(
		( ctx.target.prompts || [] ).join( '\n' )
	);
	const dirty =
		form.brand !== ( ctx.target.brand || '' ) ||
		form.category !== ( ctx.target.category || '' ) ||
		form.market !== ( ctx.target.market || '' );
	const own = lines( questions );
	const summary = ( results.latest && results.latest.summary ) || {};
	const voice = shareOfVoice( summary );
	const answers = summary.answers || [];

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Find out whether AI recommends you',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-ai" />
					</div>
					<p className="zseo-lead">
						{ __(
							'More and more buyers ask an AI instead of searching. We ask ChatGPT, Perplexity, Gemini and Claude the questions your customers would, and show whether they name you, link to you — or send people to a competitor.',
							'zinn-seo'
						) }
					</p>
					<Held state={ state } buyUrl={ ctx.buyUrl } />
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
					<div className="zseo-row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Your business name', 'zinn-seo' ) }
							help={ __(
								'The name to listen for in the answers.',
								'zinn-seo'
							) }
							value={ form.brand }
							disabled={ ! ctx.canSpend }
							onChange={ ( brand ) =>
								setForm( { ...form, brand } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'What you sell', 'zinn-seo' ) }
							help={ __(
								'In your customers’ words, e.g. “wedding photography”. The questions are built from this.',
								'zinn-seo'
							) }
							value={ form.category }
							disabled={ ! ctx.canSpend }
							onChange={ ( category ) =>
								setForm( { ...form, category } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Where you sell', 'zinn-seo' ) }
							help={ __(
								'A town, a region or a country, e.g. “Leeds”.',
								'zinn-seo'
							) }
							value={ form.market }
							disabled={ ! ctx.canSpend }
							onChange={ ( market ) =>
								setForm( { ...form, market } )
							}
						/>
					</div>
					{ ctx.canSpend && (
						<div>
							<Button
								variant="secondary"
								disabled={ ! dirty || !! action.busy }
								isBusy={ 'site' === action.busy }
								onClick={ () =>
									action
										.run(
											'site',
											() =>
												seoFetch( '/tools/site', {
													method: 'PUT',
													data: form,
												} ),
											__( 'Saved.', 'zinn-seo' )
										)
										.then(
											( done ) => done && ctx.refresh()
										)
								}
							>
								{ __( 'Save', 'zinn-seo' ) }
							</Button>
						</div>
					) }
					{ state.in_plan || 'credits' === ctx.target.funding ? (
						<>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __(
									'Your own questions (one per line)',
									'zinn-seo'
								) }
								help={
									state.in_plan
										? __(
												'Leave empty and we ask three a buyer would, built from what you sell and where. A question that names you is refused — it would always mention you and measure nothing.',
												'zinn-seo'
											)
										: __(
												'Leave empty and we ask three a buyer would, built from what you sell and where. Each question of your own is paid per check, like the three. A question that names you is refused — it would always mention you and measure nothing.',
												'zinn-seo'
											)
								}
								rows={ 4 }
								value={ questions }
								disabled={ ! ctx.canSpend }
								onChange={ setQuestions }
							/>
							{ ctx.canSpend && (
								<div>
									<Button
										variant="secondary"
										disabled={
											own.join( '\n' ) ===
												(
													ctx.target.prompts || []
												).join( '\n' ) || !! action.busy
										}
										isBusy={ 'list' === action.busy }
										onClick={ () =>
											action
												.run(
													'list',
													() =>
														seoFetch(
															'/tools/ai/list',
															{
																method: 'PUT',
																data: {
																	items: own,
																},
															}
														),
													__( 'Saved.', 'zinn-seo' )
												)
												.then(
													( done ) =>
														done && ctx.refresh()
												)
										}
									>
										{ __(
											'Save my questions',
											'zinn-seo'
										) }
									</Button>
								</div>
							) }
						</>
					) : (
						<p className="zseo-muted">
							{ sprintf(
								/* translators: %s: a number of questions. */
								__(
									'Each check asks %s questions a buyer would ask, built from what you sell and where. With the AI Visibility plan in your Zinn® account you can write your own.',
									'zinn-seo'
								),
								count( state.units )
							) }
						</p>
					) }
					<Schedule
						tool="ai"
						state={ state }
						units={ state.units }
						ctx={ ctx }
						label={ __(
							'Ask the AI engines automatically',
							'zinn-seo'
						) }
					/>
					<RunBar
						tool="ai"
						state={ state }
						units={ state.units }
						ctx={ ctx }
						label={ __( 'Ask the AI engines now', 'zinn-seo' ) }
						disabled={ ! ctx.target.category || dirty }
						why={
							dirty
								? __( 'Save your changes first.', 'zinn-seo' )
								: __(
										'Say what you sell first: the questions are built from it.',
										'zinn-seo'
									)
						}
						running={ results.running }
						onStart={ results.watch }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'What the AI engines said', 'zinn-seo' ) }
					</h2>
					<Refusal error={ results.error } buyUrl={ ctx.buyUrl } />
					<RunLine run={ results.latest } />
					{ ( summary.unanswered || [] ).length > 0 && (
						<Notice status="info" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: names of AI engines. */
								__(
									'No answer this time from: %s. They are left out of the figures, not counted as “did not mention you”.',
									'zinn-seo'
								),
								summary.unanswered
									.map( ( e ) => engineName( e ) )
									.join( ', ' )
							) }
						</Notice>
					) }
					{ voice.length > 0 ? (
						<>
							<Table
								label={ __(
									'Mentions by AI engine',
									'zinn-seo'
								) }
								rowKey={ ( r ) => r.engine }
								rows={ voice }
								columns={ [
									{
										id: 'engine',
										label: __( 'AI engine', 'zinn-seo' ),
										render: ( r ) => (
											<strong>
												{ engineName( r.engine ) }
											</strong>
										),
									},
									{
										id: 'named',
										label: __( 'Named you', 'zinn-seo' ),
										render: ( r ) =>
											sprintf(
												/* translators: 1: answers that mention the site, 2: questions asked. */
												__(
													'%1$s of %2$s answers',
													'zinn-seo'
												),
												count( r.mentioned ),
												count( r.asked )
											),
									},
									{
										id: 'linked',
										label: __(
											'Linked to you',
											'zinn-seo'
										),
										render: ( r ) =>
											sprintf(
												/* translators: 1: answers that link to the site, 2: questions asked. */
												__(
													'%1$s of %2$s answers',
													'zinn-seo'
												),
												count( r.linked ),
												count( r.asked )
											),
									},
								] }
							/>
							<ul className="zseo-answers">
								{ answers.map( ( a, i ) => (
									<li key={ i }>
										<details>
											<summary>
												<span
													className={ `zseo-tag ${
														a.mentioned
															? 'is-success'
															: 'is-warning'
													}` }
												>
													{ a.mentioned
														? __(
																'named you',
																'zinn-seo'
															)
														: __(
																'did not name you',
																'zinn-seo'
															) }
												</span>{ ' ' }
												<strong>
													{ engineName( a.engine ) }
												</strong>
												{ ' — ' }
												{ a.prompt }
											</summary>
											<p className="zseo-answer">
												{ a.answer }
											</p>
											{ a.cited_url && (
												<p>
													{ __(
														'Quoted your page:',
														'zinn-seo'
													) }{ ' ' }
													<a
														href={ a.cited_url }
														dir="ltr"
													>
														{ a.cited_url }
													</a>
												</p>
											) }
											{ ( a.competitors || [] ).length >
												0 && (
												<p>
													{ __(
														'Named instead or as well:',
														'zinn-seo'
													) }{ ' ' }
													<span dir="ltr">
														{ a.competitors.join(
															', '
														) }
													</span>
												</p>
											) }
										</details>
									</li>
								) ) }
							</ul>
						</>
					) : (
						! results.loading && (
							<Empty>
								{ __(
									'No answers yet. Run the first check and you will see, engine by engine, whether you were named, linked — and who was recommended instead.',
									'zinn-seo'
								) }
							</Empty>
						)
					) }
				</CardBody>
			</Card>
		</div>
	);
}
