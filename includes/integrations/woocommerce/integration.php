<?php
/**
 * WooCommerce (free part): products in search with brand and seller, filtered and sorted shop
 * pages and the basket, checkout and account pages kept out of search results and the sitemap
 * (zs-115 basic, zs-116).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-woo.php';

return static function ( string $context ): void {
	\ZinnDigital\ZinnSEO\Integrations\Woo\Woo::boot( $context );
};
