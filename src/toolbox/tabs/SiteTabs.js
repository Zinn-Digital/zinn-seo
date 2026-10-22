/**
 * The tools about other websites and your brand: competitor traffic and top pages (zs-523), what a
 * site is built with (zs-528), and how people feel about a brand (zs-527).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { TextareaControl } from '@wordpress/components';

import { NewTab, Table } from '../../tools/parts';
import { count, price } from '../../tools/pure';
import { domainOf, lines, shares, unitsOf } from '../pure';
import {
	History,
	RunMoney,
	RunNow,
	RunState,
	Schedule,
	useRuns,
} from '../shared';

/**
 * Domains from a text box (bad lines are reported, never silently dropped).
 *
 * @param {string} text Text.
 * @return {{domains: Array<string>, bad: Array<string>}} Domains and what was not one.
 */
export function domainsFrom( text ) {
	const domains = [];
	const bad = [];
	lines( text, 10 ).forEach( ( line ) => {
		const host = domainOf( line );
		if ( ! host ) {
			bad.push( line );
		} else if ( ! domains.includes( host ) ) {
			domains.push( host );
		}
	} );
	return { domains, bad };
}

/**
 * The box for websites.
 *
 * @param {Object}   props
 * @param {string}   props.value    Text.
 * @param {Function} props.onChange Change.
 * @return {Element} Box.
 */
function Sites( { value, onChange } ) {
	return (
		<TextareaControl
			__nextHasNoMarginBottom
			label={ __( 'Websites', 'zinn-seo' ) }
			help={ __(
				'Up to 10, one per line, such as competitor.com.',
				'zinn-seo'
			) }
			value={ value }
			onChange={ onChange }
			rows={ 3 }
		/>
	);
}

/**
 * Why websites cannot be checked yet.
 *
 * @param {Object} parsed `domainsFrom()`.
 * @return {string} Words ('' when they can).
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
 * Competitor traffic.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function TrafficTab( { ctx } ) {
	const runs = useRuns( 'traffic' );
	const [ text, setText ] = useState( '' );
	const parsed = domainsFrom( text );
	const params = {
		domains: parsed.domains,
		location: ctx.market.location || '',
		language: ctx.market.language || '',
	};
	const units = unitsOf( 'traffic', params );
	const run = runs.run;
	const rows = ( run && run.result && run.result.domains ) || [];
	return (
		<div className="zseo-stack">
			<Sites value={ text } onChange={ setText } />
			<RunNow
				product="traffic"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'See their traffic', 'zinn-seo' ) }
				why={ sitesWhy( parsed ) }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.map( ( d ) => (
				<section key={ d.domain } className="zseo-stack">
					<h3 className="zseo-card-title">{ d.domain }</h3>
					{ ! d.answered ? (
						<p className="zseo-muted">
							{ __(
								'Not measured this time (refunded).',
								'zinn-seo'
							) }
						</p>
					) : (
						<>
							<p className="zseo-lead">
								{ sprintf(
									/* translators: 1: visits a month, 2: phrases, 3: money. */
									__(
										'About %1$s visits a month from Google · ranks for %2$s searches · worth %3$s a month in ads',
										'zinn-seo'
									),
									count( d.monthly_visits ),
									count( d.keywords ),
									null === d.value_minor
										? '—'
										: price(
												d.value_minor,
												d.currency || 'USD'
											)
								) }
							</p>
							<Table
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
												{ r.url.replace(
													/^https?:\/\/(www\.)?/,
													''
												) }
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
									{
										id: 'k',
										label: __(
											'Searches it ranks for',
											'zinn-seo'
										),
										numeric: true,
										render: ( r ) => count( r.keywords ),
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
 * What a website is built with.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function TechnologyTab( { ctx } ) {
	const runs = useRuns( 'technology' );
	const [ text, setText ] = useState( '' );
	const parsed = domainsFrom( text );
	const params = { domains: parsed.domains };
	const units = unitsOf( 'technology', params );
	const run = runs.run;
	const rows = ( run && run.result && run.result.domains ) || [];
	return (
		<div className="zseo-stack">
			<Sites value={ text } onChange={ setText } />
			<RunNow
				product="technology"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'See what they use', 'zinn-seo' ) }
				why={ sitesWhy( parsed ) }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.map( ( d ) => (
				<section key={ d.domain } className="zseo-stack">
					<h3 className="zseo-card-title">{ d.domain }</h3>
					{ ! d.answered && (
						<p className="zseo-muted">
							{ __(
								'Not looked up this time (refunded).',
								'zinn-seo'
							) }
						</p>
					) }
					{ d.answered && ! d.found && (
						<p className="zseo-muted">
							{ __(
								'Nothing is known about this website yet.',
								'zinn-seo'
							) }
						</p>
					) }
					{ d.answered && d.found && (
						<dl className="zseo-techlist">
							{ ( d.groups || [] ).map( ( g ) => (
								<div key={ g.category }>
									<dt>{ g.category.replace( /_/g, ' ' ) }</dt>
									<dd>{ g.names.join( ', ' ) }</dd>
								</div>
							) ) }
						</dl>
					) }
				</section>
			) ) }
			<RunMoney run={ run } />
		</div>
	);
}

/**
 * How people feel about a brand.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export function SentimentTab( { ctx } ) {
	const runs = useRuns( 'sentiment' );
	const [ text, setText ] = useState( ctx.siteName || '' );
	const params = { phrases: lines( text, 10 ) };
	const units = unitsOf( 'sentiment', params );
	const why = units ? '' : __( 'Add your brand name.', 'zinn-seo' );
	const run = runs.run;
	const rows = ( run && run.result && run.result.phrases ) || [];
	return (
		<div className="zseo-stack">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Your brand, products or people', 'zinn-seo' ) }
				help={ __( 'Up to 10, one per line.', 'zinn-seo' ) }
				value={ text }
				onChange={ setText }
				rows={ 3 }
			/>
			<RunNow
				product="sentiment"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'See how people feel', 'zinn-seo' ) }
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product="sentiment"
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Check it automatically', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.map( ( r ) => {
				const s = shares( r );
				return (
					<section key={ r.phrase } className="zseo-stack">
						<h3 className="zseo-card-title">{ r.phrase }</h3>
						{ ! r.answered && (
							<p className="zseo-muted">
								{ __(
									'Not measured this time (refunded).',
									'zinn-seo'
								) }
							</p>
						) }
						{ r.answered && ! r.found && (
							<p className="zseo-muted">
								{ __( 'No mentions were found.', 'zinn-seo' ) }
							</p>
						) }
						{ r.answered && r.found && (
							<>
								<p className="zseo-lead">
									{ sprintf(
										/* translators: 1: number of mentions, 2: positive %, 3: neutral %, 4: negative %. */
										__(
											'%1$s mentions · %2$s%% positive · %3$s%% neutral · %4$s%% negative',
											'zinn-seo'
										),
										count( r.total ),
										s.positive,
										s.neutral,
										s.negative
									) }
								</p>
								<div className="zseo-mood" aria-hidden="true">
									<span
										className="is-pos"
										style={ {
											inlineSize: `${ s.positive }%`,
										} }
									/>
									<span
										className="is-neu"
										style={ {
											inlineSize: `${ s.neutral }%`,
										} }
									/>
									<span
										className="is-neg"
										style={ {
											inlineSize: `${ s.negative }%`,
										} }
									/>
								</div>
								{ ( r.top_domains || [] ).length > 0 && (
									<p className="zseo-muted">
										{ __(
											'Talked about most on:',
											'zinn-seo'
										) }{ ' ' }
										{ r.top_domains
											.slice( 0, 5 )
											.map( ( d ) => d.domain )
											.join( ', ' ) }
									</p>
								) }
							</>
						) }
					</section>
				);
			} ) }
			<RunMoney run={ run } />
		</div>
	);
}
