<?php
/**
 * The typed fields every SEO block is described by: one schema, read by every builder adapter.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A block's `fields()` is the ONE description of what it holds (zs-300). From it come the block
 * editor's attributes and form, the Elementor widget's controls, the shortcode's attributes and
 * the cleaning of whatever any of them sends — so a field added once appears everywhere.
 *
 * Field definition keys: `type` (text, textarea, number, bool, select, url, image, date, user,
 * list, repeater), `label` (string or callable), `default`, `help`, `choices` (value => label,
 * for select), `min`/`max` (number; for text the maximum length), `fields` (a repeater's own
 * fields), `content` (true: words a reader sees — Tranzly translates these, ADR 0033 role
 * `content`), `placeholder`.
 */
final class Fields {

	/** Text longer than this is cut (a block is not a document). */
	public const TEXT_MAX = 20000;

	/** A repeater holds at most this many rows (a FAQ of 200 questions is already a page). */
	public const ROWS_MAX = 200;

	/**
	 * Clean raw values (from any builder) against the fields. Unknown keys are dropped, missing
	 * ones take their default, and every value has its field's type afterwards.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions.
	 * @param array<string, mixed>                $raw    Raw values.
	 * @return array<string, mixed>
	 */
	public static function clean( array $fields, array $raw ): array {
		$out = array();
		foreach ( $fields as $key => $def ) {
			$out[ $key ] = array_key_exists( $key, $raw ) ? self::value( $def, $raw[ $key ] ) : self::default_of( $def );
		}

		return $out;
	}

	/**
	 * A field's default value, typed.
	 *
	 * @param array<string, mixed> $def Definition.
	 * @return mixed
	 */
	public static function default_of( array $def ) {
		if ( array_key_exists( 'default', $def ) ) {
			return self::value( $def, $def['default'] );
		}
		switch ( (string) $def['type'] ) {
			case 'number':
			case 'image':
			case 'user':
				return 0;
			case 'bool':
				return false;
			case 'list':
			case 'repeater':
				return array();
		}

		return '';
	}

	/**
	 * One value, cleaned to its field's type.
	 *
	 * @param array<string, mixed> $def   Definition.
	 * @param mixed                $value Raw value.
	 * @return mixed
	 */
	public static function value( array $def, $value ) {
		switch ( (string) $def['type'] ) {
			case 'number':
				$n = is_numeric( $value ) ? (float) $value : 0.0;
				if ( isset( $def['min'] ) ) {
					$n = max( (float) $def['min'], $n );
				}
				if ( isset( $def['max'] ) ) {
					$n = min( (float) $def['max'], $n );
				}
				return ( floor( $n ) === $n && abs( $n ) < PHP_INT_MAX ) ? (int) $n : round( $n, 4 );
			case 'image':
			case 'user':
				return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
			case 'bool':
				return true === $value || 1 === $value || in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
			case 'select':
				$choices = array_map( 'strval', array_keys( (array) ( $def['choices'] ?? array() ) ) );
				$value   = is_scalar( $value ) ? (string) $value : '';
				if ( in_array( $value, $choices, true ) ) {
					return $value;
				}
				return array_key_exists( 'default', $def ) ? (string) $def['default'] : (string) ( $choices[0] ?? '' );
			case 'url':
				$value = is_string( $value ) ? trim( $value ) : '';
				// An address on the web, or a path on this site; never javascript: or data:.
				if ( 1 !== preg_match( '#^(https?://[^\s]+|/(?!/)[^\s]*)$#i', $value ) ) {
					return '';
				}
				return esc_url_raw( $value, array( 'http', 'https' ) );
			case 'date':
				$value = is_string( $value ) ? trim( $value ) : '';
				// ISO 8601 date or date-time, as <input type="date"> and Elementor send it.
				return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $value ) ? str_replace( ' ', 'T', $value ) : '';
			case 'list':
				if ( is_string( $value ) ) {
					$value = Html::split( '/\r\n|\r|\n/', $value );
				}
				$out  = array();
				$keep = ! empty( $def['keep_empty'] ); // Positions matter (a comparison's cells).
				foreach ( is_array( $value ) ? $value : array() as $item ) {
					$item = self::text( $item, (int) ( $def['max'] ?? 1000 ), false );
					if ( '' !== $item || $keep ) {
						$out[] = $item;
					}
					if ( count( $out ) >= self::ROWS_MAX ) {
						break;
					}
				}
				while ( $keep && array() !== $out && '' === end( $out ) ) {
					array_pop( $out );
				}
				return $out;
			case 'repeater':
				$out = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean = self::clean( (array) ( $def['fields'] ?? array() ), $row );
					if ( self::filled( $clean ) ) {
						$out[] = $clean;
					}
					if ( count( $out ) >= self::ROWS_MAX ) {
						break;
					}
				}
				return $out;
			case 'textarea':
				return self::text( $value, (int) ( $def['max'] ?? self::TEXT_MAX ), true );
		}

		return self::text( $value, (int) ( $def['max'] ?? 500 ), false );
	}

	/**
	 * Plain text (no tags), optionally keeping line breaks, cut to a length.
	 *
	 * @param mixed $value     Value.
	 * @param int   $max       Maximum characters.
	 * @param bool  $multiline Keep line breaks.
	 * @return string
	 */
	public static function text( $value, int $max, bool $multiline ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_strip_all_tags( (string) $value );
		$value = $multiline ? (string) preg_replace( "/[ \t]+/u", ' ', str_replace( array( "\r\n", "\r" ), "\n", $value ) ) : (string) preg_replace( '/\s+/u', ' ', $value );
		$value = trim( $value );
		if ( $max > 0 && mb_strlen( $value ) > $max ) {
			$value = mb_substr( $value, 0, $max );
		}

		return $value;
	}

	/**
	 * Does a cleaned row hold anything (a repeater drops empty rows)?
	 *
	 * @param array<string, mixed> $row Row.
	 * @return bool
	 */
	public static function filled( array $row ): bool {
		foreach ( $row as $value ) {
			if ( '' !== $value && 0 !== $value && false !== $value && array() !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The block editor's attribute definitions for the fields.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions.
	 * @return array<string, array<string, mixed>>
	 */
	public static function attributes( array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $def ) {
			switch ( (string) $def['type'] ) {
				case 'number':
					$type = 'number';
					break;
				case 'image':
				case 'user':
					$type = 'integer';
					break;
				case 'bool':
					$type = 'boolean';
					break;
				case 'list':
				case 'repeater':
					$type = 'array';
					break;
				default:
					$type = 'string';
			}
			$attr = array(
				'type'    => $type,
				'default' => self::default_of( $def ),
			);
			if ( ! empty( $def['content'] ) ) {
				// Words a reader sees: translated with the page (Tranzly reads the role, ADR 0033).
				$attr['role'] = 'content';
			}
			$out[ $key ] = $attr;
		}

		return $out;
	}

	/**
	 * The fields as the editors see them (labels translated now, callables resolved).
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe( array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $def ) {
			$row = array(
				'key'     => (string) $key,
				'type'    => (string) $def['type'],
				'label'   => self::label( $def['label'] ?? $key ),
				'default' => self::default_of( $def ),
			);
			foreach ( array( 'help', 'placeholder' ) as $text ) {
				if ( isset( $def[ $text ] ) ) {
					$row[ $text ] = self::label( $def[ $text ] );
				}
			}
			foreach ( array( 'min', 'max', 'step', 'rows', 'advise' ) as $num ) {
				if ( isset( $def[ $num ] ) ) {
					$row[ $num ] = $def[ $num ];
				}
			}
			if ( isset( $def['choices'] ) ) {
				$row['choices'] = array();
				foreach ( (array) $def['choices'] as $value => $label ) {
					$row['choices'][] = array(
						'value' => (string) $value,
						'label' => self::label( $label ),
					);
				}
			}
			if ( 'repeater' === $def['type'] ) {
				$row['fields'] = self::describe( (array) ( $def['fields'] ?? array() ) );
				$row['item']   = self::label( $def['item'] ?? __( 'Item', 'zinn-seo' ) );
			}
			if ( ! empty( $def['content'] ) ) {
				$row['content'] = true;
			}
			if ( ! empty( $def['keep_empty'] ) ) {
				$row['keepEmpty'] = true;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * A label: a string, or a callable that returns one (translated when shown).
	 *
	 * @param mixed $label Label.
	 * @return string
	 */
	public static function label( $label ): string {
		return is_callable( $label ) && ! is_string( $label ) ? (string) call_user_func( $label ) : (string) $label;
	}
}
