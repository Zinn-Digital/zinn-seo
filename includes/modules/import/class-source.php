<?php
/**
 * One other SEO plugin's stored data, read in this plugin's terms (zs-260, zs-261).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⭐ ONE reading, two uses. A source answers "what did the other plugin store for this post, this
 * term, this site?" in OUR field and setting names. The copy job writes those answers down; the
 * side-by-side reader gives the same answers to the head while the copy has not reached a page.
 * Because both go through the same method, a page cannot say one thing during the import and
 * another after it.
 *
 * A source READS ONLY: it never writes to the other plugin's rows, options or tables, and it does
 * not call the other plugin's code (which may be switched off, or removed).
 *
 * Values are returned as the other plugin stored them, converted to our shape (its variables to
 * ours, its "noindex = 1" to our list) but NOT yet checked: the importer runs each through the
 * field's own sanitiser, counts what was refused, and never stores a value the editor would not
 * accept.
 */
abstract class Source {

	/**
	 * Variables this source used that have no counterpart here: name => times seen.
	 *
	 * @var array<string, int>
	 */
	protected array $unknown = array();

	/**
	 * Short id, `[a-z]+`.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * The plugin's name (a brand: never translated).
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * Its main files (free and paid editions): the ones switched off after the proof.
	 *
	 * @return array<int, string>
	 */
	abstract public function plugins(): array;

	/**
	 * Has it left data on this site?
	 *
	 * @return bool
	 */
	abstract public function present(): bool;

	/**
	 * Does it hold page data (titles, descriptions…)? False for a redirects-only plugin.
	 *
	 * @return bool
	 */
	public function has_pages(): bool {
		return true;
	}

	/**
	 * What it stored for one post: our field name => value.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	abstract public function post( int $post_id ): array;

	/**
	 * What it stored for one term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term( \WP_Term $term ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- a source without term data.
		return array();
	}

	/**
	 * What it stored for one user: the author's page (title, desc, robots) and the profiles on
	 * the user record (x, facebook, fediverse, same_as, job_title).
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user( \WP_User $user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- a source without author data.
		return array();
	}

	/**
	 * One of its templates in our variables (a source with no variables returns the text).
	 *
	 * @param string $text Template.
	 * @return string
	 */
	public function template( string $text ): string {
		return $text;
	}

	/**
	 * The page's structured-data types as the `schema_import` field (JSON, or '' for none).
	 *
	 * Text holding the other plugin's variables is written out for this post as it reads today:
	 * a form's answers are plain words, not templates.
	 *
	 * @param array<int, mixed>     $nodes   The types it held, each as the node search engines read.
	 * @param int                   $post_id Post.
	 * @param array<string, string> $special Its variables with no counterpart, name as written => text.
	 * @return string
	 */
	protected function schema_field( array $nodes, int $post_id, array $special = array() ): string {
		if ( array() === $nodes ) {
			return '';
		}
		require_once __DIR__ . '/class-schema-map.php';
		$post   = get_post( $post_id );
		$lookup = $post instanceof \WP_Post ? \ZinnDigital\ZinnSEO\Templates\Resolver::lookup( \ZinnDigital\ZinnSEO\Templates\Context::for_post( $post ) ) : static fn(): ?string => null;
		$seen   = $this->unknown;
		$json   = Schema_Map::encode(
			$nodes,
			function ( string $text ) use ( $special, $lookup ): string {
				if ( false === strpbrk( $text, '%#{[' ) ) {
					return $text;
				}
				$text = array() !== $special ? strtr( $text, $special ) : $text;

				return \ZinnDigital\ZinnSEO\Templates\Vars::render( $this->template( $text ), $lookup, \ZinnDigital\ZinnSEO\Templates\Resolver::sep() );
			}
		);
		// A variable met only inside structured data is not reported as one of its templates'.
		$this->unknown = $seen;

		return $json;
	}

	/**
	 * A user's own meta value as a trimmed string.
	 *
	 * @param int    $user_id User.
	 * @param string $key     Meta key.
	 * @return string
	 */
	protected static function um( int $user_id, string $key ): string {
		$value = get_user_meta( $user_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * An X (Twitter) username from whatever was typed: a name, @name or a profile address.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function x_handle( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '#^https?://(?:www\.)?(?:twitter|x)\.com/@?([A-Za-z0-9_]{1,15})/?(?:[?\#].*)?$#i', $value, $m ) ) {
			return $m[1];
		}
		$value = ltrim( $value, '@' );

		return 1 === preg_match( '/^[A-Za-z0-9_]{1,15}$/', $value ) ? $value : '';
	}

	/**
	 * A Facebook profile address from a name or an address.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function facebook_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 !== preg_match( '#^https?://#i', $value ) ) {
			return 1 === preg_match( '/^[A-Za-z0-9.]{3,80}$/', $value ) ? 'https://www.facebook.com/' . $value : '';
		}

		return (string) preg_replace( '#^http://#i', 'https://', $value );
	}

	/**
	 * Profile addresses as our "profiles elsewhere" lines (valid addresses only, each once).
	 *
	 * @param array<int, mixed> $urls Addresses.
	 * @return string
	 */
	public static function profile_lines( array $urls ): string {
		$out = array();
		foreach ( $urls as $url ) {
			$url = is_scalar( $url ) ? esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) ) : '';
			if ( '' !== $url ) {
				$out[ $url ] = $url;
			}
		}

		return implode( "\n", array_values( $out ) );
	}

	/**
	 * Its site-wide settings: our setting key => value (templates as context => record).
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return array();
	}

	/**
	 * The id of its redirects in the redirects module's importer, or ''.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return '';
	}

	/**
	 * Data with no page, term or setting of its own: counted in a dry run, copied otherwise, at
	 * the end of the move. Returns counts for the screen.
	 *
	 * By default: the robots.txt rules visitors were given while this plugin ran it (queue Q254 —
	 * Yoast's crawler clean-up and AI-crawler rules, All in One SEO's rule editor, The SEO
	 * Framework's AI and SEO crawler blocks, SureRank's and Slim SEO's own text all vanished at
	 * the switch-off). Read as a visitor reads it, so every plugin's way of building the text is
	 * covered: the other plugin is still active and ours stands down, so `/robots.txt` is its
	 * text. WordPress's own defaults and the sitemap lines are left out (ours are printed), the
	 * rest becomes the site's own rules here — only when the site has written none of its own
	 * here. A source with other extras (Link Whisper, Rank Math) overrides this.
	 *
	 * @param bool $dry Count only.
	 * @return array<string, int>
	 */
	public function extras( bool $dry ): array {
		return $this->robots_extras( $dry );
	}

	/**
	 * Take back what extras() copied (an undo).
	 *
	 * @return int Items removed.
	 */
	public function undo_extras(): int {
		return $this->robots_undo();
	}

	/**
	 * Does this plugin write robots.txt? (A redirects or structured-data plugin does not: what
	 * robots.txt says under it is WordPress's and other plugins', nothing to bring over.)
	 *
	 * @return bool
	 */
	protected function writes_robots(): bool {
		return true;
	}

	/**
	 * The option that keeps the site's own robots.txt rules from before an import.
	 *
	 * @return string
	 */
	protected function robots_before_option(): string {
		return 'zseo_import_robots_before_' . $this->id();
	}

	/**
	 * The robots.txt rules this plugin gave visitors (see extras()), as our rules text, or ''.
	 *
	 * @return string
	 */
	public function robots_rules(): string {
		if ( ! class_exists( '\ZinnDigital\ZinnSEO\Robots\Robots' ) || \ZinnDigital\ZinnSEO\Robots\Robots::physical_file() || '0' === (string) get_option( 'blog_public', '1' ) || array() === $this->active() ) {
			return '';
		}
		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$text = \ZinnDigital\ZinnSEO\Robots\Robots::foreign( (string) wp_remote_retrieve_body( $response ) );

		return trim( \ZinnDigital\ZinnSEO\Robots\Robots::clean( $text ) );
	}

	/**
	 * Copy the robots.txt rules (when this site has written none of its own here).
	 *
	 * @param bool $dry Count only.
	 * @return array<string, int>
	 */
	protected function robots_extras( bool $dry ): array {
		if ( ! $this->writes_robots() ) {
			return array();
		}
		$text = $this->robots_rules();
		if ( '' === $text ) {
			return array();
		}
		$mine = \ZinnDigital\ZinnSEO\Robots\Robots::custom();
		if ( '' !== trim( $mine ) && trim( $mine ) !== $text ) {
			return array( 'robots_rules_kept_yours' => 1 );
		}
		if ( ! $dry && trim( $mine ) !== $text ) {
			update_option(
				$this->robots_before_option(),
				array(
					'before' => $mine,
					'wrote'  => $text,
				),
				false
			);
			update_option( \ZinnDigital\ZinnSEO\Robots\Robots::CUSTOM, $text, false );
		}

		return array( 'robots_rules' => count( array_filter( explode( "\n", $text ), static fn( string $l ): bool => '' !== trim( $l ) && '#' !== substr( trim( $l ), 0, 1 ) && 0 !== stripos( trim( $l ), 'user-agent' ) ) ) );
	}

	/**
	 * Put back the robots.txt rules the import replaced.
	 *
	 * @return int
	 */
	protected function robots_undo(): int {
		$kept = get_option( $this->robots_before_option(), null );
		if ( ! is_array( $kept ) || ! class_exists( '\ZinnDigital\ZinnSEO\Robots\Robots' ) ) {
			return 0;
		}
		// Only while the rules are still the imported ones: an edit made since is the owner's.
		if ( \ZinnDigital\ZinnSEO\Robots\Robots::custom() === (string) ( $kept['wrote'] ?? '' ) ) {
			update_option( \ZinnDigital\ZinnSEO\Robots\Robots::CUSTOM, (string) ( $kept['before'] ?? '' ), false );
		}
		delete_option( $this->robots_before_option() );

		return 1;
	}

	/**
	 * Read whatever the next posts need in one query (sources that keep data in their own table).
	 *
	 * @param array<int, int> $post_ids Posts.
	 * @return void
	 */
	public function prime_posts( array $post_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- post meta is primed by the caller.
	}

	/**
	 * Read whatever the next terms need in one query.
	 *
	 * @param array<int, int> $term_ids Terms.
	 * @return void
	 */
	public function prime_terms( array $term_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- term meta is primed by the caller.
	}

	/**
	 * Things it does that have no place here, in words, for the screen (already translated).
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return array();
	}

	/**
	 * Variables seen with no counterpart here.
	 *
	 * @return array<string, int>
	 */
	public function unknown_variables(): array {
		return $this->unknown;
	}

	/**
	 * Is one of its editions active on this site?
	 *
	 * @return array<int, string> The active main files.
	 */
	public function active(): array {
		$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		}

		return array_values( array_intersect( $this->plugins(), $active ) );
	}

	/**
	 * Convert a template: the other plugin's variables to ours.
	 *
	 * @param string                        $text    Its template.
	 * @param string                        $pattern A pattern whose first group is a variable's name.
	 * @param array<string, string>         $map     Its name => our text (`%title%`, or '' to drop).
	 * @param array<string, callable>|array $prefix  Prefix => `function ( string $rest ): string` for families (`cf_`).
	 * @param bool                          $keep    Leave an unknown name as written (a syntax like `#word`, which ordinary text uses too).
	 * @return string
	 */
	protected function convert( string $text, string $pattern, array $map, array $prefix = array(), bool $keep = false ): string {
		if ( '' === $text ) {
			return '';
		}
		// A literal percent sign pair that would read as one of OUR variables is left alone only
		// when the source uses another syntax; every source's own syntax is replaced below.
		$out = (string) preg_replace_callback(
			$pattern,
			function ( array $m ) use ( $map, $prefix, $keep ): string {
				$name = strtolower( trim( (string) $m[1] ) );
				if ( array_key_exists( $name, $map ) ) {
					return $map[ $name ];
				}
				foreach ( $prefix as $start => $make ) {
					if ( 0 === strpos( $name, (string) $start ) ) {
						return (string) $make( substr( $name, strlen( (string) $start ) ), $m );
					}
				}
				if ( $keep ) {
					return (string) $m[0];
				}
				$this->unknown[ $name ] = (int) ( $this->unknown[ $name ] ?? 0 ) + 1;
				return '';
			},
			$text
		);

		return trim( (string) preg_replace( '/[ \t]+/', ' ', $out ) );
	}

	/**
	 * Our name for a custom field or taxonomy variable, or '' when the name cannot be one.
	 *
	 * @param string $family `cf` or `ct`.
	 * @param string $rest   The field or taxonomy name.
	 * @return string
	 */
	protected static function named( string $family, string $rest ): string {
		$rest = strtolower( trim( $rest ) );

		return 1 === preg_match( '/^[a-z0-9_-]{1,60}$/', $rest ) ? '%' . $family . '_' . $rest . '%' : '';
	}

	/**
	 * The media library ID of an image address, or 0 (the address may be a sized copy).
	 *
	 * @param string $url Address.
	 * @return int
	 */
	public static function image_id( string $url ): int {
		$url = trim( $url );
		if ( '' === $url ) {
			return 0;
		}
		$id = (int) attachment_url_to_postid( $url );
		if ( $id < 1 ) {
			$full = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
			if ( $full !== $url ) {
				$id = (int) attachment_url_to_postid( $full );
			}
		}

		if ( $id < 1 && function_exists( 'wp_get_upload_dir' ) ) {
			// Stored while the site had another address (a move, staging, http before https):
			// the same file under today's uploads address. (Found by the comparison: the other
			// plugin printed the old address, ours printed no image).
			$base      = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );
			$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );
			$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $base_path && 0 === strpos( $path, trailingslashit( $base_path ) ) ) {
				$moved = $base . substr( $path, strlen( $base_path ) );
				if ( $moved !== $url ) {
					$id = self::image_id( $moved );
				}
			}
		}

		return $id > 0 ? $id : 0;
	}

	/**
	 * An image ID when it is one, else the ID of an address.
	 *
	 * @param mixed $id  Stored ID.
	 * @param mixed $url Stored address.
	 * @return int
	 */
	protected static function image( $id, $url = '' ): int {
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
			return $id;
		}

		return is_string( $url ) ? self::image_id( $url ) : 0;
	}

	/**
	 * A robots list from flags: `array( 'noindex' => true, 'index' => false, … )`.
	 *
	 * @param array<string, bool> $flags Flags.
	 * @return array<int, string>
	 */
	protected static function robots( array $flags ): array {
		$out = array();
		foreach ( array( 'noindex', 'index', 'nofollow', 'follow', 'noarchive', 'nosnippet', 'noimageindex' ) as $flag ) {
			if ( ! empty( $flags[ $flag ] ) ) {
				$out[] = $flag;
			}
		}

		return $out;
	}

	/**
	 * Drop empty values, so a field the other plugin left alone is not a field at all.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	protected static function filled( array $fields ): array {
		foreach ( $fields as $name => $value ) {
			if ( null === $value || '' === $value || array() === $value || 0 === $value || false === $value ) {
				unset( $fields[ $name ] );
			}
		}

		return $fields;
	}

	/**
	 * A post's own meta value as a trimmed string (from the primed cache).
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @return string
	 */
	protected static function pm( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A term's own meta value as a trimmed string.
	 *
	 * @param int    $term_id Term.
	 * @param string $key     Meta key.
	 * @return string
	 */
	protected static function tm( int $term_id, string $key ): string {
		$value = get_term_meta( $term_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The separator of ours nearest to a character, or '' when none is the same.
	 *
	 * @param string $char Character (may be an HTML entity).
	 * @return string
	 */
	protected static function separator( string $char ): string {
		$char = trim( html_entity_decode( $char, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return in_array( $char, \ZinnDigital\ZinnSEO\Templates\Templates::separators(), true ) ? $char : '';
	}
}
