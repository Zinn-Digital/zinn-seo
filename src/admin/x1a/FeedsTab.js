/**
 * RSS feed SEO (zs-717).
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, TextControl, TextareaControl } from '@wordpress/components';

import GuideLink from '../p7/GuideLink';
import { SaveBar, Switch } from './shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.form The settings draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function FeedsTab( { form, data } ) {
	const vars =
		'%post_link% %post_url% %title% %site_link% %site_url% %sitename% %author%';
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Scraper sites copy feeds word for word. A line linking back to your post on every item means each copy credits you, and tells search engines which page came first.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-feeds" />
			</p>
			<PanelBody title={ __( 'Credit line', 'zinn-seo' ) }>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Before each item', 'zinn-seo' ) }
					value={ form.value.feed_before || '' }
					onChange={ ( v ) => form.change( { feed_before: v } ) }
					rows={ 2 }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'After each item', 'zinn-seo' ) }
					placeholder={
						/* translators: %post_link% and %site_link% are variables the plugin fills in; keep them exactly as they are. */
						__(
							'%post_link% was first published on %site_link%.',
							'zinn-seo'
						)
					}

					value={ form.value.feed_after || '' }
					onChange={ ( v ) => form.change( { feed_after: v } ) }
					rows={ 2 }
				/>
				<p className="zseo-muted">
					{ __( 'You can use:', 'zinn-seo' ) }{ ' ' }
					<code dir="ltr">{ vars }</code>
				</p>
			</PanelBody>
			<PanelBody title={ __( 'More', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="feed_image"
					label={ __(
						'Add the featured image to each item',
						'zinn-seo'
					) }
					help={ __(
						'Feed readers and email newsletters built from your feed show the picture.',
						'zinn-seo'
					) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					max={ 10080 }
					label={ __(
						'Hold new posts back from the feed (minutes)',
						'zinn-seo'
					) }
					help={ __(
						'Gives search engines time to find a new post on your site before copies of it appear elsewhere. 0: no delay.',
						'zinn-seo'
					) }
					value={ String( form.value.feed_delay || 0 ) }
					onChange={ ( v ) =>
						form.change( {
							feed_delay: Math.max(
								0,
								parseInt( v || '0', 10 ) || 0
							),
						} )
					}
				/>
				{ false !== ( data.modules || {} )[ 'smart-robots' ] && (
					<p className="zseo-muted">
						{ __(
							'Feeds are kept out of search results under Robots rules.',
							'zinn-seo'
						) }
					</p>
				) }
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}
