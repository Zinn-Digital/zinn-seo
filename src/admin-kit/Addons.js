/* Generated from wp/packages/zinn-admin-kit/src/js/Addons.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import { CrossSellCard } from './PromoCard';

/**
 * Add-ons (feature sh-r7): every product in the promotion registry, on our own screen. Unlike the
 * Overview, a card hidden there is still listed here, and can be shown again.
 *
 * @param {Object}   props
 * @param {Object}   props.kit       Boot data.
 * @param {Object}   props.prefs     The user's preferences.
 * @param {Function} props.savePrefs Save a preference change.
 * @return {Element} The screen.
 */
export default function Addons( { kit, prefs, savePrefs } ) {
	const dismissed = prefs.dismissed || [];
	// Every row, cross-sell first; no Hide button here — this is the page people come to on purpose.
	const cards = [ ...( kit.promotions || [] ) ].sort(
		( a, b ) => ( 'cross-sell' === b.slot ) - ( 'cross-sell' === a.slot )
	);

	return (
		<div className="zak-grid" data-zak-tour="addons">
			{ cards.map( ( card ) => (
				<CrossSellCard key={ card.id } card={ card } />
			) ) }
			{ dismissed.length > 0 && (
				<p className="zak-card--wide">
					<Button
						variant="link"
						onClick={ () => savePrefs( { restore_all: true } ) }
					>
						{ __(
							'Show hidden suggestions on the Overview again',
							'zinn-seo'
						) }
					</Button>
				</p>
			) }
		</div>
	);
}
