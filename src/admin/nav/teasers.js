import { __ } from '@wordpress/i18n';

import ProTeaser from '../ProTeaser';
import { PRO_SCREENS, proPoints } from './sections';

/**
 * The Pro screens' names: the same words the Pro plugin's own screens use.
 *
 * @return {Object<string, string>} Screen id → name.
 */
function proNames() {
	return {
		autopilot: __( 'Fix it on autopilot', 'zinn-seo' ),
		linkplan: __( 'Link plan', 'zinn-seo' ),
		guard: __( 'Safe updates', 'zinn-seo' ),
		referrals: __( 'AI visitors and earnings', 'zinn-seo' ),
		pages: __( 'Pages from a sheet', 'zinn-seo' ),
		reports: __( 'Client reports', 'zinn-seo' ),
		tasks: __( 'Tasks', 'zinn-seo' ),
		agency: __( 'Agency', 'zinn-seo' ),
	};
}

/**
 * On the free plugin, the Pro screens are listed in the navigation (marked Pro) and each opens a
 * card saying what it does, with the way to the plans. Nothing of the Pro screen runs: no data is
 * asked for and no Pro route exists. Never for a client login or a white label that hides the
 * plans (an agency's client is not sold to), and never on Pro (a Pro screen switched off stays
 * out of the navigation, as before).
 *
 * @param {Object} data The admin app's boot data.
 * @param {Object} kit  The kit's boot data.
 * @return {Array<Object>} Routes `{ id, label, render }`.
 */
export function proTeasers( data = {}, kit = {} ) {
	if (
		data.plan !== 'free' ||
		data.client ||
		( kit.hidden || [] ).includes( 'plans' )
	) {
		return [];
	}
	const names = proNames();
	const points = proPoints();
	return PRO_SCREENS.map( ( id ) => ( {
		id,
		label: names[ id ],
		render: () => (
			<div className="zseo-admin zseo-pro-screen">
				<ProTeaser title={ names[ id ] } points={ points[ id ] } />
			</div>
		),
	} ) );
}
