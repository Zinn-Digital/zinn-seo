<?php
/**
 * Kadence Blocks, GenerateBlocks and Spectra (zs-126).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

use ZinnDigital\ZinnSEO\Blocks\Collector;
use ZinnDigital\ZinnSEO\Integrations\Kit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * What these suites put on a page is ordinary block output, so the content checks already read
 * it as visitors do (the page is rendered through `the_content`): accordion panes, tab panels
 * and grid cells are all in the HTML that is analysed.
 *
 * What needs joining up is their FAQ data. A Kadence accordion with "FAQ schema" on, and a
 * Spectra FAQ block with schema support on, each print a FAQPage script of their own: a second
 * document beside this plugin's graph, tied to nothing, and a duplicate FAQPage when the page
 * also holds one of our FAQ blocks (Google reads one per page). Their questions go into the
 * page's ONE FAQPage in the graph instead, and their own script is not printed.
 * GenerateBlocks prints no structured data.
 */
return static function ( string $context ): void {
	if ( 'front' !== $context ) {
		return;
	}
	add_action(
		'wp',
		static function (): void {
			$post = is_singular() ? get_queried_object() : null;
			if ( ! $post instanceof \WP_Post || ! Kit::prints_graph() || ! class_exists( Collector::class ) ) {
				return;
			}
			$content = (string) $post->post_content;
			$kadence = false !== strpos( $content, '"faqSchema":true' ) && false !== strpos( $content, 'wp:kadence/accordion' );
			$spectra = false !== strpos( $content, '"enableSchemaSupport":true' ) && false !== strpos( $content, 'wp:uagb/faq' );
			if ( ! $kadence && ! $spectra ) {
				return;
			}
			$questions = array();
			$walk      = static function ( array $blocks, int $depth = 0 ) use ( &$walk, &$questions ): void {
				foreach ( $blocks as $block ) {
					$name  = (string) ( $block['blockName'] ?? '' );
					$attrs = (array) ( $block['attrs'] ?? array() );
					$inner = (array) ( $block['innerBlocks'] ?? array() );
					if ( 'uagb/faq' === $name && ! empty( $attrs['enableSchemaSupport'] ) ) {
						foreach ( $inner as $child ) {
							$q = Kit::text( (string) ( $child['attrs']['question'] ?? '' ) );
							$a = Kit::text( (string) ( $child['attrs']['answer'] ?? '' ) );
							if ( '' !== $q && '' !== $a ) {
								$questions[] = array(
									'@type'          => 'Question',
									'name'           => $q,
									'acceptedAnswer' => array(
										'@type' => 'Answer',
										'text'  => $a,
									),
								);
							}
						}
						continue;
					}
					if ( 'kadence/accordion' === $name && ! empty( $attrs['faqSchema'] ) ) {
						foreach ( $inner as $pane ) {
							// A pane's question is its title; its answer is what the pane holds.
							$q = 1 === preg_match( '/<span class="kt-blocks-accordion-title">(.*?)<\/span>/s', (string) ( $pane['innerHTML'] ?? '' ), $m ) ? Kit::text( $m[1] ) : '';
							$a = '';
							foreach ( (array) ( $pane['innerBlocks'] ?? array() ) as $part ) {
								$a .= ' ' . render_block( $part );
							}
							$a = Kit::text( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $a ) );
							if ( '' !== $q && '' !== $a ) {
								$questions[] = array(
									'@type'          => 'Question',
									'name'           => $q,
									'acceptedAnswer' => array(
										'@type' => 'Answer',
										'text'  => $a,
									),
								);
							}
						}
						continue;
					}
					if ( array() !== $inner && $depth < 12 ) {
						$walk( $inner, $depth + 1 );
					}
				}
			};
			$walk( parse_blocks( $content ) );
			if ( array() === $questions ) {
				return;
			}
			Collector::add_node(
				'faq',
				array(
					'@type'      => 'FAQPage',
					'@id'        => get_permalink( $post ) . '#faq',
					'mainEntity' => $questions,
				)
			);
			if ( $kadence && class_exists( '\Kadence_Blocks_Frontend' ) ) {
				remove_action( 'wp_head', array( \Kadence_Blocks_Frontend::get_instance(), 'faq_schema' ), 91 );
			}
			if ( $spectra ) {
				add_filter(
					'render_block_uagb/faq',
					static fn( $html ) => is_string( $html ) ? (string) preg_replace( '@<(script) type="application/ld\+json">\s*\{"\@context":"https:\\\\?/\\\\?/schema\.org","\@type":"FAQPage".*?</\1>@s', '', $html ) : $html,
					20
				);
			}
		},
		15
	);
};
