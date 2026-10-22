<?php
/**
 * The guided setup: what it found, which steps are done, where to continue (zs-240, zs-241).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The setup is a list of STEPS, each owned by the code it configures (the core's below; the
 * importer, the first audit and others add theirs with `zinn_seo_setup_steps`). Progress is stored
 * per step — done, skipped, or not yet — so the setup continues where it stopped, any step can be
 * re-run on its own later, and every step can be skipped.
 *
 * Detection runs on the server and is read-only: the site type, other SEO plugins, multilingual
 * plugins, WooCommerce, and whether search engines are discouraged.
 */
final class Setup {

	/** Non-autoloaded progress. */
	public const OPTION = 'zseo_setup';

	/**
	 * SEO plugins we recognise: main file => name. Detection only; importing is its own step.
	 *
	 * @return array<string, string>
	 */
	public static function known_seo_plugins(): array {
		return array(
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'wordpress-seo-premium/wp-seo-premium.php'    => 'Yoast SEO Premium',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math',
			'seo-by-rank-math-pro/rank-math-pro.php'      => 'Rank Math Pro',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => 'All in One SEO Pro',
			'wp-seopress/seopress.php'                    => 'SEOPress',
			'wp-seopress-pro/seopress-pro.php'            => 'SEOPress Pro',
			'autodescription/autodescription.php'         => 'The SEO Framework',
			'slim-seo/slim-seo.php'                       => 'Slim SEO',
			'squirrly-seo/squirrly.php'                   => 'Squirrly SEO',
			'smartcrawl-seo/wpmu-dev-seo.php'             => 'SmartCrawl',
			'wp-meta-seo/wp-meta-seo.php'                 => 'WP Meta SEO',
			// The long tail (zs-725).
			'surerank/surerank.php'                       => 'SureRank',
			'seo-ultimate/seo-ultimate.php'               => 'SEO Ultimate',
			'premium-seo-pack/index.php'                  => 'Premium SEO Pack',
		);
	}

	/**
	 * Multilingual plugins we recognise: main file => name.
	 *
	 * @return array<string, string>
	 */
	public static function known_language_plugins(): array {
		return array(
			'tranzly/tranzly.php'                      => 'Tranzly',
			'tranzly-premium/tranzly.php'              => 'Tranzly Pro',
			'sitepress-multilingual-cms/sitepress.php' => 'WPML',
			'polylang/polylang.php'                    => 'Polylang',
			'polylang-pro/polylang.php'                => 'Polylang Pro',
			'translatepress-multilingual/index.php'    => 'TranslatePress',
			'weglot/weglot.php'                        => 'Weglot',
		);
	}

	/**
	 * What the setup found on this site.
	 *
	 * @return array<string, mixed>
	 */
	public static function detect(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active = array_map( 'strval', $active );
		$seo    = array();
		foreach ( self::known_seo_plugins() as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$seo[] = array(
					'file' => $file,
					'name' => $name,
				);
			}
		}
		$languages = array();
		foreach ( self::known_language_plugins() as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$languages[] = array(
					'file' => $file,
					'name' => $name,
				);
			}
		}
		$woo = class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', $active, true );

		return array(
			'site_type'        => self::guess_site_type( $woo ),
			'seo_plugins'      => $seo,
			'language_plugins' => $languages,
			'locale'           => get_locale(),
			'woocommerce'      => $woo,
			'community'        => class_exists( 'bbPress' ) || class_exists( 'BuddyPress' ),
			'discouraged'      => '0' === (string) get_option( 'blog_public', '1' ),
			'multisite'        => is_multisite(),
			'site_name'        => (string) get_bloginfo( 'name' ),
			'logo'             => (int) get_theme_mod( 'custom_logo', 0 ),
		);
	}

	/**
	 * A first guess at the kind of site, for the setup's first question.
	 *
	 * @param bool $woo WooCommerce is active.
	 * @return string
	 */
	public static function guess_site_type( bool $woo ): string {
		if ( $woo ) {
			return 'shop';
		}
		if ( class_exists( 'bbPress' ) || class_exists( 'BuddyPress' ) ) {
			return 'community';
		}
		$posts = (int) ( wp_count_posts( 'post' )->publish ?? 0 );
		$pages = (int) ( wp_count_posts( 'page' )->publish ?? 0 );

		return $posts > $pages ? 'blog' : 'business';
	}

	/**
	 * The steps, in order: id => {title, section}. Labels are for screens; the React app renders
	 * each core step itself and a module's step through its own component.
	 *
	 * @return array<string, array{title: string, section: string}>
	 */
	public static function steps(): array {
		$core = array(
			'site'       => array(
				'title'   => __( 'Your site', 'zinn-seo' ),
				'section' => 'site',
			),
			'identity'   => array(
				'title'   => __( 'Who is behind it', 'zinn-seo' ),
				'section' => 'site',
			),
			'plugins'    => array(
				'title'   => __( 'Other SEO plugins', 'zinn-seo' ),
				'section' => 'plugins',
			),
			'visibility' => array(
				'title'   => __( 'Search engines', 'zinn-seo' ),
				'section' => 'visibility',
			),
			'privacy'    => array(
				'title'   => __( 'Footprint', 'zinn-seo' ),
				'section' => 'privacy',
			),
		);

		/**
		 * Filters the setup steps. A module adds its own (id => {title, section}).
		 *
		 * @param array<string, array{title: string, section: string}> $steps Steps in order.
		 */
		return (array) apply_filters( 'zinn_seo_setup_steps', $core );
	}

	/**
	 * Stored progress.
	 *
	 * @return array{steps: array<string, string>, started: int, finished: int}
	 */
	public static function progress(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'steps'    => array_map( 'strval', (array) ( $stored['steps'] ?? array() ) ),
			'started'  => (int) ( $stored['started'] ?? 0 ),
			'finished' => (int) ( $stored['finished'] ?? 0 ),
		);
	}

	/**
	 * The step to continue at: the first one neither done nor skipped.
	 *
	 * @return string Empty when every step has an answer.
	 */
	public static function next_step(): string {
		$progress = self::progress();
		foreach ( array_keys( self::steps() ) as $id ) {
			if ( ! isset( $progress['steps'][ $id ] ) ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Mark a step done or skipped, or forget it (to run it again), or start over.
	 *
	 * @param string $step   Step id, or empty with `reset`.
	 * @param string $action done|skip|redo|reset.
	 * @return array<string, mixed>|\WP_Error The state.
	 */
	public static function mark( string $step, string $action ) {
		$progress = self::progress();
		if ( 'reset' === $action ) {
			delete_option( self::OPTION );
			return self::state();
		}
		if ( ! array_key_exists( $step, self::steps() ) ) {
			/* translators: %s: a setup step id. */
			return new \WP_Error( 'zinn_seo_unknown_step', sprintf( __( 'There is no setup step called %s.', 'zinn-seo' ), $step ), array( 'status' => 404 ) );
		}
		if ( 'redo' === $action ) {
			unset( $progress['steps'][ $step ] );
			$progress['finished'] = 0;
		} elseif ( in_array( $action, array( 'done', 'skip' ), true ) ) {
			$progress['steps'][ $step ] = 'done' === $action ? 'done' : 'skipped';
		} else {
			return new \WP_Error( 'zinn_seo_bad_action', __( 'Unknown setup action.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( 0 === $progress['started'] ) {
			$progress['started'] = time();
		}
		$all_answered = true;
		foreach ( array_keys( self::steps() ) as $id ) {
			if ( ! isset( $progress['steps'][ $id ] ) ) {
				$all_answered = false;
				break;
			}
		}
		if ( $all_answered && 0 === $progress['finished'] ) {
			$progress['finished'] = time();
		}
		update_option( self::OPTION, $progress, false );

		return self::state();
	}

	/**
	 * Everything the setup screen needs.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$steps = array();
		$prog  = self::progress();
		foreach ( self::steps() as $id => $step ) {
			$steps[] = array(
				'id'      => (string) $id,
				'title'   => (string) $step['title'],
				'section' => (string) $step['section'],
				'status'  => $prog['steps'][ $id ] ?? 'todo',
			);
		}

		return array(
			'steps'    => $steps,
			'next'     => self::next_step(),
			'started'  => $prog['started'],
			'finished' => $prog['finished'],
			'found'    => self::detect(),
		);
	}
}
