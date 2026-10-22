/**
 * Pure helpers of the offers: prices in words, order states in words, and the new-tab opener.
 */
import { __, sprintf } from '@wordpress/i18n';

import { readerLocale } from '../tools/pure';

/**
 * An amount in minor units (cents) as money. Integers in, a string out (never a float stored).
 *
 * @param {number|null} amount   Minor units; null or undefined is "not known".
 * @param {string}      currency ISO code.
 * @param {string}      locale   Locale (tests).
 * @return {string} Money.
 */
export function minor( amount, currency = 'USD', locale = readerLocale() ) {
	if ( amount === null || amount === undefined || Number.isNaN( amount ) ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( amount / 100 );
	} catch {
		return `${ ( amount / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}

/**
 * A product's price line, from the facts Zinn® sent (read from the live row).
 *
 * @param {Object} facts    Product facts.
 * @param {string} currency ISO code.
 * @param {string} locale   Locale (tests).
 * @return {string} Words, or '' when there is no price to show (an account page).
 */
export function priceLine( facts, currency = 'USD', locale = readerLocale() ) {
	const f = facts || {};
	if ( typeof f.unit_micros === 'number' ) {
		// Paid per use from Zinn® credits (brand page 1): under two cents a check, so the price
		// is shown with the decimals that make it true, and a month of weekly checks beside it.
		let each;
		try {
			each = new Intl.NumberFormat( locale, {
				style: 'currency',
				currency,
				maximumFractionDigits: 4,
			} ).format( f.unit_micros / 1000000 );
		} catch {
			each = `${ ( f.unit_micros / 1000000 ).toFixed( 4 ) } ${ currency }`;
		}
		const month = minor(
			Math.round( ( f.monthly_micros || 0 ) / 10000 ),
			currency,
			locale
		);
		return sprintf(
			/* translators: 1: a price per check, e.g. "$0.0185"; 2: a price a month, e.g. "$0.08". */
			__( '%1$s a check, about %2$s a month checked weekly', 'zinn-seo' ),
			each,
			month
		);
	}
	if ( typeof f.from_minor === 'number' ) {
		return sprintf(
			/* translators: %s: a price, e.g. "$6.99". */
			__( 'from %s', 'zinn-seo' ),
			minor( f.from_minor, currency, locale )
		);
	}
	if ( typeof f.price_minor !== 'number' ) {
		return '';
	}
	const money = minor( f.price_minor, currency, locale );
	if ( f.per_gb_micros && typeof f.per_gb_micros.eu_na === 'number' ) {
		// The Zinn® CDN: metered per GB, with a monthly minimum the traffic counts towards.
		return sprintf(
			/* translators: 1: price per GB, e.g. "$0.02", 2: the monthly minimum, e.g. "$2.00". */
			__( '%1$s per GB, from %2$s a month', 'zinn-seo' ),
			minor( f.per_gb_micros.eu_na / 10000, currency, locale ),
			money
		);
	}
	switch ( f.interval ) {
		case 'monthly':
			/* translators: %s: a price. */
			return sprintf( __( '%s a month', 'zinn-seo' ), money );
		case 'annual':
		case 'yearly':
			/* translators: %s: a price. */
			return sprintf( __( '%s a year', 'zinn-seo' ), money );
		case 'one_off':
			/* translators: %s: a price. */
			return sprintf( __( '%s once', 'zinn-seo' ), money );
		default:
			return money;
	}
}

/**
 * An order's state in words.
 *
 * @param {string} status The API's status.
 * @return {Array<string>} [class, words].
 */
export function statusText( status ) {
	const words = {
		opened: [ 'is-info', __( 'Opened on Zinn®', 'zinn-seo' ) ],
		basket: [
			'is-warn',
			__( 'In your basket — waiting for you to pay', 'zinn-seo' ),
		],
		awaiting_payment: [
			'is-warn',
			__( 'Waiting for payment', 'zinn-seo' ),
		],
		payment_failed: [
			'is-bad',
			__( 'Payment did not go through', 'zinn-seo' ),
		],
		paid: [ 'is-good', __( 'Paid — being prepared', 'zinn-seo' ) ],
		brief_needed: [
			'is-warn',
			__( 'Paid — the seller needs your brief', 'zinn-seo' ),
		],
		in_progress: [ 'is-good', __( 'In progress', 'zinn-seo' ) ],
		delivered: [
			'is-good',
			__( 'Delivered — check it and accept', 'zinn-seo' ),
		],
		done: [ 'is-good', __( 'Done', 'zinn-seo' ) ],
		disputed: [ 'is-bad', __( 'In dispute', 'zinn-seo' ) ],
		cancelled: [ 'is-info', __( 'Cancelled', 'zinn-seo' ) ],
		refunded: [ 'is-info', __( 'Refunded', 'zinn-seo' ) ],
		expired: [ 'is-info', __( 'The basket expired', 'zinn-seo' ) ],
	};
	return words[ status ] || [ 'is-info', status ];
}

/**
 * Can the buyer say where a bought link went live?
 *
 * @param {Object} order An order.
 * @return {boolean} Yes.
 */
export function wantsPlacement( order ) {
	return (
		!! order &&
		[ 'links', 'citations' ].includes( order.offer ) &&
		! order.placement_url &&
		[ 'paid', 'in_progress', 'delivered', 'done' ].includes( order.status )
	);
}

/**
 * Open a tab NOW (inside the click, so no pop-up blocker refuses it) and point it at the order's
 * address once Zinn® answers. Closes the tab when the order could not be placed.
 *
 * @param {Function} place Returns a promise of the order.
 * @param {Object}   win   The window (tests).
 * @return {Promise<Object>} The order.
 */
export function inNewTab( place, win = window ) {
	const tab = win.open( 'about:blank', '_blank' );
	return place().then(
		( order ) => {
			if ( tab && order && order.url ) {
				try {
					tab.opener = null;
				} catch {
					// A browser that refuses the property keeps the tab working; nothing to undo.
				}
				tab.location.href = order.url;
			}
			return order;
		},
		( error ) => {
			if ( tab ) {
				tab.close();
			}
			throw error;
		}
	);
}

/**
 * The first product of a card to order, and whether it needs a choice first.
 *
 * @param {Object} product A product.
 * @return {string} `confirm` | `browse` | `domain` | `open` | `screen` (the plugin's own screen).
 */
export function step( product ) {
	switch ( product && product.kind ) {
		case 'market':
			return 'browse';
		case 'domain':
			return 'domain';
		case 'aged':
		case 'account':
		case 'credits':
			return 'open';
		case 'cdn':
			return 'screen';
		default:
			return 'confirm';
	}
}

/**
 * The done-for-you migration (Q348) out of the Services list: the `experts` card narrowed to
 * its `migration` product, or null when Zinn® does not offer it (the row is off sale, or the
 * site is not connected). The price stays the one Zinn® sent; nothing is typed here.
 *
 * @param {Array<Object>} cards The Services screen's cards.
 * @return {Object|null} Card.
 */
export function migrationCard( cards ) {
	const experts = ( cards || [] ).find(
		( c ) => c && c.kind === 'offer' && c.offer === 'experts'
	);
	const product =
		experts &&
		( experts.products || [] ).find( ( p ) => p && p.key === 'migration' );
	return product ? { ...experts, products: [ product ] } : null;
}
