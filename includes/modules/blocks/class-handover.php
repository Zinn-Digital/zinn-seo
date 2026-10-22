<?php
/**
 * Hand the blocks' structured data to another SEO plugin's graph (zs-031: never two graphs).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * While another SEO plugin owns the head (the owner chose not to run both), Zinn® SEO prints no
 * graph of its own — but its blocks still describe things. Those nodes go INTO that plugin's graph
 * through its own filter: Yoast SEO (`wpseo_schema_graph`), Rank Math (`rank_math/json_ld`), All in
 * One SEO (`aioseo_schema_output`) and The SEO Framework (`the_seo_framework_schema_graph_data`).
 * Only when none of them took the nodes by the footer (SEOPress has no graph to join, or the plugin
 * printed none on this page) are they printed once, alone.
 *
 * Page-level properties (`speakable`, `reviewedBy`) need the other plugin's own page node, which
 * this class does not edit; they are dropped rather than guessed.
 */
final class Handover {

	/**
	 * Hook the other plugins' filters and the fallback.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wpseo_schema_graph', array( self::class, 'yoast' ), 99 );
		add_filter( 'rank_math/json_ld', array( self::class, 'rank_math' ), 99 );
		add_filter( 'aioseo_schema_output', array( self::class, 'aioseo' ), 99 );
		add_filter( 'the_seo_framework_schema_graph_data', array( self::class, 'tsf' ), 99 );
		add_action( 'wp_footer', array( self::class, 'fallback' ), 1 );
	}

	/**
	 * The nodes to hand over, once (the collector is sealed after the first taker).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function take(): array {
		if ( Collector::sealed() ) {
			return array();
		}
		$nodes = Collector::assemble( Collector::nodes() )['nodes'];
		Collector::seal();

		return $nodes;
	}

	/**
	 * Yoast SEO: the graph is a list of pieces.
	 *
	 * @param mixed $graph Graph.
	 * @return mixed
	 */
	public static function yoast( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		return array_merge( $graph, self::take() );
	}

	/**
	 * Rank Math: the data is keyed by entity.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function rank_math( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( self::take() as $i => $node ) {
			$data[ 'zd' . $i ] = $node;
		}

		return $data;
	}

	/**
	 * All in One SEO: a list of graph nodes.
	 *
	 * @param mixed $graphs Graphs.
	 * @return mixed
	 */
	public static function aioseo( $graphs ) {
		if ( ! is_array( $graphs ) ) {
			return $graphs;
		}

		return array_merge( $graphs, self::take() );
	}

	/**
	 * The SEO Framework: the graph data.
	 *
	 * @param mixed $graph Graph.
	 * @return mixed
	 */
	public static function tsf( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		return array_merge( $graph, self::take() );
	}

	/**
	 * Nobody took them: print them once, on their own.
	 *
	 * @return void
	 */
	public static function fallback(): void {
		$nodes = self::take();
		if ( array() === $nodes ) {
			return;
		}
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		);
		echo \ZinnDigital\ZinnSEO\Head\Graph::script( $graph ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a script tag built by WordPress's own tag builder from JSON encoded at the sink with JSON_HEX_TAG | JSON_HEX_AMP.
	}
}
