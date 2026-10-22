import { __ } from '@wordpress/i18n';

import { guideUrl } from './pure';

/**
 * A link to the step-by-step guide for a screen or a section. Opens in a new tab; nothing is
 * sent with it (no tracking parameters). Shows nothing when the site sent no guide address.
 *
 * @param {Object} props
 * @param {Object} props.data     Boot data.
 * @param {string} props.id       Screen or section id (see `Guide::slugs()` in PHP).
 * @param {string} props.children The link's words (default: "Step-by-step guide").
 * @return {Element|null} The link.
 */
export default function GuideLink( { data, id, children } ) {
	const href = guideUrl( data, id );
	if ( ! href ) {
		return null;
	}
	return (
		<a
			className="zseo-guide"
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ children || __( 'Step-by-step guide', 'zinn-seo' ) }
			<span className="screen-reader-text">
				{ ' ' }
				{ __( '(opens in a new tab)', 'zinn-seo' ) }
			</span>
			<span aria-hidden="true"> ↗</span>
		</a>
	);
}
