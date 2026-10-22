/**
 * The words for things Zinn® names by code: checks, units, notices, states.
 */
import { __ } from '@wordpress/i18n';

/**
 * A check's name, by its product code at Zinn® (`kind`) or by its id here.
 *
 * @param {string} code Kind or tool id.
 * @return {string} Name.
 */
export function toolName( code ) {
	const names = {
		rank: __( 'Rank tracking', 'zinn-seo' ),
		index: __( 'Google index check', 'zinn-seo' ),
		ai: __( 'AI answers', 'zinn-seo' ),
		ai_visibility: __( 'AI answers', 'zinn-seo' ),
		links: __( 'Backlink watch', 'zinn-seo' ),
		link_watch: __( 'Backlink watch', 'zinn-seo' ),
		backlinks: __( 'Backlink monitoring', 'zinn-seo' ),
		audit: __( 'Site audit', 'zinn-seo' ),
		onpage: __( 'Site audit', 'zinn-seo' ),
		keywords: __( 'Keyword research', 'zinn-seo' ),
		keyword_research: __( 'Keyword research', 'zinn-seo' ),
		competitors: __( 'Competitor report', 'zinn-seo' ),
		domain: __( 'Domain report', 'zinn-seo' ),
		domain_score: __( 'Domain report', 'zinn-seo' ),
		brand_mentions: __( 'Brand mentions', 'zinn-seo' ),
		local_rank: __( 'Local rankings', 'zinn-seo' ),
		writing: __( 'Originality check', 'zinn-seo' ),
	};
	return names[ code ] || code;
}

/**
 * What one unit of a price is, as "per …".
 *
 * @param {string} unit Unit code.
 * @return {string} Words.
 */
export function unitEach( unit ) {
	const each = {
		rank_check: __( 'per phrase, each check', 'zinn-seo' ),
		index_check: __( 'per page', 'zinn-seo' ),
		ai_prompt_run: __( 'per question, across the AI engines', 'zinn-seo' ),
		link_check: __( 'per link, each check', 'zinn-seo' ),
		audit_page: __( 'per page read', 'zinn-seo' ),
		keyword_list: __( 'per list of phrases', 'zinn-seo' ),
		domain_report: __( 'per report', 'zinn-seo' ),
		geo_point: __( 'per map point', 'zinn-seo' ),
		review_read: __( 'per review site, each check', 'zinn-seo' ),
		shelf_check: __( 'per product, each check', 'zinn-seo' ),
		youtube_check: __( 'per phrase, each check', 'zinn-seo' ),
		serp_snapshot: __( 'per results page', 'zinn-seo' ),
		traffic_report: __( 'per website', 'zinn-seo' ),
		tech_lookup: __( 'per website', 'zinn-seo' ),
		sentiment_check: __( 'per brand or phrase, each check', 'zinn-seo' ),
		question_set: __( 'per phrase', 'zinn-seo' ),
		content_brief: __( 'per brief', 'zinn-seo' ),
		originality_phrase: __( 'per sentence searched', 'zinn-seo' ),
		trends_check: __( 'per check of up to five searches', 'zinn-seo' ),
	};
	return each[ unit ] || '';
}

/**
 * What a unit buys, for the price list.
 *
 * @param {string} unit Unit code.
 * @return {string} Words.
 */
export function unitName( unit ) {
	const names = {
		rank_check: __( 'Check where one phrase ranks', 'zinn-seo' ),
		index_check: __( 'Check one page in Google’s index', 'zinn-seo' ),
		ai_prompt_run: __(
			'Ask the AI engines one question about your business',
			'zinn-seo'
		),
		link_check: __( 'Check one backlink is still there', 'zinn-seo' ),
		audit_page: __( 'Read one page in a site audit', 'zinn-seo' ),
		keyword_list: __( 'One list of phrases worth writing for', 'zinn-seo' ),
		domain_report: __( 'One competitor or domain report', 'zinn-seo' ),
		geo_point: __( 'Check your map position from one point', 'zinn-seo' ),
		review_read: __(
			'Read your latest reviews on one review site',
			'zinn-seo'
		),
		shelf_check: __(
			'Check one product on Google Shopping or Amazon',
			'zinn-seo'
		),
		youtube_check: __(
			'Check where your videos rank for one phrase',
			'zinn-seo'
		),
		serp_snapshot: __(
			'Keep a copy and a picture of one results page',
			'zinn-seo'
		),
		traffic_report: __(
			'See one website’s traffic and top pages',
			'zinn-seo'
		),
		tech_lookup: __( 'See what one website is built with', 'zinn-seo' ),
		sentiment_check: __(
			'See how people feel about one brand',
			'zinn-seo'
		),
		question_set: __(
			'Collect the questions people ask about one phrase',
			'zinn-seo'
		),
		content_brief: __(
			'One content brief from the top ten results',
			'zinn-seo'
		),
		originality_phrase: __(
			'Search the web for one sentence of a draft',
			'zinn-seo'
		),
		trends_check: __(
			'See when people search, for up to five searches',
			'zinn-seo'
		),
	};
	return names[ unit ] || unit;
}

/**
 * The tag on a notice.
 *
 * @param {string} kind Notice kind.
 * @return {string} Words.
 */
export function noticeKind( kind ) {
	const kinds = {
		low_balance: __( 'Credit', 'zinn-seo' ),
		forecast_short: __( 'Credit', 'zinn-seo' ),
		credit_hold: __( 'Paused', 'zinn-seo' ),
		credit_resumed: __( 'Resumed', 'zinn-seo' ),
		rank_drop: __( 'Rankings', 'zinn-seo' ),
		link_lost: __( 'Backlinks', 'zinn-seo' ),
		deindexed: __( 'Google index', 'zinn-seo' ),
		ai_mention_lost: __( 'AI answers', 'zinn-seo' ),
		ai_mention_gained: __( 'AI answers', 'zinn-seo' ),
		brand_mention: __( 'Mentions', 'zinn-seo' ),
		guard_incident: __( 'Problem found', 'zinn-seo' ),
		guard_resolved: __( 'Fixed', 'zinn-seo' ),
		metric_change: __( 'Authority', 'zinn-seo' ),
		data_paused: __( 'Authority', 'zinn-seo' ),
		review_new: __( 'Reviews', 'zinn-seo' ),
		review_rating_drop: __( 'Reviews', 'zinn-seo' ),
	};
	return kinds[ kind ] || __( 'Notice', 'zinn-seo' );
}

export { engineName } from './pure';

/**
 * A run's state.
 *
 * @param {string} status running|complete|partial|failed.
 * @return {string} Words.
 */
export function runStatus( status ) {
	const words = {
		running: __( 'Running…', 'zinn-seo' ),
		complete: __( 'Finished', 'zinn-seo' ),
		partial: __( 'Finished in part', 'zinn-seo' ),
		failed: __( 'Did not finish', 'zinn-seo' ),
		queued: __( 'Running…', 'zinn-seo' ),
	};
	return words[ status ] || status;
}
