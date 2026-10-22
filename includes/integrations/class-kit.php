<?php
/**
 * What every integration needs: a say in a page's title, description, address and robots, a place
 * in the page's one graph, addresses in the sitemap — written once.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Integrations;

use ZinnDigital\ZinnSEO\Templates\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded with the first integration that starts, never before. Each helper adds ONE hook the
 * core already fires; none runs a query of its own.
 */
final class Kit {

	/**
	 * Rules that keep a page out of search results: callables `function ( Context $c ): bool`.
	 *
	 * @var array<int, callable>
	 */
	private static array $noindex = array();

	/**
	 * Answers for a page's values: field => callables `function ( $value, Context $c )`.
	 *
	 * @var array<string, array<int, callable>>
	 */
	private static array $values = array();

	/**
	 * Is the value filter hooked?
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Hook the one value filter (once).
	 *
	 * @return void
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_filter( 'zinn_seo_value', array( self::class, 'value' ), 10, 3 );
	}

	/**
	 * The core's value filter: robots rules first, then each integration's answer.
	 *
	 * @param mixed   $value Value.
	 * @param string  $field title, desc, canonical or robots.
	 * @param Context $c     The page.
	 * @return mixed
	 */
	public static function value( $value, $field, $c ) {
		if ( ! $c instanceof Context ) {
			return $value;
		}
		if ( 'robots' === $field && is_array( $value ) && ! isset( $value['noindex'] ) ) {
			foreach ( self::$noindex as $rule ) {
				if ( $rule( $c ) ) {
					$value['noindex'] = true;
					// Preview limits mean nothing on a page kept out of the index.
					unset( $value['max-snippet'], $value['max-image-preview'], $value['max-video-preview'] );
					break;
				}
			}
		}
		foreach ( self::$values[ (string) $field ] ?? array() as $answer ) {
			$value = $answer( $value, $c );
		}

		return $value;
	}

	/**
	 * Keep the pages a rule names out of search results (their links are still followed).
	 *
	 * @param callable $rule `function ( Context $c ): bool`.
	 * @return void
	 */
	public static function noindex( callable $rule ): void {
		self::hook();
		self::$noindex[] = $rule;
	}

	/**
	 * Answer for a page's title, description (`desc`), `canonical` or `robots`.
	 *
	 * @param string   $field  Which.
	 * @param callable $answer `function ( $value, Context $c )`, returning the same type.
	 * @return void
	 */
	public static function answer( string $field, callable $answer ): void {
		self::hook();
		self::$values[ $field ][] = $answer;
	}

	/**
	 * Add to (or change) the page's graph.
	 *
	 * @param callable $build    `function ( array $nodes, Context $c ): array`.
	 * @param int      $priority After the schema module (15) by default.
	 * @return void
	 */
	public static function graph( callable $build, int $priority = 30 ): void {
		add_filter(
			'zinn_seo_graph',
			static function ( $nodes, $c ) use ( $build ) {
				return $c instanceof Context ? (array) $build( (array) $nodes, $c ) : $nodes;
			},
			$priority,
			2
		);
	}

	/**
	 * A node another plugin built, made part of the page's graph: no `@context` of its own, an
	 * `@id` under the page's address, and tied to the page.
	 *
	 * @param array<string, mixed> $node     The node.
	 * @param string               $url      The page's address.
	 * @param string               $fragment Its `@id` fragment (`product`, `event`…).
	 * @param bool                 $main     It is what the page is about.
	 * @return array<string, mixed>
	 */
	public static function adopt( array $node, string $url, string $fragment, bool $main = true ): array {
		unset( $node['@context'] );
		$node['@id'] = $url . '#' . $fragment;
		if ( $main ) {
			$node['mainEntityOfPage'] = array( '@id' => $url . '#webpage' );
		}

		return $node;
	}

	/**
	 * Properties of schema.org whose value is yes or no: for these `false` is an answer, not an
	 * empty value.
	 */
	public const YES_NO = array( 'isAccessibleForFree', 'isFamilyFriendly', 'directApply', 'requiresSubscription', 'valueAddedTaxIncluded', 'isGift', 'isLiveBroadcast', 'representativeOfPage', 'abridged', 'multipleValues', 'readonlyValue', 'valueRequired', 'smokingAllowed', 'petsAllowed', 'publicAccess', 'isAcceptingNewPatients', 'acceptsReservations' );

	/**
	 * Empty values out, at every depth (a property is said or it is not there).
	 *
	 * @param array<mixed, mixed> $node Node.
	 * @return array<mixed, mixed>
	 */
	public static function prune( array $node ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::prune( $value );
				// A typed node that says nothing but its type is nothing.
				if ( array() === $value || ( isset( $value['@type'] ) && 1 === count( $value ) ) ) {
					unset( $node[ $key ] );
					continue;
				}
				$node[ $key ] = $value;
			} elseif ( null === $value || '' === $value || ( false === $value && ! in_array( $key, self::YES_NO, true ) ) ) {
				// (Plugins say "no value" with false — `image: false` — so false goes too, except
				// for the properties whose answer IS yes or no.)
				unset( $node[ $key ] );
			}
		}

		return $node;
	}

	/**
	 * Plain text for a property: tags and shortcodes out, spaces tidied, cut at a length.
	 *
	 * @param mixed $text Text.
	 * @param int   $max  Longest (0: no limit).
	 * @return string
	 */
	public static function text( $text, int $max = 0 ): string {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ), true ) ) );
		if ( $max > 0 && mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * A source of sitemap addresses that are not posts, terms or authors.
	 *
	 * @param string   $id    `[a-z0-9-]+`.
	 * @param callable $read  `function ( int $after, int $until, int $limit ): object[]` (each with `ID`, ascending).
	 * @param callable $entry `function ( object $row ): array` (`loc`, `lastmod`, `images`).
	 * @param callable $count `function (): int`, optional.
	 * @return void
	 */
	public static function sitemap_source( string $id, callable $read, callable $entry, ?callable $count = null ): void {
		add_filter(
			'zinn_seo_sitemap_sources',
			static function ( $sources ) use ( $id, $read, $entry, $count ) {
				$sources        = (array) $sources;
				$sources[ $id ] = array_filter(
					array(
						'read'  => $read,
						'entry' => $entry,
						'count' => $count,
					)
				);
				return $sources;
			}
		);
	}

	/**
	 * Leave some posts out of the sitemap.
	 *
	 * @param callable $skip `function ( \WP_Post $post ): bool`.
	 * @return void
	 */
	public static function sitemap_skip( callable $skip ): void {
		add_filter(
			'zinn_seo_sitemap_entry',
			static function ( $entry, $post ) use ( $skip ) {
				return array() !== $entry && $post instanceof \WP_Post && $skip( $post ) ? array() : $entry;
			},
			20,
			2
		);
	}

	/**
	 * Pages that are for one visitor at a time (a basket, a checkout, an account, a dashboard):
	 * out of search results and out of the sitemap.
	 *
	 * @param callable $ids `function (): int[]` — the pages' ids, asked once per request and only
	 *                      when a page or a sitemap entry is being decided.
	 * @return void
	 */
	public static function private_pages( callable $ids ): void {
		$memo = null;
		$list = static function () use ( &$memo, $ids ): array {
			if ( null === $memo ) {
				$memo = array_values( array_filter( array_map( 'intval', (array) $ids() ) ) );
			}
			return $memo;
		};
		self::noindex(
			static function ( Context $c ) use ( $list ): bool {
				$post = 'singular' === $c->kind ? $c->post() : null;
				return $post instanceof \WP_Post && in_array( (int) $post->ID, $list(), true );
			}
		);
		self::sitemap_skip( static fn( \WP_Post $post ): bool => in_array( (int) $post->ID, $list(), true ) );
	}

	/**
	 * Does this plugin print the page's graph (so another plugin's own script must not)? False
	 * while another SEO plugin owns the head.
	 *
	 * @return bool
	 */
	public static function prints_graph(): bool {
		$library = '\ZinnDigital\ZinnSEO\Blocks\Library';

		return ! class_exists( $library ) || ! is_callable( array( $library, 'we_print_the_graph' ) ) || (bool) $library::we_print_the_graph();
	}

	/**
	 * Stop another plugin printing its own structured data, once it is known that this page's
	 * graph carries it (asked on `wp`, when the page is known).
	 *
	 * @param callable $stop `function (): void` — remove its output hook.
	 * @return void
	 */
	public static function silence( callable $stop ): void {
		add_action(
			'wp',
			static function () use ( $stop ): void {
				if ( self::prints_graph() ) {
					$stop();
				}
			},
			20
		);
	}

	/**
	 * Pages another plugin describes completely by itself (a forum that writes its own title,
	 * description, address, sharing tags and structured data for every topic): there this
	 * plugin prints none of its own, so the page carries ONE set of tags — the plugin's that
	 * knows the page. Site verification tags and the robots rules still apply.
	 *
	 * @param callable $when  `function (): bool` — whether the page being viewed is one of those.
	 * @param bool     $graph The other plugin prints structured data too (false: ours stays).
	 * @return void
	 */
	public static function defer( callable $when, bool $graph = true ): void {
		$memo = null;
		$here = static function ( Context $c ) use ( &$memo, $when ): bool {
			if ( ! self::viewing( $c ) ) {
				return false;
			}
			if ( null === $memo ) {
				$memo = (bool) $when();
			}
			return $memo;
		};
		foreach ( array( 'title', 'desc', 'canonical' ) as $field ) {
			self::answer( $field, static fn( $value, Context $c ) => $here( $c ) ? '' : $value );
		}
		add_filter(
			'zinn_seo_head_tags',
			static function ( $lines, $c ) use ( $here ) {
				if ( ! $c instanceof Context || ! $here( $c ) ) {
					return $lines;
				}
				// Only what is about the site, not the page: its verification codes.
				return array_values( array_filter( (array) $lines, static fn( $line ): bool => is_string( $line ) && false !== strpos( $line, '-verification' ) ) );
			},
			999,
			2
		);
		if ( $graph ) {
			add_filter(
				'zinn_seo_graph',
				static fn( $nodes, $c ) => $c instanceof Context && $here( $c ) ? array() : $nodes,
				999,
				2
			);
		}
	}

	/**
	 * Add other sitemap files to this site's sitemap index (a forum plugin's own topic
	 * sitemaps), so search engines are given one index for the whole site.
	 *
	 * @param callable $files `function (): string[]` — the files' addresses, asked when the index is built.
	 * @return void
	 */
	public static function sitemap_files( callable $files ): void {
		add_filter(
			'zinn_seo_sitemap_index_rows',
			static function ( $rows ) use ( $files ) {
				$rows = (array) $rows;
				$have = array_column( $rows, 'loc' );
				foreach ( (array) $files() as $url ) {
					$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
					if ( '' !== $url && ! in_array( $url, $have, true ) ) {
						$rows[] = array(
							'loc'     => $url,
							'lastmod' => 0,
						);
						$have[] = $url;
					}
				}
				return $rows;
			}
		);
	}

	/**
	 * Remove a callback another plugin hooked as a method of one of its objects (it keeps the
	 * object to itself, so `remove_action()` cannot name it).
	 *
	 * @param string $hook   Hook.
	 * @param string $owner  The object's class.
	 * @param string $method The method.
	 * @return int How many callbacks were removed.
	 */
	public static function unhook( string $hook, string $owner, string $method ): int {
		global $wp_filter;
		$removed = 0;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return 0;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) && $method === $fn[1] && ( is_object( $fn[0] ) ? $fn[0] instanceof $owner : ltrim( (string) $fn[0], '\\' ) === ltrim( $owner, '\\' ) ) ) {
					remove_filter( $hook, $fn, (int) $priority );
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * Leave whole post types or taxonomies out of the sitemap (pages only members can read, a
	 * forum's reply pages).
	 *
	 * @param array<int, string> $units `post:<type>` and `tax:<taxonomy>`.
	 * @return void
	 */
	public static function sitemap_without( array $units ): void {
		add_filter(
			'zinn_seo_sitemap_units',
			static function ( $all ) use ( $units ) {
				return array_values( array_diff( (array) $all, $units ) );
			}
		);
	}

	/**
	 * Tell the sitemap that a source's addresses changed (rebuilt by the background job).
	 *
	 * @param string $id Source id.
	 * @return void
	 */
	public static function sitemap_touch( string $id ): void {
		$store = '\ZinnDigital\ZinnSEO\Sitemaps\Store';
		if ( class_exists( $store ) && is_callable( array( $store, 'mark' ) ) ) {
			$store::mark( array( 'x:' . $id . '|||0' ) );
		}
	}

	/**
	 * Is a context the page being viewed (and not one built to describe another object: a
	 * sitemap entry, a preview, a check)? Rules that read the other plugin's own "what page is
	 * this" functions apply only then.
	 *
	 * @param Context $c Context.
	 * @return bool
	 */
	public static function viewing( Context $c ): bool {
		return did_action( 'wp' ) > 0 && Context::current() === $c;
	}

	/**
	 * A title in the site's shape: the page's own words, the separator, the site's name.
	 *
	 * @param string $words The page's own words.
	 * @return string
	 */
	public static function title( string $words ): string {
		$site = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$sep  = \ZinnDigital\ZinnSEO\Templates\Resolver::sep();

		return '' === trim( $words ) ? $site : trim( self::text( $words ) ) . ' ' . $sep . ' ' . $site;
	}

	/**
	 * A person as a node from a WordPress user (name, and a profile address when given).
	 *
	 * @param int    $user_id User (0: a guest).
	 * @param string $name    Name to use for a guest, or when the user is gone.
	 * @param string $url     The person's page on this site ('' for none).
	 * @return array<string, string>
	 */
	public static function person( int $user_id, string $name = '', string $url = '' ): array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		$name = $user ? (string) $user->display_name : $name;

		return array_filter(
			array(
				'@type' => 'Person',
				'name'  => '' !== trim( $name ) ? self::text( $name ) : __( 'Guest', 'zinn-seo' ),
				'url'   => $url,
			)
		);
	}

	/**
	 * The `@id` of the site's own Organization or Person node (what a seller, a provider or a
	 * publisher points to).
	 *
	 * @return string
	 */
	public static function site_id(): string {
		$person = 'person' === (string) \ZinnDigital\ZinnSEO\Settings::get( 'identity_type', 'organization' );

		return home_url( '/' ) . ( $person ? '#person' : '#organization' );
	}

	/**
	 * The post a page is about, when it is one of some types.
	 *
	 * @param Context            $c     The page.
	 * @param array<int, string> $types Post types.
	 * @return \WP_Post|null
	 */
	public static function post_of( Context $c, array $types ): ?\WP_Post {
		$post = 'singular' === $c->kind ? $c->post() : null;

		return $post instanceof \WP_Post && in_array( $post->post_type, $types, true ) ? $post : null;
	}

	/**
	 * A picture's address from the media library ('' when there is none).
	 *
	 * @param int $attachment Attachment.
	 * @return string
	 */
	public static function image( int $attachment ): string {
		return $attachment > 0 ? (string) wp_get_attachment_image_url( $attachment, 'full' ) : '';
	}

	/**
	 * An ISO 8601 duration from minutes ('' for none).
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public static function duration( int $minutes ): string {
		if ( $minutes < 1 ) {
			return '';
		}
		$h = intdiv( $minutes, 60 );
		$m = $minutes % 60;

		return 'PT' . ( $h > 0 ? $h . 'H' : '' ) . ( $m > 0 ? $m . 'M' : '' );
	}
}
