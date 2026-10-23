<?php
/**
 * Links from the plugin's screens to the step-by-step guides on zinnseo.com.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ONE map: a screen or a section's id => the guide page that explains it. A screen asks
 * `Guide::url( 'import' )` (PHP) or `guideUrl( 'import' )` (the admin app, from the same map sent
 * with the screen's data) and gets an address in the site's language; an id nobody registered
 * gets the guide's index, never a broken link.
 *
 * The links are plain links: they open in a new tab with `rel="noopener"`, carry no tracking
 * parameter, and exist only in wp-admin — nothing is added to a visitor's page.
 *
 * Each stage adds the ids of its screens to `slugs()` (or through the `zinn_seo_guide_slugs`
 * filter); `ZinnSeoP7Test` fails when an id has an empty slug.
 *
 * ⭐ zs-685: EVERY screen of the admin shell (each route in src/admin/index.js and the kit's own
 * overview, setup, plans, add-ons, help and feedback) has a row here under its route id, and the
 * shell's Help menu ("Guide to this screen") opens it in the admin's language (`kit_help()`).
 * `scripts/zinnseo-guide-links-check.py` (G003) fails when a screen has no row, so a new screen
 * cannot ship without its guide; G001/G002 prove the page exists on zinnseo.com.
 *
 * ⭐ Q270 (DOC1): a SCREEN's id opens its screen-by-screen guide (zinnseo.com
 * `kb/zinn-seo-<screen>-screen`, product-sites/scripts/zinnseo-screen-guides.py), which links that
 * screen's step-by-step task guides; a tab or section id keeps its task guide.
 *
 * ⭐ Q325: 23 of those screen guides were FOLDED into their how-to guide (DOC3, a section of its
 * page, product-sites/folded-guides.json); their old address only works through a 308. They are
 * linked straight at that section, `<how-to leaf>#<section>`; the guide-links check (G005)
 * fails when a row names a folded guide's own address again.
 */
final class Guide {

	/** The guide's site. */
	public const BASE = 'https://zinnseo.com/';

	/**
	 * The guides' index under a language: `https://zinnseo.com/kb`, `https://zinnseo.com/de/kb`
	 * (the site sends a language's visitors on to its own translated address).
	 */
	public const INDEX = 'kb';

	/**
	 * Screen or section id => the guide page's slug under `kb/`: ONE path segment (the site's
	 * guides are flat), lower-case letters, digits and hyphens; a folded guide adds `#<section>`.
	 *
	 * @return array<string, string>
	 */
	public static function slugs(): array {
		$slugs = array(
			// The admin shell's screens, by route id (zs-685; G003 checks every route has one).
			'overview'                => 'find-your-way-around-zinn-seo#overview-screen',
			'overview-howto'          => 'find-your-way-around-zinn-seo',
			'setup'                   => 'run-the-zinn-seo-guided-setup#guided-setup-screen',
			'setup-howto'             => 'run-the-zinn-seo-guided-setup',
			'fixit'                   => 'zinn-seo-fix-it-screen',
			'fixit-howto'             => 'fix-seo-problems-from-one-list',
			// AUTO1 (zs-767, zs-778): Fix it on autopilot, and every internal link fixed in one click.
			'autopilot'               => 'fix-the-whole-fix-it-list-on-autopilot#fix-it-on-autopilot-screen',
			'autopilot-howto'         => 'fix-the-whole-fix-it-list-on-autopilot',
			'linkfix'                 => 'fix-every-internal-link-in-one-click',
			// OUT1 (zs-779): the outbound links report (Links › Outbound links).
			'outbound'                => 'see-and-fix-every-outbound-link',
			'writing'                 => 'zinn-seo-writing-screen',
			'writing-howto'           => 'write-posts-with-the-ai-writer',
			'content'                 => 'zinn-seo-content-screen',
			'content-howto'           => 'use-focus-phrases-and-the-content-analysis',
			'links'                   => 'zinn-seo-links-screen',
			'links-howto'             => 'find-orphan-pages-and-add-internal-links',
			'linkplan'                => 'plan-internal-links-for-the-whole-site#link-plan-screen',
			'linkplan-howto'          => 'plan-internal-links-for-the-whole-site',
			'redirects'               => 'zinn-seo-redirects-and-404-screen',
			'redirects-howto'         => 'redirect-a-page-and-fix-404-errors',
			'protection'              => 'zinn-seo-protection-screen',
			'protection-howto'        => 'scan-your-site-for-seo-spam',
			'appearance'              => 'zinn-seo-search-appearance-screen',
			'appearance-howto'        => 'set-seo-title-and-description-templates',
			'content-types'           => 'choose-which-content-types-search-engines-see',
			'social'                  => 'zinn-seo-social-screen',
			'social-howto'            => 'set-the-image-and-text-for-social-sharing',
			'discovery'               => 'zinn-seo-sitemaps-and-ai-screen',
			'discovery-howto'         => 'check-your-xml-sitemap',
			'schema'                  => 'zinn-seo-structured-data-screen',
			'schema-howto'            => 'check-structured-data-on-a-page',
			'richresults'             => 'zinn-seo-rich-results-screen',
			'richresults-howto'       => 'get-rich-results-in-google-search',
			'blocks'                  => 'zinn-seo-seo-blocks-screen',
			'blocks-howto'            => 'add-a-quick-answer-block',
			'bulk'                    => 'zinn-seo-bulk-editor-screen',
			'bulk-howto'              => 'edit-seo-titles-and-descriptions-in-bulk',
			// SCH1 (zs-764, zs-765): structured data in the bulk editor, and filled by AI.
			'bulk-schema'             => 'set-structured-data-for-many-pages-at-once',
			'schema-fill'             => 'fill-faq-and-structured-data-with-ai',
			'works'                   => 'zinn-seo-works-with-screen',
			'works-howto'             => 'see-which-plugins-zinn-seo-works-with',
			'checks'                  => 'zinn-seo-rankings-and-checks-screen',
			'checks-howto'            => 'track-your-google-rankings',
			'services'                => 'order-zinn-services-from-wordpress#services-screen',
			'services-howto'          => 'order-zinn-services-from-wordpress',
			'guard'                   => 'zinn-seo-safe-updates-screen',
			'guard-howto'             => 'check-updates-before-they-reach-your-site',
			'referrals'               => 'see-visitors-from-chatgpt-and-other-ai-assistants#ai-visitors-and-earnings-screen',
			'referrals-howto'         => 'see-visitors-from-chatgpt-and-other-ai-assistants',
			'settings'                => 'zinn-seo-settings-screen',
			'settings-howto'          => 'choose-your-zinn-seo-settings',
			'roles'                   => 'give-a-colleague-seo-access#roles-screen',
			'roles-howto'             => 'give-a-colleague-seo-access',
			'tools'                   => 'zinn-seo-tools-screen',
			'tools-howto'             => 'copy-seo-settings-to-another-site',
			'transfer'                => 'copy-seo-settings-to-another-site#export-import-settings-screen',
			'transfer-howto'          => 'copy-seo-settings-to-another-site',
			'plans'                   => 'upgrade-to-zinn-seo-pro#plans-and-licence-screen',
			'plans-howto'             => 'upgrade-to-zinn-seo-pro',
			'addons'                  => 'upgrade-to-zinn-seo-pro#add-ons-screen',
			'addons-howto'            => 'upgrade-to-zinn-seo-pro',
			'help'                    => 'get-help-or-report-a-problem#get-help-screen',
			'help-howto'              => 'get-help-or-report-a-problem',
			'feedback'                => 'get-help-or-report-a-problem#feedback-screen',
			'feedback-howto'          => 'get-help-or-report-a-problem',
			// Clean-up, control and safety (stage X1A).
			'cleanup'                 => 'zinn-seo-clean-up-screen',
			'cleanup-howto'           => 'clean-up-your-site-for-search-engines',
			'cleanup-head'            => 'clean-up-your-site-for-search-engines',
			'cleanup-addresses'       => 'clean-up-your-site-for-search-engines',
			'cleanup-previews'        => 'clean-up-your-site-for-search-engines',
			'cleanup-robots'          => 'keep-thin-pages-out-of-search',
			'cleanup-feeds'           => 'protect-your-rss-feed-from-copying',
			'cleanup-comments'        => 'stop-comment-spam-without-a-captcha',
			'cleanup-images'          => 'fill-image-alt-text-automatically',
			'cleanup-conflicts'       => 'fix-seo-conflicts-with-your-theme-and-plugins',
			'features'                => 'switch-off-features-you-do-not-use',
			'version'                 => 'go-back-to-an-earlier-version',
			'server-rules'            => 'edit-htaccess-and-nginx-rules-safely',
			'link-updates'            => 'keep-internal-links-working-when-addresses-change',
			'schedule'                => 'schedule-seo-changes',
			'redirect-conditions'     => 'redirect-only-some-visitors',
			// GUIDE65 (0.17.66): the country condition's own guide (zs-781).
			'redirect-country'        => 'redirect-visitors-by-country',
			'snippets'                => 'control-what-search-results-quote',
			'primary-term'            => 'choose-a-posts-main-category',
			// Writing, structured data and integrations (stage X1C).
			'writer'                  => 'write-posts-with-the-ai-writer',
			// GUIDE65 (0.17.66): the Copywriter tab's guide (zs-782).
			'copywriter'              => 'write-anything-with-the-ai-copywriter',
			'writing-copywriter'      => 'write-anything-with-the-ai-copywriter',
			'planner'                 => 'plan-content-and-write-drafts-in-bulk',
			'headline'                => 'score-your-headlines-for-clicks',
			'keyword-lists'           => 'organise-keywords-in-lists',
			'proofread'               => 'check-spelling-grammar-and-tone',
			'content-tools'           => 'use-surfer-clearscope-or-frase-guidelines-in-wordpress',
			'originality'             => 'check-a-draft-for-copied-text',
			'image-generator'         => 'create-images-with-ai',
			'affiliates'              => 'manage-affiliate-links',
			'crosslinks'              => 'link-between-your-own-sites',
			'gdocs'                   => 'write-in-google-docs-and-send-to-wordpress',
			'news'                    => 'seo-for-news-publishers',
			'carousel'                => 'show-category-pages-as-carousels',
			'image-licence'           => 'get-the-licensable-badge-in-google-images',
			'dataset'                 => 'add-dataset-and-math-solver-structured-data',
			'merchant-policies'       => 'show-shop-returns-delivery-and-loyalty-in-search',
			'accordion-faq'           => 'turn-accordions-into-faq-questions',
			'search-plugins'          => 'improve-your-site-search-with-seo-data',
			'pickup'                  => 'offer-local-pickup-from-your-locations',
			'listings'                => 'seo-for-property-classifieds-and-rentals',
			'lms-quiz'                => 'show-quiz-questions-in-search',
			// Moving from another SEO plugin.
			'import'                  => 'move-from-yoast-rank-math-or-another-seo-plugin#move-from-another-plugin-screen',
			'import-howto'            => 'move-from-yoast-rank-math-or-another-seo-plugin',
			'import-compare'          => 'compare-pages-before-you-switch-seo-plugin',
			'import-undo'             => 'undo-a-move-from-another-seo-plugin',
			// The settings a move from another SEO plugin fills (IMP1, queue Q254).
			'breadcrumbs'             => 'change-the-breadcrumb-trail-wording',
			// RIV1 (gaps from The SEO Framework 5.2.0).
			'crumb-types'             => 'set-breadcrumb-rules-for-each-content-type',
			'placeholder-edit'        => 'edit-a-suggested-title-in-one-click',
			'pagination'              => 'control-page-2-and-previous-next-links',
			'knowledge-graph'         => 'tell-google-who-runs-your-site',
			'sharing-extras'          => 'fine-tune-what-x-and-facebook-show',
			// Working with other plugins.
			'integrations'            => 'see-which-plugins-zinn-seo-works-with',
			'local'                   => 'set-up-local-business-seo',
			'local-locations'         => 'add-business-locations-and-a-store-finder',
			'local-hours'             => 'set-your-opening-hours',
			'commerce'                => 'seo-for-online-shops',
			'commerce-delivery'       => 'show-delivery-and-returns-in-search-results',
			'commerce-stock'          => 'handle-sold-out-products',
			'commerce-feed'           => 'create-a-google-merchant-product-feed',
			'commerce-readiness'      => 'check-products-for-google-merchant-listings',
			'marketplace'             => 'seo-for-marketplaces',
			'marketplace-sellers'     => 'manage-seller-pages-in-search-results',
			'community'               => 'seo-for-forums-and-communities',
			'courses'                 => 'seo-for-online-courses',
			'members'                 => 'seo-for-memberships-and-paid-content',
			'network'                 => 'use-zinn-seo-on-a-multisite-network#zinn-seo-for-the-network-pro-screen',
			'network-howto'           => 'use-zinn-seo-on-a-multisite-network',
			'headless'                => 'use-zinn-seo-with-headless-wordpress',
			'builders'                => 'use-zinn-seo-with-your-page-builder',
			'languages'               => 'use-zinn-seo-with-translation-plugins',
			'author-page'             => 'set-up-author-pages',
			// Credit and the pay-per-use checks.
			'credits'                 => 'add-credit-and-see-what-checks-cost#credit-screen',
			'credits-howto'           => 'add-credit-and-see-what-checks-cost',
			'credits-connect'         => 'connect-your-site-to-a-zinn-account',
			'credits-notices'         => 'get-notices-and-emails-about-your-checks',
			'checks-rank'             => 'track-your-google-rankings',
			'checks-index'            => 'check-which-pages-google-has-indexed',
			'checks-ai'               => 'see-whether-ai-answers-mention-you',
			'checks-links'            => 'watch-your-backlinks',
			'checks-disavow'          => 'review-backlinks-and-make-a-disavow-file',
			'checks-audit'            => 'run-a-site-audit',
			'checks-keywords'         => 'find-keywords-worth-writing-for',
			'checks-gap'              => 'find-the-content-gap-with-a-competitor',
			'checks-local'            => 'track-local-rankings-town-by-town',
			'checks-mentions'         => 'watch-brand-mentions-and-copied-content',
			// Search performance (stage P11).
			'search'                  => 'zinn-seo-search-performance-screen',
			'search-howto'            => 'connect-google-search-console',
			'search-bing'             => 'connect-bing-webmaster-tools',
			'search-index'            => 'see-which-pages-google-has-indexed',
			'search-jobs'             => 'tell-google-about-job-and-livestream-pages',
			'search-speed'            => 'see-your-real-page-speed',
			'search-striking'         => 'win-searches-you-almost-rank-for',
			'search-decay'            => 'refresh-pages-losing-clicks',
			'search-topics'           => 'plan-content-with-the-topical-map',
			'search-timeline'         => 'see-which-changes-moved-rankings',
			'search-tests'            => 'test-seo-titles-and-descriptions',
			'search-analytics'        => 'see-revenue-from-search',
			'search-crawlers'         => 'see-crawler-visits-and-crawl-budget',
			'search-edge'             => 'use-zinn-seo-with-cloudflare',
			'search-business'         => 'manage-google-business-profile-apple-and-bing-listings',
			// Site authority (stage C2).
			'authority'               => 'zinn-seo-site-authority-screen',
			'authority-howto'         => 'track-domain-rating-and-authority-scores',
			// AI (stage P10).
			'ai'                      => 'zinn-seo-ai-screen',
			'ai-howto'                => 'use-ai-to-write-seo-titles-descriptions-and-alt-text',
			'ai-write'                => 'use-ai-to-write-seo-titles-descriptions-and-alt-text',
			'ai-settings'             => 'choose-your-own-ai-key-or-zinn-credits#ai-providers-screen',
			'ai-settings-howto'       => 'choose-your-own-ai-key-or-zinn-credits',
			'ai-redirects'            => 'redirect-missing-pages-with-ai',
			'ai-ask'                  => 'ask-your-seo-data-questions',
			'ai-brief'                => 'plan-a-new-page-with-an-ai-content-brief',
			'ai-competitor'           => 'see-why-a-competitor-outranks-you',
			'ai-ctr'                  => 'get-more-clicks-with-ai-titles',
			'ai-editor'               => 'use-ai-in-the-editor#seo-panel-in-the-editor-screen',
			'ai-editor-howto'         => 'use-ai-in-the-editor',
			// Stage P12: agencies and teams.
			'agency'                  => 'zinn-seo-agency-screen',
			'agency-howto'            => 'put-your-agency-brand-on-zinn-seo',
			'agency-clients'          => 'give-clients-their-own-seo-login',
			'agency-alerts'           => 'get-seo-alerts-in-slack-teams-discord-or-telegram',
			'reports'                 => 'send-branded-seo-reports-to-clients#client-reports-screen',
			'reports-howto'           => 'send-branded-seo-reports-to-clients',
			'tasks'                   => 'give-seo-tasks-to-your-team-and-clients#team-tasks-screen',
			'tasks-howto'             => 'give-seo-tasks-to-your-team-and-clients',
			'pages'                   => 'build-landing-pages-from-a-spreadsheet#pages-from-a-sheet-screen',
			'pages-howto'             => 'build-landing-pages-from-a-spreadsheet',
			// The research toolbox (stage C1).
			'toolbox'                 => 'zinn-seo-research-toolbox-screen',
			'toolbox-howto'           => 'use-the-research-toolbox',
			'toolbox-geo-grid'        => 'see-your-map-rank-street-by-street',
			'toolbox-reviews'         => 'read-and-answer-your-reviews',
			'toolbox-shopping'        => 'track-your-prices-on-google-shopping',
			'toolbox-amazon'          => 'track-your-amazon-rankings',
			'toolbox-youtube'         => 'track-your-youtube-rankings',
			'toolbox-traffic'         => 'see-a-competitors-traffic-and-top-pages',
			'toolbox-snapshot'        => 'keep-a-snapshot-of-the-search-results',
			'toolbox-sentiment'       => 'see-how-people-feel-about-your-brand',
			'toolbox-technology'      => 'see-what-a-website-is-built-with',
			'toolbox-questions'       => 'turn-people-also-ask-into-an-faq',
			'toolbox-brief'           => 'write-a-page-from-a-content-brief',
			'optimiser'               => 'optimise-a-post-against-the-pages-that-rank',
			// Late additions (stage X1B).
			'toolbox-domain-overview' => 'get-a-report-on-any-website',
			'toolbox-visibility'      => 'measure-your-share-of-search-visibility',
			'toolbox-trends'          => 'see-when-people-search-with-google-trends',
			'catalogue'               => 'see-zinn-prices-and-what-you-have#zinn-price-list-screen',
			'catalogue-howto'         => 'see-zinn-prices-and-what-you-have',
			'indexing'                => 'send-pages-to-search-engines-by-hand#indexing-screen',
			'indexing-howto'          => 'send-pages-to-search-engines-by-hand',
			'ai-referrals'            => 'see-visitors-from-chatgpt-and-other-ai-assistants',
			'ad-earnings'             => 'see-what-each-page-earns-from-ads',
			'safe-updates'            => 'check-updates-before-they-reach-your-site',
			'social-refresh'          => 'refresh-shared-links-on-facebook',
			// The Zinn® CDN (stage CDN1).
			'cdn'                     => 'speed-up-your-site-with-the-zinn-cdn#cdn-screen',
			'cdn-howto'               => 'speed-up-your-site-with-the-zinn-cdn',
			// Site care with AI (Pro, CARE1: zs-772 … zs-776).
			'care'                    => 'zinn-seo-site-care-screen',
			'care-howto'              => 'clean-up-malware-on-your-wordpress-site',
			'care-malware'            => 'clean-up-malware-on-your-wordpress-site',
			'care-errors'             => 'read-and-fix-your-php-error-logs',
			'care-database'           => 'clean-up-your-wordpress-database-safely',
			'care-fixes'              => 'approve-small-code-fixes-safely',
			// AI agents (MCP, stage P13).
			'agents'                  => 'let-ai-agents-improve-your-seo',
		);

		/**
		 * Filters the guide pages screens link to: screen or section id => slug under the
		 * guide. An add-on adds its own screens here.
		 *
		 * @param array<string, string> $slugs The map.
		 */
		$slugs = (array) apply_filters( 'zinn_seo_guide_slugs', $slugs );
		$out   = array();
		foreach ( $slugs as $id => $slug ) {
			// A guide FOLDED into another (a section of its page) is `<parent leaf>#<section>`.
			$parts = explode( '#', strtolower( (string) $slug ), 2 );
			$clean = array();
			foreach ( $parts as $part ) {
				$clean[] = trim( (string) preg_replace( '#[^a-z0-9\-]#', '', str_replace( '/', '-', trim( $part, '/ ' ) ) ), '-' );
			}
			$slug = $clean[0] . ( isset( $clean[1] ) && '' !== $clean[1] && '' !== $clean[0] ? '#' . $clean[1] : '' );
			if ( is_string( $id ) && '' !== $slug ) {
				$out[ $id ] = $slug;
			}
		}

		return $out;
	}

	/**
	 * The language part of a guide address for this site's admin ('' for English).
	 *
	 * @return string
	 */
	public static function language(): string {
		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
		$lang   = strtolower( (string) strtok( str_replace( '-', '_', $locale ), '_' ) );

		return 1 === preg_match( '/^[a-z]{2,3}$/', $lang ) && 'en' !== $lang ? $lang : '';
	}

	/**
	 * The guide's index in the site's language.
	 *
	 * @return string
	 */
	public static function index(): string {
		$lang = self::language();

		/**
		 * Filters the address of the guide's site (a white-label build points it at its own).
		 *
		 * @param string $base The site, with a trailing slash.
		 */
		$base = trailingslashit( esc_url_raw( (string) apply_filters( 'zinn_seo_guide_base', self::BASE ), array( 'https' ) ) );
		$base = '/' === $base ? self::BASE : $base;

		return $base . ( '' !== $lang ? $lang . '/' : '' ) . self::INDEX;
	}

	/**
	 * The guide page for a screen or section (the index when nobody registered the id).
	 *
	 * @param string $id Screen or section id.
	 * @return string
	 */
	public static function url( string $id ): string {
		$slug = self::slugs()[ $id ] ?? '';

		return self::index() . ( '' !== $slug ? '/' . $slug : '' );
	}

	/**
	 * A link to the guide, for screens printed by PHP.
	 *
	 * @param string $id   Screen or section id.
	 * @param string $text The link's words ('' for "Step-by-step guide").
	 * @return string HTML.
	 */
	public static function link( string $id, string $text = '' ): string {
		return '<a href="' . esc_url( self::url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $text ? $text : __( 'Step-by-step guide', 'zinn-seo' ) ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'zinn-seo' ) . '</span></a>';
	}

	/**
	 * Every id's guide address, in the admin's language.
	 *
	 * @return array<string, string>
	 */
	public static function links(): array {
		$index = self::index();
		$links = array();
		foreach ( self::slugs() as $id => $slug ) {
			$links[ $id ] = $index . '/' . $slug;
		}

		return $links;
	}

	/**
	 * The map for the admin app (sent with every screen's data).
	 *
	 * @param mixed $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data          = (array) $data;
		$data['guide'] = array(
			'index' => self::index(),
			'links' => self::links(),
		);

		return $data;
	}

	/**
	 * The admin shell's help links (zs-685): its Help menu's "Guide to this screen" reads
	 * `guides[ <route id> ]`, and falls back to `docsRoot`, so both come from this map, in the
	 * admin's language. Without it every screen linked the English guide index.
	 *
	 * @return array{docsRoot: string, guides: array<string, string>}
	 */
	public static function kit_help(): array {
		return array(
			'docsRoot' => self::index(),
			'guides'   => self::links(),
		);
	}
}
