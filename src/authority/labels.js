/**
 * The words for sources, scores and units, in the reader's language. A score's own brand name
 * (Domain Rating, Trust Flow, Domain Authority, Spam Score, Authority Score, Domain Trust, Open
 * PageRank) is the name the reader sees in that company's own tool, so it stays as it is; every
 * other word is translated here — never shown in English from the API.
 */
import { __, sprintf } from '@wordpress/i18n';

/** Company names: brands, never translated. */
const BRANDS = {
	ahrefs: 'Ahrefs',
	majestic: 'Majestic',
	moz: 'Moz',
	semrush: 'Semrush',
	se_ranking: 'SE Ranking',
	openpagerank: 'Open PageRank',
};

/**
 * A company's own name (a brand, never translated).
 *
 * @param {string} key      Source key.
 * @param {string} fallback The API's name.
 * @return {string} Name.
 */
export function brandName( key, fallback = '' ) {
	return BRANDS[ key === 'ahrefs_free' ? 'ahrefs' : key ] || fallback || key;
}

/**
 * A score's name.
 *
 * @param {string} key      Metric key.
 * @param {string} fallback The API's name.
 * @return {string} Name.
 */
export function metricName( key, fallback = '' ) {
	// A score's own brand name stays as the company writes it.
	const names = {
		referring_domains: __( 'Referring domains', 'zinn-seo' ),
		backlinks: __( 'Backlinks', 'zinn-seo' ),
		organic_traffic: __( 'Visits from search (estimate)', 'zinn-seo' ),
		organic_keywords: __( 'Searches you rank for', 'zinn-seo' ),
		ahrefs_rank: __( 'Ahrefs Rank (lower is better)', 'zinn-seo' ),
		semrush_rank: __( 'Semrush Rank (lower is better)', 'zinn-seo' ),
		opr_rank: __( 'Open PageRank position (lower is better)', 'zinn-seo' ),
		zinn_rank: __( 'Zinn® Authority score', 'zinn-seo' ),
		topical_trust_flow: __( 'Topical Trust Flow (main topic)', 'zinn-seo' ),
	};
	return names[ key ] || fallback || key;
}

/**
 * A source's name.
 *
 * @param {string} key      Source key.
 * @param {string} fallback The API's name.
 * @return {string} Name.
 */
export function sourceName( key, fallback = '' ) {
	const names = {
		ahrefs_free: __( 'Ahrefs — free Domain Rating key', 'zinn-seo' ),
		ahrefs: __( 'Ahrefs — your API key', 'zinn-seo' ),
		zinn_authority: __( 'Zinn® Authority', 'zinn-seo' ),
	};
	return names[ key ] || fallback || key;
}

/**
 * The unit a company counts your allowance in.
 *
 * @param {string} key Source key.
 * @return {string} Unit.
 */
export function unitName( key ) {
	const units = {
		ahrefs_free: __( 'calls', 'zinn-seo' ),
		ahrefs: __( 'API units', 'zinn-seo' ),
		majestic: __( 'index item units', 'zinn-seo' ),
		moz: __( 'rows', 'zinn-seo' ),
		semrush: __( 'API units', 'zinn-seo' ),
		se_ranking: __( 'credits', 'zinn-seo' ),
		openpagerank: __( 'domains', 'zinn-seo' ),
		zinn_authority: __( 'checks', 'zinn-seo' ),
	};
	return units[ key ] || '';
}

/**
 * What a source needs, plainly, when its API is a paid product.
 *
 * @param {string} key Source key.
 * @return {string} Note ('' when none).
 */
export function planNote( key ) {
	const notes = {
		ahrefs: __(
			'API access comes with Ahrefs’ Enterprise plan or as an add-on.',
			'zinn-seo'
		),
		majestic: __( 'Needs a Majestic API subscription.', 'zinn-seo' ),
		moz: __(
			'Moz’s free API level includes a small number of rows each month.',
			'zinn-seo'
		),
		semrush: __( 'API access needs a Semrush Business plan.', 'zinn-seo' ),
		se_ranking: __(
			'Needs an SE Ranking Data API credit package.',
			'zinn-seo'
		),
	};
	return notes[ key ] || '';
}

/**
 * The line that credits the company whose number it is (a licence term for Ahrefs).
 *
 * @param {string} key      Source key.
 * @param {string} fallback The API's line.
 * @return {string} Line.
 */
export function attributionText( key, fallback = '' ) {
	// "Domain Rating by Ahrefs" is the exact wording Ahrefs' licence asks for: never translated.
	if ( key === 'ahrefs_free' ) {
		return fallback || 'Domain Rating by Ahrefs';
	}
	if ( key === 'zinn_authority' ) {
		return __( 'Zinn® Authority, from Zinn® backlink data', 'zinn-seo' );
	}
	return BRANDS[ key ]
		? sprintf(
				/* translators: %s: a company name, e.g. "Majestic". */
				__( 'Data by %s', 'zinn-seo' ),
				BRANDS[ key ]
			)
		: fallback;
}
