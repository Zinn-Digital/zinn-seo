<?php
/**
 * Loads the analysis classes where they are used (never on a visitor's page).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Analysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pure analysis classes (Doc, Text, Result, Checks) and the readability module's classes are
 * required on demand: by this module's boot (admin, REST, CLI, cron) and by any other module that
 * reads a page (links, duplicates, the inspector), so a visitor's request parses none of them.
 */
final class Loader {

	/**
	 * Require the classes once.
	 *
	 * @return void
	 */
	public static function classes(): void {
		require_once __DIR__ . '/class-doc.php';
		require_once __DIR__ . '/class-text.php';
		require_once __DIR__ . '/class-result.php';
		require_once __DIR__ . '/class-checks.php';
		require_once dirname( __DIR__ ) . '/readability/class-language.php';
		require_once dirname( __DIR__ ) . '/readability/class-readability.php';
	}
}
