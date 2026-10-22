/**
 * Bing's crawl problem names in plain words (zs-743).
 */
import { __ } from '@wordpress/i18n';

/**
 * A crawl problem's words.
 *
 * @param {string} issue Problem id (`Bing_Data::ISSUES`).
 * @return {string} Words.
 */
export function bingIssueName( issue ) {
	const names = {
		code301: __( 'Moved permanently (301)', 'zinn-seo' ),
		code302: __( 'Moved for now (302)', 'zinn-seo' ),
		code4xx: __( 'Page not found or refused (4xx)', 'zinn-seo' ),
		code5xx: __( 'Server error (5xx)', 'zinn-seo' ),
		blocked_by_robots: __( 'Blocked by robots.txt', 'zinn-seo' ),
		contains_malware: __( 'Malware found', 'zinn-seo' ),
		important_url_blocked: __( 'An important page is blocked', 'zinn-seo' ),
		dns_errors: __( 'DNS error', 'zinn-seo' ),
		timeout_errors: __( 'Timed out', 'zinn-seo' ),
		connection_errors: __( 'Could not connect', 'zinn-seo' ),
		content_too_big: __( 'Page too large', 'zinn-seo' ),
		not_ok: __( 'Other error', 'zinn-seo' ),
		missing_title: __( 'No title', 'zinn-seo' ),
		missing_description: __( 'No description', 'zinn-seo' ),
		unsupported_language: __( 'Language not supported', 'zinn-seo' ),
	};
	return names[ issue ] || issue;
}
