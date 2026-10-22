<?php
/**
 * Author expertise on the user profile (E-E-A-T, zs-032, zs-306).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

use ZinnDigital\ZinnSEO\Blocks\People;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users → Profile → "Expertise": job title, credentials, topics, schools, awards and profiles
 * elsewhere. They become the person's node in every page's graph and show in the author box.
 */
final class Profile {

	/** Nonce action. */
	private const NONCE = 'zseo_profile';

	/**
	 * Hook the profile screens.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'show_user_profile', array( self::class, 'form' ) );
		add_action( 'edit_user_profile', array( self::class, 'form' ) );
		add_action( 'personal_options_update', array( self::class, 'save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ) );
	}

	/**
	 * The section.
	 *
	 * @param \WP_User $user User.
	 * @return void
	 */
	public static function form( $user ): void {
		if ( ! $user instanceof \WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Expertise', 'zinn-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shown to search engines and AI assistants on the pages you write, review or check, and in the author box.', 'zinn-seo' ) . '</p>';
		wp_nonce_field( self::NONCE, '_zseo_profile_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( People::fields() as $key => $field ) {
			$id    = 'zseo' . str_replace( '_zseo', '', $key );
			$value = (string) get_user_meta( $user->ID, $key, true );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			if ( $field[1] ) {
				echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Save the section.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['_zseo_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_zseo_profile_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		foreach ( People::fields() as $key => $field ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw   = wp_unslash( (string) $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned on the next line by the field's kind.
			$value = $field[1] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			if ( People::SAME_AS === $key ) {
				$value = implode( "\n", Schema::urls( \ZinnDigital\ZinnSEO\Blocks\Html::split( '/\r\n|\r|\n/', $value ) ) );
			}
			if ( '' === $value ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, $value );
			}
		}
	}
}
