<?php
/**
 * What Zinn® sells, offered inside the plugin beside the finding it solves (zs-600 … zs-616).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Offers;

use ZinnDigital\ZinnSEO\AdminKit\Data;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ONE registry: the `kind: offer` rows of the admin kit's promotions list (plus anything the
 * `zseo_kit_promotions` filter adds). Each row names an offer (something Zinn® sells, priced by
 * Zinn® from its live catalogue row) or one of our own plugins, and the Fix-it findings it stands
 * beside.
 *
 * The rules, so it sells without nagging (WordPress.org guideline 11):
 *
 * - beside a finding only: an offer is attached to a Fix-it issue whose id matches one of its
 *   `findings`, and to nothing else; the Services screen lists them for a person who went there;
 * - Zinn® SEO screens only: it travels inside this plugin's own REST answers, never as an admin
 *   notice, a dashboard widget of another plugin, or anything on a visitor's page;
 * - dismissible per offer and per person, for good (the Services screen can bring it back);
 * - frequency-capped: once someone has seen an offer `CAP` times in `WINDOW`, it rests until the
 *   window passes;
 * - never on a white-labelled client login (`Whitelabel::hides_zinn()`), never for a person who
 *   cannot manage the plugin;
 * - two copies (A and B) per offer, fixed per site; the copy shown travels with the order, so
 *   conversion is counted at Zinn® only — nothing is counted or sent from here for a view.
 *
 * ⛔ Money: an order opens a BASKET at Zinn® and hands back where to pay it, in a new tab, signed
 * in. Nothing here can charge anybody.
 */
final class Offers {

	/** Zinn®'s offer list (prices), kept an hour. Not autoloaded. */
	public const OPTION = 'zseo_offers_catalogue';

	/** How long the offer list is kept. */
	public const TTL = HOUR_IN_SECONDS;

	/** User meta: `dismissed` id => time, `seen` id => [times]. */
	public const META = '_zseo_offers';

	/** Views of one offer allowed in `WINDOW` before it rests. */
	public const CAP = 3;

	/** The frequency window. */
	public const WINDOW = WEEK_IN_SECONDS;

	/** Most offers one Fix-it list shows. */
	public const PER_LIST = 3;

	/** Most offers beside one finding. */
	public const PER_FINDING = 2;

	/**
	 * Replaces the plugin-active checks in tests: `function ( string $file ): bool`.
	 *
	 * @var callable|null
	 */
	public static $is_active = null;

	/**
	 * Offers already placed in this request's Fix-it list (the per-list ceiling).
	 *
	 * @var array<int, string>
	 */
	private static array $placed = array();

	/**
	 * Hook in (admin and REST only: nothing here is ever on a visitor's page).
	 *
	 * @param string $context Boot context.
	 * @return void
	 */
	public static function boot( string $context ): void {
		add_filter( 'zinn_seo_guide_slugs', array( self::class, 'guide_slugs' ) );
		add_action( 'zinn_seo_disconnected', static fn() => delete_option( self::OPTION ) );
		add_action( 'zinn_seo_connected', static fn() => delete_option( self::OPTION ) );
		add_filter( 'zinn_seo_fixit_sources', array( self::class, 'attach' ), 999 );
		if ( 'rest' === $context || 'admin' === $context ) {
			add_filter( 'rest_post_dispatch', array( self::class, 'decorate' ), 10, 3 );
		}
		if ( 'admin' === $context ) {
			add_filter( 'zinn_seo_admin_data', array( self::class, 'admin_data' ) );
		}
	}

	/**
	 * The screen's help link: SEO → Services → "Order Zinn® services from WordPress".
	 *
	 * @param array<string, string> $slugs Screen id => guide slug.
	 * @return array<string, string>
	 */
	public static function guide_slugs( $slugs ): array {
		return array_merge(
			(array) $slugs,
			array(
				'services' => 'order-zinn-services-from-wordpress',
			)
		);
	}

	/**
	 * What the admin screen needs on load.
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data           = (array) $data;
		$data['offers'] = array(
			'show'      => self::may_show(),
			'connected' => Connection::connected(),
			'canOrder'  => Connection::connected() && current_user_can( Capabilities::SPEND ),
		);

		return $data;
	}

	// ── the registry ──────────────────────────────────────────────────────────────────────────

	/**
	 * The `kind: offer` rows: `id`, `ledger`, `findings`, and either `offer` (sold by Zinn®) or
	 * `plugin` (one of ours).
	 *
	 * @return array<string, array<string, mixed>> By row id.
	 */
	public static function rows(): array {
		$rows = class_exists( Data::class ) ? (array) ( Data::get( 'promotions' )['promotions'] ?? array() ) : array();
		/** This filter is documented in includes/admin-kit/class-promotions.php */
		$rows = (array) apply_filters( 'zseo_kit_promotions', $rows );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || 'offer' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id || ( empty( $row['offer'] ) && empty( $row['plugin'] ) ) ) {
				continue;
			}
			$out[ $id ] = array(
				'id'       => $id,
				'offer'    => sanitize_key( (string) ( $row['offer'] ?? '' ) ),
				'plugin'   => sanitize_key( (string) ( $row['plugin'] ?? '' ) ),
				'ledger'   => sanitize_text_field( (string) ( $row['ledger'] ?? '' ) ),
				'findings' => array_values( array_map( 'strval', (array) ( $row['findings'] ?? array() ) ) ),
				'when'     => sanitize_key( (string) ( $row['when'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Does a finding id match a pattern (`spam-*` matches every spam finding)? Pure.
	 *
	 * @param string $pattern Pattern; a trailing `*` matches any rest.
	 * @param string $finding Finding id.
	 * @return bool
	 */
	public static function matches( string $pattern, string $finding ): bool {
		if ( '' === $pattern || '' === $finding ) {
			return false;
		}
		if ( '*' === substr( $pattern, -1 ) ) {
			$stem = substr( $pattern, 0, -1 );
			return '' !== $stem && 0 === strpos( $finding, $stem ) && $finding !== $stem;
		}

		return $pattern === $finding;
	}

	// ── who may see them ──────────────────────────────────────────────────────────────────────

	/**
	 * May the current person be shown offers at all?
	 *
	 * @return bool
	 */
	public static function may_show(): bool {
		if ( ! current_user_can( Capabilities::MANAGE ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$whitelabel = '\ZinnDigital\ZinnSEO\Pro\Whitelabel\Whitelabel';
		if ( class_exists( $whitelabel ) && call_user_func( array( $whitelabel, 'hides_zinn' ) ) ) {
			return false;
		}

		/**
		 * Whether offers may be shown to the current person (false hides every offer).
		 *
		 * @param bool $show Show them.
		 */
		return (bool) apply_filters( 'zinn_seo_offers_show', true );
	}

	/**
	 * The current person's offer state.
	 *
	 * @param int $user User id; 0 for the current one.
	 * @return array{dismissed: array<string, int>, seen: array<string, array<int, int>>}
	 */
	public static function state( int $user = 0 ): array {
		$user = $user > 0 ? $user : get_current_user_id();
		$raw  = $user > 0 ? get_user_meta( $user, self::META, true ) : array();
		$raw  = is_array( $raw ) ? $raw : array();

		return array(
			'dismissed' => array_map( 'intval', (array) ( $raw['dismissed'] ?? array() ) ),
			'seen'      => array_map( static fn( $t ) => array_values( array_map( 'intval', (array) $t ) ), (array) ( $raw['seen'] ?? array() ) ),
		);
	}

	/**
	 * Is an offer resting for this person (dismissed, or seen too often lately)? Pure but the clock.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $id    Row id.
	 * @param int|null             $now   Timestamp.
	 * @return bool
	 */
	public static function resting( array $state, string $id, ?int $now = null ): bool {
		$now = $now ?? time();
		if ( isset( $state['dismissed'][ $id ] ) ) {
			return true;
		}
		$recent = array_filter( (array) ( $state['seen'][ $id ] ?? array() ), static fn( $t ) => (int) $t > $now - self::WINDOW );

		return count( $recent ) >= self::cap();
	}

	/**
	 * Views of one offer allowed in a week before it rests.
	 *
	 * @return int
	 */
	public static function cap(): int {
		/**
		 * How many times a week one person may see the same offer (default 3; at least 1).
		 *
		 * @param int $cap Views.
		 */
		return max( 1, (int) apply_filters( 'zinn_seo_offers_cap', self::CAP ) );
	}

	/**
	 * Count a view of these offers (the screen says what it showed).
	 *
	 * @param array<int, string> $ids Row ids.
	 * @return array<string, mixed> The new state.
	 */
	public static function seen( array $ids ): array {
		$state = self::state();
		$now   = time();
		$known = self::rows();
		foreach ( array_unique( array_map( 'sanitize_key', $ids ) ) as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				continue;
			}
			$times                = array_filter( (array) ( $state['seen'][ $id ] ?? array() ), static fn( $t ) => (int) $t > $now - self::WINDOW );
			$times[]              = $now;
			$state['seen'][ $id ] = array_slice( array_values( $times ), -self::cap() );
		}
		update_user_meta( get_current_user_id(), self::META, $state );

		return $state;
	}

	/**
	 * Dismiss an offer for good, or bring one (or all) back.
	 *
	 * @param string $id      Row id, or `*` with `$restore` for all.
	 * @param bool   $restore Bring it back.
	 * @return array<string, mixed> The new state.
	 */
	public static function dismiss( string $id, bool $restore = false ): array {
		$state = self::state();
		$id    = '*' === $id ? '*' : sanitize_key( $id );
		if ( $restore ) {
			if ( '*' === $id ) {
				$state['dismissed'] = array();
				$state['seen']      = array();
			} else {
				unset( $state['dismissed'][ $id ], $state['seen'][ $id ] );
			}
		} elseif ( isset( self::rows()[ $id ] ) ) {
			$state['dismissed'][ $id ] = time();
		}
		update_user_meta( get_current_user_id(), self::META, $state );

		return $state;
	}

	/**
	 * The copy this site shows for an offer: `a` or `b`, fixed per site. Pure but the home address.
	 *
	 * @param string $id Row id.
	 * @return string
	 */
	public static function variant( string $id ): string {
		return 0 === crc32( home_url( '/' ) . '|' . $id ) % 2 ? 'a' : 'b';
	}

	// ── Zinn®'s offer list ────────────────────────────────────────────────────────────────────

	/**
	 * The offer list with each product's live price, kept an hour.
	 *
	 * @param bool $fresh Ask Zinn® again.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function catalogue( bool $fresh = false ) {
		$kept = get_option( self::OPTION );
		if ( ! $fresh && is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) > time() - self::TTL && is_array( $kept['data'] ?? null ) ) {
			return $kept['data'];
		}
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/offers' );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$data = (array) $result['body'];
		update_option(
			self::OPTION,
			array(
				'at'   => time(),
				'data' => $data,
			),
			false
		);

		return $data;
	}

	/**
	 * Zinn®'s offers by key, from the kept list only (a Fix-it list never waits for the network).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function kept_offers(): array {
		$kept = get_option( self::OPTION );
		$out  = array();
		foreach ( (array) ( is_array( $kept ) ? ( $kept['data']['offers'] ?? array() ) : array() ) as $offer ) {
			if ( is_array( $offer ) && ! empty( $offer['key'] ) ) {
				$out[ (string) $offer['key'] ] = $offer;
			}
		}

		return $out;
	}

	/**
	 * The dashboard address Zinn® named (the order pages live there).
	 *
	 * @return string
	 */
	public static function dashboard(): string {
		$kept = get_option( self::OPTION );
		$url  = is_array( $kept ) ? (string) ( $kept['data']['dashboard'] ?? '' ) : '';

		return '' === $url ? '' : esc_url_raw( $url );
	}

	// ── cards ─────────────────────────────────────────────────────────────────────────────────

	/**
	 * The card for one row, or null when it cannot be offered here now.
	 *
	 * @param array<string, mixed>                $row    Registry row.
	 * @param array<string, array<string, mixed>> $offers Zinn®'s offers by key.
	 * @param array<string, string>               $finding The finding it stands beside (`id`, `url`).
	 * @return array<string, mixed>|null
	 */
	public static function card( array $row, array $offers, array $finding = array() ): ?array {
		$id      = (string) $row['id'];
		$variant = self::variant( $id );
		if ( '' !== $row['plugin'] ) {
			$plugin = Copy::plugin( (string) $row['plugin'], $finding );
			if ( null === $plugin || self::plugin_active( (array) $plugin['files'] ) ) {
				return null;
			}
			unset( $plugin['files'] );

			return array(
				'id'      => $id,
				'ledger'  => $row['ledger'],
				'variant' => $variant,
				'kind'    => 'plugin',
			) + $plugin;
		}
		if ( 'notrace' === $row['when'] && ! self::notrace() ) {
			return null;
		}
		$offer = $offers[ (string) $row['offer'] ] ?? null;
		if ( ! is_array( $offer ) || empty( $offer['available'] ) ) {
			return null;
		}
		$copy = Copy::offer( (string) $row['offer'], $variant );
		if ( null === $copy ) {
			return null;
		}
		$products = array();
		foreach ( (array) ( $offer['products'] ?? array() ) as $p ) {
			if ( is_array( $p ) && ! empty( $p['available'] ) ) {
				$products[] = array(
					'key'   => (string) $p['key'],
					'kind'  => (string) $p['kind'],
					'label' => Copy::product( (string) $row['offer'], (string) $p['key'] ),
					'facts' => (array) ( $p['facts'] ?? array() ),
				);
			}
		}
		if ( ! $products ) {
			return null;
		}

		return array(
			'id'       => $id,
			'ledger'   => $row['ledger'],
			'variant'  => $variant,
			'kind'     => 'offer',
			'offer'    => (string) $row['offer'],
			'currency' => (string) ( $offer['currency'] ?? 'USD' ),
			'products' => $products,
			'referral' => (array) ( $offer['referral'] ?? array() ),
			'finding'  => $finding,
		) + $copy;
	}

	/**
	 * Is no-trace (footprint-free) mode on?
	 *
	 * @return bool
	 */
	public static function notrace(): bool {
		return class_exists( '\ZinnDigital\ZinnSEO\Notrace' ) && \ZinnDigital\ZinnSEO\Notrace::enabled();
	}

	/**
	 * Is one of these plugin files active on this site?
	 *
	 * @param array<int, string> $files Plugin basenames.
	 * @return bool
	 */
	public static function plugin_active( array $files ): bool {
		if ( null === self::$is_active && ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $files as $file ) {
			if ( null !== self::$is_active ? (bool) call_user_func( self::$is_active, (string) $file ) : is_plugin_active( (string) $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Start a new Fix-it list (the per-list ceiling counts from here).
	 *
	 * @return void
	 */
	public static function new_list(): void {
		self::$placed = array();
	}

	/**
	 * The offers that stand beside one finding, for this person, within the list's ceiling.
	 *
	 * @param array<string, mixed> $issue A Fix-it issue.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_finding( array $issue ): array {
		$finding = (string) ( $issue['id'] ?? '' );
		if ( '' === $finding || count( self::$placed ) >= self::PER_LIST ) {
			return array();
		}
		$state  = self::state();
		$offers = self::kept_offers();
		$out    = array();
		foreach ( self::rows() as $id => $row ) {
			if ( in_array( $id, self::$placed, true ) || self::resting( $state, $id ) ) {
				continue;
			}
			$hit = false;
			foreach ( (array) $row['findings'] as $pattern ) {
				$hit = $hit || self::matches( (string) $pattern, $finding );
			}
			if ( ! $hit ) {
				continue;
			}
			$card = self::card(
				$row,
				$offers,
				array(
					'id'    => $finding,
					'title' => wp_strip_all_tags( (string) ( $issue['title'] ?? '' ) ),
					'url'   => esc_url_raw( (string) ( $issue['offer_url'] ?? '' ) ),
					'lang'  => sanitize_text_field( (string) ( $issue['offer_lang'] ?? '' ) ),
				)
			);
			if ( null === $card ) {
				continue;
			}
			$out[]          = $card;
			self::$placed[] = $id;
			if ( count( $out ) >= self::PER_FINDING || count( self::$placed ) >= self::PER_LIST ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Add this module's own findings to the Fix-it inbox (a slow server, pages Google left out,
	 * old addresses still asked for, visitors in other languages, a long list of urgent fixes).
	 *
	 * ⭐ The offers themselves are NOT added here: the inbox's list is cached for every person,
	 * so they are added per request, after the cache (`decorate`), and one administrator's
	 * dismissals never leak into another's list.
	 *
	 * @param array<string, callable> $sources Fix-it sources.
	 * @return array<string, callable>
	 */
	public static function attach( $sources ): array {
		$sources           = (array) $sources;
		$sources['offers'] = array( Findings::class, 'issues' );

		return $sources;
	}

	/**
	 * Add offers to a Fix-it REST answer (the per-person half — see `attach`).
	 *
	 * @param \WP_REST_Response|\WP_Error|mixed $response The answer.
	 * @param \WP_REST_Server                   $server   Server.
	 * @param \WP_REST_Request                  $request  Request.
	 * @return mixed
	 */
	public static function decorate( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInMiddle -- the filter's signature.
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( '/' . \ZinnDigital\ZinnSEO\Plugin::REST_NS . '/fixit' !== $request->get_route() || 'GET' !== $request->get_method() || ! self::may_show() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! is_array( $data['issues'] ?? null ) ) {
			return $response;
		}
		self::new_list();
		$many = Findings::many_fixes( $data['issues'] );
		if ( null !== $many ) {
			array_unshift( $data['issues'], $many );
		}
		foreach ( $data['issues'] as $i => $issue ) {
			if ( is_array( $issue ) ) {
				$data['issues'][ $i ]['offers'] = self::for_finding( $issue );
			}
		}
		$data['offersDashboard'] = self::dashboard();
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Every card the Services screen lists, grouped by what it is for, for this person.
	 *
	 * @param bool $fresh Ask Zinn® for prices again.
	 * @return array<string, mixed>
	 */
	public static function services( bool $fresh = false ): array {
		$connected = Connection::connected();
		$error     = '';
		$offers    = array();
		if ( $connected ) {
			$catalogue = self::catalogue( $fresh );
			if ( is_wp_error( $catalogue ) ) {
				$error = $catalogue->get_error_message();
			} else {
				$offers = self::kept_offers();
			}
		}
		$state = self::state();
		$cards = array();
		foreach ( self::rows() as $id => $row ) {
			$card = self::card( $row, $offers );
			if ( null !== $card ) {
				$card['dismissed'] = isset( $state['dismissed'][ $id ] );
				$cards[]           = $card;
			}
		}

		return array(
			'connected' => $connected,
			'canOrder'  => $connected && current_user_can( Capabilities::SPEND ),
			'dashboard' => self::dashboard(),
			'error'     => $error,
			'cards'     => $cards,
			'bundle'    => Copy::bundle(),
		);
	}

	/**
	 * Place an order at Zinn®: opens the basket and answers where to pay it.
	 *
	 * @param array<string, mixed> $in `offer`, `product`, and `package_id`, `domain`, `context`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function order( array $in ) {
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$body    = array(
			'offer'   => sanitize_key( (string) ( $in['offer'] ?? '' ) ),
			'product' => sanitize_key( (string) ( $in['product'] ?? '' ) ),
			'context' => self::context( (array) ( $in['context'] ?? array() ) ),
		);
		$package = (string) ( $in['package_id'] ?? '' );
		if ( '' !== $package && wp_is_uuid( $package ) ) {
			$body['package_id'] = $package;
		}
		$domain = strtolower( trim( (string) ( $in['domain'] ?? '' ) ) );
		if ( '' !== $domain ) {
			$body['domain'] = substr( sanitize_text_field( $domain ), 0, 253 );
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/offers/orders', $body );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * The order context the plugin may send, cleaned. Pure.
	 *
	 * @param array<string, mixed> $raw Raw context.
	 * @return array<string, string>
	 */
	public static function context( array $raw ): array {
		$out = array();
		foreach ( array(
			'finding'    => 80,
			'target_url' => 2000,
			'anchor'     => 200,
			'brief'      => 4000,
			'variant'    => 1,
		) as $key => $max ) {
			$value = trim( 'brief' === $key ? sanitize_textarea_field( (string) ( $raw[ $key ] ?? '' ) ) : sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) ) );
			if ( 'target_url' === $key ) {
				$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
				$value  = in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
			}
			if ( 'variant' === $key && ! in_array( $value, array( 'a', 'b' ), true ) ) {
				$value = '';
			}
			if ( '' !== $value ) {
				$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
			}
		}

		return $out;
	}

	/**
	 * Pass one engine call through, as REST data or an error in plain words.
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path.
	 * @param array<string, mixed>|null $body   Body.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function pass( string $method, string $path, ?array $body = null ) {
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$result = Connection::call( $method, $path, $body );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}
}
