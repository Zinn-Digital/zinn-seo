<?php
/**
 * Loads the admin kit's classes. The host plugin requires this file, then calls Kit::boot().
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/load.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnSEO\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-data.php';
require_once __DIR__ . '/class-matrix.php';
require_once __DIR__ . '/class-kit.php';
require_once __DIR__ . '/class-screens.php';
require_once __DIR__ . '/class-licence.php';
require_once __DIR__ . '/class-prefs.php';
require_once __DIR__ . '/class-promotions.php';
require_once __DIR__ . '/class-crypto.php';
require_once __DIR__ . '/class-engine.php';
require_once __DIR__ . '/class-connection.php';
require_once __DIR__ . '/class-diagnostics.php';
require_once __DIR__ . '/class-legacy-support-users.php';
require_once __DIR__ . '/class-support.php';
require_once __DIR__ . '/class-rest.php';
// ⭐ Absent from a WordPress.org build, which fetches nothing from us (translate.wordpress.org
// serves its translations); everywhere else it installs the plugin's language packs.
if ( is_readable( __DIR__ . '/class-language-packs.php' ) ) {
	require_once __DIR__ . '/class-language-packs.php';
}
