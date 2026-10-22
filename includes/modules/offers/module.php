<?php
/**
 * Module: what Zinn® sells, offered beside the finding it solves — links to a page, articles,
 * domains, a Wayback restore, domain checks, review monitoring, local citations, uptime watching,
 * hosting and migration, the footprint check, a hack clean-up and backups, index watching, our
 * other plugins, expert help, the referral programme and the SEO tool bundle (zs-600 … zs-616).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'          => 'offers',
	'name'        => static fn() => __( 'Services from Zinn®', 'zinn-seo' ),
	'description' => static fn() => __( 'When a check finds a problem, see the Zinn® service or plugin that solves it right beside it — links, articles, faster hosting, a hack clean-up, expert help — priced up front and ordered in a new tab. Offers can be dismissed and never appear for a client login.', 'zinn-seo' ),
	'tier'        => 'free',
	'area'        => 'credits',
	'requires'    => array( 'connect' ),
	'features'    => array( 'zs-600', 'zs-601', 'zs-602', 'zs-603', 'zs-604', 'zs-605', 'zs-606', 'zs-607', 'zs-608', 'zs-609', 'zs-610', 'zs-611', 'zs-612', 'zs-613', 'zs-614', 'zs-615', 'zs-616', 'zs-657', 'zs-659', 'zs-660' ),
	'contexts'    => array( 'admin', 'rest' ),
	// zs-715: loaded only when the module boots.
	'files'       => array( 'class-copy.php', 'class-findings.php', 'class-offers.php', 'class-offers-rest.php' ),
	'boot'        => static function ( string $context ): void {
		\ZinnDigital\ZinnSEO\Offers\Offers::boot( $context );
		\ZinnDigital\ZinnSEO\Offers\Offers_Rest::init();
	},
);
