/**
 * A link to a step-by-step guide by its address (for screens that get one address rather than
 * the whole guide map: the redirect conditions and the editor's panels).
 */
import { __ } from '@wordpress/i18n';

/**
 * The link, or nothing when there is no address.
 *
 * @param {Object} props
 * @param {string} props.href The guide's address.
 * @return {Element|null} The link.
 */
export default function GuideAnchor( { href } ) {
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
			{ __( 'Step-by-step guide', 'zinn-seo' ) }
			<span className="screen-reader-text">
				{ ' ' }
				{ __( '(opens in a new tab)', 'zinn-seo' ) }
			</span>
			<span aria-hidden="true"> ↗</span>
		</a>
	);
}

/**
 * A guide's address from the editor's data.
 *
 * @param {string} id Screen or section id.
 * @return {string} Address, or ''.
 */
export function editorGuide( id ) {
	return String(
		( ( window.zinnSeoEditor || {} ).guides || {} )[ id ] || ''
	);
}
