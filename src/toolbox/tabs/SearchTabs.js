/**
 * The search tools: YouTube rankings (zs-525), snapshots of the results page (zs-524), "People
 * also ask" → an FAQ (zs-529) and content briefs from the top ten (zs-530).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import { NewTab, Refusal, Table, useAction } from '../../tools/parts';
import { count } from '../../tools/pure';
import { lines, unitsOf } from '../pure';
import {
	History,
	RunMoney,
	RunNow,
	RunState,
	Schedule,
	useRuns,
} from '../shared';

/**
 * The market every search tool asks in.
 *
 * @param {Object} ctx Shared state.
 * @return {Object} `{location, language}`.
 */
function market( ctx ) {
	return {
		location: ctx.market.location || '',
		language: ctx.market.language || '',
	};
}

/**
 * YouTube rankings.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function YoutubeTab( { ctx } ) {
	const runs = useRuns( 'youtube' );
	const [ phrases, setPhrases ] = useState( '' );
	const [ own, setOwn ] = useState( '' );
	const params = {
		phrases: lines( phrases ),
		own: lines( own, 20 ),
		...market( ctx ),
	};
	const units = unitsOf( 'youtube', params );
	let why = '';
	if ( ! params.own.length ) {
		why = __(
			'Add your channel or one of your video links, so we can tell which results are yours.',
			'zinn-seo'
		);
	} else if ( ! units ) {
		why = __( 'Add at least one search.', 'zinn-seo' );
	}
	const run = runs.run;
	const rows = ( run && run.result && run.result.phrases ) || [];
	return (
		<div className="zseo-stack">
			<div className="zseo-formrow">
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Searches on YouTube', 'zinn-seo' ) }
					help={ __( 'One per line.', 'zinn-seo' ) }
					value={ phrases }
					onChange={ setPhrases }
					rows={ 4 }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Your channel or videos', 'zinn-seo' ) }
					help={ __(
						'Your channel address (youtube.com/@name) or video links, one per line.',
						'zinn-seo'
					) }
					value={ own }
					onChange={ setOwn }
					rows={ 4 }
				/>
			</div>
			<RunNow
				product="youtube"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Check YouTube', 'zinn-seo' ) }
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product="youtube"
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Check it automatically', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.length > 0 && (
				<Table
					label={ __( 'Your YouTube rankings', 'zinn-seo' ) }
					rowKey={ ( r ) => r.phrase }
					rows={ rows }
					columns={ [
						{
							id: 'q',
							label: __( 'Search', 'zinn-seo' ),
							render: ( r ) => r.phrase,
						},
						{
							id: 'pos',
							label: __( 'Your best place', 'zinn-seo' ),
							numeric: true,
							render: ( r ) => {
								if ( ! r.answered ) {
									return __(
										'not checked (refunded)',
										'zinn-seo'
									);
								}
								return (
									r.position ??
									__( 'not in the top 20', 'zinn-seo' )
								);
							},
						},
						{
							id: 'top',
							label: __( 'Top video', 'zinn-seo' ),
							render: ( r ) => {
								const top = ( r.results || [] )[ 0 ];
								return top ? (
									<NewTab
										href={ top.url }
									>{ `${ top.title } — ${ top.channel }` }</NewTab>
								) : (
									'—'
								);
							},
						},
					] }
				/>
			) }
			<RunMoney run={ run } />
		</div>
	);
}

/**
 * Snapshots of the results page: a dated copy and a picture, as proof and to see what changed.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function SnapshotTab( { ctx } ) {
	const runs = useRuns( 'snapshot' );
	const [ phrases, setPhrases ] = useState( '' );
	const [ device, setDevice ] = useState( 'desktop' );
	const params = { phrases: lines( phrases, 25 ), device, ...market( ctx ) };
	const units = unitsOf( 'snapshot', params );
	const why = units ? '' : __( 'Add at least one search.', 'zinn-seo' );
	const run = runs.run;
	const rows = ( run && run.result && run.result.phrases ) || [];
	return (
		<div className="zseo-stack">
			<div className="zseo-formrow">
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Searches to keep a copy of', 'zinn-seo' ) }
					help={ __( 'One per line.', 'zinn-seo' ) }
					value={ phrases }
					onChange={ setPhrases }
					rows={ 4 }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Seen on', 'zinn-seo' ) }
					value={ device }
					options={ [
						{
							value: 'desktop',
							label: __( 'A computer', 'zinn-seo' ),
						},
						{ value: 'mobile', label: __( 'A phone', 'zinn-seo' ) },
					] }
					onChange={ setDevice }
				/>
			</div>
			<RunNow
				product="snapshot"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Take a snapshot', 'zinn-seo' ) }
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product="snapshot"
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Take one automatically', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.map( ( r, n ) => (
				<section key={ n } className="zseo-snapshot zseo-stack">
					<h3 className="zseo-card-title">
						{ r.phrase }
						{ r.answered &&
							' · ' +
								( null === r.own_position ||
								undefined === r.own_position
									? __(
											'you are not on page one',
											'zinn-seo'
										)
									: sprintf(
											/* translators: %s: a position. */
											__(
												'you are number %s',
												'zinn-seo'
											),
											count( r.own_position )
										) ) }
					</h3>
					{ ! r.answered && (
						<p className="zseo-muted">
							{ __(
								'Not taken this time (refunded).',
								'zinn-seo'
							) }
						</p>
					) }
					{ r.picture && (
						<a
							href={ r.picture }
							target="_blank"
							rel="noopener noreferrer"
							className="zseo-snapshot__pic"
						>
							<img
								src={ r.picture }
								alt={ sprintf(
									/* translators: %s: a search. */ __(
										'Google results for “%s”',
										'zinn-seo'
									),
									r.phrase
								) }
								loading="lazy"
							/>
						</a>
					) }
					{ r.features && r.features.length > 0 && (
						<p className="zseo-muted">
							{ __( 'Also on the page:', 'zinn-seo' ) }{ ' ' }
							{ r.features.map( featureName ).join( ', ' ) }
						</p>
					) }
					{ r.entries && (
						<ol className="zseo-serp">
							{ r.entries
								.filter( ( e ) => 'organic' === e.kind )
								.map( ( e ) => (
									<li key={ e.position }>
										<NewTab href={ e.url }>
											{ e.title || e.domain }
										</NewTab>{ ' ' }
										<span className="zseo-muted">
											{ e.domain }
										</span>
									</li>
								) ) }
						</ol>
					) }
				</section>
			) ) }
			<RunMoney run={ run } />
		</div>
	);
}

/**
 * A results-page feature by name.
 *
 * @param {string} kind Feature code.
 * @return {string} Words.
 */
export function featureName( kind ) {
	const names = {
		ai_overview: __( 'Google’s AI answer', 'zinn-seo' ),
		people_also_ask: __( 'People also ask', 'zinn-seo' ),
		local_pack: __( 'Map results', 'zinn-seo' ),
		featured_snippet: __( 'Featured answer', 'zinn-seo' ),
		video: __( 'Videos', 'zinn-seo' ),
		images: __( 'Images', 'zinn-seo' ),
		top_stories: __( 'Top stories', 'zinn-seo' ),
		related_searches: __( 'Related searches', 'zinn-seo' ),
		discussions_and_forums: __( 'Forums', 'zinn-seo' ),
		shopping: __( 'Shopping', 'zinn-seo' ),
		paid: __( 'Ads', 'zinn-seo' ),
	};
	return names[ kind ] || kind.replace( /_/g, ' ' );
}

/**
 * "People also ask" → an FAQ on a page.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function QuestionsTab( { ctx } ) {
	const runs = useRuns( 'questions' );
	const action = useAction();
	const [ phrase, setPhrase ] = useState( '' );
	const [ picked, setPicked ] = useState( {} );
	const [ answers, setAnswers ] = useState( {} );
	const [ postId, setPostId ] = useState( '' );
	const params = { phrase: phrase.trim(), ...market( ctx ) };
	const units = unitsOf( 'questions', params );
	const run = runs.run;
	const found = ( run && run.result && run.result.questions ) || [];
	const chosen = found.filter( ( q, n ) => picked[ n ] !== false );
	const items = found
		.map( ( q, n ) => ( {
			question: q.question,
			answer: answers[ n ] ?? q.answer ?? '',
		} ) )
		.filter( ( q, n ) => picked[ n ] !== false );
	const ai = () =>
		action
			.run( 'ai', () =>
				seoFetch( '/toolbox/ai/faq-answers', {
					method: 'POST',
					data: {
						questions: chosen.map( ( q ) => q.question ),
						phrase: run.params.phrase,
					},
				} )
			)
			.then( ( out ) => {
				if ( out ) {
					const map = { ...answers };
					( out.answers || [] ).forEach( ( a ) => {
						const n = found.findIndex(
							( q ) => q.question === a.question
						);
						if ( n >= 0 ) {
							map[ n ] = a.answer;
						}
					} );
					setAnswers( map );
				}
			} );
	const place = () =>
		action
			.run(
				'faq',
				() =>
					seoFetch( '/toolbox/faq', {
						method: 'POST',
						data: {
							items,
							post_id: Number( postId ) || 0,
							title: run.params.phrase,
						},
					} ),
				''
			)
			.then(
				( made ) =>
					made && made.edit && window.location.assign( made.edit )
			);
	return (
		<div className="zseo-stack">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'A search your customers make', 'zinn-seo' ) }
				value={ phrase }
				onChange={ setPhrase }
			/>
			<RunNow
				product="questions"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Find the questions people ask', 'zinn-seo' ) }
				why={ units ? '' : __( 'Type a search first.', 'zinn-seo' ) }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			{ found.length > 0 && (
				<div className="zseo-stack">
					<p className="zseo-lead">
						{ __(
							'Choose the questions to answer on your site. Answers Google shows are filled in; edit them so they are yours.',
							'zinn-seo'
						) }
					</p>
					{ found.map( ( q, n ) => (
						<div key={ n } className="zseo-question">
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ q.question }
								checked={ picked[ n ] !== false }
								onChange={ ( on ) =>
									setPicked( { ...picked, [ n ]: on } )
								}
							/>
							{ picked[ n ] !== false && (
								<TextareaControl
									__nextHasNoMarginBottom
									label={ __( 'Answer', 'zinn-seo' ) }
									hideLabelFromVision
									value={ answers[ n ] ?? q.answer ?? '' }
									onChange={ ( v ) =>
										setAnswers( { ...answers, [ n ]: v } )
									}
									rows={ 3 }
								/>
							) }
						</div>
					) ) }
					<div className="zseo-actions zseo-actions--start">
						{ ctx.pro ? (
							<Button
								variant="secondary"
								isBusy={ 'ai' === action.busy }
								disabled={ ! chosen.length }
								onClick={ ai }
							>
								{ __(
									'Write the answers with AI',
									'zinn-seo'
								) }
							</Button>
						) : (
							<span className="zseo-muted">
								{ __(
									'With Pro, AI writes the answers for you.',
									'zinn-seo'
								) }
							</span>
						) }
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							label={ __(
								'Add to page (ID), or leave empty for a new draft',
								'zinn-seo'
							) }
							value={ postId }
							onChange={ setPostId }
						/>
						<Button
							variant="primary"
							isBusy={ 'faq' === action.busy }
							disabled={ ! items.length }
							onClick={ place }
						>
							{ Number( postId )
								? __( 'Add the FAQ to that page', 'zinn-seo' )
								: __(
										'Create a draft with this FAQ',
										'zinn-seo'
									) }
						</Button>
					</div>
				</div>
			) }
			{ run && 'complete' === run.status && ! found.length && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Google shows no “People also ask” box for this search right now.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<RunMoney run={ run } />
		</div>
	);
}

/**
 * A content brief from the top ten, and a draft started from it.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function BriefTab( { ctx } ) {
	const runs = useRuns( 'brief' );
	const action = useAction();
	const [ phrase, setPhrase ] = useState( '' );
	const params = { phrase: phrase.trim(), ...market( ctx ) };
	const units = unitsOf( 'brief', params );
	const run = runs.run;
	const brief =
		( run && run.result && run.result.pages && run.result ) || null;
	const draft = () =>
		action
			.run(
				'draft',
				() =>
					seoFetch( '/toolbox/brief-draft', {
						method: 'POST',
						data: { brief },
					} ),
				''
			)
			.then(
				( made ) =>
					made && made.edit && window.location.assign( made.edit )
			);
	return (
		<div className="zseo-stack">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'The search you want to rank for', 'zinn-seo' ) }
				value={ phrase }
				onChange={ setPhrase }
			/>
			<RunNow
				product="brief"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Build the brief', 'zinn-seo' ) }
				why={ units ? '' : __( 'Type a search first.', 'zinn-seo' ) }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			{ brief && (
				<div className="zseo-stack">
					<p className="zseo-lead">
						{ sprintf(
							/* translators: %s: a number of words. */
							__(
								'The pages that rank are about %s words long.',
								'zinn-seo'
							),
							count( brief.median_words )
						) }
					</p>
					{ brief.terms.length > 0 && (
						<p>
							<strong>
								{ __( 'Cover these topics:', 'zinn-seo' ) }
							</strong>{ ' ' }
							{ brief.terms.slice( 0, 20 ).join( ', ' ) }
						</p>
					) }
					{ brief.questions.length > 0 && (
						<>
							<strong>
								{ __( 'Answer these questions:', 'zinn-seo' ) }
							</strong>
							<ul className="zseo-bullets">
								{ brief.questions.map( ( q ) => (
									<li key={ q }>{ q }</li>
								) ) }
							</ul>
						</>
					) }
					<Table
						label={ __( 'The top ten', 'zinn-seo' ) }
						rowKey={ ( r ) => r.url }
						rows={ brief.pages }
						columns={ [
							{
								id: 'n',
								label: '#',
								numeric: true,
								render: ( r ) => r.position,
							},
							{
								id: 't',
								label: __( 'Page', 'zinn-seo' ),
								render: ( r ) => (
									<NewTab href={ r.url }>
										{ r.title || r.url }
									</NewTab>
								),
							},
							{
								id: 'w',
								label: __( 'Words', 'zinn-seo' ),
								numeric: true,
								render: ( r ) =>
									r.read
										? count( r.words )
										: __( 'could not be read', 'zinn-seo' ),
							},
							{
								id: 'h',
								label: __( 'Sections', 'zinn-seo' ),
								render: ( r ) =>
									( r.headings || [] )
										.slice( 1, 6 )
										.join( ' · ' ),
							},
						] }
					/>
					<div>
						<Button
							variant="primary"
							isBusy={ 'draft' === action.busy }
							onClick={ draft }
						>
							{ __(
								'Start a draft from this brief',
								'zinn-seo'
							) }
						</Button>
					</div>
				</div>
			) }
			<RunMoney run={ run } />
		</div>
	);
}
