/**
 * The words for what a site audit finds: what it is, and what to do about it.
 */
import { __ } from '@wordpress/i18n';

/**
 * A finding's words.
 *
 * @param {string} code The finding's code.
 * @return {{title: string, fix: string}} Words.
 */
export function issueWords( code ) {
	const words = {
		broken_links: {
			title: __( 'Links that lead nowhere', 'zinn-seo' ),
			fix: __(
				'Visitors and Google hit a dead end. Point each link at a page that exists, or remove it.',
				'zinn-seo'
			),
		},
		broken_resources: {
			title: __( 'Images or files that do not load', 'zinn-seo' ),
			fix: __(
				'The page asks for an image, script or stylesheet that is missing. Upload it again or remove the reference.',
				'zinn-seo'
			),
		},
		links_relation_conflict: {
			title: __( 'Links marked both followed and nofollow', 'zinn-seo' ),
			fix: __(
				'The same page is linked with and without nofollow, so search engines get mixed signals. Choose one.',
				'zinn-seo'
			),
		},
		duplicate_title_tag: {
			title: __( 'Pages sharing the same title', 'zinn-seo' ),
			fix: __(
				'Google cannot tell these pages apart in its results. Give each page its own title.',
				'zinn-seo'
			),
		},
		no_title: {
			title: __( 'Pages with no title', 'zinn-seo' ),
			fix: __(
				'The title is the headline of your search result. Add one to each page.',
				'zinn-seo'
			),
		},
		no_description: {
			title: __( 'Pages with no description', 'zinn-seo' ),
			fix: __(
				'Without one Google writes its own snippet. Add a description that makes people want to click.',
				'zinn-seo'
			),
		},
		duplicate_description: {
			title: __( 'Pages sharing the same description', 'zinn-seo' ),
			fix: __(
				'Write a description for each page that says what is on that page.',
				'zinn-seo'
			),
		},
		duplicate_content: {
			title: __( 'Pages with the same content', 'zinn-seo' ),
			fix: __(
				'Two pages competing with the same text weaken each other. Merge them, or point one at the other with a canonical address.',
				'zinn-seo'
			),
		},
		no_h1_tag: {
			title: __( 'Pages with no main heading', 'zinn-seo' ),
			fix: __(
				'Every page needs one main heading that says what it is about.',
				'zinn-seo'
			),
		},
		title_too_long: {
			title: __( 'Titles too long to show in full', 'zinn-seo' ),
			fix: __(
				'Google cuts these off. Put the important words first and shorten the rest.',
				'zinn-seo'
			),
		},
		title_too_short: {
			title: __( 'Titles too short to say much', 'zinn-seo' ),
			fix: __(
				'A few more words tell searchers why this page is the one to open.',
				'zinn-seo'
			),
		},
		small_page_size: {
			title: __( 'Pages with almost nothing on them', 'zinn-seo' ),
			fix: __(
				'Thin pages rarely rank. Add useful content, or remove the page.',
				'zinn-seo'
			),
		},
		low_content_rate: {
			title: __( 'Pages with little text for their size', 'zinn-seo' ),
			fix: __(
				'Most of the page is code, not words. Add content people came for.',
				'zinn-seo'
			),
		},
		no_image_alt: {
			title: __( 'Images with no description', 'zinn-seo' ),
			fix: __(
				'A short description helps people using screen readers and helps the image appear in image search.',
				'zinn-seo'
			),
		},
		no_favicon: {
			title: __( 'No site icon', 'zinn-seo' ),
			fix: __(
				'The small icon shows next to your site in search results and browser tabs. Add one under Settings → General.',
				'zinn-seo'
			),
		},
		canonical_to_broken: {
			title: __(
				'Canonical addresses that point at a missing page',
				'zinn-seo'
			),
			fix: __(
				'The page tells Google its real address is one that does not exist, so it may drop out of search. Correct the canonical address.',
				'zinn-seo'
			),
		},
		canonical_chain: {
			title: __(
				'Canonical addresses that pass through another',
				'zinn-seo'
			),
			fix: __(
				'Point each page straight at its final address.',
				'zinn-seo'
			),
		},
		is_redirect: {
			title: __( 'Addresses that redirect', 'zinn-seo' ),
			fix: __(
				'Not a problem by themselves. Link straight to the final address where you can, so pages load faster.',
				'zinn-seo'
			),
		},
		is_4xx_code: {
			title: __( 'Pages that cannot be found', 'zinn-seo' ),
			fix: __(
				'These addresses answer “not found”. Restore the page, or redirect the address to the closest one that exists.',
				'zinn-seo'
			),
		},
		is_5xx_code: {
			title: __( 'Pages that fail with a server error', 'zinn-seo' ),
			fix: __(
				'The server could not show these pages. Open one to see the error; your host can help if it persists.',
				'zinn-seo'
			),
		},
		is_orphan_page: {
			title: __( 'Pages nothing links to', 'zinn-seo' ),
			fix: __(
				'Visitors and Google can only find these by accident. Link to them from a related page or a menu.',
				'zinn-seo'
			),
		},
		no_content_encoding: {
			title: __( 'Pages sent without compression', 'zinn-seo' ),
			fix: __(
				'Compressed pages load faster. Your host or a caching plugin can switch it on.',
				'zinn-seo'
			),
		},
		https_to_http_links: {
			title: __( 'Secure pages linking to insecure ones', 'zinn-seo' ),
			fix: __(
				'Change these links from http:// to https://.',
				'zinn-seo'
			),
		},
		non_indexable: {
			title: __( 'Pages Google is told not to index', 'zinn-seo' ),
			fix: __(
				'Fine when you mean it. If a page here should be found in search, switch “hide from search engines” off for it.',
				'zinn-seo'
			),
		},
		irrelevant_description: {
			title: __( 'Descriptions that do not match the page', 'zinn-seo' ),
			fix: __(
				'Google tends to replace these. Describe what is actually on the page.',
				'zinn-seo'
			),
		},
		irrelevant_title: {
			title: __( 'Titles that do not match the page', 'zinn-seo' ),
			fix: __(
				'Use the words the page itself uses, so searchers get what the title promised.',
				'zinn-seo'
			),
		},
		deprecated_html_tags: {
			title: __( 'Outdated HTML', 'zinn-seo' ),
			fix: __(
				'Old tags still work in most browsers; update the theme or the content when convenient.',
				'zinn-seo'
			),
		},
		has_render_blocking_resources: {
			title: __( 'Files that delay the page showing', 'zinn-seo' ),
			fix: __(
				'Scripts or styles hold the page back. A caching or performance plugin can defer them.',
				'zinn-seo'
			),
		},
		redirect_chain: {
			title: __(
				'Redirects that pass through several steps',
				'zinn-seo'
			),
			fix: __(
				'Each hop slows the visitor down. Redirect straight to the final address.',
				'zinn-seo'
			),
		},
		sitemap_url_not_indexable: {
			title: __(
				'Sitemap lists pages Google is told not to index',
				'zinn-seo'
			),
			fix: __(
				'A sitemap should list only pages you want found. Remove these from it, or allow them to be indexed.',
				'zinn-seo'
			),
		},
		onpage_clean: {
			title: __( 'No problems found', 'zinn-seo' ),
			fix: __( 'Every page that was read is in good shape.', 'zinn-seo' ),
		},
	};
	return (
		words[ code ] || {
			title: String( code || '' ).replace( /_/g, ' ' ),
			fix: '',
		}
	);
}

/**
 * Why a watched link is not as expected.
 *
 * @param {string} problem The problem's code.
 * @return {string} Words.
 */
export function linkProblem( problem ) {
	const words = {
		page_gone: __( 'The page that linked to you is gone', 'zinn-seo' ),
		page_unreadable: __(
			'The page could not be opened this time',
			'zinn-seo'
		),
		link_missing: __( 'The page no longer links to your site', 'zinn-seo' ),
		link_moved: __(
			'The page links to you, but not to the page you chose',
			'zinn-seo'
		),
		now_nofollow: __(
			'The link is now nofollow, so it passes no authority',
			'zinn-seo'
		),
		anchor_changed: __( 'The link text changed', 'zinn-seo' ),
	};
	return words[ problem ] || '';
}

/**
 * Why a backlink got its verdict.
 *
 * @param {string} reason Reason code.
 * @return {string} Words.
 */
export function verdictReason( reason ) {
	const words = {
		spam_high: __( 'very high spam score', 'zinn-seo' ),
		spam_raised: __( 'raised spam score', 'zinn-seo' ),
		spam_words: __( 'spam words in the link text', 'zinn-seo' ),
		nofollow: __( 'nofollow, so Google already ignores it', 'zinn-seo' ),
	};
	return words[ reason ] || reason;
}
