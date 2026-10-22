/**
 * The offers beside one finding: what solves it, priced up front, dismissible.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { viewHref } from '../admin-kit/router';
import OrderFlow from './OrderFlow';
import { priceLine, step } from './pure';
import './offers.scss';

/** Offers already counted as seen on this page view (a re-render is not a second view). */
const counted = new Set();

/**
 * Count a view of these cards, once per page view.
 *
 * @param {Array<Object>} cards Cards.
 * @return {void}
 */
function countSeen( cards ) {
	const ids = cards
		.map( ( c ) => c.id )
		.filter( ( id ) => ! counted.has( id ) );
	if ( ! ids.length ) {
		return;
	}
	ids.forEach( ( id ) => counted.add( id ) );
	seoFetch( '/offers/seen', { method: 'POST', data: { ids } } ).catch(
		() => {}
	);
}

/**
 * One offer card.
 *
 * @param {Object}   props
 * @param {Object}   props.card      Card.
 * @param {boolean}  props.canOrder  The person may place orders.
 * @param {Function} props.onDismiss Hide it.
 * @param {Function} props.onOrder   Called with a placed order.
 * @param {boolean}  props.compact   Beside a finding (smaller).
 * @return {Element} Card.
 */
export function OfferCard( {
	card,
	canOrder,
	onDismiss,
	onOrder,
	compact = true,
} ) {
	const [ open, setOpen ] = useState( null );
	if ( card.kind === 'plugin' ) {
		return (
			<div
				className={ `zseo-offer${ compact ? ' is-compact' : '' }` }
				data-offer={ card.offer || card.plugin }
			>
				<p className="zseo-offer__eyebrow">
					{ __( 'Free plugin from Zinn Digital®', 'zinn-seo' ) }
				</p>
				<h4 className="zseo-offer__title">{ card.title }</h4>
				<p className="zseo-offer__body">{ card.body }</p>
				<div className="zseo-offer__actions">
					<Button
						variant="secondary"
						href={ card.url }
						{ ...( card.external
							? { target: '_blank', rel: 'noopener noreferrer' }
							: {} ) }
					>
						{ card.cta }
						{ card.external && (
							<>
								<span className="screen-reader-text">
									{ ' ' }
									{ __( '(opens in a new tab)', 'zinn-seo' ) }
								</span>
								<span aria-hidden="true"> ↗</span>
							</>
						) }
					</Button>
					{ onDismiss && (
						<Button
							variant="tertiary"
							onClick={ () => onDismiss( card ) }
						>
							{ __( 'Not now', 'zinn-seo' ) }
						</Button>
					) }
				</div>
			</div>
		);
	}
	const products = card.products || [];
	return (
		<div
			className={ `zseo-offer${ compact ? ' is-compact' : '' }` }
			data-offer={ card.offer || card.plugin }
		>
			<p className="zseo-offer__eyebrow">
				{ __( 'From Zinn Digital®', 'zinn-seo' ) }
			</p>
			<h4 className="zseo-offer__title">{ card.title }</h4>
			<p className="zseo-offer__body">{ card.body }</p>
			{ card.referral && card.referral.url && (
				<p className="zseo-offer__body">
					{ __( 'Your referral link:', 'zinn-seo' ) }{ ' ' }
					<code>{ card.referral.url }</code>
				</p>
			) }
			<ul className="zseo-offer__products">
				{ products.map( ( p ) => (
					<li key={ p.key }>
						<span>{ p.label }</span>
						{ priceLine( p.facts, card.currency ) && (
							<strong>
								{ ' ' }
								{ priceLine( p.facts, card.currency ) }
							</strong>
						) }{ ' ' }
						{ canOrder && step( p ) === 'screen' && (
							<Button
								variant={
									p === products[ 0 ]
										? 'primary'
										: 'secondary'
								}
								size="compact"
								href={ viewHref( p.facts.screen || 'cdn' ) }
							>
								{ card.cta }
							</Button>
						) }
						{ canOrder && step( p ) !== 'screen' && (
							<Button
								variant={
									p === products[ 0 ]
										? 'primary'
										: 'secondary'
								}
								size="compact"
								onClick={ () => setOpen( p ) }
							>
								{ step( p ) === 'open' &&
									__( 'Open', 'zinn-seo' ) }
								{ step( p ) !== 'open' &&
									( p === products[ 0 ]
										? card.cta
										: __( 'Order this', 'zinn-seo' ) ) }
							</Button>
						) }
					</li>
				) ) }
			</ul>
			{ ! canOrder && (
				<p className="zseo-offer__small">
					{ __(
						'Ask the site owner to order this: ordering needs the right to spend the account’s money.',
						'zinn-seo'
					) }
				</p>
			) }
			{ onDismiss && (
				<div className="zseo-offer__actions">
					<Button
						variant="tertiary"
						onClick={ () => onDismiss( card ) }
					>
						{ __( 'Not now', 'zinn-seo' ) }
					</Button>
				</div>
			) }
			{ open && (
				<OrderFlow
					card={ card }
					product={ open }
					onClose={ () => setOpen( null ) }
					onDone={ ( o ) => onOrder && onOrder( o ) }
				/>
			) }
		</div>
	);
}

/**
 * The offers beside a Fix-it finding.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.offers Cards the server attached.
 * @param {Object}        props.data   Boot data.
 * @return {Element|null} Offers.
 */
export default function OfferSlot( { offers, data } ) {
	const [ hidden, setHidden ] = useState( [] );
	const cards = ( offers || [] ).filter( ( c ) => ! hidden.includes( c.id ) );
	const shown = cards.map( ( c ) => c.id ).join();
	// Counted once per page view (see `countSeen`), so the ids are the only dependency.
	useEffect( () => countSeen( cards ), [ shown ] ); // eslint-disable-line react-hooks/exhaustive-deps
	if ( ! cards.length ) {
		return null;
	}
	const canOrder = !! ( data && data.offers && data.offers.canOrder );
	return (
		<aside
			className="zseo-offers"
			aria-label={ __( 'Services that solve this', 'zinn-seo' ) }
		>
			{ cards.map( ( card ) => (
				<OfferCard
					key={ card.id }
					card={ card }
					canOrder={ canOrder }
					onDismiss={ ( c ) => {
						setHidden( ( h ) => [ ...h, c.id ] );
						seoFetch( '/offers/dismiss', {
							method: 'POST',
							data: { id: c.id },
						} ).catch( () => {} );
					} }
				/>
			) ) }
		</aside>
	);
}
