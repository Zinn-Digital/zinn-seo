<?php
/**
 * Agent Ready: how ready the site is for AI agents and answer engines, as one score with the
 * fix for every gap (zs-784).
 *
 * Four groups, each check worth points: ACCESS (the site is public; the crawlers that answer
 * questions with a link to you are not blocked, read from the robots.txt the site really serves;
 * how your content may be used is stated), DISCOVERY (llms.txt, llms-full.txt with a summary,
 * XML sitemaps), UNDERSTANDING (structured data, the site's identity, Markdown for agents) and
 * ACTION (agents can work with the site through its MCP abilities, signed in as a user).
 *
 * A check whose fix is one setting has a one-click fix; the others link to the screen that
 * fixes them. A Pro check on a free plan is shown but left out of the score, so a free site can
 * reach 100.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AgentReady;

use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checks, the score and the one-click fixes.
 */
final class Agent_Ready {

	/** Answer-engine crawlers checked against robots.txt when the AI crawlers module is off. */
	public const ANSWER_TOKENS = array( 'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'DuckAssistBot' );

	/**
	 * The report: score (0-100), grade, and every check.
	 *
	 * @param bool $live Also fetch /robots.txt and /llms.txt over HTTP and judge what is served.
	 * @return array{score: int, grade: string, checks: array<int, array<string, mixed>>, live: bool}
	 */
	public static function report( bool $live = false ): array {
		$checks = self::checks( self::facts( $live ) );

		return array(
			'score'  => self::score( $checks ),
			'grade'  => self::grade( self::score( $checks ) ),
			'checks' => $checks,
			'live'   => $live,
		);
	}

	/**
	 * What the checks judge, read from the site (kept apart so the checks are pure and tested).
	 *
	 * @param bool $live Fetch the served files too.
	 * @return array<string, mixed>
	 */
	public static function facts( bool $live = false ): array {
		$robots  = self::robots_text();
		$blocked = array();
		if ( Modules::enabled( 'crawlers' ) && class_exists( '\ZinnDigital\ZinnSEO\Crawlers\Crawlers' ) ) {
			$all = \ZinnDigital\ZinnSEO\Crawlers\Crawlers::all();
			foreach ( \ZinnDigital\ZinnSEO\Crawlers\Crawlers::blocked() as $id ) {
				if ( isset( $all[ $id ] ) && in_array( $all[ $id ]['kind'], array( 'answer', 'user' ), true ) ) {
					$blocked[] = (string) $all[ $id ]['token'];
				}
			}
		}
		$by_rules = array();
		foreach ( self::ANSWER_TOKENS as $token ) {
			if ( self::robots_blocks( $robots, $token ) && ! in_array( $token, $blocked, true ) ) {
				$blocked[]  = $token;
				$by_rules[] = $token;
			}
		}
		$facts = array(
			'public'        => '0' !== (string) get_option( 'blog_public', '1' ),
			'answer_block'  => $blocked,
			'answer_rules'  => $by_rules,
			'crawlers_on'   => Modules::enabled( 'crawlers' ),
			'signals'       => (bool) Settings::get( 'ai_signals', false ),
			'llms'          => Modules::enabled( 'llms' ),
			'llms_full'     => (bool) Settings::get( 'llms_full', true ),
			'llms_summary'  => '' !== trim( (string) Settings::get( 'llms_summary', '' ) ),
			'sitemaps'      => Modules::enabled( 'sitemaps' ),
			'schema'        => Modules::enabled( 'schema' ),
			'identity'      => '' !== trim( (string) Settings::get( 'identity_name', '' ) ),
			'markdown'      => Modules::enabled( 'markdown' ),
			'markdown_code' => isset( Modules::discover()['markdown'] ),
			'pro'           => Plugin::is_pro(),
			'mcp'           => Modules::enabled( 'mcp' ),
			'served'        => array(),
		);
		if ( $live ) {
			foreach ( array(
				'robots' => '/robots.txt',
				'llms'   => '/llms.txt',
			) as $key => $path ) {
				$facts['served'][ $key ] = self::fetch_status( home_url( $path ) );
			}
		}

		return $facts;
	}

	/**
	 * Every check from the facts.
	 *
	 * @param array<string, mixed> $f Facts (see facts()).
	 * @return array<int, array<string, mixed>>
	 */
	public static function checks( array $f ): array {
		$discovery = 'admin.php?page=zinn-seo&view=discovery#/';
		$blocked   = (array) ( $f['answer_block'] ?? array() );
		$served    = (array) ( $f['served'] ?? array() );
		$llms_ok   = ! empty( $f['llms'] );
		$llms_note = '';
		if ( $llms_ok && isset( $served['llms'] ) && 200 !== $served['llms'] && 0 !== $served['llms'] ) {
			$llms_ok = false;
			/* translators: %d: an HTTP status code. */
			$llms_note = sprintf( __( 'llms.txt is switched on but the site answers %d for it: a server rule, a cache or another plugin is in the way.', 'zinn-seo' ), (int) $served['llms'] );
		}

		$checks = array(
			self::check(
				'site_public',
				'access',
				15,
				! empty( $f['public'] ),
				__( 'The site is open to search engines and AI', 'zinn-seo' ),
				__( 'WordPress is set to discourage search engines, so robots.txt closes the whole site to every crawler and agent.', 'zinn-seo' ),
				array(
					'type'  => 'link',
					'label' => __( 'Open Settings, Reading', 'zinn-seo' ),
					'url'   => admin_url( 'options-reading.php' ),
				)
			),
			self::check(
				'answer_bots',
				'access',
				20,
				array() === $blocked,
				__( 'AI answer engines may read the site', 'zinn-seo' ),
				'' === implode( '', $blocked ) ? '' : sprintf(
					/* translators: %s: crawler names, e.g. "OAI-SearchBot, PerplexityBot". */
					__( 'These crawlers answer questions with a link to you and are blocked: %s. Blocking the ones that train models is a separate choice and costs no points.', 'zinn-seo' ),
					implode( ', ', $blocked )
				),
				! empty( $f['crawlers_on'] ) && empty( $f['answer_rules'] ) ? array(
					'type'  => 'auto',
					'label' => __( 'Allow the answer engines', 'zinn-seo' ),
				) : array(
					'type'  => 'link',
					'label' => __( 'Edit robots.txt', 'zinn-seo' ),
					'url'   => admin_url( $discovery . 'robots' ),
				)
			),
			self::check(
				'usage_signals',
				'access',
				5,
				! empty( $f['signals'] ),
				__( 'How your content may be used is stated', 'zinn-seo' ),
				__( 'robots.txt carries no content signals, so AI companies are not told whether your pages may be used for search, as answer input or for training.', 'zinn-seo' ),
				! empty( $f['crawlers_on'] ) ? array(
					'type'  => 'auto',
					'label' => __( 'State the usual choice (search and answers yes, training no)', 'zinn-seo' ),
				) : self::module_fix()
			),
			self::check(
				'llms',
				'discovery',
				15,
				$llms_ok,
				__( 'llms.txt guides AI assistants to your key pages', 'zinn-seo' ),
				'' !== $llms_note ? $llms_note : __( 'There is no llms.txt, the short guide AI assistants read to learn what the site is and which pages matter.', 'zinn-seo' ),
				'' !== $llms_note ? array(
					'type'  => 'link',
					'label' => __( 'Open llms.txt', 'zinn-seo' ),
					'url'   => admin_url( $discovery . 'llms' ),
				) : array(
					'type'  => 'auto',
					'label' => __( 'Publish llms.txt', 'zinn-seo' ),
				)
			),
			self::check(
				'llms_full',
				'discovery',
				5,
				! empty( $f['llms'] ) && ! empty( $f['llms_full'] ),
				__( 'llms-full.txt carries the text of your key pages', 'zinn-seo' ),
				__( 'llms-full.txt is off, so an assistant has to fetch every page to read it.', 'zinn-seo' ),
				array(
					'type'  => 'auto',
					'label' => __( 'Publish llms-full.txt', 'zinn-seo' ),
				)
			),
			self::check(
				'llms_summary',
				'discovery',
				5,
				! empty( $f['llms_summary'] ),
				__( 'llms.txt opens with a summary written for the site', 'zinn-seo' ),
				__( 'llms.txt starts with the tagline only. Two or three sentences on who the site is for and what it offers are what an assistant quotes.', 'zinn-seo' ),
				array(
					'type'  => 'link',
					'label' => __( 'Write the summary', 'zinn-seo' ),
					'url'   => admin_url( $discovery . 'llms' ),
				)
			),
			self::check(
				'sitemaps',
				'discovery',
				10,
				! empty( $f['sitemaps'] ),
				__( 'XML sitemaps list every page that matters', 'zinn-seo' ),
				__( 'Zinn® SEO sitemaps are off, so crawlers find new and changed pages late.', 'zinn-seo' ),
				array(
					'type'  => 'auto',
					'label' => __( 'Build XML sitemaps', 'zinn-seo' ),
				)
			),
			self::check(
				'schema',
				'understanding',
				10,
				! empty( $f['schema'] ),
				__( 'Structured data describes each page', 'zinn-seo' ),
				__( 'Structured data is off, so agents have to guess what a page is (an article, a product, an event) and who is behind it.', 'zinn-seo' ),
				array(
					'type'  => 'auto',
					'label' => __( 'Add structured data', 'zinn-seo' ),
				)
			),
			self::check(
				'identity',
				'understanding',
				5,
				! empty( $f['identity'] ),
				__( 'The site says who is behind it', 'zinn-seo' ),
				__( 'No organisation or person is named, so answers cannot attribute your content to you.', 'zinn-seo' ),
				array(
					'type'  => 'link',
					'label' => __( 'Name the organisation or person', 'zinn-seo' ),
					'url'   => admin_url( 'admin.php?page=zinn-seo&view=schema' ),
				)
			),
			self::check(
				'mcp',
				'action',
				5,
				! empty( $f['mcp'] ),
				__( 'AI agents can work with the site, signed in as you', 'zinn-seo' ),
				__( 'The MCP abilities are off, so an AI assistant you connect cannot read or improve the site for you.', 'zinn-seo' ),
				array(
					'type'  => 'auto',
					'label' => __( 'Turn on AI agents (MCP)', 'zinn-seo' ),
				)
			),
		);

		$markdown = self::check(
			'markdown',
			'understanding',
			5,
			! empty( $f['markdown'] ),
			__( 'Each page has a Markdown copy for agents', 'zinn-seo' ),
			__( 'Agents read clean Markdown (page.md) far more cheaply than a themed HTML page.', 'zinn-seo' ),
			! empty( $f['markdown_code'] ) && ! empty( $f['pro'] ) ? array(
				'type'  => 'auto',
				'label' => __( 'Serve Markdown copies', 'zinn-seo' ),
			) : array(
				'type'  => 'pro',
				'label' => __( 'Markdown for agents is in Zinn® SEO Pro', 'zinn-seo' ),
				'url'   => admin_url( 'admin.php?page=zinn-seo&view=plans' ),
			)
		);
		if ( ! $markdown['passed'] && 'pro' === $markdown['fix']['type'] ) {
			$markdown['state'] = 'pro';
		}
		$checks[] = $markdown;

		return $checks;
	}

	/**
	 * One check.
	 *
	 * @param string               $id     Id.
	 * @param string               $group  access, discovery, understanding or action.
	 * @param int                  $weight Points.
	 * @param bool                 $passed Passed.
	 * @param string               $label  What a pass means.
	 * @param string               $detail Why it fails.
	 * @param array<string, mixed> $fix    The fix.
	 * @return array<string, mixed>
	 */
	private static function check( string $id, string $group, int $weight, bool $passed, string $label, string $detail, array $fix ): array {
		return array(
			'id'     => $id,
			'group'  => $group,
			'weight' => $weight,
			'passed' => $passed,
			'state'  => $passed ? 'pass' : 'fail',
			'label'  => $label,
			'detail' => $passed ? '' : $detail,
			'fix'    => $passed ? null : $fix,
		);
	}

	/**
	 * The fix when a module must be switched on first.
	 *
	 * @return array<string, string>
	 */
	private static function module_fix(): array {
		return array(
			'type'  => 'link',
			'label' => __( 'Turn on the AI crawlers module', 'zinn-seo' ),
			'url'   => admin_url( 'admin.php?page=zinn-seo&view=discovery#/ai' ),
		);
	}

	/**
	 * The score: points of the passed checks over the points of the checks that count (a Pro
	 * check on a free plan does not).
	 *
	 * @param array<int, array<string, mixed>> $checks Checks.
	 * @return int 0-100.
	 */
	public static function score( array $checks ): int {
		$total = 0;
		$got   = 0;
		foreach ( $checks as $c ) {
			if ( 'pro' === $c['state'] ) {
				continue;
			}
			$total += (int) $c['weight'];
			if ( $c['passed'] ) {
				$got += (int) $c['weight'];
			}
		}

		return $total > 0 ? (int) round( 100 * $got / $total ) : 0;
	}

	/**
	 * The grade for a score.
	 *
	 * @param int $score Score.
	 * @return string ready, nearly or not_ready.
	 */
	public static function grade( int $score ): string {
		if ( $score >= 90 ) {
			return 'ready';
		}

		return $score >= 60 ? 'nearly' : 'not_ready';
	}

	/**
	 * The changes a one-click fix makes: settings => values, or a module to switch on.
	 *
	 * @param string               $id    Check id.
	 * @param array<string, mixed> $facts Facts.
	 * @return array{settings?: array<string, mixed>, module?: string}|null Null: no one-click fix.
	 */
	public static function fix_plan( string $id, array $facts ): ?array {
		foreach ( self::checks( $facts ) as $c ) {
			if ( $c['id'] !== $id ) {
				continue;
			}
			if ( $c['passed'] || 'auto' !== ( $c['fix']['type'] ?? '' ) ) {
				return null;
			}
			switch ( $id ) {
				case 'answer_bots':
					$choices = (array) Settings::get( 'ai_crawlers', array() );
					if ( class_exists( '\ZinnDigital\ZinnSEO\Crawlers\Crawlers' ) ) {
						foreach ( \ZinnDigital\ZinnSEO\Crawlers\Crawlers::all() as $cid => $crawler ) {
							if ( in_array( $crawler['kind'], array( 'answer', 'user' ), true ) ) {
								$choices[ $cid ] = true;
							}
						}
					}
					return array( 'settings' => array( 'ai_crawlers' => $choices ) );
				case 'usage_signals':
					return array(
						'settings' => array(
							'ai_signals'    => true,
							'ai_sig_search' => 'yes',
							'ai_sig_input'  => 'yes',
							'ai_sig_train'  => 'no',
						),
					);
				case 'llms_full':
					return empty( $facts['llms'] ) ? array(
						'module'   => 'llms',
						'settings' => array( 'llms_full' => true ),
					) : array( 'settings' => array( 'llms_full' => true ) );
				case 'llms':
				case 'sitemaps':
				case 'schema':
				case 'mcp':
				case 'markdown':
					return array( 'module' => $id );
			}
		}

		return null;
	}

	/**
	 * Apply a one-click fix.
	 *
	 * @param string $id Check id.
	 * @return array<string, mixed>|\WP_Error The report after the fix.
	 */
	public static function fix( string $id ) {
		$plan = self::fix_plan( $id, self::facts() );
		if ( null === $plan ) {
			return new \WP_Error( 'zinn_seo_agent_ready_no_fix', __( 'That check has passed already or has no one-click fix; follow its link instead.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( isset( $plan['module'] ) ) {
			$on = Modules::set( (string) $plan['module'], true );
			if ( is_wp_error( $on ) ) {
				return $on;
			}
		}
		if ( ! empty( $plan['settings'] ) ) {
			$saved = Settings::save( (array) $plan['settings'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		Settings::flush();
		Modules::flush();

		return self::report();
	}

	/**
	 * The robots.txt the site serves, as WordPress builds it (or the physical file when one
	 * exists, which the web server sends instead).
	 *
	 * @return string
	 */
	public static function robots_text(): string {
		$file = ABSPATH . 'robots.txt';
		if ( is_readable( $file ) ) {
			return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		}
		$public = '0' !== (string) get_option( 'blog_public', '1' );
		$core   = "User-agent: *\n" . ( $public ? "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" : "Disallow: /\n" );

		return (string) apply_filters( 'robots_txt', $core, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter.
	}

	/**
	 * Does robots.txt close the whole site to this crawler? The groups naming it win over `*`
	 * (RFC 9309); the site is closed when such a group disallows `/` and does not allow `/`.
	 *
	 * @param string $text  robots.txt.
	 * @param string $token Crawler product token.
	 * @return bool
	 */
	public static function robots_blocks( string $text, string $token ): bool {
		$groups  = array();
		$current = null;
		$in_ua   = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $raw ) {
			$line = trim( (string) preg_replace( '/#.*/', '', (string) $raw ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$key                 = strtolower( $key );
			if ( 'user-agent' === $key ) {
				if ( ! $in_ua ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current  = count( $groups ) - 1;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
				$in_ua                          = true;
				continue;
			}
			$in_ua = false;
			if ( null !== $current && in_array( $key, array( 'allow', 'disallow' ), true ) ) {
				$groups[ $current ]['rules'][] = array( $key, $value );
			}
		}
		$name   = strtolower( $token );
		$named  = array_filter( $groups, static fn( array $g ): bool => in_array( $name, $g['agents'], true ) );
		$chosen = array() !== $named ? $named : array_filter( $groups, static fn( array $g ): bool => in_array( '*', $g['agents'], true ) );
		$closed = false;
		foreach ( $chosen as $g ) {
			foreach ( $g['rules'] as $rule ) {
				if ( '/' === $rule[1] ) {
					if ( 'allow' === $rule[0] ) {
						return false;
					}
					$closed = true;
				}
			}
		}

		return $closed;
	}

	/**
	 * The HTTP status a URL answers (0 when the site cannot reach itself, which is no verdict).
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function fetch_status( string $url ): int {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 3,
			)
		);

		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}
}
