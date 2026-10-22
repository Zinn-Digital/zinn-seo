<?php
/**
 * Finds which supported plugins are active and loads the code for those, and only those.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations;

use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The registry (`registry.php`) names every plugin Zinn® SEO works with: how to recognise it, in
 * which edition, and where its code lives. Recognising one is a `class_exists()`, a
 * `function_exists()`, a `defined()` or a look in the list of active plugins WordPress has already
 * read — never a file read, never a query. Only then is that plugin's `integration.php` included
 * and started. The file returns its starter, `function ( string $context )`: called once per
 * request. One that also takes `bool $again` is called for each context a request is booted for
 * (a REST request is first taken for a front-end one) and adds its page hooks only when
 * `$again` is false.
 *
 * ⭐ So the cost of supporting fifty plugins, on a site that runs two of them, is the code of two.
 * `loaded()` says which were started; the harness counts included files with and without each
 * plugin to prove it.
 */
final class Integrations {

	/**
	 * Started integrations: id => true.
	 *
	 * @var array<string, bool>
	 */
	private static array $loaded = array();

	/**
	 * What each included integration file returned (its starter): file => callable.
	 *
	 * @var array<string, mixed>
	 */
	private static array $starters = array();

	/**
	 * The registry, read once.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $registry = null;

	/**
	 * Every supported plugin: id => `name`, `group`, `tier` (free|pro), `detect` (a list of
	 * `class:`, `function:`, `const:`, `plugin:` and `always` tests — one is enough), `dir`
	 * (under `includes/integrations/`, or under the premium directory for `tier` pro; a free
	 * integration may have a premium part in the same-named premium directory).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function registry(): array {
		if ( null === self::$registry ) {
			$list = require __DIR__ . '/registry.php';

			/**
			 * Filters the plugins Zinn® SEO works with. An add-on adds its own entry with an
			 * absolute `file` (its `integration.php`).
			 *
			 * @param array<string, array<string, mixed>> $list Entries.
			 */
			self::$registry = (array) apply_filters( 'zinn_seo_integrations', is_array( $list ) ? $list : array() );
		}

		return self::$registry;
	}

	/**
	 * The premium directory (split so the free package never carries the premium token).
	 *
	 * @return string
	 */
	public static function pro_dir(): string {
		return ZINN_SEO_DIR . 'includes/pro_' . '_premium_only/integrations/'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, as in Modules::roots().
	}

	/**
	 * Is the plugin of one entry active on this site?
	 *
	 * @param array<string, mixed> $entry Registry entry.
	 * @return bool
	 */
	public static function detected( array $entry ): bool {
		foreach ( (array) ( $entry['detect'] ?? array() ) as $test ) {
			$test  = (string) $test;
			$colon = strpos( $test, ':' );
			$kind  = false === $colon ? $test : substr( $test, 0, $colon );
			$name  = false === $colon ? '' : substr( $test, $colon + 1 );
			switch ( $kind ) {
				case 'always':
					return true;
				case 'class':
					if ( class_exists( $name, false ) ) {
						return true;
					}
					break;
				case 'function':
					if ( function_exists( $name ) ) {
						return true;
					}
					break;
				case 'const':
					if ( defined( $name ) ) {
						return true;
					}
					break;
				case 'multisite':
					if ( is_multisite() ) {
						return true;
					}
					break;
				case 'plugin':
					if ( in_array( $name, self::active_plugins(), true ) ) {
						return true;
					}
					break;
				case 'theme':
					if ( get_template() === $name ) {
						return true;
					}
					break;
			}
		}

		return false;
	}

	/**
	 * The active plugins' main files (the option WordPress autoloads; no query).
	 *
	 * @return array<int, string>
	 */
	private static function active_plugins(): array {
		static $active = null;
		if ( null === $active ) {
			$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
			if ( is_multisite() ) {
				$active = array_merge( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
			}
		}

		return $active;
	}

	/**
	 * Start every integration whose plugin is active (and that the site has not switched off).
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		static $stamped = false;
		if ( ! $stamped ) {
			$stamped = true;
			// The page as the content checks read it depends on which integrations are at work
			// (a builder's rendering, the text of custom fields): switching one changes it.
			add_filter( 'zinn_seo_render_stamp', static fn( $stamp ) => $stamp . '|' . implode( ',', self::started() ) );
		}
		$off = (array) Settings::get( 'integrations_off', array() );
		$pro = Plugin::is_pro();
		foreach ( self::registry() as $id => $entry ) {
			$id = (string) $id;
			if ( isset( self::$loaded[ $id . '|' . $context ] ) || in_array( $id, $off, true ) || ! self::detected( (array) $entry ) ) {
				continue;
			}
			$tier = (string) ( $entry['tier'] ?? 'pro' );
			if ( 'pro' === $tier && ! $pro ) {
				continue;
			}
			// An integration that only matters to some kinds of request (`contexts`) is not even
			// read from disk on the others: a visitor's page never loads the editor's parts.
			if ( isset( $entry['contexts'] ) && ! in_array( $context, (array) $entry['contexts'], true ) ) {
				continue;
			}
			self::$loaded[ $id . '|' . $context ] = true;
			self::$loaded[ $id ]                  = true;
			$dir                                  = (string) ( $entry['dir'] ?? $id );
			$files                                = array();
			if ( isset( $entry['file'] ) ) {
				$files[] = (string) $entry['file'];
			} elseif ( 'pro' === $tier ) {
				$files[] = self::pro_dir() . $dir . '/integration.php';
			} else {
				$files[] = __DIR__ . '/' . $dir . '/integration.php';
				// A free integration's premium part (fuller product data, the merchant feed…).
				if ( $pro && ! empty( $entry['pro_part'] ) ) {
					$files[] = self::pro_dir() . $dir . '/integration.php';
				}
			}
			foreach ( $files as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}
				require_once __DIR__ . '/class-kit.php';
				// Included once: a request can be booted for two contexts (a REST request is first
				// taken for a front-end one), and the file may declare a class.
				$again = array_key_exists( $file, self::$starters );
				if ( ! $again ) {
					self::$starters[ $file ] = require $file;
				}
				$start = self::$starters[ $file ];
				if ( ! is_callable( $start ) ) {
					continue;
				}
				if ( ! $again ) {
					$start( $context, false );
				} elseif ( $start instanceof \Closure && ( new \ReflectionFunction( $start ) )->getNumberOfParameters() > 1 ) {
					// A starter that takes `$again` has hooks for one kind of request only (REST
					// routes, an admin box): it is told about the second context and adds just those.
					$start( $context, true );
				}
			}
		}
		if ( in_array( $context, array( 'admin', 'rest', 'cli' ), true ) && ! isset( self::$loaded[ '#screen|' . $context ] ) ) {
			self::$loaded[ '#screen|' . $context ] = true;
			require_once __DIR__ . '/class-integrations-rest.php';
			Integrations_Rest::init( $context );
		}
	}

	/**
	 * Was an integration started in this request?
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function loaded( string $id ): bool {
		return isset( self::$loaded[ $id ] );
	}

	/**
	 * The ids started in this request.
	 *
	 * @return array<int, string>
	 */
	public static function started(): array {
		return array_values( array_filter( array_keys( self::$loaded ), static fn( string $k ): bool => false === strpos( $k, '|' ) ) );
	}

	/**
	 * Forget the per-request state (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$loaded   = array();
		self::$registry = null;
	}

	/**
	 * The list for the Integrations screen, REST and WP-CLI: every supported plugin, whether it
	 * is on this site, and whether this edition works with it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe(): array {
		$off = (array) Settings::get( 'integrations_off', array() );
		$pro = Plugin::is_pro();
		$out = array();
		foreach ( self::registry() as $id => $entry ) {
			$tier  = (string) ( $entry['tier'] ?? 'pro' );
			$name  = $entry['name'] ?? $id;
			$does  = $entry['does'] ?? '';
			$more  = $entry['pro_does'] ?? '';
			$found = self::detected( (array) $entry );
			$out[] = array(
				// A Closure, never is_callable(): a plain name such as "Dokan" is also the name of a function.
				'id'       => (string) $id,
				'name'     => $name instanceof \Closure ? (string) $name() : (string) $name,
				'group'    => (string) ( $entry['group'] ?? 'other' ),
				'tier'     => $tier,
				'pro_part' => ! empty( $entry['pro_part'] ),
				'does'     => $does instanceof \Closure ? (string) $does() : (string) $does,
				'pro_does' => $more instanceof \Closure ? (string) $more() : (string) $more,
				'detected' => $found,
				'on'       => $found && ! in_array( (string) $id, $off, true ) && ( 'free' === $tier || $pro ),
				'off'      => in_array( (string) $id, $off, true ),
				'needs'    => 'pro' === $tier && ! $pro ? 'pro' : '',
				'proof'    => (string) ( $entry['proof'] ?? 'plugin' ),
				'core'     => in_array( 'always', (array) ( $entry['detect'] ?? array() ), true ),
			);
		}

		return $out;
	}

	/**
	 * The module's settings. Every integration's settings are declared here, whether its plugin
	 * is active or not: a setting must exist before the code that reads it is loaded.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		$text = static fn( int $max = 200 ): array => array(
			'type'    => 'string',
			'default' => '',
			'max'     => $max,
			'group'   => 'integrations',
		);
		$bool = static fn( bool $on ): array => array(
			'type'    => 'bool',
			'default' => $on,
			'group'   => 'integrations',
		);
		$pick = static fn( array $choices, string $fallback ): array => array(
			'type'    => 'enum',
			'choices' => $choices,
			'default' => $fallback,
			'group'   => 'integrations',
		);
		$int  = static fn( int $fallback, int $min, int $max ): array => array(
			'type'    => 'int',
			'default' => $fallback,
			'min'     => $min,
			'max'     => $max,
			'group'   => 'integrations',
		);

		return array(
			// Integrations a site switched off by hand.
			'integrations_off'      => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'integrations',
			),
			// The business itself (zs-110): who, where, when open.
			'local_on'              => $bool( false ),
			'local_type'            => $text( 60 ),
			'local_name'            => $text(),
			'local_phone'           => $text( 40 ),
			'local_email'           => $text( 120 ),
			'local_street'          => $text(),
			'local_locality'        => $text( 120 ),
			'local_region'          => $text( 120 ),
			'local_postcode'        => $text( 20 ),
			'local_country'         => $text( 2 ),
			'local_lat'             => $text( 20 ),
			'local_lng'             => $text( 20 ),
			'local_price_range'     => $text( 20 ),
			'local_area'            => $text(),
			// One line per day group: "Mo-Fr 09:00-17:30", "Sa 10:00-14:00"; "24/7"; empty = not said.
			'local_hours'           => array(
				'type'    => 'text',
				'default' => '',
				'max'     => 600,
				'group'   => 'integrations',
			),
			// Shops (zs-116…118).
			'shop_noindex_filters'  => $bool( true ),
			'shop_out_of_stock'     => $pick( array( 'keep', 'noindex', 'redirect', 'gone' ), 'keep' ),
			'shop_discontinued'     => $pick( array( 'keep', 'noindex', 'redirect', 'gone' ), 'redirect' ),
			'shop_brand'            => $text( 120 ),
			'shop_ship_country'     => $text( 2 ),
			'shop_ship_rate'        => $text( 12 ),
			'shop_ship_free_over'   => $text( 12 ),
			'shop_handling_days'    => $text( 7 ),
			'shop_transit_days'     => $text( 7 ),
			'shop_return_days'      => $int( 0, 0, 365 ),
			'shop_return_fees'      => $pick( array( '', 'free', 'customer' ), '' ),
			'shop_return_method'    => $pick( array( '', 'mail', 'store', 'kiosk' ), '' ),
			// zs-705: each business location is a WooCommerce local pickup point.
			'shop_pickup_locations' => $bool( false ),
			'shop_feed'             => $bool( false ),
			'shop_feed_key'         => $text( 40 ),
			// Forums, communities (zs-123).
			'forum_thin_replies'    => $int( 0, 0, 50 ),
			'forum_noindex_users'   => $bool( true ),
			// Marketplaces (zs-124, zs-620).
			'vendor_thin_products'  => $int( 1, 0, 50 ),
			'vendor_fields'         => $bool( true ),
			// Courses (zs-125): lessons a visitor cannot read are kept out of search results.
			'lms_noindex_lessons'   => $bool( true ),
			// zs-738: quiz questions and answers as Education Q&A (publishes the answers: off).
			'lms_quiz_schema'       => $bool( false ),
			// zs-735: ended listings — sold or let, and expired; and what a rental is.
			'listing_sold'          => $pick( array( 'keep', 'noindex', 'gone', 'redirect' ), 'keep' ),
			'listing_expired'       => $pick( array( 'keep', 'noindex', 'gone', 'redirect' ), 'gone' ),
			'listing_rental_kind'   => $pick( array( 'vacation', 'hotel' ), 'vacation' ),
			// zs-702: pages hidden from search engines are left out of the site's own search.
			'search_feed_noindex'   => $pick( array( 'exclude', 'keep' ), 'exclude' ),
			// Memberships (zs-126).
			'member_markup'         => $bool( true ),
			'member_noindex_pages'  => $bool( true ),
			'member_selector'       => $text( 120 ),
			// Multisite (zs-128): written by the network screen, read on each site.
			'network_locked'        => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'integrations',
			),
		);
	}

	/**
	 * The module's per-object fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		return array(
			// A product that will not come back (zs-117): the out-of-stock rule for "discontinued" applies.
			'discontinued' => array(
				'type'        => 'bool',
				'objects'     => array( 'post' ),
				'description' => static fn() => __( 'This product is discontinued: the rule for discontinued products applies to it.', 'zinn-seo' ),
			),
			// Where a discontinued or sold-out product sends visitors when the rule is "redirect".
			'replaced_by'  => array(
				'type'        => 'url',
				'objects'     => array( 'post' ),
				'description' => static fn() => __( 'The page that replaces this product. Empty: its category.', 'zinn-seo' ),
			),
		);
	}
}
