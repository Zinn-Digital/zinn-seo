<?php
/**
 * What AI agents did on the site (zs-214): one line per tool call, newest first.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bounded log in one non-autoloaded option: the last KEEP calls. It never grows past that, so it
 * costs nothing on a site whose agents run all day, and it is never read on a visitor's page.
 * Arguments are not stored (they may carry a key or personal data); only their names are.
 */
final class Log {

	/** The option. */
	public const OPTION = 'zseo_mcp_log';

	/** How many calls are kept. */
	public const KEEP = 500;

	/**
	 * Start a line for an ability call.
	 *
	 * @param string $ability Ability name.
	 * @param mixed  $input   Its input (only the keys are kept).
	 * @return string The line's id.
	 */
	public static function start( string $ability, $input ): string {
		$id   = substr( md5( uniqid( $ability, true ) ), 0, 12 );
		$user = wp_get_current_user();
		$keys = is_array( $input ) ? array_values( array_filter( array_map( 'strval', array_keys( $input ) ), static fn( string $k ): bool => 1 === preg_match( '/^[a-z0-9_\-]{1,64}$/i', $k ) ) ) : array();
		self::write(
			static function ( array $rows ) use ( $id, $ability, $user, $keys ): array {
				array_unshift(
					$rows,
					array(
						'id'      => $id,
						'time'    => time(),
						'ability' => $ability,
						'user'    => (int) $user->ID,
						'login'   => (string) $user->user_login,
						'client'  => self::client(),
						'inputs'  => array_slice( $keys, 0, 20 ),
						'outcome' => 'running',
						'status'  => 0,
						'note'    => '',
					)
				);
				return $rows;
			}
		);

		return $id;
	}

	/**
	 * Finish a line.
	 *
	 * @param string $id      Line id.
	 * @param string $outcome done|error|refused|dry-run|queued.
	 * @param int    $status  The REST status the call ended with (0 when it was not a REST route).
	 * @param string $note    A short note (an approval id, an error code).
	 * @return void
	 */
	public static function finish( string $id, string $outcome, int $status = 0, string $note = '' ): void {
		self::write(
			static function ( array $rows ) use ( $id, $outcome, $status, $note ): array {
				foreach ( $rows as $i => $row ) {
					if ( $row['id'] !== $id ) {
						continue;
					}
					// A queued, dry-run or refused line keeps what it is: a later "done" or "error"
					// from the ability's own wrapper must not hide that nothing changed, or why.
					if ( in_array( $row['outcome'], array( 'queued', 'dry-run', 'refused' ), true ) && in_array( $outcome, array( 'done', 'error' ), true ) ) {
						return $rows;
					}
					$rows[ $i ]['outcome'] = $outcome;
					$rows[ $i ]['status']  = $status > 0 ? $status : (int) $row['status'];
					$rows[ $i ]['note']    = '' !== $note ? substr( $note, 0, 120 ) : (string) $row['note'];
					break;
				}
				return $rows;
			}
		);
	}

	/**
	 * The newest lines.
	 *
	 * @param int $limit How many.
	 * @return list<array<string, mixed>>
	 */
	public static function recent( int $limit = 100 ): array {
		$rows = get_option( self::OPTION, array() );

		return array_slice( is_array( $rows ) ? array_values( $rows ) : array(), 0, max( 1, min( self::KEEP, $limit ) ) );
	}

	/**
	 * Who is calling: the OAuth app's name, an application password's name, or WP-CLI.
	 *
	 * @return string
	 */
	private static function client(): string {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return 'WP-CLI';
		}
		$app = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( is_string( $app ) && '' !== $app ) {
			foreach ( (array) \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $item ) {
				if ( ( $item['uuid'] ?? '' ) === $app ) {
					return substr( sanitize_text_field( (string) $item['name'] ), 0, 60 );
				}
			}
		}

		return 'MCP';
	}

	/**
	 * Read, change and store, bounded.
	 *
	 * @param callable $change Receives the rows, returns them.
	 * @return void
	 */
	private static function write( callable $change ): void {
		$rows = get_option( self::OPTION, array() );
		$rows = $change( is_array( $rows ) ? array_values( $rows ) : array() );
		update_option( self::OPTION, array_slice( $rows, 0, self::KEEP ), false );
	}
}
