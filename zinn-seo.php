<?php

/**
 * Plugin Name:       Zinn® SEO
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/
 * Description:       Complete search engine and AI visibility optimisation for WordPress, built for speed: nothing added to your visitors' pages except the tags search engines read, settings in one place, and a guided setup.
 * Version:           0.17.64
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-seo
 * Domain Path:       /languages
 *
 * @package ZinnDigital\ZinnSEO
 *
 * ⛔⛔ THIS FILE IS WRITTEN IN THE LICENSING SERVICE'S OWN PRINT, NOT IN WPCS STYLE. Freemius
 * re-prints the file that calls fs_dynamic_init() (four-space indent, no blank lines between
 * statements, `!$x`); every other file reaches its free package byte-for-byte (docs/adr/0031).
 * Keep it to headers, constants, the SDK init and one require; everything else lives in includes/.
 *
 * ⛔ No `Update URI` header and no secret key in this SOURCE. Freemius adds the header to the
 * premium download only. The SDK needs only the PUBLIC key below (product 41086).
 */
defined( 'ABSPATH' ) || exit;
if ( function_exists( 'zinn_seo_fs' ) ) {
    zinn_seo_fs()->set_basename( false, __FILE__ );
    return;
}
define( 'ZINN_SEO_VERSION', '0.17.64' );
define( 'ZINN_SEO_FILE', __FILE__ );
define( 'ZINN_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZINN_SEO_URL', plugin_dir_url( __FILE__ ) );
if ( !function_exists( 'zinn_seo_fs' ) ) {
    /**
     * The licensing SDK instance for this plugin (Freemius product 41086).
     *
     * @return Freemius
     */
    function zinn_seo_fs() {
        global $zinn_seo_fs;
        if ( !isset( $zinn_seo_fs ) ) {
            require_once __DIR__ . '/includes/class-sdk-guard.php';
            \ZinnDigital\ZinnSEO\Sdk_Guard::arm();
            require_once __DIR__ . '/vendor/freemius/start.php';
            $zinn_seo_fs = fs_dynamic_init( array(
                'id'                             => '41086',
                'slug'                           => 'zinn-seo',
                'premium_slug'                   => 'zinn-seo-premium',
                'type'                           => 'plugin',
                'public_key'                     => 'pk_e3774f904dabf70893a2cf758ca8e',
                'bundle_id'                      => '40216',
                'bundle_public_key'              => 'pk_1bcbfe8657c755d37d4b8a4c29f46',
                'bundle_license_auto_activation' => true,
                'is_premium'                     => false,
                'premium_suffix'                 => 'Pro',
                'has_addons'                     => false,
                'has_paid_plans'                 => true,
                'trial'                          => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'is_org_compliant'               => true,
                'menu'                           => array(
                    'slug'       => 'zinn-seo',
                    'first-path' => 'admin.php?page=zinn-seo',
                    'contact'    => false,
                    'support'    => false,
                ),
                'anonymous_mode'                 => defined( 'ZINN_SITE_EVENTS_URL' ),
                'is_live'                        => true,
            ) );
        }
        return $zinn_seo_fs;
    }

    zinn_seo_fs();
    zinn_seo_fs()->add_action( 'after_uninstall', 'zinn_seo_uninstall' );
    // The SDK's screens show THIS icon. Without a local icon the SDK downloads one from the
    // licensing service on a non-hosted install, before any consent.
    zinn_seo_fs()->add_filter( 'plugin_icon', static fn() => __DIR__ . '/assets/icon-256x256.png' );
    /**
     * Fires once the licensing SDK has started, before the plugin itself loads.
     */
    do_action( 'zinn_seo_fs_loaded' );
}
require_once __DIR__ . '/includes/bootstrap.php';