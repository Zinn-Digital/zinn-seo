<?php
/**
 * Per-page fields where people edit: the block editor, the classic editor and term screens.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Head;

use ZinnDigital\ZinnSEO\Capabilities;
use ZinnDigital\ZinnSEO\Meta;
use ZinnDigital\ZinnSEO\Plugin;
use ZinnDigital\ZinnSEO\Templates\Context;
use ZinnDigital\ZinnSEO\Templates\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - Block editor: a "Search and social" panel in the document sidebar (`build/editor.js`). It
 *   reads and writes through `/zinn-seo/v1/fields`, and saves when the post is saved.
 * - Classic editor: the same fields in a meta box, saved with the post (nonce + capability).
 * - Categories, tags and every public taxonomy: the same fields on the term's edit screen.
 *
 * ⛔ zs-155: the editor bundle loads only where a post of a public type is edited by someone who
 * may edit its SEO; the term and classic screens get a small script of their own; every other
 * admin screen gets nothing.
 *
 * Every empty field shows, as its placeholder, what the template gives this page — so the person
 * edits from what visitors and search engines see now, never from a blank box.
 */
final class Editor {

	/** Nonce action of the classic and term forms. */
	private const NONCE = 'zseo_fields';

	/** Script handle of the block editor panel. */
	public const HANDLE = 'zinn-seo-editor';

	/** Script handle of the classic and term screens. */
	public const FIELDS_HANDLE = 'zinn-seo-fields';

	/**
	 * Hooks (admin).
	 *
	 * @return void
	 */
	public static function boot_admin(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'save_post' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'term_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_fields' ) );
		add_action( 'show_user_profile', array( self::class, 'render_user_fields' ), 20 );
		add_action( 'edit_user_profile', array( self::class, 'render_user_fields' ), 20 );
		add_action( 'personal_options_update', array( self::class, 'save_user' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_user' ) );
		// The Users list: how each author's page shows in search (zs-756).
		add_filter( 'manage_users_columns', array( self::class, 'user_columns' ) );
		add_filter( 'manage_users_custom_column', array( self::class, 'user_column' ), 10, 3 );
		add_filter( 'users_list_table_query_args', array( self::class, 'users_query_args' ) );
	}

	/**
	 * The Users list's "SEO" column, for people who edit SEO.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function user_columns( $columns ): array {
		$columns = (array) $columns;
		if ( current_user_can( Capabilities::EDIT ) || current_user_can( Capabilities::MANAGE ) ) {
			$columns['zseo_author'] = __( 'Author page in search', 'zinn-seo' );
		}

		return $columns;
	}

	/**
	 * Load every listed user's fields with the list's own query (no query per row).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public static function users_query_args( $args ): array {
		$args = (array) $args;
		if ( ! isset( $args['fields'] ) || 'all' === $args['fields'] ) {
			$args['fields'] = 'all_with_meta';
		}

		return $args;
	}

	/**
	 * One user's cell.
	 *
	 * @param string $output  Output so far.
	 * @param string $column  Column.
	 * @param int    $user_id User.
	 * @return string
	 */
	public static function user_column( $output, $column, $user_id ): string {
		if ( 'zseo_author' !== $column ) {
			return (string) $output;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}
		$c         = Context::for_key( 'author' );
		$c->object = $user;
		$state     = self::author_state(
			'' !== Head::author_target( (int) $user_id ),
			isset( Resolver::robots( $c )['noindex'] ),
			'' !== (string) Meta::get_user( (int) $user_id, 'title' ),
			'' !== (string) Meta::get_user( (int) $user_id, 'desc' )
		);
		$out       = '<span class="zseo-pill zseo-pill--' . esc_attr( $state['tone'] ) . '">' . esc_html( $state['label'] ) . '</span>';
		if ( '' !== $state['detail'] ) {
			$out .= '<br><span class="description">' . esc_html( $state['detail'] ) . '</span>';
		}

		return $out;
	}

	/**
	 * How an author's page shows in search, in words (zs-756). Pure, for the tests.
	 *
	 * @param bool $redirected Sent elsewhere with a 301.
	 * @param bool $hidden     Kept out of search (noindex).
	 * @param bool $own_title  Has its own SEO title.
	 * @param bool $own_desc   Has its own description.
	 * @return array{tone: string, label: string, detail: string}
	 */
	public static function author_state( bool $redirected, bool $hidden, bool $own_title, bool $own_desc ): array {
		if ( $redirected ) {
			return array(
				'tone'   => 'info',
				'label'  => __( 'Redirected', 'zinn-seo' ),
				'detail' => '',
			);
		}
		if ( $hidden ) {
			return array(
				'tone'   => 'muted',
				'label'  => __( 'Hidden from search', 'zinn-seo' ),
				'detail' => '',
			);
		}
		$parts = array(
			$own_title ? __( 'Own title', 'zinn-seo' ) : __( 'Title from the template', 'zinn-seo' ),
			$own_desc ? __( 'own description', 'zinn-seo' ) : __( 'description from the template', 'zinn-seo' ),
		);

		return array(
			'tone'   => $own_title && $own_desc ? 'ok' : 'warn',
			'label'  => __( 'In search', 'zinn-seo' ),
			'detail' => implode( ', ', $parts ),
		);
	}

	/**
	 * May this person change how an author's page appears in search results? Their own page, or
	 * anyone's for someone who manages SEO.
	 *
	 * @param int $user_id The author.
	 * @return bool
	 */
	public static function can_edit_user( int $user_id ): bool {
		return current_user_can( 'edit_user', $user_id ) && ( current_user_can( Capabilities::EDIT ) || current_user_can( Capabilities::MANAGE ) );
	}

	/**
	 * The profile screen's section: the author's own page (title, description, search engines).
	 *
	 * @param \WP_User $user User.
	 * @return void
	 */
	public static function render_user_fields( $user ): void {
		if ( ! $user instanceof \WP_User || ! self::can_edit_user( (int) $user->ID ) ) {
			return;
		}
		$c         = Context::for_key( 'author' );
		$c->object = $user;
		$auto      = self::auto( $c );
		echo '<div id="zseo-author-page"><h2>' . esc_html__( 'This author\'s page in search results', 'zinn-seo' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in Guide::link().
		echo '<p class="description">' . esc_html__( 'The page that lists this person\'s posts. Leave a field empty to use the author template from Search appearance.', 'zinn-seo' ) . ' ' . \ZinnDigital\ZinnSEO\Guide::link( 'author-page' ) . '</p>';
		wp_nonce_field( self::NONCE, 'zseo_fields_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::form_fields() as $name => $field ) {
			if ( in_array( 'user', (array) ( Meta::fields()[ $name ]['objects'] ?? array() ), true ) ) {
				self::render_field( $name, $field, Meta::get_user( (int) $user->ID, $name ), $auto[ $name ] ?? '', 'tr' );
			}
		}
		// The 301 for an author's page: only for someone who manages SEO (an author must not be
		// able to send their own page, and its links, to another site).
		if ( current_user_can( Capabilities::MANAGE ) ) {
			self::render_field( 'redirect', self::redirect_field(), Meta::get_user( (int) $user->ID, 'redirect' ), '', 'tr' );
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The author page's redirect field (zs-756).
	 *
	 * @return array<string, mixed>
	 */
	public static function redirect_field(): array {
		return array(
			'label' => __( 'Redirect this page (301)', 'zinn-seo' ),
			'kind'  => 'url',
			'help'  => __( 'For an author who left: visitors and search engines go to this address instead, and the page leaves the sitemap. Empty: no redirect.', 'zinn-seo' ),
		);
	}

	/**
	 * Store an author's page fields.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function save_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! isset( $_POST['zseo_fields_nonce'], $_POST['zseo'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zseo_fields_nonce'] ) ), self::NONCE ) || ! self::can_edit_user( $user_id ) ) {
			return;
		}
		$values = wp_unslash( (array) $_POST['zseo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by Meta::set() against its field definition.
		self::store( 'user', $user_id, $values );
		if ( array_key_exists( 'redirect', $values ) && is_scalar( $values['redirect'] ) && current_user_can( Capabilities::MANAGE ) ) {
			Meta::set( 'user', $user_id, 'redirect', trim( (string) $values['redirect'] ), 'user' );
		}
	}

	/**
	 * Is this a post type whose pages visitors reach?
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function public_type( string $type ): bool {
		$object = get_post_type_object( $type );

		return $object instanceof \WP_Post_Type && ( $object->public || is_post_type_viewable( $object ) );
	}

	/**
	 * Block editor: enqueue the panel for a public type, for someone who may edit SEO fields.
	 *
	 * @return void
	 */
	public static function enqueue_block_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = $screen && is_string( $screen->post_type ) ? $screen->post_type : '';
		if ( '' === $type || ! self::public_type( $type ) || ! current_user_can( Capabilities::EDIT ) ) {
			return;
		}
		$asset_file = ZINN_SEO_DIR . 'build/editor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		// The panel reads the editor's own packages from `window.wp` (the document panel moved
		// from edit-post to editor in WordPress 6.6), so they are named here rather than bundled.
		\ZinnDigital\ZinnSEO\Admin\Admin::register_jsx_runtime();
		$deps = array_unique( array_merge( (array) ( $asset['dependencies'] ?? array() ), array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-block-editor', 'wp-core-data' ) ) );
		wp_enqueue_script( self::HANDLE, ZINN_SEO_URL . 'build/editor.js', $deps, (string) ( $asset['version'] ?? ZINN_SEO_VERSION ), true );
		wp_set_script_translations( self::HANDLE, 'zinn-seo', ZINN_SEO_DIR . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.zinnSeoEditor = ' . wp_json_encode( self::editor_data() ) . ';', 'before' );
		if ( is_readable( ZINN_SEO_DIR . 'build/editor.css' ) ) {
			wp_enqueue_style( self::HANDLE, ZINN_SEO_URL . 'build/editor.css', array(), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Data the editor panel boots with. ⛔ Printed into the page: nothing secret.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data(): array {
		/**
		 * Filters the data the block editor's panels boot with (the schema module adds its
		 * choices). ⛔ Printed into the page: nothing secret.
		 *
		 * @param array<string, mixed> $data Data.
		 */
		return (array) apply_filters(
			'zinn_seo_editor_data',
			array(
				'restPath'        => '/' . Plugin::REST_NS,
				'isPro'           => Plugin::is_pro(),
				'plansUrl'        => admin_url( 'admin.php?page=zinn-seo&view=plans' ),
				'canManage'       => Capabilities::can_manage(),
				// Click a suggested title or description to edit it (zs-757).
				'placeholderEdit' => self::placeholder_edit(),
			)
		);
	}

	/**
	 * Classic and term screens: the small fields script.
	 *
	 * @param string $hook_suffix Screen.
	 * @return void
	 */
	public static function enqueue_fields( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( Capabilities::EDIT ) ) {
			return;
		}
		$classic = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) && is_string( $screen->post_type ) && self::public_type( $screen->post_type ) && ! $screen->is_block_editor();
		$term    = 'term.php' === $hook_suffix && is_string( $screen->taxonomy ) && self::public_taxonomy( $screen->taxonomy );
		// The profile's author-page fields (zs-756) and the Users list's SEO column.
		$people = in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'users.php' ), true );
		if ( ! $classic && ! $term && ! $people ) {
			return;
		}
		$asset_file = ZINN_SEO_DIR . 'build/fields.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		if ( 'users.php' !== $hook_suffix ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( self::FIELDS_HANDLE, ZINN_SEO_URL . 'build/fields.js', (array) ( $asset['dependencies'] ?? array() ), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ), true );
		wp_set_script_translations( self::FIELDS_HANDLE, 'zinn-seo', ZINN_SEO_DIR . 'languages' );
		if ( is_readable( ZINN_SEO_DIR . 'build/fields.css' ) ) {
			wp_enqueue_style( self::FIELDS_HANDLE, ZINN_SEO_URL . 'build/fields.css', array(), (string) ( $asset['version'] ?? ZINN_SEO_VERSION ) );
			wp_style_add_data( self::FIELDS_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Is this a taxonomy whose archives visitors reach?
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function public_taxonomy( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		return $object instanceof \WP_Taxonomy && $object->public && 'post_format' !== $taxonomy;
	}

	/**
	 * Classic editor: the meta box (only where the block editor is not used).
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 * @return void
	 */
	public static function add_meta_box( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! self::public_type( $post_type ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}
		if ( ! Capabilities::can_edit_post( (int) $post->ID ) ) {
			return;
		}
		add_meta_box( 'zinn-seo', __( 'Search and social', 'zinn-seo' ), array( self::class, 'render_meta_box' ), $post_type, 'normal', 'high' );
	}

	/**
	 * The fields each form shows: name => [label, kind]. Kinds: text, textarea, url, robots,
	 * image, select (with choices).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_fields(): array {
		$fields = array(
			'title'     => array(
				'label' => __( 'SEO title', 'zinn-seo' ),
				'kind'  => 'text',
			),
			'desc'      => array(
				'label' => __( 'Meta description', 'zinn-seo' ),
				'kind'  => 'textarea',
			),
			'canonical' => array(
				'label' => __( 'Canonical address', 'zinn-seo' ),
				'kind'  => 'url',
			),
			'robots'    => array(
				'label' => __( 'Search engines', 'zinn-seo' ),
				'kind'  => 'robots',
			),
		);

		/**
		 * Filters the per-page fields the classic editor and term forms show (the social module
		 * adds its own).
		 *
		 * @param array<string, array<string, mixed>> $fields Fields.
		 */
		return (array) apply_filters( 'zinn_seo_form_fields', $fields );
	}

	/**
	 * Print the meta box.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_nonce_field( self::NONCE, 'zseo_fields_nonce' );
		$auto = self::auto( Context::for_post( $post ) );
		echo '<div class="zseo-fields">';
		foreach ( self::form_fields() as $name => $field ) {
			self::render_field( $name, $field, Meta::get_post( (int) $post->ID, $name ), $auto[ $name ] ?? '', 'div' );
		}
		echo '</div>';
	}

	/**
	 * What each field would be if left empty (the template's result), for placeholders.
	 *
	 * @param Context $c Context.
	 * @return array<string, string>
	 */
	public static function auto( Context $c ): array {
		$own = array(
			'title'     => Resolver::auto_title( $c ),
			'desc'      => Resolver::auto_description( $c ),
			'canonical' => Resolver::address( $c ),
		);

		/**
		 * Filters the template results a page's empty fields show as placeholders.
		 *
		 * @param array<string, string> $own Field => text.
		 * @param Context               $c   The page.
		 */
		return (array) apply_filters( 'zinn_seo_field_placeholders', $own, $c );
	}

	/**
	 * Print one field.
	 *
	 * @param string               $name  Field.
	 * @param array<string, mixed> $field Definition from form_fields().
	 * @param mixed                $value Stored value.
	 * @param string               $auto  Placeholder (the template's result).
	 * @param string               $wrap  `div` (meta box) or `tr` (term table).
	 * @return void
	 */
	public static function render_field( string $name, array $field, $value, string $auto, string $wrap ): void {
		$id    = 'zseo-field-' . $name;
		$label = esc_html( (string) $field['label'] );
		$input = '';
		switch ( (string) $field['kind'] ) {
			case 'textarea':
				$input = '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="zseo[' . esc_attr( $name ) . ']" placeholder="' . esc_attr( $auto ) . '" data-zseo-count="' . ( 'desc' === $name ? '160' : '' ) . '"' . self::fill_attr( $auto ) . '>' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'url':
				$input = '<input type="url" class="large-text" id="' . esc_attr( $id ) . '" name="zseo[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $auto ) . '">';
				break;
			case 'image':
				$image = (int) $value;
				$src   = $image > 0 ? wp_get_attachment_image_url( $image, 'medium' ) : '';
				$input = '<span class="zseo-image" data-zseo-image>'
					. '<input type="hidden" id="' . esc_attr( $id ) . '" name="zseo[' . esc_attr( $name ) . ']" value="' . esc_attr( $image > 0 ? (string) $image : '' ) . '">'
					. '<img class="zseo-image__preview" alt="" src="' . esc_url( (string) $src ) . '"' . ( $src ? '' : ' hidden' ) . '>'
					. '<button type="button" class="button" data-zseo-pick>' . esc_html__( 'Choose image', 'zinn-seo' ) . '</button> '
					. '<button type="button" class="button-link" data-zseo-clear' . ( $image > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'zinn-seo' ) . '</button>'
					. ( '' !== $auto ? '<span class="description">' . esc_html( $auto ) . '</span>' : '' )
					. '</span>';
				break;
			case 'robots':
				$set     = (array) $value;
				$choices = array(
					'noindex'      => __( 'Hide from search results (noindex)', 'zinn-seo' ),
					'index'        => __( 'Show in search results even if its type is hidden (index)', 'zinn-seo' ),
					'nofollow'     => __( 'Do not follow its links (nofollow)', 'zinn-seo' ),
					'noarchive'    => __( 'No cached copy (noarchive)', 'zinn-seo' ),
					'nosnippet'    => __( 'No text snippet (nosnippet)', 'zinn-seo' ),
					'noimageindex' => __( 'Do not index its images (noimageindex)', 'zinn-seo' ),
				);
				$input   = '<fieldset id="' . esc_attr( $id ) . '"><legend class="screen-reader-text">' . $label . '</legend>';
				foreach ( $choices as $flag => $text ) {
					$input .= '<label class="zseo-check"><input type="checkbox" name="zseo[robots][]" value="' . esc_attr( $flag ) . '"' . checked( in_array( $flag, $set, true ), true, false ) . '> ' . esc_html( $text ) . '</label>';
				}
				$input .= '<input type="hidden" name="zseo[robots][]" value="">';
				$input .= '</fieldset>';
				break;
			case 'select':
				$input = '<select id="' . esc_attr( $id ) . '" name="zseo[' . esc_attr( $name ) . ']">';
				foreach ( (array) ( $field['choices'] ?? array() ) as $choice => $text ) {
					$input .= '<option value="' . esc_attr( (string) $choice ) . '"' . selected( (string) $value, (string) $choice, false ) . '>' . esc_html( (string) $text ) . '</option>';
				}
				$input .= '</select>';
				break;
			default:
				$input = '<input type="text" class="large-text" id="' . esc_attr( $id ) . '" name="zseo[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $auto ) . '" data-zseo-count="' . ( 'title' === $name ? '60' : '' ) . '"' . self::fill_attr( $auto ) . '>';
		}
		$help = isset( $field['help'] ) ? '<p class="description">' . esc_html( (string) $field['help'] ) . '</p>' : '';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every part was escaped above.
		if ( 'tr' === $wrap ) {
			echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( $id ) . '">' . $label . '</label></th><td>' . $input . $help . '</td></tr>';
		} else {
			echo '<p class="zseo-field"><label for="' . esc_attr( $id ) . '"><strong>' . $label . '</strong></label><br>' . $input . '</p>' . $help;
		}
		// phpcs:enable
	}

	/**
	 * Is click-to-edit of the suggested text on (zs-757)?
	 *
	 * @return bool
	 */
	public static function placeholder_edit(): bool {
		return (bool) \ZinnDigital\ZinnSEO\Settings::get( 'placeholder_edit', false );
	}

	/**
	 * The attribute that lets a click put the suggested text in an empty field to change it.
	 *
	 * @param string $auto The suggested text.
	 * @return string
	 */
	private static function fill_attr( string $auto ): string {
		return '' !== trim( $auto ) && self::placeholder_edit() ? ' data-zseo-fill="1"' : '';
	}

	/**
	 * Classic editor: store the fields with the post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function save_post( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the action's signature.
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['zseo_fields_nonce'], $_POST['zseo'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zseo_fields_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! Capabilities::can_edit_post( $post_id ) ) {
			return;
		}
		$values = wp_unslash( (array) $_POST['zseo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by Meta::set() against its field definition.
		self::store( 'post', $post_id, $values );
	}

	/**
	 * Store submitted values; a value that does not pass its field's check is left unchanged.
	 *
	 * @param string               $kind   post|term|user.
	 * @param int                  $id     Object ID.
	 * @param array<string, mixed> $values Submitted values.
	 * @return void
	 */
	public static function store( string $kind, int $id, array $values ): void {
		$defs = Meta::fields();
		foreach ( self::form_fields() as $name => $field ) {
			if ( ! array_key_exists( $name, $values ) || ! in_array( $kind, (array) ( $defs[ $name ]['objects'] ?? array( 'post' ) ), true ) ) {
				continue;
			}
			$value = $values[ $name ];
			if ( 'robots' === $field['kind'] ) {
				$value = array_values( array_filter( array_map( 'strval', (array) $value ) ) );
			} elseif ( 'image' === $field['kind'] ) {
				$value = (int) $value;
			} else {
				$value = is_scalar( $value ) ? (string) $value : '';
			}
			Meta::set( $kind, $id, (string) $name, $value, 'user' );
		}
	}

	/**
	 * Term screens: hooks for every public taxonomy (registered by now).
	 *
	 * @return void
	 */
	public static function term_hooks(): void {
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}
			add_action( $taxonomy . '_edit_form_fields', array( self::class, 'render_term_fields' ), 20 );
		}
		add_action( 'edited_term', array( self::class, 'save_term' ), 10, 3 );
	}

	/**
	 * The term edit screen's rows.
	 *
	 * @param \WP_Term $term Term.
	 * @return void
	 */
	public static function render_term_fields( $term ): void {
		if ( ! $term instanceof \WP_Term || ! Capabilities::can_edit_term( (int) $term->term_id ) ) {
			return;
		}
		$auto = self::auto( Context::for_term( $term ) );
		echo '<tr class="form-field zseo-term-heading"><th scope="row" colspan="2"><h2>' . esc_html__( 'Search and social', 'zinn-seo' ) . '</h2>';
		wp_nonce_field( self::NONCE, 'zseo_fields_nonce' );
		echo '</th></tr>';
		foreach ( self::form_fields() as $name => $field ) {
			self::render_field( $name, $field, Meta::get_term( (int) $term->term_id, $name ), $auto[ $name ] ?? '', 'tr' );
		}
	}

	/**
	 * Store a term's fields.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function save_term( $term_id, $tt_id = 0, $taxonomy = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the action's signature.
		$term_id = (int) $term_id;
		if ( ! isset( $_POST['zseo_fields_nonce'], $_POST['zseo'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zseo_fields_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! is_string( $taxonomy ) || ! self::public_taxonomy( $taxonomy ) || ! Capabilities::can_edit_term( $term_id ) ) {
			return;
		}
		$values = wp_unslash( (array) $_POST['zseo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by Meta::set() against its field definition.
		self::store( 'term', $term_id, $values );
	}
}
