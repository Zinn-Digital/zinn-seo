<?php
/**
 * REST for the templates screen: what exists, and a live preview on a real page of the site.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Templates;

use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Rest;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /templates` lists every context the site has with its stored record and its defaults, the
 * separators and the variables. `POST /templates/preview` renders a draft template on a real
 * example (the newest published post of the type, the term with the most posts, …), so the
 * person sees the title a visitor will get before saving anything. Saving is the ordinary
 * `PUT /settings` with the `templates` key: one write path for the screen, REST, WP-CLI and MCP.
 */
final class Templates_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		\ZinnDigital\ZinnSEO\Plugin::on_rest_api_init( array( self::class, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Plugin::REST_NS,
			'/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'describe' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/templates/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'preview' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
				'args'                => array(
					'key'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9][a-z0-9_:-]{0,80}$',
					),
					'title' => array(
						'type'    => 'string',
						'default' => '',
					),
					'desc'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'sep'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * GET /templates.
	 *
	 * @return \WP_REST_Response
	 */
	public static function describe(): \WP_REST_Response {
		$stored = (array) Settings::get( 'templates', array() );
		$list   = array();
		foreach ( Templates::contexts() as $key => $row ) {
			// PHP turns the key "404" into an integer.
			$key    = (string) $key;
			$list[] = array(
				'key'      => $key,
				'label'    => $row['label'],
				'group'    => $row['group'],
				'stored'   => (object) ( $stored[ $key ] ?? array() ),
				'defaults' => Templates::defaults( $key ),
			);
		}
		$vars = array();
		foreach ( Vars::catalogue() as $name => $row ) {
			$vars[] = array(
				'name'  => $name,
				'label' => (string) call_user_func( $row['label'] ),
				'where' => $row['where'],
			);
		}

		return rest_ensure_response(
			array(
				'contexts'   => $list,
				'separators' => Templates::separators(),
				'variables'  => $vars,
			)
		);
	}

	/**
	 * POST /templates/preview: render draft templates on an example of the context.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$key = (string) $request['key'];
		if ( ! array_key_exists( $key, Templates::contexts() ) ) {
			return new \WP_Error( 'zinn_seo_unknown_context', __( 'There is no such kind of page on this site.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$c       = self::example( $key );
		$sep     = in_array( (string) $request['sep'], Templates::separators(), true ) ? (string) $request['sep'] : Resolver::sep();
		$lookup  = Resolver::lookup( $c );
		$tpl     = Templates::effective( $key );
		$title   = '' !== (string) $request['title'] ? (string) $request['title'] : (string) $tpl['title'];
		$desc    = '' !== (string) $request['desc'] ? (string) $request['desc'] : (string) $tpl['desc'];
		$t_out   = Vars::render( $title, $lookup, $sep );
		$d_out   = Vars::trim_text( Vars::render( $desc, $lookup, $sep ), Resolver::DESC_LENGTH );
		$address = Resolver::address( $c );
		$unknown = array();
		foreach ( array_merge( Vars::used( $title ), Vars::used( $desc ) ) as $name ) {
			if ( null === Resolver::value( $c, $name ) && 'sep' !== $name ) {
				$unknown[] = $name;
			}
		}

		return rest_ensure_response(
			array(
				'title'   => $t_out,
				'desc'    => $d_out,
				'url'     => '' !== $address ? $address : home_url( '/' ),
				'example' => self::example_label( $c ),
				'unknown' => array_values( array_unique( $unknown ) ),
			)
		);
	}

	/**
	 * A real example of a context.
	 *
	 * @param string $key Context key.
	 * @return Context
	 */
	public static function example( string $key ): Context {
		if ( 0 === strpos( $key, 'post:' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => substr( $key, 5 ),
					'post_status'    => 'attachment' === substr( $key, 5 ) ? 'inherit' : 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			if ( isset( $posts[0] ) && $posts[0] instanceof \WP_Post ) {
				return Context::for_post( $posts[0] );
			}
		}
		if ( 0 === strpos( $key, 'tax:' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => substr( $key, 4 ),
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'hide_empty' => false,
				)
			);
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				return Context::for_term( $terms[0] );
			}
		}
		if ( 'home' === $key && 'page' === get_option( 'show_on_front' ) ) {
			$front = get_post( (int) get_option( 'page_on_front' ) );
			if ( $front instanceof \WP_Post ) {
				return Context::for_post( $front );
			}
		}
		$c = Context::for_key( $key );
		if ( null === $c->object && ( 0 === strpos( $key, 'post:' ) || 0 === strpos( $key, 'tax:' ) ) ) {
			// Nothing of this kind exists yet: show the template with an invented title.
			$c->kind = 'other';
		}

		return $c;
	}

	/**
	 * What the example is, in words.
	 *
	 * @param Context $c Context.
	 * @return string
	 */
	private static function example_label( Context $c ): string {
		if ( $c->post() ) {
			return (string) get_the_title( $c->post() );
		}
		if ( $c->term() ) {
			return (string) $c->term()->name;
		}

		return '';
	}
}
