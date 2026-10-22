/**
 * The warning before a change to many pages at once (HARDEN1, from zinnhub: one link-plan apply
 * saved 2,403 pages and other plugins queued ~13,900 pieces of work for them).
 */
import { _n, sprintf } from '@wordpress/i18n';

/** Pages from which a bulk change asks first. */
export const BULK_WARN_PAGES = 100;

/**
 * The question asked before a bulk change of `pages` pages.
 *
 * @param {number} pages Pages the change will update.
 * @return {string} Question.
 */
export function bulkMessage( pages ) {
	return sprintf(
		/* translators: %d: number of pages a bulk change will update. */
		_n(
			'This will update %d page. Other plugins may queue their own work for each page they see change. Go on?',
			'This will update %d pages. Other plugins may queue their own work for each page they see change. Go on?',
			pages,
			'zinn-seo'
		),
		pages
	);
}

/**
 * Whether to go on with a change of `pages` pages: yes at once below BULK_WARN_PAGES, else the
 * person's answer.
 *
 * @param {number}                       pages Pages the change will update (unknown: 0).
 * @param {(message: string) => boolean} ask   Asks the person (window.confirm).
 * @return {boolean} Go on.
 */
// eslint-disable-next-line no-alert -- the browser's own question before a change to many pages, as the Update Guard's.
export function confirmBulk( pages, ask = ( m ) => window.confirm( m ) ) {
	const n = Number( pages ) || 0;
	if ( n < BULK_WARN_PAGES ) {
		return true;
	}
	return !! ask( bulkMessage( n ) );
}
