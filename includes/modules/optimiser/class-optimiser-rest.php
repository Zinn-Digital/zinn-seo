<?php
/**
 * REST routes of the live content optimiser.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Optimiser;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Guide;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `GET  /optimiser/model?phrase=&location=&language=` — the kept model of a search, if any
 *   (free; a read in progress is followed).
 * - `POST /optimiser/read` `{phrase, location, language, again, post}` — read the top 10 now
 *   (spends Zinn® credit once per search; a kept model is returned as it is unless `again`).
 * - `GET  /optimiser/post/<id>` — a saved post's score against its search's kept model.
 * - `GET  /optimiser/searches?before=` — the kept searches, newest first (keyset).
 * - `DELETE /optimiser/searches/<key>` — forget a kept search.
 *
 * Reading a kept model needs the right to edit posts; reading the top 10 needs the right to
 * spend the owner's credit (administrators by default); a post's score, the right to edit it.
 */
final class Optimiser_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * What the editor boots with: the price of a read (from the copy of the account kept here —
	 * never a call while the editor loads), the countries, the defaults and the guide.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_data(): array {
		$markets = array();
		foreach ( Optimiser::markets() as $code => $m ) {
			$markets[ $code ] = $m;
		}
		$post = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which post the editor shows; changes nothing.

		return array(
			'money'   => Optimiser::money( false ),
			'markets' => $markets,
			'default' => $post > 0 ? array_intersect_key( Optimiser::search_for( $post ), array_flip( array( 'location', 'language' ) ) ) : Optimiser::default_market(),
			'help'    => class_exists( Guide::class ) ? Guide::url( 'optimiser' ) : '',
			'fresh'   => Optimiser::FRESH_DAYS,
			'stop'    => Optimiser::stopwords(),
			// Connecting and buying credit both happen on the Credit screen.
			'connect' => admin_url( 'admin.php?page=zinn-seo&view=credits' ),
		);
	}

	/**
	 * Wrap a result for REST.
	 *
	 * @param mixed $result Result or error.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function out( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done   = true;
		$ns     = Plugin::REST_NS;
		$write  = static fn(): bool => current_user_can( 'edit_posts' ) || current_user_can( Capabilities::MANAGE );
		$spend  = static fn(): bool => current_user_can( Capabilities::SPEND );
		$search = array(
			'phrase'   => array(
				'type'     => 'string',
				'required' => true,
			),
			'location' => array(
				'type'    => 'string',
				'default' => '',
			),
			'language' => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		register_rest_route(
			$ns,
			'/optimiser/model',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out(
					self::with_money( Optimiser::lookup( (string) $r['phrase'], (string) $r['location'], (string) $r['language'] ), false )
				),
				'permission_callback' => $write,
				'args'                => $search,
			)
		);
		register_rest_route(
			$ns,
			'/optimiser/read',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$post = (int) $r['post'];
					if ( $post > 0 && ! current_user_can( 'edit_post', $post ) ) {
						$post = 0;
					}

					return self::out( self::with_money( Optimiser::pull( (string) $r['phrase'], (string) $r['location'], (string) $r['language'], (bool) $r['again'], $post ), true ) );
				},
				'permission_callback' => $spend,
				'args'                => $search + array(
					'again' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'post'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/optimiser/post/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => self::out( Optimiser::score_post( (int) $r['id'], (string) $r['phrase'], (string) $r['location'], (string) $r['language'] ) ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r['id'] ),
				'args'                => array(
					'phrase'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'location' => array(
						'type'    => 'string',
						'default' => '',
					),
					'language' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/optimiser/searches',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Optimiser::page( (int) $r['before'], (int) $r['limit'] ) ),
				'permission_callback' => $write,
				'args'                => array(
					'before' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 50,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/optimiser/searches/(?P<key>[0-9a-f]{40})',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( array( 'deleted' => Optimiser::forget( (string) $r['key'] ) ) ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::MANAGE ),
			)
		);
	}

	/**
	 * Add what a read costs and whether it can be paid, so every answer can show the price and
	 * where to buy credit.
	 *
	 * @param array<string, mixed>|\WP_Error $result Result.
	 * @param bool                           $ask    Ask Zinn® for the account (after a spend).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function with_money( $result, bool $ask ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// No copy of the account kept yet: ask Zinn® once (kept five minutes), so the price is on
		// the button before anything is spent.
		$kept = get_option( \ZinnDigital\ZinnSEO\Credits\Credits::OPTION, array() );

		return $result + array( 'money' => Optimiser::money( $ask || ! is_array( $kept ) || ! isset( $kept['view'] ) ) );
	}
}
