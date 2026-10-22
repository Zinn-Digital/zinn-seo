/* Generated from wp/packages/zinn-admin-kit/src/js/plans.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, _n, sprintf } from '@wordpress/i18n';

/** Plan ranks, lowest first. A plan includes every feature of the plans below it. */
export const RANK = { free: 0, personal: 1, business: 2, agency: 3 };

/**
 * Does a plan include a feature whose lowest plan is `min`?
 *
 * @param {string} plan Plan id.
 * @param {string} min  The feature's lowest plan.
 * @return {boolean} Included.
 */
export function includes( plan, min ) {
	return plan in RANK && min in RANK && RANK[ plan ] >= RANK[ min ];
}

/**
 * A plan's name, translated.
 *
 * @param {string} plan Plan id.
 * @return {string} Name.
 */
export function planName( plan ) {
	switch ( plan ) {
		case 'personal':
			return __( 'Personal', 'zinn-seo' );
		case 'business':
			return __( 'Business', 'zinn-seo' );
		case 'agency':
			return __( 'Agency', 'zinn-seo' );
		default:
			return __( 'Free', 'zinn-seo' );
	}
}

/**
 * How many sites a plan covers, in words.
 *
 * @param {Object} plan `{ id, sites }`, sites null = unlimited.
 * @return {string} Text.
 */
export function planSites( plan ) {
	if ( 'free' === plan.id ) {
		return __( 'Any number of sites', 'zinn-seo' );
	}
	if ( null === plan.sites ) {
		return __( 'Unlimited sites', 'zinn-seo' );
	}
	return sprintf(
		/* translators: %d: number of sites. */
		_n( '%d site', '%d sites', plan.sites, 'zinn-seo' ),
		plan.sites
	);
}

/**
 * The badge in the header: the edition this site runs.
 *
 * @param {Object} licence Licence snapshot from the boot data.
 * @return {string} Text.
 */
export function badgeText( licence ) {
	if ( ! licence || ! licence.premium ) {
		return __( 'Free', 'zinn-seo' );
	}
	const name = licence.legacy
		? __( 'Pro', 'zinn-seo' )
		: planName( licence.plan );
	return licence.trial && licence.trial.active
		? sprintf(
				/* translators: %s: plan name. */
				__( '%s trial', 'zinn-seo' ),
				name
			)
		: name;
}

/**
 * The cards a user has not hidden, for one slot. Exported for the tests.
 *
 * @param {Array<Object>} promotions Resolved cards.
 * @param {Array<string>} dismissed  Dismissed ids.
 * @param {string}        slot       `promo` or `cross-sell`.
 * @return {Array<Object>} Cards to show.
 */
export function visibleCards( promotions, dismissed, slot ) {
	return ( promotions || [] ).filter(
		( card ) =>
			card.slot === slot && ! ( dismissed || [] ).includes( card.id )
	);
}
