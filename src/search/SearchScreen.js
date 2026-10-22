/**
 * Search performance: Google Search Console, Bing, Google Analytics and the page speed real
 * visitors get — and, with Pro, what to do about them (stage P11).
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Spinner, TabPanel } from '@wordpress/components';

import ProTeaser from '../admin/ProTeaser';
import { NewTab, Refusal, useLoad } from '../tools/parts';
import { guideUrl } from '../tools/pure';
import Connect from './Connect';
import OverviewTab from './OverviewTab';
import {
	DecayTab,
	OpportunitiesTab,
	TimelineTab,
	TopicsTab,
} from './InsightsTabs';
import IndexTab from './IndexTab';
import SpeedTab from './SpeedTab';
import TestsTab from './TestsTab';
import AnalyticsTab from './AnalyticsTab';
import CrawlTab from './CrawlTab';
import EdgeTab from './EdgeTab';
import BusinessTab from './BusinessTab';
import BingTab from './BingTab';
import './search.scss';

/**
 * The tabs this site has (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function searchTabs( data = {} ) {
	const mods = data.modules || {};
	const pro = [
		[
			'search-insights',
			'opportunities',
			__( 'Opportunities', 'zinn-seo' ),
		],
		[ 'search-insights', 'decay', __( 'Losing clicks', 'zinn-seo' ) ],
		[ 'search-insights', 'topics', __( 'Topics', 'zinn-seo' ) ],
		[
			'search-insights',
			'timeline',
			__( 'Changes and rankings', 'zinn-seo' ),
		],
		[ 'abtest', 'tests', __( 'Title tests', 'zinn-seo' ) ],
		[ 'ga4', 'analytics', __( 'Revenue from search', 'zinn-seo' ) ],
		[ 'crawl-budget', 'crawlers', __( 'Crawlers', 'zinn-seo' ) ],
		[ 'edge', 'edge', __( 'Cloudflare', 'zinn-seo' ) ],
		[ 'gbp', 'business', __( 'Business listings', 'zinn-seo' ) ],
	];
	const have = pro.filter( ( [ id ] ) => mods[ id ] );
	return [
		{ name: 'overview', title: __( 'Overview', 'zinn-seo' ) },
		...have.map( ( [ , name, title ] ) => ( { name, title } ) ),
		{ name: 'index', title: __( 'Indexing', 'zinn-seo' ) },
		...( mods.cwv
			? [ { name: 'speed', title: __( 'Page speed', 'zinn-seo' ) } ]
			: [] ),
		...( mods.bing
			? [ { name: 'bing', title: 'Bing' /* a brand, not translated */ } ]
			: [] ),
		...( have.length < pro.length
			? [ { name: 'more', title: __( 'More with Pro', 'zinn-seo' ) } ]
			: [] ),
	];
}

const GUIDES = {
	overview: 'search',
	opportunities: 'search-striking',
	decay: 'search-decay',
	topics: 'search-topics',
	timeline: 'search-timeline',
	tests: 'search-tests',
	analytics: 'search-analytics',
	crawlers: 'search-crawlers',
	edge: 'search-edge',
	business: 'search-business',
	index: 'search-index',
	speed: 'search-speed',
	bing: 'search-bing',
};

/**
 * What Pro adds on this screen.
 *
 * @return {Element} Teasers.
 */
function More() {
	return (
		<div className="zseo-stack">
			<ProTeaser
				title={ __( 'Win the clicks you nearly have', 'zinn-seo' ) }
				points={ [
					__(
						'Every search each page is one push from page one for, with the exact change to make — and an AI rewrite in one click.',
						'zinn-seo'
					),
					__(
						'Pages losing clicks, caught early, with a refresh list.',
						'zinn-seo'
					),
					__(
						'The topics to write next, with a ready brief for each.',
						'zinn-seo'
					),
				] }
			/>
			<ProTeaser
				title={ __( 'Prove what works', 'zinn-seo' ) }
				points={ [
					__(
						'Test a new title against the old one with real Google clicks; the winner is kept for you.',
						'zinn-seo'
					),
					__(
						'Every SEO change marked on your clicks chart, so you see what moved rankings.',
						'zinn-seo'
					),
					__(
						'Revenue and conversions from search per page, without adding a tracking script.',
						'zinn-seo'
					),
				] }
			/>
			<ProTeaser
				title={ __( 'Faster crawling, at the edge', 'zinn-seo' ) }
				points={ [
					__(
						'See which pages Google, Bing and AI crawlers read, and stop the visits wasted on filters and dead pages.',
						'zinn-seo'
					),
					__(
						'Redirects answered by Cloudflare in milliseconds, and the cache cleared the moment a title changes.',
						'zinn-seo'
					),
					__(
						'Reply to Google reviews and keep Apple Maps and Bing listings in step.',
						'zinn-seo'
					),
				] }
			/>
		</div>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function SearchScreen( { data } ) {
	const link = useLoad( '/search/google' );
	const tabs = searchTabs( data );
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	const [ current, setCurrent ] = useState(
		tabs.some( ( t ) => t.name === initial ) ? initial : 'overview'
	);
	// Back from Google in another tab: read the link again when this tab is looked at.
	const reload = link.reload;
	useEffect( () => {
		const again = () => {
			if ( document.visibilityState === 'visible' ) {
				reload( '/search/google?fresh=1' );
			}
		};
		document.addEventListener( 'visibilitychange', again );
		return () => document.removeEventListener( 'visibilitychange', again );
	}, [ reload ] );
	const google = link.data || {};
	const ready = !! google.connected && !! google.search_console_property;
	return (
		<div className="zseo-admin zseo-tools zseo-search">
			<div className="zseo-cardhead">
				<h2 className="zseo-hero">
					{ __(
						'How people find you on Google and Bing — and what to do next',
						'zinn-seo'
					) }
				</h2>
				<NewTab
					href={ guideUrl( data, GUIDES[ current ] || 'search' ) }
				>
					{ __( 'Step-by-step guide', 'zinn-seo' ) }
				</NewTab>
			</div>
			{ link.error && ! link.data && <Refusal error={ link.error } /> }
			{ ! link.data && ! link.error && <Spinner /> }
			{ link.data && (
				<Connect data={ data } link={ link } google={ google } />
			) }
			{ link.data && (
				<TabPanel
					className="zseo-tabs"
					tabs={ tabs }
					initialTabName={
						tabs.some( ( t ) => t.name === initial )
							? initial
							: 'overview'
					}
					onSelect={ ( name ) => {
						setCurrent( name );
						if (
							! ( window.location.hash || '' ).startsWith(
								`#/${ name }`
							)
						) {
							window.history.replaceState(
								null,
								'',
								`#/${ name }`
							);
						}
					} }
				>
					{ ( tab ) => {
						const props = { data, google, ready };
						switch ( tab.name ) {
							case 'opportunities':
								return <OpportunitiesTab { ...props } />;
							case 'decay':
								return <DecayTab { ...props } />;
							case 'topics':
								return <TopicsTab { ...props } />;
							case 'timeline':
								return <TimelineTab { ...props } />;
							case 'tests':
								return <TestsTab { ...props } />;
							case 'analytics':
								return <AnalyticsTab { ...props } />;
							case 'crawlers':
								return <CrawlTab { ...props } />;
							case 'edge':
								return <EdgeTab { ...props } />;
							case 'business':
								return <BusinessTab { ...props } />;
							case 'index':
								return <IndexTab { ...props } />;
							case 'speed':
								return <SpeedTab { ...props } />;
							case 'bing':
								return <BingTab { ...props } />;
							case 'more':
								return <More />;
							default:
								return <OverviewTab { ...props } />;
						}
					} }
				</TabPanel>
			) }
		</div>
	);
}
