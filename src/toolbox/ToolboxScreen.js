/**
 * The research toolbox: fourteen paid-per-use tools for the questions SEO plugins usually send you
 * elsewhere for — the map street by street, reviews, shelves, YouTube, a competitor's traffic,
 * dated copies of the results page, brand mood, technology, the questions people ask, and briefs.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	SelectControl,
	TabPanel,
} from '@wordpress/components';

import ProTeaser from '../admin/ProTeaser';
import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { Loaded, useLoad } from '../tools/parts';
import { money } from '../tools/pure';
import { marketOptions } from '../tools/markets';
import GridTab from './tabs/GridTab';
import ReviewsTab from './tabs/ReviewsTab';
import ShelfTab from './tabs/ShelfTab';
import {
	BriefTab,
	QuestionsTab,
	SnapshotTab,
	YoutubeTab,
} from './tabs/SearchTabs';
import { SentimentTab, TechnologyTab, TrafficTab } from './tabs/SiteTabs';
import { DomainOverviewTab, TrendsTab, VisibilityTab } from './tabs/X1bTabs';
import './toolbox.scss';

/**
 * The tools in the order shown, with their tab title and what Pro brings (exported for the
 * command palette).
 *
 * @return {Array<{name: string, title: string, pitch: string}>} Tools.
 */
export function toolboxTabs() {
	return [
		{
			name: 'geo_grid',
			title: __( 'Map rank grid', 'zinn-seo' ),
			pitch: __(
				'See where your business shows on Google Maps from every street around you — not one average number, the whole picture.',
				'zinn-seo'
			),
		},
		{
			name: 'reviews',
			title: __( 'Reviews', 'zinn-seo' ),
			pitch: __(
				'Your Google, Trustpilot and Tripadvisor reviews in one place, with an alert when a new one arrives or your rating slips.',
				'zinn-seo'
			),
		},
		{
			name: 'traffic',
			title: __( 'Competitor traffic', 'zinn-seo' ),
			pitch: __(
				'How many visits a competitor gets from Google, and the pages that bring them.',
				'zinn-seo'
			),
		},
		{
			name: 'domain_overview',
			title: __( 'Domain reports', 'zinn-seo' ),
			pitch: __(
				'Any website’s traffic, best searches, top pages and links in one report, kept so you can look back.',
				'zinn-seo'
			),
		},
		{
			name: 'visibility',
			title: __( 'Share of visibility', 'zinn-seo' ),
			pitch: __(
				'How much of the clicks on your searches go to you and how much to each rival — and how that moves over time.',
				'zinn-seo'
			),
		},
		{
			name: 'trends',
			title: __( 'Google Trends', 'zinn-seo' ),
			pitch: __(
				'When in the year people search for your topic, and the searches that are rising fast.',
				'zinn-seo'
			),
		},
		{
			name: 'questions',
			title: __( 'Questions to FAQ', 'zinn-seo' ),
			pitch: __(
				'The questions people ask Google about your topic, turned into an FAQ on your page in one click.',
				'zinn-seo'
			),
		},
		{
			name: 'brief',
			title: __( 'Content brief', 'zinn-seo' ),
			pitch: __(
				'Write the page that deserves to rank: how long the winners are, what they cover and what searchers ask.',
				'zinn-seo'
			),
		},
		{
			name: 'shopping',
			title: __( 'Google Shopping', 'zinn-seo' ),
			pitch: __(
				'Your place and your price on Google Shopping against the cheapest seller, product by product.',
				'zinn-seo'
			),
		},
		{
			name: 'amazon',
			title: __( 'Amazon', 'zinn-seo' ),
			pitch: __(
				'Where your products come in Amazon search and who sells them for less.',
				'zinn-seo'
			),
		},
		{
			name: 'youtube',
			title: __( 'YouTube', 'zinn-seo' ),
			pitch: __(
				'Where your videos rank on YouTube for the searches that matter to you.',
				'zinn-seo'
			),
		},
		{
			name: 'snapshot',
			title: __( 'Results snapshots', 'zinn-seo' ),
			pitch: __(
				'A dated copy and a picture of the results page — proof for clients and a record of what changed.',
				'zinn-seo'
			),
		},
		{
			name: 'sentiment',
			title: __( 'Brand mood', 'zinn-seo' ),
			pitch: __(
				'How people feel when they write about your brand: positive, neutral or negative, and where.',
				'zinn-seo'
			),
		},
		{
			name: 'technology',
			title: __( 'Technology lookup', 'zinn-seo' ),
			pitch: __(
				'What a competitor’s website is built with: its platform, shop, analytics and marketing tools.',
				'zinn-seo'
			),
		},
	];
}

/**
 * One tool's content.
 *
 * @param {Object} props
 * @param {string} props.name Tool code.
 * @param {Object} props.ctx  Shared state.
 * @return {Element} Tool.
 */
function Tool( { name, ctx } ) {
	switch ( name ) {
		case 'geo_grid':
			return <GridTab ctx={ ctx } />;
		case 'reviews':
			return <ReviewsTab ctx={ ctx } />;
		case 'shopping':
			return <ShelfTab ctx={ ctx } />;
		case 'amazon':
			return <ShelfTab ctx={ ctx } amazon />;
		case 'youtube':
			return <YoutubeTab ctx={ ctx } />;
		case 'snapshot':
			return <SnapshotTab ctx={ ctx } />;
		case 'traffic':
			return <TrafficTab ctx={ ctx } />;
		case 'technology':
			return <TechnologyTab ctx={ ctx } />;
		case 'sentiment':
			return <SentimentTab ctx={ ctx } />;
		case 'questions':
			return <QuestionsTab ctx={ ctx } />;
		case 'domain_overview':
			return <DomainOverviewTab ctx={ ctx } />;
		case 'visibility':
			return <VisibilityTab ctx={ ctx } />;
		case 'trends':
			return <TrendsTab ctx={ ctx } />;
		default:
			return <BriefTab ctx={ ctx } />;
	}
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function ToolboxScreen( { data } ) {
	const load = useLoad( '/toolbox' );
	const watches = useLoad( '/toolbox/watches' );
	const tools = data.tools || {};
	const markets = tools.markets || {};
	const [ country, setCountry ] = useState(
		( tools.market && tools.market.country ) || 'US'
	);
	const tabs = toolboxTabs();
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	return (
		<div className="zseo-admin zseo-tools zseo-paid zseo-toolbox">
			<Loaded load={ load }>
				{ ( overview ) => {
					if ( ! overview.connected ) {
						return (
							<Card className="zseo-connect">
								<CardBody className="zseo-stack">
									<h2 className="zseo-hero">
										{ __(
											'Answers you would normally pay a monthly tool for — only when you need them',
											'zinn-seo'
										) }
									</h2>
									<p className="zseo-lead">
										{ __(
											'Connect this site to a Zinn® account to use the research toolbox. No subscription: each check shows its price first and costs only what you run.',
											'zinn-seo'
										) }
									</p>
									<div>
										<Button
											variant="primary"
											href={ viewHref( 'credits' ) }
										>
											{ __(
												'Connect this site',
												'zinn-seo'
											) }
										</Button>
									</div>
								</CardBody>
							</Card>
						);
					}
					const catalogue = overview.catalogue || {};
					const prices = {};
					const units = {};
					( catalogue.products || [] ).forEach( ( p ) => {
						prices[ p.unit ] = p.price_micros;
						units[ p.code ] = p.unit;
					} );
					const market = markets[ country ] || {
						location: 'United States',
						language: 'en',
					};
					const ctx = {
						prices,
						units,
						balance: overview.balance,
						buyUrl: overview.buy_url,
						canSpend:
							!! overview.can_spend &&
							!! ( data.extra || {} ).canSpend,
						pro: ( overview.products || [] ).includes( 'brief' ),
						market: { country, ...market },
						watches: ( watches.data && watches.data.watches ) || [],
						siteName: data.siteName || '',
						refresh: () => {
							load.reload();
							watches.reload();
						},
					};
					return (
						<>
							<div className="zseo-creditline">
								<span>
									{ __( 'Credit:', 'zinn-seo' ) }{ ' ' }
									<strong>
										{ money( overview.balance ) }
									</strong>
								</span>
								<a href={ viewHref( 'credits' ) }>
									{ __(
										'Add credit, prices and notices',
										'zinn-seo'
									) }
								</a>
								<GuideLink data={ data } id="toolbox" />
							</div>
							{ Object.keys( markets ).length > 0 && (
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									className="zseo-market"
									label={ __(
										'Country to search in',
										'zinn-seo'
									) }
									value={ country }
									options={ marketOptions( markets ) }
									onChange={ setCountry }
								/>
							) }
							<TabPanel
								className="zseo-tabs"
								tabs={ tabs }
								initialTabName={
									tabs.some( ( t ) => t.name === initial )
										? initial
										: 'geo_grid'
								}
								onSelect={ ( name ) => {
									if (
										! (
											window.location.hash || ''
										).startsWith( `#/${ name }` )
									) {
										window.history.replaceState(
											null,
											'',
											`#/${ name }`
										);
									}
								} }
							>
								{ ( tab ) => (
									<Card>
										<CardBody className="zseo-stack">
											<div className="zseo-cardhead">
												<h2 className="zseo-card-title">
													{ tab.title }
												</h2>
												<GuideLink
													data={ data }
													id={ `toolbox-${ tab.name.replace( '_', '-' ) }` }
												/>
											</div>
											<p className="zseo-lead">
												{ tab.pitch }
											</p>
											{ (
												overview.products || []
											).includes( tab.name ) ? (
												<Tool
													name={ tab.name }
													ctx={ ctx }
												/>
											) : (
												<ProTeaser
													title={ tab.title }
													points={ [ tab.pitch ] }
												/>
											) }
										</CardBody>
									</Card>
								) }
							</TabPanel>
						</>
					);
				} }
			</Loaded>
		</div>
	);
}
