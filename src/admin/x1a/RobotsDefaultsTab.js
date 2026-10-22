/**
 * Smart robots defaults (zs-713): each rule, how many pages it covers, and its switch.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { PanelBody, Spinner } from '@wordpress/components';

import GuideLink from '../p7/GuideLink';
import { useRoute } from '../p4/shared';
import { SaveBar, Switch } from './shared';

/**
 * Words for each rule.
 *
 * @return {Object<string, Array<string>>} Rule => [label, help].
 */
function words() {
	return {
		password: [
			__( 'Password-protected posts', 'zinn-seo' ),
			__(
				'Search engines can only see the password form, so the page would rank for nothing.',
				'zinn-seo'
			),
		],
		empty_terms: [
			__( 'Empty categories, tags and archives', 'zinn-seo' ),
			__(
				'An archive with no posts is an empty page. It comes back into search results by itself once it has posts.',
				'zinn-seo'
			),
		],
		comment_pages: [
			__( 'Comment pages', 'zinn-seo' ),
			__(
				'"/comment-page-2/" copies of a post that only differ by older comments.',
				'zinn-seo'
			),
		],
		feeds: [
			__( 'Feeds', 'zinn-seo' ),
			__(
				'Feeds stay readable and their links are followed, but they never show up as results themselves.',
				'zinn-seo'
			),
		],
		paging: [
			__( 'Page numbers a post does not have', 'zinn-seo' ),
			__(
				'WordPress answers "/your-post/7/" with a copy of the post even when it has one page. Those addresses now go to the post itself.',
				'zinn-seo'
			),
		],
	};
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.form The settings draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function RobotsDefaultsTab( { form, data } ) {
	const state = useRoute( '/robots-defaults' );
	const w = words();
	if ( ! state.data ) {
		return <Spinner />;
	}
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Thin pages in search results drag a whole site down. These pages are kept out automatically, with links on them still followed. A page you set to "show in search results" yourself is never overruled.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-robots" />
			</p>
			<PanelBody title={ __( 'Kept out of search results', 'zinn-seo' ) }>
				{ state.data.rules.map( ( r ) => (
					<div key={ r.id }>
						<Switch
							form={ form }
							name={ r.setting }
							label={ ( w[ r.id ] || [ r.id ] )[ 0 ] }
							help={ ( w[ r.id ] || [ '', '' ] )[ 1 ] }
						/>
						{ r.count >= 0 && (
							<p className="zseo-muted zseo-x1a-count">
								{ sprintf(
									/* translators: %d: number of pages. */
									_n(
										'%d page on your site now.',
										'%d pages on your site now.',
										r.count,
										'zinn-seo'
									),
									r.count
								) }
							</p>
						) }
					</div>
				) ) }
				<p className="zseo-muted">
					{ __(
						'Search results pages are set under Search appearance, and sitemaps are always kept out of results.',
						'zinn-seo'
					) }
				</p>
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}
