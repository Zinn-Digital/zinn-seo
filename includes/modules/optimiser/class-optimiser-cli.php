<?php
/**
 * WP-CLI: the live content optimiser.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Optimiser;

use ZinnDigital\ZinnSEO\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Score posts against the pages that rank, and read the top 10 for a phrase.
 */
final class Optimiser_Cli {

	/**
	 * A saved post's score against the kept top 10 for its focus phrase (free: nothing is read).
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post id.
	 *
	 * [--phrase=<phrase>]
	 * : Another phrase than the post's first focus phrase.
	 *
	 * [--location=<country>]
	 * : Country (English name, e.g. "United Kingdom").
	 *
	 * [--language=<code>]
	 * : Language code (e.g. de).
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function score( array $args, array $assoc ): void {
		$out = Optimiser::score_post( (int) ( $args[0] ?? 0 ), (string) ( $assoc['phrase'] ?? '' ), (string) ( $assoc['location'] ?? '' ), (string) ( $assoc['language'] ?? '' ) );
		if ( is_wp_error( $out ) ) {
			\WP_CLI::error( $out->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $out ) );
	}

	/**
	 * Read the top 10 for a phrase now — spends Zinn® credit (once per phrase, country and
	 * language; a kept model is shown as it is unless --again). Run as a user who may spend:
	 * `--user=<admin>`.
	 *
	 * ## OPTIONS
	 *
	 * <phrase>
	 * : The phrase.
	 *
	 * [--location=<country>]
	 * : Country (English name).
	 *
	 * [--language=<code>]
	 * : Language code.
	 *
	 * [--again]
	 * : Read again although a model is kept.
	 *
	 * [--yes]
	 * : Do not ask before spending.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function read( array $args, array $assoc ): void {
		if ( ! current_user_can( Capabilities::SPEND ) ) {
			\WP_CLI::error( 'Run this as a user who may spend the account\'s credit: --user=<administrator>.' );
		}
		$question = self::question( Optimiser::money( true ), Optimiser::refusal() );
		if ( null !== $question ) {
			\WP_CLI::confirm( $question, $assoc );
		}
		// No question when nothing can be spent: the read answers with the kept model, or says
		// why it cannot read (not connected: how to connect; out of credit: where to buy).
		$out = Optimiser::pull( (string) ( $args[0] ?? '' ), (string) ( $assoc['location'] ?? '' ), (string) ( $assoc['language'] ?? '' ), isset( $assoc['again'] ) );
		if ( is_wp_error( $out ) ) {
			\WP_CLI::error( $out->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $out ) );
	}

	/**
	 * What to ask before a read (ZH5-4): null when the site cannot spend at all — then nothing is
	 * asked, and the read itself says what to do. ⛔ It used to offer to spend "an unknown amount
	 * of Zinn® credit" on a site with no Zinn® connection, where nothing could be spent.
	 *
	 * @param array<string, mixed> $money   Optimiser::money().
	 * @param \WP_Error|null       $refused Optimiser::refusal().
	 * @return string|null
	 */
	public static function question( array $money, ?\WP_Error $refused ): ?string {
		if ( null !== $refused ) {
			return null;
		}
		$cost = null === ( $money['price_micros'] ?? null )
			? 'is paid from Zinn® credit at the current price'
			: 'costs ' . Optimiser::price_text( (int) $money['price_micros'] ) . ' of Zinn® credit';

		return sprintf( 'Reading the top 10 %s (nothing when it is kept already). Go on?', $cost );
	}

	/**
	 * The kept searches, newest first.
	 *
	 * [--before=<id>]
	 * : Rows older than this id (the "next" of the previous page).
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function searches( array $args, array $assoc ): void {
		\WP_CLI::line( (string) wp_json_encode( Optimiser::page( (int) ( $assoc['before'] ?? 0 ), 200 ) ) );
	}

	/**
	 * Forget a kept search.
	 *
	 * <key>
	 * : The search's key (from `searches`).
	 *
	 * @param array<int, string> $args Arguments.
	 * @return void
	 */
	public function forget( array $args ): void {
		Optimiser::forget( (string) ( $args[0] ?? '' ) ) ? \WP_CLI::success( 'Forgotten.' ) : \WP_CLI::error( 'No such search.' );
	}
}
