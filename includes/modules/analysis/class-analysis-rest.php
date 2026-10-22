<?php
/**
 * REST routes of the content analysis: run, store, list and score everything.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Jobs;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Readability\Language;
use ZinnDigital\ZinnSEO\Readability\Readability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - `POST /analysis/post/{id}`: analyse with the editor's unsaved values (`content`, `title`,
 *   `slug`, `excerpt`, `seo_title`, `seo_desc`, `focus`, `related`, `cornerstone`, `noindex`);
 *   `store: true` (after a save) stores the scores.
 * - `GET /analysis/post/{id}`: the saved post's analysis.
 * - `GET /analysis/list`: the bulk view (zs-011), keyset paged by ID, filtered by rating.
 * - `POST /analysis/score-all`: start the job that scores every page.
 * - `GET /analysis/languages`: what the readability checks measure in each of the site's languages.
 */
final class Analysis_Rest {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
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
		$done = true;
		$post = static fn( \WP_REST_Request $r ): bool => Capabilities::can_edit_post( (int) $r['id'] );
		register_rest_route(
			Plugin::REST_NS,
			'/analysis/post/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => $post,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'post' ),
					'permission_callback' => $post,
					'args'                => array(
						'content'     => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'excerpt'     => array( 'type' => 'string' ),
						'seo_title'   => array( 'type' => 'string' ),
						'seo_desc'    => array( 'type' => 'string' ),
						'focus'       => array( 'type' => 'string' ),
						'related'     => array( 'type' => 'string' ),
						'cornerstone' => array( 'type' => 'boolean' ),
						'noindex'     => array( 'type' => 'boolean' ),
						'store'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/analysis/list',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::EDIT ),
				'args'                => array(
					'type'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'rating' => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'good', 'ok', 'bad', 'none', 'cornerstone', 'nofocus' ),
					),
					'issue'  => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-z0-9_]{0,40}$',
					),
					'before' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'per'    => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/analysis/score-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					$started = Analysis::start_all();
					return is_wp_error( $started ) ? $started : rest_ensure_response( Jobs::view( Analysis::JOB ) );
				},
				'permission_callback' => static fn(): bool => Capabilities::can_manage(),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/analysis/languages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'languages' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || current_user_can( Capabilities::EDIT ),
			)
		);
	}

	/**
	 * GET /analysis/post/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Analysis::types(), true ) ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID can be analysed.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$result = Analysis::run( $post );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /analysis/post/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function post( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Analysis::types(), true ) ) {
			return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID can be analysed.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$draft = array();
		foreach ( array( 'content', 'title', 'slug', 'excerpt', 'seo_title', 'seo_desc', 'focus', 'related' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$draft[ $key ] = (string) $request->get_param( $key );
			}
		}
		foreach ( array( 'cornerstone', 'noindex' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$draft[ $key ] = (bool) $request->get_param( $key );
			}
		}
		if ( $request->get_param( 'store' ) ) {
			// Stored from the SAVED post, so the list never shows a score for unsaved text.
			$result = Analysis::score( (int) $post->ID );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( array() === $draft ) {
				return rest_ensure_response( $result );
			}
		}
		$result = Analysis::run( $post, $draft );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /analysis/list — newest first, keyset by ID (`before`), no page count (zs-157).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list( \WP_REST_Request $request ) {
		global $wpdb;
		$types = Analysis::types();
		$type  = (string) $request['type'];
		// A type that is not scored lists nothing (Q277); it never widens to every type.
		if ( '' !== $type && ! in_array( $type, $types, true ) ) {
			return rest_ensure_response(
				array(
					'items'  => array(),
					'next'   => 0,
					'job'    => Jobs::view( Analysis::JOB ),
					'types'  => array(),
					'hidden' => class_exists( '\ZinnDigital\ZinnSEO\Templates\Content_Types' ) ? count( array_diff( array_keys( \ZinnDigital\ZinnSEO\Templates\Content_Types::post_types() ), $types, array( 'attachment' ) ) ) : 0,
				)
			);
		}
		$types  = '' !== $type ? array( $type ) : $types;
		$per    = (int) $request['per'];
		$before = (int) $request['before'];
		$rating = (string) $request['rating'];
		$join   = '';
		$where  = '';
		switch ( $rating ) {
			case 'good':
			case 'ok':
			case 'bad':
				$range = array(
					'good' => array( 75, 100 ),
					'ok'   => array( 50, 74 ),
					'bad'  => array( 0, 49 ),
				)[ $rating ];
				$join  = " JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_zseo_score'";
				$where = $wpdb->prepare( ' AND CAST(s.meta_value AS SIGNED) BETWEEN %d AND %d', $range[0], $range[1] );
				break;
			case 'none':
				$join  = " LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_zseo_scored'";
				$where = ' AND s.post_id IS NULL';
				break;
			case 'cornerstone':
				$join = " JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_zseo_cornerstone' AND s.meta_value = '1'";
				break;
			case 'nofocus':
				$join  = " LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_zseo_focus'";
				$where = ' AND s.post_id IS NULL';
				break;
		}
		$issue = (string) $request['issue'];
		if ( '' !== $issue ) {
			$join  .= " JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_zseo_issues'";
			$where .= $wpdb->prepare( " AND CONCAT(',', i.meta_value, ',') LIKE %s", '%,' . $wpdb->esc_like( $issue ) . ',%' );
		}
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args = $types;
		$sql  = "SELECT p.ID FROM {$wpdb->posts} p{$join} WHERE p.post_type IN ({$in}) AND p.post_status IN ('publish','future','draft','pending','private'){$where}";
		if ( $before > 0 ) {
			$sql   .= ' AND p.ID < %d';
			$args[] = $before;
		}
		$sql   .= ' ORDER BY p.ID DESC LIMIT %d';
		$args[] = $per + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every part above is a placeholder or a prepared fragment.
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
		$more = count( $ids ) > $per;
		$ids  = array_slice( $ids, 0, $per );
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		$rows = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p instanceof \WP_Post ) {
				continue;
			}
			$score  = get_post_meta( $id, '_zseo_score', true );
			$read   = get_post_meta( $id, '_zseo_rscore', true );
			$focus  = Analysis::phrases( (string) Meta::get_post( $id, 'focus' ), true );
			$issues = (string) get_post_meta( $id, '_zseo_issues', true );
			$rows[] = array(
				'id'          => $id,
				'title'       => '' !== $p->post_title ? $p->post_title : __( '(no title)', 'zinn-seo' ),
				'type'        => $p->post_type,
				'status'      => $p->post_status,
				'edit'        => (string) get_edit_post_link( $id, 'raw' ),
				'view'        => (string) get_permalink( $p ),
				'score'       => '' === $score ? null : (int) $score,
				'readability' => '' === $read ? null : (int) $read,
				'focus'       => array() !== $focus ? $focus[0]['phrase'] : '',
				'cornerstone' => (bool) Meta::get_post( $id, 'cornerstone' ),
				'issues'      => '' === $issues ? array() : explode( ',', $issues ),
				'inbound'     => Analysis::inbound( $id ),
			);
		}

		// The type filter's choices, and how many types are left out of scores (Q277: the screen
		// says so and links to where it is chosen).
		$scored = Analysis::types();
		$choice = array();
		foreach ( $scored as $name ) {
			$object   = get_post_type_object( $name );
			$choice[] = array(
				'value' => $name,
				'label' => is_object( $object ) && isset( $object->labels->name ) ? (string) $object->labels->name : $name,
			);
		}
		$hidden = class_exists( '\ZinnDigital\ZinnSEO\Templates\Content_Types' ) ? count( array_diff( array_keys( \ZinnDigital\ZinnSEO\Templates\Content_Types::post_types() ), $scored, array( 'attachment' ) ) ) : 0;

		return rest_ensure_response(
			array(
				'items'  => $rows,
				'next'   => $more && array() !== $ids ? (int) end( $ids ) : 0,
				'job'    => Jobs::view( Analysis::JOB ),
				'types'  => $choice,
				'hidden' => $hidden,
			)
		);
	}

	/**
	 * GET /analysis/languages — every language of the site and what runs in it.
	 *
	 * @return \WP_REST_Response
	 */
	public static function languages() {
		$locales = array( (string) get_locale() );
		if ( function_exists( 'tranzly_languages' ) ) {
			foreach ( (array) call_user_func( 'tranzly_languages' ) as $lang ) {
				$code = is_array( $lang ) ? (string) ( $lang['code'] ?? $lang['locale'] ?? '' ) : (string) $lang;
				if ( '' !== $code ) {
					$locales[] = $code;
				}
			}
		}
		$rows = array();
		foreach ( array_unique( $locales ) as $locale ) {
			$profile = Language::profile( $locale );
			$rows[]  = array(
				'locale'      => $locale,
				'base'        => (string) $profile['base'],
				'unit'        => (string) $profile['unit'],
				'curated'     => (bool) $profile['curated'],
				'transitions' => (string) $profile['transitions_source'],
				'passive'     => ! empty( $profile['passive'] ),
				'ease'        => isset( Readability::FLESCH[ (string) ( $profile['flesch'] ?? '' ) ] ),
				'inclusive'   => array() !== Readability::inclusive_terms( (string) $profile['base'] ),
			);
		}

		return rest_ensure_response(
			array(
				'languages' => $rows,
				'curated'   => array_keys( Language::all() ),
			)
		);
	}
}
