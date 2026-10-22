/**
 * Rankings and checks: the rank tracker, the Google index checker, AI answers, backlinks, the
 * site audit and keyword research — and, with Pro, competitors, the local grid and mentions.
 */
import { __ } from '@wordpress/i18n';
import { Button, Card, CardBody, TabPanel } from '@wordpress/components';

import ProTeaser from '../admin/ProTeaser';
import { viewHref } from '../admin-kit/router';
import { Loaded, useLoad } from './parts';
import { money } from './pure';
import RankTab from './tabs/RankTab';
import IndexTab from './tabs/IndexTab';
import AiTab from './tabs/AiTab';
import LinksTab from './tabs/LinksTab';
import AuditTab from './tabs/AuditTab';
import KeywordsTab from './tabs/KeywordsTab';
import GapTab from './tabs/GapTab';
import LocalTab from './tabs/LocalTab';
import MentionsTab from './tabs/MentionsTab';
import './tools.scss';

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function checksTabs( data = {} ) {
	return [
		{ name: 'rank', title: __( 'Rankings', 'zinn-seo' ) },
		{ name: 'index', title: __( 'Google index', 'zinn-seo' ) },
		{ name: 'ai', title: __( 'AI answers', 'zinn-seo' ) },
		{ name: 'links', title: __( 'Backlinks', 'zinn-seo' ) },
		{ name: 'audit', title: __( 'Site audit', 'zinn-seo' ) },
		{ name: 'keywords', title: __( 'Keywords', 'zinn-seo' ) },
		...( data.toolsPro
			? [
					{ name: 'gap', title: __( 'Competitors', 'zinn-seo' ) },
					{ name: 'local', title: __( 'Local', 'zinn-seo' ) },
					{ name: 'mentions', title: __( 'Mentions', 'zinn-seo' ) },
				]
			: [ { name: 'more', title: __( 'More with Pro', 'zinn-seo' ) } ] ),
	];
}

/**
 * What Pro adds here.
 *
 * @return {Element} Teasers.
 */
function More() {
	return (
		<div className="zseo-stack">
			<ProTeaser
				title={ __(
					'Win the searches your competitors win',
					'zinn-seo'
				) }
				points={ [
					__(
						'See the phrases a competitor ranks for and you do not, and start a draft from any of them.',
						'zinn-seo'
					),
					__(
						'Research phrases in every country and language you sell in — right inside the editor.',
						'zinn-seo'
					),
				] }
			/>
			<ProTeaser
				title={ __( 'Be found by customers nearby', 'zinn-seo' ) }
				points={ [
					__(
						'Your position on Google Maps and in the local results, town by town.',
						'zinn-seo'
					),
					__(
						'See which businesses are above you in each town.',
						'zinn-seo'
					),
				] }
			/>
			<ProTeaser
				title={ __( 'Know when people talk about you', 'zinn-seo' ) }
				points={ [
					__(
						'New mentions of your brand across the web, good and bad.',
						'zinn-seo'
					),
					__(
						'An alert when another site copies one of your pages.',
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
export default function ChecksScreen( { data } ) {
	const load = useLoad( '/tools' );
	const tabs = checksTabs( data );
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	return (
		<div className="zseo-admin zseo-tools zseo-paid">
			<Loaded load={ load }>
				{ ( overview ) => {
					if ( ! overview.connected ) {
						return (
							<Card className="zseo-connect">
								<CardBody className="zseo-stack">
									<h2 className="zseo-hero">
										{ __(
											'See where you rank, what Google has indexed and whether AI answers mention you',
											'zinn-seo'
										) }
									</h2>
									<p className="zseo-lead">
										{ __(
											'Connect this site to a Zinn® account to run these checks from here. No subscription — you pay only for what you run.',
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
					const account = overview.account || null;
					const tools = data.tools || {};
					const ctx = {
						target: overview.target,
						plan: overview.plan || { products: [] },
						tools: overview.tools,
						account,
						canSpend: !! overview.can_spend && !! data.canSpend,
						buyUrl: account ? account.buy_url : '',
						markets: tools.markets || {},
						market: tools.market || {
							country: 'US',
							language: 'en',
						},
						refresh: () => load.reload(),
					};
					return (
						<>
							<p className="zseo-creditline">
								<span>
									{ __( 'Credit:', 'zinn-seo' ) }{ ' ' }
									<strong>
										{ account
											? money( account.balance_micros )
											: '—' }
									</strong>
								</span>
								<a href={ viewHref( 'credits' ) }>
									{ __(
										'Add credit, prices and notices',
										'zinn-seo'
									) }
								</a>
							</p>
							<TabPanel
								className="zseo-tabs"
								tabs={ tabs }
								initialTabName={
									tabs.some( ( t ) => t.name === initial )
										? initial
										: 'rank'
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
								{ ( tab ) => {
									const props = { data, ctx };
									switch ( tab.name ) {
										case 'index':
											return <IndexTab { ...props } />;
										case 'ai':
											return <AiTab { ...props } />;
										case 'links':
											return <LinksTab { ...props } />;
										case 'audit':
											return <AuditTab { ...props } />;
										case 'keywords':
											return <KeywordsTab { ...props } />;
										case 'gap':
											return <GapTab { ...props } />;
										case 'local':
											return <LocalTab { ...props } />;
										case 'mentions':
											return <MentionsTab { ...props } />;
										case 'more':
											return <More />;
										default:
											return <RankTab { ...props } />;
									}
								} }
							</TabPanel>
						</>
					);
				} }
			</Loaded>
		</div>
	);
}
