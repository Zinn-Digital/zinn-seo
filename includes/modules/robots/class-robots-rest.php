<?php
/**
 * REST: the robots.txt editor, its checks, the address tester and a real file's take-over.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Robots;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET|PUT /robots`, `POST /robots/test`, `POST /robots/file` (import or move aside a real file).
 */
final class Robots_Rest {

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
		$manage = static fn(): bool => Capabilities::can_manage();
		register_rest_route(
			Plugin::REST_NS,
			'/robots',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put' ),
					'permission_callback' => $manage,
					'args'                => array(
						'custom' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/robots/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'test' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
				'args'                => array(
					'url'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'agent'  => array(
						'type'    => 'string',
						'default' => 'Googlebot',
					),
					'custom' => array(
						'type' => 'string',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/robots/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'preview' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW ) || Capabilities::can_manage(),
				'args'                => array(
					'custom' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/robots/file',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'file' ),
				// Renaming a file in the site's folder: administrators only.
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'import', 'move' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Everything the screen shows.
	 *
	 * @param string|null $custom A draft of the owner's rules (null: the stored ones).
	 * @return array<string, mixed>
	 */
	public static function describe( ?string $custom = null ): array {
		$custom  = $custom ?? Robots::custom();
		$public  = '0' !== (string) get_option( 'blog_public', '1' );
		$staging = Staging::reason();
		$text    = Robots::compose( $custom, $public && '' === $staging );
		$home    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return array(
			'url'         => home_url( '/robots.txt' ),
			'text'        => $text,
			'custom'      => $custom,
			'checks'      => Rules::check( $text ),
			'own_checks'  => Rules::check( $custom ),
			'physical'    => Robots::physical_file(),
			'discouraged' => ! $public,
			'staging'     => $staging,
			'subfolder'   => '/' !== $home && '' !== $home,
			'defaults'    => (bool) \ZinnDigital\ZinnSEO\Settings::get( 'robots_defaults', true ),
		);
	}

	/**
	 * GET /robots.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		return rest_ensure_response( self::describe() );
	}

	/**
	 * PUT /robots: store the owner's rules (refused only when too large to be read whole).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put( \WP_REST_Request $request ) {
		$custom = Robots::clean( (string) $request['custom'] );
		if ( strlen( $custom ) > Rules::MAX_BYTES ) {
			return new \WP_Error( 'zinn_seo_robots_too_big', __( 'Search engines read only the first 500 KB of robots.txt. Shorten the rules and save again.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$before = Robots::custom();
		update_option( Robots::CUSTOM, $custom, false );

		/**
		 * Fires after the owner's robots.txt rules changed.
		 *
		 * @param string $custom Rules now.
		 * @param string $before Rules before.
		 */
		do_action( 'zinn_seo_robots_saved', $custom, $before );

		return rest_ensure_response( self::describe() );
	}

	/**
	 * POST /robots/preview: the whole text and its checks with a draft of the owner's rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function preview( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( self::describe( Robots::clean( (string) $request['custom'] ) ) );
	}

	/**
	 * POST /robots/test: may this crawler fetch this address (with the saved or a draft text)?
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function test( \WP_REST_Request $request ): \WP_REST_Response {
		$custom = null !== $request['custom'] ? Robots::clean( (string) $request['custom'] ) : null;
		$d      = self::describe( $custom );
		$agent  = sanitize_text_field( (string) $request['agent'] );
		// Not sanitize_text_field(): it removes percent-encoded bytes, which addresses carry.
		$url    = trim( wp_strip_all_tags( wp_check_invalid_utf8( (string) $request['url'] ) ) );
		$result = Rules::test( (string) $d['text'], '' === $agent ? '*' : $agent, $url );

		return rest_ensure_response( $result + array( 'path' => Rules::path_of( $url ) ) );
	}

	/**
	 * POST /robots/file: bring a real robots.txt's rules into the editor, or move the file aside
	 * (renamed, never deleted) so the editor's text is served.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function file( \WP_REST_Request $request ) {
		$path = Robots::physical_path();
		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'zinn_seo_no_robots_file', __( 'There is no robots.txt file in the site\'s folder.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( 'import' === $request['action'] ) {
			$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the site's own folder.
			$text = trim( (string) preg_replace( '/^Sitemap:.*$/mi', '', Robots::clean( $text ) ) );
			return rest_ensure_response(
				array(
					'custom' => $text,
				)
			);
		}
		$target = $path . '.zinn-seo-' . gmdate( 'Ymd-His' ) . '.bak';
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem instanceof \WP_Filesystem_Base || ! $wp_filesystem->move( $path, $target ) ) {
			return new \WP_Error( 'zinn_seo_robots_move_failed', __( 'The file could not be renamed (the site\'s folder is not writable). Rename or delete robots.txt with your host\'s file manager.', 'zinn-seo' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( self::describe() + array( 'moved_to' => basename( $target ) ) );
	}
}
