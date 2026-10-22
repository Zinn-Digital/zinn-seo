<?php
/**
 * The redirects table (architecture §1.5).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table; every value is a placeholder.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{prefix}zseo_redirects`: one row per source (UNIQUE `path_hash`, so a source can never hold two
 * rules). Visitors never read this table on a normal page view — they read the index files
 * (`Index`); the table is the record the screens, the importers, the checks and the index are
 * built from.
 *
 * Codes: 301, 302, 307, 308 (moved), 410 (gone), 451 (unavailable for legal reasons).
 * Origins: `manual`, `auto` (slug change, trash, delete), `import:<plugin>`, `csv`, `migration`
 * (permalink change), `404` (made from the 404 monitor), `chain-fix`.
 */
final class Store {

	/** Table schema version. */
	public const DB_VERSION = 1;

	/** Option holding the schema version. */
	public const DB_OPTION = 'zseo_redirects_db';

	/** Codes a rule may use. */
	public const CODES = array( 301, 302, 307, 308, 410, 451 );

	/** Codes that send the visitor somewhere (the others answer with a page). */
	public const MOVES = array( 301, 302, 307, 308 );

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'zseo_redirects';
	}

	/**
	 * Is the table there (without creating it)?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION;
	}

	/**
	 * Create the table when its version is behind.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( self::ready() ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
path_hash char(32) NOT NULL,
source text NOT NULL,
target text NOT NULL,
target_hash char(32) NOT NULL DEFAULT '',
code smallint(5) unsigned NOT NULL DEFAULT 301,
is_regex tinyint(1) unsigned NOT NULL DEFAULT 0,
enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
position int(11) NOT NULL DEFAULT 0,
grp varchar(64) NOT NULL DEFAULT '',
origin varchar(32) NOT NULL DEFAULT 'manual',
object_ref varchar(40) NOT NULL DEFAULT '',
note varchar(191) NOT NULL DEFAULT '',
problem varchar(16) NOT NULL DEFAULT '',
hits bigint(20) unsigned NOT NULL DEFAULT 0,
last_hit datetime NULL DEFAULT NULL,
created datetime NOT NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY path_hash (path_hash),
KEY regex (is_regex,enabled,position),
KEY grp (grp),
KEY object_ref (object_ref),
KEY target_hash (target_hash),
KEY problem (problem)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * The site's sub-folder (`/blog`), '' at the domain root.
	 *
	 * @return string
	 */
	public static function base(): string {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return '/' === rtrim( $path, '/' ) . '/' ? '' : rtrim( $path, '/' );
	}

	/**
	 * Case-sensitive matching?
	 *
	 * @return bool
	 */
	public static function sensitive(): bool {
		return (bool) Settings::get( 'redirects_case_sensitive', false );
	}

	/**
	 * A row as the screens and REST see it.
	 *
	 * @param object|array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public static function shape( $row ): array {
		$row = (array) $row;

		return array(
			'id'       => (int) $row['id'],
			'source'   => (string) $row['source'],
			'target'   => (string) $row['target'],
			'code'     => (int) $row['code'],
			'regex'    => (bool) (int) $row['is_regex'],
			'enabled'  => (bool) (int) $row['enabled'],
			'position' => (int) $row['position'],
			'group'    => (string) $row['grp'],
			'origin'   => (string) $row['origin'],
			'object'   => (string) $row['object_ref'],
			'note'     => (string) $row['note'],
			'problem'  => (string) $row['problem'],
			'hits'     => (int) $row['hits'],
			'last_hit' => null === $row['last_hit'] ? '' : (string) $row['last_hit'],
			'created'  => (string) $row['created'],
			'updated'  => (string) $row['updated'],
		);
	}

	/**
	 * Check and clean a rule before it is stored.
	 *
	 * @param array<string, mixed> $in Fields (source, target, code, regex, enabled, group, note, position).
	 * @return array<string, mixed>|\WP_Error Clean fields incl. `key` and `hash`.
	 */
	public static function clean( array $in ) {
		$regex  = ! empty( $in['regex'] );
		$source = trim( (string) ( $in['source'] ?? '' ) );
		$code   = (int) ( $in['code'] ?? 301 );
		$target = trim( (string) ( $in['target'] ?? '' ) );
		if ( '' === $source ) {
			return new \WP_Error( 'zinn_seo_redirect_source', __( 'Enter the address to redirect from.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $code, self::CODES, true ) ) {
			return new \WP_Error( 'zinn_seo_redirect_code', __( 'Choose 301, 302, 307, 308, 410 or 451.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( $regex ) {
			if ( ! Path::valid_regex( $source ) ) {
				return new \WP_Error( 'zinn_seo_redirect_regex', __( 'This regular expression is not valid.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
			$key = $source;
		} else {
			$key = Path::key( $source, self::base(), self::sensitive() );
			if ( '/' === $key ) {
				return new \WP_Error( 'zinn_seo_redirect_home', __( 'The home page cannot be redirected here; change the site address in Settings → General instead.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
		}
		if ( in_array( $code, self::MOVES, true ) ) {
			if ( '' === $target ) {
				return new \WP_Error( 'zinn_seo_redirect_target', __( 'Enter the address to send visitors to.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
			if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) && 1 !== preg_match( '#^https?://#i', $target ) ) {
				return new \WP_Error( 'zinn_seo_redirect_scheme', __( 'The target must be an address starting with http:// or https://, or a path on this site.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
			$target = 1 === preg_match( '#^https?://#i', $target ) ? esc_url_raw( $target ) : '/' . ltrim( str_replace( array( "\r", "\n", "\0" ), '', $target ), '/' );
			if ( ! $regex && Path::key( $target, self::base(), self::sensitive() ) === $key && self::is_local( $target ) ) {
				return new \WP_Error( 'zinn_seo_redirect_self', __( 'An address cannot redirect to itself.', 'zinn-seo' ), array( 'status' => 400 ) );
			}
		} else {
			$target = '';
		}

		return array(
			'source'   => $regex ? $source : ( '' === wp_parse_url( $source, PHP_URL_HOST ) || null === wp_parse_url( $source, PHP_URL_HOST ) ? '/' . ltrim( $source, '/' ) : $source ),
			'target'   => $target,
			'code'     => $code,
			'regex'    => $regex,
			'enabled'  => ! array_key_exists( 'enabled', $in ) || ! empty( $in['enabled'] ),
			'position' => (int) ( $in['position'] ?? 0 ),
			'group'    => substr( sanitize_text_field( (string) ( $in['group'] ?? '' ) ), 0, 64 ),
			'note'     => substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 191 ),
			'origin'   => substr( sanitize_key( str_replace( ':', '-', (string) ( $in['origin'] ?? 'manual' ) ) ), 0, 32 ),
			'object'   => substr( (string) preg_replace( '/[^a-z0-9:_-]/', '', (string) ( $in['object'] ?? '' ) ), 0, 40 ),
			'key'      => $key,
			'hash'     => Path::hash( ( $regex ? 're:' : '' ) . $key ),
		);
	}

	/**
	 * Is an address on this site?
	 *
	 * @param string $url Address or path.
	 * @return bool
	 */
	public static function is_local( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( null === $host || false === $host || '' === $host ) {
			return true;
		}

		return strtolower( (string) $host ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * Create a rule, or replace the rule already holding that source.
	 *
	 * @param array<string, mixed> $in      Fields.
	 * @param bool                 $replace Replace an existing rule for the same source (else an error).
	 * @return array<string, mixed>|\WP_Error The stored rule.
	 */
	public static function save( array $in, bool $replace = false ) {
		$clean = self::clean( $in );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		self::maybe_install();
		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = self::by_hash( (string) $clean['hash'] );
		$id       = (int) ( $in['id'] ?? 0 );
		if ( null !== $existing && (int) $existing['id'] !== $id && ! $replace ) {
			return new \WP_Error(
				'zinn_seo_redirect_exists',
				/* translators: %s: an address. */
				sprintf( __( 'A redirect from %s already exists. Edit that one instead.', 'zinn-seo' ), (string) $existing['source'] ),
				array(
					'status' => 409,
					'id'     => (int) $existing['id'],
				)
			);
		}
		$fields = array(
			'path_hash'   => (string) $clean['hash'],
			'source'      => (string) $clean['source'],
			'target'      => (string) $clean['target'],
			'target_hash' => self::target_hash( (string) $clean['target'] ),
			'code'        => (int) $clean['code'],
			'is_regex'    => $clean['regex'] ? 1 : 0,
			'enabled'     => $clean['enabled'] ? 1 : 0,
			'position'    => (int) $clean['position'],
			'grp'         => (string) $clean['group'],
			'note'        => (string) $clean['note'],
			'problem'     => '',
			'updated'     => $now,
		);
		$before = null;
		if ( null !== $existing && ( $replace || (int) $existing['id'] === $id ) ) {
			$id     = (int) $existing['id'];
			$before = $existing;
		}
		if ( $id > 0 && null === $before ) {
			$before = self::get( $id );
			if ( null === $before ) {
				return new \WP_Error( 'zinn_seo_not_found', __( 'Nothing with that ID exists.', 'zinn-seo' ), array( 'status' => 404 ) );
			}
		}
		if ( null === $before ) {
			$fields['origin']     = (string) $clean['origin'];
			$fields['object_ref'] = (string) $clean['object'];
			$fields['created']    = $now;
			$wpdb->insert( self::table(), $fields );
			$id = (int) $wpdb->insert_id;
		} else {
			if ( '' !== (string) $clean['object'] ) {
				$fields['object_ref'] = (string) $clean['object'];
			}
			if ( isset( $in['origin'] ) ) {
				$fields['origin'] = (string) $clean['origin'];
			}
			$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
		}
		$after = self::get( $id );
		if ( null === $after ) {
			return new \WP_Error( 'zinn_seo_redirect_store', __( 'The redirect could not be stored.', 'zinn-seo' ), array( 'status' => 500 ) );
		}
		Index::changed( $before, $after );

		/**
		 * Fires after a redirect is created or changed.
		 *
		 * @param array<string, mixed>      $after  The rule now.
		 * @param array<string, mixed>|null $before The rule before (null when new).
		 */
		do_action( 'zinn_seo_redirect_saved', $after, $before );

		return self::shape( $after );
	}

	/**
	 * One row (raw).
	 *
	 * @param int $id Row.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * One row by its hash (raw).
	 *
	 * @param string $hash Hash.
	 * @return array<string, mixed>|null
	 */
	public static function by_hash( string $hash ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE path_hash = %s', self::table(), $hash ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The plain (non-pattern) rule stored for a source, if any.
	 *
	 * @param string $source Address or path.
	 * @return array<string, mixed>|null Raw row.
	 */
	public static function by_source( string $source ): ?array {
		return self::by_hash( Path::hash( Path::key( $source, self::base(), self::sensitive() ) ) );
	}

	/**
	 * Delete rules.
	 *
	 * @param array<int, int> $ids Rows.
	 * @return int Deleted.
	 */
	public static function delete( array $ids ): int {
		if ( ! self::ready() || array() === $ids ) {
			return 0;
		}
		global $wpdb;
		$n = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$row = self::get( $id );
			if ( null === $row ) {
				continue;
			}
			$n += (int) $wpdb->delete( self::table(), array( 'id' => $id ) );
			Index::changed( $row, null );
		}

		return $n;
	}

	/**
	 * Switch rules on or off.
	 *
	 * @param array<int, int> $ids Rows.
	 * @param bool            $on  On.
	 * @return int Changed.
	 */
	public static function enable( array $ids, bool $on ): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$n = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$row = self::get( $id );
			if ( null === $row || (bool) (int) $row['enabled'] === $on ) {
				continue;
			}
			$n += (int) $wpdb->update(
				self::table(),
				array(
					'enabled' => $on ? 1 : 0,
					'updated' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $id )
			);
			Index::changed( $row, self::get( $id ) );
		}

		return $n;
	}

	/**
	 * Rules for the screen: newest first (or by hits), keyset, searchable.
	 *
	 * @param array<string, mixed> $args search, group, code, origin, problem, regex, before (id), order (id|hits), per.
	 * @return array{items: array<int, array<string, mixed>>, next: int, total: int}
	 */
	public static function page( array $args ): array {
		if ( ! self::ready() ) {
			return array(
				'items' => array(),
				'next'  => 0,
				'total' => 0,
			);
		}
		global $wpdb;
		$where = '1=1';
		if ( '' !== (string) ( $args['search'] ?? '' ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND (source LIKE %s OR target LIKE %s OR note LIKE %s)', $like, $like, $like );
		}
		if ( '' !== (string) ( $args['group'] ?? '' ) ) {
			$where .= $wpdb->prepare( ' AND grp = %s', (string) $args['group'] );
		}
		if ( ! empty( $args['code'] ) ) {
			$where .= $wpdb->prepare( ' AND code = %d', (int) $args['code'] );
		}
		if ( '' !== (string) ( $args['origin'] ?? '' ) ) {
			$where .= $wpdb->prepare( ' AND origin LIKE %s', $wpdb->esc_like( (string) $args['origin'] ) . '%' );
		}
		if ( '' !== (string) ( $args['problem'] ?? '' ) ) {
			$where .= 'any' === $args['problem'] ? " AND problem <> ''" : $wpdb->prepare( ' AND problem = %s', (string) $args['problem'] );
		}
		if ( isset( $args['regex'] ) && '' !== (string) $args['regex'] ) {
			$where .= $wpdb->prepare( ' AND is_regex = %d', (int) (bool) $args['regex'] );
		}
		if ( isset( $args['enabled'] ) && '' !== (string) $args['enabled'] ) {
			$where .= $wpdb->prepare( ' AND enabled = %d', (int) (bool) $args['enabled'] );
		}
		$per   = max( 1, min( 500, (int) ( $args['per'] ?? 50 ) ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ', self::table() ) . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared pieces.
		$page  = $where;
		if ( ! empty( $args['before'] ) ) {
			$page .= $wpdb->prepare( ' AND id < %d', (int) $args['before'] );
		}
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ', self::table() ) . $page . $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', $per + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared pieces.
		$more  = count( $rows ) > $per;
		$rows  = array_slice( $rows, 0, $per );
		$items = array_map( array( self::class, 'shape' ), $rows );
		$last  = end( $items );

		return array(
			'items' => $items,
			'next'  => $more && $last ? (int) $last['id'] : 0,
			'total' => $total,
		);
	}

	/**
	 * Totals for the screen.
	 *
	 * @return array<string, int>
	 */
	public static function totals(): array {
		if ( ! self::ready() ) {
			return array(
				'all'      => 0,
				'enabled'  => 0,
				'regex'    => 0,
				'problems' => 0,
				'hits'     => 0,
			);
		}
		global $wpdb;
		$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS a, SUM(enabled) AS e, SUM(is_regex) AS r, SUM(problem <> '') AS p, SUM(hits) AS h FROM %i", self::table() ), ARRAY_A );

		return array(
			'all'      => (int) ( $row['a'] ?? 0 ),
			'enabled'  => (int) ( $row['e'] ?? 0 ),
			'regex'    => (int) ( $row['r'] ?? 0 ),
			'problems' => (int) ( $row['p'] ?? 0 ),
			'hits'     => (int) ( $row['h'] ?? 0 ),
		);
	}

	/**
	 * The groups in use.
	 *
	 * @return array<int, string>
	 */
	public static function groups(): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT grp FROM %i WHERE grp <> '' ORDER BY grp LIMIT 500", self::table() ) ) );
	}

	/**
	 * Count a hit on a rule.
	 *
	 * @param int $id Row.
	 * @return void
	 */
	public static function hit( int $id ): void {
		if ( $id <= 0 || ! self::ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', self::table(), gmdate( 'Y-m-d H:i:s' ), $id ) );
	}

	/**
	 * Rules made for one object (`post:12`, `term:7`).
	 *
	 * @param string $ref Object reference.
	 * @return array<int, array<string, mixed>> Raw rows.
	 */
	public static function for_object( string $ref ): array {
		if ( ! self::ready() || '' === $ref ) {
			return array();
		}
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_ref = %s ORDER BY id', self::table(), $ref ), ARRAY_A );
	}

	/**
	 * The indexed hash of a target on this site ('' for another site or no target), so rules
	 * pointing at an address are found without reading the table (chain collapsing).
	 *
	 * @param string $target Target.
	 * @return string
	 */
	public static function target_hash( string $target ): string {
		if ( '' === $target || ! self::is_local( $target ) || false !== strpos( $target, '$' ) ) {
			return '';
		}

		return Path::hash( Path::key( $target, self::base(), self::sensitive() ) );
	}

	/**
	 * Plain rules whose target is an address (indexed by `target_hash`).
	 *
	 * @param string $target Address or path.
	 * @return array<int, array<string, mixed>> Raw rows.
	 */
	public static function pointing_to( string $target ): array {
		$hash = self::target_hash( $target );
		if ( ! self::ready() || '' === $hash ) {
			return array();
		}
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE target_hash = %s AND is_regex = 0', self::table(), $hash ), ARRAY_A );
	}
}
