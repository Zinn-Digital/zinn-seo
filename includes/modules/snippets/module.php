<?php
/**
 * Module: snippet controls — keep any block or passage out of search-result snippets, and set a
 * page's snippet limits (zs-741).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'snippets',
	'name'        => static fn() => __( 'Snippet controls', 'zinn-seo' ),
	'description' => static fn() => __( 'Choose what Google may quote from a page: hide any block or passage from the search result snippet, or limit the snippet and video preview, and see the result in the search preview.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'appearance',
	'features'    => array( 'zs-741' ),
	'files'       => array( 'class-snippets.php' ),
	'contexts'    => array( 'front', 'admin', 'rest', 'cli' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Snippets\Snippets::boot();
		if ( 'admin' === $context ) {
			add_filter(
				'zinn_seo_editor_data',
				static function ( $data ) {
					$data              = (array) $data;
					$data['nosnippet']           = true;
					$data['guides']['snippets'] = \ZinnDigital\ZinnSEO\Guide::url( 'snippets' );
					return $data;
				}
			);
		}
	},
);
