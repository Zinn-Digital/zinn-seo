<?php
/**
 * Site-wide settings: one autoloaded option, a typed schema, one sanitiser.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every site-wide setting lives in ONE option, `zseo_settings`, autoloaded (zs-154: one small
 * autoloaded option, ≤ 8 KB). Large or rarely read state (setup progress, job cursors, the
 * export history) lives in non-autoloaded options instead, so the front end loads one row.
 *
 * The schema is typed: every key has a type, a default and (for choices) the allowed values. Each
 * module adds its own keys through `zinn_seo_settings_schema`, so a module never edits this file.
 * Every write path — the settings screen, REST, WP-CLI, MCP, an import — goes through save(),
 * which drops unknown keys and coerces each value to its type, so no path can store something the
 * screen could not.
 */
final class Settings {

	/** The option. */
	public const OPTION = 'zseo_settings';

	/** The autoload budget in bytes (zs-154). */
	public const AUTOLOAD_BUDGET = 8192;

	/**
	 * Settings marked `apart` in the schema (long texts: llms.txt details) are kept in this option,
	 * not autoloaded, so one long text never pushes the autoloaded row past its budget. The row
	 * holds APART in their place.
	 */
	public const APART_OPTION = 'zseo_settings_apart';

	/** What the autoloaded row holds in place of a setting kept apart. */
	public const APART = '@apart';

	/**
	 * Settings kept apart, read once per request when one is set.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $apart = null;

	/** Schema version written with the settings, for future migrations. */
	public const SCHEMA = 1;

	/**
	 * The stored values, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Overlays (see overlay()).
	 *
	 * @var array<int, callable>
	 */
	private static array $overlays = array();

	/**
	 * The schema, built once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $schema = null;

	/**
	 * The core schema plus every module's keys.
	 *
	 * Types: bool, int, string, text, template (text with %variables%), url, enum (with `choices`),
	 * list (of strings, with optional
	 * `choices`), map (string keys to bool), dict (string keys to records whose own `fields` are
	 * typed the same way; for per-post-type and per-taxonomy choices). `secret` keys are never
	 * exported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		if ( null !== self::$schema ) {
			return self::$schema;
		}
		$core = array(
			'site_type'         => array(
				'type'    => 'enum',
				'default' => '',
				'choices' => array( '', 'blog', 'business', 'shop', 'news', 'portfolio', 'community', 'other' ),
				'group'   => 'site',
			),
			'identity_type'     => array(
				'type'    => 'enum',
				'default' => 'organization',
				'choices' => array( 'organization', 'person' ),
				'group'   => 'site',
			),
			'identity_name'     => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 200,
				'group'   => 'site',
			),
			'identity_logo'     => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'group'   => 'site',
			),
			'identity_person'   => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'group'   => 'site',
			),
			'modules'           => array(
				'type'    => 'map',
				'default' => array(),
				'group'   => 'modules',
			),
			'notrace'           => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'privacy',
			),
			'notrace_hash'      => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 12,
				'pattern' => '/^[a-z0-9]*$/',
				'group'   => 'privacy',
				'export'  => false,
			),
			'keep_data'         => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'data',
			),
			// Q356: kinds of e-mail this site never sends (Alert_Mail::kinds(); a test keeps the
			// two lists the same), and the period inside which a repeat is counted, not sent.
			'alert_mail_muted'  => array(
				'type'    => 'list',
				'default' => array(),
				'choices' => array( 'fatal_error', 'fatal_error_background', 'fix_auto_off', 'malware_found', 'spam_found', 'guard_round', 'guard_rollback', 'broken_links', 'notfound', 'content_copy', 'link_spike', 'background_backlog', 'autopilot_report', 'task', 'tasks_due', 'notices' ),
				'group'   => 'alerts',
			),
			'alert_mail_digest' => array(
				'type'    => 'enum',
				'default' => 'day',
				'choices' => array( 'hour', 'day', 'week' ),
				'group'   => 'alerts',
			),
			// Q337: background jobs run very gently in these hours (site time zone), and finish.
			'jobs_quiet'        => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'jobs',
			),
			'jobs_quiet_from'   => array(
				'type'    => 'int',
				'default' => 9,
				'min'     => 0,
				'max'     => 23,
				'group'   => 'jobs',
			),
			'jobs_quiet_to'     => array(
				'type'    => 'int',
				'default' => 17,
				'min'     => 0,
				'max'     => 23,
				'group'   => 'jobs',
			),
			// HARDEN1: bulk changes save each page quietly (other plugins' save actions held, see
			// Content\Quiet_Save) unless the site needs every plugin to react to every change.
			'jobs_full_saves'   => array(
				'type'    => 'bool',
				'default' => false,
				'group'   => 'jobs',
			),
		);

		// Every module's own keys (its definition's `settings`), whether the module is on or off:
		// the schema is read BEFORE modules boot (Modules::enabled() reads `modules` from here), so
		// a key a module added only while booting would be missing from the first, cached schema,
		// and the next save would drop the module's stored values as unknown.
		foreach ( Modules::discover() as $def ) {
			foreach ( (array) ( $def['settings'] ?? array() ) as $key => $key_def ) {
				if ( ! isset( $core[ $key ] ) ) {
					$core[ $key ] = $key_def;
				}
			}
		}

		/**
		 * Filters the settings schema. A module adds its own keys here.
		 *
		 * @param array<string, array<string, mixed>> $schema Key => definition.
		 */
		$schema = (array) apply_filters( 'zinn_seo_settings_schema', $core );
		foreach ( $schema as $key => $def ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) || ! is_array( $def ) || ! isset( $def['type'] ) ) {
				unset( $schema[ $key ] );
			}
		}
		self::$schema = $schema;

		return self::$schema;
	}

	/**
	 * Forget the per-request caches (tests, and after a module registers late).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache  = null;
		self::$schema = null;
		self::$apart  = null;
	}

	/**
	 * The settings kept apart.
	 *
	 * @return array<string, mixed>
	 */
	private static function apart(): array {
		if ( null === self::$apart ) {
			$stored      = get_option( self::APART_OPTION, array() );
			self::$apart = is_array( $stored ) ? $stored : array();
		}

		return self::$apart;
	}

	/**
	 * Every key's default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$out[ $key ] = $def['default'] ?? null;
		}

		return $out;
	}

	/**
	 * Every setting, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		$out = self::defaults();
		foreach ( $out as $key => $default ) {
			if ( array_key_exists( $key, self::$cache ) ) {
				$out[ $key ] = self::APART === self::$cache[ $key ] ? ( self::apart()[ $key ] ?? $default ) : self::$cache[ $key ];
			}
		}
		foreach ( self::$overlays as $overlay ) {
			$out = (array) $overlay( $out, self::$cache );
		}

		return $out;
	}

	/**
	 * Values that stand above a site's own (a multisite network's defaults and locked settings).
	 *
	 * @param callable $overlay `function ( array $values, array $stored ): array` — every
	 *                          setting with defaults filled in, and what this site itself stored;
	 *                          returns the values to use. Called on each read, so it must be cheap
	 *                          (an array it already holds, no query).
	 * @return void
	 */
	public static function overlay( callable $overlay ): void {
		self::$overlays[] = $overlay;
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Check and coerce a set of changes without storing them.
	 *
	 * @param array<string, mixed> $changes Key => value.
	 * @return array{clean: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate( array $changes ): array {
		$schema = self::schema();
		$clean  = array();
		$errors = array();
		foreach ( $changes as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $schema[ $key ] ) ) {
				/* translators: %s: a settings key. */
				$errors[ $key ] = sprintf( __( 'There is no setting called %s.', 'zinn-seo' ), $key );
				continue;
			}
			$result = self::coerce( $schema[ $key ], $value );
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				continue;
			}
			/**
			 * Filters whether a setting is fixed from outside this site (a multisite network
			 * that locked its group). A locked setting sent back unchanged is left alone; a
			 * different value is refused.
			 *
			 * @param bool                 $locked Whether it is locked.
			 * @param string               $key    The setting.
			 * @param array<string, mixed> $def    Its definition.
			 */
			if ( (bool) apply_filters( 'zinn_seo_setting_locked', false, $key, $schema[ $key ] ) ) {
				if ( self::get( $key ) !== $result ) {
					/* translators: %s: a settings key. */
					$errors[ $key ] = sprintf( __( '%s is set for the whole network and cannot be changed on this site.', 'zinn-seo' ), $key );
				}
				continue;
			}
			$clean[ $key ] = $result;
		}

		return array(
			'clean'  => $clean,
			'errors' => $errors,
		);
	}

	/**
	 * Store a set of changes, keeping every other key. Unknown keys and bad values are refused
	 * as a whole: nothing is stored when anything is wrong.
	 *
	 * @param array<string, mixed> $changes Key => value.
	 * @param string               $actor   Who changed it: user, agent, import, setup, platform.
	 * @return array<string, mixed>|\WP_Error Every setting after the change, or the problems.
	 */
	public static function save( array $changes, string $actor = 'user' ) {
		$checked = self::validate( $changes );
		if ( array() !== $checked['errors'] ) {
			return new \WP_Error(
				'zinn_seo_invalid_settings',
				implode( ' ', $checked['errors'] ),
				array(
					'status' => 400,
					'fields' => $checked['errors'],
				)
			);
		}
		$before = self::all();
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$next   = array_merge( $stored, $checked['clean'] );
		// Only what differs from the default is stored, which keeps the autoloaded row small.
		// What a setting falls back to is the plugin's default, or a network's default above it.
		$defaults = self::defaults();
		foreach ( self::$overlays as $overlay ) {
			$defaults = (array) $overlay( $defaults, array() );
		}
		foreach ( $next as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) || $defaults[ $key ] === $value ) {
				unset( $next[ $key ] );
			}
		}
		// Long texts are kept apart (not autoloaded): the row holds a marker in their place.
		$schema = self::schema();
		$apart  = self::apart();
		$moved  = false;
		foreach ( $schema as $key => $def ) {
			if ( empty( $def['apart'] ) ) {
				continue;
			}
			if ( array_key_exists( $key, $next ) && self::APART !== $next[ $key ] ) {
				$apart[ $key ] = $next[ $key ];
				$next[ $key ]  = self::APART;
				$moved         = true;
			} elseif ( ! array_key_exists( $key, $next ) && array_key_exists( $key, $apart ) ) {
				unset( $apart[ $key ] );
				$moved = true;
			}
		}
		$next['_v']                     = self::SCHEMA;
		list( $next, $apart, $spilled ) = self::fit( $next, $apart, $schema );
		$moved                          = $moved || $spilled;
		if ( strlen( (string) wp_json_encode( $next ) ) > self::AUTOLOAD_BUDGET ) {
			return new \WP_Error( 'zinn_seo_settings_too_large', __( 'These settings are too large to store. Shorten the longest text and try again.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( $moved ) {
			update_option( self::APART_OPTION, $apart, false );
			self::$apart = $apart;
		}
		update_option( self::OPTION, $next, true );
		self::$cache = $next;
		$after       = self::all();

		/**
		 * Fires after settings changed.
		 *
		 * @param array<string, mixed> $after  Every setting now.
		 * @param array<string, mixed> $before Every setting before.
		 * @param string               $actor  Who changed it.
		 */
		do_action( 'zinn_seo_settings_saved', $after, $before, $actor );

		return $after;
	}

	/**
	 * Make the autoloaded row fit its budget: while it is over, the largest value moves to the
	 * row kept apart (read once, only when a request needs one of them), and the row keeps a
	 * marker. A value kept apart this way that is no longer marked (changed back, or set again
	 * small) is forgotten there. Pure, for the tests.
	 *
	 * ⛔ WHY. The budget used to refuse the whole save. A site moved from Rank Math carries big
	 * templates, an organisation profile and llms texts; zinnhub.com (2026-10-15) stood at 8,099
	 * of 8,192 bytes, so switching ON one more option ("fill missing alt text") was refused with
	 * "shorten the longest text" — no text was too long, and nothing could be changed any more.
	 *
	 * @param array<string, mixed>                $next   The row to store.
	 * @param array<string, mixed>                $apart  The values kept apart.
	 * @param array<string, array<string, mixed>> $schema The settings' definitions.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool} Row, apart, changed.
	 */
	public static function fit( array $next, array $apart, array $schema ): array {
		$size    = static fn( $value ): int => strlen( (string) wp_json_encode( $value ) );
		$changed = false;
		if ( $size( $next ) > self::AUTOLOAD_BUDGET ) {
			$sizes = array();
			foreach ( $next as $key => $value ) {
				if ( '_v' !== $key && self::APART !== $value ) {
					$sizes[ $key ] = $size( $value );
				}
			}
			arsort( $sizes );
			foreach ( array_keys( $sizes ) as $key ) {
				if ( $size( $next ) <= self::AUTOLOAD_BUDGET ) {
					break;
				}
				$apart[ $key ] = $next[ $key ];
				$next[ $key ]  = self::APART;
				$changed       = true;
			}
		}
		foreach ( array_keys( $apart ) as $key ) {
			if ( empty( $schema[ $key ]['apart'] ) && ( ! array_key_exists( $key, $next ) || self::APART !== $next[ $key ] ) ) {
				unset( $apart[ $key ] );
				$changed = true;
			}
		}

		return array( $next, $apart, $changed );
	}

	/**
	 * Coerce one value to its definition.
	 *
	 * @param array<string, mixed> $def   Definition.
	 * @param mixed                $value Value.
	 * @return mixed|\WP_Error
	 */
	public static function coerce( array $def, $value ) {
		$bad = new \WP_Error( 'zinn_seo_invalid_value', __( 'This value is not allowed here.', 'zinn-seo' ) );
		switch ( (string) $def['type'] ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( in_array( $value, array( 1, 0, '1', '0', 'true', 'false', 'yes', 'no', 'on', 'off' ), true ) ) {
					return in_array( $value, array( 1, '1', 'true', 'yes', 'on' ), true );
				}
				return $bad;
			case 'int':
				if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,18}$/', $value ) ) ) {
					return $bad;
				}
				$value = (int) $value;
				if ( ( isset( $def['min'] ) && $value < (int) $def['min'] ) || ( isset( $def['max'] ) && $value > (int) $def['max'] ) ) {
					return $bad;
				}
				return $value;
			case 'string':
			case 'text':
				if ( ! is_scalar( $value ) ) {
					return $bad;
				}
				// A long text keeps its percent signs: sanitize_textarea_field() would eat every
				// "%C3"-like pair, breaking addresses such as Wikipedia's (found 2026-10-09, P3).
				$value = 'text' === $def['type']
					? str_replace( "\u{E000}", '%', sanitize_textarea_field( str_replace( '%', "\u{E000}", str_replace( "\u{E000}", '', (string) $value ) ) ) )
					: sanitize_text_field( (string) $value );
				if ( isset( $def['max'] ) && mb_strlen( $value ) > (int) $def['max'] ) {
					return $bad;
				}
				if ( isset( $def['pattern'] ) && 1 !== preg_match( (string) $def['pattern'], $value ) ) {
					return $bad;
				}
				return $value;
			case 'template':
				// Text with %variables%. sanitize_text_field() removes anything that looks like a
				// percent-encoded byte, so %category% would lose "%ca" and %date% "%da" (measured
				// 2026-10-09, zinn_seo_p1.sh: "Hello SEO | tegory%"). The percent signs are set
				// aside while the text is cleaned and put back after.
				if ( ! is_scalar( $value ) ) {
					return $bad;
				}
				$value = str_replace( "\u{E000}", '', (string) $value );
				$value = str_replace( "\u{E000}", '%', sanitize_text_field( str_replace( '%', "\u{E000}", $value ) ) );
				if ( isset( $def['max'] ) && mb_strlen( $value ) > (int) $def['max'] ) {
					return $bad;
				}
				return $value;
			case 'url':
				if ( ! is_string( $value ) ) {
					return $bad;
				}
				if ( '' === $value ) {
					return '';
				}
				$url = esc_url_raw( $value, array( 'http', 'https' ) );
				return '' === $url ? $bad : $url;
			case 'enum':
				return in_array( $value, (array) ( $def['choices'] ?? array() ), true ) ? $value : $bad;
			case 'list':
				if ( ! is_array( $value ) ) {
					return $bad;
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) ) {
						return $bad;
					}
					$item = sanitize_text_field( (string) $item );
					if ( isset( $def['choices'] ) && ! in_array( $item, (array) $def['choices'], true ) ) {
						return $bad;
					}
					$out[] = $item;
				}
				return array_values( array_unique( $out ) );
			case 'map':
				if ( ! is_array( $value ) ) {
					return $bad;
				}
				$out = array();
				foreach ( $value as $k => $v ) {
					if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) $k ) || ! is_bool( $v ) ) {
						return $bad;
					}
					$out[ (string) $k ] = $v;
				}
				ksort( $out );
				return $out;
			case 'dict':
				// Records under keys a module cannot list in advance (one per post type or
				// taxonomy, e.g. `post:product`). Each record's fields have their own definitions
				// (`fields`), checked with this same function; an empty field is dropped, and an
				// empty record with it, so only what differs from the default is stored.
				if ( ! is_array( $value ) ) {
					return $bad;
				}
				$fields = (array) ( $def['fields'] ?? array() );
				$out    = array();
				foreach ( $value as $k => $record ) {
					if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_:-]{0,80}$/', (string) $k ) || ! is_array( $record ) ) {
						return $bad;
					}
					$clean = array();
					foreach ( $record as $name => $v ) {
						if ( ! isset( $fields[ $name ] ) || ! is_array( $fields[ $name ] ) ) {
							return $bad;
						}
						$v = self::coerce( $fields[ $name ], $v );
						if ( is_wp_error( $v ) ) {
							return $v;
						}
						if ( '' !== $v && array() !== $v && null !== $v ) {
							$clean[ (string) $name ] = $v;
						}
					}
					if ( array() !== $clean ) {
						ksort( $clean );
						$out[ (string) $k ] = $clean;
					}
				}
				if ( isset( $def['max_keys'] ) && count( $out ) > (int) $def['max_keys'] ) {
					return $bad;
				}
				ksort( $out );
				return $out;
		}

		return $bad;
	}
}
