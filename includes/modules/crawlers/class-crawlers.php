<?php
/**
 * AI crawlers: who they are, what each one is for, and the owner's choice for each (zs-064),
 * plus content-usage signals where a standard exists (zs-067).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Crawlers;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three kinds of AI crawler, and the difference matters to the owner:
 * - `train`: collects pages to TRAIN models. Blocking it keeps the site out of future training
 *   sets and costs nothing in search or AI answers.
 * - `answer`: fetches pages to ANSWER questions in an AI search product and link to the source.
 *   Blocking it removes the site from those answers.
 * - `user`: fetches one page because a person asked an assistant to read it.
 *
 * The choice is written to robots.txt (one group of blocked crawlers). Crawlers that respect
 * robots.txt obey it; the list says which ones publish that they do. Default: every crawler
 * allowed, the best choice for AI visibility (the owner's GEO rule, CLAUDE.md §2.18).
 */
final class Crawlers {

	/**
	 * The crawlers: id => [token, company, kind, documentation].
	 *
	 * Tokens are the names each company documents for robots.txt (the list as of October
	 * 2026; `zinn_seo_ai_crawlers` adds others without a plugin update).
	 *
	 * @return array<string, array{token: string, company: string, kind: string, docs: string}>
	 */
	public static function all(): array {
		$list = array(
			'gptbot'               => array( 'GPTBot', 'OpenAI', 'train', 'https://platform.openai.com/docs/bots' ),
			'oai-searchbot'        => array( 'OAI-SearchBot', 'OpenAI', 'answer', 'https://platform.openai.com/docs/bots' ),
			'chatgpt-user'         => array( 'ChatGPT-User', 'OpenAI', 'user', 'https://platform.openai.com/docs/bots' ),
			'claudebot'            => array( 'ClaudeBot', 'Anthropic', 'train', 'https://support.anthropic.com/en/articles/8896518' ),
			'claude-searchbot'     => array( 'Claude-SearchBot', 'Anthropic', 'answer', 'https://support.anthropic.com/en/articles/8896518' ),
			'claude-user'          => array( 'Claude-User', 'Anthropic', 'user', 'https://support.anthropic.com/en/articles/8896518' ),
			'google-extended'      => array( 'Google-Extended', 'Google', 'train', 'https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers' ),
			'applebot-extended'    => array( 'Applebot-Extended', 'Apple', 'train', 'https://support.apple.com/en-us/119829' ),
			'perplexitybot'        => array( 'PerplexityBot', 'Perplexity', 'answer', 'https://docs.perplexity.ai/guides/bots' ),
			'perplexity-user'      => array( 'Perplexity-User', 'Perplexity', 'user', 'https://docs.perplexity.ai/guides/bots' ),
			'meta-externalagent'   => array( 'meta-externalagent', 'Meta', 'train', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers' ),
			'meta-externalfetcher' => array( 'meta-externalfetcher', 'Meta', 'user', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers' ),
			'amazonbot'            => array( 'Amazonbot', 'Amazon', 'answer', 'https://developer.amazon.com/amazonbot' ),
			'bytespider'           => array( 'Bytespider', 'ByteDance', 'train', '' ),
			'ccbot'                => array( 'CCBot', 'Common Crawl', 'train', 'https://commoncrawl.org/ccbot' ),
			'cohere-ai'            => array( 'cohere-ai', 'Cohere', 'train', '' ),
			'cohere-training'      => array( 'cohere-training-data-crawler', 'Cohere', 'train', '' ),
			'mistralai-user'       => array( 'MistralAI-User', 'Mistral AI', 'user', 'https://docs.mistral.ai/robots' ),
			'duckassistbot'        => array( 'DuckAssistBot', 'DuckDuckGo', 'answer', 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot' ),
			'youbot'               => array( 'YouBot', 'You.com', 'answer', '' ),
			'diffbot'              => array( 'Diffbot', 'Diffbot', 'train', '' ),
			'ai2bot'               => array( 'AI2Bot', 'Allen Institute for AI', 'train', '' ),
			'timpibot'             => array( 'Timpibot', 'Timpi', 'train', '' ),
			'omgilibot'            => array( 'omgilibot', 'Webz.io', 'train', '' ),
		);
		$out  = array();
		foreach ( $list as $id => $row ) {
			$out[ $id ] = array(
				'token'   => $row[0],
				'company' => $row[1],
				'kind'    => $row[2],
				'docs'    => $row[3],
			);
		}

		/**
		 * Filters the AI crawlers the policy screen lists (id => token, company, kind, docs).
		 *
		 * @param array<string, array<string, string>> $out Crawlers.
		 */
		return (array) apply_filters( 'zinn_seo_ai_crawlers', $out );
	}

	/**
	 * Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return require __DIR__ . '/settings.php';
	}

	/**
	 * The presets the screen offers: id => crawler ids it blocks.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function presets(): array {
		$all   = self::all();
		$train = array_keys( array_filter( $all, static fn( array $c ): bool => 'train' === $c['kind'] ) );

		return array(
			'allow_all'   => array(),
			'block_train' => $train,
			'block_all'   => array_keys( $all ),
		);
	}

	/**
	 * Crawler ids the site blocks.
	 *
	 * @param array<string, bool>|null $choices Map (default: the setting).
	 * @return array<int, string>
	 */
	public static function blocked( ?array $choices = null ): array {
		$choices = $choices ?? (array) Settings::get( 'ai_crawlers', array() );
		$out     = array();
		foreach ( array_keys( self::all() ) as $id ) {
			if ( array_key_exists( $id, $choices ) && false === $choices[ $id ] ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The robots.txt group that blocks the chosen crawlers (empty when none is).
	 *
	 * @param array<int, array<int, string>> $groups Groups so far.
	 * @return array<int, array<int, string>>
	 */
	public static function robots_groups( $groups ): array {
		$groups  = (array) $groups;
		$blocked = self::blocked();
		if ( array() === $blocked ) {
			return $groups;
		}
		$all   = self::all();
		$lines = array();
		foreach ( $blocked as $id ) {
			$lines[] = 'User-agent: ' . $all[ $id ]['token'];
		}
		$lines[]  = 'Disallow: /';
		$groups[] = $lines;

		return $groups;
	}

	/**
	 * The content-signal line in the `*` group (zs-067), when switched on: the Content Signals
	 * Policy (contentsignals.org) — `search`, `ai-input` (use in AI answers), `ai-train`.
	 *
	 * @param array<int, string> $lines Lines so far.
	 * @return array<int, string>
	 */
	public static function star_lines( $lines ): array {
		$lines = (array) $lines;
		if ( ! (bool) Settings::get( 'ai_signals', false ) ) {
			return $lines;
		}
		$lines[] = self::signal_line(
			(string) Settings::get( 'ai_sig_search', 'yes' ),
			(string) Settings::get( 'ai_sig_input', 'yes' ),
			(string) Settings::get( 'ai_sig_train', 'no' )
		);

		return $lines;
	}

	/**
	 * A `Content-Signal` line. Pure, for the tests.
	 *
	 * @param string $search yes|no.
	 * @param string $input  yes|no.
	 * @param string $train  yes|no.
	 * @return string
	 */
	public static function signal_line( string $search, string $input, string $train ): string {
		$v = static fn( string $x ): string => 'no' === $x ? 'no' : 'yes';

		return 'Content-Signal: search=' . $v( $search ) . ', ai-input=' . $v( $input ) . ', ai-train=' . $v( $train );
	}

	/**
	 * The W3C TDM Reservation Protocol file (`/.well-known/tdmrep.json`): text-and-data-mining
	 * rights reserved for the whole site, with the owner's licensing policy when given. The
	 * protocol is how EU law's opt-out (Directive 2019/790, Article 4) is expressed by machine.
	 *
	 * @return string JSON.
	 */
	public static function tdmrep(): string {
		$entry  = array(
			'location'        => '/',
			'tdm-reservation' => 1,
		);
		$policy = (string) Settings::get( 'tdm_policy', '' );
		if ( '' !== $policy ) {
			$entry['tdm-policy'] = $policy;
		}

		return (string) wp_json_encode( array( $entry ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Which AI crawler sent this user agent string, or ''. Pure, for the tests and the hit log.
	 *
	 * @param string $user_agent User-Agent header.
	 * @return string Crawler id.
	 */
	public static function identify( string $user_agent ): string {
		static $regex = null;
		static $ids   = array();
		if ( '' === $user_agent ) {
			return '';
		}
		if ( null === $regex ) {
			$parts = array();
			foreach ( self::all() as $id => $c ) {
				$parts[]                          = preg_quote( strtolower( $c['token'] ), '/' );
				$ids[ strtolower( $c['token'] ) ] = $id;
			}
			// Longest first, so `Claude-SearchBot` is not read as a shorter token.
			usort( $parts, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			$regex = '/(' . implode( '|', $parts ) . ')/';
		}
		if ( 1 !== preg_match( $regex, strtolower( $user_agent ), $m ) ) {
			return '';
		}

		return (string) ( $ids[ $m[1] ] ?? '' );
	}

	/**
	 * Serve `/.well-known/tdmrep.json` when switched on (front end, a string comparison otherwise).
	 *
	 * @return void
	 */
	public static function maybe_serve_tdmrep(): void {
		if ( ! (bool) Settings::get( 'tdm_reservation', false ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		if ( '/.well-known/tdmrep.json' !== $path ) {
			return;
		}
		$body = self::tdmrep();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built by wp_json_encode().
		exit;
	}
}
