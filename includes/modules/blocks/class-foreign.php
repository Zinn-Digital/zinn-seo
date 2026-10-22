<?php
/**
 * Other SEO plugins' FAQ and How-to blocks keep their structured data after a move (importer
 * certification, queue Q254).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO, All in One SEO and Rank Math save their FAQ and How-to blocks as HTML plus the
 * questions and steps in the block's attributes; their own code turned those into `FAQPage` and
 * `HowTo` structured data. Switched off, the HTML still shows but the structured data was gone
 * (measured: a Yoast FAQ page lost its two questions and its How-to). The same facts are read
 * here from the saved block — never from the other plugin's code — and given to the page's graph
 * through the collector, exactly as this plugin's own FAQ block does.
 *
 * ⛔ Only for a block no plugin has registered: while the other plugin runs, it describes its
 * own blocks.
 */
final class Foreign {

	/** The blocks read: name => kind. */
	public const BLOCKS = array(
		'yoast/faq-block'     => 'yoast-faq',
		'yoast/how-to-block'  => 'yoast-howto',
		'aioseo/faq'          => 'aioseo-faq',
		'rank-math/faq-block' => 'rm-faq',
	);

	/**
	 * Hooks (front end).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'wp', array( self::class, 'collect' ), 20 );
	}

	/**
	 * Read the page's foreign blocks into the collector.
	 *
	 * @return void
	 */
	public static function collect(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) || false === strpos( (string) $post->post_content, '<!-- wp:' ) ) {
			return;
		}
		foreach ( self::nodes( parse_blocks( (string) $post->post_content ), (string) get_permalink( $post ), Html::plain( get_the_title( $post ) ), self::registered() ) as $key => $node ) {
			Collector::add_instance( 'foreign:' . $post->ID . ':' . $key, array( ( 0 === strpos( (string) $key, 'faq' ) ? 'faq' : 'howto' ) => $node ) );
		}
	}

	/**
	 * The foreign block names some plugin has registered (those are its own to describe).
	 *
	 * @return array<int, string>
	 */
	private static function registered(): array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return array();
		}
		$registry = \WP_Block_Type_Registry::get_instance();

		return array_values( array_filter( array_keys( self::BLOCKS ), static fn( string $name ): bool => $registry->is_registered( $name ) ) );
	}

	/**
	 * The structured data of the foreign blocks in a page's parsed blocks. Pure, for the tests.
	 *
	 * @param array<int, array<string, mixed>> $blocks     `parse_blocks()` result.
	 * @param string                           $url        The page's address.
	 * @param string                           $title      The page's title (a How-to's name).
	 * @param array<int, string>               $registered Block names another plugin handles.
	 * @return array<string, array<string, mixed>> Key (`faq…`, `howto…`) => node.
	 */
	public static function nodes( array $blocks, string $url, string $title, array $registered = array() ): array {
		$questions = array();
		$out       = array();
		$n         = 0;
		$walk      = static function ( array $blocks ) use ( &$walk, &$questions, &$out, &$n, $url, $title, $registered ): void {
			foreach ( $blocks as $block ) {
				$name = (string) ( $block['blockName'] ?? '' );
				$a    = (array) ( $block['attrs'] ?? array() );
				if ( isset( self::BLOCKS[ $name ] ) && ! in_array( $name, $registered, true ) ) {
					switch ( self::BLOCKS[ $name ] ) {
						case 'yoast-faq':
							foreach ( (array) ( $a['questions'] ?? array() ) as $q ) {
								$q = (array) $q;
								self::question( $questions, self::text( $q['jsonQuestion'] ?? ( $q['question'] ?? '' ) ), self::text( $q['jsonAnswer'] ?? ( $q['answer'] ?? '' ) ), $url, (string) ( $q['id'] ?? '' ) );
							}
							break;
						case 'rm-faq':
							foreach ( (array) ( $a['questions'] ?? array() ) as $q ) {
								$q = (array) $q;
								if ( ! isset( $q['visible'] ) || ! empty( $q['visible'] ) ) {
									self::question( $questions, self::text( $q['title'] ?? '' ), self::text( $q['content'] ?? '' ), $url, (string) ( $q['id'] ?? '' ) );
								}
							}
							break;
						case 'aioseo-faq':
							$answer = (string) preg_replace( '#^.*?<div class="aioseo-faq-block-answer">(.*)</div>\s*</div>\s*$#s', '$1', (string) ( $block['innerHTML'] ?? '' ) );
							if ( array() !== (array) ( $block['innerBlocks'] ?? array() ) ) {
								$answer = implode( ' ', array_map( static fn( $b ): string => (string) ( $b['innerHTML'] ?? '' ), (array) $block['innerBlocks'] ) );
							}
							self::question( $questions, self::text( $a['question'] ?? '' ), self::text( $answer ), $url, '' );
							break;
						case 'yoast-howto':
							$node = self::howto( $a, $url, $title );
							if ( array() !== $node ) {
								++$n;
								$out[ 'howto' . ( $n > 1 ? '-' . $n : '' ) ] = $node;
							}
							break;
					}
				}
				if ( array() !== (array) ( $block['innerBlocks'] ?? array() ) ) {
					$walk( (array) $block['innerBlocks'] );
				}
			}
		};
		$walk( $blocks );
		if ( array() !== $questions ) {
			$out = array(
				'faq' => array(
					'@type'      => 'FAQPage',
					'@id'        => $url . '#faq',
					'mainEntity' => $questions,
				),
			) + $out;
		}

		return $out;
	}

	/**
	 * Plain text of a stored value (a string, or a list of rich-text pieces).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_map( static fn( $v ): string => is_scalar( $v ) ? (string) $v : '', $value ) );
		}

		return is_scalar( $value ) ? Html::plain( wp_strip_all_tags( (string) $value ) ) : '';
	}

	/**
	 * Add one question.
	 *
	 * @param array<int, array<string, mixed>> $questions List.
	 * @param string                           $question  Question.
	 * @param string                           $answer    Answer.
	 * @param string                           $url       Page address.
	 * @param string                           $anchor    The question's anchor on the page.
	 * @return void
	 */
	private static function question( array &$questions, string $question, string $answer, string $url, string $anchor ): void {
		if ( '' === $question || '' === $answer ) {
			return;
		}
		$node = array(
			'@type'          => 'Question',
			'name'           => $question,
			'position'       => count( $questions ) + 1,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $answer,
			),
		);
		if ( 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $anchor ) ) {
			$node['url'] = $url . '#' . $anchor;
		}
		$questions[] = $node;
	}

	/**
	 * Yoast's How-to block as a `HowTo` node (steps as Yoast described them).
	 *
	 * @param array<string, mixed> $a     Attributes.
	 * @param string               $url   Page address.
	 * @param string               $title Page title.
	 * @return array<string, mixed>
	 */
	private static function howto( array $a, string $url, string $title ): array {
		$steps = array();
		foreach ( (array) ( $a['steps'] ?? array() ) as $step ) {
			$step = (array) $step;
			$name = self::text( $step['jsonName'] ?? ( $step['name'] ?? '' ) );
			$text = self::text( $step['jsonText'] ?? ( $step['text'] ?? '' ) );
			if ( '' === $name && '' === $text ) {
				continue;
			}
			$node = array( '@type' => 'HowToStep' );
			$id   = (string) ( $step['id'] ?? '' );
			if ( 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $id ) ) {
				$node['url'] = $url . '#' . $id;
			}
			if ( '' !== $name && '' !== $text ) {
				$node['name']            = $name;
				$node['itemListElement'] = array(
					array(
						'@type' => 'HowToDirection',
						'text'  => $text,
					),
				);
			} else {
				$node['text'] = '' !== $text ? $text : $name;
			}
			$steps[] = $node;
		}
		if ( array() === $steps ) {
			return array();
		}
		$node = array(
			'@type' => 'HowTo',
			'@id'   => $url . '#howto',
			'name'  => $title,
			'step'  => $steps,
		);
		$desc = self::text( $a['jsonDescription'] ?? '' );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		if ( ! empty( $a['hasDuration'] ) ) {
			$d = max( 0, (int) ( $a['days'] ?? 0 ) );
			$h = max( 0, (int) ( $a['hours'] ?? 0 ) );
			$m = max( 0, (int) ( $a['minutes'] ?? 0 ) );
			if ( $d + $h + $m > 0 ) {
				$node['totalTime'] = 'P' . ( $d > 0 ? $d . 'D' : '' ) . ( $h + $m > 0 ? 'T' . ( $h > 0 ? $h . 'H' : '' ) . ( $m > 0 ? $m . 'M' : '' ) : '' );
			}
		}

		return $node;
	}
}
