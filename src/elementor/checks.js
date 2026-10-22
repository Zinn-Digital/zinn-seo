/**
 * The title and description checks shown in Elementor's SEO panel (pure, unit-tested).
 */
import { __, sprintf } from '@wordpress/i18n';

/** Lengths search engines show before cutting, in characters. */
export const TITLE_MAX = 60;
export const DESC_MIN = 70;
export const DESC_MAX = 160;

/**
 * Checks: `[ { level: good|warn|bad, text } ]`.
 *
 * @param {Object}  v         Values.
 * @param {string}  v.title   Title.
 * @param {string}  v.desc    Description.
 * @param {boolean} v.noindex Hidden from search engines.
 * @return {Array<Object>} Checks.
 */
export function checks( { title, desc, noindex } ) {
	const out = [];
	const t = ( title || '' ).trim().length;
	const d = ( desc || '' ).trim().length;
	if ( noindex ) {
		out.push( {
			level: 'bad',
			text: __(
				'This page is hidden from search engines (noindex).',
				'zinn-seo'
			),
		} );
	}
	if ( ! t ) {
		out.push( { level: 'bad', text: __( 'No title.', 'zinn-seo' ) } );
	} else if ( t > TITLE_MAX ) {
		out.push( {
			level: 'warn',
			text: sprintf(
				/* translators: 1: characters, 2: the most shown. */
				__(
					'The title has %1$d characters; search results show about %2$d.',
					'zinn-seo'
				),
				t,
				TITLE_MAX
			),
		} );
	} else {
		out.push( {
			level: 'good',
			text: sprintf(
				/* translators: %d: characters. */
				__( 'Title length is good (%d characters).', 'zinn-seo' ),
				t
			),
		} );
	}
	if ( ! d ) {
		out.push( {
			level: 'warn',
			text: __(
				'No description: search engines will pick text from the page.',
				'zinn-seo'
			),
		} );
	} else if ( d < DESC_MIN || d > DESC_MAX ) {
		out.push( {
			level: 'warn',
			text: sprintf(
				/* translators: 1: characters, 2: fewest advised, 3: most advised. */
				__(
					'The description has %1$d characters; %2$d to %3$d show best.',
					'zinn-seo'
				),
				d,
				DESC_MIN,
				DESC_MAX
			),
		} );
	} else {
		out.push( {
			level: 'good',
			text: sprintf(
				/* translators: %d: characters. */
				__( 'Description length is good (%d characters).', 'zinn-seo' ),
				d
			),
		} );
	}
	return out;
}
