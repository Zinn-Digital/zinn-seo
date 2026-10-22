<?php
/**
 * Page Builder Sandwich adapter: the SEO blocks in PBS's inserter, and PBS's structured data in
 * the one graph (zs-300, zs-031, zs-072).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Adapters;

use ZinnDigital\ZinnSEO\Blocks\Collector;
use ZinnDigital\ZinnSEO\Blocks\Library;
use ZinnDigital\ZinnSEO\Modules;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two directions, both through PBS's PUBLIC API only (nothing in PBS is edited):
 *
 * 1. The blocks are registered with `pbsw_register_block()`, so they appear in PBS's inserter and
 *    extensions list and get its Style panel, exactly like PBS's own.
 * 2. While Zinn® SEO prints the page's graph, PBS's structured data joins it instead of printing a
 *    second graph: the page's PBS blocks are read before the head prints (PBS's public
 *    `Schema::node()` for steps, pricing tables and review scores; the accordion's own FAQ), the
 *    nodes go into the collector, and `pbsw_schema_skip` tells PBS those types are printed.
 *    The FAQ and How-to blocks of Zinn® SEO leave the inserters while PBS is active (PBS has its
 *    own, zs-072); a page with both still gets ONE FAQPage.
 */
final class Pbs {

	/** PBS schema types this adapter takes over. */
	private const TYPES = array( 'FAQPage', 'HowTo', 'Product', 'Review' );

	/**
	 * Types absorbed on this page.
	 *
	 * @var array<int, string>
	 */
	private static array $absorbed = array();

	/**
	 * Is Page Builder Sandwich active (with its public block API)?
	 *
	 * @return bool
	 */
	public static function active(): bool {
		// The Integrations screen's switch for Page Builder Sandwich is the switch for all of this.
		return function_exists( 'pbsw_register_block' ) && ! in_array( 'page-builder-sandwich', array_map( 'strval', (array) Settings::get( 'integrations_off', array() ) ), true );
	}

	/**
	 * Hook in.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		add_action( 'init', array( self::class, 'register' ), 21 );
		if ( 'front' === $context ) {
			add_action( 'wp', array( self::class, 'absorb' ), 19 );
			add_filter( 'pbsw_schema_skip', array( self::class, 'skip' ), 20 );
			add_filter( 'render_block', array( self::class, 'strip_inline' ), 30, 2 );
		}
	}

	/**
	 * Register every SEO block through PBS's API.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}
		foreach ( Library::all() as $block ) {
			$args           = Gutenberg::args( $block );
			$args['plugin'] = __( 'Zinn® SEO', 'zinn-seo' );
			call_user_func( 'pbsw_register_block', $block->block_name(), $args );
		}
	}

	/**
	 * Read the page's PBS structured data into the graph (only while our graph is printed).
	 *
	 * @return void
	 */
	public static function absorb(): void {
		self::$absorbed = array();
		if ( ! self::active() || ! Library::we_print_the_graph() ) {
			return;
		}
		$post = is_singular() ? get_queried_object() : null;
		if ( ! $post instanceof \WP_Post || false === strpos( (string) $post->post_content, 'wp:pbs/' ) || post_password_required( $post ) ) {
			return;
		}
		$url   = (string) get_permalink( $post );
		$title = wp_strip_all_tags( get_the_title( $post ) );
		foreach ( self::pbs_blocks( parse_blocks( (string) $post->post_content ) ) as $b ) {
			$name  = (string) $b['blockName'];
			$attrs = (array) ( $b['attrs'] ?? array() );
			if ( 'pbs/accordion' === $name && ! empty( $attrs['faq'] ) ) {
				foreach ( self::inline_json( self::render_alone( $b ) ) as $node ) {
					if ( 'FAQPage' === ( $node['@type'] ?? '' ) ) {
						$node['@id'] = $url . '#faq';
						Collector::add_instance( 'pbs:' . md5( (string) wp_json_encode( $node ) ), array( 'faq' => $node ) );
						self::$absorbed[] = 'FAQPage';
					}
				}
				continue;
			}
			$schema = '\ZinnDigital\PBS\Pro\Seo\Schema';
			if ( ! empty( $attrs['pbsSchema'] ) && class_exists( $schema ) && is_callable( array( $schema, 'node' ) ) ) {
				$node = call_user_func( array( $schema, 'node' ), $name, $attrs, $title );
				if ( is_array( $node ) && in_array( $node['@type'] ?? '', self::TYPES, true ) ) {
					$key         = strtolower( (string) $node['@type'] );
					$node['@id'] = $url . '#pbs-' . $key . '-' . substr( md5( (string) wp_json_encode( $attrs ) ), 0, 6 );
					Collector::add_instance( 'pbs:' . md5( (string) wp_json_encode( $node ) ), array( $key => $node ) );
					self::$absorbed[] = (string) $node['@type'];
				}
			}
		}
		self::$absorbed = array_values( array_unique( self::$absorbed ) );
	}

	/**
	 * A block's HTML as free Page Builder Sandwich prints it, its own JSON-LD still inside.
	 *
	 * ⛔ WHY NOT A BARE render_block(). Page Builder Sandwich PRO takes that JSON-LD out of the
	 * HTML as the block renders and keeps it for its own footer script. Read through a bare
	 * render, the accordion looked empty here — so nothing was absorbed, Pro printed a second
	 * script beside our graph, and it held every question TWICE (once from this early render,
	 * once from the page's own). Found on the family site with the premium editions, 2026-10-11.
	 * Pro's collector is taken off for this one render and put back.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private static function render_alone( array $block ): string {
		$collector = array( 'ZinnDigital\\PBS\\Pro\\Seo\\Schema', 'collect' );
		$was       = class_exists( $collector[0], false ) ? has_filter( 'render_block', $collector ) : false;
		if ( false !== $was ) {
			remove_filter( 'render_block', $collector, (int) $was );
		}
		$html = (string) render_block( $block );
		if ( false !== $was ) {
			add_filter( 'render_block', $collector, (int) $was, 2 );
		}

		return $html;
	}

	/**
	 * Tell PBS which types are already printed (in our graph).
	 *
	 * @param mixed $taken Types PBS leaves out.
	 * @return array<int, string>
	 */
	public static function skip( $taken ): array {
		$taken = (array) $taken;
		if ( ! Library::we_print_the_graph() ) {
			return $taken;
		}
		$ours = self::$absorbed;
		if ( Modules::enabled( 'schema' ) ) {
			$ours[] = 'BreadcrumbList'; // The graph's own trail.
		}

		return array_values( array_unique( array_merge( $taken, $ours ) ) );
	}

	/**
	 * Take the accordion's inline JSON-LD out of its HTML (free PBS prints it in the body); its
	 * questions are already in the graph.
	 *
	 * @param string               $html  HTML.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public static function strip_inline( $html, $block ): string {
		$html = (string) $html;
		if ( 'pbs/accordion' !== ( $block['blockName'] ?? '' ) || ! in_array( 'FAQPage', self::$absorbed, true ) || false === strpos( $html, 'application/ld+json' ) ) {
			return $html;
		}

		return (string) preg_replace( '#<script\b[^>]*application/ld\+json[^>]*>.*?</script>#is', '', $html );
	}

	/**
	 * Every PBS block in a parsed tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function pbs_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( 0 === strpos( (string) ( $b['blockName'] ?? '' ), 'pbs/' ) ) {
				$out[] = $b;
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$out = array_merge( $out, self::pbs_blocks( (array) $b['innerBlocks'] ) );
			}
		}

		return $out;
	}

	/**
	 * JSON-LD nodes printed inside some HTML.
	 *
	 * @param string $html HTML.
	 * @return array<int, array<string, mixed>>
	 */
	public static function inline_json( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<script\b[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$data = json_decode( html_entity_decode( $raw, ENT_NOQUOTES, 'UTF-8' ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				unset( $data['@context'] );
				foreach ( isset( $data['@graph'] ) ? (array) $data['@graph'] : array( $data ) as $node ) {
					if ( is_array( $node ) ) {
						$out[] = $node;
					}
				}
			}
		}

		return $out;
	}
}
