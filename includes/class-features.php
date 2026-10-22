<?php
/**
 * The feature registry: what this build of the plugin actually does (zs-247).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ONE list, read by the Plans screen, REST, WP-CLI, MCP and the readme generator, so none of them
 * can claim a feature the code does not have. A feature is here only when the code that does it
 * ships: the core's own features below, plus each module's `features` (its definition names the
 * catalogue ids it implements). The plan matrix the admin kit shows is generated from the same
 * ids in `docs/plugins-overhaul/features.json` (product `zseo`), and a unit test fails when the
 * two disagree.
 */
final class Features {

	/**
	 * The core's own features: catalogue id => [tier, area].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function core(): array {
		return array(
			'zs-150'    => array( 'free', 'performance' ),
			'zs-151'    => array( 'free', 'performance' ),
			'zs-152'    => array( 'free', 'performance' ),
			'zs-153'    => array( 'free', 'performance' ),
			'zs-154'    => array( 'free', 'performance' ),
			'zs-155'    => array( 'free', 'performance' ),
			'zs-156'    => array( 'free', 'performance' ),
			'zs-158'    => array( 'free', 'data' ),
			'zs-159'    => array( 'free', 'performance' ),
			'zs-234'    => array( 'free', 'admin' ),
			'zs-237'    => array( 'pro', 'admin' ),
			'zs-240'    => array( 'free', 'admin' ),
			'zs-241'    => array( 'free', 'admin' ),
			'zs-243'    => array( 'free', 'admin' ),
			'zs-244'    => array( 'free', 'admin' ),
			'zs-245'    => array( 'free', 'developers' ),
			'zs-246'    => array( 'free', 'developers' ),
			'zs-247'    => array( 'free', 'developers' ),
			'zs-249'    => array( 'free', 'data' ),
			'zs-inn-15' => array( 'free', 'performance' ),
			// Stage X1A: module switches, the accessible admin, no-trace mode.
			'zs-715'    => array( 'free', 'admin' ),
			'zs-729'    => array( 'free', 'admin' ),
			'zs-747'    => array( 'free', 'footprint' ),
		);
	}

	/**
	 * Every feature this build has: id => {tier, area, module}.
	 *
	 * @return array<string, array{tier: string, area: string, module: string}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::core() as $id => $row ) {
			$out[ $id ] = array(
				'tier'   => $row[0],
				'area'   => $row[1],
				'module' => 'core',
			);
		}
		foreach ( Modules::discover() as $module => $def ) {
			// A free module may hold features that need Pro (`pro_features`): the integrations
			// module is one list of plugins, some of whose parts are free and most are not.
			$pro = array_map( 'strval', (array) ( $def['pro_features'] ?? array() ) );
			foreach ( (array) $def['features'] as $id ) {
				$out[ (string) $id ] = array(
					// An Agency-plan module is a Pro feature sold on the Agency plan (the plans
					// matrix's override), so the catalogue's tier for it is `pro`.
					'tier'   => in_array( (string) $id, $pro, true ) || 'agency' === $def['tier'] ? 'pro' : (string) $def['tier'],
					'area'   => (string) ( $def['area'] ?? $module ),
					'module' => (string) $module,
				);
			}
		}
		ksort( $out, SORT_NATURAL );

		return $out;
	}
}
