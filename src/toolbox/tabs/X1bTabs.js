/**
 * Three research tools on Zinn® credits (stage X1B): any domain's overview, saved as reports
 * (zs-653); your share of search visibility against rivals, over time (zs-654); and Google
 * Trends — interest over time, seasonality and rising searches (zs-709).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { SelectControl, TextareaControl } from '@wordpress/components';

import { NewTab, Table } from '../../tools/parts';
import { count, day, price } from '../../tools/pure';
import { lines, unitsOf } from '../pure';
import {
	History,
	RunMoney,
	RunNow,
	RunState,
	Schedule,
	useRuns,
} from '../shared';
import { domainsFrom } from './SiteTabs';
import { monthName, sparkPoints, visibilitySeries } from './x1bPure';

/**
 * A table with its name shown above it as a heading (the name is also the table's label).
 *
 * @param {Object} props Table props (`label`, `columns`, `rows`, `rowKey`).
 * @return {Element} Table.
 */
function TitledTable( props ) {
	return (
		<>
			<h3 className="zseo-card-title">{ props.label }</h3>
			<Table { ...props } />
		</>
	);
}

const short = ( url ) =>
	String( url || '' ).replace( /^https?:\/\/(www\.)?/, '' );

/**
 * Why websites cannot be checked yet ('' when they can).
 *
 * @param {Object} parsed `domainsFrom()`.
 * @return {string} Words.
 */
function sitesWhy( parsed ) {
	if ( parsed.bad.length ) {
		return sprintf(
			/* translators: %s: what was typed. */
			__( '“%s” is not a website address.', 'zinn-seo' ),
			parsed.bad[ 0 ]
		);
	}
	return parsed.domains.length
		? ''
		: __( 'Add at least one website.', 'zinn-seo' );
}

/**
 * Domain overview (zs-653): traffic, searches, top pages and links of any website. Every run is
 * kept, so past reports stay one click away.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function DomainOverviewTab( { ctx } ) {
	const runs = useRuns( 'domain_overview' );
	const [ text, setText ] = useState( '' );
	const parsed = domainsFrom( text );
	const params = {
		domains: parsed.domains,
		location: ctx.market.location || '',
		language: ctx.market.language || '',
	};
	const run = runs.run;
	const rows = ( run && run.result && run.result.domains ) || [];
	return (
		<div className="zseo-stack">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Websites', 'zinn-seo' ) }
				help={ __(
					'Up to 10, one per line, such as competitor.com.',
					'zinn-seo'
				) }
				value={ text }
				onChange={ setText }
				rows={ 3 }
			/>
			<RunNow
				product="domain_overview"
				params={ params }
				units={ unitsOf( 'domain_overview', params ) }
				ctx={ ctx }
				label={ __( 'Get the report', 'zinn-seo' ) }
				why={ sitesWhy( parsed ) }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.map( ( d ) => (
				<section key={ d.domain } className="zseo-stack">
					<h3 className="zseo-card-title">
						<bdi>{ d.domain }</bdi>
					</h3>
					{ ! d.answered ? (
						<p className="zseo-muted">
							{ __(
								'Not measured this time (refunded).',
								'zinn-seo'
							) }
						</p>
					) : (
						<>
							<ul className="zseo-facts">
								<li>
									{ sprintf(
										/* translators: %s: a number of visits. */
										__(
											'About %s visits a month from Google',
											'zinn-seo'
										),
										count( d.monthly_visits )
									) }
								</li>
								<li>
									{ sprintf(
										/* translators: 1: searches, 2: in the top three, 3: on page one. */
										__(
											'Ranks for %1$s searches (%2$s in the top three, %3$s on page one)',
											'zinn-seo'
										),
										count( d.keywords ),
										count( d.top3 ),
										count( d.top10 )
									) }
								</li>
								<li>
									{ sprintf(
										/* translators: %s: money. */
										__(
											'Worth %s a month in ads',
											'zinn-seo'
										),
										null === d.traffic_value_minor ||
											undefined === d.traffic_value_minor
											? '—'
											: price(
													d.traffic_value_minor,
													d.currency || 'USD'
												)
									) }
								</li>
								<li>
									{ d.backlinks
										? sprintf(
												/* translators: 1: linking websites, 2: links, 3: authority 0-1000. */
												__(
													'%1$s websites link to it with %2$s links · authority %3$s',
													'zinn-seo'
												),
												count(
													d.backlinks
														.referring_domains
												),
												count( d.backlinks.backlinks ),
												count( d.backlinks.rank )
											)
										: __(
												'Links: not measured this time.',
												'zinn-seo'
											) }
								</li>
							</ul>
							<TitledTable
								label={ __(
									'Searches it ranks best for',
									'zinn-seo'
								) }
								rowKey={ ( r ) => r.keyword }
								rows={ ( d.top_keywords || [] ).slice( 0, 30 ) }
								columns={ [
									{
										id: 'k',
										label: __( 'Search', 'zinn-seo' ),
										render: ( r ) => (
											<bdi>{ r.keyword }</bdi>
										),
									},
									{
										id: 'p',
										label: __( 'Position', 'zinn-seo' ),
										numeric: true,
										render: ( r ) => count( r.position ),
									},
									{
										id: 'v',
										label: __(
											'Searches a month',
											'zinn-seo'
										),
										numeric: true,
										render: ( r ) => count( r.volume ),
									},
								] }
							/>
							<TitledTable
								label={ __(
									'Pages that bring the most visits',
									'zinn-seo'
								) }
								rowKey={ ( r ) => r.url }
								rows={ ( d.top_pages || [] ).slice( 0, 20 ) }
								columns={ [
									{
										id: 'u',
										label: __( 'Page', 'zinn-seo' ),
										render: ( r ) => (
											<NewTab href={ r.url }>
												{ short( r.url ) }
											</NewTab>
										),
									},
									{
										id: 'v',
										label: __(
											'Visits a month',
											'zinn-seo'
										),
										numeric: true,
										render: ( r ) => count( r.visits ),
									},
								] }
							/>
						</>
					) }
				</section>
			) ) }
			<RunMoney run={ run } />
		</div>
	);
}

/**
 * Share of search visibility (zs-654): how much of the clicks on your searches go to you and to
 * each rival — measured once, or on a schedule to watch it move.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function VisibilityTab( { ctx } ) {
	const runs = useRuns( 'visibility' );
	const [ text, setText ] = useState( '' );
	const [ rivalText, setRivalText ] = useState( '' );
	const phrases = lines( text, 100 );
	const rivals = domainsFrom( rivalText );
	const params = {
		phrases,
		rivals: rivals.domains,
		location: ctx.market.location || '',
		language: ctx.market.language || '',
	};
	let why = '';
	if ( ! phrases.length ) {
		why = __( 'Add at least one search.', 'zinn-seo' );
	} else if ( rivals.bad.length ) {
		why = sitesWhy( rivals );
	}
	const units = unitsOf( 'visibility', params );
	const run = runs.run;
	const result = ( run && run.result ) || {};
	const series = visibilitySeries( runs.runs );
	return (
		<div className="zseo-stack">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Searches', 'zinn-seo' ) }
				help={ __(
					'Up to 100, one per line: the searches your customers make.',
					'zinn-seo'
				) }
				value={ text }
				onChange={ setText }
				rows={ 4 }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Rivals (optional)', 'zinn-seo' ) }
				help={ __( 'Up to 10 websites, one per line.', 'zinn-seo' ) }
				value={ rivalText }
				onChange={ setRivalText }
				rows={ 3 }
			/>
			<RunNow
				product="visibility"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Measure visibility', 'zinn-seo' ) }
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product="visibility"
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Measure it on a schedule', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ ( result.domains || [] ).length > 0 && (
				<TitledTable
					label={ __( 'Share of search visibility', 'zinn-seo' ) }
					rowKey={ ( r ) => r.domain }
					rows={ result.domains }
					columns={ [
						{
							id: 'd',
							label: __( 'Website', 'zinn-seo' ),
							render: ( r ) => (
								<bdi>
									{ r.own
										? sprintf(
												/* translators: %s: a website. */
												__( '%s (you)', 'zinn-seo' ),
												r.domain
											)
										: r.domain }
								</bdi>
							),
						},
						{
							id: 'v',
							label: __( 'Visibility', 'zinn-seo' ),
							numeric: true,
							render: ( r ) => `${ r.visibility }%`,
						},
					] }
				/>
			) }
			{ series.length > 1 && (
				<TitledTable
					label={ __( 'Your visibility over time', 'zinn-seo' ) }
					rowKey={ ( r ) => r.at }
					rows={ series }
					columns={ [
						{
							id: 'a',
							label: __( 'Measured', 'zinn-seo' ),
							render: ( r ) => day( r.at ),
						},
						{
							id: 'v',
							label: __( 'Visibility', 'zinn-seo' ),
							numeric: true,
							render: ( r ) =>
								null === r.visibility
									? '—'
									: `${ r.visibility }%`,
						},
					] }
				/>
			) }
			{ ( result.leaders || [] ).length > 0 && (
				<TitledTable
					label={ __( 'Who wins these searches', 'zinn-seo' ) }
					rowKey={ ( r ) => r.domain }
					rows={ result.leaders }
					columns={ [
						{
							id: 'd',
							label: __( 'Website', 'zinn-seo' ),
							render: ( r ) => <bdi>{ r.domain }</bdi>,
						},
						{
							id: 'v',
							label: __( 'Visibility', 'zinn-seo' ),
							numeric: true,
							render: ( r ) => `${ r.visibility }%`,
						},
					] }
				/>
			) }
			{ ( result.pages || [] ).length > 0 && (
				<TitledTable
					label={ __( 'Your pages that earn it', 'zinn-seo' ) }
					rowKey={ ( r ) => r.url }
					rows={ result.pages.slice( 0, 30 ) }
					columns={ [
						{
							id: 'u',
							label: __( 'Page', 'zinn-seo' ),
							render: ( r ) => (
								<NewTab href={ r.url }>
									{ short( r.url ) }
								</NewTab>
							),
						},
						{
							id: 'v',
							label: __( 'Visibility', 'zinn-seo' ),
							numeric: true,
							render: ( r ) => `${ r.visibility }%`,
						},
					] }
				/>
			) }
			<RunMoney run={ run } />
		</div>
	);
}

/**
 * Interest over time as a small line.
 *
 * @param {Object} props
 * @param {Array}  props.points `[{date, value|null}]`.
 * @param {string} props.label  Accessible name.
 * @return {Element|null} Chart.
 */
function Spark( { points, label } ) {
	const runsOf = sparkPoints( points, 600, 120 );
	if ( ! runsOf.length ) {
		return null;
	}
	return (
		<svg
			className="zseo-chart__svg"
			viewBox="0 0 600 120"
			preserveAspectRatio="none"
			role="img"
			aria-label={ label }
		>
			{ runsOf.map( ( pts, i ) => (
				<polyline
					key={ i }
					points={ pts }
					className="zseo-chart__clicks"
					vectorEffect="non-scaling-stroke"
				/>
			) ) }
		</svg>
	);
}

/**
 * Google Trends (zs-709): how interest in a search moves through the year, and what people
 * search for alongside it.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function TrendsTab( { ctx } ) {
	const runs = useRuns( 'trends' );
	const asked = new URLSearchParams(
		( window.location.hash || '' ).split( '?' )[ 1 ] || ''
	).get( 'phrase' );
	const [ text, setText ] = useState( asked || '' );
	const [ range, setRange ] = useState( 'past_12_months' );
	const phrases = lines( text, 5 );
	const params = {
		phrases,
		time_range: range,
		location: ctx.market.location || '',
		language: ctx.market.language || '',
	};
	const run = runs.run;
	const rows = ( run && run.result && run.result.phrases ) || [];
	return (
		<div className="zseo-stack">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Searches to compare', 'zinn-seo' ) }
				help={ __(
					'Up to 5, one per line. They are compared on one scale.',
					'zinn-seo'
				) }
				value={ text }
				onChange={ setText }
				rows={ 3 }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Period', 'zinn-seo' ) }
				value={ range }
				onChange={ setRange }
				options={ [
					{
						value: 'past_90_days',
						label: __( 'The last 90 days', 'zinn-seo' ),
					},
					{
						value: 'past_12_months',
						label: __( 'The last 12 months', 'zinn-seo' ),
					},
					{
						value: 'past_5_years',
						label: __( 'The last 5 years', 'zinn-seo' ),
					},
				] }
			/>
			<RunNow
				product="trends"
				params={ params }
				units={ unitsOf( 'trends', params ) }
				ctx={ ctx }
				label={ __( 'See the trend', 'zinn-seo' ) }
				why={
					phrases.length
						? ''
						: __( 'Add at least one search.', 'zinn-seo' )
				}
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.map( ( p ) => (
				<section key={ p.phrase } className="zseo-stack">
					<h3 className="zseo-card-title">
						<bdi>{ p.phrase }</bdi>
					</h3>
					<p className="zseo-lead">
						{ null === p.average || undefined === p.average
							? __(
									'Google has too little data for this search.',
									'zinn-seo'
								)
							: sprintf(
									/* translators: %s: interest on a 0-100 scale. */
									__(
										'Average interest %s out of 100',
										'zinn-seo'
									),
									p.average
								) }
					</p>
					<Spark
						points={ p.points }
						label={ sprintf(
							/* translators: %s: a search. */
							__( 'Interest over time in “%s”', 'zinn-seo' ),
							p.phrase
						) }
					/>
					{ ( p.seasonality || [] ).length > 0 && (
						<TitledTable
							label={ __( 'Busiest months', 'zinn-seo' ) }
							rowKey={ ( r ) => r.month }
							rows={ [ ...p.seasonality ].sort(
								( a, b ) => b.value - a.value
							) }
							columns={ [
								{
									id: 'm',
									label: __( 'Month', 'zinn-seo' ),
									render: ( r ) => monthName( r.month ),
								},
								{
									id: 'v',
									label: __( 'Interest', 'zinn-seo' ),
									numeric: true,
									render: ( r ) => r.value,
								},
							] }
						/>
					) }
					{ ( p.rising || [] ).length > 0 && (
						<TitledTable
							label={ __( 'Rising searches', 'zinn-seo' ) }
							rowKey={ ( r ) => r.query }
							rows={ p.rising }
							columns={ [
								{
									id: 'q',
									label: __( 'Search', 'zinn-seo' ),
									render: ( r ) => <bdi>{ r.query }</bdi>,
								},
								{
									id: 'v',
									label: __( 'Growth', 'zinn-seo' ),
									numeric: true,
									render: ( r ) =>
										sprintf(
											/* translators: %s: a percentage. */
											__( '+%s%%', 'zinn-seo' ),
											count( r.value )
										),
								},
							] }
						/>
					) }
					{ ( p.top || [] ).length > 0 && (
						<p className="zseo-muted">
							{ sprintf(
								/* translators: %s: a list of searches. */
								__( 'Also searched: %s', 'zinn-seo' ),
								p.top
									.slice( 0, 10 )
									.map( ( r ) => r.query )
									.join( ', ' )
							) }
						</p>
					) }
				</section>
			) ) }
			<RunMoney run={ run } />
		</div>
	);
}
