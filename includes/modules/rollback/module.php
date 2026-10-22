<?php
/**
 * Module: version rollback — go back to an earlier release from the settings screen, checked,
 * backed up and undone automatically if the site stops answering (zs-718).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'rollback',
	'name'        => static fn() => __( 'Version rollback', 'zinn-seo' ),
	'description' => static fn() => __( 'Go back to an earlier version of Zinn® SEO in one click if an update ever causes a problem — checked first, with your current version kept aside and put back automatically if your site stops answering.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'tools',
	'features'    => array( 'zs-718' ),
	'files'       => array( 'class-rollback.php' ),
	'contexts'    => array( 'admin', 'rest' ),
	'boot'        => static function (): void {
		require_once __DIR__ . '/class-rollback-rest.php';
		\ZinnDigital\ZinnSEO\Rollback\Rollback_Rest::init();
	},
);
