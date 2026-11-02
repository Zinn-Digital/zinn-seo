/**
 * Cookie consent banner with Google Consent Mode v2 (zs-783).
 */
import { __ } from '@wordpress/i18n';
import { PanelBody } from '@wordpress/components';

import { SaveBar, Switch } from './shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.form The settings draft.
 * @return {Element} Tab.
 */
export default function ConsentTab( { form } ) {
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Visitors are asked before analytics and advertising cookies are used, each as its own choice, and Google tags (Analytics, Ads, Tag Manager) are told the answer through Consent Mode: everything is denied until the visitor chooses, and the choice is remembered for 180 days.',
					'zinn-seo'
				) }
			</p>
			<PanelBody title={ __( 'The banner', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="consent_ads"
					label={ __( 'Ask about advertising too', 'zinn-seo' ) }
					help={ __(
						'Switch off if the site shows no advertising: the banner then asks about analytics only, and advertising stays denied.',
						'zinn-seo'
					) }
				/>
				<Switch
					form={ form }
					name="consent_reopen"
					label={ __(
						'Show a small "Privacy choices" button after a choice',
						'zinn-seo'
					) }
					help={ __(
						'Visitors can change their mind at any time. Without the button, add a link yourself.',
						'zinn-seo'
					) }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Your own link', 'zinn-seo' ) }>
				<p className="zseo-muted">
					{ __(
						'Any link to the address below, for example a menu item, re-opens the banner. In a page or widget you can also use the shortcode.',
						'zinn-seo'
					) }
				</p>
				<p>
					<code dir="ltr">#site-consent</code>{ ' ' }
					<code dir="ltr">[zinn_seo_consent_link]</code>
				</p>
				<p className="zseo-muted">
					{ __(
						'The banner links to the privacy policy page chosen under Settings, Privacy in WordPress.',
						'zinn-seo'
					) }
				</p>
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}
