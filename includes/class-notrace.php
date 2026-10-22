<?php
/**
 * No-trace mode: hide that this site uses an SEO plugin (architecture §1.7).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Off on an ordinary install; ON when the site is deployed on the Footprint-Free line (the platform
 * sets `zseo_notrace` or defines `ZSEO_NOTRACE` at install), and offered in the setup.
 *
 * What it changes is owned by the modules that print each fingerprint (the sitemap address, the
 * sitemap stylesheet, JSON-LD fragment ids, the llms.txt header): each asks enabled() and each
 * reports, through `zinn_seo_notrace_changes`, what a switch would change. This class owns the
 * switch itself, the site's stable hash, the warning screen's list and the log.
 *
 * ⛔⛔ A SWITCH IS NEVER SILENT. Turning it on or off can move addresses search engines know (the
 * sitemap), so plan() lists every change first, the screen asks for a confirmation showing that
 * list, and apply() records it and fires `zinn_seo_notrace_changed`, on which the modules put a
 * 301 from each old address to its new one and the Search Console module resubmits.
 */
final class Notrace {

	/** The constant a deploy footprint defines to force the mode (true or false). */
	public const CONSTANT = 'ZSEO_NOTRACE';

	/** The option a platform sets at install, before our settings exist. */
	public const PLATFORM_OPTION = 'zseo_notrace';

	/** Non-autoloaded log of the last switches. */
	public const LOG_OPTION = 'zseo_notrace_log';

	/**
	 * Hooks that only matter while the mode is on (one array read otherwise).
	 *
	 * ⭐ With the mode on, nothing a visitor or a crawler can fetch ties the site to Zinn® SEO or
	 * to another of our sites (zs-747): the sitemap answers at WordPress's own address, the
	 * classes and stylesheet folder of the SEO blocks carry a prefix of this site's own (not the
	 * family's shared `zd`), and the robots tag carries only what the site chose — not the
	 * "no limit" values that every SEO plugin prints and that equal what search engines assume.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'zinn_seo_frontend_prefix', array( self::class, 'prefix' ), 20 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 99 );
		add_filter( 'rest_index', array( self::class, 'rest_index' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest_namespace_index' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'rest_without_fields' ), 99 );
		// ⛔⛔ A footprint-free site is never offered Zinn® Ads in the admin kit (za-35).
		add_filter( 'zseo_kit_footprint_free', '__return_true' );
	}

	/**
	 * Our fields (`_zseo_…`) out of every REST answer a visitor gets: the posts' `meta` values and
	 * the schemas that describe them would otherwise name the plugin on `/wp-json/wp/v2/posts`.
	 * Editors (whose block editor reads and writes these fields) get them as before.
	 *
	 * @param mixed $result The answer.
	 * @return mixed
	 */
	public static function rest_without_fields( $result ) {
		if ( ! $result instanceof \WP_REST_Response || current_user_can( 'edit_posts' ) ) {
			return $result;
		}
		$data = $result->get_data();
		if ( is_array( $data ) ) {
			$result->set_data( self::without_fields( $data ) );
		}

		return $result;
	}

	/**
	 * An array without any key that starts with `_zseo_`, at any depth.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	public static function without_fields( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && 0 === strpos( $key, Meta::PREFIX ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::without_fields( $value );
			}
		}

		return $data;
	}

	/**
	 * The REST API's public index without this plugin's namespaces and routes: `/wp-json/` lists
	 * every plugin's namespace to anyone, which names the plugin. Signed-in editors (whose screens
	 * call these routes) still see them; the routes themselves are untouched.
	 *
	 * @param \WP_REST_Response $response The index.
	 * @return \WP_REST_Response
	 */
	public static function rest_index( $response ) {
		if ( ! $response instanceof \WP_REST_Response || current_user_can( 'edit_posts' ) ) {
			return $response;
		}
		$data = (array) $response->get_data();
		$ours = self::rest_namespaces();
		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values( array_diff( $data['namespaces'], $ours ) );
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				foreach ( $ours as $ns ) {
					if ( 0 === strpos( (string) $route, '/' . $ns ) ) {
						unset( $data['routes'][ $route ] );
					}
				}
			}
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * The REST namespaces this plugin registers: its own and the AI library's.
	 *
	 * @return array<int, string>
	 */
	public static function rest_namespaces(): array {
		return array( Plugin::REST_NS, class_exists( AiCore\Rest::class ) ? AiCore\Rest::NAMESPACE : 'zinn-ai/v1' );
	}

	/**
	 * The namespace's own index (`/wp-json/zinn-seo/v1`) answers "no route" to a visitor, as an
	 * address no plugin registered would.
	 *
	 * @param mixed            $result  A result so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_namespace_index( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request || current_user_can( 'edit_posts' ) ) {
			return $result;
		}
		if ( in_array( ltrim( untrailingslashit( (string) $request->get_route() ), '/' ), self::rest_namespaces(), true ) ) {
			return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'zinn-seo' ), array( 'status' => 404 ) );
		}

		return $result;
	}

	/**
	 * This site's own class prefix: a letter and the site's hash (never a word that names us).
	 *
	 * @param string $prefix The prefix so far.
	 * @return string
	 */
	public static function prefix( $prefix ): string {
		$mine = 'c' . self::hash();
		foreach ( array( 'seo', 'zinn', 'pbs', 'rank', 'yoast' ) as $word ) {
			if ( false !== strpos( $mine, $word ) ) {
				return 'c' . strrev( self::hash() );
			}
		}

		return 1 === preg_match( '/^[a-z][a-z0-9]{0,7}$/', $mine ) ? $mine : (string) $prefix;
	}

	/**
	 * Robots directives without the "no limit" values search engines assume anyway.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		foreach ( array( 'max-snippet', 'max-video-preview' ) as $limit ) {
			if ( isset( $robots[ $limit ] ) && '-1' === (string) $robots[ $limit ] ) {
				unset( $robots[ $limit ] );
			}
		}

		return $robots;
	}

	/**
	 * Is the mode on?
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( defined( self::CONSTANT ) ) {
			return (bool) constant( self::CONSTANT );
		}

		return (bool) Settings::get( 'notrace', false );
	}

	/**
	 * Is the mode forced by the hosting platform (so the screen shows it as managed)?
	 *
	 * @return bool
	 */
	public static function forced(): bool {
		return defined( self::CONSTANT );
	}

	/**
	 * The site's stable six-character hash, used for neutral addresses. Created once, at random,
	 * and kept (a hash derived from the address or the salts would move every time they change).
	 *
	 * @return string
	 */
	public static function hash(): string {
		$hash = (string) Settings::get( 'notrace_hash', '' );
		if ( 1 === preg_match( '/^[a-z0-9]{6}$/', $hash ) ) {
			return $hash;
		}
		$hash = strtolower( wp_generate_password( 6, false, false ) );
		Settings::save( array( 'notrace_hash' => $hash ), 'system' );

		return $hash;
	}

	/**
	 * Is this site on our Footprint-Free (PBN) hosting? The platform writes the constant into the
	 * site's wp-config.php (engine `site_constants`, product line `footprint_free`).
	 *
	 * @return bool
	 */
	public static function footprint_free_host(): bool {
		return defined( 'ZINN_FOOTPRINT_FREE' ) && true === constant( 'ZINN_FOOTPRINT_FREE' );
	}

	/**
	 * Take over a value the platform left at install, once (then the setting is the truth).
	 *
	 * @return void
	 */
	public static function adopt_platform_value(): void {
		$value = get_option( self::PLATFORM_OPTION, null );
		if ( null === $value ) {
			return;
		}
		delete_option( self::PLATFORM_OPTION );
		self::apply( (bool) $value, 'platform' );
	}

	/**
	 * What a switch to `$on` would change, without changing it.
	 *
	 * @param bool $on The mode after the switch.
	 * @return array<int, array{what: string, from: string, to: string, resubmit: bool}>
	 */
	public static function plan( bool $on ): array {
		if ( self::enabled() === $on ) {
			return array();
		}

		/**
		 * Filters what a switch of the no-trace mode would change. Each module that prints a
		 * fingerprint adds a row: a label, the old and new value, and whether search engines
		 * should be told (a sitemap address).
		 *
		 * @param array<int, array<string, mixed>> $changes Changes.
		 * @param bool                             $on      The mode after the switch.
		 */
		$changes = (array) apply_filters( 'zinn_seo_notrace_changes', array(), $on );
		$out     = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['what'] ) ) {
				continue;
			}
			$out[] = array(
				'what'     => (string) $change['what'],
				'from'     => (string) ( $change['from'] ?? '' ),
				'to'       => (string) ( $change['to'] ?? '' ),
				'resubmit' => ! empty( $change['resubmit'] ),
			);
		}

		return $out;
	}

	/**
	 * Switch the mode, record it and tell the modules.
	 *
	 * @param bool   $on    The mode after the switch.
	 * @param string $actor Who switched: user, setup, platform, agent, import.
	 * @return array{enabled: bool, changes: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function apply( bool $on, string $actor = 'user' ) {
		if ( self::forced() ) {
			return new \WP_Error( 'zinn_seo_notrace_forced', __( 'Your hosting manages this setting for this site.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$changes = self::plan( $on );
		if ( self::enabled() === $on ) {
			return array(
				'enabled' => $on,
				'changes' => array(),
			);
		}
		if ( $on ) {
			self::hash();
		}
		$saved = Settings::save( array( 'notrace' => $on ), $actor );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'on'      => $on,
				'at'      => time(),
				'actor'   => $actor,
				'user'    => get_current_user_id(),
				'changes' => $changes,
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 20 ), false );

		/**
		 * Fires after the no-trace mode was switched. Modules redirect old addresses here.
		 *
		 * @param bool                             $on      The mode now.
		 * @param array<int, array<string, mixed>> $changes What changed.
		 * @param string                           $actor   Who switched.
		 */
		do_action( 'zinn_seo_notrace_changed', $on, $changes, $actor );

		// The SEO blocks' classes and stylesheet folder follow the mode: copy their stylesheets
		// under the new prefix now, so no page asks for a file that is not there yet.
		if ( class_exists( '\ZinnDigital\ZinnSEO\Blocks\Assets' ) && class_exists( '\ZinnDigital\ZinnSEO\Blocks\Html' ) ) {
			remove_filter( 'zinn_seo_frontend_prefix', array( self::class, 'prefix' ), 20 );
			if ( $on ) {
				add_filter( 'zinn_seo_frontend_prefix', array( self::class, 'prefix' ), 20 );
			}
			\ZinnDigital\ZinnSEO\Blocks\Html::flush();
			if ( is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				\ZinnDigital\ZinnSEO\Blocks\Assets::publish();
			}
		}

		return array(
			'enabled' => $on,
			'changes' => $changes,
		);
	}

	/**
	 * The state for screens and REST.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return array(
			'enabled' => self::enabled(),
			'forced'  => self::forced(),
			'log'     => is_array( $log ) ? array_slice( $log, 0, 5 ) : array(),
		);
	}
}
