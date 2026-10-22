<?php
/**
 * The AI tasks in the free edition: titles and descriptions, alt text, structured data, and the
 * audit explained (zs-190, zs-433, zs-543, zs-545).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each task here runs on the site owner's own key or on Zinn® credits, one item at a time in the
 * free edition; the Pro bulk job runs `meta` and `alt` over the whole site.
 */
final class Free_Tasks {

	/** Longest SEO title kept (what search results show in full). */
	public const TITLE_MAX = 70;

	/** Longest meta description kept. */
	public const DESC_MAX = 160;

	/** Largest image sent to a provider that reads images. */
	public const IMAGE_BYTES = 1500000;

	/**
	 * Register the tasks.
	 *
	 * @return void
	 */
	public static function register(): void {
		Tasks::register( 'meta', self::meta() );
		Tasks::register( 'alt', self::alt() );
		Tasks::register( 'schema', self::schema() );
		Tasks::register( 'explain', self::explain() );
	}

	/**
	 * A post the current person may edit, or why not.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	public static function editable( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'zinn_seo_ai_missing', __( 'That item no longer exists.', 'zinn-seo' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() && ! Bulk_Context::running() ) {
			return new \WP_Error( 'zinn_seo_ai_forbidden', __( 'You cannot edit that item.', 'zinn-seo' ) );
		}

		return $post;
	}

	/**
	 * The resolved title and description of a post (what search shows now).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{title: string, desc: string}
	 */
	public static function shown( \WP_Post $post ): array {
		if ( class_exists( Context::class ) && class_exists( Resolver::class ) ) {
			$c = Context::for_post( $post );

			return array(
				'title' => (string) Resolver::title( $c ),
				'desc'  => (string) Resolver::description( $c ),
			);
		}

		return array(
			'title' => get_the_title( $post ),
			'desc'  => '',
		);
	}

	/**
	 * Titles and meta descriptions (zs-190). Ten posts per request.
	 *
	 * @return array<string, mixed>
	 */
	private static function meta(): array {
		return array(
			'label'       => static fn() => __( 'SEO titles and meta descriptions', 'zinn-seo' ),
			'tier'        => 'free',
			'bulk'        => true,
			'pack'        => 10,
			'per_item'    => 160,
			'fields'      => array(
				'title' => array( 'type' => 'string' ),
				'desc'  => array( 'type' => 'string' ),
			),
			'input'       => static function ( int $id ) {
				$post = self::editable( $id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$focus = (string) Meta::get_post( $id, 'focus' );

				return array(
					'language' => Tasks::language_of( $id ),
					'kind'     => (string) get_post_type( $post ),
					'name'     => get_the_title( $post ),
					'focus'    => trim( (string) strtok( $focus, "\n|" ) ),
					'site'     => (string) get_bloginfo( 'name' ),
					'text'     => Tasks::text_of( $post, 1800 ),
				);
			},
			'instruction' => static fn() => 'You write the SEO title and meta description for web pages. For each item: "title" is at most 60 characters, starts with what the page is about (the focus phrase when one is given, naturally), and does not repeat the site name unless it adds trust; "desc" is 120 to 155 characters, one or two sentences that say what the reader gets and why to click, in the page\'s own tone. Never use clickbait or words the page does not support.',
			'clean'       => static fn( array $a ): array => array(
				'title' => Tasks::line( $a['title'] ?? '', self::TITLE_MAX ),
				'desc'  => Tasks::line( $a['desc'] ?? '', self::DESC_MAX ),
			),
			'current'     => static function ( int $id ): array {
				$post = get_post( $id );

				return $post instanceof \WP_Post ? self::shown( $post ) : array();
			},
			'apply'       => static function ( int $id, array $fields ) {
				$post = self::editable( $id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				foreach ( array( 'title', 'desc' ) as $name ) {
					if ( '' !== (string) ( $fields[ $name ] ?? '' ) ) {
						$done = Meta::set( 'post', $id, $name, (string) $fields[ $name ], 'ai' );
						if ( is_wp_error( $done ) ) {
							return $done;
						}
					}
				}
				return true;
			},
			'bulk_next'   => static fn( int $after, int $limit, array $scope ): array => Bulk_Query::posts( $after, $limit, $scope, 'meta' ),
			'bulk_count'  => static fn( array $scope ): int => Bulk_Query::count_posts( $scope, 'meta' ),
		);
	}

	/**
	 * Alt text (zs-433, zs-191). With a key from a provider that reads images the model sees the
	 * picture (one image per request); on Zinn® credits it writes from the image's file name,
	 * title, caption and the page it sits on, ten images per request.
	 *
	 * @return array<string, mixed>
	 */
	private static function alt(): array {
		return array(
			'label'       => static fn() => __( 'Image alt text', 'zinn-seo' ),
			'tier'        => 'free',
			'bulk'        => true,
			'subject'     => 'attachment',
			'pack'        => 10,
			'per_item'    => 90,
			'fields'      => array(
				'alt' => array( 'type' => 'string' ),
			),
			'input'       => static function ( int $id ) {
				$post = self::editable( $id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) ) {
					return new \WP_Error( 'zinn_seo_ai_not_image', __( 'That is not an image.', 'zinn-seo' ) );
				}
				$parent = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
				$file   = (string) get_post_meta( $id, '_wp_attached_file', true );

				return array(
					'language' => Tasks::language_of( $parent instanceof \WP_Post ? (int) $parent->ID : $id ),
					'file'     => wp_basename( $file ),
					'title'    => (string) $post->post_title,
					'caption'  => Tasks::cut( (string) $post->post_excerpt, 300 ),
					'about'    => Tasks::cut( wp_strip_all_tags( (string) $post->post_content ), 300 ),
					'page'     => $parent instanceof \WP_Post ? get_the_title( $parent ) : '',
					'near'     => $parent instanceof \WP_Post ? self::near( $parent, $id, $file ) : '',
				);
			},
			'images'      => static fn( int $id ): array => self::image( $id ),
			'instruction' => static fn( array $opts, bool $sees ): string => $sees
				? 'You write alt text for images on a website. Describe what the image shows that matters to a reader of the page, in one sentence of at most 125 characters. Do not start with "Image of" or "Picture of". Include visible text that matters. If the image is purely decorative, answer an empty string.'
				: 'You write alt text for images on a website without seeing them: use only what the file name, title, caption and the page around the image say it shows. One sentence of at most 125 characters. Do not start with "Image of". When those facts say nothing about what the image shows, answer an empty string rather than guess.',
			'clean'       => static fn( array $a ): array => array( 'alt' => Tasks::line( $a['alt'] ?? '', 150 ) ),
			'current'     => static fn( int $id ): array => array( 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'apply'       => static function ( int $id, array $fields ) {
				$post = self::editable( $id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$alt = (string) ( $fields['alt'] ?? '' );
				if ( '' === $alt ) {
					return new \WP_Error( 'zinn_seo_ai_empty', __( 'The AI found nothing it could say about this image. Write the alt text yourself.', 'zinn-seo' ), array( 'status' => 422 ) );
				}
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
				return true;
			},
			'bulk_next'   => static fn( int $after, int $limit, array $scope ): array => Bulk_Query::images( $after, $limit, $scope ),
			'bulk_count'  => static fn( array $scope ): int => Bulk_Query::count_images( $scope ),
		);
	}

	/**
	 * The words around an image where its page uses it (the sentence before and after).
	 *
	 * @param \WP_Post $page Page.
	 * @param int      $id   Attachment.
	 * @param string   $file Its file (relative).
	 * @return string
	 */
	public static function near( \WP_Post $page, int $id, string $file ): string {
		$content = (string) $page->post_content;
		$needles = array( 'wp-image-' . $id, '"id":' . $id, wp_basename( $file ) );
		foreach ( $needles as $needle ) {
			$at = '' !== $needle ? strpos( $content, $needle ) : false;
			if ( false !== $at ) {
				$before = wp_strip_all_tags( substr( $content, max( 0, $at - 1200 ), min( 1200, $at ) ) );
				$after  = wp_strip_all_tags( substr( $content, $at, 1500 ) );
				$text   = trim( (string) preg_replace( '/\s+/u', ' ', substr( $before, -400 ) . ' … ' . substr( $after, 0, 400 ) ) );
				return Tasks::cut( $text, 700 );
			}
		}

		return '';
	}

	/**
	 * The image itself, for a provider that reads images: the medium size, else the original,
	 * when small enough.
	 *
	 * @param int $id Attachment.
	 * @return array<int, array{mime: string, data: string}>
	 */
	public static function image( int $id ): array {
		$path = '';
		$meta = image_get_intermediate_size( $id, 'medium' );
		if ( is_array( $meta ) && ! empty( $meta['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$path    = trailingslashit( (string) $uploads['basedir'] ) . (string) $meta['path'];
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			$path = (string) get_attached_file( $id );
		}
		if ( '' === $path || ! is_readable( $path ) || (int) filesize( $path ) > self::IMAGE_BYTES ) {
			return array();
		}
		$type = wp_check_filetype( $path );
		$mime = (string) ( $type['type'] ?? '' );
		if ( 1 !== preg_match( '#^image/(png|jpeg|webp|gif)$#', $mime ) ) {
			return array();
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload.

		return false === $bytes ? array() : array(
			array(
				'mime' => $mime,
				'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- an image for the AI provider.
			),
		);
	}

	/**
	 * Structured data for a page (zs-543): the right types and their values, checked offline
	 * against schema.org and Google's rich-result rules before anyone sees them.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		return array(
			'label'       => static fn() => __( 'Structured data that fits the page', 'zinn-seo' ),
			'tier'        => 'free',
			'pack'        => 1,
			'per_item'    => 1400,
			'fields'      => array(
				'page'  => array( 'type' => 'string' ),
				'nodes' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'   => array( 'type' => 'string' ),
							'why'    => array( 'type' => 'string' ),
							'values' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'name'  => array( 'type' => 'string' ),
										'value' => array( 'type' => 'string' ),
									),
									'required'             => array( 'name', 'value' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'type', 'why', 'values' ),
						'additionalProperties' => false,
					),
				),
			),
			'input'       => static function ( int $id ) {
				$post = self::editable( $id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				return array(
					'language' => Tasks::language_of( $id ),
					'kind'     => (string) get_post_type( $post ),
					'name'     => get_the_title( $post ),
					'text'     => Tasks::text_of( $post, 5000 ),
				);
			},
			'instruction' => static fn() => 'You choose schema.org structured data for a web page. "page" is the best schema.org WebPage type for the page as a whole (WebPage, AboutPage, ContactPage, CollectionPage, ItemPage, ProfilePage, QAPage, FAQPage). "nodes" lists up to three schema.org types the page\'s content truly supports (for example Article, Product, Recipe, Event, HowTo, FAQPage, LocalBusiness, Course, JobPosting, SoftwareApplication), each with "why" (one short sentence a site owner understands) and "values": schema.org property names with values copied from the page. Only properties the page states; never invent ratings, prices, dates or addresses. An empty list when nothing beyond the page type fits.',
			'clean'       => static function ( array $a, int $id ): array {
				$nodes = array();
				foreach ( (array) ( $a['nodes'] ?? array() ) as $node ) {
					$type = preg_replace( '/[^A-Za-z]/', '', (string) ( $node['type'] ?? '' ) );
					if ( '' === $type || ( class_exists( '\ZinnDigital\ZinnSEO\Schema\Vocab' ) && ! \ZinnDigital\ZinnSEO\Schema\Vocab::is_type( $type ) ) ) {
						continue;
					}
					$values = array();
					foreach ( (array) ( $node['values'] ?? array() ) as $pair ) {
						$name = preg_replace( '/[^A-Za-z]/', '', (string) ( $pair['name'] ?? '' ) );
						$val  = Tasks::line( $pair['value'] ?? '', 500 );
						if ( '' !== $name && '' !== $val ) {
							$values[ $name ] = $val;
						}
					}
					$nodes[] = array(
						'type'   => $type,
						'why'    => Tasks::line( $node['why'] ?? '', 200 ),
						'values' => $values,
					);
				}
				$nodes = array_slice( $nodes, 0, 3 );
				$page  = preg_replace( '/[^A-Za-z]/', '', (string) ( $a['page'] ?? '' ) );

				return array(
					'page'   => $page,
					'nodes'  => $nodes,
					'checks' => self::validate( $id, $nodes ),
				);
			},
			'current'     => static fn( int $id ): array => array( 'page' => (string) Meta::get_post( $id, 'schema_page' ) ),
			'apply'       => static function ( int $id, array $fields ) {
				$post = self::editable( $id );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$page    = (string) ( $fields['page'] ?? '' );
				$choices = (array) ( Meta::fields()['schema_page']['choices'] ?? array() );
				if ( '' !== $page && in_array( $page, $choices, true ) ) {
					$done = Meta::set( 'post', $id, 'schema_page', $page, 'ai' );
					if ( is_wp_error( $done ) ) {
						return $done;
					}
				}
				$nodes = (array) ( $fields['nodes'] ?? array() );
				// Pro keeps whole types on the page (the Structured data panel's list); the free
				// edition sets the page type, which is what it can show.
				if ( array() !== $nodes && class_exists( '\ZinnDigital\ZinnSEO\Pro\Schema\Schema_Pro' ) ) {
					$items = \ZinnDigital\ZinnSEO\Pro\Schema\Schema_Pro::stored( $id );
					foreach ( $nodes as $node ) {
						$items[] = array(
							'type'   => (string) $node['type'],
							'values' => (array) $node['values'],
						);
					}
					\ZinnDigital\ZinnSEO\Pro\Schema\Schema_Pro::store( $id, $items );
				}
				return true;
			},
		);
	}

	/**
	 * Validate proposed nodes offline (zs-035): schema.org types and properties, Google's
	 * rich-result requirements. A proposal with errors is still shown, with the errors.
	 *
	 * @param int                              $id    Page.
	 * @param array<int, array<string, mixed>> $nodes Proposed nodes.
	 * @return array{errors: int, warnings: int, notes: array<int, string>}
	 */
	public static function validate( int $id, array $nodes ): array {
		$graph = array();
		foreach ( $nodes as $node ) {
			$graph[] = array(
				'@type' => (string) $node['type'],
				'@id'   => (string) get_permalink( $id ) . '#' . strtolower( (string) $node['type'] ),
			) + (array) $node['values'];
		}
		if ( array() === $graph || ! class_exists( '\ZinnDigital\ZinnSEO\Schema\Validator' ) ) {
			return array(
				'errors'   => 0,
				'warnings' => 0,
				'notes'    => array(),
			);
		}
		$report = \ZinnDigital\ZinnSEO\Schema\Validator::check( array( '@graph' => $graph ) );
		$notes  = array();
		foreach ( (array) $report['nodes'] as $row ) {
			foreach ( array_merge( (array) ( $row['errors'] ?? array() ), (array) ( $row['warnings'] ?? array() ) ) as $note ) {
				$notes[] = is_array( $note ) ? (string) ( $note['message'] ?? wp_json_encode( $note ) ) : (string) $note;
			}
		}

		return array(
			'errors'   => (int) $report['errors'],
			'warnings' => (int) $report['warnings'],
			'notes'    => array_slice( $notes, 0, 20 ),
		);
	}

	/**
	 * An audit issue explained for someone who is not technical (zs-545), with the issue's own
	 * one-click fix offered where it has one.
	 *
	 * @return array<string, mixed>
	 */
	private static function explain(): array {
		return array(
			'label'       => static fn() => __( 'Explain an issue in plain words', 'zinn-seo' ),
			'tier'        => 'free',
			'subject'     => 'none',
			'store'       => false,
			'pack'        => 1,
			'per_item'    => 700,
			'fields'      => array(
				'what'  => array( 'type' => 'string' ),
				'why'   => array( 'type' => 'string' ),
				'steps' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'input'       => static function ( int $id, array $opts ) {
				$issue = self::issue( (string) ( $opts['issue'] ?? '' ) );
				if ( null === $issue ) {
					return new \WP_Error( 'zinn_seo_ai_issue', __( 'That issue is not in the Fix it list any more.', 'zinn-seo' ) );
				}

				return array(
					'language' => str_replace( '_', '-', determine_locale() ),
					'issue'    => (string) $issue['title'],
					'detail'   => (string) $issue['detail'],
					'count'    => (int) $issue['count'],
					'area'     => (string) $issue['area'],
					'site'     => (string) get_bloginfo( 'name' ),
				);
			},
			'instruction' => static fn() => 'You explain a website SEO issue to a business owner who is not technical, in the language given. "what": one or two short sentences on what is wrong, in plain words, no jargon (explain any term you must use). "why": one or two sentences on what it costs them in visitors or sales if left. "steps": up to five short steps to fix it inside WordPress, starting with the easiest. Be accurate and calm; never exaggerate.',
			'clean'       => static fn( array $a ): array => array(
				'what'  => Tasks::line( $a['what'] ?? '', 600 ),
				'why'   => Tasks::line( $a['why'] ?? '', 600 ),
				'steps' => Tasks::lines( $a['steps'] ?? array(), 300, 5 ),
			),
		);
	}

	/**
	 * A Fix-it issue by id (as the inbox lists it now).
	 *
	 * @param string $id Issue id.
	 * @return array<string, mixed>|null
	 */
	public static function issue( string $id ): ?array {
		if ( '' === $id || ! class_exists( '\ZinnDigital\ZinnSEO\Fixit\Fixit' ) ) {
			return null;
		}
		foreach ( (array) ( \ZinnDigital\ZinnSEO\Fixit\Fixit::issues()['issues'] ?? array() ) as $issue ) {
			if ( (string) ( $issue['id'] ?? '' ) === $id ) {
				return $issue;
			}
		}

		return null;
	}
}
