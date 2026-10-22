<?php
/**
 * Per-object SEO fields: postmeta and termmeta, registered with WordPress (zs-153).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⛔⛔ NO SHADOW INDEX TABLE. A post's SEO fields are ordinary postmeta (a term's, termmeta), under
 * private `_zseo_*` keys, read in ONE primed query per request (Primer). The alternative other SEO
 * plugins chose — a second table mirroring every post — has to be rebuilt, drifts from the posts
 * it mirrors and is the first thing that times out on a site with a million posts.
 *
 * A field is declared once (key, type, which objects carry it, who may write it) and WordPress is
 * told about it with register_meta(), so the block editor, the REST API and WP-CLI all read and
 * write the same value through the same sanitiser. Modules add fields with `zinn_seo_meta_fields`.
 */
final class Meta {

	/** Every key starts with this; uninstall removes exactly this family. */
	public const PREFIX = '_zseo_';

	/**
	 * Fields, built once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $fields = null;

	/**
	 * The fields: name (without the prefix) => definition.
	 *
	 * `type` is string|bool|int|list; `objects` is a list of `post`, `term` and/or `user` (a
	 * user's fields are those of the author's page); `max` bounds a string's length. The four core
	 * fields are the ones every later feature reads.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		if ( null !== self::$fields ) {
			return self::$fields;
		}
		$core = array(
			'title'     => array(
				'type'        => 'template',
				'objects'     => array( 'post', 'term', 'user' ),
				'max'         => 300,
				'description' => __( 'The title search engines show. Empty means the template decides.', 'zinn-seo' ),
			),
			'desc'      => array(
				'type'        => 'template',
				'objects'     => array( 'post', 'term', 'user' ),
				'max'         => 1000,
				'description' => __( 'The meta description. Empty means the template decides.', 'zinn-seo' ),
			),
			'robots'    => array(
				'type'        => 'list',
				'objects'     => array( 'post', 'term', 'user' ),
				// `index` and `follow` exist to overrule a content type's default (all of a type
				// set to noindex, this one page indexed); they are never printed (zs-021).
				// `nocache` (Q44, FIX14): Bing's own value — Copilot may show only the title, address and
				// snippet; the page stays in Bing's results. Other engines ignore it.
				'choices'     => array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'nocache', 'index', 'follow' ),
				'description' => __( 'Robots directives for this page only.', 'zinn-seo' ),
			),
			'canonical' => array(
				'type'        => 'url',
				'objects'     => array( 'post', 'term', 'user' ),
				'description' => __( 'The canonical address. Empty means this page\'s own address.', 'zinn-seo' ),
			),
		);

		// Every module's own fields (its definition's `meta`), on or off, for the same reason as
		// Settings::schema(): a field must exist before the module that owns it boots.
		foreach ( Modules::discover() as $def ) {
			foreach ( (array) ( $def['meta'] ?? array() ) as $name => $field ) {
				if ( ! isset( $core[ $name ] ) ) {
					$core[ $name ] = $field;
				}
			}
		}

		/**
		 * Filters the per-object SEO fields. A module adds its own here.
		 *
		 * @param array<string, array<string, mixed>> $fields Name => definition.
		 */
		$fields = (array) apply_filters( 'zinn_seo_meta_fields', $core );
		foreach ( $fields as $name => $def ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,40}$/', $name ) || ! is_array( $def ) ) {
				unset( $fields[ $name ] );
			}
		}
		self::$fields = $fields;

		return self::$fields;
	}

	/**
	 * A field's description: a string, or a callable so a module's definition (read before
	 * translations load) is translated only when shown.
	 *
	 * @param array<string, mixed> $def Definition.
	 * @return string
	 */
	public static function describe( array $def ): string {
		$text = $def['description'] ?? '';

		return is_callable( $text ) ? (string) call_user_func( $text ) : (string) $text;
	}

	/**
	 * Forget the per-request cache (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$fields = null;
	}

	/**
	 * The stored key of a field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function key( string $name ): string {
		return self::PREFIX . $name;
	}

	/**
	 * Tell WordPress about every field (on `init`).
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::fields() as $name => $def ) {
			foreach ( (array) ( $def['objects'] ?? array( 'post' ) ) as $object ) {
				$args = array(
					'type'              => 'list' === $def['type'] ? 'array' : ( 'int' === $def['type'] ? 'integer' : ( 'bool' === $def['type'] ? 'boolean' : 'string' ) ),
					'single'            => true,
					'description'       => self::describe( $def ),
					'sanitize_callback' => static function ( $value ) use ( $def ) {
						$clean = self::sanitize( $def, $value );
						return is_wp_error( $clean ) ? self::empty_value( $def ) : $clean;
					},
					'auth_callback'     => 'post' === $object
						? static function ( $allowed, $meta_key, $object_id ): bool {
							return Capabilities::can_edit_post( (int) $object_id );
						}
						: ( 'user' === $object
							? static function ( $allowed, $meta_key, $object_id ): bool {
								return current_user_can( 'edit_user', (int) $object_id );
							}
							: static function ( $allowed, $meta_key, $object_id ): bool {
								return Capabilities::can_edit_term( (int) $object_id );
							} ),
					'show_in_rest'      => 'list' === $def['type']
						? array(
							'schema' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						)
						: true,
				);
				if ( 'post' === $object ) {
					register_post_meta( '', self::key( $name ), $args );
				} elseif ( 'user' === $object ) {
					// Never in the public users endpoint: an author's page fields are read through ours.
					$args['show_in_rest'] = false;
					register_meta( 'user', self::key( $name ), $args );
				} else {
					register_term_meta( '', self::key( $name ), $args );
				}
			}
		}
	}

	/**
	 * The empty value of a field's type.
	 *
	 * @param array<string, mixed> $def Definition.
	 * @return mixed
	 */
	public static function empty_value( array $def ) {
		switch ( (string) $def['type'] ) {
			case 'list':
				return array();
			case 'bool':
				return false;
			case 'int':
				return 0;
		}

		return '';
	}

	/**
	 * Sanitise one value for a field.
	 *
	 * @param array<string, mixed> $def   Definition.
	 * @param mixed                $value Value.
	 * @return mixed|\WP_Error
	 */
	public static function sanitize( array $def, $value ) {
		if ( 'json' === $def['type'] ) {
			return self::sanitize_json( $def, $value );
		}
		if ( 'list' === $def['type'] ) {
			if ( ! is_array( $value ) ) {
				return new \WP_Error( 'zinn_seo_invalid_value', __( 'This value is not allowed here.', 'zinn-seo' ) );
			}
			$out = array();
			foreach ( $value as $item ) {
				$item = sanitize_key( (string) $item );
				if ( isset( $def['choices'] ) && ! in_array( $item, (array) $def['choices'], true ) ) {
					return new \WP_Error( 'zinn_seo_invalid_value', __( 'This value is not allowed here.', 'zinn-seo' ) );
				}
				$out[] = $item;
			}
			return array_values( array_unique( $out ) );
		}

		return Settings::coerce( $def, $value );
	}

	/**
	 * A structured field (`json`, zs-764): a record stored as one JSON string, so the history keeps
	 * it as one value and undo puts back exactly what was there. The field's `clean` callable
	 * (array in, array or WP_Error out) decides what is allowed; an empty record is the empty
	 * value (the row is deleted). Accepts the record or its JSON text.
	 *
	 * @param array<string, mixed> $def   Definition (`clean`, `max` bytes of JSON).
	 * @param mixed                $value Value.
	 * @return string|\WP_Error
	 */
	private static function sanitize_json( array $def, $value ) {
		$bad = new \WP_Error( 'zinn_seo_invalid_value', __( 'This value is not allowed here.', 'zinn-seo' ) );
		if ( '' === $value || null === $value || array() === $value ) {
			return '';
		}
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		if ( ! is_array( $value ) ) {
			return $bad;
		}
		$clean = isset( $def['clean'] ) && is_callable( $def['clean'] ) ? call_user_func( $def['clean'], $value ) : $value;
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( ! is_array( $clean ) || array() === $clean ) {
			return '';
		}
		$json = (string) wp_json_encode( $clean );
		if ( '' === $json || ( isset( $def['max'] ) && strlen( $json ) > (int) $def['max'] ) ) {
			return $bad;
		}

		return $json;
	}

	/**
	 * Read a field of a post (from the primed cache).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $name    Field name.
	 * @return mixed The value, or the empty value of its type.
	 */
	public static function get_post( int $post_id, string $name ) {
		$def = self::fields()[ $name ] ?? null;
		if ( null === $def ) {
			return null;
		}
		$value = get_post_meta( $post_id, self::key( $name ), true );

		return ( '' === $value || null === $value || ( false === $value && 'bool' !== $def['type'] ) ) ? self::empty_value( $def ) : $value;
	}

	/**
	 * Read a field of a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $name    Field name.
	 * @return mixed
	 */
	public static function get_term( int $term_id, string $name ) {
		$def = self::fields()[ $name ] ?? null;
		if ( null === $def ) {
			return null;
		}
		$value = get_term_meta( $term_id, self::key( $name ), true );

		return ( '' === $value || null === $value || ( false === $value && 'bool' !== $def['type'] ) ) ? self::empty_value( $def ) : $value;
	}

	/**
	 * Read a field of a user (the author's page).
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Field name.
	 * @return mixed
	 */
	public static function get_user( int $user_id, string $name ) {
		$def = self::fields()[ $name ] ?? null;
		if ( null === $def ) {
			return null;
		}
		$value = get_user_meta( $user_id, self::key( $name ), true );

		return ( '' === $value || null === $value || ( false === $value && 'bool' !== $def['type'] ) ) ? self::empty_value( $def ) : $value;
	}

	/**
	 * Read a field of a post, a term or a user.
	 *
	 * @param string $kind `post`, `term` or `user`.
	 * @param int    $id   Object ID.
	 * @param string $name Field name.
	 * @return mixed
	 */
	public static function get( string $kind, int $id, string $name ) {
		if ( 'term' === $kind ) {
			return self::get_term( $id, $name );
		}

		return 'user' === $kind ? self::get_user( $id, $name ) : self::get_post( $id, $name );
	}

	/**
	 * Write a field (empty values delete the row, so an untouched post stores nothing).
	 *
	 * @param string $kind  `post`, `term` or `user`.
	 * @param int    $id    Object ID.
	 * @param string $name  Field name.
	 * @param mixed  $value Value.
	 * @param string $actor Who: user, agent, import, bulk.
	 * @return true|\WP_Error
	 */
	public static function set( string $kind, int $id, string $name, $value, string $actor = 'user' ) {
		$def = self::fields()[ $name ] ?? null;
		if ( null === $def || ! in_array( $kind, (array) ( $def['objects'] ?? array( 'post' ) ), true ) ) {
			/* translators: %s: a field name. */
			return new \WP_Error( 'zinn_seo_unknown_field', sprintf( __( 'There is no SEO field called %s here.', 'zinn-seo' ), $name ), array( 'status' => 400 ) );
		}
		$clean = self::sanitize( $def, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$key    = self::key( $name );
		$before = get_metadata( $kind, $id, $key, true );
		if ( self::empty_value( $def ) === $clean ) {
			delete_metadata( $kind, $id, $key );
		} else {
			// Slashed: WordPress unslashes what it is given, which would eat the backslashes of a
			// JSON value (the structured data carried over from another plugin).
			update_metadata( $kind, $id, $key, is_string( $clean ) ? wp_slash( $clean ) : $clean );
		}

		/**
		 * Fires after an SEO field changed (the history module records it).
		 *
		 * @param string $kind `post`, `term` or `user`.
		 * @param int    $id     Object ID.
		 * @param string $name   Field name.
		 * @param mixed  $before Old value.
		 * @param mixed  $clean  New value.
		 * @param string $actor  Who changed it.
		 */
		do_action( 'zinn_seo_field_saved', $kind, $id, $name, $before, $clean, $actor );

		return true;
	}
}
