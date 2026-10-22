import { __ } from '@wordpress/i18n';
import { createRoot } from '@wordpress/element';

import { mountAdminKit } from '../admin-kit';
import { viewHref } from '../admin-kit/router';
import CommandPalette from './CommandPalette';
import SettingsScreen, { settingsTabs } from './SettingsScreen';
import RolesScreen from './RolesScreen';
import ToolsScreen, { TransferScreen, toolsTabs } from './ToolsScreen';
import CleanupScreen, { cleanupTabs } from './x1a/CleanupScreen';
import WritingScreen, { writingTabs } from './x1c/WritingScreen';
import RichResultsScreen, { richTabs } from './x1c/RichResultsScreen';
import AppearanceScreen, { appearanceTabs } from './AppearanceScreen';
import SocialScreen, { socialTabs } from './SocialScreen';
import BulkScreen, { bulkTabs } from './BulkScreen';
import DiscoveryScreen, { discoveryTabs } from './DiscoveryScreen';
import SchemaScreen, { schemaTabs } from './SchemaScreen';
import BlocksScreen, { blocksTabs } from './BlocksScreen';
import FixitScreen from './p4/FixitScreen';
import ContentScreen, { contentTabs } from './p4/ContentScreen';
import LinksScreen, { linksTabs } from './p4/LinksScreen';
import LinkPlanTab from './ail1/LinkPlanTab';
import AutopilotScreen from './auto1/AutopilotScreen';
import RedirectsScreen, { redirectsTabs } from './p5/RedirectsScreen';
import ProtectionScreen, { protectionTabs } from './p5/ProtectionScreen';
import ImportScreen from './p6/ImportScreen';
import IntegrationsScreen, { integrationsTabs } from './p7/IntegrationsScreen';
import ChecksScreen, { checksTabs } from '../tools/ChecksScreen';
import CreditsScreen from '../tools/CreditsScreen';
import ServicesScreen from '../offers/ServicesScreen';
import CatalogueScreen from '../catalogue/CatalogueScreen';
import IndexingScreen from '../indexing/IndexingScreen';
import ReferralsScreen from '../referrals/ReferralsScreen';
import GuardScreen from '../guard/GuardScreen';
import CareScreen, { careTabs } from '../care/CareScreen';
import CdnScreen from '../cdn/CdnScreen';
import ToolboxScreen, { toolboxTabs } from '../toolbox/ToolboxScreen';
import SearchScreen, { searchTabs } from '../search/SearchScreen';
import AuthorityScreen, { authorityTabs } from '../authority/AuthorityScreen';
import { OverviewCredit } from '../tools/CreditSettings';
import AiScreen, { aiTabs } from '../ai/AiScreen';
import AgencyScreen, { agencyTabs } from '../agency/AgencyScreen';
import ReportsScreen from '../agency/ReportsScreen';
import TasksScreen from '../agency/Tasks';
import PagesScreen from '../agency/PagesScreen';
import { clientRoutes } from '../agency/screens';
import setupSteps from './setupSteps';
import { mountWithSideNav } from './nav/mount';
import { navBadges } from './nav/sections';
import { proTeasers } from './nav/teasers';
import './admin.scss';
import './p4/p4.scss';

const root = document.getElementById( 'zinn-seo-admin-root' );
const data = window.zinnSeoAdmin;

/**
 * Every place the command palette can jump to: the screens and each settings tab.
 *
 * @return {Array<Object>} Commands.
 */
function commands() {
	const go =
		( view, hash = '' ) =>
		() => {
			window.location.assign(
				viewHref( view ) + ( hash ? `#/${ hash }` : '' )
			);
		};
	const tabs = ( view, list, prefix ) =>
		list.map( ( tab ) => ( {
			id: `${ view }-${ tab.name }`,
			label: `${ prefix }: ${ tab.title }`,
			keywords: `${ view } ${ tab.name }`,
			run: go( view, tab.name ),
		} ) );
	const mods = data.modules || {};
	return [
		...( mods.fixit
			? [
					{
						id: 'fixit',
						label: __(
							'Fix it: what to fix across the site',
							'zinn-seo'
						),
						keywords: 'issues problems inbox tasks',
						run: go( 'fixit' ),
					},
					...( 'free' !== data.plan && data.autopilot
						? [
								{
									id: 'autopilot',
									label: __(
										'Fix it on autopilot',
										'zinn-seo'
									),
									keywords:
										'autopilot fix all bulk whole site ai schedule weekly undo',
									run: go( 'autopilot' ),
								},
							]
						: [] ),
				]
			: [] ),
		...( mods.analysis
			? tabs( 'content', contentTabs(), __( 'Content', 'zinn-seo' ) )
			: [] ),
		...( mods.links
			? tabs( 'links', linksTabs(), __( 'Links', 'zinn-seo' ) )
			: [] ),
		...( mods.links && 'free' !== data.plan && data.linkPlan
			? [
					{
						id: 'linkplan',
						label: __( 'Link plan', 'zinn-seo' ),
						keywords:
							'link plan internal links whole site orphans hub ai',
						run: go( 'linkplan' ),
					},
				]
			: [] ),
		...( mods.redirects
			? tabs(
					'redirects',
					redirectsTabs( data ),
					__( 'Redirects and 404', 'zinn-seo' )
				)
			: [] ),
		...tabs(
			'protection',
			protectionTabs(),
			__( 'Protection', 'zinn-seo' )
		),
		...tabs(
			'appearance',
			appearanceTabs(),
			__( 'Search appearance', 'zinn-seo' )
		),
		...tabs( 'cleanup', cleanupTabs( data ), __( 'Clean-up', 'zinn-seo' ) ),
		...( data.social
			? tabs( 'social', socialTabs(), __( 'Social', 'zinn-seo' ) )
			: [] ),
		...tabs(
			'discovery',
			discoveryTabs(),
			__( 'Sitemaps and AI', 'zinn-seo' )
		),
		...tabs( 'schema', schemaTabs(), __( 'Structured data', 'zinn-seo' ) ),
		...tabs(
			'richresults',
			richTabs( data ),
			__( 'Rich results', 'zinn-seo' )
		),
		...( writingTabs( data ).length
			? tabs(
					'writing',
					writingTabs( data ),
					__( 'Writing', 'zinn-seo' )
				)
			: [] ),
		...tabs( 'blocks', blocksTabs(), __( 'SEO blocks', 'zinn-seo' ) ),
		...( data.care
			? tabs( 'care', careTabs(), __( 'Site care', 'zinn-seo' ) )
			: [] ),
		...( data.bulk
			? tabs( 'bulk', bulkTabs(), __( 'Bulk editor', 'zinn-seo' ) )
			: [] ),
		...( mods.integrations
			? tabs(
					'works',
					integrationsTabs( data ),
					__( 'Works with', 'zinn-seo' )
				)
			: [] ),
		...( mods.gsc
			? tabs(
					'search',
					searchTabs( data ),
					__( 'Search performance', 'zinn-seo' )
				)
			: [] ),
		...( mods[ 'ahrefs-dr' ]
			? tabs(
					'authority',
					authorityTabs(),
					__( 'Site authority', 'zinn-seo' )
				)
			: [] ),
		...( mods.tools
			? tabs(
					'checks',
					checksTabs( data ),
					__( 'Rankings and checks', 'zinn-seo' )
				)
			: [] ),
		...( mods[ 'tools-extra' ]
			? tabs(
					'toolbox',
					toolboxTabs(),
					__( 'Research toolbox', 'zinn-seo' )
				)
			: [] ),
		...( data.aiState
			? tabs( 'ai', aiTabs(), __( 'AI', 'zinn-seo' ) )
			: [] ),
		...( mods.credits
			? [
					{
						id: 'credits',
						label: __(
							'Credit: balance, add credit, notices',
							'zinn-seo'
						),
						keywords:
							'zinn account connect buy top up balance price notices alerts',
						run: go( 'credits' ),
					},
				]
			: [] ),
		...( data.tasks
			? [
					{
						id: 'tasks',
						label: __( 'Tasks: what is given to whom', 'zinn-seo' ),
						keywords: 'todo assign due comments',
						run: go( 'tasks' ),
					},
				]
			: [] ),
		...( data.reports && data.reports.manage
			? [
					{
						id: 'reports',
						label: __( 'Client reports', 'zinn-seo' ),
						keywords:
							'white label pdf email google sheet drive client page agency',
						run: go( 'reports' ),
					},
				]
			: [] ),
		...( data.programmatic
			? [
					{
						id: 'pages',
						label: __( 'Pages from a spreadsheet', 'zinn-seo' ),
						keywords:
							'programmatic csv sheet template landing bulk create',
						run: go( 'pages' ),
					},
				]
			: [] ),
		...tabs( 'agency', agencyTabs( data ), __( 'Agency', 'zinn-seo' ) ),
		...settingsTabs().map( ( tab ) => ( {
			id: `settings-${ tab.name }`,
			label: `${ __( 'Settings', 'zinn-seo' ) }: ${ tab.title }`,
			keywords: tab.name,
			run: go( 'settings', tab.name ),
		} ) ),
		{
			id: 'transfer',
			label: __( 'Export / import settings', 'zinn-seo' ),
			keywords:
				'copy settings another site file json download upload migrate backup transfer',
			run: go( 'transfer' ),
		},
		{
			id: 'roles',
			label: __( 'Roles and permissions', 'zinn-seo' ),
			keywords: 'users capabilities',
			run: go( 'roles' ),
		},
		...( mods.import
			? [
					{
						id: 'import',
						label: __(
							'Move from another SEO plugin (Yoast, Rank Math, All in One SEO…)',
							'zinn-seo'
						),
						keywords:
							'import migrate yoast rank math aioseo seopress framework slim squirrly smartcrawl redirection switch',
						run: go( 'import' ),
					},
				]
			: [] ),
		...tabs( 'tools', toolsTabs(), __( 'Tools', 'zinn-seo' ) ),
		{
			id: 'setup',
			label: __( 'Run the setup again', 'zinn-seo' ),
			keywords: 'wizard guided',
			run: go( 'setup' ),
		},
		{
			id: 'plans',
			label: __( 'Plans and licence', 'zinn-seo' ),
			keywords: 'pro upgrade licence license',
			run: go( 'plans' ),
		},
		{
			id: 'help',
			label: __( 'Get help', 'zinn-seo' ),
			keywords: 'support ticket bug',
			run: go( 'help' ),
		},
	];
}

if ( root && data ) {
	// The admin kit is the shell (overview, setup, plans, add-ons, help); Zinn® SEO's own screens are
	// routes inside it. The setup's steps ARE settings, so a choice made during setup is the same
	// setting as on its own tab.
	const palette = document.createElement( 'div' );
	root.after( palette );
	const kit = ( window.zinnAdminKit || {} )[ 'zinn-seo' ] || {};
	const hostRoutes = [
		...clientRoutes(
			[
				...( ( data.modules || {} ).fixit
					? [
							{
								id: 'fixit',
								label: __( 'Fix it', 'zinn-seo' ),
								render: () => <FixitScreen data={ data } />,
							},
						]
					: [] ),
				// Fix it on autopilot (Pro, zs-767): its own screen just under Fix it. On the free
				// plugin the Pro card stands in its place (nav/teasers.js).
				...( ( data.modules || {} ).fixit &&
				'free' !== data.plan &&
				data.autopilot
					? [
							{
								id: 'autopilot',
								label: __( 'Fix it on autopilot', 'zinn-seo' ),
								render: () => <AutopilotScreen data={ data } />,
							},
						]
					: [] ),
				...( writingTabs( data ).length
					? [
							{
								id: 'writing',
								label: __( 'Writing', 'zinn-seo' ),
								render: () => <WritingScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).analysis
					? [
							{
								id: 'content',
								label: __( 'Content', 'zinn-seo' ),
								render: () => <ContentScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).links
					? [
							{
								id: 'links',
								label: __( 'Links', 'zinn-seo' ),
								render: () => <LinksScreen data={ data } />,
							},
						]
					: [] ),
				// The whole-site link plan (Pro, zs-763): its own screen under Links. On the free
				// plugin the Pro card stands in its place (nav/teasers.js).
				...( ( data.modules || {} ).links &&
				'free' !== data.plan &&
				data.linkPlan
					? [
							{
								id: 'linkplan',
								label: __( 'Link plan', 'zinn-seo' ),
								render: () => (
									<div className="zseo-admin">
										<LinkPlanTab data={ data } />
									</div>
								),
							},
						]
					: [] ),
				...( ( data.modules || {} ).redirects
					? [
							{
								id: 'redirects',
								label: __( 'Redirects and 404', 'zinn-seo' ),
								render: () => <RedirectsScreen data={ data } />,
							},
						]
					: [] ),
				{
					id: 'protection',
					label: __( 'Protection', 'zinn-seo' ),
					render: () => <ProtectionScreen data={ data } />,
				},
				{
					id: 'appearance',
					label: __( 'Search appearance', 'zinn-seo' ),
					render: () => <AppearanceScreen data={ data } />,
				},
				...( cleanupTabs( data ).length
					? [
							{
								id: 'cleanup',
								label: __( 'Clean-up', 'zinn-seo' ),
								render: () => <CleanupScreen data={ data } />,
							},
						]
					: [] ),
				...( data.social
					? [
							{
								id: 'social',
								label: __( 'Social', 'zinn-seo' ),
								render: () => <SocialScreen data={ data } />,
							},
						]
					: [] ),
				{
					id: 'discovery',
					label: __( 'Sitemaps and AI', 'zinn-seo' ),
					render: () => <DiscoveryScreen data={ data } />,
				},
				{
					id: 'schema',
					label: __( 'Structured data', 'zinn-seo' ),
					render: () => <SchemaScreen data={ data } />,
				},
				{
					id: 'richresults',
					label: __( 'Rich results', 'zinn-seo' ),
					render: () => <RichResultsScreen data={ data } />,
				},
				{
					id: 'blocks',
					label: __( 'SEO blocks', 'zinn-seo' ),
					render: () => <BlocksScreen data={ data } />,
				},
				...( data.bulk
					? [
							{
								id: 'bulk',
								label: __( 'Bulk editor', 'zinn-seo' ),
								render: () => <BulkScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).integrations
					? [
							{
								id: 'works',
								label: __( 'Works with', 'zinn-seo' ),
								render: () => (
									<IntegrationsScreen data={ data } />
								),
							},
						]
					: [] ),
				...( ( data.modules || {} ).indexing && data.indexing
					? [
							{
								id: 'indexing',
								label: __( 'Indexing', 'zinn-seo' ),
								render: () => <IndexingScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).gsc
					? [
							{
								id: 'search',
								label: __( 'Search performance', 'zinn-seo' ),
								render: () => <SearchScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).referrals && data.referrals
					? [
							{
								id: 'referrals',
								label: __(
									'AI visitors and earnings',
									'zinn-seo'
								),
								render: () => <ReferralsScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} )[ 'ahrefs-dr' ]
					? [
							{
								id: 'authority',
								label: __( 'Site authority', 'zinn-seo' ),
								render: () => <AuthorityScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).tools
					? [
							{
								id: 'checks',
								label: __( 'Rankings and checks', 'zinn-seo' ),
								render: () => <ChecksScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} )[ 'tools-extra' ]
					? [
							{
								id: 'toolbox',
								label: __( 'Research toolbox', 'zinn-seo' ),
								render: () => <ToolboxScreen data={ data } />,
							},
						]
					: [] ),
				...( data.aiState
					? [
							{
								id: 'ai',
								label: __( 'AI', 'zinn-seo' ),
								render: () => <AiScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).credits
					? [
							{
								id: 'credits',
								label: __( 'Credit', 'zinn-seo' ),
								render: () => <CreditsScreen data={ data } />,
								// The credit card on the Overview screen (P10 / Q127).
								overview: ( navigate ) =>
									data.canSpend ? (
										<OverviewCredit navigate={ navigate } />
									) : null,
							},
						]
					: [] ),
				...( ( data.modules || {} ).offers && ( data.offers || {} ).show
					? [
							{
								id: 'services',
								label: __( 'Services', 'zinn-seo' ),
								render: () => <ServicesScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).catalogue && data.catalogue
					? [
							{
								id: 'catalogue',
								label: __( 'Zinn® price list', 'zinn-seo' ),
								render: () => <CatalogueScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} )[ 'update-guard' ] && data.guard
					? [
							{
								id: 'guard',
								label: __( 'Safe updates', 'zinn-seo' ),
								render: () => <GuardScreen data={ data } />,
							},
						]
					: [] ),
				// Site care (Pro): the free plugin shows its Pro card instead (nav/teasers.js).
				...( data.care ||
				( data.modules || {} )[ 'site-care' ] !== undefined
					? [
							{
								id: 'care',
								label: __( 'Site care', 'zinn-seo' ),
								render: () => <CareScreen data={ data } />,
							},
						]
					: [] ),
				...( ( data.modules || {} ).cdn
					? [
							{
								id: 'cdn',
								label: __( 'CDN', 'zinn-seo' ),
								render: () => <CdnScreen data={ data } />,
							},
						]
					: [] ),
				...( data.tasks
					? [
							{
								id: 'tasks',
								label: __( 'Tasks', 'zinn-seo' ),
								render: () => <TasksScreen data={ data } />,
							},
						]
					: [] ),
				...( data.reports
					? [
							{
								id: 'reports',
								label: __( 'Client reports', 'zinn-seo' ),
								render: () => <ReportsScreen data={ data } />,
							},
						]
					: [] ),
				...( data.programmatic
					? [
							{
								id: 'pages',
								label: __( 'Pages from a sheet', 'zinn-seo' ),
								render: () => <PagesScreen />,
							},
						]
					: [] ),
				...( agencyTabs( data ).length
					? [
							{
								id: 'agency',
								label: __( 'Agency', 'zinn-seo' ),
								render: () => <AgencyScreen data={ data } />,
							},
						]
					: [] ),
				{
					id: 'settings',
					label: __( 'Settings', 'zinn-seo' ),
					render: () => <SettingsScreen data={ data } />,
				},
				{
					id: 'transfer',
					label: __( 'Export / import settings', 'zinn-seo' ),
					render: () => <TransferScreen data={ data } />,
				},
				{
					id: 'roles',
					label: __( 'Roles', 'zinn-seo' ),
					render: () => <RolesScreen data={ data } />,
				},
				...( ( data.modules || {} ).import
					? [
							{
								id: 'import',
								label: __(
									'Move from another plugin',
									'zinn-seo'
								),
								render: () => <ImportScreen data={ data } />,
							},
						]
					: [] ),
				{
					id: 'tools',
					label: __( 'Tools', 'zinn-seo' ),
					render: () => <ToolsScreen data={ data } />,
				},
			],
			data.client || null
		),
		// The free plugin lists the Pro screens too, marked Pro, each opening a card that says
		// what it does (its screen, data and routes are not in the free plugin).
		// A real screen always wins over its card.
		...proTeasers( data, kit ),
	].filter( ( r, i, all ) => all.findIndex( ( o ) => o.id === r.id ) === i );
	mountWithSideNav(
		root,
		() =>
			mountAdminKit(
				root,
				'zinn-seo',
				hostRoutes,
				data.client ? [] : setupSteps( data )
			),
		hostRoutes,
		kit,
		navBadges( data )
	);
	// The palette is its own tree beside the shell, so it listens on every screen of the plugin.
	const allowed = data.client ? new Set( data.client.screens || [] ) : null;
	createRoot( palette ).render(
		<CommandPalette
			commands={ commands().filter(
				( c ) =>
					! allowed || allowed.has( String( c.id ).split( '-' )[ 0 ] )
			) }
		/>
	);
}
