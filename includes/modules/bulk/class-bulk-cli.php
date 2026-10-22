<?php
/**
 * WP-CLI: `wp zinn-seo bulk …` — change SEO fields across many posts or terms, see and undo
 * the changes.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Bulk;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change SEO fields across many posts or terms, list the changes and undo them.
 *
 * ## EXAMPLES
 *
 *     # Hide every tag archive from search results.
 *     wp zinn-seo bulk apply --object=term --type=post_tag --set=robots='["noindex"]' --yes
 *
 *     # Give every product without a description the template's description back.
 *     wp zinn-seo bulk apply --type=product --set=desc='' --yes
 *
 *     wp zinn-seo bulk history
 *     wp zinn-seo bulk undo abcdefghij12
 */
final class Bulk_Cli {

	/**
	 * Change fields on every post or term matching the filters (runs here, to the end).
	 *
	 * ## OPTIONS
	 *
	 * [--object=<object>]
	 * : post or term.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--type=<type>]
	 * : Post type or taxonomy.
	 *
	 * [--status=<status>]
	 * : Post status (any, publish, draft, pending, future, private).
	 *
	 * [--search=<text>]
	 * : Only titles containing this.
	 *
	 * [--missing=<field>]
	 * : Only rows whose own title or desc is empty.
	 *
	 * [--term=<id>]
	 * : Only posts in this category, tag or term.
	 *
	 * --set=<changes>
	 * : A field and its new value as field=value (JSON when it parses, e.g. robots='["noindex"]'). Repeat
	 * with a comma: --set=title='%title%',desc=''.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function apply( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		$fields = array();
		foreach ( preg_split( '/,(?=[a-z_]+=)/', (string) ( $assoc['set'] ?? '' ) ) as $pair ) {
			if ( false === strpos( (string) $pair, '=' ) ) {
				continue;
			}
			list( $name, $value )    = explode( '=', (string) $pair, 2 );
			$json                    = json_decode( $value, true );
			$fields[ trim( $name ) ] = null !== $json || 'null' === $value ? $json : $value;
		}
		$f = Bulk::filter( $assoc );
		\WP_CLI::confirm( sprintf( 'Change %s on every matching %s?', implode( ', ', array_keys( $fields ) ), Bulk::type_label( $f ) ), $assoc );
		$started = Bulk::start_apply( $f, $fields );
		if ( is_wp_error( $started ) ) {
			\WP_CLI::error( $started->get_error_message() );
		}
		$job = Jobs::run( Bulk::JOB_APPLY );
		\WP_CLI::success( sprintf( 'Changed %d. Undo with: wp zinn-seo bulk undo %s', (int) ( $job['state']['done'] ?? 0 ), (string) $started['batch'] ) );
	}

	/**
	 * List the recent changes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 *
	 * @subcommand history
	 */
	public function history( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		$rows = array();
		foreach ( History::index() as $row ) {
			$rows[] = array(
				'batch'   => $row['id'],
				'when'    => gmdate( 'Y-m-d H:i', (int) $row['at'] ),
				'what'    => $row['label'],
				'changes' => $row['count'],
				'undone'  => '' !== (string) $row['undone'] ? 'yes' : '',
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'batch', 'when', 'what', 'changes', 'undone' ) );
	}

	/**
	 * Undo a change (fields edited since are left as they are).
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : The batch id from `wp zinn-seo bulk history`.
	 *
	 * @param array<int, string> $args Positional.
	 * @return void
	 */
	public function undo( array $args ): void {
		$result = Bulk::undo( (string) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( '' !== $result['job'] ) {
			$job    = Jobs::run( Bulk::JOB_UNDO );
			$result = array_merge( $result, (array) $job['state'] );
		}
		\WP_CLI::success( sprintf( 'Restored %d fields; %d changed since and left as they are.', (int) $result['restored'], (int) $result['kept'] ) );
	}
}
