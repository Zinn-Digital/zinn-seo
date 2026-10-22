<?php
/**
 * Advanced Custom Fields: text kept in fields counts in the content checks (zs-122).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

use ZinnDigital\ZinnSEO\Integrations\Fields\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return static function ( string $context ): void {
	// Only an editor's request runs the analysis: nothing to do on a visitor's page.
	if ( 'front' === $context ) {
		return;
	}
	require_once dirname( __DIR__ ) . '/fields/class-fields.php';
	Fields::boot(
		'acf',
		// A post's fields with their types (`get_field_objects()`): text, text area and rich
		// text, and the same inside repeaters, groups and flexible content.
		static function ( int $post_id ): array {
			if ( ! function_exists( 'get_field_objects' ) ) {
				return array();
			}
			$out = array();
			foreach ( (array) get_field_objects( $post_id ) as $field ) {
				$type = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
				if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
					$out[] = is_scalar( $field['value'] ?? null ) ? (string) $field['value'] : '';
				} elseif ( in_array( $type, array( 'repeater', 'group', 'flexible_content' ), true ) ) {
					foreach ( Fields::strings( $field['value'] ?? array() ) as $text ) {
						$out[] = $text;
					}
				}
			}
			return $out;
		}
	);
};
