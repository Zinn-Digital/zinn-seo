<?php
/**
 * The words each offer says (translated; prices come from Zinn®, never from here).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Offers;

use ZinnDigital\ZinnSEO\AdminKit\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy rule (owner, 2026-10-10): sell the BENEFIT in plain words, no internal process talk. Each
 * offer has two headlines (A and B, fixed per site) and one body; the price line is drawn by the
 * screen from the live price Zinn® sent.
 */
final class Copy {

	/**
	 * An offer's words.
	 *
	 * @param string $offer   Offer key.
	 * @param string $variant `a` or `b`.
	 * @return array{title: string, body: string, cta: string, note: string}|null
	 */
	public static function offer( string $offer, string $variant ): ?array {
		$b    = 'b' === $variant;
		$note = '';
		switch ( $offer ) {
			case 'links':
				$title = $b ? __( 'Get links to this page from real websites', 'zinn-seo' ) : __( 'Help this page climb with links from other sites', 'zinn-seo' );
				$body  = __( 'Choose a guest post or link placement from sites in your niche, with your page and link text filled in for you. We check the link is live and keep watching it.', 'zinn-seo' );
				$cta   = __( 'See link offers', 'zinn-seo' );
				break;
			case 'content':
				$title = $b ? __( 'Have this article written for you', 'zinn-seo' ) : __( 'Get a better article for this page from a writer', 'zinn-seo' );
				$body  = __( 'Order an article from a professional writer. Your brief goes with the order, so the writer knows what the page needs to cover.', 'zinn-seo' );
				$cta   = __( 'See writers', 'zinn-seo' );
				break;
			case 'domains':
				$title = $b ? __( 'Find the right domain name', 'zinn-seo' ) : __( 'Register a domain, or buy an aged one with history', 'zinn-seo' );
				$body  = __( 'Search and register a new name, or choose an aged domain with its authority, trust and age shown. It appears in your Zinn® account straight away.', 'zinn-seo' );
				$cta   = __( 'Search domains', 'zinn-seo' );
				break;
			case 'wayback':
				$title = $b ? __( 'Bring back the old site at these addresses', 'zinn-seo' ) : __( 'Rebuild the old site from the web archive', 'zinn-seo' );
				$body  = __( 'People and links still ask for pages that are gone. Restore the old site from the Wayback Machine on Zinn® hosting, so those links count for you again.', 'zinn-seo' );
				$cta   = __( 'Restore with Zinn®', 'zinn-seo' );
				break;
			case 'vetting':
				$title = $b ? __( 'Check a domain before you buy it', 'zinn-seo' ) : __( 'Know a domain’s history before you pay for it', 'zinn-seo' );
				$body  = __( 'One report shows spam history, archive snapshots, whether Google indexes it, penalties and its links, so you never buy a burned domain.', 'zinn-seo' );
				$cta   = __( 'Vet a domain', 'zinn-seo' );
				break;
			case 'reputation':
				$title = $b ? __( 'Never miss a bad review again', 'zinn-seo' ) : __( 'Watch what customers say about you', 'zinn-seo' );
				$body  = __( 'Your rating and newest reviews from Google, Trustpilot and Tripadvisor in one place, checked every week, with the poor reviews you have not answered yet. Or watch what Google’s first page shows for your name, and hear the moment a complaint or bad review reaches it.', 'zinn-seo' );
				$cta   = __( 'Watch my reputation', 'zinn-seo' );
				break;
			case 'citations':
				$title = $b ? __( 'Get listed in the directories customers use', 'zinn-seo' ) : __( 'Build and clean up your business listings', 'zinn-seo' );
				$body  = __( 'Consistent name, address and phone details across local directories help you show in the map results. Choose a citation package from a specialist.', 'zinn-seo' );
				$cta   = __( 'See citation packages', 'zinn-seo' );
				break;
			case 'uptime':
				$title = $b ? __( 'Know the minute your site goes down', 'zinn-seo' ) : __( 'Watch this site’s uptime and speed around the clock', 'zinn-seo' );
				$body  = __( 'We check ten of your pages every minute from outside, tell you within two minutes when one stops answering, and measure how fast each one loads for a real visitor. Give your visitors a status page with your own logo, colours and address.', 'zinn-seo' );
				$cta   = __( 'Start monitoring', 'zinn-seo' );
				break;
			case 'hosting':
				$title = $b ? __( 'Your server is slowing every page down', 'zinn-seo' ) : __( 'Move to hosting that answers fast', 'zinn-seo' );
				$body  = __( 'Zinn® hosting is tuned for WordPress speed. Move with our free migration tool, or let our team move the site for you, and get 50% off our Pro plugins for the first year.', 'zinn-seo' );
				$cta   = __( 'See hosting plans', 'zinn-seo' );
				break;
			case 'footprint':
				$title = $b ? __( 'Check your sites leave no footprint', 'zinn-seo' ) : __( 'Keep your network free of shared footprints', 'zinn-seo' );
				$body  = __( 'A monthly check finds what publicly ties your sites together and gives a plain fix for each tie, and footprint-free hosting keeps each site on its own.', 'zinn-seo' );
				$cta   = __( 'Check my footprint', 'zinn-seo' );
				break;
			case 'security':
				$title = $b ? __( 'Have the hack cleaned up by experts', 'zinn-seo' ) : __( 'Get this site cleaned and protected', 'zinn-seo' );
				$body  = __( 'Our team removes the malware and spam, repairs the site and hardens it. Backups stored away from your server mean you can always go back.', 'zinn-seo' );
				$cta   = __( 'Get it cleaned', 'zinn-seo' );
				break;
			case 'indexing':
				$title = $b ? __( 'Know the day Google drops one of your pages', 'zinn-seo' ) : __( 'Watch your important pages stay in Google', 'zinn-seo' );
				$body  = __( 'We check up to 50 of your pages in Google every week and tell you as soon as one drops out, so you can fix it before the traffic goes.', 'zinn-seo' );
				$note  = __( 'Nobody can force Google to index a page. If a page is thin, duplicated or blocked, fix that first, then ask Google to look again from the Indexing tab.', 'zinn-seo' );
				$cta   = __( 'Watch my pages', 'zinn-seo' );
				break;
			case 'experts':
				$title = $b ? __( 'Let an SEO expert fix this for you', 'zinn-seo' ) : __( 'Hand this list to our SEO team', 'zinn-seo' );
				$body  = __( 'An expert audit with a fix plan in priority order, monthly SEO done for you, or a done-for-you move from another SEO plugin, from the team led by Neil Lock, who has ranked thousands of sites over 25 years.', 'zinn-seo' );
				$cta   = __( 'See expert help', 'zinn-seo' );
				break;
			case 'referral':
				$title = $b ? __( 'Earn credit when friends join Zinn®', 'zinn-seo' ) : __( 'Recommend Zinn® and earn', 'zinn-seo' );
				$body  = __( 'Share your link and earn a share of what the people you send pay. Agencies can resell our tools and services under their own brand, at their own price.', 'zinn-seo' );
				$cta   = __( 'Get my link', 'zinn-seo' );
				break;
			case 'bundle':
				$title = $b ? __( 'Three SEO tools for less', 'zinn-seo' ) : __( 'Rankings, backlinks and audits in one bundle', 'zinn-seo' );
				$body  = __( 'Rank tracking, backlink monitoring and a monthly site audit together cost less than each on its own.', 'zinn-seo' );
				$cta   = __( 'Get the bundle', 'zinn-seo' );
				break;
			case 'services':
				$title = $b ? __( 'Have our team fix this for you', 'zinn-seo' ) : __( 'Fixed for you by the Zinn® team', 'zinn-seo' );
				$body  = __( 'Fixed-price work by our own team: speed tuning, a database clean-up, an SSL certificate, your site translated, or a move from another platform.', 'zinn-seo' );
				$cta   = __( 'Order it', 'zinn-seo' );
				break;
			case 'cdn':
				$title = $b ? __( 'Serve pages from servers near every visitor', 'zinn-seo' ) : __( 'Speed the site up with a global CDN', 'zinn-seo' );
				$body  = __( 'Your images, CSS, scripts and fonts load from servers close to each visitor, so pages appear faster. Your domain and hosting stay as they are.', 'zinn-seo' );
				$cta   = __( 'Add the CDN', 'zinn-seo' );
				break;
			case 'backups':
				$title = $b ? __( 'Back up before the next big change', 'zinn-seo' ) : __( 'Keep a copy of the site away from your server', 'zinn-seo' );
				$body  = __( 'Daily backups stored encrypted away from your server, so a big change — or anything else — can always be undone.', 'zinn-seo' );
				$cta   = __( 'Start backups', 'zinn-seo' );
				break;
			case 'support':
				$title = $b ? __( 'Get an expert on it now', 'zinn-seo' ) : __( 'Need help with this right away?', 'zinn-seo' );
				$body  = __( 'Priority support puts you at the front of the queue; emergency support gets urgent, out-of-hours help when something is badly wrong.', 'zinn-seo' );
				$cta   = __( 'Get help', 'zinn-seo' );
				break;
			// Stage X1B: the managed AI and agency extras (zs-655, zs-656, zs-661).
			case 'update_guard':
				$title = $b ? __( 'Know which updates are safe before you install them', 'zinn-seo' ) : __( 'Update without the worry', 'zinn-seo' );
				$body  = __( 'Every plugin and theme on your site is checked against your WordPress and PHP, with a plain verdict for each and a one-click proposal for the safe ones.', 'zinn-seo' );
				$cta   = __( 'Check my updates', 'zinn-seo' );
				break;
			case 'managed_ai':
				$title = $b ? __( 'Let AI look after the routine work', 'zinn-seo' ) : __( 'An AI webmaster and an AI chat for your visitors', 'zinn-seo' );
				$body  = __( 'The AI Webmaster reviews your site from its real measurements and hands you each fix ready to approve; the AI chat answers your visitors from your own pages.', 'zinn-seo' );
				$cta   = __( 'Choose', 'zinn-seo' );
				break;
			case 'reseller_data':
				$title = $b ? __( 'Sell SEO data under your own brand', 'zinn-seo' ) : __( 'Resell Zinn® SEO data to your clients', 'zinn-seo' );
				$body  = __( 'One monthly price covers 200 client sites, under your brand; each extra client site adds a little more.', 'zinn-seo' );
				$cta   = __( 'Start reselling', 'zinn-seo' );
				break;
			default:
				return null;
		}

		return array(
			'title' => $title,
			'body'  => $body,
			'cta'   => $cta,
			'note'  => $note,
		);
	}

	/**
	 * A product's short name inside its offer.
	 *
	 * @param string $offer   Offer key.
	 * @param string $product Product key.
	 * @return string
	 */
	public static function product( string $offer, string $product ): string {
		$names = array(
			'links:links'          => __( 'Guest posts and link placements', 'zinn-seo' ),
			'content:articles'     => __( 'Articles by a writer', 'zinn-seo' ),
			'domains:register'     => __( 'Register a new domain', 'zinn-seo' ),
			'domains:aged'         => __( 'Aged domains', 'zinn-seo' ),
			'wayback:hosting'      => __( 'Hosting to restore it on', 'zinn-seo' ),
			'vetting:vet'          => __( 'One domain report', 'zinn-seo' ),
			'reputation:monitor'   => __( 'Reputation monitoring', 'zinn-seo' ),
			'citations:citations'  => __( 'Citation packages', 'zinn-seo' ),
			'uptime:monitor'       => __( 'Monitoring for 10 pages', 'zinn-seo' ),
			'hosting:hosting'      => __( 'WordPress hosting', 'zinn-seo' ),
			'hosting:migration'    => __( 'We move the site for you', 'zinn-seo' ),
			'footprint:check'      => __( 'Monthly footprint check', 'zinn-seo' ),
			'footprint:hosting'    => __( 'Footprint-free hosting', 'zinn-seo' ),
			'security:cleanup'     => __( 'Malware clean-up and repair', 'zinn-seo' ),
			'security:backups'     => __( 'Off-site backups', 'zinn-seo' ),
			'indexing:boost'       => __( 'Index watch for 50 pages', 'zinn-seo' ),
			'experts:audit'        => __( 'Expert SEO audit and fix plan', 'zinn-seo' ),
			'experts:monthly'      => __( 'Monthly SEO done for you', 'zinn-seo' ),
			'experts:migration'    => __( 'Done-for-you move from Yoast, Rank Math or another SEO plugin', 'zinn-seo' ),
			'referral:referral'    => __( 'Your referral link', 'zinn-seo' ),
			'referral:reseller'    => __( 'Resell under your brand', 'zinn-seo' ),
			'bundle:seo-tools'     => __( 'Rank tracking, backlinks and audits', 'zinn-seo' ),
			'services:speed'       => __( 'Speed optimisation', 'zinn-seo' ),
			'services:speedup'     => __( 'Expert speed-up', 'zinn-seo' ),
			'services:accelerate'  => __( 'AccelerateWP set-up', 'zinn-seo' ),
			'services:database'    => __( 'Database clean-up', 'zinn-seo' ),
			'services:ssl'         => __( 'SSL certificate set-up', 'zinn-seo' ),
			'services:translation' => __( 'The site translated for you', 'zinn-seo' ),
			'services:transfer'    => __( 'Move from another platform', 'zinn-seo' ),
			'cdn:zinn-cdn'         => __( 'Zinn® CDN, on in one click', 'zinn-seo' ),
			'cdn:cdn'              => __( 'Managed CDN', 'zinn-seo' ),
			'cdn:edge'             => __( 'Edge deploy', 'zinn-seo' ),
			'backups:offsite'      => __( 'Backups to your own storage', 'zinn-seo' ),
			'backups:external'     => __( 'Backups for a site hosted elsewhere', 'zinn-seo' ),
			'support:priority'     => __( 'Priority support', 'zinn-seo' ),
			'support:emergency'    => __( 'Emergency support', 'zinn-seo' ),
			'update_guard:check'   => __( 'AI Update Guard', 'zinn-seo' ),
			'managed_ai:webmaster' => __( 'AI Webmaster site review', 'zinn-seo' ),
			'managed_ai:chat'      => __( 'AI chat for your website', 'zinn-seo' ),
			'reputation:page1'     => __( 'Watch Google page 1 for your name', 'zinn-seo' ),
			'uptime:statuspage'    => __( 'Your public status page', 'zinn-seo' ),
			'reseller_data:data'   => __( 'Reseller SEO data for 200 client sites', 'zinn-seo' ),
			'reseller_data:site'   => __( 'One more client site', 'zinn-seo' ),
		);

		return $names[ $offer . ':' . $product ] ?? $product;
	}

	/**
	 * One of our plugins at the moment it is needed (zs-613).
	 *
	 * @param string               $slug    Plugin slug.
	 * @param array<string, mixed> $finding The finding (`lang`: the language visitors speak).
	 * @return array<string, mixed>|null With `files` (to skip an active plugin).
	 */
	public static function plugin( string $slug, array $finding ): ?array {
		$listed = true;
		switch ( $slug ) {
			case 'tranzly':
				$name  = 'Tranzly™';
				$files = array( 'tranzly/tranzly.php', 'tranzly-premium/tranzly.php' );
				$lang  = (string) ( $finding['lang'] ?? '' );
				/* translators: %s: a language, e.g. "German". */
				$title = '' !== $lang ? sprintf( __( 'Translate this site into %s with Tranzly™', 'zinn-seo' ), $lang ) : __( 'Translate this site with Tranzly™', 'zinn-seo' );
				$body  = __( 'Give these visitors pages in their own language, with translated addresses and SEO data, and every language gets its own sitemap.', 'zinn-seo' );
				break;
			case 'page-builder-sandwich':
				$name   = 'Page Builder Sandwich';
				$files  = array( 'page-builder-sandwich/class-plugin.php', 'page-builder-sandwich-premium/class-plugin.php' );
				$title  = __( 'Build fuller pages with Page Builder Sandwich', 'zinn-seo' );
				$body   = __( 'Ready-made sections — FAQs, comparisons, features, calls to action — turn a thin page into a complete one in minutes, with nothing slowing it down.', 'zinn-seo' );
				$listed = false;
				break;
			case 'zinn-cache':
				$name  = 'Zinn® Cache';
				$files = array( 'zinn-cache/zinn-cache.php', 'zinn-cache-pro/zinn-cache-pro.php' );
				$title = __( 'Serve pages faster with Zinn® Cache', 'zinn-seo' );
				$body  = __( 'A free page cache and object cache that answer repeat visits without waking WordPress, so pages arrive faster.', 'zinn-seo' );
				break;
			case 'zinn-chat':
				$name   = 'Zinn® Chat';
				$files  = array( 'zinn-chat/zinn-chat.php' );
				$title  = __( 'Answer your visitors while they are still on the page', 'zinn-seo' );
				$body   = __( 'Live chat for your site, with an AI agent that answers from your own pages day and night and hands over to you when a person is needed. Questions become customers instead of lost tabs.', 'zinn-seo' );
				$listed = false;
				break;
			case 'zinn-offload':
				$name  = 'Zinn® Media Offload';
				$files = array( 'zinn-offload/zinn-offload.php' );
				$title = __( 'Move your images to a fast CDN', 'zinn-seo' );
				$body  = __( 'Zinn® Media Offload moves the media library to object storage and serves it from a CDN, so your server stops carrying the weight.', 'zinn-seo' );
				break;
			default:
				return null;
		}
		if ( $listed ) {
			$url = admin_url( 'plugin-install.php?s=' . rawurlencode( wp_strip_all_tags( $name ) ) . '&tab=search&type=term' );
			/* translators: %s: a plugin's name. */
			$cta = sprintf( __( 'Install %s free', 'zinn-seo' ), $name );
		} else {
			$product = class_exists( Data::class ) ? Data::product( $slug ) : array();
			// Zinn® Chat is not a kit product (it carries no admin kit), so its product page is named here.
			$site = 'zinn-chat' === $slug ? 'https://zinnchat.com/' : (string) ( $product['site'] ?? '' );
			$url  = esc_url_raw( $site );
			/* translators: %s: a plugin's name. */
			$cta = sprintf( __( 'Get %s', 'zinn-seo' ), $name );
		}
		if ( '' === $url ) {
			return null;
		}

		return array(
			'plugin'   => $slug,
			'name'     => $name,
			'title'    => $title,
			'body'     => $body,
			'cta'      => $cta,
			'url'      => $url,
			'external' => ! $listed,
			'files'    => $files,
		);
	}

	/**
	 * The Pro plugin bundle (zs-616): its saving and pricing page, from the kit's product data.
	 *
	 * @return array{percent: int, url: string}|null
	 */
	public static function bundle(): ?array {
		$promotions = '\ZinnDigital\ZinnSEO\AdminKit\Promotions';
		if ( ! class_exists( $promotions ) ) {
			return null;
		}
		$offer = call_user_func( array( $promotions, 'bundle_offer' ) );

		return is_array( $offer ) ? $offer : null;
	}
}
