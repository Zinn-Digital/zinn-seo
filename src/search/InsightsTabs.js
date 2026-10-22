/**
 * Pro: what Search Console says to do — striking distance and keyword opportunities, pages losing
 * clicks, the topical map, and SEO changes on the clicks chart (zs-320, zs-091, zs-008, zs-323,
 * zs-321).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import {
	Loaded,
	NewTab,
	Refusal,
	Table,
	useAction,
	useLoad,
} from '../tools/parts';
import { count, day } from '../tools/pure';
import Chart from './Chart';
import { NeedsGoogle, Period } from './OverviewTab';
import { pct, position, shortUrl } from './pure';

/**
 * The exact change, in words.
 *
 * @param {string} fix title|heading|faq|description|links.
 * @return {string} Text.
 */
function fixText( fix ) {
	switch ( fix ) {
		case 'title':
			return __( 'Put this phrase in the SEO title', 'zinn-seo' );
		case 'heading':
			return __( 'Add a heading (H2) with this phrase', 'zinn-seo' );
		case 'faq':
			return __(
				'Answer it as a question on the page (FAQ)',
				'zinn-seo'
			);
		case 'description':
			return __( 'Use it in the meta description', 'zinn-seo' );
		default:
			return __(
				'Link to this page from other pages using this phrase',
				'zinn-seo'
			);
	}
}

/**
 * The AI rewrite of one search on one page, with one-click use.
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @param {string} props.query  Search.
 * @param {string} props.edit   Edit link.
 * @return {Element} Panel.
 */
function Rewrite( { postId, query, edit } ) {
	const act = useAction();
	const [ out, setOut ] = useState( null );
	const [ used, setUsed ] = useState( {} );
	const ask = () =>
		act.run( 'ask', () =>
			seoFetch( '/search/rewrite', {
				method: 'POST',
				data: { post_id: postId, query },
			} ).then( setOut )
		);
	const apply = ( field, value ) =>
		act.run( field, () =>
			seoFetch( '/search/apply', {
				method: 'POST',
				data: { post_id: postId, field, value },
			} ).then( () => setUsed( { ...used, [ field ]: true } ) )
		);
	if ( ! out ) {
		return (
			<div className="zseo-stack">
				<div>
					<Button
						variant="secondary"
						isBusy={ act.busy === 'ask' }
						disabled={ !! act.busy }
						onClick={ ask }
					>
						{ __( 'Rewrite with AI', 'zinn-seo' ) }
					</Button>
				</div>
				<Refusal error={ act.error } onRemove={ act.clear } />
			</div>
		);
	}
	return (
		<div className="zseo-stack zseo-rewrite">
			{ [
				[ 'title', __( 'SEO title', 'zinn-seo' ), out.title ],
				[
					'desc',
					__( 'Meta description', 'zinn-seo' ),
					out.description,
				],
			].map( ( [ field, label, value ] ) => (
				<div key={ field } className="zseo-rewrite__row">
					<strong>{ label }</strong>
					<span className="zseo-rewrite__text">{ value }</span>
					<Button
						variant={ used[ field ] ? 'tertiary' : 'primary' }
						disabled={ !! act.busy || used[ field ] }
						onClick={ () => apply( field, value ) }
					>
						{ used[ field ]
							? __( 'Used', 'zinn-seo' )
							: __( 'Use this', 'zinn-seo' ) }
					</Button>
				</div>
			) ) }
			<div className="zseo-rewrite__row">
				<strong>{ __( 'Heading to add', 'zinn-seo' ) }</strong>
				<span className="zseo-rewrite__text">{ out.heading }</span>
			</div>
			<div className="zseo-rewrite__row">
				<strong>
					{ __( 'Question and answer to add', 'zinn-seo' ) }
				</strong>
				<span className="zseo-rewrite__text">
					{ out.question } { out.answer }
				</span>
			</div>
			{ edit && (
				<NewTab href={ edit }>
					{ __( 'Edit the page', 'zinn-seo' ) }
				</NewTab>
			) }
			<Refusal error={ act.error } onRemove={ act.clear } />
		</div>
	);
}

/**
 * The AI column of one search: a Rewrite link, or the rewrite itself once opened.
 *
 * @param {Object}   props
 * @param {Object}   props.page    The page (`post_id`, `page`, `edit`).
 * @param {string}   props.query   The search.
 * @param {boolean}  props.ai      AI is set up.
 * @param {string}   props.open    Which rewrite is open.
 * @param {Function} props.setOpen Open one.
 * @return {Element|string} Cell.
 */
function AiCell( { page, query, ai, open, setOpen } ) {
	if ( page.post_id < 1 || ! ai ) {
		return '—';
	}
	const key = `${ page.page }|${ query }`;
	if ( open === key ) {
		return (
			<Rewrite
				postId={ page.post_id }
				query={ query }
				edit={ page.edit }
			/>
		);
	}
	return (
		<Button variant="link" onClick={ () => setOpen( key ) }>
			{ __( 'Rewrite', 'zinn-seo' ) }
		</Button>
	);
}

/**
 * Striking distance, page by page.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {number} props.days Days.
 * @return {Element} Card.
 */
function Striking( { data, days } ) {
	const load = useLoad( `/search/striking?days=${ days }` );
	const [ open, setOpen ] = useState( '' );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h3 className="zseo-card-title">
					{ __( 'Almost on page one', 'zinn-seo' ) }
				</h3>
				<p className="zseo-small zseo-muted">
					{ __(
						'Searches where a page sits at position 8 to 20 with at least 10 impressions — the cheapest clicks to win. Each has the one change most likely to move it.',
						'zinn-seo'
					) }
				</p>
				<Loaded load={ load }>
					{ ( r ) =>
						r.pages.length === 0 ? (
							<p className="zseo-muted">
								{ __(
									'No page is in striking distance in this period.',
									'zinn-seo'
								) }
							</p>
						) : (
							<ul className="zseo-pages">
								{ r.pages.map( ( p ) => (
									<li
										key={ p.page }
										className="zseo-pages__item"
									>
										<div className="zseo-cardhead">
											<NewTab href={ p.page }>
												<span className="zseo-address">
													{ p.title ||
														shortUrl(
															p.page,
															data.homeUrl
														) }
												</span>
											</NewTab>
											<span className="zseo-small zseo-muted">
												{ sprintf(
													/* translators: 1: number of searches, 2: impressions. */
													__(
														'%1$s searches · %2$s impressions',
														'zinn-seo'
													),
													count( p.searches.length ),
													count( p.impressions )
												) }
											</span>
										</div>
										<Table
											label={ __(
												'Searches',
												'zinn-seo'
											) }
											rowKey={ ( s ) => s.query }
											rows={ p.searches }
											columns={ [
												{
													id: 'q',
													label: __(
														'Search',
														'zinn-seo'
													),
													render: ( s ) => s.query,
												},
												{
													id: 'pos',
													numeric: true,
													label: __(
														'Position',
														'zinn-seo'
													),
													render: ( s ) =>
														position( s.position ),
												},
												{
													id: 'imp',
													numeric: true,
													label: __(
														'Impressions',
														'zinn-seo'
													),
													render: ( s ) =>
														count( s.impressions ),
												},
												{
													id: 'fix',
													label: __(
														'Do this',
														'zinn-seo'
													),
													render: ( s ) =>
														fixText( s.fix ),
												},
												{
													id: 'ai',
													label: __(
														'AI',
														'zinn-seo'
													),
													render: ( s ) => (
														<AiCell
															page={ p }
															query={ s.query }
															ai={ r.ai }
															open={ open }
															setOpen={ setOpen }
														/>
													),
												},
											] }
										/>
									</li>
								) ) }
							</ul>
						)
					}
				</Loaded>
				{ load.data && ! load.data.ai && (
					<p className="zseo-small zseo-muted">
						{ __(
							'Add an AI key (or use Zinn® AI credit) in Settings → AI to rewrite titles in one click.',
							'zinn-seo'
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The opportunities tab.
 *
 * @param {Object}  props
 * @param {Object}  props.data   Boot data.
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export function OpportunitiesTab( { data, google, ready } ) {
	const [ days, setDays ] = useState( 28 );
	const load = useLoad(
		ready ? `/search/opportunities?days=${ days }` : null
	);
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	const list = ( rows, gain ) => (
		<Table
			label={ __( 'Searches', 'zinn-seo' ) }
			rowKey={ ( s ) => `${ s.query }|${ s.page }` }
			rows={ rows.slice( 0, 100 ) }
			columns={ [
				{
					id: 'q',
					label: __( 'Search', 'zinn-seo' ),
					render: ( s ) => s.query,
				},
				{
					id: 'page',
					label: __( 'Page', 'zinn-seo' ),
					render: ( s ) => (
						<span className="zseo-address">
							{ shortUrl( s.page, data.homeUrl ) }
						</span>
					),
				},
				{
					id: 'pos',
					numeric: true,
					label: __( 'Position', 'zinn-seo' ),
					render: ( s ) => position( s.position ),
				},
				{
					id: 'imp',
					numeric: true,
					label: __( 'Impressions', 'zinn-seo' ),
					render: ( s ) => count( s.impressions ),
				},
				{
					id: 'ctr',
					numeric: true,
					label: __( 'Click rate', 'zinn-seo' ),
					render: ( s ) => pct( s.ctr ),
				},
				{
					id: 'gain',
					numeric: true,
					label: gain,
					render: ( s ) => count( Math.max( 0, s.gain ) ),
				},
			] }
		/>
	);
	return (
		<div className="zseo-stack">
			<Period
				days={ days }
				onChange={ setDays }
				choices={ [ 7, 28, 90 ] }
			/>
			<Striking data={ data } days={ days } />
			<Loaded load={ load }>
				{ ( r ) => (
					<>
						<Card>
							<CardBody className="zseo-stack">
								<h3 className="zseo-card-title">
									{ __(
										'On page two, with real demand',
										'zinn-seo'
									) }
								</h3>
								<p className="zseo-small zseo-muted">
									{ __(
										'Searches at position 11 to 20 with at least 20 impressions. "More clicks" is roughly what reaching position 5 would bring over the same period.',
										'zinn-seo'
									) }
								</p>
								{ r.page_two.length ? (
									list(
										r.page_two,
										__( 'More clicks', 'zinn-seo' )
									)
								) : (
									<p className="zseo-muted">
										{ __(
											'None in this period.',
											'zinn-seo'
										) }
									</p>
								) }
							</CardBody>
						</Card>
						<Card>
							<CardBody className="zseo-stack">
								<h3 className="zseo-card-title">
									{ __(
										'Seen often, clicked rarely',
										'zinn-seo'
									) }
								</h3>
								<p className="zseo-small zseo-muted">
									{ __(
										'Searches where the page is on page one but gets under half the clicks that position usually gets: a better title and description win them.',
										'zinn-seo'
									) }
								</p>
								{ r.low_ctr.length ? (
									list(
										r.low_ctr,
										__( 'Missed clicks', 'zinn-seo' )
									)
								) : (
									<p className="zseo-muted">
										{ __(
											'None in this period.',
											'zinn-seo'
										) }
									</p>
								) }
							</CardBody>
						</Card>
					</>
				) }
			</Loaded>
		</div>
	);
}

/**
 * Pages losing clicks.
 *
 * @param {Object}  props
 * @param {Object}  props.data   Boot data.
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export function DecayTab( { data, google, ready } ) {
	const [ days, setDays ] = useState( 28 );
	const load = useLoad( ready ? `/search/decay?days=${ days }` : null );
	const act = useAction();
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	const todo = ( p, done ) =>
		act.run( p.page, () =>
			seoFetch( '/search/todo', {
				method: 'PUT',
				data: { kind: 'refresh', page: p.page, done },
			} ).then( () => load.reload() )
		);
	return (
		<div className="zseo-stack">
			<Period
				days={ days }
				onChange={ setDays }
				choices={ [ 28, 90, 180 ] }
			/>
			<Loaded load={ load }>
				{ ( r ) => (
					<Card>
						<CardBody className="zseo-stack">
							<p className="zseo-small zseo-muted">
								{ sprintf(
									/* translators: 1: first day, 2: last day, 3: first day of the period before, 4: last day of the period before. */
									__(
										'Pages that lost at least a quarter of their clicks: %1$s to %2$s against %3$s to %4$s (pages that had 10 clicks or more before). A refresh — newer facts, what searchers now ask — usually wins them back.',
										'zinn-seo'
									),
									day( r.from ),
									day( r.to ),
									day( r.before_from ),
									day( r.before_to )
								) }
							</p>
							{ r.pages.length === 0 ? (
								<p className="zseo-ok">
									{ __(
										'No page is losing clicks. Good.',
										'zinn-seo'
									) }
								</p>
							) : (
								<Table
									label={ __(
										'Pages losing clicks',
										'zinn-seo'
									) }
									rowKey={ ( p ) => p.page }
									rows={ r.pages }
									columns={ [
										{
											id: 'page',
											label: __( 'Page', 'zinn-seo' ),
											render: ( p ) => (
												<NewTab href={ p.page }>
													<span className="zseo-address">
														{ p.title ||
															shortUrl(
																p.page,
																data.homeUrl
															) }
													</span>
												</NewTab>
											),
										},
										{
											id: 'b',
											numeric: true,
											label: __(
												'Clicks before',
												'zinn-seo'
											),
											render: ( p ) =>
												count( p.clicks_before ),
										},
										{
											id: 'n',
											numeric: true,
											label: __(
												'Clicks now',
												'zinn-seo'
											),
											render: ( p ) =>
												count( p.clicks_now ),
										},
										{
											id: 'c',
											numeric: true,
											label: __( 'Change', 'zinn-seo' ),
											render: ( p ) => pct( p.change ),
										},
										{
											id: 'pos',
											numeric: true,
											label: __( 'Position', 'zinn-seo' ),
											render: ( p ) =>
												`${ position( p.position_before ) } → ${ position( p.position_now ) }`,
										},
										{
											id: 'do',
											label: __( 'Refresh', 'zinn-seo' ),
											render: ( p ) => (
												<span className="zseo-row">
													<Button
														variant={
															p.todo
																? 'tertiary'
																: 'secondary'
														}
														disabled={
															act.busy === p.page
														}
														onClick={ () =>
															todo( p, p.todo )
														}
													>
														{ p.todo
															? __(
																	'Done',
																	'zinn-seo'
																)
															: __(
																	'Add to my list',
																	'zinn-seo'
																) }
													</Button>
													{ p.edit && (
														<NewTab href={ p.edit }>
															{ __(
																'Edit',
																'zinn-seo'
															) }
														</NewTab>
													) }
												</span>
											),
										},
									] }
								/>
							) }
							<Refusal
								error={ act.error }
								onRemove={ act.clear }
							/>
						</CardBody>
					</Card>
				) }
			</Loaded>
		</div>
	);
}

/**
 * The topical map.
 *
 * @param {Object}  props
 * @param {Object}  props.data   Boot data.
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export function TopicsTab( { data, google, ready } ) {
	const [ days, setDays ] = useState( 90 );
	const load = useLoad( ready ? `/search/topics?days=${ days }` : null );
	const act = useAction();
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	return (
		<div className="zseo-stack">
			<Period
				days={ days }
				onChange={ setDays }
				choices={ [ 28, 90, 180, 365 ] }
			/>
			<p className="zseo-small zseo-muted">
				{ __(
					'Searches grouped by the topic they share. Each topic’s best page is its pillar; the others support it. Gaps are searches you are shown for but have no good page for yet — each comes with a writing brief.',
					'zinn-seo'
				) }
			</p>
			<Loaded load={ load }>
				{ ( r ) =>
					r.groups.length === 0 ? (
						<p className="zseo-muted">
							{ __(
								'Not enough searches yet to group into topics.',
								'zinn-seo'
							) }
						</p>
					) : (
						r.groups.slice( 0, 30 ).map( ( g ) => (
							<Card key={ g.topic }>
								<CardBody className="zseo-stack">
									<div className="zseo-cardhead">
										<h3 className="zseo-card-title">
											{ g.topic }
										</h3>
										<span className="zseo-small zseo-muted">
											{ sprintf(
												/* translators: 1: searches, 2: impressions, 3: clicks. */
												__(
													'%1$s searches · %2$s impressions · %3$s clicks',
													'zinn-seo'
												),
												count( g.searches.length ),
												count( g.impressions ),
												count( g.clicks )
											) }
										</span>
									</div>
									{ g.pillar && (
										<p className="zseo-small">
											<strong>
												{ __(
													'Pillar page:',
													'zinn-seo'
												) }
											</strong>{ ' ' }
											<span className="zseo-address">
												{ g.title ||
													shortUrl(
														g.pillar,
														data.homeUrl
													) }
											</span>
											{ g.supporting.length > 0 && ' · ' }
											{ g.supporting.length > 0 &&
												sprintf(
													/* translators: %s: number of pages. */
													__(
														'%s supporting pages',
														'zinn-seo'
													),
													count( g.supporting.length )
												) }
										</p>
									) }
									{ g.brief && (
										<div className="zseo-brief">
											<p>
												<strong>
													{ __(
														'Write next:',
														'zinn-seo'
													) }
												</strong>{ ' ' }
												{ g.brief.title }{ ' ' }
												<span className="zseo-muted">
													{ sprintf(
														/* translators: %s: impressions. */
														__(
															'(%s impressions waiting)',
															'zinn-seo'
														),
														count( g.brief.demand )
													) }
												</span>
											</p>
											{ g.brief.headings.length > 0 && (
												<>
													<p className="zseo-small">
														<strong>
															{ __(
																'Headings:',
																'zinn-seo'
															) }
														</strong>
													</p>
													<ul className="zseo-found-list">
														{ g.brief.headings.map(
															( h ) => (
																<li key={ h }>
																	{ h }
																</li>
															)
														) }
													</ul>
												</>
											) }
											{ g.brief.questions.length > 0 && (
												<>
													<p className="zseo-small">
														<strong>
															{ __(
																'Questions to answer:',
																'zinn-seo'
															) }
														</strong>
													</p>
													<ul className="zseo-found-list">
														{ g.brief.questions.map(
															( q ) => (
																<li key={ q }>
																	{ q }
																</li>
															)
														) }
													</ul>
												</>
											) }
											<div>
												<Button
													variant="secondary"
													disabled={
														act.busy === g.topic
													}
													onClick={ () =>
														act.run(
															g.topic,
															() =>
																seoFetch(
																	'/search/todo',
																	{
																		method: 'PUT',
																		data: {
																			kind: 'write',
																			page: g
																				.brief
																				.target,
																			note: g
																				.brief
																				.title,
																		},
																	}
																),
															__(
																'Added to your list.',
																'zinn-seo'
															)
														)
													}
												>
													{ __(
														'Add to my writing list',
														'zinn-seo'
													) }
												</Button>
											</div>
										</div>
									) }
								</CardBody>
							</Card>
						) )
					)
				}
			</Loaded>
			{ act.done && (
				<Notice status="success" isDismissible={ false }>
					{ act.done }
				</Notice>
			) }
			<Refusal error={ act.error } onRemove={ act.clear } />
		</div>
	);
}

/**
 * Changes and rankings: every SEO change marked on the clicks chart, with before and after.
 *
 * @param {Object}  props
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export function TimelineTab( { google, ready } ) {
	const [ days, setDays ] = useState( 90 );
	const [ page, setPage ] = useState( '' );
	const [ typed, setTyped ] = useState( '' );
	const load = useLoad(
		ready
			? `/search/timeline?days=${ days }&page=${ encodeURIComponent( page ) }`
			: null
	);
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	return (
		<div className="zseo-stack">
			<div className="zseo-row zseo-row--top">
				<Period
					days={ days }
					onChange={ setDays }
					choices={ [ 28, 90, 180, 365 ] }
				/>
				<form
					className="zseo-row"
					onSubmit={ ( e ) => {
						e.preventDefault();
						setPage( typed.trim() );
					} }
				>
					<TextControl
						label={ __(
							'One page (its address), or empty for the whole site',
							'zinn-seo'
						) }
						value={ typed }
						type="url"
						onChange={ setTyped }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button variant="secondary" type="submit">
						{ __( 'Show', 'zinn-seo' ) }
					</Button>
				</form>
			</div>
			<Loaded load={ load }>
				{ ( r ) => (
					<>
						{ ! r.recording && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Switch on SEO revisions (Settings → Features) so every change is recorded and shown here.',
									'zinn-seo'
								) }
							</Notice>
						) }
						<Chart
							days={ r.days }
							position
							label={ __(
								'Clicks and position, with SEO changes',
								'zinn-seo'
							) }
						/>
						{ r.changes.length === 0 ? (
							<p className="zseo-muted">
								{ __(
									'No SEO change was made in this period.',
									'zinn-seo'
								) }
							</p>
						) : (
							<Table
								label={ __( 'SEO changes', 'zinn-seo' ) }
								rowKey={ ( c, i ) =>
									`${ c.at }-${ c.post_id }-${ c.field }-${ i }`
								}
								rows={ [ ...r.changes ].reverse() }
								columns={ [
									{
										id: 'at',
										label: __( 'Day', 'zinn-seo' ),
										render: ( c ) => day( c.at ),
									},
									{
										id: 'page',
										label: __( 'Page', 'zinn-seo' ),
										render: ( c ) => c.title,
									},
									{
										id: 'f',
										label: __( 'Changed', 'zinn-seo' ),
										render: ( c ) => c.label,
									},
									{
										id: 'cl',
										numeric: true,
										label: __(
											'Clicks a day, before → after',
											'zinn-seo'
										),
										render: ( c ) =>
											c.effect.days_after > 0
												? `${ c.effect.before.clicks } → ${ c.effect.after.clicks }`
												: __(
														'too recent',
														'zinn-seo'
													),
									},
									{
										id: 'po',
										numeric: true,
										label: __(
											'Position, before → after',
											'zinn-seo'
										),
										render: ( c ) =>
											c.effect.days_after > 0
												? `${ position( c.effect.before.position ) } → ${ position( c.effect.after.position ) }`
												: '—',
									},
								] }
							/>
						) }
						<p className="zseo-small zseo-muted">
							{ __(
								'Before and after are the 14 days each side of the change, for the whole site or the page chosen above.',
								'zinn-seo'
							) }
						</p>
					</>
				) }
			</Loaded>
		</div>
	);
}
