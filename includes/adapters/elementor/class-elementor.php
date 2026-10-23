<?php
/**
 * Elementor adapter: the SEO blocks as widgets, and the SEO fields, preview, dynamic tags and
 * templates inside Elementor (zs-300, zs-313).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Adapters;

use ZinnDigital\ZinnSEO\Blocks\Block;
use ZinnDigital\ZinnSEO\Blocks\Library;
use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only after Elementor (`elementor/loaded`).
 *
 * - Widgets: one per SEO block in the "SEO" panel category, controls generated from the block's
 *   fields, output from the one renderer; the page scan reads Elementor's saved data so the
 *   blocks' structured data and styles are known before the head prints.
 * - Page settings → "SEO": the title, description, robots and canonical address of the page,
 *   with a live search-result preview, read from and saved to the same fields as everywhere else.
 *   On Theme Builder templates (headers, singles, archives) it says where those pages' SEO comes
 *   from instead (Search appearance → titles for each kind of page), since a template is not a page.
 * - Dynamic tags "SEO title", "Meta description" and "Breadcrumbs", so any widget can show them.
 * - The analysis reads what Elementor renders (`zinn_seo_rendered_content`), not the empty
 *   `post_content` an Elementor page has.
 */
final class Elementor {

	/** Widget name prefix and panel category. */
	public const PREFIX   = 'zinn-seo-';
	public const CATEGORY = 'zinn-seo';

	/** Page-settings control ids => our field names. */
	public const DOC_FIELDS = array(
		'zseo_title'     => 'title',
		'zseo_desc'      => 'desc',
		'zseo_canonical' => 'canonical',
	);

	/**
	 * Hook in.
	 *
	 * @param string $context Request type.
	 * @return void
	 */
	public static function boot( string $context ): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_action( 'elementor/elements/categories_registered', array( self::class, 'category' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'widgets' ) );
		add_action( 'elementor/dynamic_tags/register', array( self::class, 'tags' ) );
		add_filter( 'zinn_seo_block_scan', array( self::class, 'scan' ), 30, 2 );
		add_filter( 'zinn_seo_rendered_content', array( self::class, 'rendered' ), 10, 2 );
		if ( 'front' !== $context ) {
			add_action( 'elementor/documents/register_controls', array( self::class, 'document_controls' ) );
			add_action( 'elementor/document/after_save', array( self::class, 'document_saved' ), 10, 2 );
			add_action( 'elementor/editor/after_enqueue_scripts', array( self::class, 'editor_script' ) );
		}
	}

	/**
	 * The "SEO" category in the widget panel.
	 *
	 * @param object $manager Elements manager.
	 * @return void
	 */
	public static function category( $manager ): void {
		if ( is_object( $manager ) && method_exists( $manager, 'add_category' ) ) {
			$manager->add_category(
				self::CATEGORY,
				array(
					'title' => __( 'SEO', 'zinn-seo' ),
					'icon'  => 'eicon-search',
				)
			);
		}
	}

	/**
	 * Register a widget per block.
	 *
	 * @param object $manager Widgets manager.
	 * @return void
	 */
	public static function widgets( $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'register' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		// Elementor's base classes exist from here on, not at `elementor/loaded`.
		require_once __DIR__ . '/class-widget.php';
		foreach ( Library::all() as $block ) {
			$manager->register( new Elementor\Widget( array(), array( 'zseo_block' => $block->name() ) ) );
		}
	}

	/**
	 * An Elementor icon for a Dashicons name.
	 *
	 * @param string $dashicon Dashicons name.
	 * @return string
	 */
	public static function icon( string $dashicon ): string {
		$map = array(
			'format-chat'      => 'eicon-commenting-o',
			'editor-help'      => 'eicon-help-o',
			'list-view'        => 'eicon-bullet-list',
			'yes-alt'          => 'eicon-check-circle-o',
			'index-card'       => 'eicon-table-of-contents',
			'thumbs-up'        => 'eicon-thumbs-up',
			'star-filled'      => 'eicon-rating',
			'admin-users'      => 'eicon-person',
			'admin-links'      => 'eicon-link',
			'location'         => 'eicon-map-pin',
			'arrow-right-alt2' => 'eicon-chevron-double-right',
			'networking'       => 'eicon-sitemap',
			'columns'          => 'eicon-table',
			'shield'           => 'eicon-check-circle',
			'book-alt'         => 'eicon-library-open',
			'video-alt3'       => 'eicon-youtube',
			'admin-customizer' => 'eicon-apps',
		);

		return $map[ $dashicon ] ?? 'eicon-search';
	}

	/**
	 * An Elementor control for a block field.
	 *
	 * @param array<string, mixed> $def Field definition.
	 * @return array<string, mixed>
	 */
	public static function control( array $def ): array {
		$cm      = '\Elementor\Controls_Manager';
		$label   = \ZinnDigital\ZinnSEO\Blocks\Fields::label( $def['label'] ?? '' );
		$default = \ZinnDigital\ZinnSEO\Blocks\Fields::default_of( $def );
		$out     = array( 'label' => $label );
		if ( isset( $def['help'] ) ) {
			$out['description'] = \ZinnDigital\ZinnSEO\Blocks\Fields::label( $def['help'] );
		}
		switch ( (string) $def['type'] ) {
			case 'textarea':
				$out['type']    = constant( $cm . '::TEXTAREA' );
				$out['default'] = $default;
				$out['rows']    = 5;
				$out['dynamic'] = array( 'active' => true );
				break;
			case 'number':
				$out['type']    = constant( $cm . '::NUMBER' );
				$out['default'] = $default;
				foreach ( array( 'min', 'max', 'step' ) as $k ) {
					if ( isset( $def[ $k ] ) ) {
						$out[ $k ] = $def[ $k ];
					}
				}
				break;
			case 'bool':
				$out['type']         = constant( $cm . '::SWITCHER' );
				$out['default']      = $default ? 'yes' : '';
				$out['return_value'] = 'yes';
				break;
			case 'select':
				$out['type']    = constant( $cm . '::SELECT' );
				$out['default'] = (string) $default;
				$out['options'] = array();
				foreach ( (array) ( $def['choices'] ?? array() ) as $value => $choice ) {
					$out['options'][ (string) $value ] = \ZinnDigital\ZinnSEO\Blocks\Fields::label( $choice );
				}
				break;
			case 'url':
				$out['type']    = constant( $cm . '::URL' );
				$out['default'] = array( 'url' => (string) $default );
				$out['dynamic'] = array( 'active' => true );
				break;
			case 'image':
				$out['type'] = constant( $cm . '::MEDIA' );
				break;
			case 'date':
				$out['type']           = constant( $cm . '::DATE_TIME' );
				$out['picker_options'] = array( 'enableTime' => false );
				break;
			case 'user':
				$out['type']    = constant( $cm . '::SELECT' );
				$out['default'] = '';
				$out['options'] = self::user_options();
				break;
			case 'list':
				$out['type']        = constant( $cm . '::TEXTAREA' );
				$out['rows']        = 5;
				$out['default']     = implode( "\n", (array) $default );
				$out['description'] = __( 'One per line.', 'zinn-seo' );
				break;
			case 'repeater':
				$out['type']   = constant( $cm . '::REPEATER' );
				$out['fields'] = array();
				$first         = '';
				foreach ( (array) ( $def['fields'] ?? array() ) as $key => $sub ) {
					$control         = self::control( (array) $sub );
					$control['name'] = (string) $key;
					$out['fields'][] = $control;
					if ( '' === $first && in_array( $sub['type'], array( 'text', 'select' ), true ) ) {
						$first = (string) $key;
					}
				}
				if ( '' !== $first ) {
					$out['title_field'] = '{{{ ' . $first . ' }}}';
				}
				break;
			default:
				$out['type']        = constant( $cm . '::TEXT' );
				$out['default']     = (string) $default;
				$out['label_block'] = true;
				$out['dynamic']     = array( 'active' => true );
				if ( isset( $def['placeholder'] ) ) {
					$out['placeholder'] = \ZinnDigital\ZinnSEO\Blocks\Fields::label( $def['placeholder'] );
				}
		}

		return $out;
	}

	/**
	 * People who can write, for the user fields (read only while Elementor's editor is open).
	 *
	 * @return array<string, string>
	 */
	private static function user_options(): array {
		$out = array( '' => __( 'Default', 'zinn-seo' ) );
		if ( ! is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $out; // A visitor's page needs the stored value, not the list.
		}
		foreach ( get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => 200,
				'fields'     => array( 'ID', 'display_name' ),
				'orderby'    => 'display_name',
			)
		) as $u ) {
			$out[ (string) $u->ID ] = (string) $u->display_name;
		}

		return $out;
	}

	/**
	 * A widget's settings as the block's raw values.
	 *
	 * @param Block                $block    Block.
	 * @param array<string, mixed> $settings Elementor settings.
	 * @return array<string, mixed>
	 */
	public static function values( Block $block, array $settings ): array {
		$out = array();
		foreach ( $block->fields() as $key => $def ) {
			if ( array_key_exists( (string) $key, $settings ) ) {
				$out[ $key ] = self::value( (array) $def, $settings[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * One Elementor value as the field's raw value.
	 *
	 * @param array<string, mixed> $def   Field.
	 * @param mixed                $value Elementor's value.
	 * @return mixed
	 */
	private static function value( array $def, $value ) {
		switch ( (string) $def['type'] ) {
			case 'bool':
				return 'yes' === $value || true === $value;
			case 'url':
				return is_array( $value ) ? (string) ( $value['url'] ?? '' ) : (string) $value;
			case 'image':
				return is_array( $value ) ? (int) ( $value['id'] ?? 0 ) : (int) $value;
			case 'repeater':
				$rows = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean = array();
					foreach ( (array) ( $def['fields'] ?? array() ) as $key => $sub ) {
						if ( array_key_exists( (string) $key, $row ) ) {
							$clean[ $key ] = self::value( (array) $sub, $row[ $key ] );
						}
					}
					$rows[] = $clean;
				}
				return $rows;
		}

		return $value;
	}

	/**
	 * The SEO widgets in an Elementor page's saved data.
	 *
	 * @param array<int, array<string, mixed>> $found Found so far.
	 * @param \WP_Post                         $post  Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( $found, $post ): array {
		$found = (array) $found;
		if ( ! $post instanceof \WP_Post || 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return $found;
		}
		$raw = get_post_meta( $post->ID, '_elementor_data', true );
		if ( ! is_string( $raw ) || false === strpos( $raw, self::PREFIX ) ) {
			return $found;
		}
		$data = json_decode( $raw, true );

		return array_merge( $found, self::walk( is_array( $data ) ? $data : array() ) );
	}

	/**
	 * Walk Elementor's element tree.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function walk( array $elements ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type = (string) ( $el['widgetType'] ?? '' );
			if ( 'widget' === ( $el['elType'] ?? '' ) && 0 === strpos( $type, self::PREFIX ) ) {
				$block = Library::get( substr( $type, strlen( self::PREFIX ) ) );
				if ( null !== $block ) {
					// Elementor stores only what differs from a control's default; the controls'
					// defaults ARE the fields' defaults, so cleaning fills the rest the same way.
					$settings = (array) ( $el['settings'] ?? array() );
					$out[]    = array(
						'name'    => $block->name(),
						'values'  => self::values( $block, $settings ),
						'builder' => 'elementor',
					);
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$out = array_merge( $out, self::walk( $el['elements'] ) );
			}
		}

		return $out;
	}

	/**
	 * What Elementor renders for a post (the analysis reads this, zs-313).
	 *
	 * @param string   $content Content so far.
	 * @param \WP_Post $post    Post.
	 * @return string
	 */
	public static function rendered( $content, $post ): string {
		if ( ! $post instanceof \WP_Post || 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return (string) $content;
		}
		$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID, true );

		return is_string( $html ) && '' !== $html ? $html : (string) $content;
	}

	/**
	 * Page settings → SEO (pages), or the note for Theme Builder templates.
	 *
	 * @param object $document Elementor document.
	 * @return void
	 */
	public static function document_controls( $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! method_exists( $document, 'start_controls_section' ) ) {
			return;
		}
		$post_id = (int) $document->get_main_id();
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$cm = '\Elementor\Controls_Manager';
		$document->start_controls_section(
			'zseo_section',
			array(
				'label' => __( 'SEO', 'zinn-seo' ),
				'tab'   => constant( $cm . '::TAB_SETTINGS' ),
			)
		);
		if ( 'elementor_library' === $post->post_type ) {
			$document->add_control(
				'zseo_template_note',
				array(
					'type' => constant( $cm . '::RAW_HTML' ),
					'raw'  => '<p>' . esc_html__( 'A template is not a page of its own: the pages that use it take their titles and descriptions from Search appearance, by kind of page (posts, archives, categories and so on).', 'zinn-seo' ) . '</p><p><a target="_blank" rel="noopener" href="' . esc_url( admin_url( 'admin.php?page=zinn-seo#/appearance' ) ) . '">' . esc_html__( 'Open Search appearance', 'zinn-seo' ) . '</a></p>',
				)
			);
			$document->end_controls_section();
			return;
		}
		if ( ! Capabilities::can_edit_post( $post_id ) ) {
			$document->end_controls_section();
			return;
		}
		$document->add_control(
			'zseo_preview',
			array(
				'type'            => constant( $cm . '::RAW_HTML' ),
				'raw'             => '<div class="zseo-el-preview" data-post="' . esc_attr( (string) $post_id ) . '"></div>',
				'content_classes' => 'zseo-el-preview-wrap',
			)
		);
		if ( \ZinnDigital\ZinnSEO\Modules::enabled( 'copywriter' ) && current_user_can( 'edit_posts' ) ) {
			// zs-782: the AI copywriter, drawn by the Elementor bundle; "Insert" adds a Text Editor
			// widget with the chosen version at the end of the page.
			$document->add_control(
				'zseo_copywriter',
				array(
					'type'            => constant( $cm . '::RAW_HTML' ),
					'raw'             => '<div class="zseo-el-copywriter" data-post="' . esc_attr( (string) $post_id ) . '"></div>',
					'content_classes' => 'zseo-el-copywriter-wrap',
				)
			);
		}
		if ( \ZinnDigital\ZinnSEO\Modules::enabled( 'analysis' ) ) {
			// The content analysis (stage P4): drawn by the Elementor bundle, measured on what
			// Elementor renders, re-checked after each save.
			$document->add_control(
				'zseo_analysis',
				array(
					'type'            => constant( $cm . '::RAW_HTML' ),
					'raw'             => '<div class="zseo-el-analysis" data-post="' . esc_attr( (string) $post_id ) . '"></div>',
					'content_classes' => 'zseo-el-analysis-wrap',
				)
			);
		}
		$document->add_control(
			'zseo_title',
			array(
				'label'       => __( 'SEO title', 'zinn-seo' ),
				'type'        => constant( $cm . '::TEXT' ),
				'ai'          => array( 'active' => false ),
				'label_block' => true,
				'default'     => (string) Meta::get_post( $post_id, 'title' ),
				'placeholder' => __( 'Empty: the template for this kind of page', 'zinn-seo' ),
			)
		);
		$document->add_control(
			'zseo_desc',
			array(
				'label'       => __( 'Meta description', 'zinn-seo' ),
				'type'        => constant( $cm . '::TEXTAREA' ),
				'ai'          => array( 'active' => false ),
				'rows'        => 4,
				'default'     => (string) Meta::get_post( $post_id, 'desc' ),
				'placeholder' => __( 'Empty: the template for this kind of page', 'zinn-seo' ),
			)
		);
		$robots = (array) Meta::get_post( $post_id, 'robots' );
		$document->add_control(
			'zseo_noindex',
			array(
				'label'        => __( 'Hide from search engines (noindex)', 'zinn-seo' ),
				'type'         => constant( $cm . '::SWITCHER' ),
				'return_value' => 'yes',
				'default'      => in_array( 'noindex', $robots, true ) ? 'yes' : '',
			)
		);
		$document->add_control(
			'zseo_canonical',
			array(
				'label'       => __( 'Canonical address', 'zinn-seo' ),
				'type'        => constant( $cm . '::TEXT' ),
				'ai'          => array( 'active' => false ),
				'label_block' => true,
				'default'     => (string) Meta::get_post( $post_id, 'canonical' ),
				'placeholder' => __( 'Empty: this page\'s own address', 'zinn-seo' ),
			)
		);
		$document->add_control(
			'zseo_more',
			array(
				'type' => constant( $cm . '::RAW_HTML' ),
				'raw'  => '<p><a target="_blank" rel="noopener" href="' . esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ) . '">' . esc_html__( 'All SEO and social fields (block editor)', 'zinn-seo' ) . '</a></p>',
			)
		);
		$document->end_controls_section();
	}

	/**
	 * Save the SEO page settings into the plugin's own fields.
	 *
	 * @param object               $document Document.
	 * @param array<string, mixed> $data     Saved data.
	 * @return void
	 */
	public static function document_saved( $document, $data ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}
		$post_id  = (int) $document->get_main_id();
		$settings = (array) ( $data['settings'] ?? array() );
		if ( $post_id <= 0 || array() === $settings || ! Capabilities::can_edit_post( $post_id ) ) {
			return;
		}
		foreach ( self::DOC_FIELDS as $control => $field ) {
			if ( array_key_exists( $control, $settings ) ) {
				Meta::set( 'post', $post_id, $field, (string) $settings[ $control ] );
			}
		}
		if ( array_key_exists( 'zseo_noindex', $settings ) ) {
			$robots = array_values( array_diff( (array) Meta::get_post( $post_id, 'robots' ), array( 'noindex', 'index' ) ) );
			if ( 'yes' === $settings['zseo_noindex'] ) {
				$robots[] = 'noindex';
			}
			Meta::set( 'post', $post_id, 'robots', $robots );
		}
	}

	/**
	 * The live preview script in Elementor's editor.
	 *
	 * @return void
	 */
	public static function editor_script(): void {
		$asset_file = ZINN_SEO_DIR . 'build/elementor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		\ZinnDigital\ZinnSEO\Admin\Admin::register_jsx_runtime();
		wp_enqueue_script( 'zinn-seo-elementor', ZINN_SEO_URL . 'build/elementor.js', (array) ( $asset['dependencies'] ?? array() ), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ), true );
		wp_set_script_translations( 'zinn-seo-elementor', 'zinn-seo', ZINN_SEO_DIR . 'languages' );
		if ( class_exists( '\ZinnDigital\ZinnSEO\Head\Editor' ) ) {
			// The analysis panel reads the same boot data as the block editor's.
			wp_add_inline_script( 'zinn-seo-elementor', 'window.zinnSeoEditor = ' . wp_json_encode( \ZinnDigital\ZinnSEO\Head\Editor::editor_data() ) . ';', 'before' );
		}
		wp_enqueue_style( 'wp-components' );
		if ( is_readable( ZINN_SEO_DIR . 'build/elementor.css' ) ) {
			wp_enqueue_style( 'zinn-seo-elementor', ZINN_SEO_URL . 'build/elementor.css', array(), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
			wp_style_add_data( 'zinn-seo-elementor', 'rtl', 'replace' );
		}
	}

	/**
	 * Dynamic tags.
	 *
	 * @param object $tags Dynamic tags manager.
	 * @return void
	 */
	public static function tags( $tags ): void {
		if ( ! is_object( $tags ) || ! method_exists( $tags, 'register' ) || ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
			return;
		}
		require_once __DIR__ . '/class-tag-title.php';
		require_once __DIR__ . '/class-tag-description.php';
		require_once __DIR__ . '/class-tag-breadcrumbs.php';
		if ( method_exists( $tags, 'register_group' ) ) {
			$tags->register_group( self::CATEGORY, array( 'title' => __( 'SEO', 'zinn-seo' ) ) );
		}
		$tags->register( new Elementor\Tag_Title() );
		$tags->register( new Elementor\Tag_Description() );
		$tags->register( new Elementor\Tag_Breadcrumbs() );
	}
}
