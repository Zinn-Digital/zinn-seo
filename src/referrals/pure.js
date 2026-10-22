/**
 * AI visitors without React.
 */
import { __ } from '@wordpress/i18n';

/**
 * An assistant's name.
 *
 * @param {string} id Assistant id.
 * @return {string} Name.
 */
export function assistantName( id ) {
	const names = {
		chatgpt: 'ChatGPT',
		perplexity: 'Perplexity',
		gemini: 'Gemini',
		copilot: 'Copilot',
		claude: 'Claude',
		deepseek: 'DeepSeek',
		meta_ai: 'Meta AI',
	};
	return names[ id ] || __( 'Other assistant', 'zinn-seo' );
}

/**
 * Visits by assistant, most first. Google Analytics wins when connected (it already sees the
 * visits this server counted, so the two are never added together).
 *
 * @param {Object|null} ga4    GA4 summary (`by_assistant`).
 * @param {Object|null} server This server's summary.
 * @return {Array<{assistant: string, visits: number}>} Rows.
 */
export function merge( ga4, server ) {
	const by =
		( ga4 && ga4.by_assistant ) || ( server && server.by_assistant ) || {};
	return Object.keys( by )
		.map( ( assistant ) => ( {
			assistant,
			visits: Number( by[ assistant ] ),
		} ) )
		.sort( ( a, b ) => b.visits - a.visits );
}
