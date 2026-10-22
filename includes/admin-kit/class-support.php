<?php
/**
 * Get help, report a bug, send feedback, request a feature.
 *
 * Generated from wp/packages/zinn-admin-kit/src/php/class-support.php by wp/bin/build-admin-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnSEO\AdminKit
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\AdminKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Files a ticket in the one Zinn Digital support inbox (features sh-r6, sh-r3; docs/843 D17;
 * x1-x7-contract.md §2.4).
 *
 * Works from an e-mail address alone: a site that never connected sends the address with the
 * ticket, and the platform files it under a support contact, never a customer organisation (D33).
 * A connected site sends its connection token instead.
 *
 * ⛔ Nothing is sent before the site owner has agreed on the consent screen, which lists exactly
 * what goes where. The consent is recorded per site with who gave it and when.
 */
final class Support {

	/** Option recording the consent. Per plugin. */
	public const CONSENT = 'zseo_kit_support_consent';

	/** Ticket kinds the API accepts. */
	public const KINDS = array( 'help', 'bug', 'feedback', 'feature_request' );

	/**
	 * Has someone on this site agreed to send support data to Zinn Digital?
	 *
	 * @return bool
	 */
	public static function has_consent(): bool {
		$row = get_option( self::CONSENT, array() );

		return is_array( $row ) && ! empty( $row['at'] );
	}

	/**
	 * Record the current user's agreement.
	 *
	 * @return void
	 */
	public static function give_consent(): void {
		update_option(
			self::CONSENT,
			array(
				'user' => get_current_user_id(),
				'at'   => time(),
			),
			false
		);
	}

	/**
	 * File a ticket.
	 *
	 * @param array<string, mixed> $input Fields from the screen: kind, email, name, subject,
	 *                                    message, diagnostics (bool).
	 *
	 * ⛔⛔ A ticket NEVER carries a login (WordPress.org review 2026-10-01, "Remote
	 * administration"): no user is created and no credentials are sent. A `credentials` or
	 * `access_days` field from an old screen is ignored.
	 * @return array{ok: bool, status: int, message: string, reference: string, priority: string, access: null}
	 */
	public static function send( array $input ): array {
		if ( ! self::has_consent() ) {
			return self::fail( 403, __( 'Agree to sending support information first.', 'zinn-seo' ) );
		}
		$kind    = in_array( $input['kind'] ?? '', self::KINDS, true ) ? (string) $input['kind'] : 'help';
		$subject = trim( sanitize_text_field( (string) ( $input['subject'] ?? '' ) ) );
		$message = trim( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ) );
		$email   = self::reply_address( (string) ( $input['email'] ?? '' ) );
		$token   = Connection::token();
		if ( '' === $subject || '' === $message ) {
			return self::fail( 400, __( 'Write a subject and a message.', 'zinn-seo' ) );
		}
		if ( '' === $token && ! is_email( $email ) ) {
			return self::fail( 400, __( 'Enter an e-mail address support can reply to.', 'zinn-seo' ) );
		}
		$licence = Licence::snapshot();
		$body    = array(
			'name'                => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'product'             => Kit::host( 'slug' ),
			'kind'                => $kind,
			'subject'             => mb_substr( $subject, 0, 200 ),
			'message'             => mb_substr( $message, 0, 20000 ),
			'locale'              => get_user_locale(),
			'consent_diagnostics' => ! empty( $input['diagnostics'] ),
			'licence'             => array(
				'plan'                => (string) $licence['plan'],
				'freemius_install_id' => (string) $licence['installId'],
				'freemius_license_id' => (string) $licence['licenceId'],
			),
			'website'             => '',
		);
		if ( '' === $token ) {
			$body['email'] = $email;
		}
		if ( $body['consent_diagnostics'] ) {
			$body['diagnostics'] = Diagnostics::collect();
		}
		$result = Engine::call( 'POST', '/v1/plugin-support/tickets', $body, $token );
		if ( ! $result['ok'] && 401 === $result['status'] && '' !== $token ) {
			Connection::mark_revoked( 'connection_revoked' === $result['code'] ? 'revoked_by_zinn' : 'unknown_to_zinn' );
		}
		if ( ! $result['ok'] ) {
			// A 4xx is the site's own request and keeps its meaning (422, 429 …); anything from the
			// far side failing, or not answering, is a 502 from this site, never its own 500.
			$status = $result['status'] >= 400 && $result['status'] < 500 ? $result['status'] : 502;

			return self::fail( $status, $result['message'], $result['retry_after'] );
		}

		return array(
			'ok'        => true,
			'status'    => 201,
			'message'   => '',
			'reference' => sanitize_text_field( (string) ( $result['body']['reference'] ?? '' ) ),
			'priority'  => 'high' === ( $result['body']['priority'] ?? '' ) ? 'high' : 'normal',
			'access'    => null,
		);
	}

	/**
	 * The reply address exactly as typed, or '' when it is not one plain address.
	 *
	 * ⛔ sanitize_email() CLEANS an address rather than refusing it: "a@b.test\r\nBcc: c@d.test"
	 * came back as the single address "a@b.testBccc@d.test", which passed is_email() and was sent
	 * to support as the customer's reply address, so the answer went nowhere (D28904). An address
	 * that sanitize_email() had to change is not the address the person meant: refuse it.
	 *
	 * @param string $raw What the screen sent.
	 * @return string
	 */
	public static function reply_address( string $raw ): string {
		$typed = trim( $raw );
		$clean = sanitize_email( $typed );

		return ( '' !== $typed && $clean === $typed ) ? $clean : '';
	}

	/**
	 * A failure result.
	 *
	 * @param int    $status      HTTP status.
	 * @param string $message     Our message.
	 * @param int    $retry_after Seconds, for a rate limit.
	 * @return array{ok: bool, status: int, message: string, reference: string, priority: string, access: null, retry_after: int}
	 */
	private static function fail( int $status, string $message, int $retry_after = 0 ): array {
		return array(
			'ok'          => false,
			'status'      => $status,
			'message'     => $message,
			'reference'   => '',
			'priority'    => 'normal',
			'access'      => null,
			'retry_after' => $retry_after,
		);
	}
}
