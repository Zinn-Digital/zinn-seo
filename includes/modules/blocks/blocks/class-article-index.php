<?php
/**
 * Article index, "In this article" (zs-301).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Headings;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The box at the top of an article: the reading time, when it was last reviewed, the key
 * takeaways and the contents — open and close with no script (`<details>`). Placed by hand, or
 * added to every post of chosen types with no editing (Blocks → Article index); the takeaways
 * then come from the post's own "Key takeaways" field.
 */
final class Article_Index extends Block {

	/** Post meta: the post's takeaways (one per line) and when it was last reviewed (Y-m-d). */
	public const META_TAKEAWAYS = '_zseo_takeaways';
	public const META_REVIEWED  = '_zseo_reviewed';

	/** {@inheritDoc} */
	public function name(): string {
		return 'article-index';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Article index', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'In this article: reading time, last reviewed, key takeaways and contents, at the top of a post.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'index-card';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'in this article', 'zinn-seo' ), __( 'reading time', 'zinn-seo' ), __( 'contents', 'zinn-seo' ), 'TL;DR' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		$list = Toc::list_fields( __( 'Contents', 'zinn-seo' ) );
		unset( $list['title'], $list['collapsible'] );

		return array_merge(
			array(
				'title'         => array(
					'type'    => 'text',
					'label'   => static fn() => __( 'Title', 'zinn-seo' ),
					'default' => __( 'In this article', 'zinn-seo' ),
					'content' => true,
					'max'     => 120,
				),
				'takeaways'     => array(
					'type'    => 'list',
					'label'   => static fn() => __( 'Key takeaways (one per line; empty uses the post\'s own)', 'zinn-seo' ),
					'content' => true,
					'max'     => 400,
				),
				'show_contents' => array(
					'type'    => 'bool',
					'label'   => static fn() => __( 'Show the contents', 'zinn-seo' ),
					'default' => true,
				),
				'show_time'     => array(
					'type'    => 'bool',
					'label'   => static fn() => __( 'Show the reading time', 'zinn-seo' ),
					'default' => true,
				),
				'show_reviewed' => array(
					'type'    => 'bool',
					'label'   => static fn() => __( 'Show when it was last reviewed', 'zinn-seo' ),
					'default' => true,
				),
				'open'          => array(
					'type'    => 'bool',
					'label'   => static fn() => __( 'Open when the page loads', 'zinn-seo' ),
					'default' => true,
				),
			),
			$list
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return string
	 */
	public function render( array $v, Render_Context $ctx ): string {
		$post      = $ctx->post;
		$takeaways = array() !== $v['takeaways'] ? $v['takeaways'] : self::post_takeaways( $post );
		$build     = static function ( array $headings, string $html ) use ( $v, $post, $takeaways ): string {
			return self::html( $v, $post, $takeaways, $headings, Headings::words( $html ) );
		};
		if ( Headings::in_content() && ! $ctx->preview ) {
			return Headings::placeholder( $build );
		}
		$content = $post instanceof \WP_Post ? (string) $post->post_content : '';
		$html    = $build( Headings::of_post( $post ), $content );

		return '' === $html && $ctx->preview ? Toc::preview_note( (string) $v['title'] ) : $html;
	}

	/**
	 * The box.
	 *
	 * @param array<string, mixed>                                    $v         Values.
	 * @param \WP_Post|null                                           $post      Post.
	 * @param array<int, string>                                      $takeaways Takeaways.
	 * @param array<int, array{level: int, text: string, id: string}> $headings  Headings.
	 * @param int                                                     $words     Words on the page.
	 * @return string
	 */
	public static function html( array $v, ?\WP_Post $post, array $takeaways, array $headings, int $words ): string {
		$facts = array();
		if ( $v['show_time'] && $words > 0 ) {
			$minutes = self::reading_minutes( $words );
			/* translators: %s: a number of minutes. */
			$facts[] = '<span>' . esc_html( sprintf( _n( '%s minute read', '%s minute read', $minutes, 'zinn-seo' ), number_format_i18n( $minutes ) ) ) . '</span>';
		}
		if ( $v['show_reviewed'] && $post instanceof \WP_Post ) {
			$reviewed = (string) get_post_meta( $post->ID, self::META_REVIEWED, true );
			$stamp    = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $reviewed ) ? strtotime( $reviewed . ' 12:00:00' ) : false;
			if ( false !== $stamp ) {
				/* translators: %s: a date. */
				$facts[] = '<span>' . sprintf( esc_html__( 'Reviewed %s', 'zinn-seo' ), '<time datetime="' . esc_attr( $reviewed ) . '">' . esc_html( date_i18n( (string) get_option( 'date_format' ), $stamp ) ) . '</time>' ) . '</span>';
			} else {
				$modified = (string) get_post_modified_time( 'U', true, $post );
				if ( '' !== $modified ) {
					/* translators: %s: a date. */
					$facts[] = '<span>' . sprintf( esc_html__( 'Updated %s', 'zinn-seo' ), '<time datetime="' . esc_attr( (string) get_post_modified_time( 'c', true, $post ) ) . '">' . esc_html( date_i18n( (string) get_option( 'date_format' ), (int) $modified ) ) . '</time>' ) . '</span>';
				}
			}
		}
		$inner = '';
		if ( array() !== $facts ) {
			$inner .= Html::open( 'p', Html::cls( 'index__facts' ) ) . implode( ' ', $facts ) . '</p>';
		}
		if ( array() !== $takeaways ) {
			$inner .= Takeaways::html( __( 'Key takeaways', 'zinn-seo' ), $takeaways, false );
		}
		if ( $v['show_contents'] ) {
			$toc    = Toc::html(
				$headings,
				array(
					'title'       => __( 'Contents', 'zinn-seo' ),
					'min_level'   => $v['min_level'],
					'max_level'   => $v['max_level'],
					'numbered'    => $v['numbered'],
					'collapsible' => false,
				),
				'index-toc'
			);
			$inner .= $toc;
		}
		if ( '' === $inner ) {
			return '';
		}
		$title = '' !== $v['title'] ? $v['title'] : __( 'In this article', 'zinn-seo' );

		return Html::open( 'aside', Html::cls( 'index' ), array( 'aria-label' => $title ) )
			. Html::open( 'details', Html::cls( 'index__box' ), $v['open'] ? array( 'open' => 'open' ) : array() )
			. Html::open( 'summary', Html::cls( 'index__title' ) ) . esc_html( $title ) . '</summary>'
			. Html::open( 'div', Html::cls( 'index__body' ) ) . $inner . '</div></details></aside>';
	}

	/**
	 * A post's own takeaways (the "Key takeaways" field).
	 *
	 * @param \WP_Post|null $post Post.
	 * @return array<int, string>
	 */
	public static function post_takeaways( ?\WP_Post $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$raw = get_post_meta( $post->ID, self::META_TAKEAWAYS, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : Html::split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = Html::plain( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Minutes to read some words (at least 1).
	 *
	 * @param int $words Words.
	 * @return int
	 */
	public static function reading_minutes( int $words ): int {
		/**
		 * Filters the reading speed, in words a minute (default 225: adults reading on screen).
		 *
		 * @param int $wpm Words a minute.
		 */
		$wpm = max( 50, (int) apply_filters( 'zinn_seo_words_per_minute', 225 ) );

		return max( 1, (int) ceil( $words / $wpm ) );
	}
}
