<?php
/**
 * Author / expert box (zs-306).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks\Types;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\People;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who wrote the page, who reviewed it and who checked the facts, with their job titles,
 * credentials and profiles — the experience and expertise readers and search engines look for
 * (E-E-A-T). Each person is ONE Person node in the graph; the page node gains `reviewedBy` and
 * `lastReviewed`.
 */
final class Author_Box extends Block {

	/** {@inheritDoc} */
	public function name(): string {
		return 'author-box';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Author and expert box', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Who wrote, reviewed and fact-checked the page, with their credentials and profiles.', 'zinn-seo' );
	}

	/** {@inheritDoc} */
	public function icon(): string {
		return 'admin-users';
	}

	/** {@inheritDoc} */
	public function keywords(): array {
		return array( __( 'author', 'zinn-seo' ), __( 'expert', 'zinn-seo' ), __( 'reviewed by', 'zinn-seo' ), 'E-E-A-T' );
	}

	/** {@inheritDoc} */
	public function fields(): array {
		return array(
			'author'   => array(
				'type'  => 'user',
				'label' => static fn() => __( 'Written by (empty: the post\'s author)', 'zinn-seo' ),
			),
			'reviewer' => array(
				'type'  => 'user',
				'label' => static fn() => __( 'Reviewed by', 'zinn-seo' ),
			),
			'checker'  => array(
				'type'  => 'user',
				'label' => static fn() => __( 'Facts checked by', 'zinn-seo' ),
			),
			'show_bio' => array(
				'type'    => 'bool',
				'label'   => static fn() => __( 'Show the biography', 'zinn-seo' ),
				'default' => true,
			),
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
		$rows = self::people( $v, $ctx );
		if ( array() === $rows ) {
			return '';
		}
		$out = Html::open( 'div', Html::cls( 'author' ) );
		foreach ( $rows as $role => $user_id ) {
			$out .= self::person_html( (int) $user_id, $role, (bool) $v['show_bio'] );
		}

		return $out . '</div>';
	}

	/**
	 * The people to show: role => user ID.
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where.
	 * @return array<string, int>
	 */
	private static function people( array $v, Render_Context $ctx ): array {
		$author = $v['author'] > 0 ? (int) $v['author'] : ( $ctx->post instanceof \WP_Post ? (int) $ctx->post->post_author : 0 );
		$out    = array();
		foreach ( array(
			'author'   => $author,
			'reviewer' => (int) $v['reviewer'],
			'checker'  => (int) $v['checker'],
		) as $role => $id ) {
			if ( $id > 0 && get_userdata( $id ) ) {
				$out[ $role ] = $id;
			}
		}

		return $out;
	}

	/**
	 * One person's card.
	 *
	 * @param int    $user_id User.
	 * @param string $role    author, reviewer or checker.
	 * @param bool   $bio     Show the biography.
	 * @return string
	 */
	private static function person_html( int $user_id, string $role, bool $bio ): string {
		$user  = get_userdata( $user_id );
		$roles = array(
			'author'   => __( 'Written by', 'zinn-seo' ),
			'reviewer' => __( 'Reviewed by', 'zinn-seo' ),
			'checker'  => __( 'Facts checked by', 'zinn-seo' ),
		);
		$out   = Html::open( 'div', Html::cls( 'author__person' ) );
		$img   = get_avatar_url( $user_id, array( 'size' => 128 ) );
		if ( is_string( $img ) && '' !== $img ) {
			$out .= Html::open(
				'img',
				Html::cls( 'author__img' ),
				array(
					'src'      => $img,
					'alt'      => '',
					'width'    => '64',
					'height'   => '64',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
		}
		$out .= '<div>' . Html::open( 'p', Html::cls( 'author__role' ) ) . esc_html( $roles[ $role ] ) . '</p>';
		$out .= Html::open( 'p', Html::cls( 'author__name' ) ) . Html::open( 'a', '', array( 'href' => get_author_posts_url( $user_id ) ) ) . esc_html( (string) $user->display_name ) . '</a>';
		$job  = Html::plain( (string) get_user_meta( $user_id, People::JOB_TITLE, true ) );
		if ( '' !== $job ) {
			$out .= ' <span>' . esc_html( $job ) . '</span>';
		}
		$out  .= '</p>';
		$creds = People::lines( $user_id, People::CREDENTIALS );
		if ( array() !== $creds ) {
			$out .= Html::open( 'p', Html::cls( 'author__creds' ) ) . esc_html( implode( ' · ', $creds ) ) . '</p>';
		}
		if ( $bio ) {
			$text = (string) get_user_meta( $user_id, 'description', true );
			if ( '' !== trim( $text ) ) {
				$out .= Html::open( 'div', Html::cls( 'author__bio' ) ) . Html::paragraphs( Html::plain( $text ) ) . '</div>';
			}
		}
		$links = People::same_as( $user_id );
		if ( array() !== $links ) {
			$out .= Html::open( 'p', Html::cls( 'author__links' ) );
			foreach ( $links as $url ) {
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );
				$out .= Html::open(
					'a',
					'',
					array(
						'href' => $url,
						'rel'  => 'me noopener',
					)
				) . esc_html( (string) preg_replace( '/^www\./', '', $host ) ) . '</a> ';
			}
			$out = rtrim( $out ) . '</p>';
		}

		return $out . '</div></div>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $v   Values.
	 * @param Render_Context       $ctx Where it renders.
	 * @return array<string, mixed>
	 */
	public function schema( array $v, Render_Context $ctx ): array {
		$out  = array();
		$page = array();
		foreach ( self::people( $v, $ctx ) as $role => $user_id ) {
			$node = People::node( $user_id );
			if ( array() === $node ) {
				continue;
			}
			$out[ 'person-' . $role ] = $node;
			if ( 'reviewer' === $role || 'checker' === $role ) {
				$page['reviewedBy'][] = array( '@id' => $node['@id'] );
			}
		}
		if ( $ctx->post instanceof \WP_Post ) {
			$reviewed = (string) get_post_meta( $ctx->post->ID, Article_Index::META_REVIEWED, true );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $reviewed ) && isset( $page['reviewedBy'] ) ) {
				$page['lastReviewed'] = $reviewed;
			}
		}
		if ( array() !== $page ) {
			$out['@page'] = $page;
		}

		return $out;
	}
}
