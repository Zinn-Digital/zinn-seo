<?php
/**
 * Spelling, grammar and tone of voice (zs-721).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AnalysisExtra;

use ZinnDigital\ZinnSEO\AdminKit\Crypto;
use ZinnDigital\ZinnSEO\Ai\Ai;
use ZinnDigital\ZinnSEO\Ai\Tasks;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two ways to check a text, chosen in Content → Spelling and grammar:
 *
 * 1. A LANGUAGETOOL server — the site's own, a hosted LanguageTool account, or any server that
 *    speaks LanguageTool's `/v2/check` API. The text goes there and nowhere else. Long texts are
 *    sent in pieces that end at a paragraph, each well under the public server's 20 KB limit,
 *    and every mistake's position is put back in the whole text.
 * 2. AI — on the owner's own key (free), or on Zinn® credits (Pro). The model names each mistake
 *    by quoting it; the position is found here, and a quote not found in the text is dropped
 *    (a model that misquotes never points at the wrong words).
 *
 * The tone check (the voice chosen under the same screen) always runs on AI.
 *
 * Nothing is changed by a check: the editor shows each mistake and its fix, and the person
 * applies it to the unsaved post (zs-200).
 */
final class Proofread {

	/** Longest text checked at once. */
	public const MAX_TEXT = 60000;

	/** Largest piece sent to a LanguageTool server in one request (bytes; its public limit is 20 KB). */
	public const PIECE = 18000;

	/** Most mistakes reported for one text. */
	public const MAX_ISSUES = 300;

	/** The option holding the LanguageTool API key, sealed (never sent to a browser). */
	public const KEY_OPTION = 'zseo_proof_lt_key';

	/**
	 * The LanguageTool key, opened ('' when none, or when it cannot be opened).
	 *
	 * @return string
	 */
	public static function key(): string {
		$sealed = (string) get_option( self::KEY_OPTION, '' );

		return '' === $sealed ? '' : (string) Crypto::open( $sealed );
	}

	/**
	 * Is the request made with the right edition and funding? Free: a LanguageTool server or AI on
	 * the owner's own key. Pro: Zinn® credits too.
	 *
	 * @param string $task AI task.
	 * @return true|\WP_Error
	 */
	public static function may_use_ai( string $task ) {
		$def = Tasks::get( $task );
		if ( null === $def || ! Ai::ready() ) {
			return new \WP_Error( 'zinn_seo_proof_ai', __( 'Set up AI first: add your own AI key, or connect Zinn® credits (Pro).', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		if ( Ai::on_credits( $def ) && ! Plugin::is_pro() ) {
			return new \WP_Error( 'zinn_seo_proof_pro', __( 'Checking with Zinn® credits is part of Zinn® SEO Pro. Add your own AI key or a LanguageTool server to check for free.', 'zinn-seo' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * What the screens need.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		return array(
			'engine'  => (string) Settings::get( 'proof_engine', 'languagetool' ),
			'server'  => '' !== (string) Settings::get( 'proof_lt_url', '' ),
			'hasKey'  => '' !== self::key(),
			'tone'    => (string) Settings::get( 'proof_tone', '' ),
			'aiReady' => class_exists( Ai::class ) && Ai::ready(),
		);
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Plugin::REST_NS,
			'/proofread',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_check' ),
				'permission_callback' => static fn( \WP_REST_Request $r ): bool => (int) $r['post'] > 0 ? Capabilities::can_edit_post( (int) $r['post'] ) : ( current_user_can( Capabilities::EDIT ) || Capabilities::can_manage() ),
				'args'                => array(
					'post'     => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'text'     => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => self::MAX_TEXT,
					),
					'language' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[A-Za-z_-]{0,12}$',
					),
					'tone'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/proofread/key',
			array(
				'methods'             => 'PUT',
				'callback'            => static function ( \WP_REST_Request $r ) {
					$key = trim( sanitize_text_field( (string) $r['key'] ) );
					if ( '' === $key ) {
						delete_option( self::KEY_OPTION );
					} else {
						// Sealed at rest with the site's own salts: a copy of the database alone does not reveal it.
						update_option( self::KEY_OPTION, Crypto::seal( $key ), false );
					}
					return rest_ensure_response( self::state() );
				},
				'permission_callback' => array( Capabilities::class, 'can_manage' ),
				'args'                => array(
					'key' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 200,
					),
				),
			)
		);
	}

	/**
	 * POST /proofread.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_check( \WP_REST_Request $r ) {
		$text = (string) $r['text'];
		$lang = (string) $r['language'];
		$post = (int) $r['post'];
		if ( '' === trim( $text ) && $post > 0 ) {
			$p    = get_post( $post );
			$text = $p instanceof \WP_Post ? self::plain( (string) apply_filters( 'zinn_seo_rendered_content', (string) $p->post_content, $p ) ) : '';
		}
		if ( '' === $lang && $post > 0 ) {
			$lang = Tasks::language_of( $post );
		}
		$out = self::check( $text, $lang, (bool) $r['tone'] );

		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * HTML as plain text, paragraphs kept apart by a blank line.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( string $html ): string {
		$html = (string) preg_replace( '#<(?:/p|/h[1-6]|/li|br\s*/?|/div|/blockquote|/td)>#i', "$0\n\n", strip_shortcodes( $html ) );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+/u", ' ', $text );

		return trim( (string) preg_replace( "/\n\s*\n+/u", "\n\n", $text ) );
	}

	/**
	 * Check a text.
	 *
	 * @param string $text Text.
	 * @param string $lang Language (a locale or tag; '' = detect).
	 * @param bool   $tone Also check the tone, when the site chose one.
	 * @return array<string, mixed>|\WP_Error `{engine, language, issues: [{offset, length, found, suggestions, message, kind}], tone}`.
	 */
	public static function check( string $text, string $lang = '', bool $tone = true ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return new \WP_Error( 'zinn_seo_proof_empty', __( 'There is no text to check yet.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) > self::MAX_TEXT ) {
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
		}
		$engine = (string) Settings::get( 'proof_engine', 'languagetool' );
		if ( 'ai' === $engine ) {
			$found = self::ai_issues( $text, $lang );
		} else {
			$found = self::languagetool( $text, $lang );
		}
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$out  = array(
			'engine'   => $engine,
			'language' => (string) $found['language'],
			'issues'   => array_slice( $found['issues'], 0, self::MAX_ISSUES ),
			'tone'     => null,
		);
		$want = (string) Settings::get( 'proof_tone', '' );
		if ( $tone && '' !== $want && true === self::may_use_ai( 'tone' ) ) {
			$read        = self::tone( $text, $want, $lang );
			$out['tone'] = is_wp_error( $read ) ? array( 'error' => $read->get_error_message() ) : $read;
		}

		return $out;
	}

	/**
	 * The server's check address.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		$base = untrailingslashit( (string) Settings::get( 'proof_lt_url', '' ) );
		if ( '' === $base ) {
			return '';
		}
		$base = (string) preg_replace( '#/v2(?:/check)?$#', '', $base );

		return $base . '/v2/check';
	}

	/**
	 * Split a text into pieces that end at a paragraph (or a sentence), each at most `PIECE` bytes.
	 *
	 * @param string $text Text.
	 * @param int    $max  Largest piece in bytes.
	 * @return array<int, array{0: int, 1: string}> [character offset, piece].
	 */
	public static function pieces( string $text, int $max = self::PIECE ): array {
		$out   = array();
		$start = 0;
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		while ( $start < $len ) {
			$rest = function_exists( 'mb_substr' ) ? mb_substr( $text, $start ) : substr( $text, $start );
			if ( strlen( $rest ) <= $max ) {
				$out[] = array( $start, $rest );
				break;
			}
			$head = mb_strcut( $rest, 0, $max, 'UTF-8' );
			$cut  = max( (int) mb_strrpos( $head, "\n\n" ), 0 );
			if ( $cut < mb_strlen( $head ) / 2 ) {
				$cut = max( (int) mb_strrpos( $head, '. ' ) + 1, 0 );
			}
			if ( $cut < 1 ) {
				$cut = mb_strlen( $head );
			}
			$out[]  = array( $start, mb_substr( $rest, 0, $cut ) );
			$start += $cut;
		}

		return $out;
	}

	/**
	 * Ask a LanguageTool-compatible server.
	 *
	 * @param string $text Text.
	 * @param string $lang Language.
	 * @return array{language: string, issues: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function languagetool( string $text, string $lang ) {
		$url = self::endpoint();
		if ( '' === $url ) {
			return new \WP_Error( 'zinn_seo_proof_server', __( 'Add the address of a LanguageTool server under Content, Spelling and grammar, or choose AI to check.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$code   = '' === $lang ? 'auto' : str_replace( '_', '-', $lang );
		$issues = array();
		$found  = $code;
		foreach ( self::pieces( $text ) as $piece ) {
			$body = array(
				'text'     => $piece[1],
				'language' => $code,
			);
			$user = (string) Settings::get( 'proof_lt_user', '' );
			$key  = self::key();
			if ( '' !== $user && '' !== $key ) {
				$body['username'] = $user;
				$body['apiKey']   = $key;
			}
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 30,
					'body'    => $body,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			// A language the server does not know: ask it to detect the language instead.
			if ( 400 === $status && 'auto' !== $code ) {
				$code = 'auto';
				return self::languagetool( $text, '' );
			}
			if ( 200 !== $status ) {
				return new \WP_Error(
					'zinn_seo_proof_server_down',
					429 === $status
						? __( 'The LanguageTool server is busy (too many checks in a minute). Try again in a minute.', 'zinn-seo' )
						/* translators: %s: an HTTP status or error. */
						: sprintf( __( 'The LanguageTool server did not answer (%s). Check its address under Content, Spelling and grammar.', 'zinn-seo' ), is_wp_error( $response ) ? $response->get_error_message() : (string) $status ),
					array( 'status' => 502 )
				);
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || ! isset( $data['matches'] ) || ! is_array( $data['matches'] ) ) {
				return new \WP_Error( 'zinn_seo_proof_server_odd', __( 'The server answered, but not in LanguageTool\'s format. Check its address.', 'zinn-seo' ), array( 'status' => 502 ) );
			}
			$found = (string) ( $data['language']['detectedLanguage']['code'] ?? $data['language']['code'] ?? $found );
			foreach ( $data['matches'] as $m ) {
				if ( ! is_array( $m ) || ! isset( $m['offset'], $m['length'] ) ) {
					continue;
				}
				$offset   = $piece[0] + (int) $m['offset'];
				$length   = (int) $m['length'];
				$issues[] = array(
					'offset'      => $offset,
					'length'      => $length,
					'found'       => mb_substr( $text, $offset, $length ),
					'suggestions' => array_slice( array_values( array_filter( array_map( static fn( $r ): string => is_array( $r ) ? (string) ( $r['value'] ?? '' ) : '', (array) ( $m['replacements'] ?? array() ) ), 'strlen' ) ), 0, 5 ),
					'message'     => sanitize_text_field( (string) ( $m['message'] ?? '' ) ),
					'kind'        => self::kind( (string) ( $m['rule']['issueType'] ?? '' ), (string) ( $m['rule']['category']['id'] ?? '' ) ),
					'context'     => self::context( $text, $offset, $length ),
				);
			}
		}

		return array(
			'language' => $found,
			'issues'   => $issues,
		);
	}

	/**
	 * LanguageTool's issue type as ours: spelling, grammar, punctuation or style.
	 *
	 * @param string $type     `issueType`.
	 * @param string $category Category id.
	 * @return string
	 */
	public static function kind( string $type, string $category ): string {
		if ( 'misspelling' === $type || 'TYPOS' === $category ) {
			return 'spelling';
		}
		if ( 'PUNCTUATION' === $category || 'TYPOGRAPHY' === $category ) {
			return 'punctuation';
		}
		if ( in_array( $type, array( 'style', 'locale-violation', 'register' ), true ) || in_array( $category, array( 'STYLE', 'REDUNDANCY', 'PLAIN_ENGLISH', 'CASING' ), true ) ) {
			return 'style';
		}

		return 'grammar';
	}

	/**
	 * The words around a mistake, for the list.
	 *
	 * @param string $text   Text.
	 * @param int    $offset Start.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function context( string $text, int $offset, int $length ): string {
		$from = max( 0, $offset - 40 );

		return trim( mb_substr( $text, $from, $length + 80 ) );
	}

	/**
	 * Ask AI for the mistakes.
	 *
	 * @param string $text Text.
	 * @param string $lang Language.
	 * @return array{language: string, issues: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function ai_issues( string $text, string $lang ) {
		$may = self::may_use_ai( 'proofread' );
		if ( is_wp_error( $may ) ) {
			return $may;
		}
		$pieces = self::pieces( $text, 6000 );
		$ids    = array_keys( $pieces );
		$run    = Ai::run(
			'proofread',
			array_map( static fn( int $i ): int => $i + 1, $ids ),
			array(
				'pieces'   => array_column( $pieces, 1 ),
				'language' => $lang,
			)
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$issues = array();
		foreach ( (array) $run['items'] as $row ) {
			if ( empty( $row['ok'] ) ) {
				$error = Ai::as_error( $run );
				if ( null !== $error ) {
					return $error;
				}
				continue;
			}
			$index = (int) $row['id'] - 1;
			$base  = (int) ( $pieces[ $index ][0] ?? 0 );
			$piece = (string) ( $pieces[ $index ][1] ?? '' );
			$from  = 0;
			foreach ( (array) ( $row['fields']['issues'] ?? array() ) as $issue ) {
				$quote = (string) ( $issue['found'] ?? '' );
				$at    = '' === $quote ? false : mb_strpos( $piece, $quote, $from );
				if ( false === $at ) {
					$at = '' === $quote ? false : mb_strpos( $piece, $quote );
				}
				if ( false === $at ) {
					continue; // A quote that is not in the text points at nothing.
				}
				$from     = $at + 1;
				$issues[] = array(
					'offset'      => $base + $at,
					'length'      => mb_strlen( $quote ),
					'found'       => $quote,
					'suggestions' => '' === (string) ( $issue['fix'] ?? '' ) ? array() : array( (string) $issue['fix'] ),
					'message'     => (string) ( $issue['why'] ?? '' ),
					'kind'        => in_array( $issue['kind'] ?? '', array( 'spelling', 'grammar', 'punctuation', 'style' ), true ) ? (string) $issue['kind'] : 'grammar',
					'context'     => self::context( $text, $base + $at, mb_strlen( $quote ) ),
				);
			}
		}
		usort( $issues, static fn( array $a, array $b ): int => $a['offset'] <=> $b['offset'] );

		return array(
			'language' => '' === $lang ? 'auto' : $lang,
			'issues'   => $issues,
		);
	}

	/**
	 * How the text sounds against the voice the site chose.
	 *
	 * @param string $text Text.
	 * @param string $want The voice.
	 * @param string $lang Language.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function tone( string $text, string $want, string $lang ) {
		$run = Ai::run(
			'tone',
			array( 1 ),
			array(
				'text'     => $text,
				'want'     => $want,
				'language' => $lang,
			)
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$row = (array) ( $run['items'][0] ?? array() );
		if ( empty( $row['ok'] ) ) {
			$error = Ai::as_error( $run );
			return null !== $error ? $error : new \WP_Error( 'zinn_seo_proof_tone', __( 'The tone could not be read. Try again.', 'zinn-seo' ) );
		}

		return array( 'want' => $want ) + (array) $row['fields'];
	}

	/**
	 * The two AI tasks (registered with the AI module's own).
	 *
	 * @return void
	 */
	public static function register_tasks(): void {
		if ( ! class_exists( Tasks::class ) ) {
			return;
		}
		Tasks::register(
			'proofread',
			array(
				'feature'     => 'proofread',
				'label'       => static fn() => __( 'Spelling and grammar check', 'zinn-seo' ),
				'tier'        => 'free',
				'subject'     => 'none',
				'store'       => false,
				'per_item'    => 1500,
				'fields'      => array(
					'issues' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'found' => array( 'type' => 'string' ),
								'fix'   => array( 'type' => 'string' ),
								'why'   => array( 'type' => 'string' ),
								'kind'  => array(
									'type' => 'string',
									'enum' => array( 'spelling', 'grammar', 'punctuation', 'style' ),
								),
							),
							'required'             => array( 'found', 'fix', 'why', 'kind' ),
							'additionalProperties' => false,
						),
					),
				),
				'input'       => static function ( int $id, array $opts ) {
					$piece = (string) ( ( (array) ( $opts['pieces'] ?? array() ) )[ $id - 1 ] ?? '' );
					if ( '' === trim( $piece ) ) {
						return new \WP_Error( 'zinn_seo_proof_empty', __( 'There is no text to check yet.', 'zinn-seo' ) );
					}
					return array(
						'language' => (string) ( $opts['language'] ?? '' ),
						'text'     => $piece,
					);
				},
				'instruction' => static fn() => 'You proofread a passage of a web page. List every spelling, grammar and punctuation mistake, and wordy or unclear phrasing as "style". For each: "found" — the mistaken words copied EXACTLY from the text (a few words, enough to be unique); "fix" — the corrected words; "why" — one short sentence in the language of the text; "kind". Do not report names, brand names, code or deliberate informal style. An empty list when the text is correct.',
				'clean'       => static fn( array $a ): array => array(
					'issues' => array_slice(
						array_values(
							array_filter(
								array_map(
									static fn( $i ): array => is_array( $i ) ? array(
										'found' => Tasks::cut( (string) ( $i['found'] ?? '' ), 300 ),
										'fix'   => Tasks::cut( sanitize_text_field( (string) ( $i['fix'] ?? '' ) ), 300 ),
										'why'   => Tasks::line( $i['why'] ?? '', 300 ),
										'kind'  => (string) ( $i['kind'] ?? 'grammar' ),
									) : array(),
									(array) ( $a['issues'] ?? array() )
								),
								static fn( array $i ): bool => '' !== ( $i['found'] ?? '' )
							)
						),
						0,
						100
					),
				),
			)
		);
		Tasks::register(
			'tone',
			array(
				'feature'     => 'proofread',
				'label'       => static fn() => __( 'Tone of voice check', 'zinn-seo' ),
				'tier'        => 'free',
				'subject'     => 'none',
				'store'       => false,
				'per_item'    => 1200,
				'fields'      => array(
					'heard'     => array( 'type' => 'string' ),
					'match'     => array( 'type' => 'integer' ),
					'summary'   => array( 'type' => 'string' ),
					'sentences' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'found' => array( 'type' => 'string' ),
								'fix'   => array( 'type' => 'string' ),
							),
							'required'             => array( 'found', 'fix' ),
							'additionalProperties' => false,
						),
					),
				),
				'input'       => static fn( int $id, array $opts ): array => array(
					'language' => (string) ( $opts['language'] ?? '' ),
					'wanted'   => (string) ( $opts['want'] ?? '' ),
					'text'     => Tasks::cut( (string) ( $opts['text'] ?? '' ), 8000 ),
				),
				'instruction' => static fn() => 'You judge the tone of voice of a web page against the tone the site wants ("wanted"). "heard": two or three words for how it sounds now. "match": 0 to 100, how close it is to the wanted tone. "summary": one or two sentences, in the language of the text, on what to change. "sentences": up to five sentences that most miss the tone — "found" copied EXACTLY from the text, "fix" rewritten in the wanted tone, same meaning and language.',
				'clean'       => static fn( array $a ): array => array(
					'heard'     => Tasks::line( $a['heard'] ?? '', 80 ),
					'match'     => max( 0, min( 100, (int) ( $a['match'] ?? 0 ) ) ),
					'summary'   => Tasks::line( $a['summary'] ?? '', 400 ),
					'sentences' => array_slice(
						array_values(
							array_filter(
								array_map(
									static fn( $s ): array => is_array( $s ) ? array(
										'found' => Tasks::cut( (string) ( $s['found'] ?? '' ), 500 ),
										'fix'   => Tasks::cut( sanitize_text_field( (string) ( $s['fix'] ?? '' ) ), 500 ),
									) : array(),
									(array) ( $a['sentences'] ?? array() )
								),
								static fn( array $s ): bool => '' !== ( $s['found'] ?? '' )
							)
						),
						0,
						5
					),
				),
			)
		);
	}
}
