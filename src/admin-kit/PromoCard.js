/* Generated from wp/packages/zinn-admin-kit/src/js/PromoCard.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
} from '@wordpress/components';

import { visibleCards } from './plans';

/** The id under which the whole hosting card is dismissed. */
export const PROMO_ID = 'promo';

/**
 * The Zinn Digital hosting card (feature adm-5), with ZinnHub and PBN.ltd beside it (owner, D7).
 * Drawn only by the shell, so only on this plugin's own screens; dismissed per user.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.promotions Resolved cards.
 * @param {Array<string>} props.dismissed  Dismissed ids.
 * @param {Function}      props.onDismiss  Called with an id.
 * @return {Element|null} The card.
 */
export function PromoCard( { promotions, dismissed, onDismiss } ) {
	const cards = visibleCards( promotions, dismissed, 'promo' );
	if ( ( dismissed || [] ).includes( PROMO_ID ) || 0 === cards.length ) {
		return null;
	}
	const [ lead, ...rest ] = cards;

	return (
		<Card className="zak-card zak-promo" data-zak-card={ PROMO_ID }>
			<CardHeader>
				<h2 className="zak-card__title">{ lead.title }</h2>
				<Button
					variant="tertiary"
					size="small"
					onClick={ () => onDismiss( PROMO_ID ) }
				>
					{ __( 'Hide', 'zinn-seo' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<p>{ lead.body }</p>
				<p>
					<ExternalLink href={ lead.url }>{ lead.cta }</ExternalLink>
				</p>
				{ rest.length > 0 && (
					<ul className="zak-promo__more">
						{ rest.map( ( card ) => (
							<li key={ card.id }>
								<strong>{ card.title }</strong>{ ' ' }
								<span>{ card.body }</span>{ ' ' }
								<ExternalLink href={ card.url }>
									{ card.cta }
								</ExternalLink>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * One cross-sell card (features adm-4, adm-8): the other plugin, which swaps to "integration
 * active" when that plugin runs on this site, or the bundle.
 *
 * @param {Object}   props
 * @param {Object}   props.card      Resolved card.
 * @param {Function} props.onDismiss Called with the card id; omitted where the card cannot be hidden.
 * @return {Element} The card.
 */
export function CrossSellCard( { card, onDismiss } ) {
	const active = 'active' === card.state;

	return (
		<Card
			className={ `zak-card zak-cross${ active ? ' is-active' : '' }` }
			data-zak-card={ card.id }
		>
			<CardHeader>
				<h2 className="zak-card__title">
					{ active ? card.activeTitle : card.title }
				</h2>
				{ onDismiss && (
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onDismiss( card.id ) }
					>
						{ __( 'Hide', 'zinn-seo' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<p>{ active ? card.activeBody : card.body }</p>
				{ ! active && 'installed' === card.state && card.actionUrl && (
					<p>
						<Button variant="secondary" href={ card.actionUrl }>
							{ __(
								'Activate it on the Plugins screen',
								'zinn-seo'
							) }
						</Button>
					</p>
				) }
				{ ! active && 'available' === card.state && card.actionUrl && (
					<p>
						<Button variant="secondary" href={ card.actionUrl }>
							{ __( 'Install it', 'zinn-seo' ) }
						</Button>
					</p>
				) }
				{ ! active && (
					<p>
						<ExternalLink href={ card.url }>
							{ card.cta }
						</ExternalLink>
					</p>
				) }
			</CardBody>
		</Card>
	);
}
