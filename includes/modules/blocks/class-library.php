<?php
/**
 * The block library: every SEO block, one renderer, every builder (zs-300).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

use ZinnDigital\ZinnSEO\Head\Head;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the blocks, renders them for every adapter through ONE function (`render()`), and
 * prepares the page before its head is printed: the post is scanned for blocks from every
 * builder so their stylesheets go into the head and their structured data into the graph.
 *
 * ⛔ Cost on a page WITHOUT blocks: one `strpos()` per scanned text; no query, no parse.
 */
final class Library {

	/**
	 * Blocks by short name.
	 *
	 * @var array<string, Block>|null
	 */
	private static ?array $blocks = null;

	/**
	 * Instance counters per block on this page.
	 *
	 * @var array<string, int>
	 */
	private static array $counter = array();

	/**
	 * Blocks found by the scan, waiting for their stylesheets to be enqueued.
	 *
	 * @var array<string, Block>
	 */
	private static array $styles = array();

	/**
	 * Every block: the free ones in `blocks/`, plus what Pro and add-ons register.
	 *
	 * @return array<string, Block>
	 */
	public static function all(): array {
		if ( null !== self::$blocks ) {
			return self::$blocks;
		}
		$list = array();
		foreach ( (array) glob( __DIR__ . '/blocks/class-*.php' ) as $file ) {
			require_once (string) $file;
		}
		foreach ( self::free_classes() as $class ) {
			$list[] = new $class();
		}

		/**
		 * Filters the SEO blocks. Each item is a Block; Pro adds its blocks and the site's custom
		 * blocks here, and they appear in every builder with no more code.
		 *
		 * @param array<int, Block> $list Blocks.
		 */
		$list = (array) apply_filters( 'zinn_seo_blocks', $list );
		$out  = array();
		foreach ( $list as $block ) {
			if ( $block instanceof Block && 1 === preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $block->name() ) ) {
				$out[ $block->name() ] = $block;
			}
		}
		self::$blocks = $out;

		return self::$blocks;
	}

	/**
	 * The free blocks' classes, in inserter order.
	 *
	 * @return array<int, class-string<Block>>
	 */
	public static function free_classes(): array {
		$ns = __NAMESPACE__ . '\\Types\\';

		return array(
			$ns . 'Quick_Answer',
			$ns . 'Article_Index',
			$ns . 'Toc',
			$ns . 'Takeaways',
			$ns . 'Faq',
			$ns . 'Howto',
			$ns . 'Pros_Cons',
			$ns . 'Review',
			$ns . 'Author_Box',
			$ns . 'Related',
			$ns . 'Local_Business',
			$ns . 'Breadcrumbs',
			$ns . 'Html_Sitemap',
		);
	}

	/**
	 * One block.
	 *
	 * @param string $name Short name or `zinn-seo/<name>`.
	 * @return Block|null
	 */
	public static function get( string $name ): ?Block {
		$name  = 0 === strpos( $name, 'zinn-seo/' ) ? substr( $name, 9 ) : $name;
		$found = self::all()[ $name ] ?? null;
		if ( null === $found && 1 === preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $name ) ) {
			/**
			 * Filters a block the list does not hold yet (Pro loads a custom block's definition
			 * only when a page uses it, so other pages read nothing).
			 *
			 * @param Block|null $found Block.
			 * @param string     $name  Short name.
			 */
			$found = apply_filters( 'zinn_seo_block_lookup', null, $name );
			if ( $found instanceof Block && $found->name() === $name ) {
				self::$blocks[ $name ] = $found;
			} else {
				$found = null;
			}
		}

		return $found;
	}

	/**
	 * Forget the per-request state (tests, and between pages computed in one request).
	 *
	 * @param bool $blocks Also forget the list of blocks.
	 * @return void
	 */
	public static function flush( bool $blocks = false ): void {
		self::$counter = array();
		self::$styles  = array();
		if ( $blocks ) {
			self::$blocks = null;
		}
	}

	/**
	 * Render a block for any builder. THE one renderer.
	 *
	 * @param string               $name Block name.
	 * @param array<string, mixed> $raw  Values as the builder stores them.
	 * @param Render_Context       $ctx  Where.
	 * @return string
	 */
	public static function render( string $name, array $raw, Render_Context $ctx ): string {
		$block = self::get( $name );
		if ( null === $block ) {
			return '';
		}
		$values        = self::values( $block, $raw, $ctx );
		$ctx->instance = self::next( $block->name() );
		$html          = $block->render( $values, $ctx );
		if ( '' === $html || $ctx->preview ) {
			return $html;
		}
		Assets::enqueue( $block );
		Collector::add_instance( self::identity( $block, $values ), $block->schema( $values, $ctx ) );

		return $html;
	}

	/**
	 * Clean a builder's values (and let add-ons adjust them).
	 *
	 * @param Block                $block Block.
	 * @param array<string, mixed> $raw   Raw values.
	 * @param Render_Context       $ctx   Where.
	 * @return array<string, mixed>
	 */
	public static function values( Block $block, array $raw, Render_Context $ctx ): array {
		$values = Fields::clean( $block->fields(), $raw );

		/**
		 * Filters a block's cleaned values before it renders (every builder).
		 *
		 * @param array<string, mixed> $values Values.
		 * @param string               $name   Block name.
		 * @param Render_Context       $ctx    Where.
		 */
		return (array) apply_filters( 'zinn_seo_block_values', $values, $block->name(), $ctx );
	}

	/**
	 * The identity of an instance: the same block with the same values is the same instance,
	 * whichever way it was found.
	 *
	 * @param Block                $block  Block.
	 * @param array<string, mixed> $values Cleaned values.
	 * @return string
	 */
	public static function identity( Block $block, array $values ): string {
		return $block->name() . ':' . md5( (string) wp_json_encode( $values ) );
	}

	/**
	 * The next instance number of a block on this page.
	 *
	 * @param string $name Block name.
	 * @return int
	 */
	private static function next( string $name ): int {
		self::$counter[ $name ] = ( self::$counter[ $name ] ?? 0 ) + 1;

		return self::$counter[ $name ];
	}

	/**
	 * Every SEO block a post holds, in document order, from every builder:
	 * `[ [ 'name' => …, 'values' => […], 'builder' => … ], … ]`.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array{name: string, values: array<string, mixed>, builder: string}>
	 */
	public static function scan( \WP_Post $post ): array {
		/**
		 * Filters the SEO blocks found in a post. Each adapter adds what its builder stores
		 * (block markup, shortcodes, Elementor data).
		 *
		 * @param array<int, array<string, mixed>> $found Found blocks.
		 * @param \WP_Post                         $post  Post.
		 */
		$found = (array) apply_filters( 'zinn_seo_block_scan', array(), $post );
		$out   = array();
		foreach ( $found as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) && null !== self::get( (string) $item['name'] ) ) {
				$out[] = array(
					'name'    => (string) $item['name'],
					'values'  => (array) ( $item['values'] ?? array() ),
					'builder' => (string) ( $item['builder'] ?? 'scan' ),
				);
			}
		}

		return $out;
	}

	/**
	 * The structured data a post's blocks describe (the validator and REST use this; the page
	 * itself goes through prepare()).
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  The page address.
	 * @return array<string, array<string, mixed>>
	 */
	public static function nodes_for( \WP_Post $post, string $url ): array {
		$nodes = array();
		$count = array();
		foreach ( self::scan( $post ) as $item ) {
			$block = self::get( $item['name'] );
			if ( null === $block ) {
				continue;
			}
			$ctx                     = Render_Context::for_post( $post, $item['builder'] );
			$ctx->url                = $url;
			$count[ $block->name() ] = ( $count[ $block->name() ] ?? 0 ) + 1;
			$ctx->instance           = $count[ $block->name() ];
			$values                  = self::values( $block, $item['values'], $ctx );
			foreach ( $block->schema( $values, $ctx ) as $key => $node ) {
				$nodes[] = array( (string) $key, $node );
			}
		}
		foreach ( self::page_nodes( $post, $url ) as $key => $node ) {
			$nodes[] = array( (string) $key, $node );
		}
		if ( false !== strpos( (string) $post->post_content, '<!-- wp:' ) ) {
			foreach ( Foreign::nodes( parse_blocks( (string) $post->post_content ), $url, Html::plain( get_the_title( $post ) ) ) as $key => $node ) {
				$nodes[] = array( 0 === strpos( (string) $key, 'faq' ) ? 'faq' : 'howto', $node );
			}
		}
		Collector::reset();
		foreach ( $nodes as $pair ) {
			Collector::add_node( $pair[0], $pair[1] );
		}
		$out = Collector::nodes();
		Collector::reset();

		return $out;
	}

	/**
	 * Nodes a page has without a block: what the page's own Structured data panel adds and what
	 * the schema rules give it (Pro), and properties of the page itself (`@page`).
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  The page address.
	 * @return array<string, array<string, mixed>>
	 */
	public static function page_nodes( \WP_Post $post, string $url ): array {
		/**
		 * Filters the nodes a page adds to its graph besides its blocks: local key => node
		 * (`@page` = properties of the page node itself).
		 *
		 * @param array<string, array<string, mixed>> $nodes Nodes.
		 * @param \WP_Post                            $post  The page.
		 * @param string                              $url   Its address.
		 */
		return (array) apply_filters( 'zinn_seo_page_nodes', array(), $post, $url );
	}

	/**
	 * Before the head is printed (`wp`): scan the page being viewed, so the stylesheets of the
	 * blocks it holds go into the head and their structured data into the graph.
	 *
	 * @return void
	 */
	public static function prepare(): void {
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && ! post_password_required( $post ) ) {
			$url   = (string) get_permalink( $post );
			$count = array();
			foreach ( self::scan( $post ) as $item ) {
				$block = self::get( $item['name'] );
				if ( null === $block ) {
					continue;
				}
				$ctx                            = Render_Context::for_post( $post, $item['builder'] );
				$ctx->url                       = $url;
				$count[ $block->name() ]        = ( $count[ $block->name() ] ?? 0 ) + 1;
				$ctx->instance                  = $count[ $block->name() ];
				$values                         = self::values( $block, $item['values'], $ctx );
				self::$styles[ $block->name() ] = $block;
				Collector::add_instance( self::identity( $block, $values ), $block->schema( $values, $ctx ) );
			}
			$extra = self::page_nodes( $post, $url );
			if ( array() !== $extra ) {
				Collector::add_instance( 'page:' . $post->ID, $extra );
			}
		}
		if ( array() !== self::$styles ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_found' ), 20 );
		}
	}

	/**
	 * A block the page will show although the scan did not find it (the auto-inserted index).
	 *
	 * @param Block $block Block.
	 * @return void
	 */
	public static function want_style( Block $block ): void {
		$had                            = array() !== self::$styles;
		self::$styles[ $block->name() ] = $block;
		if ( ! $had ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_found' ), 20 );
		}
	}

	/**
	 * Enqueue the stylesheets of the blocks the scan found.
	 *
	 * @return void
	 */
	public static function enqueue_found(): void {
		Assets::enqueue_set( array_values( self::$styles ) );
	}

	/**
	 * Does this site print our graph (or does another SEO plugin own the head)?
	 *
	 * @return bool
	 */
	public static function we_print_the_graph(): bool {
		return class_exists( Head::class ) && Head::should_print();
	}
}
