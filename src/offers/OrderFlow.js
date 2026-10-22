/**
 * Ordering one offer: choose what (a package, a domain), say what it is for (the page, the link
 * text, a brief), then pay for it on Zinn® in a new tab. Nothing is charged here.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../admin/api';
import { inNewTab, minor, priceLine, step } from './pure';

/**
 * Place the order and send the person to pay for it in a new tab.
 *
 * @param {Object} card    The offer card.
 * @param {Object} product The product.
 * @param {Object} extra   `package_id`, `domain`, `context` additions.
 * @return {Promise<Object>} The order.
 */
export function placeOrder( card, product, extra = {} ) {
	const finding = card.finding || {};
	return inNewTab( () =>
		seoFetch( '/offers/orders', {
			method: 'POST',
			data: {
				offer: card.offer,
				product: product.key,
				package_id: extra.package_id,
				domain: extra.domain,
				context: {
					finding: finding.id || '',
					variant: card.variant,
					...( extra.context || {} ),
				},
			},
		} )
	);
}

/**
 * The "it opened in a new tab" line, with the link again in case the browser blocked the tab.
 *
 * @param {Object} props
 * @param {Object} props.order The placed order.
 * @return {Element} Line.
 */
export function Placed( { order } ) {
	return (
		<Notice status="success" isDismissible={ false }>
			<p>
				{ __(
					'Your order is ready. Pay for it on Zinn® in the new tab — nothing has been charged yet.',
					'zinn-seo'
				) }{ ' ' }
				<a href={ order.url } target="_blank" rel="noopener noreferrer">
					{ __( 'Open it again', 'zinn-seo' ) }
					<span className="screen-reader-text">
						{ ' ' }
						{ __( '(opens in a new tab)', 'zinn-seo' ) }
					</span>
					<span aria-hidden="true"> ↗</span>
				</a>
			</p>
		</Notice>
	);
}

/**
 * Choose a marketplace package (links, articles, citations, monthly SEO).
 *
 * @param {Object}   props
 * @param {Object}   props.card    Card.
 * @param {Object}   props.product Product.
 * @param {Function} props.onPick  Called with `{listing, pkg}`.
 * @return {Element} Browser.
 */
function Browse( { card, product, onPick } ) {
	const [ words, setWords ] = useState( '' );
	const [ max, setMax ] = useState( '' );
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const search = () => {
		setBusy( true );
		setError( '' );
		const q = new URLSearchParams( {
			offer: card.offer,
			product: product.key,
		} );
		if ( words.trim() ) {
			q.set( 'q', words.trim() );
		}
		const cap = parseInt( max, 10 );
		if ( cap > 0 ) {
			q.set( 'max_price_minor', String( cap * 100 ) );
		}
		seoFetch( `/offers/listings?${ q.toString() }` )
			.then( ( r ) => setRows( r.listings || [] ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="zseo-stack">
			<div className="zseo-offer__filters">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Words to look for', 'zinn-seo' ) }
					help={ __(
						'A niche, a language or a metric, for example “finance”, “German” or “DR 50”.',
						'zinn-seo'
					) }
					value={ words }
					onChange={ setWords }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min="0"
					label={ __( 'Highest price (whole dollars)', 'zinn-seo' ) }
					value={ max }
					onChange={ setMax }
				/>
				<div>
					<Button
						variant="secondary"
						isBusy={ busy }
						onClick={ search }
					>
						{ __( 'Show offers', 'zinn-seo' ) }
					</Button>
				</div>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ busy && <Spinner /> }
			{ rows && rows.length === 0 && (
				<p>
					{ __(
						'Nothing matches. Try fewer words or a higher price.',
						'zinn-seo'
					) }
				</p>
			) }
			{ rows && rows.length > 0 && (
				<ul className="zseo-offer__listings">
					{ rows.map( ( listing ) => (
						<li key={ listing.id } className="zseo-offer__listing">
							<strong>{ listing.title }</strong>
							{ listing.summary && <p>{ listing.summary }</p> }
							<div className="zseo-offer__packages">
								{ listing.packages.map( ( pkg ) => (
									<Button
										key={ pkg.id }
										variant="secondary"
										onClick={ () =>
											onPick( { listing, pkg } )
										}
									>
										{ sprintf(
											/* translators: 1: package name, 2: price, 3: days to deliver. */
											__(
												'%1$s — %2$s, %3$d days',
												'zinn-seo'
											),
											pkg.name,
											minor(
												pkg.price_minor,
												card.currency
											),
											pkg.delivery_days
										) }
									</Button>
								) ) }
							</div>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Search a domain to register.
 *
 * @param {Object}   props
 * @param {Function} props.onPick Called with the chosen name.
 * @return {Element} Search.
 */
function DomainSearch( { onPick } ) {
	const [ query, setQuery ] = useState( '' );
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const search = () => {
		setBusy( true );
		setError( '' );
		seoFetch( '/offers/domains', {
			method: 'POST',
			data: { query: query.trim() },
		} )
			.then( ( r ) => setRows( r.results || [] ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="zseo-stack">
			<div className="zseo-offer__filters">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'The name you want', 'zinn-seo' ) }
					value={ query }
					onChange={ setQuery }
				/>
				<div>
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ ! query.trim() }
						onClick={ search }
					>
						{ __( 'Search', 'zinn-seo' ) }
					</Button>
				</div>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ rows && (
				<ul className="zseo-offer__listings">
					{ rows.map( ( r ) => (
						<li key={ r.fqdn } className="zseo-offer__listing">
							<strong>{ r.fqdn }</strong>{ ' ' }
							{ r.available && ! r.premium ? (
								<Button
									variant="secondary"
									onClick={ () => onPick( r.fqdn ) }
								>
									{ r.price &&
									typeof r.price.amount_minor === 'number'
										? sprintf(
												/* translators: %s: a price. */
												__(
													'Register for %s',
													'zinn-seo'
												),
												minor(
													r.price.amount_minor,
													r.price.currency || 'USD'
												)
											)
										: __( 'Register', 'zinn-seo' ) }
								</Button>
							) : (
								<span>
									{ __( 'Not available', 'zinn-seo' ) }
								</span>
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * The order dialog for one offer card.
 *
 * @param {Object}   props
 * @param {Object}   props.card    Card.
 * @param {Object}   props.product The product to order.
 * @param {Function} props.onClose Close it.
 * @param {Function} props.onDone  Called with the placed order.
 * @return {Element} Dialog.
 */
export default function OrderFlow( { card, product, onClose, onDone } ) {
	const kind = step( product );
	const [ picked, setPicked ] = useState( null );
	const [ domain, setDomain ] = useState( '' );
	const finding = card.finding || {};
	const [ target, setTarget ] = useState( finding.url || '' );
	const [ anchor, setAnchor ] = useState( '' );
	const [ brief, setBrief ] = useState(
		finding.title
			? sprintf(
					/* translators: %s: what the check found. */
					__( 'Found by Zinn® SEO: %s', 'zinn-seo' ),
					finding.title
				)
			: ''
	);
	const [ order, setOrder ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const go = ( extra ) => {
		setBusy( true );
		setError( '' );
		placeOrder( card, product, extra )
			.then( ( o ) => {
				setOrder( o );
				onDone( o );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const ready =
		kind === 'confirm' ||
		kind === 'open' ||
		( kind === 'browse' && picked ) ||
		( kind === 'domain' && domain );
	const context = {};
	if ( card.offer === 'links' || card.offer === 'citations' ) {
		context.target_url = target;
		context.anchor = anchor;
	}
	if ( kind === 'browse' ) {
		context.brief = brief;
	}
	return (
		<Modal
			className="zseo-offer__modal"
			title={ card.title }
			onRequestClose={ onClose }
		>
			<div className="zseo-stack">
				<p>{ card.body }</p>
				{ card.note && (
					<p className="zseo-offer__note">{ card.note }</p>
				) }
				<p className="zseo-offer__price">
					<strong>{ product.label }</strong>{ ' ' }
					{ priceLine( product.facts, card.currency ) }
				</p>
				{ ! order && kind === 'browse' && ! picked && (
					<Browse
						card={ card }
						product={ product }
						onPick={ setPicked }
					/>
				) }
				{ ! order && kind === 'browse' && picked && (
					<div className="zseo-stack">
						<p>
							{ sprintf(
								/* translators: 1: listing, 2: package, 3: price. */
								__(
									'You chose %1$s — %2$s, %3$s.',
									'zinn-seo'
								),
								picked.listing.title,
								picked.pkg.name,
								minor( picked.pkg.price_minor, card.currency )
							) }{ ' ' }
							<Button
								variant="link"
								onClick={ () => setPicked( null ) }
							>
								{ __( 'Choose another', 'zinn-seo' ) }
							</Button>
						</p>
						{ ( card.offer === 'links' ||
							card.offer === 'citations' ) && (
							<>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									type="url"
									label={ __(
										'The page the link should point to',
										'zinn-seo'
									) }
									value={ target }
									onChange={ setTarget }
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'The words of the link',
										'zinn-seo'
									) }
									value={ anchor }
									onChange={ setAnchor }
								/>
							</>
						) }
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Brief for the seller', 'zinn-seo' ) }
							value={ brief }
							onChange={ setBrief }
						/>
					</div>
				) }
				{ ! order && kind === 'domain' && ! domain && (
					<DomainSearch onPick={ setDomain } />
				) }
				{ ! order && kind === 'domain' && domain && (
					<p>
						{ domain }{ ' ' }
						<Button
							variant="link"
							onClick={ () => setDomain( '' ) }
						>
							{ __( 'Choose another', 'zinn-seo' ) }
						</Button>
					</p>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ order ? (
					<Placed order={ order } />
				) : (
					<div className="zseo-offer__actions">
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || ! ready }
							onClick={ () =>
								go( {
									package_id: picked
										? picked.pkg.id
										: undefined,
									domain: domain || undefined,
									context,
								} )
							}
						>
							{ kind === 'open'
								? __( 'Open on Zinn®', 'zinn-seo' )
								: __( 'Continue to payment', 'zinn-seo' ) }
							<span className="screen-reader-text">
								{ ' ' }
								{ __( '(opens in a new tab)', 'zinn-seo' ) }
							</span>
							<span aria-hidden="true"> ↗</span>
						</Button>
						<p className="zseo-offer__small">
							{ kind === 'open'
								? __(
										'Opens your Zinn® account in a new tab.',
										'zinn-seo'
									)
								: __(
										'Opens your Zinn® basket in a new tab. You see the full price, with any tax, before you pay; nothing is charged until you do.',
										'zinn-seo'
									) }
						</p>
					</div>
				) }
			</div>
		</Modal>
	);
}
