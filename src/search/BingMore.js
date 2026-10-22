/**
 * More from the site's own Bing Webmaster Tools account (zs-743): the problems Bing found while
 * crawling, how much Bing crawls, which pages have links, Bing keyword research — and, in Pro,
 * every problem and link and Bing's crawl rate by hour.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, RangeControl, TextControl } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import ProTeaser from '../admin/ProTeaser';
import { Refusal, Table, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';
import { bingIssueName } from './bingPure';

/**
 * Bing keyword research: weekly impressions for one search.
 *
 * @return {Element} Section.
 */
function Keyword() {
	const [ q, setQ ] = useState( '' );
	const [ asked, setAsked ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const lang = ( document.documentElement.lang || 'en-US' ).replace(
		'_',
		'-'
	);
	const country = ( lang.split( '-' )[ 1 ] || 'us' ).toLowerCase();
	const look = () => {
		setBusy( true );
		setError( null );
		seoFetch(
			`/search/bing/keyword?q=${ encodeURIComponent(
				q
			) }&country=${ country }&language=${ encodeURIComponent( lang ) }`
		)
			.then( setAsked )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const weeks = ( asked && asked.weeks ) || [];
	const total = weeks.reduce( ( n, w ) => n + w.impressions, 0 );
	return (
		<section className="zseo-stack">
			<h3>{ __( 'Keyword research on Bing', 'zinn-seo' ) }</h3>
			<div className="zseo-row">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Search', 'zinn-seo' ) }
					value={ q }
					onChange={ setQ }
				/>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || ! q.trim() }
					onClick={ look }
				>
					{ __( 'Look it up', 'zinn-seo' ) }
				</Button>
			</div>
			<Refusal error={ error } onRemove={ () => setError( null ) } />
			{ asked && (
				<p className="zseo-lead">
					{ weeks.length
						? sprintf(
								/* translators: 1: a search, 2: impressions, 3: weeks. */
								__(
									'“%1$s” was seen %2$s times on Bing over the last %3$s weeks.',
									'zinn-seo'
								),
								asked.phrase,
								count( total ),
								count( weeks.length )
							)
						: __(
								'Bing has no figures for that search yet.',
								'zinn-seo'
							) }
				</p>
			) }
		</section>
	);
}

/**
 * Bing's crawl rate by hour (Pro).
 *
 * @param {Object} props
 * @param {Object} props.settings `{rate: number[24], boost, can_boost}`.
 * @return {Element} Section.
 */
function CrawlRate( { settings } ) {
	const [ rate, setRate ] = useState( settings.rate );
	const [ busy, setBusy ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( null );
	const all = ( v ) => {
		setRate( rate.map( () => v ) );
		setSaved( false );
	};
	const save = () => {
		setBusy( true );
		setError( null );
		seoFetch( '/search/bing/crawl-settings', {
			method: 'POST',
			data: { rate, boost: !! settings.boost },
		} )
			.then( () => setSaved( true ) )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const average = Math.round(
		rate.reduce( ( n, v ) => n + v, 0 ) / Math.max( 1, rate.length )
	);
	return (
		<section className="zseo-stack">
			<h3>{ __( 'How fast Bing crawls your site', 'zinn-seo' ) }</h3>
			<p className="zseo-muted">
				{ __(
					'Slow Bing down at your busiest hours, or let it crawl faster when your server is quiet. 1 is slowest, 10 fastest.',
					'zinn-seo'
				) }
			</p>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Every hour', 'zinn-seo' ) }
				value={ average }
				min={ 1 }
				max={ 10 }
				onChange={ ( v ) => all( Number( v ) ) }
			/>
			<p>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ save }
				>
					{ saved
						? __( 'Saved in Bing', 'zinn-seo' )
						: __( 'Save in Bing', 'zinn-seo' ) }
				</Button>
			</p>
			<Refusal error={ error } onRemove={ () => setError( null ) } />
		</section>
	);
}

/**
 * The extra Bing sections.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element|null} Sections.
 */
export default function BingMore( { data } ) {
	const load = useLoad( '/search/bing/data' );
	if ( ! load.data ) {
		return null;
	}
	const b = load.data;
	const crawl = b.crawl || [];
	const last = crawl.length ? crawl[ crawl.length - 1 ] : null;
	return (
		<div className="zseo-stack zseo-bing-more">
			<section className="zseo-stack">
				<h3>{ __( 'Problems Bing found', 'zinn-seo' ) }</h3>
				<p className="zseo-lead">
					{ b.issues_total
						? sprintf(
								/* translators: %s: a number of pages. */
								__(
									'%s pages have a problem Bing reported.',
									'zinn-seo'
								),
								count( b.issues_total )
							)
						: __(
								'Bing reports no crawl problems on this site.',
								'zinn-seo'
							) }
				</p>
				{ ( b.issues || [] ).length > 0 && (
					<Table
						label={ __( 'Pages with problems', 'zinn-seo' ) }
						rowKey={ ( r ) => r.url }
						rows={ b.issues }
						columns={ [
							{
								id: 'u',
								label: __( 'Page', 'zinn-seo' ),
								render: ( r ) => (
									<bdi className="zseo-break">{ r.url }</bdi>
								),
							},
							{
								id: 'i',
								label: __( 'Problem', 'zinn-seo' ),
								render: ( r ) =>
									r.issues.map( bingIssueName ).join( ', ' ),
							},
						] }
					/>
				) }
				{ ! b.pro && b.issues_total > ( b.issues || [] ).length && (
					<ProTeaser
						title={ __( 'See every problem', 'zinn-seo' ) }
						points={ [
							__(
								'Every page Bing had trouble with, and every link Bing knows about.',
								'zinn-seo'
							),
						] }
					/>
				) }
			</section>
			{ last && (
				<section className="zseo-stack">
					<h3>{ __( 'Bing’s crawling', 'zinn-seo' ) }</h3>
					<p>
						{ sprintf(
							/* translators: 1: date, 2: pages crawled, 3: pages in Bing, 4: errors. */
							__(
								'On %1$s Bing crawled %2$s pages; %3$s pages are in Bing; %4$s crawl errors.',
								'zinn-seo'
							),
							day( last.date ),
							count( last.crawled ),
							count( last.in_index ),
							count( last.errors )
						) }
					</p>
				</section>
			) }
			<section className="zseo-stack">
				<h3>{ __( 'Links Bing knows about', 'zinn-seo' ) }</h3>
				<p>
					{ null === b.linked_pages
						? __( 'Bing did not answer this time.', 'zinn-seo' )
						: sprintf(
								/* translators: %s: a number of pages. */
								__(
									'%s of your pages have links from other sites in Bing.',
									'zinn-seo'
								),
								count( b.linked_pages )
							) }
				</p>
				{ ( b.links || [] ).length > 0 && (
					<Table
						label={ __( 'Pages with links', 'zinn-seo' ) }
						rowKey={ ( r ) => r.url }
						rows={ b.links }
						columns={ [
							{
								id: 'u',
								label: __( 'Page', 'zinn-seo' ),
								render: ( r ) => (
									<bdi className="zseo-break">{ r.url }</bdi>
								),
							},
							{
								id: 'c',
								label: __( 'Links', 'zinn-seo' ),
								numeric: true,
								render: ( r ) => count( r.count ),
							},
						] }
					/>
				) }
			</section>
			<Keyword />
			{ b.pro && b.settings && data.canManage && (
				<CrawlRate settings={ b.settings } />
			) }
			<p className="zseo-muted">
				{ __(
					'Bing does not share Copilot answers through its tools for site owners yet. Visits from Copilot are counted in your Bing traffic above and in AI referrals.',
					'zinn-seo'
				) }
			</p>
		</div>
	);
}
