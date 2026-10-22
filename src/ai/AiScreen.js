/**
 * AI: write with AI and review, redirects by meaning, ask your data, plan and compete, and how
 * AI is paid for. On the site owner's own key or on Zinn® credits.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Card, CardBody, TabPanel } from '@wordpress/components';

import ProTeaser from '../admin/ProTeaser';
import { AiFailure, SetUp } from './parts';
import WriteTab from './WriteTab';
import RedirectsTab from './RedirectsTab';
import AskTab from './AskTab';
import PlanTab from './PlanTab';
import SettingsTab from './SettingsTab';
import './ai.scss';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function aiTabs() {
	return [
		{ name: 'write', title: __( 'Write with AI', 'zinn-seo' ) },
		{ name: 'redirects', title: __( 'Redirects', 'zinn-seo' ) },
		{ name: 'ask', title: __( 'Ask your data', 'zinn-seo' ) },
		{ name: 'plan', title: __( 'Plan and compete', 'zinn-seo' ) },
		{ name: 'settings', title: __( 'Settings and costs', 'zinn-seo' ) },
	];
}

/**
 * What a free site sees on a Pro tab.
 *
 * @param {Object} props
 * @param {string} props.tab Tab.
 * @return {Element} Teaser.
 */
function Teaser( { tab } ) {
	const teasers = {
		redirects: [
			__( 'Send lost visitors to the right page', 'zinn-seo' ),
			[
				__(
					'Every address in your 404 list gets the page that matches it best, by meaning.',
					'zinn-seo'
				),
				__(
					'Moving site? Give it the old sitemap and get the whole redirect map in minutes.',
					'zinn-seo'
				),
			],
		],
		ask: [
			__( 'Ask your SEO data anything', 'zinn-seo' ),
			[
				__(
					'"Why did traffic drop last week?" — answered from your own Search Console, rankings and audits, with the numbers.',
					'zinn-seo'
				),
			],
		],
		plan: [
			__( 'Plan pages that rank, and beat competitors', 'zinn-seo' ),
			[
				__(
					'Content briefs built on real search data, ready to start as a draft.',
					'zinn-seo'
				),
				__(
					"See exactly why a competitor's page outranks yours, and what to change.",
					'zinn-seo'
				),
				__(
					'Better titles for pages people see in Google but do not click — test them with one click.',
					'zinn-seo'
				),
			],
		],
	};
	const [ title, points ] = teasers[ tab ];
	return <ProTeaser title={ title } points={ points } />;
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function AiScreen( { data } ) {
	const [ ai, setAi ] = useState( data.aiState );
	if ( ! ai ) {
		return (
			<AiFailure
				failure={ {
					kind: 'refused',
					message: __(
						'Your account is not allowed to use AI on this site. Ask an administrator.',
						'zinn-seo'
					),
					action: 'none',
				} }
			/>
		);
	}
	const local = { ...data, ai };
	const tabs = aiTabs();
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	return (
		<div className="zseo-admin zseo-ai">
			{ ! ai.ready && (
				<Card className="zseo-ai__setup">
					<CardBody>
						<SetUp ai={ ai } />
					</CardBody>
				</Card>
			) }
			<TabPanel
				className="zseo-tabs"
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === initial ) ? initial : 'write'
				}
				onSelect={ ( name ) => {
					if (
						! ( window.location.hash || '' ).startsWith(
							`#/${ name }`
						)
					) {
						window.history.replaceState( null, '', `#/${ name }` );
					}
				} }
			>
				{ ( tab ) => {
					if ( 'settings' === tab.name ) {
						return (
							<SettingsTab data={ local } onChange={ setAi } />
						);
					}
					if ( 'write' === tab.name ) {
						return <WriteTab data={ local } />;
					}
					if ( ! ai.pro ) {
						return <Teaser tab={ tab.name } />;
					}
					if ( 'redirects' === tab.name ) {
						return <RedirectsTab data={ local } />;
					}
					if ( 'ask' === tab.name ) {
						return <AskTab data={ local } />;
					}
					return <PlanTab data={ local } />;
				} }
			</TabPanel>
		</div>
	);
}
