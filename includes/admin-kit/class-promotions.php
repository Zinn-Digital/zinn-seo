<?php
/**
 * The upsell and cross-sell registry.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-promotions.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnSEO\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns `data/promotions.json` (plus the `zinn_admin_kit_promotions` filter) into the cards the
 * shell draws on our own screens (features adm-4, adm-5, sh-r7).
 *
 * ⛔ No remote call, no tracking parameter, no image fetched from us: every card is static copy
 * shipped in the plugin and linked to a bare URL (`scripts/wp-promo-check.py` asserts all three
 * on the rendered copy). ⛔ Nothing here is ever drawn outside the host's own admin page.
 *
 * ⭐ The copy is chosen by `kind`, so a new product is a JSON row: `kind: plugin` with its
 * main files renders "<Name>, from Zinn Digital®" plus its install state, with no new string.
 * A product with copy of its own (Tranzly, Page Builder Sandwich, Zinn® SEO) has its own tagline,
 * pitch and "integration active" text below.
 */
final class Promotions {

	/** The kinds of card the copy table knows. */
	public const KINDS = array( 'hosting', 'marketplace', 'platform', 'plugin', 'bundle', 'ads' );

	/**
	 * Every card, resolved for the current site and host, including dismissed ones (the shell
	 * hides what the user dismissed; the Add-ons screen can bring a card back).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$rows = (array) ( Data::get( 'promotions' )['promotions'] ?? array() );

		/**
		 * Filters the promotion registry before it is resolved. Add a row to promote another
		 * product: `id`, `kind` (hosting|marketplace|platform|plugin|bundle|ads), `slot`
		 * (promo|cross-sell), and `url` or (for a plugin) `name`, `site`, `main_files`.
		 *
		 * @param array<int, array<string, mixed>> $rows Registry rows.
		 */
		$rows = (array) apply_filters( 'zseo_kit_promotions', $rows );
		$out  = array();
		foreach ( $rows as $row ) {
			$card = is_array( $row ) ? self::resolve( $row ) : null;
			if ( null !== $card ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * One registry row, resolved to a card, or null when it does not apply here.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return array<string, mixed>|null
	 */
	public static function resolve( array $row ): ?array {
		$id   = sanitize_key( (string) ( $row['id'] ?? '' ) );
		$kind = (string) ( $row['kind'] ?? '' );
		if ( '' === $id || ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}
		$card = array(
			'id'    => $id,
			'kind'  => $kind,
			'slot'  => 'promo' === ( $row['slot'] ?? '' ) ? 'promo' : 'cross-sell',
			'state' => '',
			'url'   => self::safe_url( (string) ( $row['url'] ?? '' ) ),
		);

		if ( 'plugin' === $kind ) {
			return self::plugin_card( $card, $row );
		}
		if ( 'ads' === $kind ) {
			// ⛔⛔ Never on a Footprint-Free (PBN) site (za-35, owner footprint rule).
			if ( self::footprint_free() ) {
				return null;
			}
			$locale      = function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en_US';
			$card['url'] = self::safe_url( self::localized_url( $card['url'], (array) ( $row['slugs'] ?? array() ), $locale ) );
		}
		if ( 'bundle' === $kind ) {
			$offer = self::bundle_offer();
			if ( null === $offer || 'free' !== Licence::tier() ) {
				return null; // No bundle configured, or this site already pays: nothing to offer.
			}
			// The bundle's section of this plugin's own pricing page: the three plans, what the two
			// plugins cost separately and the saving, then Freemius's checkout (owner, 2026-10-07).
			$card['url'] = $offer['url'];
		}

		$copy = self::copy( $kind, (string) ( $row['brand'] ?? '' ), '' );
		if ( '' === $card['url'] || null === $copy ) {
			return null;
		}

		return array_merge( $card, $copy );
	}

	/**
	 * A card for another plugin: its install state, and "integration active" when it runs.
	 *
	 * @param array<string, mixed> $card Card so far.
	 * @param array<string, mixed> $row  Registry row.
	 * @return array<string, mixed>|null Null for the host itself.
	 */
	private static function plugin_card( array $card, array $row ): ?array {
		$slug    = sanitize_key( (string) ( $row['product'] ?? $card['id'] ) );
		$product = array_merge( Data::product( $slug ), array_intersect_key( $row, array_flip( array( 'name', 'site', 'main_files' ) ) ) );
		if ( Kit::host( 'slug' ) === $slug || empty( $product['name'] ) ) {
			return null;
		}
		$files = array_map( 'strval', (array) ( $product['main_files'] ?? array() ) );

		$card['state'] = 'available';
		foreach ( $files as $file ) {
			if ( self::is_active( $file ) ) {
				$card['state'] = 'active';
				break;
			}
			if ( self::is_installed( $file ) ) {
				$card['state'] = 'installed';
			}
		}
		$card['url']     = self::safe_url( (string) ( $product['site'] ?? '' ) );
		$card['product'] = $slug;
		// A plugin that is not in the WordPress.org directory yet (`on_wordpress_org: false`) cannot
		// be found by the Add Plugins search, so it gets no Install button: its card links its own
		// site, which says how to get it.
		$listed = false !== ( $product['on_wordpress_org'] ?? true );
		if ( 'installed' === $card['state'] ) {
			$card['actionUrl'] = admin_url( 'plugins.php' );
		} elseif ( 'available' === $card['state'] && $listed ) {
			$card['actionUrl'] = admin_url( 'plugin-install.php?s=' . rawurlencode( (string) ( $product['install_search'] ?? $product['name'] ) ) . '&tab=search&type=term' );
		}

		$copy = self::copy( 'plugin', (string) $product['name'], $slug );
		if ( null !== $copy && ! $listed ) {
			/* translators: %s: a plugin's name. */
			$copy['cta'] = sprintf( __( 'See %s', 'zinn-seo' ), (string) $product['name'] );
		}

		return null === $copy || '' === $card['url'] ? null : array_merge( $card, $copy );
	}

	/**
	 * The Page Builder Sandwich Pro + Tranzly Pro bundle as this plugin offers it: the largest saving
	 * (products.json `bundle.saving_percent`, held equal to wp/freemius-plans.json by
	 * wp/tests/unit/AdminKitBundleOfferTest.php) and the bundle section of the host's own pricing page.
	 *
	 * @return array{percent: int, url: string}|null Null when no bundle is configured, or the host is
	 *                                               not in it.
	 */
	public static function bundle_offer(): ?array {
		$bundle  = (array) ( Data::get( 'products' )['bundle'] ?? array() );
		$percent = (int) ( $bundle['saving_percent'] ?? 0 );
		$product = Data::product( (string) Kit::host( 'slug' ) );
		$site    = (string) ( $product['site'] ?? '' );
		$url     = self::safe_url( '' === $site ? '' : rtrim( $site, '/' ) . '/' . ltrim( (string) ( $bundle['pricing_path'] ?? '' ), '/' ) );
		// A host the bundle does not include (`in_bundle: false`) never offers it.
		if ( false === ( $product['in_bundle'] ?? true ) || '' === (string) preg_replace( '/\D/', '', (string) ( $bundle['id'] ?? '' ) ) || $percent <= 0 || '' === $url ) {
			return null;
		}
		return array(
			'percent' => $percent,
			'url'     => $url,
		);
	}

	/**
	 * The translated copy for a card.
	 *
	 * @param string $kind  Card kind.
	 * @param string $brand The brand or product name (not translated: it is a name).
	 * @param string $slug  For a plugin card, the product slug (picks a specific tagline).
	 * @return array{brand: string, title: string, body: string, cta: string, activeTitle: string, activeBody: string}|null
	 */
	public static function copy( string $kind, string $brand, string $slug ): ?array {
		$host = Kit::host( 'name' );
		switch ( $kind ) {
			case 'hosting':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Hosting built for fast, multilingual WordPress', 'zinn-seo' ),
					'body'        => __( 'Managed WordPress hosting from the team that makes this plugin, tuned for speed and for sites in many languages.', 'zinn-seo' ),
					'cta'         => __( 'See Zinn Digital® hosting', 'zinn-seo' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'marketplace':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Hire a WordPress expert on ZinnHub', 'zinn-seo' ),
					'body'        => __( 'A freelance marketplace for WordPress design, development, translation and SEO work.', 'zinn-seo' ),
					'cta'         => __( 'Visit ZinnHub', 'zinn-seo' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'platform':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Bulk site and PBN hosting', 'zinn-seo' ),
					'body'        => __( 'PBN.ltd is our platform for hosting and managing many sites at once.', 'zinn-seo' ),
					'cta'         => __( 'Visit PBN.ltd', 'zinn-seo' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'ads':
				return array(
					'brand'       => $brand,
					'title'       => __( 'Google Ads management that asks before it spends', 'zinn-seo' ),
					'body'        => __( 'Zinn® Ads audits your Google Ads account every day and fixes wasted spend, every change approved by you first. Its click-fraud shield is free with Zinn® hosting.', 'zinn-seo' ),
					'cta'         => __( 'See Zinn® Ads', 'zinn-seo' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'bundle':
				$offer = self::bundle_offer();
				return array(
					'brand'       => $brand,
					'title'       => __( 'Get Page Builder Sandwich Pro and Tranzly Pro together', 'zinn-seo' ),
					'body'        => null === $offer
						? __( 'The bundle costs less than buying both plugins separately, on the same plans and site limits.', 'zinn-seo' )
						/* translators: %d: the largest saving, in whole percent. */
						: sprintf( __( 'Both Pro plugins on the same plan and site limit, for up to %d%% less than buying them separately.', 'zinn-seo' ), $offer['percent'] ),
					'cta'         => __( 'See the bundle', 'zinn-seo' ),
					'activeTitle' => '',
					'activeBody'  => '',
				);
			case 'plugin':
				return array(
					'brand'       => $brand,
					'title'       => self::tagline( $slug, $brand ),
					'body'        => self::pitch( $slug, $brand ),
					/* translators: %s: a plugin's name. */
					'cta'         => sprintf( __( 'Get %s', 'zinn-seo' ), $brand ),
					/* translators: 1: this plugin's name, 2: the other plugin's name. */
					'activeTitle' => sprintf( __( 'Integration active: %1$s and %2$s work together', 'zinn-seo' ), $host, $brand ),
					'activeBody'  => self::active_body( $slug, $brand ),
				);
		}

		return null;
	}

	/**
	 * A plugin card's headline.
	 *
	 * @param string $slug  Product slug.
	 * @param string $brand Product name.
	 * @return string
	 */
	private static function tagline( string $slug, string $brand ): string {
		if ( 'tranzly' === $slug ) {
			return __( 'Translate this site with Tranzly', 'zinn-seo' );
		}
		if ( 'page-builder-sandwich' === $slug ) {
			return __( 'Build pages visually with Page Builder Sandwich', 'zinn-seo' );
		}
		if ( 'zinn-seo' === $slug ) {
			return __( 'Get this site professionally optimised for search with Zinn® SEO', 'zinn-seo' );
		}

		/* translators: %s: a plugin's name. */
		return sprintf( __( '%s, from Zinn Digital®', 'zinn-seo' ), $brand );
	}

	/**
	 * A plugin card's body.
	 *
	 * @param string $slug  Product slug.
	 * @param string $brand Product name.
	 * @return string
	 */
	private static function pitch( string $slug, string $brand ): string {
		if ( 'tranzly' === $slug ) {
			return __( 'Publish every page in many languages, with translated addresses, SEO data and a language switcher. Works with the pages you build here.', 'zinn-seo' );
		}
		if ( 'page-builder-sandwich' === $slug ) {
			return __( 'Design pages right in the editor with fast, footprint-free blocks. Everything you build translates with Tranzly.', 'zinn-seo' );
		}
		if ( 'zinn-seo' === $slug ) {
			return __( 'Titles, sitemaps, rich results, redirects and AI visibility from one guided setup, with nothing added to the pages your visitors load, so the site stays fast. Moving from Yoast or Rank Math takes a few clicks.', 'zinn-seo' );
		}

		/* translators: %s: a plugin's name. */
		return sprintf( __( '%s is made by the same team and works alongside this plugin.', 'zinn-seo' ), $brand );
	}

	/**
	 * What a plugin card says once the other plugin runs on this site: what the two now do together.
	 *
	 * @param string $slug  The other plugin's product slug.
	 * @param string $brand Its name.
	 * @return string
	 */
	private static function active_body( string $slug, string $brand ): string {
		if ( 'zinn-seo' === $slug ) {
			$host = (string) Kit::host( 'slug' );
			if ( 'tranzly' === $host ) {
				return __( 'Zinn® SEO is active on this site: search titles, descriptions and social text are translated with each page, and every language gets its own sitemap and llms.txt.', 'zinn-seo' );
			}
			if ( 'page-builder-sandwich' === $host ) {
				return __( 'Zinn® SEO is active on this site: its SEO blocks are in the block inserter, and the structured data of the pages you build joins one search graph.', 'zinn-seo' );
			}
		}

		/* translators: %s: the other plugin's name. */
		return sprintf( __( '%s is active on this site, so both plugins share one set of AI provider keys and one settings screen for them.', 'zinn-seo' ), $brand );
	}

	/**
	 * Is this a Footprint-Free (PBN) site, where the Zinn® Ads card must never show?
	 *
	 * ⛔ Two signals, either is enough: `ZINN_FOOTPRINT_FREE`, which our hosting writes into
	 * `wp-config.php` for a site on the Footprint-Free line, and the `<prefix>_kit_footprint_free`
	 * filter, which a host plugin answers from its own no-trace mode (Zinn® SEO does).
	 *
	 * @return bool
	 */
	public static function footprint_free(): bool {
		$constant = defined( 'ZINN_FOOTPRINT_FREE' ) && (bool) constant( 'ZINN_FOOTPRINT_FREE' );

		/**
		 * Filters whether this site is footprint-free, so Zinn® Ads is never promoted on it.
		 *
		 * @param bool $footprint_free Whether `ZINN_FOOTPRINT_FREE` says so.
		 */
		return (bool) apply_filters( 'zseo_kit_footprint_free', $constant );
	}

	/**
	 * A page's address in the administrator's language: its translated slug under the locale
	 * prefix, or the English page when the locale has no slug. Pure.
	 *
	 * ⭐ The slugs are the public site's own (`content/site/slugs.i18n.json`, pinned by
	 * AdminKitPromotionsTest), so the link lands on the translated page with no redirect.
	 *
	 * @param string                $url    The English page, `https://host/slug`.
	 * @param array<string, string> $slugs  Translated slug by public-site locale (`pl`, `pt-BR`).
	 * @param string                $locale The WordPress locale (`pl_PL`, `pt_BR`, `en_US`).
	 * @return string
	 */
	public static function localized_url( string $url, array $slugs, string $locale ): string {
		$exact = str_replace( '_', '-', $locale );
		$base  = strtolower( (string) strtok( $exact, '-' ) );
		$key   = isset( $slugs[ $exact ] ) ? $exact : ( isset( $slugs[ $base ] ) ? $base : '' );
		$slug  = '' === $key ? '' : trim( (string) $slugs[ $key ] );
		if ( '' === $slug || ! preg_match( '#^(https://[^/]+)/#', $url, $origin ) ) {
			return $url;
		}

		return $origin[1] . '/' . rawurlencode( $key ) . '/' . rawurlencode( $slug );
	}

	/**
	 * Only a bare https URL is allowed on a card: no query string, no fragment (no tracking).
	 *
	 * @param string $url Candidate.
	 * @return string Empty when refused.
	 */
	public static function safe_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Is a plugin active on this site (or network)?
	 *
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	private static function is_active( string $file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $file );
	}

	/**
	 * Is a plugin installed (present, active or not)?
	 *
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	private static function is_installed( string $file ): bool {
		return '' !== $file && file_exists( WP_PLUGIN_DIR . '/' . $file );
	}
}
