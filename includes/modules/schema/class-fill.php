<?php
/**
 * Structured data details per post: FAQ, how-to steps, product details and article details, set
 * in the bulk editor (zs-764) or filled by AI across the site (zs-765).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Blocks\Html;
use ZinnDigital\ZinnSEO\Blocks\Library;
use ZinnDigital\ZinnSEO\Blocks\Render_Context;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four per-post fields, each ONE JSON record (`_zseo_schema_faq`, `_zseo_schema_howto`,
 * `_zseo_schema_product`, `_zseo_schema_article`), so the bulk history records each as one value
 * and undo puts back exactly what was there.
 *
 * Where each one goes:
 * - FAQ and how-to: shown on the page under the post's text, through the SEO blocks' own FAQ and
 *   how-to renderers (Google's rule: the questions and steps in structured data must be on the
 *   page), and joined to the page's ONE graph through `zinn_seo_page_nodes` — so the page, the
 *   offline check and the site graph all see the same. A post that already holds an FAQ or a
 *   how-to of its own (our block, a block from Yoast SEO, Rank Math or All in One SEO, a Pro panel
 *   item, hand-written JSON-LD) keeps it: the filled one is neither shown nor printed, so a page
 *   never says it twice (zs-769).
 * - Product details (brand, model, material, colour, size): added to the WooCommerce product node,
 *   only where WooCommerce gives none.
 * - Article details (summary, keywords, topics): added to the article node, only where the page
 *   gives none.
 *
 * ⛔ Cost on a visitor's page: two primed meta reads on a post that has the fields, nothing on
 * one that has none, and no query of its own.
 */
final class Fill {

	/** The kinds. */
	public const KINDS = array( 'faq', 'howto', 'product', 'article' );

	/**
	 * Each kind's per-post field (`schema_article` is taken: the article TYPE).
	 */
	public const FIELDS = array(
		'faq'     => 'schema_faq',
		'howto'   => 'schema_howto',
		'product' => 'schema_product',
		'article' => 'schema_article_data',
	);

	/** Longest stored record (bytes of JSON). */
	public const MAX = 60000;

	/** FAQ blocks of other SEO plugins (their saved block names). */
	private const OTHER_FAQ = array( 'wp:yoast/faq-block', 'wp:aioseo/faq', 'wp:rank-math/faq-block' );

	/** How-to blocks of other SEO plugins. */
	private const OTHER_HOWTO = array( 'wp:yoast/how-to-block', 'wp:rank-math/howto-block' );

	/**
	 * Answers of has_own() in this request (post, kind and content => answer).
	 *
	 * @var array<string, bool>
	 */
	private static array $own = array();

	/**
	 * The four per-post fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta(): array {
		$out = array();
		foreach ( self::KINDS as $kind ) {
			$out[ self::FIELDS[ $kind ] ] = array(
				'type'        => 'json',
				'objects'     => array( 'post' ),
				'max'         => self::MAX,
				'clean'       => static fn( array $v ) => self::clean( $kind, $v ),
				'description' => self::describe( $kind ),
			);
		}

		return $out;
	}

	/**
	 * What a field is, in words.
	 *
	 * @param string $kind Kind.
	 * @return callable
	 */
	private static function describe( string $kind ): callable {
		switch ( $kind ) {
			case 'faq':
				return static fn() => __( 'Questions and answers shown under the post and given to search engines as FAQ structured data.', 'zinn-seo' );
			case 'howto':
				return static fn() => __( 'How-to steps shown under the post and given to search engines as how-to structured data.', 'zinn-seo' );
			case 'product':
				return static fn() => __( 'Product details (brand, model, material, colour, size) added to the product\'s structured data.', 'zinn-seo' );
		}

		return static fn() => __( 'Article details (summary, keywords, topics) added to the article\'s structured data.', 'zinn-seo' );
	}

	/**
	 * The field name of a kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function field( string $kind ): string {
		return self::FIELDS[ $kind ] ?? '';
	}

	/**
	 * The kind of a field, or ''.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public static function kind_of( string $field ): string {
		$kind = array_search( $field, self::FIELDS, true );

		return false === $kind ? '' : (string) $kind;
	}

	/**
	 * A kind's name, for lists and messages.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function label( string $kind ): string {
		switch ( $kind ) {
			case 'faq':
				return __( 'FAQ', 'zinn-seo' );
			case 'howto':
				return __( 'How-to steps', 'zinn-seo' );
			case 'product':
				return __( 'Product details', 'zinn-seo' );
		}

		return __( 'Article details', 'zinn-seo' );
	}

	/**
	 * Is this a kind?
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	public static function is_kind( string $kind ): bool {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * Clean a record: keep the allowed shape, drop what is empty. An empty result means "none".
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $v    Record.
	 * @return array<string, mixed>
	 */
	public static function clean( string $kind, array $v ): array {
		switch ( $kind ) {
			case 'faq':
				$items = array();
				foreach ( self::rows( $v['items'] ?? array() ) as $row ) {
					$q = self::line( $row['question'] ?? '', 300 );
					$a = self::para( $row['answer'] ?? '', 5000 );
					if ( '' !== $q && '' !== $a ) {
						$items[] = array(
							'question' => $q,
							'answer'   => $a,
						);
					}
				}
				return array() === $items ? array() : array( 'items' => array_slice( $items, 0, 20 ) );
			case 'howto':
				$steps = array();
				foreach ( self::rows( $v['steps'] ?? array() ) as $row ) {
					$text = self::para( $row['text'] ?? '', 5000 );
					if ( '' !== $text ) {
						$steps[] = array(
							'name' => self::line( $row['name'] ?? '', 200 ),
							'text' => $text,
						);
					}
				}
				if ( array() === $steps ) {
					return array();
				}
				return array_filter(
					array(
						'name'        => self::line( $v['name'] ?? '', 200 ),
						'description' => self::para( $v['description'] ?? '', 2000 ),
						'minutes'     => max( 0, min( 525600, is_numeric( $v['minutes'] ?? null ) ? (int) $v['minutes'] : 0 ) ),
						'supplies'    => self::lines( $v['supplies'] ?? array(), 200, 30 ),
						'tools'       => self::lines( $v['tools'] ?? array(), 200, 30 ),
						'steps'       => array_slice( $steps, 0, 50 ),
					),
					static fn( $x ): bool => '' !== $x && 0 !== $x && array() !== $x
				);
			case 'product':
				return array_filter(
					array(
						'brand'    => self::line( $v['brand'] ?? '', 120 ),
						'model'    => self::line( $v['model'] ?? '', 120 ),
						'material' => self::line( $v['material'] ?? '', 120 ),
						'color'    => self::line( $v['color'] ?? '', 80 ),
						'size'     => self::line( $v['size'] ?? '', 80 ),
					),
					static fn( string $x ): bool => '' !== $x
				);
			case 'article':
				return array_filter(
					array(
						'description' => self::para( $v['description'] ?? '', 500 ),
						'keywords'    => self::lines( $v['keywords'] ?? array(), 60, 10 ),
						'about'       => self::lines( $v['about'] ?? array(), 120, 5 ),
					),
					static fn( $x ): bool => '' !== $x && array() !== $x
				);
		}

		return array();
	}

	/**
	 * Rows of a list (arrays only).
	 *
	 * @param mixed $value Value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( $value ): array {
		return array_values( array_filter( is_array( $value ) ? $value : array(), 'is_array' ) );
	}

	/**
	 * One line of plain text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Longest.
	 * @return string
	 */
	private static function line( $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $max ) : '';
	}

	/**
	 * Plain text that may hold paragraphs.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Longest.
	 * @return string
	 */
	private static function para( $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, $max ) : '';
	}

	/**
	 * Lines from a list, or from text with one per line.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Longest line.
	 * @param int   $count Most lines.
	 * @return array<int, string>
	 */
	private static function lines( $value, int $max, int $count ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n|,/', $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			$line = self::line( $item, $max );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}

		return array_slice( $out, 0, $count );
	}

	/**
	 * A post's stored record of a kind.
	 *
	 * @param int    $post_id Post.
	 * @param string $kind    Kind.
	 * @return array<string, mixed>
	 */
	public static function get( int $post_id, string $kind ): array {
		if ( ! self::is_kind( $kind ) ) {
			return array();
		}
		$raw = Meta::get_post( $post_id, self::field( $kind ) );
		$v   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		return is_array( $v ) ? $v : array();
	}

	/**
	 * Does a post already describe this kind by itself (a block, another plugin's block, a Pro
	 * panel item, hand-written JSON-LD)? Then a filled record is not shown or printed, and the AI
	 * fill leaves the post alone: the page never says it twice.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $kind `faq` or `howto` (other kinds are never duplicates).
	 * @return bool
	 */
	public static function has_own( \WP_Post $post, string $kind ): bool {
		if ( ! in_array( $kind, array( 'faq', 'howto' ), true ) ) {
			return false;
		}
		// Asked up to three times per page view (graph, styles, text): read the content once.
		$memo = $post->ID . ':' . $kind . ':' . md5( (string) $post->post_content );
		if ( ! isset( self::$own[ $memo ] ) ) {
			self::$own[ $memo ] = self::find_own( $post, $kind );
		}

		return self::$own[ $memo ];
	}

	/**
	 * Does the post describe the kind itself (uncached)?
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $kind `faq` or `howto`.
	 * @return bool
	 */
	private static function find_own( \WP_Post $post, string $kind ): bool {
		$content = (string) $post->post_content;
		foreach ( 'faq' === $kind ? self::OTHER_FAQ : self::OTHER_HOWTO as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				return true;
			}
		}
		if ( false !== stripos( $content, 'faq' === $kind ? '"FAQPage"' : '"HowTo"' ) ) {
			return true;
		}
		foreach ( array( '_zseo_schema', '_zseo_schema_import' ) as $key ) {
			$panel = (string) get_post_meta( $post->ID, $key, true );
			if ( '' !== $panel && 1 === preg_match( 'faq' === $kind ? '/"type":"(faq|FAQPage)"/i' : '/"type":"(howto|HowTo)"/i', $panel ) ) {
				return true;
			}
		}
		if ( class_exists( Library::class ) ) {
			foreach ( Library::scan( $post ) as $item ) {
				if ( $kind === $item['name'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The block that shows a kind on the page.
	 *
	 * @param string $kind `faq` or `howto`.
	 * @return \ZinnDigital\ZinnSEO\Blocks\Block|null
	 */
	private static function block( string $kind ): ?\ZinnDigital\ZinnSEO\Blocks\Block {
		return in_array( $kind, array( 'faq', 'howto' ), true ) && \ZinnDigital\ZinnSEO\Modules::enabled( 'blocks' ) && class_exists( Library::class ) ? Library::get( $kind ) : null;
	}

	/**
	 * The block values of a record (what the FAQ or how-to block is given).
	 *
	 * @param string               $kind   Kind.
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>
	 */
	public static function block_values( string $kind, array $record ): array {
		if ( 'faq' === $kind ) {
			return array(
				'items'       => (array) ( $record['items'] ?? array() ),
				'collapsible' => true,
				'level'       => 'h3',
			);
		}

		return array(
			'name'        => (string) ( $record['name'] ?? '' ),
			'description' => (string) ( $record['description'] ?? '' ),
			'minutes'     => (int) ( $record['minutes'] ?? 0 ),
			'supplies'    => (array) ( $record['supplies'] ?? array() ),
			'tools'       => (array) ( $record['tools'] ?? array() ),
			'steps'       => (array) ( $record['steps'] ?? array() ),
		);
	}

	/**
	 * The FAQ and how-to a post shows from its records (kind => block values), leaving out a kind
	 * the post already describes by itself.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, array<string, mixed>>
	 */
	public static function shown( \WP_Post $post ): array {
		$out = array();
		foreach ( array( 'faq', 'howto' ) as $kind ) {
			$record = self::get( (int) $post->ID, $kind );
			if ( array() !== $record && null !== self::block( $kind ) && ! self::has_own( $post, $kind ) ) {
				$out[ $kind ] = self::block_values( $kind, $record );
			}
		}

		return $out;
	}

	/**
	 * `zinn_seo_page_nodes`: the filled FAQ and how-to join the page's graph (the page, the
	 * offline check and the site graph all call this).
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes so far.
	 * @param \WP_Post                            $post  The page.
	 * @param string                              $url   Its address.
	 * @return array<string, array<string, mixed>>
	 */
	public static function page_nodes( $nodes, $post, $url ): array {
		$nodes = (array) $nodes;
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return $nodes;
		}
		foreach ( self::shown( $post ) as $kind => $values ) {
			foreach ( self::block_nodes( $kind, $values, $post, (string) $url ) as $key => $node ) {
				// `faq` merges with any other FAQ-like block in the collector (one FAQPage).
				$nodes[ 'faq' === $key ? 'faq' : 'fill-' . $key ] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * A kind's nodes as its block describes them.
	 *
	 * @param string               $kind   `faq` or `howto`.
	 * @param array<string, mixed> $values Block values.
	 * @param \WP_Post             $post   Post.
	 * @param string               $url    Address.
	 * @return array<string, array<string, mixed>>
	 */
	private static function block_nodes( string $kind, array $values, \WP_Post $post, string $url ): array {
		$block = self::block( $kind );
		if ( null === $block ) {
			return array();
		}
		$ctx      = Render_Context::for_post( $post, 'scan' );
		$ctx->url = '' !== $url ? $url : (string) get_permalink( $post );

		return $block->schema( Library::values( $block, $values, $ctx ), $ctx );
	}

	/**
	 * Before the head is printed (`wp`): a post with a filled FAQ or how-to loads the block's
	 * styles and shows it under its text.
	 *
	 * @return void
	 */
	public static function prepare(): void {
		$post = is_singular() ? get_queried_object() : null;
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return;
		}
		$shown = self::shown( $post );
		if ( array() === $shown ) {
			return;
		}
		foreach ( array_keys( $shown ) as $kind ) {
			$block = self::block( $kind );
			if ( null !== $block ) {
				Library::want_style( $block );
			}
		}
		add_filter( 'the_content', array( self::class, 'append' ), 12 );
	}

	/**
	 * Show the filled FAQ and how-to under the main post's text (once).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function append( $content ): string {
		static $done = false;
		$post        = get_post();
		if ( $done || ! in_the_loop() || ! is_main_query() || ! $post instanceof \WP_Post || (int) get_queried_object_id() !== (int) $post->ID ) {
			return (string) $content;
		}
		$done = true;

		return (string) $content . self::html( $post );
	}

	/**
	 * The HTML of a post's filled FAQ and how-to (its structured data is already in the graph:
	 * rendered as a preview so it is not collected twice).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function html( \WP_Post $post ): string {
		$html = '';
		foreach ( self::shown( $post ) as $kind => $values ) {
			$ctx          = Render_Context::for_post( $post );
			$ctx->preview = true;
			$html        .= Library::render( $kind, $values, $ctx );
		}

		return $html;
	}

	/**
	 * Add the filled article details to the article node (where the page gives none).
	 *
	 * @param array<string, mixed> $node Article node.
	 * @param \WP_Post             $post Post.
	 * @return array<string, mixed>
	 */
	public static function article_props( array $node, \WP_Post $post ): array {
		return self::with_article( $node, self::get( (int) $post->ID, 'article' ) );
	}

	/**
	 * An article node with a record's details, where the node has none.
	 *
	 * @param array<string, mixed> $node Article node.
	 * @param array<string, mixed> $a    Article record.
	 * @return array<string, mixed>
	 */
	public static function with_article( array $node, array $a ): array {
		if ( ! isset( $node['description'] ) && '' !== (string) ( $a['description'] ?? '' ) ) {
			$node['description'] = Html::plain( (string) $a['description'] );
		}
		if ( ! isset( $node['keywords'] ) && array() !== (array) ( $a['keywords'] ?? array() ) ) {
			$node['keywords'] = array_values( array_map( 'strval', (array) $a['keywords'] ) );
		}
		if ( ! isset( $node['about'] ) && array() !== (array) ( $a['about'] ?? array() ) ) {
			$node['about'] = array_values(
				array_map(
					static fn( $name ): array => array(
						'@type' => 'Thing',
						'name'  => (string) $name,
					),
					(array) $a['about']
				)
			);
		}

		return $node;
	}

	/**
	 * Add the filled product details to the product node (where the shop gives none).
	 *
	 * @param array<string, mixed> $node    Product node.
	 * @param int                  $post_id The product's post.
	 * @return array<string, mixed>
	 */
	public static function product_props( array $node, int $post_id ): array {
		return array() === $node ? $node : self::with_product( $node, self::get( $post_id, 'product' ) );
	}

	/**
	 * A product node with a record's details, where the node has none.
	 *
	 * @param array<string, mixed> $node Product node.
	 * @param array<string, mixed> $p    Product record.
	 * @return array<string, mixed>
	 */
	public static function with_product( array $node, array $p ): array {
		if ( ! isset( $node['brand'] ) && '' !== (string) ( $p['brand'] ?? '' ) ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => (string) $p['brand'],
			);
		}
		foreach ( array( 'model', 'material', 'color', 'size' ) as $prop ) {
			if ( ! isset( $node[ $prop ] ) && '' !== (string) ( $p[ $prop ] ?? '' ) ) {
				$node[ $prop ] = (string) $p[ $prop ];
			}
		}

		return $node;
	}

	/**
	 * Check a record before it is saved: against schema.org and Google's rich-result rules, as the
	 * structured-data check would see it on the page.
	 *
	 * `errors` stop it being saved; `warnings` and Google's status are shown with it.
	 *
	 * @param string               $kind   Kind.
	 * @param array<string, mixed> $record Record (cleaned here).
	 * @param \WP_Post             $post   Post.
	 * @return array{ok: bool, empty: bool, errors: array<int, string>, warnings: array<int, string>, feature: string, status: string, note: string, nodes: array<int, array<string, mixed>>}
	 */
	public static function check( string $kind, array $record, \WP_Post $post ): array {
		$record = self::clean( $kind, $record );
		$out    = array(
			'ok'       => true,
			'empty'    => array() === $record,
			'errors'   => array(),
			'warnings' => array(),
			'feature'  => '',
			'status'   => 'none',
			'note'     => '',
			'nodes'    => array(),
		);
		if ( array() === $record ) {
			return $out;
		}
		$url = (string) get_permalink( $post );
		if ( in_array( $kind, array( 'faq', 'howto' ), true ) ) {
			if ( null === self::block( $kind ) ) {
				$out['errors'][] = __( 'The SEO blocks feature is switched off, so this cannot be shown on the page. Switch on SEO blocks under Settings, then try again.', 'zinn-seo' );
			}
			if ( self::has_own( $post, $kind ) ) {
				$out['warnings'][] = 'faq' === $kind
					? __( 'This page already has an FAQ of its own, so this one is kept but not shown or printed: a page never says it twice.', 'zinn-seo' )
					: __( 'This page already has how-to steps of their own, so these are kept but not shown or printed: a page never says it twice.', 'zinn-seo' );
			}
			$nodes = array_values( self::block_nodes( $kind, self::block_values( $kind, $record ), $post, $url ) );
			$rules = true;
		} elseif ( 'product' === $kind ) {
			$nodes = array(
				self::with_product(
					array(
						'@type' => 'Product',
						'@id'   => $url . '#product',
						'name'  => Html::plain( get_the_title( $post ) ),
					),
					$record
				),
			);
			// Price, stock and reviews come from the shop: only these details are judged here.
			$rules = false;
			if ( 'product' !== $post->post_type ) {
				$out['errors'][] = __( 'Product details belong on a WooCommerce product.', 'zinn-seo' );
			}
		} else {
			$type  = Schema::post_types( $post )['article'];
			$nodes = array(
				self::with_article(
					array(
						'@type'    => '' !== $type ? $type : 'Article',
						'@id'      => $url . '#article',
						'headline' => Html::plain( get_the_title( $post ) ),
					),
					$record
				),
			);
			$rules = false;
			if ( '' === $type ) {
				$out['warnings'][] = __( 'This page has no article type, so these details are not printed. Choose an article type for it (or its content type) first.', 'zinn-seo' );
			}
		}
		$result = Validator::check( $nodes, array(), $rules );
		foreach ( (array) $result['nodes'] as $row ) {
			$out['errors']   = array_merge( $out['errors'], (array) $row['errors'] );
			$out['warnings'] = array_merge( $out['warnings'], (array) $row['warnings'] );
			if ( '' !== (string) $row['feature'] ) {
				$out['feature'] = (string) $row['feature'];
				$out['status']  = (string) $row['status'];
				$out['note']    = (string) $row['note'];
			}
		}
		$out['errors']   = array_values( array_unique( $out['errors'] ) );
		$out['warnings'] = array_values( array_unique( $out['warnings'] ) );
		$out['ok']       = array() === $out['errors'];
		$out['nodes']    = $nodes;

		return $out;
	}

	/**
	 * A short summary of a record for a list ("3 questions", "5 steps", "brand, colour").
	 *
	 * @param string               $kind   Kind.
	 * @param array<string, mixed> $record Record.
	 * @return string
	 */
	public static function summary( string $kind, array $record ): string {
		if ( array() === $record ) {
			return '';
		}
		switch ( $kind ) {
			case 'faq':
				$n = count( (array) ( $record['items'] ?? array() ) );
				/* translators: %d: how many questions. */
				return sprintf( _n( '%d question', '%d questions', $n, 'zinn-seo' ), $n );
			case 'howto':
				$n = count( (array) ( $record['steps'] ?? array() ) );
				/* translators: %d: how many steps. */
				return sprintf( _n( '%d step', '%d steps', $n, 'zinn-seo' ), $n );
		}

		return implode( ', ', array_keys( $record ) );
	}
}
