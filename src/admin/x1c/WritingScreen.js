/**
 * Writing (stage X1C): the AI writer and planner (zs-700), headlines (zs-723), keyword lists
 * (zs-731), spelling and tone (zs-721), writing tool guidelines (zs-732), affiliate links (zs-724),
 * links between your sites (zs-733) and the Google Docs add-on (zs-704). A tab shows while its
 * feature is switched on (a Pro feature on a free site shows what it would do).
 */
import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';

import { seoFetch } from '../api';
import { viewHref } from '../../admin-kit/router';
import WriterTab from './WriterTab';
import PlannerTab from './PlannerTab';
import HeadlineTab from './HeadlineTab';
import KeywordListsTab from './KeywordListsTab';
import ProofreadTab from './ProofreadTab';
import ContentToolsTab from './ContentToolsTab';
import AffiliatesTab from './AffiliatesTab';
import CrosslinksTab from './CrosslinksTab';
import GdocsTab from './GdocsTab';
// zs-782. Not a lazy chunk: WordPress loads a script's translations only for the registered file,
// so strings in a chunk loaded later would stay English (CLAUDE.md §2.19 outranks splitting here;
// settings.js is loaded only on this plugin's own screens).
import CopywriterTab from '../../copywriter/CopywriterTab';
import './x1c.scss';

/**
 * Is a module known to this site (in the boot data's module map)?
 *
 * @param {Object} data Boot data.
 * @param {string} id   Module id.
 * @return {boolean} Known.
 */
function known( data, id ) {
	return id in ( ( data && data.modules ) || {} );
}

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function writingTabs( data = {} ) {
	const mods = data.modules || {};
	const show = ( id ) =>
		!! mods[ id ] || ( known( data, id ) && 'free' === data.plan );
	return [
		...( mods.writer
			? [ { name: 'writer', title: __( 'Writer', 'zinn-seo' ) } ]
			: [] ),
		...( show( 'writer-pro' )
			? [ { name: 'planner', title: __( 'Planner', 'zinn-seo' ) } ]
			: [] ),
		...( show( 'copywriter' )
			? [ { name: 'copywriter', title: __( 'Copywriter', 'zinn-seo' ) } ]
			: [] ),
		...( mods.headline
			? [ { name: 'headlines', title: __( 'Headlines', 'zinn-seo' ) } ]
			: [] ),
		...( mods.keywords
			? [ { name: 'lists', title: __( 'Keyword lists', 'zinn-seo' ) } ]
			: [] ),
		...( mods[ 'analysis-extra' ]
			? [
					{
						name: 'proofread',
						title: __( 'Spelling and tone', 'zinn-seo' ),
					},
				]
			: [] ),
		...( show( 'content-tools' )
			? [ { name: 'tools', title: __( 'Writing tools', 'zinn-seo' ) } ]
			: [] ),
		...( show( 'affiliates' )
			? [
					{
						name: 'affiliates',
						title: __( 'Affiliate links', 'zinn-seo' ),
					},
				]
			: [] ),
		...( show( 'crosslinks' )
			? [
					{
						name: 'crosslinks',
						title: __( 'Between your sites', 'zinn-seo' ),
					},
				]
			: [] ),
		...( show( 'gdocs' )
			? [
					{
						name: 'gdocs',
						title: 'Google Docs™' /* a trademark, never translated: Gdocs::TAB */,
					},
				]
			: [] ),
	];
}

/**
 * What the Pro copywriter does, on a free site (one upgrade path, zs-234).
 *
 * @return {Element} Notice.
 */
function CopywriterUpsell() {
	return (
		<p className="zseo-muted">
			{ __(
				"Pro: more than seventy AI writing tools and a library of ready-made prompts, written from each page's own SEO research, in any language, with versions to compare and a check of each.",
				'zinn-seo'
			) }{ ' ' }
			<a href={ viewHref( 'plans' ) }>
				{ __( 'See the Pro plans', 'zinn-seo' ) }
			</a>
		</p>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function WritingScreen( { data } ) {
	const tabs = writingTabs( data );
	const plan =
		data.modules && data.modules[ 'writer-pro' ]
			? ( ideas ) =>
					seoFetch( '/writer/plan', {
						method: 'POST',
						data: { ideas },
					} )
			: null;
	const initial = String( window.location.hash || '' ).replace( /^#\/?/, '' );
	return (
		<div className="zseo-x1c-screen">
			<TabPanel
				className="zseo-tabs"
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === initial )
						? initial
						: undefined
				}
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'writer':
							return <WriterTab data={ data } onPlan={ plan } />;
						case 'planner':
							return <PlannerTab data={ data } />;
						case 'copywriter':
							return data.modules && data.modules.copywriter ? (
								<CopywriterTab />
							) : (
								<CopywriterUpsell />
							);
						case 'headlines':
							return <HeadlineTab data={ data } />;
						case 'lists':
							return <KeywordListsTab data={ data } />;
						case 'proofread':
							return <ProofreadTab data={ data } />;
						case 'tools':
							return <ContentToolsTab data={ data } />;
						case 'affiliates':
							return <AffiliatesTab data={ data } />;
						case 'crosslinks':
							return <CrosslinksTab data={ data } />;
						case 'gdocs':
							return <GdocsTab data={ data } />;
						default:
							return null;
					}
				} }
			</TabPanel>
		</div>
	);
}
