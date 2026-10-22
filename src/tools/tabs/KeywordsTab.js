/**
 * Keyword research: the phrases worth writing for — how often they are searched, how hard page
 * one is, what a click is worth — around a phrase, a page, or the whole site.
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
import { count, day, price } from '../pure';
import { Empty, RunBar, RunLine, useResults } from '../tool';
import { countryName, marketOptions } from '../markets';

/**
 * A list of phrases with their numbers, and what to do with each.
 *
 * @param {Object}   props
 * @param {Array}    props.ideas   `{ keyword, search_volume, difficulty, cpc_minor }`.
 * @param {Function} props.onTrack Track a phrase's ranking (or null).
 * @param {Function} props.onUse   Use a phrase (the editor) (or null).
 * @param {string}   props.busy    The phrase being sent.
 * @return {Element} Table.
 */
export function IdeasTable( { ideas, onTrack, onUse, busy } ) {
	const hard = ( d ) => {
		if ( null === d || undefined === d ) {
			return '—';
		}
		if ( d <= 30 ) {
			return (
				<span className="zseo-ok">
					{ sprintf(
						/* translators: %s: a difficulty out of 100. */
						__( 'Within reach (%s)', 'zinn-seo' ),
						count( d )
					) }
				</span>
			);
		}
		if ( d <= 60 ) {
			return sprintf(
				/* translators: %s: a difficulty out of 100. */
				__( 'Takes work (%s)', 'zinn-seo' ),
				count( d )
			);
		}
		return sprintf(
			/* translators: %s: a difficulty out of 100. */
			__( 'Very hard (%s)', 'zinn-seo' ),
			count( d )
		);
	};
	return (
		<Table
			label={ __( 'Phrases worth writing for', 'zinn-seo' ) }
			rowKey={ ( r ) => r.keyword }
			rows={ ideas }
			columns={ [
				{
					id: 'phrase',
					label: __( 'Phrase', 'zinn-seo' ),
					render: ( r ) => <strong>{ r.keyword }</strong>,
				},
				{
					id: 'volume',
					label: __( 'Searches a month', 'zinn-seo' ),
					numeric: true,
					render: ( r ) => count( r.search_volume ),
				},
				{
					id: 'difficulty',
					label: __( 'Getting on page one', 'zinn-seo' ),
					render: ( r ) => hard( r.difficulty ),
				},
				{
					id: 'cpc',
					label: __( 'Advertisers pay per click', 'zinn-seo' ),
					numeric: true,
					render: ( r ) =>
						null === r.cpc_minor || undefined === r.cpc_minor
							? '—'
							: price( r.cpc_minor ),
				},
				...( onTrack || onUse
					? [
							{
								id: 'do',
								label: __( 'Action', 'zinn-seo' ),
								render: ( r ) => (
									<span className="zseo-inline">
										{ onUse && (
											<Button
												variant="link"
												onClick={ () => onUse( r ) }
											>
												{ __(
													'Use as focus phrase',
													'zinn-seo'
												) }
											</Button>
										) }
										{ onTrack && (
											<Button
												variant="link"
												disabled={ !! busy }
												isBusy={ busy === r.keyword }
												onClick={ () => onTrack( r ) }
											>
												{ __(
													'Track its ranking',
													'zinn-seo'
												) }
											</Button>
										) }
									</span>
								),
							},
						]
					: [] ),
			] }
		/>
	);
}

/**
 * The market a list was measured in, said plainly — including when the one asked for could not
 * be used.
 *
 * @param {Object} props
 * @param {Object} props.summary A run's summary.
 * @param {Object} props.markets Country code => `{ location, language }`.
 * @return {Element|null} Line.
 */
export function MarketLine( { summary, markets } ) {
	const market = summary && summary.market;
	if ( ! market ) {
		return null;
	}
	if ( false === market.resolved ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: a country name. */
					__(
						'These numbers are for searches in the United States: %s is not a market we can measure yet.',
						'zinn-seo'
					),
					market.location
				) }
			</Notice>
		);
	}
	return (
		<p className="zseo-muted">
			{ sprintf(
				/* translators: 1: a country, 2: a language code. */
				__( 'Searches in %1$s, in %2$s.', 'zinn-seo' ),
				countryName( markets, market.location ) || market.location,
				String( market.language || '' ).toUpperCase()
			) }
		</p>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function KeywordsTab( { data, ctx } ) {
	const state = ctx.tools.keywords;
	const results = useResults( 'keywords' );
	const track = useAction();
	const pro = !! data.toolsPro;
	const [ seed, setSeed ] = useState( '' );
	const [ country, setCountry ] = useState( ctx.market.country );
	const [ language, setLanguage ] = useState( ctx.market.language );
	const [ shown, setShown ] = useState( '' );
	const done = results.audits.filter( ( a ) => 'running' !== a.status );
	const run = done.find( ( a ) => a.id === shown ) || results.latest;
	const summary = ( run && run.summary ) || {};
	const ideas = summary.ideas || [];
	const tracked = new Set(
		( ctx.target.keywords || [] ).map( ( k ) => k.phrase.toLowerCase() )
	);
	const body = pro ? { tool: 'keywords', seed, country, language } : { seed };
	const onTrack = ! ctx.canSpend
		? null
		: ( row ) => {
				const market = summary.market || {};
				track
					.run(
						row.keyword,
						() =>
							seoFetch( '/tools/rank/list', {
								method: 'PUT',
								data: {
									items: [
										...( ctx.target.keywords || [] ),
										{
											phrase: row.keyword,
											location: market.location || '',
											language: market.language || '',
											device: '',
										},
									],
								},
							} ),
						sprintf(
							/* translators: %s: a search phrase. */
							__(
								'“%s” is now tracked. See it under Rankings.',
								'zinn-seo'
							),
							row.keyword
						)
					)
					.then( ( saved ) => saved && ctx.refresh() );
			};

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Find the phrases worth writing for',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-keywords" />
					</div>
					<p className="zseo-lead">
						{ __(
							'Type a topic and see what people actually search, how many of them, and how hard it is to reach page one — so you write the page that can win, not the one that cannot.',
							'zinn-seo'
						) }
					</p>
					<div className="zseo-row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'A topic or phrase', 'zinn-seo' ) }
							help={ __(
								'Leave empty for the phrases your whole site is already relevant for.',
								'zinn-seo'
							) }
							value={ seed }
							onChange={ setSeed }
						/>
						{ pro && (
							<>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Searches in', 'zinn-seo' ) }
									value={ country }
									options={ marketOptions( ctx.markets ) }
									onChange={ ( c ) => {
										setCountry( c );
										setLanguage(
											( ctx.markets[ c ] || {} )
												.language || 'en'
										);
									} }
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Language code', 'zinn-seo' ) }
									help={ __(
										'Two letters, e.g. de for German.',
										'zinn-seo'
									) }
									value={ language }
									onChange={ ( v ) =>
										setLanguage(
											v
												.toLowerCase()
												.replace( /[^a-z]/g, '' )
												.slice( 0, 3 )
										)
									}
								/>
							</>
						) }
					</div>
					{ ! pro && (
						<p className="zseo-muted zseo-small">
							{ __(
								'Numbers are for searches in the United States, in English. Pro researches each country and language you sell in — and brings research into the editor.',
								'zinn-seo'
							) }
						</p>
					) }
					<RunBar
						tool="keywords"
						state={ state }
						units={ 1 }
						ctx={ ctx }
						path={ pro ? '/tools/research' : undefined }
						body={ body }
						label={ __( 'Find phrases', 'zinn-seo' ) }
						running={ results.running }
						onStart={ () => {
							setShown( '' );
							results.watch();
						} }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ summary.seed
							? sprintf(
									/* translators: %s: a phrase or an address. */
									__( 'Phrases around “%s”', 'zinn-seo' ),
									summary.seed
								)
							: __( 'Phrases found', 'zinn-seo' ) }
					</h2>
					<Refusal
						error={ results.error || track.error }
						buyUrl={ ctx.buyUrl }
						onRemove={ track.clear }
					/>
					{ track.done && (
						<Notice status="success" onRemove={ track.clear }>
							{ track.done }
						</Notice>
					) }
					{ done.length > 1 && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Earlier lists', 'zinn-seo' ) }
							value={ run ? run.id : '' }
							options={ done.map( ( a ) => ( {
								value: a.id,
								label: `${
									( a.summary && a.summary.seed ) || '—'
								} · ${ day( a.finished_at || a.started_at ) }`,
							} ) ) }
							onChange={ setShown }
						/>
					) }
					<RunLine run={ run } />
					<MarketLine summary={ summary } markets={ ctx.markets } />
					{ ideas.length > 0 ? (
						<IdeasTable
							ideas={ ideas.map( ( i ) => i ) }
							busy={ track.busy }
							onTrack={
								onTrack
									? ( row ) =>
											! tracked.has(
												row.keyword.toLowerCase()
											) && onTrack( row )
									: null
							}
						/>
					) : (
						! results.loading && (
							<Empty>
								{ run
									? __(
											'Nothing came back for that. Try a broader topic.',
											'zinn-seo'
										)
									: __(
											'No list yet. Type a topic above and your first list appears here.',
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
