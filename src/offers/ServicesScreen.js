/**
 * SEO → Services: everything Zinn® can do for this site, priced up front, and the orders placed
 * from here with where each one is now.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { Loaded, useLoad } from '../tools/parts';
import { OfferCard } from './OfferSlot';
import { minor, statusText, wantsPlacement } from './pure';
import './offers.scss';

/** Which group each offer sits in. */
const GROUPS = {
	links: 'grow',
	content: 'grow',
	citations: 'grow',
	domains: 'grow',
	vetting: 'grow',
	wayback: 'grow',
	reputation: 'grow',
	hosting: 'run',
	uptime: 'run',
	security: 'run',
	indexing: 'run',
	footprint: 'run',
	services: 'run',
	cdn: 'run',
	backups: 'run',
	experts: 'team',
	referral: 'team',
	support: 'team',
	bundle: 'team',
	// Stage X1B: the managed AI and agency extras.
	update_guard: 'run',
	managed_ai: 'run',
	reseller_data: 'team',
};

/**
 * The group titles.
 *
 * @return {Array<Array<string>>} [id, title].
 */
function groups() {
	return [
		[ 'grow', __( 'Grow your traffic', 'zinn-seo' ) ],
		[ 'run', __( 'Keep the site fast and safe', 'zinn-seo' ) ],
		[ 'plugin', __( 'Our free plugins', 'zinn-seo' ) ],
		[ 'team', __( 'Expert help, bundles and earning', 'zinn-seo' ) ],
	];
}

/**
 * Tell Zinn® where a bought link went live (it is then watched).
 *
 * @param {Object}   props
 * @param {Object}   props.order   Order.
 * @param {Function} props.onSaved Reload.
 * @return {Element} Form.
 */
function Placement( { order, onSaved } ) {
	const [ url, setUrl ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	return (
		<div className="zseo-offer__placement">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="url"
				label={ __( 'Where did your link go live?', 'zinn-seo' ) }
				help={ __(
					'Paste the page on the other website. We check the link is there and keep watching it.',
					'zinn-seo'
				) }
				value={ url }
				onChange={ setUrl }
			/>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || ! url.trim() }
					onClick={ () => {
						setBusy( true );
						setError( '' );
						seoFetch( `/offers/orders/${ order.id }/placement`, {
							method: 'POST',
							data: { url: url.trim() },
						} )
							.then( onSaved )
							.catch( ( e ) => setError( errorText( e ) ) )
							.finally( () => setBusy( false ) );
					} }
				>
					{ __( 'Watch this link', 'zinn-seo' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * The orders placed from this site.
 *
 * @param {Object} props
 * @param {Object} props.load The orders load.
 * @return {Element} Orders.
 */
function Orders( { load } ) {
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'Your orders', 'zinn-seo' ) }</h2>
				<Loaded load={ load }>
					{ ( data ) =>
						( data.orders || [] ).length === 0 ? (
							<p>
								{ __(
									'Nothing ordered from this site yet.',
									'zinn-seo'
								) }
							</p>
						) : (
							<ul className="zseo-offer__orders">
								{ data.orders.map( ( o ) => {
									const [ cls, words ] = statusText(
										o.status
									);
									return (
										<li
											key={ o.id }
											className="zseo-offer__order"
										>
											<div>
												<strong>
													{ o.label || o.offer }
												</strong>{ ' ' }
												<span
													className={ `zseo-offer__status ${ cls }` }
												>
													{ words }
												</span>
											</div>
											<div className="zseo-offer__small">
												{ new Date(
													o.created_at
												).toLocaleDateString() }
												{ typeof o.total_minor ===
													'number' &&
													` · ${ minor( o.total_minor, o.currency || 'USD' ) }` }
												{ o.context &&
													o.context.target_url &&
													` · ${ o.context.target_url }` }
												{ o.placement_url &&
													` · ${ o.placement_url }` }
												{ o.watched &&
													` · ${ __( 'link watched', 'zinn-seo' ) }` }
											</div>
											<a
												href={ o.url }
												target="_blank"
												rel="noopener noreferrer"
											>
												{ [
													'basket',
													'awaiting_payment',
													'payment_failed',
												].includes( o.status )
													? __(
															'Pay on Zinn®',
															'zinn-seo'
														)
													: __(
															'See it on Zinn®',
															'zinn-seo'
														) }
												<span className="screen-reader-text">
													{ ' ' }
													{ __(
														'(opens in a new tab)',
														'zinn-seo'
													) }
												</span>
												<span aria-hidden="true">
													{ ' ' }
													↗
												</span>
											</a>
											{ wantsPlacement( o ) && (
												<Placement
													order={ o }
													onSaved={ () =>
														load.reload()
													}
												/>
											) }
										</li>
									);
								} ) }
							</ul>
						)
					}
				</Loaded>
			</CardBody>
		</Card>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function ServicesScreen( { data } ) {
	const load = useLoad( '/offers' );
	// A site not connected to Zinn has no orders to ask for: no request, no failed call in the
	// console (T1 found a 409 here on every visit to a fresh site's Services screen).
	const connected = !! ( load.data && load.data.connected );
	const orders = useLoad( connected ? '/offers/orders' : null );
	const [ dismissed, setDismissed ] = useState( [] );
	return (
		<div
			className="zseo-admin zseo-services"
			role="region"
			aria-label={ __( 'Services', 'zinn-seo' ) }
		>
			<Loaded load={ load }>
				{ ( s ) => {
					if ( ! s.connected ) {
						return (
							<Card className="zseo-connect">
								<CardBody className="zseo-stack">
									<h2 className="zseo-hero">
										{ __(
											'Links, writers, hosting and expert help, ordered from here',
											'zinn-seo'
										) }
									</h2>
									<p className="zseo-lead">
										{ __(
											'Connect this site to a Zinn® account to see what each service costs and order it. You pay on Zinn®; nothing is charged from WordPress.',
											'zinn-seo'
										) }
									</p>
									<div>
										<Button
											variant="primary"
											href={ viewHref( 'credits' ) }
										>
											{ __(
												'Connect this site',
												'zinn-seo'
											) }
										</Button>
									</div>
								</CardBody>
							</Card>
						);
					}
					const cards = ( s.cards || [] ).filter(
						( c ) => ! c.dismissed && ! dismissed.includes( c.id )
					);
					const hiddenCount = ( s.cards || [] ).length - cards.length;
					return (
						<div className="zseo-stack">
							<div className="zseo-creditline">
								<span>
									{ __(
										'Prices are read from Zinn® now and shown before any tax. You pay on Zinn® in a new tab.',
										'zinn-seo'
									) }
								</span>
								<GuideLink data={ data } id="services" />
							</div>
							{ s.error && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ s.error }
								</Notice>
							) }
							{ groups().map( ( [ id, title ] ) => {
								const mine = cards.filter( ( c ) =>
									id === 'plugin'
										? c.kind === 'plugin'
										: c.kind !== 'plugin' &&
											GROUPS[ c.offer ] === id
								);
								if ( ! mine.length ) {
									return null;
								}
								return (
									<section key={ id } className="zseo-stack">
										<h2>{ title }</h2>
										<div className="zseo-offers is-grid">
											{ mine.map( ( card ) => (
												<OfferCard
													key={ card.id }
													card={ card }
													compact={ false }
													canOrder={ !! s.canOrder }
													onOrder={ () =>
														orders.reload()
													}
													onDismiss={ ( c ) => {
														setDismissed( ( d ) => [
															...d,
															c.id,
														] );
														seoFetch(
															'/offers/dismiss',
															{
																method: 'POST',
																data: {
																	id: c.id,
																},
															}
														).catch( () => {} );
													} }
												/>
											) ) }
										</div>
									</section>
								);
							} ) }
							{ s.bundle && s.bundle.url && (
								<p>
									<a
										href={ s.bundle.url }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ sprintf(
											/* translators: %d: a saving in percent. */
											__(
												'Page Builder Sandwich Pro and Tranzly™ Pro together: save up to %d%% as a bundle',
												'zinn-seo'
											),
											s.bundle.percent
										) }
										<span className="screen-reader-text">
											{ ' ' }
											{ __(
												'(opens in a new tab)',
												'zinn-seo'
											) }
										</span>
										<span aria-hidden="true"> ↗</span>
									</a>
								</p>
							) }
							{ hiddenCount > 0 && (
								<p>
									<Button
										variant="link"
										onClick={ () =>
											seoFetch( '/offers/dismiss', {
												method: 'POST',
												data: {
													id: '*',
													restore: true,
												},
											} ).then( () => {
												setDismissed( [] );
												load.reload();
											} )
										}
									>
										{ sprintf(
											/* translators: %d: number of hidden offers. */
											__(
												'Show the %d offers you hid',
												'zinn-seo'
											),
											hiddenCount
										) }
									</Button>
								</p>
							) }
						</div>
					);
				} }
			</Loaded>
			{ connected && <Orders load={ orders } /> }
		</div>
	);
}
