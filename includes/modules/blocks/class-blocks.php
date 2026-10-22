<?php
/**
 * The blocks module: wiring of the library, the adapters and the page's graph.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

use ZinnDigital\ZinnSEO\Blocks\Types\Article_Index;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the adapters for the builders that are active, prepares each page before its head prints,
 * joins the blocks' structured data to the ONE graph (or hands it to another SEO plugin's graph
 * when that plugin owns the head), and adds the article index to posts of the chosen types.
 */
final class Blocks {

	/**
	 * The module's settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array(
			'article_index_types' => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'blocks',
			),
			'blocks_off'          => array(
				'type'    => 'list',
				'default' => array(),
				'group'   => 'blocks',
			),
		);
	}

	/**
	 * The module's per-post fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		return array(
			'takeaways' => array(
				'type'        => 'text',
				'objects'     => array( 'post' ),
				'max'         => 4000,
				'description' => static fn() => __( 'The key takeaways the article index shows (one per line).', 'zinn-seo' ),
			),
			// The name of this page in a breadcrumb trail, when the trail should say something
			// shorter than the title (other SEO plugins call it the breadcrumb title; an import
			// brings theirs here).
			'crumb'     => array(
				'type'        => 'string',
				'objects'     => array( 'post', 'term' ),
				'max'         => 200,
				'description' => static fn() => __( 'The name of this page in breadcrumbs. Empty: its title.', 'zinn-seo' ),
			),
			'reviewed'  => array(
				'type'        => 'string',
				'objects'     => array( 'post' ),
				'max'         => 10,
				'pattern'     => '/^(\d{4}-\d{2}-\d{2})?$/',
				'description' => static fn() => __( 'When the content was last reviewed by an expert (YYYY-MM-DD).', 'zinn-seo' ),
			),
		);
	}

	/**
	 * The breadcrumb name on the classic editor's and the term forms.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_fields( $fields ): array {
		$fields          = (array) $fields;
		$fields['crumb'] = array(
			'label' => __( 'Name in breadcrumbs', 'zinn-seo' ),
			'kind'  => 'text',
			'help'  => __( 'Empty: the title.', 'zinn-seo' ),
		);

		return $fields;
	}

	/**
	 * Boot for a request type.
	 *
	 * @param string $context front, admin, rest, cli or cron.
	 * @return void
	 */
	public static function boot( string $context ): void {
		add_filter( 'zinn_seo_blocks', array( self::class, 'drop_switched_off' ), 99 );
		require_once ZINN_SEO_DIR . 'includes/adapters/gutenberg/class-gutenberg.php';
		require_once ZINN_SEO_DIR . 'includes/adapters/shortcode/class-shortcode.php';
		require_once ZINN_SEO_DIR . 'includes/adapters/pbs/class-pbs.php';
		\ZinnDigital\ZinnSEO\Adapters\Gutenberg::boot( $context );
		\ZinnDigital\ZinnSEO\Adapters\Shortcode::boot();
		\ZinnDigital\ZinnSEO\Adapters\Pbs::boot( $context );
		add_action(
			'elementor/loaded',
			static function () use ( $context ): void {
				require_once ZINN_SEO_DIR . 'includes/adapters/elementor/class-elementor.php';
				\ZinnDigital\ZinnSEO\Adapters\Elementor::boot( $context );
			}
		);
		if ( did_action( 'elementor/loaded' ) ) {
			require_once ZINN_SEO_DIR . 'includes/adapters/elementor/class-elementor.php';
			\ZinnDigital\ZinnSEO\Adapters\Elementor::boot( $context );
		}

		if ( 'front' === $context ) {
			Headings::register();
			add_action( 'wp', array( Library::class, 'prepare' ), 20 );
			// Other SEO plugins' FAQ and How-to blocks, after a move from them (queue Q254).
			Foreign::boot();
			add_action( 'wp', array( self::class, 'prepare_index' ), 21 );
			add_filter( 'zinn_seo_graph', array( self::class, 'join_graph' ), 20, 2 );
			add_action( 'wp', array( self::class, 'maybe_hand_over' ), 22 );
		}
		if ( 'admin' === $context ) {
			Assets::register();
			add_filter( 'zinn_seo_admin_data', array( self::class, 'admin_data' ) );
		}
		if ( 'front' !== $context ) {
			add_filter( 'zinn_seo_form_fields', array( self::class, 'form_fields' ), 20 );
		}
		if ( in_array( $context, array( 'admin', 'rest', 'cli' ), true ) ) {
			require_once __DIR__ . '/class-blocks-rest.php';
			Blocks_Rest::init();
		}
	}

	/**
	 * What the Blocks screen needs: which builders are active, content types, AI available.
	 *
	 * @param array<string, mixed> $data Boot data.
	 * @return array<string, mixed>
	 */
	public static function admin_data( $data ): array {
		$data             = (array) $data;
		$data['builders'] = array(
			'pbs'       => function_exists( 'pbsw_register_block' ),
			'elementor' => did_action( 'elementor/loaded' ) > 0,
		);
		$data['ai']       = (bool) apply_filters( 'zinn_seo_ai_available', false );
		if ( ! isset( $data['schema']['postTypes'] ) ) {
			$types = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
				if ( 'attachment' !== $type->name ) {
					$types[] = array(
						'name'     => $type->name,
						'label'    => Html::plain( $type->labels->name ?? $type->label ),
						'defaults' => array(
							'page'    => '',
							'article' => '',
						),
					);
				}
			}
			$data['schema']['postTypes'] = $types;
		}

		return $data;
	}

	/**
	 * Leave out the blocks the site switched off (Blocks screen).
	 *
	 * @param array<int, Block> $blocks Blocks.
	 * @return array<int, Block>
	 */
	public static function drop_switched_off( $blocks ): array {
		$off = (array) Settings::get( 'blocks_off', array() );

		return array_values(
			array_filter(
				(array) $blocks,
				static fn( $b ): bool => $b instanceof Block && ! in_array( $b->name(), $off, true )
			)
		);
	}

	/**
	 * Join the blocks' nodes to the page's graph (our own head).
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function join_graph( $nodes ): array {
		$nodes = (array) $nodes;
		$parts = Collector::assemble( Collector::nodes() );
		if ( array() !== $parts['page'] && isset( $nodes['webpage'] ) && is_array( $nodes['webpage'] ) ) {
			$nodes['webpage'] = Collector::merge_page( $nodes['webpage'], $parts['page'] );
		}
		// One node per `@id`: a person the page already describes (the article's author) is
		// completed by what the blocks know, not described twice.
		$by_id = array();
		foreach ( $nodes as $key => $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) ) {
				$by_id[ (string) $node['@id'] ] = $key;
			}
		}
		foreach ( $parts['nodes'] as $i => $node ) {
			$id = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			if ( '' !== $id && isset( $by_id[ $id ] ) ) {
				$nodes[ $by_id[ $id ] ] = array_merge( $node, (array) $nodes[ $by_id[ $id ] ] );
				continue;
			}
			$nodes[ 'block-' . $i ] = $node;
		}
		if ( doing_action( 'wp_head' ) ) {
			Collector::seal();
		}

		return $nodes;
	}

	/**
	 * When another SEO plugin owns the head, hand the blocks' nodes to its graph.
	 *
	 * @return void
	 */
	public static function maybe_hand_over(): void {
		if ( Library::we_print_the_graph() ) {
			return;
		}
		require_once __DIR__ . '/class-handover.php';
		Handover::register();
	}

	/**
	 * The article index on posts of the chosen types (Blocks → Article index), with no editing.
	 *
	 * @return void
	 */
	public static function prepare_index(): void {
		$types = (array) Settings::get( 'article_index_types', array() );
		$post  = is_singular() ? get_queried_object() : null;
		if ( array() === $types || ! $post instanceof \WP_Post || ! in_array( $post->post_type, $types, true ) || post_password_required( $post ) ) {
			return;
		}
		foreach ( Library::scan( $post ) as $item ) {
			if ( 'article-index' === $item['name'] ) {
				return; // Placed by hand: that one wins.
			}
		}
		$block = Library::get( 'article-index' );
		if ( null === $block ) {
			return;
		}
		Library::want_style( $block );
		add_filter( 'the_content', array( self::class, 'insert_index' ), 12 );
	}

	/**
	 * Put the index above the post's content (the main post, once).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function insert_index( $content ): string {
		static $done = false;
		$post        = get_post();
		if ( $done || ! in_the_loop() || ! is_main_query() || ! $post instanceof \WP_Post || (int) get_queried_object_id() !== (int) $post->ID ) {
			return (string) $content;
		}
		$done = true;
		$ctx  = Render_Context::for_post( $post );

		return Library::render( 'article-index', array(), $ctx ) . (string) $content;
	}

	/**
	 * The editor data for one post: the takeaways and review date the index shows.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function post_data( \WP_Post $post ): array {
		return array(
			'takeaways' => Article_Index::post_takeaways( $post ),
			'reviewed'  => (string) get_post_meta( $post->ID, Article_Index::META_REVIEWED, true ),
		);
	}
}
