<?php
/**
 * The typed PHP API for developers (zs-246): `zinn_seo()`.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stable surface other code may call. Everything else in the plugin is internal and may
 * change between versions; this class follows semantic versioning.
 *
 *     $seo = zinn_seo();
 *     $seo->version();                          // '0.1.0'
 *     $seo->setting( 'site_type' );             // 'blog'
 *     $seo->post_field( 42, 'desc' );           // the stored meta description, or ''
 *     $seo->set_post_field( 42, 'robots', array( 'noindex' ) );
 *     $seo->module_enabled( 'sitemaps' );
 *
 * Writes go through the same checks as the settings screen and the REST API.
 *
 * Hooks (each documented where it fires):
 *  - `zinn_seo_loaded` (action)            after start-up, with the request context
 *  - `zinn_seo_settings_schema` (filter)   add setting keys
 *  - `zinn_seo_settings_saved` (action)    after settings changed
 *  - `zinn_seo_meta_fields` (filter)       add per-object fields
 *  - `zinn_seo_field_saved` (action)       after a field changed
 *  - `zinn_seo_setup_steps` (filter)       add setup steps
 *  - `zinn_seo_notrace_changes` (filter)   report what a no-trace switch changes
 *  - `zinn_seo_notrace_changed` (action)   after the no-trace mode switched
 *  - `zinn_seo_actor` (filter)             who is making a REST change
 */
final class Api {

	/**
	 * The one instance.
	 *
	 * @var Api|null
	 */
	private static ?Api $instance = null;

	/**
	 * The instance.
	 *
	 * @return Api
	 */
	public static function instance(): Api {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The plugin version.
	 *
	 * @return string
	 */
	public function version(): string {
		return ZINN_SEO_VERSION;
	}

	/**
	 * Is a paid plan (or trial) active?
	 *
	 * @return bool
	 */
	public function is_pro(): bool {
		return Plugin::is_pro();
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed Null when unknown.
	 */
	public function setting( string $key ) {
		return Settings::get( $key );
	}

	/**
	 * Change settings.
	 *
	 * @param array<string, mixed> $changes Key => value.
	 * @return array<string, mixed>|\WP_Error Every setting after the change.
	 */
	public function update_settings( array $changes ) {
		return Settings::save( $changes, 'api' );
	}

	/**
	 * A post's SEO field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name (title, desc, robots, canonical, …).
	 * @return mixed
	 */
	public function post_field( int $post_id, string $field ) {
		return Meta::get_post( $post_id, $field );
	}

	/**
	 * A term's SEO field.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $field   Field name.
	 * @return mixed
	 */
	public function term_field( int $term_id, string $field ) {
		return Meta::get_term( $term_id, $field );
	}

	/**
	 * Set a post's SEO field (no capability check: the caller is PHP running on the site).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @param mixed  $value   Value.
	 * @return true|\WP_Error
	 */
	public function set_post_field( int $post_id, string $field, $value ) {
		return Meta::set( 'post', $post_id, $field, $value, 'api' );
	}

	/**
	 * Set a term's SEO field.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $field   Field name.
	 * @param mixed  $value   Value.
	 * @return true|\WP_Error
	 */
	public function set_term_field( int $term_id, string $field, $value ) {
		return Meta::set( 'term', $term_id, $field, $value, 'api' );
	}

	/**
	 * Is a module switched on?
	 *
	 * @param string $module Module id.
	 * @return bool
	 */
	public function module_enabled( string $module ): bool {
		return Modules::enabled( $module );
	}

	/**
	 * Is the no-trace mode on?
	 *
	 * @return bool
	 */
	public function notrace(): bool {
		return Notrace::enabled();
	}

	/**
	 * Register a resumable background job (see Jobs::register()).
	 *
	 * @param string          $id    Job id.
	 * @param callable|string $label Label.
	 * @param callable        $step  Step callable.
	 * @return void
	 */
	public function register_job( string $id, $label, callable $step ): void {
		Jobs::register( $id, $label, $step );
	}
}
