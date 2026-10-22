/**
 * "Let our team do it" on the Move from another SEO plugin screen (Q348, zs-614): the
 * done-for-you migration, priced from Zinn®'s live catalogue row and ordered like every other
 * offer — a basket paid on Zinn® in a new tab, never here.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { viewHref } from '../admin-kit/router';
import { OfferCard } from './OfferSlot';
import { migrationCard } from './pure';

/**
 * The card, or nothing when Zinn® does not offer it here (not connected, switched off, or the
 * catalogue row is not on sale).
 *
 * @param {Object} props
 * @param {Object} props.data   Boot data.
 * @param {string} props.source The plugin being moved from (its id), for the staff brief.
 * @return {Element|null} Card.
 */
export default function TeamMove( { data, source } ) {
	const offers = ( data && data.offers ) || {};
	const [ card, setCard ] = useState( null );
	useEffect( () => {
		if ( ! offers.show || ! offers.connected ) {
			return;
		}
		seoFetch( '/offers' )
			.then( ( r ) => setCard( migrationCard( r.cards || [] ) ) )
			.catch( () => setCard( null ) );
	}, [ offers.show, offers.connected ] );
	if ( ! offers.show ) {
		return null;
	}
	if ( ! offers.connected ) {
		return (
			<p className="zseo-muted">
				{ __(
					'Rather have our team move it for you? Connect this site to Zinn® to see the done-for-you migration and its price.',
					'zinn-seo'
				) }{ ' ' }
				<Button variant="link" href={ viewHref( 'services' ) }>
					{ __( 'See our services', 'zinn-seo' ) }
				</Button>
			</p>
		);
	}
	if ( ! card ) {
		return null;
	}
	return (
		<OfferCard
			compact={ false }
			canOrder={ !! offers.canOrder }
			card={ {
				...card,
				finding: { id: source ? `import:${ source }` : 'import' },
				title: __( 'Let our team do it', 'zinn-seo' ),
				body: __(
					'Our team moves this site for you with this same importer, checks every page against the old plugin, and sends you the report showing nothing changed. You share a login with us yourself; this plugin never signs anyone in.',
					'zinn-seo'
				),
				cta: __( 'Order the migration', 'zinn-seo' ),
			} }
		/>
	);
}
