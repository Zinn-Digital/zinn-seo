<?php
/**
 * The business itself: who, where, when open — in the site's structured data (zs-110).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-local.php';

return static function ( string $context ): void {
	\ZinnDigital\ZinnSEO\Integrations\Local\Local::boot( $context );
};
