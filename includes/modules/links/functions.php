<?php
/**
 * Template functions of the links module.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zinn_seo_breadcrumbs' ) ) {
	/**
	 * Print (or return) the breadcrumb trail of the current page, the same trail search engines
	 * get in the page's structured data (zs-105).
	 *
	 * @param array<string, mixed> $args    `home`, `separator`, `show_last`.
	 * @param bool                 $display Print it (true) or return it.
	 * @return string The HTML.
	 */
	function zinn_seo_breadcrumbs( array $args = array(), bool $display = true ): string {
		$html = class_exists( '\ZinnDigital\ZinnSEO\Links\Breadcrumbs' ) ? \ZinnDigital\ZinnSEO\Links\Breadcrumbs::html( $args ) : '';
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the block renderer, every part escaped there.
		}

		return $html;
	}
}
