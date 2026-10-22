<?php
/**
 * Whether a bulk AI step is running.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Set while the bulk job runs a step: the step runs without a signed-in person (a loopback or the
 * watchdog), on behalf of the person who started it and was allowed to.
 */
final class Bulk_Context {

	/**
	 * Running?
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Is a bulk step running?
	 *
	 * @return bool
	 */
	public static function running(): bool {
		return self::$running;
	}

	/**
	 * Run a callable as the bulk job.
	 *
	 * @param callable $work The work.
	 * @return mixed What it returned.
	 */
	public static function run( callable $work ) {
		self::$running = true;
		try {
			return $work();
		} finally {
			self::$running = false;
		}
	}
}
