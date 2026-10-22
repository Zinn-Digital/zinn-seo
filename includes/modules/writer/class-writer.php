<?php
/**
 * The AI writer (zs-700).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Writer;

use ZinnDigital\ZinnSEO\Ai\Ai;
use ZinnDigital\ZinnSEO\Ai\Tasks;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three AI tasks, each run by the one AI runner (own key or Zinn® credits, batched, every failure
 * named by its class):
 *
 * - `ideas` — post ideas that fit THIS site: it reads the site's name and tagline, its main
 *   categories, its latest titles (so nothing it already has is suggested again) and, when a
 *   keyword list is chosen, the phrases the site wants to rank for;
 * - `draft` — a starter draft or a full article from a brief (a topic, an optional outline from a
 *   content brief, the phrases to use, a length and a voice): a title, an SEO title and
 *   description, headings and paragraphs. ONE request per article, so a planner's many drafts go
 *   as one batched call (`Ai::run` packs up to the API's batch size);
 * - `passage` — a passage of the post being edited, rewritten, expanded, shortened, simplified or
 *   continued; the editor puts it into the unsaved post and the person saves it (a revision).
 *
 * ⛔ zs-200: a draft from `draft` becomes a post ONLY when the person presses "Create draft"
 * (`POST /writer/drafts`), and then as a DRAFT — never published, never written over an existing
 * post. The answer is shown first.
 */
final class Writer {

	/** Lengths a draft may ask for, in words. */
	public const LENGTHS = array( 300, 600, 1000, 1500, 2000 );

	/** Voices a draft may be written in. */
	public const VOICES = array( 'friendly', 'professional', 'expert', 'casual', 'persuasive', 'neutral' );

	/** What can be done to a passage. */
	public const MODES = array( 'rewrite', 'expand', 'shorten', 'simplify', 'continue' );

	/**
	 * May the current person use the writer?
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( 'edit_posts' ) && ( current_user_can( Capabilities::AI ) || Capabilities::can_manage() );
	}

	/**
	 * Routes: `POST /writer/drafts` (make the reviewed draft a post), `GET /writer/site` (what the
	 * idea task reads, shown on the screen so the person sees what the AI is told).
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Plugin::REST_NS,
			'/writer/drafts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$out = self::create_draft( (array) $r['draft'], (array) $r['options'] );
					return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
				},
				'permission_callback' => array( self::class, 'can' ),
				'args'                => array(
					'draft'   => array(
						'type'     => 'object',
						'required' => true,
					),
					'options' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/writer/site',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( self::site() ),
				'permission_callback' => array( self::class, 'can' ),
			)
		);
	}

	/**
	 * What the AI is told about the site.
	 *
	 * @param int $titles How many recent titles.
	 * @return array{name: string, tagline: string, categories: array<int, string>, titles: array<int, string>}
	 */
	public static function site( int $titles = 30 ): array {
		$cats   = get_terms(
			array(
				'taxonomy'   => 'category',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
				'hide_empty' => true,
				'fields'     => 'names',
			)
		);
		$recent = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'draft', 'future' ),
				'numberposts'      => $titles,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return array(
			'name'       => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'tagline'    => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
			'categories' => is_array( $cats ) ? array_values( array_filter( array_map( 'strval', $cats ), static fn( string $c ): bool => 'uncategorized' !== strtolower( $c ) ) ) : array(),
			'titles'     => array_values( array_filter( array_map( static fn( $id ): string => wp_strip_all_tags( get_the_title( (int) $id ) ), (array) $recent ) ) ),
		);
	}

	/**
	 * The phrases of a keyword list (zs-731), when that module is on.
	 *
	 * @param int $list_id List.
	 * @return array<int, string>
	 */
	public static function phrases( int $list_id ): array {
		$class = '\ZinnDigital\ZinnSEO\Keywords\Keyword_Lists';
		if ( $list_id <= 0 || ! class_exists( $class ) ) {
			return array();
		}
		$row = $class::get( $list_id );

		return null === $row ? array() : array_slice( (array) $row['phrases'], 0, 40 );
	}

	/**
	 * Make a reviewed draft a post: always a DRAFT, never published, never over another post.
	 *
	 * @param array<string, mixed> $draft The `draft` task's answer (as shown, perhaps edited).
	 * @param array<string, mixed> $opts  `post_type`, `kwlist`, `phrase` (focus).
	 * @return array{id: int, edit: string}|\WP_Error
	 */
	public static function create_draft( array $draft, array $opts = array() ) {
		$type = sanitize_key( (string) ( $opts['post_type'] ?? 'post' ) );
		$obj  = get_post_type_object( $type );
		if ( null === $obj || ! $obj->public || ! current_user_can( $obj->cap->edit_posts ) ) {
			return new \WP_Error( 'zinn_seo_writer_type', __( 'You cannot write that kind of content.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		$title = Tasks::line( $draft['title'] ?? '', 200 );
		if ( '' === $title ) {
			return new \WP_Error( 'zinn_seo_writer_empty', __( 'The draft has no title.', 'zinn-seo' ), array( 'status' => 422 ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_excerpt' => Tasks::line( $draft['excerpt'] ?? '', 300 ),
				'post_content' => self::blocks( $draft ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		foreach ( array(
			'title' => Tasks::line( $draft['seo_title'] ?? '', 70 ),
			'desc'  => Tasks::line( $draft['seo_desc'] ?? '', 160 ),
		) as $field => $value ) {
			if ( '' !== $value ) {
				Meta::set( 'post', $id, $field, $value );
			}
		}
		$phrase = Tasks::line( $opts['phrase'] ?? '', 120 );
		if ( '' !== $phrase && isset( Meta::fields()['focus'] ) ) {
			Meta::set( 'post', $id, 'focus', $phrase );
		}
		$list  = (int) ( $opts['kwlist'] ?? 0 );
		$class = '\ZinnDigital\ZinnSEO\Keywords\Keyword_Lists';
		if ( $list > 0 && class_exists( $class ) ) {
			$class::assign( $list, array( $id ) );
		}
		/**
		 * Fires when the writer has made a draft (the Pro planner marks its idea done).
		 *
		 * @param int                  $id    The new draft.
		 * @param array<string, mixed> $opts  Options it was made with.
		 */
		do_action( 'zinn_seo_writer_drafted', $id, $opts );

		return array(
			'id'   => $id,
			'edit' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * A draft's text as blocks: an introduction, then a heading and paragraphs per section.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return string
	 */
	public static function blocks( array $draft ): string {
		$out = '';
		$p   = static fn( string $text ): string => "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		foreach ( Tasks::lines( $draft['intro'] ?? array(), 2000, 6 ) as $para ) {
			$out .= $p( $para );
		}
		foreach ( array_slice( (array) ( $draft['sections'] ?? array() ), 0, 20 ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$heading = Tasks::line( $section['heading'] ?? '', 200 );
			if ( '' !== $heading ) {
				$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $heading ) . "</h2>\n<!-- /wp:heading -->\n\n";
			}
			foreach ( Tasks::lines( $section['paragraphs'] ?? array(), 2000, 12 ) as $para ) {
				$out .= $p( $para );
			}
		}

		return trim( $out );
	}

	/**
	 * The writer's AI tasks.
	 *
	 * @return void
	 */
	public static function register_tasks(): void {
		if ( ! class_exists( Tasks::class ) ) {
			return;
		}
		Tasks::register( 'ideas', self::ideas_task() + array( 'feature' => 'writer' ) );
		Tasks::register( 'draft', self::draft_task() + array( 'feature' => 'writer' ) );
		Tasks::register( 'passage', self::passage_task() + array( 'feature' => 'writer' ) );
	}

	/**
	 * Post ideas.
	 *
	 * @return array<string, mixed>
	 */
	private static function ideas_task(): array {
		return array(
			'label'       => static fn() => __( 'Post ideas for this site', 'zinn-seo' ),
			'tier'        => 'free',
			'subject'     => 'none',
			'store'       => false,
			'per_item'    => 1500,
			'fields'      => array(
				'ideas' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'  => array( 'type' => 'string' ),
							'phrase' => array( 'type' => 'string' ),
							'angle'  => array( 'type' => 'string' ),
							'kind'   => array(
								'type' => 'string',
								'enum' => array( 'how-to', 'list', 'guide', 'comparison', 'opinion', 'news', 'case-study', 'faq' ),
							),
						),
						'required'             => array( 'title', 'phrase', 'angle', 'kind' ),
						'additionalProperties' => false,
					),
				),
			),
			'input'       => static function ( int $id, array $opts ): array {
				$site = self::site();
				return array(
					'language'   => str_replace( '_', '-', (string) ( $opts['language'] ?? get_locale() ) ),
					'site'       => $site['name'],
					'about'      => $site['tagline'],
					'categories' => $site['categories'],
					'existing'   => $site['titles'],
					'topic'      => Tasks::cut( sanitize_text_field( (string) ( $opts['topic'] ?? '' ) ), 200 ),
					'phrases'    => self::phrases( (int) ( $opts['kwlist'] ?? 0 ) ),
					'count'      => max( 3, min( 20, (int) ( $opts['count'] ?? 10 ) ) ),
				);
			},
			'instruction' => static fn() => 'You plan new posts for one website. From what it is about, its categories and the titles it already has, suggest "count" new post ideas that fit it, that its readers search for, and that it has NOT covered yet (never repeat an existing title). Around "topic" when given; use the "phrases" (searches the site wants to rank for) when given. Each: "title" (a working title, 40 to 65 characters), "phrase" (the search it targets), "angle" (one sentence: what makes it worth reading), "kind".',
			'clean'       => static fn( array $a ): array => array(
				'ideas' => array_slice(
					array_values(
						array_filter(
							array_map(
								static fn( $i ): array => is_array( $i ) ? array(
									'title'  => Tasks::line( $i['title'] ?? '', 120 ),
									'phrase' => Tasks::line( $i['phrase'] ?? '', 120 ),
									'angle'  => Tasks::line( $i['angle'] ?? '', 300 ),
									'kind'   => sanitize_key( (string) ( $i['kind'] ?? 'guide' ) ),
								) : array(),
								(array) ( $a['ideas'] ?? array() )
							),
							static fn( array $i ): bool => '' !== ( $i['title'] ?? '' )
						)
					),
					0,
					20
				),
			),
		);
	}

	/**
	 * A draft or a full article from a brief.
	 *
	 * @return array<string, mixed>
	 */
	private static function draft_task(): array {
		return array(
			'label'       => static fn() => __( 'Draft from a brief', 'zinn-seo' ),
			'tier'        => 'free',
			'subject'     => 'none',
			'store'       => false,
			'per_item'    => 4000,
			'fields'      => array(
				'title'     => array( 'type' => 'string' ),
				'seo_title' => array( 'type' => 'string' ),
				'seo_desc'  => array( 'type' => 'string' ),
				'excerpt'   => array( 'type' => 'string' ),
				'intro'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'sections'  => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'heading'    => array( 'type' => 'string' ),
							'paragraphs' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
						'required'             => array( 'heading', 'paragraphs' ),
						'additionalProperties' => false,
					),
				),
			),
			'input'       => static function ( int $id, array $opts ) {
				// One item per article: a planner sends several briefs at once (`briefs`), a person one.
				$briefs = (array) ( $opts['briefs'] ?? array( $opts ) );
				$brief  = (array) ( $briefs[ max( 0, $id - 1 ) ] ?? array() );
				$topic  = Tasks::cut( sanitize_text_field( (string) ( $brief['topic'] ?? '' ) ), 200 );
				if ( '' === $topic ) {
					return new \WP_Error( 'zinn_seo_writer_topic', __( 'Enter what the post should be about.', 'zinn-seo' ) );
				}
				$words   = (int) ( $brief['words'] ?? 600 );
				$words   = in_array( $words, self::LENGTHS, true ) ? $words : 600;
				$voice   = in_array( $brief['voice'] ?? '', self::VOICES, true ) ? (string) $brief['voice'] : 'friendly';
				$outline = array();
				foreach ( array_slice( (array) ( $brief['outline'] ?? array() ), 0, 15 ) as $h ) {
					$heading = Tasks::line( is_array( $h ) ? ( $h['heading'] ?? '' ) : $h, 200 );
					if ( '' !== $heading ) {
						$outline[] = array(
							'heading' => $heading,
							'points'  => is_array( $h ) ? Tasks::lines( $h['points'] ?? array(), 200, 6 ) : array(),
						);
					}
				}
				$site = self::site( 10 );
				return array(
					'language' => str_replace( '_', '-', (string) ( $brief['language'] ?? $opts['language'] ?? get_locale() ) ),
					'site'     => $site['name'],
					'about'    => $site['tagline'],
					'topic'    => $topic,
					'title'    => Tasks::line( $brief['title'] ?? '', 200 ),
					'phrase'   => Tasks::line( $brief['phrase'] ?? '', 120 ),
					'phrases'  => array_slice( array_merge( Tasks::lines( $brief['phrases'] ?? array(), 120, 30 ), self::phrases( (int) ( $brief['kwlist'] ?? 0 ) ) ), 0, 30 ),
					'outline'  => $outline,
					'notes'    => Tasks::cut( sanitize_textarea_field( (string) ( $brief['notes'] ?? '' ) ), 1500 ),
					'words'    => $words,
					'voice'    => $voice,
					'full'     => ! empty( $brief['full'] ),
				);
			},
			'instruction' => static fn() => 'You write a blog post for the website named in "site". Follow the "outline" when given (its headings, in order), else plan five to eight sections yourself. "full" true: a complete article of about "words" words. "full" false: a starter draft — the introduction and every section with two short paragraphs that a writer will expand. Use "phrase" in the title, the first paragraph and one heading, naturally; work in the other "phrases" where they fit. Voice: "voice". Write facts only where they are common knowledge; never invent statistics, quotes, prices or names — write [check: …] where the writer must add a fact. "title": the post title. "seo_title": at most 60 characters. "seo_desc": 140 to 155 characters. "excerpt": one sentence. "intro": one to three paragraphs. "sections": each a heading (no numbering) and its paragraphs (plain text, no markdown).',
			'clean'       => static function ( array $a ): array {
				$sections = array();
				foreach ( array_slice( (array) ( $a['sections'] ?? array() ), 0, 20 ) as $s ) {
					if ( is_array( $s ) && '' !== Tasks::line( $s['heading'] ?? '', 200 ) ) {
						$sections[] = array(
							'heading'    => Tasks::line( $s['heading'] ?? '', 200 ),
							'paragraphs' => Tasks::lines( $s['paragraphs'] ?? array(), 3000, 12 ),
						);
					}
				}
				return array(
					'title'     => Tasks::line( $a['title'] ?? '', 200 ),
					'seo_title' => Tasks::line( $a['seo_title'] ?? '', 70 ),
					'seo_desc'  => Tasks::line( $a['seo_desc'] ?? '', 170 ),
					'excerpt'   => Tasks::line( $a['excerpt'] ?? '', 300 ),
					'intro'     => Tasks::lines( $a['intro'] ?? array(), 3000, 6 ),
					'sections'  => $sections,
				);
			},
		);
	}

	/**
	 * A passage rewritten, expanded, shortened, simplified or continued.
	 *
	 * @return array<string, mixed>
	 */
	private static function passage_task(): array {
		return array(
			'label'       => static fn() => __( 'Rewrite or expand a passage', 'zinn-seo' ),
			'tier'        => 'free',
			'store'       => false,
			'per_item'    => 1500,
			'fields'      => array(
				'text' => array( 'type' => 'string' ),
			),
			'input'       => static function ( int $id, array $opts ) {
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $id ) ) {
					return new \WP_Error( 'zinn_seo_ai_forbidden', __( 'You cannot edit that item.', 'zinn-seo' ) );
				}
				$text = Tasks::cut( sanitize_textarea_field( (string) ( $opts['text'] ?? '' ) ), 4000 );
				if ( '' === $text ) {
					return new \WP_Error( 'zinn_seo_ai_passage', __( 'Choose the text to rewrite.', 'zinn-seo' ) );
				}
				return array(
					'language' => Tasks::language_of( $id ),
					'page'     => wp_strip_all_tags( get_the_title( $post ) ),
					'mode'     => in_array( $opts['mode'] ?? '', self::MODES, true ) ? (string) $opts['mode'] : 'rewrite',
					'passage'  => $text,
					'around'   => Tasks::text_of( $post, 1500 ),
					'phrase'   => Tasks::line( $opts['phrase'] ?? '', 120 ),
				);
			},
			'instruction' => static fn() => 'You edit one passage of a web page ("around" is the page, for context only). "mode": rewrite (clearer, same length), expand (about twice as long, with useful detail, never invented facts), shorten (half the length), simplify (plain words a 12-year-old reads easily), continue (write the next paragraph). Keep the meaning, the facts, the voice and the language; use "phrase" naturally when given. "text": the new passage only, paragraphs separated by a blank line.',
			'clean'       => static fn( array $a ): array => array( 'text' => Tasks::cut( sanitize_textarea_field( (string) ( $a['text'] ?? '' ) ), 8000 ) ),
		);
	}

	/**
	 * Run the draft task for several briefs at once (the Pro planner), batched.
	 *
	 * @param array<int, array<string, mixed>> $briefs Briefs.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function draft_many( array $briefs ) {
		$briefs = array_values( $briefs );
		if ( array() === $briefs ) {
			return array( 'items' => array() );
		}

		return Ai::run( 'draft', range( 1, count( $briefs ) ), array( 'briefs' => $briefs ) );
	}
}
