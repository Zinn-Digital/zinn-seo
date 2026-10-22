/**
 * Plain functions of the AI agents tab (tested without a browser).
 */
import { __ } from '@wordpress/i18n';

/**
 * The write modes, as radio options.
 *
 * @return {Array<{label: string, value: string}>} Options.
 */
export function writeModes() {
	return [
		{
			label: __(
				'Make the change straight away (you can undo it in the change history)',
				'zinn-seo'
			),
			value: 'apply',
		},
		{
			label: __(
				'Wait for my approval on this screen before anything changes',
				'zinn-seo'
			),
			value: 'approve',
		},
		{
			label: __(
				'Never change anything: agents may only read and suggest',
				'zinn-seo'
			),
			value: 'off',
		},
	];
}

/**
 * One agent call's outcome, in words.
 *
 * @param {string} outcome running|done|error|refused|dry-run|queued.
 * @return {string} Words.
 */
export function outcomeText( outcome ) {
	switch ( outcome ) {
		case 'done':
			return __( 'Done', 'zinn-seo' );
		case 'queued':
			return __( 'Waiting for your approval', 'zinn-seo' );
		case 'dry-run':
			return __( 'Tried first, nothing changed', 'zinn-seo' );
		case 'refused':
			return __( 'Refused: changes are not allowed', 'zinn-seo' );
		case 'running':
			return __( 'Running', 'zinn-seo' );
		default:
			return __( 'Failed', 'zinn-seo' );
	}
}
