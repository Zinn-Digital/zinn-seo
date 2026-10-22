/**
 * Pro: who competes with you in search, and what one of them ranks for that you do not — each
 * gap a brief you can start writing from.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Refusal, Table, useAction } from '../parts';
import { count } from '../pure';
import { Empty, RunBar, RunLine, useResults } from '../tool';
import { marketOptions } from '../markets';
import { MarketLine } from './KeywordsTab';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function GapTab( { data, ctx } ) {
	const state = ctx.tools.competitors;
	const results = useResults( 'competitors' );
	const action = useAction();
	const [ rival, setRival ] = useState( '' );
	const [ country, setCountry ] = useState( ctx.market.country );
	const market = ctx.markets[ country ] || {};
	const done = results.audits.filter( ( a ) => 'running' !== a.status );
	const summary = ( results.latest && results.latest.summary ) || {};
	const rivals = summary.competitors || [];
	// The newest run that drew a gap (a later run without a rival must not hide it).
	const withGap = done.find( ( a ) => a.summary && a.summary.gap );
	const gap = withGap ? withGap.summary.gap : null;
	const body = {
		tool: 'competitors',
		country,
		language: market.language || '',
	};
	const draft = ( row ) =>
		action
			.run(
				row.keyword,
				() =>
					seoFetch( '/tools/brief', {
						method: 'POST',
						data: { row, rival: gap.competitor },
					} ),
				''
			)
			.then( ( made ) => {
				if ( made && made.edit ) {
					window.location.assign( made.edit );
				}
			} );

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Win the searches your competitors win',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-gap" />
					</div>
					<p className="zseo-lead">
						{ __(
							'See who you are really up against in search, then pick one and get the list of phrases they rank for and you do not — each one a page you could write, with a brief to start from.',
							'zinn-seo'
						) }
					</p>
					<div className="zseo-row">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Searches in', 'zinn-seo' ) }
							value={ country }
							options={ marketOptions( ctx.markets ) }
							onChange={ setCountry }
						/>
					</div>
					<RunBar
						tool="competitors"
						state={ state }
						units={ 1 }
						ctx={ ctx }
						path="/tools/research"
						body={ body }
						label={ __( 'Find my competitors', 'zinn-seo' ) }
						running={ results.running }
						onStart={ results.watch }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'Who competes with you', 'zinn-seo' ) }
					</h2>
					<Refusal error={ results.error } buyUrl={ ctx.buyUrl } />
					<RunLine run={ results.latest } />
					<MarketLine summary={ summary } markets={ ctx.markets } />
					{ rivals.length > 0 ? (
						<Table
							label={ __( 'Competitors', 'zinn-seo' ) }
							rowKey={ ( r ) => r.domain }
							rows={ rivals.slice( 0, 20 ) }
							columns={ [
								{
									id: 'domain',
									label: __( 'Site', 'zinn-seo' ),
									render: ( r ) => (
										<strong dir="ltr">{ r.domain }</strong>
									),
								},
								{
									id: 'common',
									label: __(
										'Phrases you both rank for',
										'zinn-seo'
									),
									numeric: true,
									render: ( r ) => count( r.common_keywords ),
								},
								{
									id: 'total',
									label: __(
										'Phrases they rank for',
										'zinn-seo'
									),
									numeric: true,
									render: ( r ) => count( r.total_keywords ),
								},
								{
									id: 'traffic',
									label: __(
										'Visits from search a month',
										'zinn-seo'
									),
									numeric: true,
									render: ( r ) => count( r.organic_traffic ),
								},
								{
									id: 'do',
									label: __( 'Action', 'zinn-seo' ),
									render: ( r ) => (
										<Button
											variant="link"
											onClick={ () =>
												setRival( r.domain )
											}
										>
											{ __(
												'Compare with this site',
												'zinn-seo'
											) }
										</Button>
									),
								},
							] }
						/>
					) : (
						! results.loading && (
							<Empty>
								{ results.latest
									? __(
											'Google does not show enough of your pages yet for us to tell who you compete with. That is normal for a new site — type a competitor’s site below to compare with one you already know.',
											'zinn-seo'
										)
									: __(
											'No report yet. Run it once and your real search competitors are listed here.',
											'zinn-seo'
										) }
							</Empty>
						)
					) }
				</CardBody>
			</Card>
			<Card className="zseo-gap">
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __(
							'What a competitor ranks for and you do not',
							'zinn-seo'
						) }
					</h2>
					<div className="zseo-row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Competitor’s site', 'zinn-seo' ) }
							placeholder="example.com"
							className="zseo-address"
							value={ rival }
							onChange={ setRival }
						/>
					</div>
					<RunBar
						tool="competitors"
						state={ state }
						units={ 1 }
						ctx={ ctx }
						path="/tools/research"
						body={ { ...body, competitor: rival } }
						label={ __( 'Show me the gap', 'zinn-seo' ) }
						disabled={ ! /^[^\s/]+\.[^\s/]+$/.test( rival.trim() ) }
						why={ __(
							'Type a competitor’s site, or pick one from the list above.',
							'zinn-seo'
						) }
						running={ results.running }
						onStart={ results.watch }
					/>
					<Refusal
						error={ action.error }
						buyUrl={ ctx.buyUrl }
						onRemove={ action.clear }
					/>
					{ gap && false === gap.compared && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The comparison could not be made this time, so no gap is shown — that is not the same as “no gap”. Try again.',
								'zinn-seo'
							) }
						</Notice>
					) }
					{ gap && gap.compared && (
						<>
							<p className="zseo-muted">
								{ gap.own_complete
									? sprintf(
											/* translators: 1: a competitor's site, 2: number of phrases. */
											__(
												'%1$s ranks for these %2$s phrases and you do not.',
												'zinn-seo'
											),
											gap.competitor,
											count( gap.keywords.length )
										)
									: sprintf(
											/* translators: 1: a competitor's site, 2: number of phrases, 3: number of phrases. */
											__(
												'%1$s ranks for these %2$s phrases, and they are not among your %3$s strongest. Your site ranks for more phrases than one report reads, so check a phrase before writing for it.',
												'zinn-seo'
											),
											gap.competitor,
											count( gap.keywords.length ),
											count( gap.own_checked )
										) }
							</p>
							{ gap.keywords.length > 0 ? (
								<Table
									label={ __( 'Content gap', 'zinn-seo' ) }
									rowKey={ ( r ) => r.keyword }
									rows={ gap.keywords }
									columns={ [
										{
											id: 'phrase',
											label: __( 'Phrase', 'zinn-seo' ),
											render: ( r ) => (
												<strong>{ r.keyword }</strong>
											),
										},
										{
											id: 'volume',
											label: __(
												'Searches a month',
												'zinn-seo'
											),
											numeric: true,
											render: ( r ) =>
												count( r.search_volume ),
										},
										{
											id: 'their',
											label: __(
												'Their position',
												'zinn-seo'
											),
											numeric: true,
											render: ( r ) =>
												count( r.position ),
										},
										{
											id: 'page',
											label: __(
												'Their page',
												'zinn-seo'
											),
											render: ( r ) =>
												r.url ? (
													<a
														href={ r.url }
														className="zseo-url"
														dir="ltr"
														target="_blank"
														rel="noopener noreferrer nofollow"
													>
														{ r.url.replace(
															/^https?:\/\//,
															''
														) }
													</a>
												) : (
													'—'
												),
										},
										{
											id: 'do',
											label: __( 'Action', 'zinn-seo' ),
											render: ( r ) => (
												<Button
													variant="link"
													disabled={ !! action.busy }
													isBusy={
														action.busy ===
														r.keyword
													}
													onClick={ () => draft( r ) }
												>
													{ __(
														'Start a draft from this',
														'zinn-seo'
													) }
												</Button>
											),
										},
									] }
								/>
							) : (
								<Empty>
									{ __(
										'They rank for nothing you do not — you are level on the phrases that were read.',
										'zinn-seo'
									) }
								</Empty>
							) }
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
