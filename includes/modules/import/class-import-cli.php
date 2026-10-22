<?php
/**
 * WP-CLI: `wp zinn-seo import`.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import;

use ZinnDigital\ZinnSEO\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move from another SEO plugin: count, copy, compare, switch it off, undo.
 */
final class Import_Cli {

	/**
	 * Stop with the message of an error.
	 *
	 * @param mixed $result A result.
	 * @return void
	 */
	private static function bail( $result ): void {
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
	}

	/**
	 * Run a started job to its end in this process, or continue one that was stopped.
	 *
	 * @param string $job Job id.
	 * @return array<string, mixed> The record.
	 */
	private static function finish( string $job ): array {
		$record = Jobs::get( $job );
		if ( in_array( $record['status'], array( 'paused', 'failed' ), true ) ) {
			Jobs::control( $job, 'resume' );
		}
		$record = Jobs::run(
			$job,
			static function ( array $record ): void {
				\WP_CLI::log( sprintf( '%d of %s', (int) ( $record['progress']['done'] ?? 0 ), (string) ( $record['progress']['total'] ?? '?' ) ) );
			}
		);
		if ( 'done' !== $record['status'] ) {
			\WP_CLI::error( sprintf( 'The job stopped: %s %s', (string) $record['status'], (string) $record['error'] ) );
		}

		return $record;
	}

	/**
	 * The SEO plugins found on this site.
	 *
	 * @return void
	 */
	public function sources(): void {
		\WP_CLI::line( (string) wp_json_encode( Import::describe()['sources'] ) );
	}

	/**
	 * The state of the move, as JSON.
	 *
	 * @return void
	 */
	public function status(): void {
		\WP_CLI::line( (string) wp_json_encode( Import::describe() ) );
	}

	/**
	 * Count what a copy would bring over. Nothing is written.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : yoast, rankmath, aioseo, seopress, tsf, slimseo, squirrly, smartcrawl, redirection or srm.
	 *
	 * [--overwrite]
	 * : Count values that would replace ones already set here.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function count( array $args, array $assoc ): void {
		self::bail( Import::start( (string) $args[0], true, isset( $assoc['overwrite'] ) ) );
		self::finish( Import::JOB );
		\WP_CLI::line( (string) wp_json_encode( Import::state()['dry'] ) );
	}

	/**
	 * Copy the other plugin's data in (resumes a copy that was stopped with --resume).
	 *
	 * ## OPTIONS
	 *
	 * [<source>]
	 * : The plugin to copy from. Not needed with --resume.
	 *
	 * [--overwrite]
	 * : Replace values already set here.
	 *
	 * [--resume]
	 * : Continue the copy that was stopped.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function copy( array $args, array $assoc ): void {
		$record = Jobs::get( Import::JOB );
		if ( ! isset( $assoc['resume'] ) || ! in_array( $record['status'], array( 'pending', 'paused', 'failed' ), true ) ) {
			if ( ! isset( $args[0] ) ) {
				\WP_CLI::error( 'Name the plugin to copy from.' );
			}
			self::bail( Import::start( (string) $args[0], false, isset( $assoc['overwrite'] ) ) );
		}
		self::finish( Import::JOB );
		\WP_CLI::line( (string) wp_json_encode( Import::state()['applied'] ) );
		// The copy is read back at once (it is what opens the comparison and the switch-off).
		if ( in_array( Jobs::get( Verify::JOB )['status'], array( 'pending', 'paused', 'failed' ), true ) ) {
			self::finish( Verify::JOB );
		}
		self::proof();
	}

	/**
	 * Print the read-back of the copy; an error when something copied is not stored.
	 *
	 * @return void
	 */
	private static function proof(): void {
		$state = Import::state();
		\WP_CLI::line( (string) wp_json_encode( $state['proof'] ) );
		$why = Verify::refusal( $state );
		if ( '' !== $why ) {
			\WP_CLI::error( $why );
		}
	}

	/**
	 * Look at what the move has to allow for: the other plugin and its version, page caches and
	 * CDNs, a multilingual plugin, and whether this site can read its own pages. Nothing is written
	 * but the result.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The plugin to move from.
	 *
	 * @param array<int, string> $args Arguments.
	 * @return void
	 */
	public function preflight( array $args ): void {
		$result = Preflight::run( (string) $args[0] );
		self::bail( $result );
		foreach ( (array) $result['items'] as $item ) {
			\WP_CLI::line( sprintf( '%-5s %s', (string) $item['level'], (string) $item['text'] ) );
		}
		if ( ! empty( $result['blocked'] ) ) {
			\WP_CLI::error( Preflight::refusal( $result ) );
		}
		\WP_CLI::success( 'Nothing stops the move.' );
	}

	/**
	 * Read the copy back from the database: every setting and value it wrote must be stored.
	 *
	 * @return void
	 */
	public function verify(): void {
		self::bail( Verify::start() );
		self::finish( Verify::JOB );
		self::proof();
		\WP_CLI::success( 'Everything copied is stored.' );
	}

	/**
	 * Start reading the other plugin's data side by side without copying it.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The plugin.
	 *
	 * @param array<int, string> $args Arguments.
	 * @return void
	 */
	public function begin( array $args ): void {
		self::bail( Import::begin( (string) $args[0] ) );
		\WP_CLI::success( 'Reading ' . (string) $args[0] . ' side by side.' );
	}

	/**
	 * Stop reading side by side and put the settings back (before anything was copied).
	 *
	 * @return void
	 */
	public function stop(): void {
		self::bail( Import::stop() );
		\WP_CLI::success( 'Stopped.' );
	}

	/**
	 * Compare every page as the other plugin renders it with what Zinn SEO would print.
	 *
	 * ## OPTIONS
	 *
	 * [--keep]
	 * : Store what the other plugin showed where the two differ (the default). With --no-keep it is only listed.
	 *
	 * [--resume]
	 * : Continue a comparison that was stopped.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function check( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		$record = Jobs::get( Check::JOB );
		if ( ! isset( $assoc['resume'] ) || ! in_array( $record['status'], array( 'pending', 'paused', 'failed' ), true ) ) {
			self::bail( Check::start( false !== ( $assoc['keep'] ?? true ) ) );
		}
		self::finish( Check::JOB );
		\WP_CLI::line( (string) wp_json_encode( Import::state()['check'] ) );
	}

	/**
	 * The differences the comparison found (the first 500), as JSON.
	 *
	 * @return void
	 */
	public function differences(): void {
		$list = get_option( Check::DIFFS, array() );
		\WP_CLI::line( (string) wp_json_encode( is_array( $list ) ? array_values( $list ) : array() ) );
	}

	/**
	 * Accept the listed differences, which opens the switch-off.
	 *
	 * @return void
	 */
	public function accept(): void {
		self::bail( Check::accept() );
		\WP_CLI::success( 'Accepted.' );
	}

	/**
	 * Switch the other plugin off (only after the comparison passed), then read the pages again;
	 * a page that says something else switches it back on.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function switch( array $args, array $assoc ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames,WordPress.NamingConventions.ValidFunctionName,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's own subcommand name and signature.
		\WP_CLI::confirm( 'Switch the other SEO plugin off?', $assoc );
		self::bail( Switcher::off() );
		self::finish( Switcher::JOB );
		$state = Import::state();
		if ( isset( $state['rollback'] ) && 'pending' === Jobs::get( Import::JOB_UNDO )['status'] ) {
			// The rollback undoes the move as a whole: finish that here too.
			self::finish( Import::JOB_UNDO );
			$state = Import::state();
		}
		\WP_CLI::line( (string) wp_json_encode( array_intersect_key( $state, array_flip( array( 'switched', 'rollback', 'purge', 'switch_error' ) ) ) ) );
		self::caches( $state );
		if ( '' !== (string) ( $state['switch_error'] ?? '' ) ) {
			\WP_CLI::error( (string) $state['switch_error'] . ' The other plugin is still active.' );
		}
		if ( null === $state['switched'] ) {
			\WP_CLI::error( 'A page said something else after the switch. The other plugin is active again and the move was undone.' );
		}
		\WP_CLI::success( 'Switched off; every page and key file read again says the same.' );
	}

	/**
	 * Say which caches were emptied and which are left to the person.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function caches( array $state ): void {
		$purge = (array) ( $state['purge'] ?? array() );
		if ( array() !== (array) ( $purge['purged'] ?? array() ) ) {
			\WP_CLI::log( 'Emptied: ' . implode( ', ', (array) $purge['purged'] ) . '.' );
		}
		if ( array() !== (array) ( $purge['manual'] ?? array() ) ) {
			\WP_CLI::warning( 'Empty this cache yourself now: ' . implode( ', ', (array) $purge['manual'] ) . '.' );
		}
	}

	/**
	 * The whole move in one command: preflight, copy, read back, compare, switch off with the
	 * before/after check. It stops at the first step that does not pass, with the other plugin
	 * still active; a failed check after the switch puts it back on and undoes the move.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The plugin to move from.
	 *
	 * [--overwrite]
	 * : Replace values already set here.
	 *
	 * [--accept]
	 * : Accept the differences the comparison lists (read them first with `differences`).
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function move( array $args, array $assoc ): void {
		\WP_CLI::confirm( 'Move from ' . (string) $args[0] . ' and switch it off once every check passes?', $assoc );
		$this->preflight( $args );
		$this->copy( $args, array_intersect_key( $assoc, array( 'overwrite' => 1 ) ) );
		self::bail( Check::start( true ) );
		self::finish( Check::JOB );
		$state = Import::state();
		\WP_CLI::line( (string) wp_json_encode( $state['check'] ) );
		if ( ! Check::passed( $state['check'] ) ) {
			if ( ! isset( $assoc['accept'] ) ) {
				\WP_CLI::error( 'The comparison lists differences. Read them with `wp zinn-seo import differences`, then accept them (--accept) or fix them. The other plugin is still active.' );
			}
			self::bail( Check::accept() );
		}
		$this->switch( array(), array( 'yes' => true ) );
	}

	/**
	 * Undo the move: the other plugin back on, copied values and settings removed.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int, string>    $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 * @return void
	 */
	public function undo( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI's signature.
		\WP_CLI::confirm( 'Undo the import?', $assoc );
		self::bail( Import::undo() );
		self::finish( Import::JOB_UNDO );
		\WP_CLI::line( (string) wp_json_encode( Import::state()['undo'] ?? array() ) );
	}
}
