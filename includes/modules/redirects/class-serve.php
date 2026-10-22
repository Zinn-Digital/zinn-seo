<?php
/**
 * Serve redirects to visitors, before the theme loads (zs-094, zs-095).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Redirects;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The visitor's side:
 *
 * 1. `boot()` runs while the plugin's modules boot (`plugins_loaded`), BEFORE WordPress sets up
 *    the theme, parses the request or runs a query: a matching rule answers there and then (one
 *    shard file read; the hit is counted with one UPDATE on the redirect response itself). The Pro
 *    "fast redirects" drop-in answers earlier still, before any plugin loads; a server export
 *    answers without PHP at all.
 * 2. 410 and 451 rules let WordPress go on so the theme's not-found page is shown — with that
 *    status — and nothing about the old page.
 * 3. On a real 404 (`template_redirect`), every regular-expression rule is tried — not only the
 *    first 500 — and then the optional fallback.
 *
 * Only GET and HEAD requests are redirected (a form posted to an old address is never turned
 * into a GET). The response says `X-Redirect-By: WordPress`, never this plugin's name.
 */
final class Serve {

	/**
	 * A 410/451 rule matched early: [id, code].
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private static ?array $gone = null;

	/**
	 * A rule matched early that may only answer on a real 404 (a Pro condition): look again then.
	 *
	 * @var bool
	 */
	private static bool $retry = false;

	/**
	 * Ask for the rules to be looked up again if WordPress finds no page (a rule that answers
	 * "only when the page does not exist" asks for this while being matched early).
	 *
	 * @return void
	 */
	public static function retry_on_404(): void {
		self::$retry = true;
	}

	/**
	 * May a matched rule answer this request? Rules carry no conditions in the free plugin; the
	 * Pro conditional redirects answer here (login state, role, browser, referrer, cookie, header,
	 * IP range, language, a custom check, or only on a 404).
	 *
	 * @param array<string, mixed>|null $found The match.
	 * @param string                    $phase early (before the theme) or 404.
	 * @return bool
	 */
	private static function allowed( ?array $found, string $phase ): bool {
		if ( null === $found ) {
			return false;
		}
		// ⚖️ §2.62: a rule Pro gave conditions to (members only, phones, a country…) never answers
		// EVERYONE once nothing decides its conditions (Pro stopped, or the free plugin runs): the
		// address behaves as if the rule were not there. Only "when the page no longer exists"
		// can be decided here, so such a rule still answers a real 404 — never a new 404.
		$conds = self::stored_conditions( (int) ( $found['id'] ?? 0 ) );
		if ( null !== $conds && ! \ZinnDigital\ZinnSEO\Modules::enabled( 'redirect-conditions' ) ) {
			if ( ! self::decided_by_404( $conds ) ) {
				return false;
			}
			if ( '404' !== $phase ) {
				self::retry_on_404();
				return false;
			}
			return true;
		}

		/**
		 * Filters whether a matched redirect answers this request.
		 *
		 * @param bool                 $allowed Whether it answers.
		 * @param array<string, mixed> $found   `id`, `code`, `location`.
		 * @param string               $phase   early or 404.
		 */
		return (bool) apply_filters( 'zinn_seo_redirect_allowed', true, $found, $phase );
	}

	/**
	 * The conditions Pro stored for a rule (`zseo_redirect_conditions`), or null when it has none.
	 * Read only after a rule matched.
	 *
	 * @param int $id Rule.
	 * @return array<string, mixed>|null
	 */
	public static function stored_conditions( int $id ): ?array {
		$all   = get_option( 'zseo_redirect_conditions', array() ); // WordPress keeps it for the rest of the request.
		$conds = is_array( $all ) ? ( $all[ $id ] ?? null ) : null;

		return is_array( $conds ) && array() !== (array) ( $conds['rules'] ?? array() ) ? $conds : null;
	}

	/**
	 * Can a 404 alone decide these conditions? Yes when every condition is "the page no longer
	 * exists", or when any one of them is enough and that is one of them.
	 *
	 * @param array<string, mixed> $conds Conditions (`match` all|any, `rules`).
	 * @return bool
	 */
	public static function decided_by_404( array $conds ): bool {
		$types = array_map( static fn( $r ): string => (string) ( is_array( $r ) ? ( $r['type'] ?? '' ) : '' ), (array) ( $conds['rules'] ?? array() ) );
		if ( array() === $types ) {
			return false;
		}
		if ( 'any' === ( $conds['match'] ?? 'all' ) ) {
			return in_array( 'notfound', $types, true );
		}

		return array( 'notfound' ) === array_values( array_unique( $types ) );
	}

	/**
	 * Hook the visitor's side.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! self::eligible() ) {
			return;
		}
		// The Pro drop-in already answered a 410/451 before any plugin loaded and left its rule.
		if ( isset( $GLOBALS['zseo_early_gone'] ) && is_array( $GLOBALS['zseo_early_gone'] ) ) {
			self::$gone = array( (int) $GLOBALS['zseo_early_gone'][0], (int) $GLOBALS['zseo_early_gone'][1] );
			add_action( 'template_redirect', array( self::class, 'gone' ), 0 );
			return;
		}
		// The drop-in already looked this request up (plain rules and the first patterns).
		if ( '' !== Index::store() && empty( $GLOBALS['zseo_early_checked'] ) ) {
			// Right after every module has booted (still before the theme loads), so a Pro
			// module that decides whether a rule may answer (conditions, zs-734) is listening.
			if ( doing_action( 'plugins_loaded' ) ) {
				add_action( 'plugins_loaded', array( self::class, 'early' ), 21 );
			} else {
				self::early();
			}
		}
		add_action( 'template_redirect', array( self::class, 'not_found' ), 1 );
	}

	/**
	 * The early lookup: a matching rule answers before the theme loads.
	 *
	 * @return void
	 */
	public static function early(): void {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by Path before any use; never printed.
		$match = self::match( $uri, false );
		if ( null !== $match ) {
			self::answer( $match );
		}
	}

	/**
	 * Is this request one a redirect may answer?
	 *
	 * @return bool
	 */
	public static function eligible(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || is_admin() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.

		return 1 !== preg_match( '#/(wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php)(/|\?|$)#', $uri );
	}

	/**
	 * Find the rule for an address.
	 *
	 * @param string $uri     REQUEST_URI.
	 * @param bool   $all_regex Try every regular-expression rule (a 404), not only the first 500.
	 * @param string $phase   'early' (before WordPress parses the request) or 'late' (a 404).
	 * @return array<string, mixed>|null `id`, `code`, `location` (empty for 410/451).
	 */
	public static function match( string $uri, bool $all_regex, string $phase = 'early' ): ?array {
		$base      = Store::base();
		$sensitive = Store::sensitive();
		$query     = Path::split( $uri )[1];
		$pass      = (bool) Settings::get( 'redirects_pass_query', true );
		$found     = Index::find( Path::lookups( $uri, $base, $sensitive ) );
		if ( null !== $found ) {
			// A rule whose source carries a query matched that query; it is not passed on again.
			$result = self::result( $found[0], $found[1], $found[2], array(), $pass && false === strpos( $found[3], '?' ) ? $query : '', $uri );
			if ( self::allowed( $result, $phase ) ) {
				return $result;
			}
		}
		$rules = Index::regex_rules();
		if ( array() === $rules ) {
			return null;
		}
		$path = Path::normal_path( Path::split( $uri )[0], $base, true );
		$n    = 0;
		foreach ( $rules as $rule ) {
			if ( ! $all_regex && ++$n > Index::REGEX_EARLY ) {
				break;
			}
			$m = array();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern that became invalid is skipped, not fatal.
			if ( 1 === @preg_match( Path::delimit( (string) $rule[1], $sensitive ), $path, $m ) ) {
				$result = self::result( (int) $rule[0], (int) $rule[2], (string) $rule[3], $m, $pass ? $query : '', $uri );
				if ( self::allowed( $result, $phase ) ) {
					return $result;
				}
			}
		}

		return null;
	}

	/**
	 * A matched rule's answer.
	 *
	 * @param int                $id     Rule.
	 * @param int                $code   Code.
	 * @param string             $target Target.
	 * @param array<int, string> $groups Captures.
	 * @param string             $query  Query to pass on.
	 * @param string             $uri    The request.
	 * @return array<string, mixed>|null Null when the target is the request itself (a loop guard).
	 */
	private static function result( int $id, int $code, string $target, array $groups, string $query, string $uri ): ?array {
		if ( ! in_array( $code, Store::MOVES, true ) ) {
			return array(
				'id'       => $id,
				'code'     => $code,
				'location' => '',
			);
		}
		$location = Path::target( $target, $groups, $query, '' !== $query, home_url( '/' ) );
		if ( Store::is_local( $location ) && Path::key( $location, Store::base(), Store::sensitive() ) === Path::key( $uri, Store::base(), Store::sensitive() ) ) {
			return null;
		}

		return array(
			'id'       => $id,
			'code'     => $code,
			'location' => $location,
		);
	}

	/**
	 * Answer a matched rule.
	 *
	 * @param array<string, mixed> $found Match.
	 * @return void
	 */
	private static function answer( array $found ): void {
		if ( '' === (string) $found['location'] ) {
			self::$gone = array( (int) $found['id'], (int) $found['code'] );
			add_action( 'template_redirect', array( self::class, 'gone' ), 0 );
			return;
		}
		Store::hit( (int) $found['id'] );
		/**
		 * Fires just before a redirect is sent.
		 *
		 * @param array<string, mixed> $found `id`, `code`, `location`.
		 */
		do_action( 'zinn_seo_redirecting', $found );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- a rule the site's owner stored may send visitors to another site; that is its purpose.
		if ( wp_redirect( (string) $found['location'], (int) $found['code'], 'WordPress' ) ) {
			exit;
		}
	}

	/**
	 * Answer 410 Gone / 451 with the theme's not-found page.
	 *
	 * @return void
	 */
	public static function gone(): void {
		global $wp_query;
		if ( null === self::$gone ) {
			return;
		}
		Store::hit( self::$gone[0] );
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( self::$gone[1] );
		nocache_headers();
		header( 'X-Robots-Tag: noindex', true );
	}

	/**
	 * A real 404: every regular-expression rule, then the fallback.
	 *
	 * @return void
	 */
	public static function not_found(): void {
		if ( ! is_404() || null !== self::$gone ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by Path before any use.
		if ( self::$retry || (int) Settings::get( 'redirects_regex', 0 ) > Index::REGEX_EARLY ) {
			$match = self::match( $uri, true, '404' );
			if ( null !== $match ) {
				self::answer( $match );
				if ( null !== self::$gone ) {
					self::gone();
				}
				return;
			}
		}
		/**
		 * Fires on a 404 no redirect answered (the 404 monitor counts it here).
		 *
		 * @param string $uri The address asked for.
		 */
		do_action( 'zinn_seo_unanswered_404', $uri );
		$fallback = (string) Settings::get( 'redirects_404_fallback', '' );
		if ( '' === $fallback ) {
			return;
		}
		$to = 'home' === $fallback ? home_url( '/' ) : (string) Settings::get( 'redirects_404_target', '' );
		if ( '' !== $to && Path::key( $to, Store::base(), Store::sensitive() ) !== Path::key( $uri, Store::base(), Store::sensitive() ) ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the owner's own fallback address.
			if ( wp_redirect( $to, 301, 'WordPress' ) ) {
				exit;
			}
		}
	}
}
