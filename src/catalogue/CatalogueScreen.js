/**
 * Zinn® price list (zs-650): everything Zinn® sells that helps this site, read live
 * from Zinn® — so a new service or a new price shows here with no plugin update — with the
 * managed AI and agency services on their own tabs (zs-655, zs-656, zs-661). Buying opens the
 * service's page in the person's Zinn® account in a new tab; nothing is bought from WordPress.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TabPanel,
} from '@wordpress/components';

import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { seoFetch } from '../admin/api';
import { inNewTab } from '../offers/pure';
import { Loaded, NewTab, Refusal, useLoad } from '../tools/parts';
import { readerLocale } from '../tools/pure';
import { amount, groups, holds, inContexts, OWN_TABS } from './pure';
import './catalogue.scss';

/**
 * Plain names for the places a service is used. A place this plugin does not know yet still
 * shows, under "More from Zinn".
 *
 * @return {Object<string, string>} Context => title.
 */
export function contextTitles() {
	return {
		rank: __( 'Rank tracking', 'zinn-seo' ),
		ai: __( 'Visibility in AI answers', 'zinn-seo' ),
		index: __( 'Indexing checks', 'zinn-seo' ),
		audit: __( 'Site audit', 'zinn-seo' ),
		links: __( 'Backlink monitoring', 'zinn-seo' ),
		keywords: __( 'Keyword research', 'zinn-seo' ),
		mentions: __( 'Brand mentions', 'zinn-seo' ),
		local: __( 'Local rankings', 'zinn-seo' ),
		domain_overview: __( 'Competitor and domain reports', 'zinn-seo' ),
		visibility: __( 'Competitor tracking', 'zinn-seo' ),
		alerts: __( 'Domain alerts', 'zinn-seo' ),
		history: __( 'Domain score history', 'zinn-seo' ),
		shopping: __( 'Product prices and shelves', 'zinn-seo' ),
		reviews: __( 'Reviews', 'zinn-seo' ),
		reports: __( 'Client reports', 'zinn-seo' ),
		footprint: __( 'Network footprint checks', 'zinn-seo' ),
		guard: __( 'Uptime monitoring', 'zinn-seo' ),
		update_guard: __( 'Safe updates', 'zinn-seo' ),
		webmaster: __( 'AI Webmaster', 'zinn-seo' ),
		chat_agent: __( 'AI chat for your visitors', 'zinn-seo' ),
		reseller: __( 'Reselling under your brand', 'zinn-seo' ),
		services: __( 'Done-for-you services', 'zinn-seo' ),
		more: __( 'More from Zinn®', 'zinn-seo' ),
	};
}

/**
 * One service: name, today's price, whether the account has it, and where to get it.
 *
 * @param {Object}  props
 * @param {Object}  props.product  Row.
 * @param {Array}   props.owned    Held purchases.
 * @param {boolean} props.canOrder Orders go through the offers basket.
 * @return {Element} Row.
 */
function Product( { product, owned, canOrder } ) {
	const [ busy, setBusy ] = useState( false );
	const [ placed, setPlaced ] = useState( null );
	const [ error, setError ] = useState( null );
	const has = holds( owned, product.code );
	const price = amount(
		product.price_minor,
		product.currency,
		readerLocale()
	);
	const label = has
		? __( 'Add more', 'zinn-seo' )
		: __( 'Get it', 'zinn-seo' );
	// One wallet, one checkout: a service the offers basket sells is ordered there (stage C3).
	const order = () => {
		setBusy( true );
		setError( null );
		inNewTab( () =>
			seoFetch( '/offers/orders', {
				method: 'POST',
				data: {
					offer: product.offer,
					product: product.offer_item,
					context: { finding: 'price-list' },
				},
			} )
		)
			.then( setPlaced )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<li className="zseo-catalogue__item">
			<span className="zseo-catalogue__name">
				<bdi>{ product.name }</bdi>
			</span>
			<span className="zseo-catalogue__price">
				{ product.interval === 'monthly'
					? sprintf(
							/* translators: %s: a price. */
							__( '%s a month', 'zinn-seo' ),
							price
						)
					: price }
			</span>
			<span className="zseo-catalogue__act">
				{ has && (
					<span className="zseo-badge is-success">
						{ __( 'You have this', 'zinn-seo' ) }
					</span>
				) }
				{ product.offer && canOrder ? (
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ order }
					>
						{ label }
					</Button>
				) : (
					product.buy_url && (
						<NewTab
							href={ product.buy_url }
							className="components-button is-secondary"
						>
							{ label }
						</NewTab>
					)
				) }
				{ product.screen && product.screen !== 'catalogue' && (
					<a href={ viewHref( product.screen ) }>
						{ __( 'Where you use it', 'zinn-seo' ) }
					</a>
				) }
			</span>
			{ placed && placed.url && (
				<span className="zseo-catalogue__placed">
					{ __(
						'Your basket opened in a new tab: pay there. Nothing has been charged yet.',
						'zinn-seo'
					) }{ ' ' }
					<NewTab href={ placed.url }>
						{ __( 'Open the basket again', 'zinn-seo' ) }
					</NewTab>
				</span>
			) }
			<Refusal error={ error } onRemove={ () => setError( null ) } />
		</li>
	);
}

/**
 * A list of services under a heading, with what they do for the site.
 *
 * @param {Object}  props
 * @param {string}  props.title    Heading.
 * @param {string}  props.pitch    What it does for the site.
 * @param {Array}   props.products Rows.
 * @param {Array}   props.owned    Held purchases.
 * @param {boolean} props.canOrder Orders go through the offers basket.
 * @return {Element|null} Section.
 */
function Section( { title, pitch, products, owned, canOrder } ) {
	if ( ! products.length ) {
		return null;
	}
	return (
		<section className="zseo-stack">
			<h3>{ title }</h3>
			{ pitch && <p className="zseo-muted">{ pitch }</p> }
			<ul className="zseo-catalogue">
				{ products.map( ( p ) => (
					<Product
						key={ p.code }
						product={ p }
						owned={ owned }
						canOrder={ canOrder }
					/>
				) ) }
			</ul>
		</section>
	);
}

/**
 * The managed-AI and agency tabs: what each does, in plain words.
 *
 * @return {Object<string, Object>} Tab => {contexts, title, pitch}.
 */
export function ownTabs() {
	return {
		update_guard: {
			contexts: [ 'update_guard' ],
			title: __( 'Safe updates', 'zinn-seo' ),
			pitch: __(
				'Before you update, every plugin and theme you use is checked against your WordPress and PHP versions, with a plain verdict for each and advice written by AI — so you know which updates are safe before they reach your site.',
				'zinn-seo'
			),
		},
		webmaster: {
			contexts: [ 'webmaster', 'chat_agent' ],
			title: __( 'AI Webmaster and chat', 'zinn-seo' ),
			pitch: __(
				'The AI Webmaster reviews your site from its real measurements and hands you each fix ready to approve. The AI chat answers your visitors from your own pages, day and night.',
				'zinn-seo'
			),
		},
		reseller: {
			contexts: [ 'reseller' ],
			title: __( 'For agencies', 'zinn-seo' ),
			pitch: __(
				'Resell Zinn® SEO data to your clients under your own brand: one monthly price covers 200 client sites, and each extra site costs a little more.',
				'zinn-seo'
			),
		},
	};
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function CatalogueScreen( { data } ) {
	const state = data.catalogue || {};
	const load = useLoad( state.connected ? '/catalogue' : null );
	if ( ! state.connected ) {
		return (
			<Card className="zseo-admin">
				<CardBody className="zseo-stack">
					<h2>{ __( 'Zinn® price list', 'zinn-seo' ) }</h2>
					<p>
						{ __(
							"Connect this site to your Zinn® account to see every service that helps it, with today's prices and what you already have.",
							'zinn-seo'
						) }
					</p>
					<p>
						<a
							className="components-button is-primary"
							href={ viewHref( 'credits' ) }
						>
							{ __( 'Connect this site', 'zinn-seo' ) }
						</a>
					</p>
				</CardBody>
			</Card>
		);
	}
	const titles = contextTitles();
	const tabs = ownTabs();
	return (
		<div className="zseo-admin zseo-catalogue-screen">
			<Loaded load={ load }>
				{ ( catalogue ) => {
					const products = catalogue.products || [];
					const owned = catalogue.owned || [];
					const rest = products.filter(
						( p ) =>
							! OWN_TABS.includes( p.context ) &&
							p.context !== 'chat_agent'
					);
					const panels = [
						{
							name: 'all',
							title: __( 'Everything for this site', 'zinn-seo' ),
						},
						...Object.keys( tabs ).map( ( name ) => ( {
							name,
							title: tabs[ name ].title,
						} ) ),
					];
					return (
						<Card>
							<CardBody className="zseo-stack">
								<p>
									{ __(
										"Today's prices, straight from Zinn®. Choose a service and it opens in your Zinn® account in a new tab, where you can check out.",
										'zinn-seo'
									) }{ ' ' }
									<GuideLink data={ data } id="catalogue" />
								</p>
								{ ! products.length && (
									<Notice
										status="info"
										isDismissible={ false }
									>
										{ __(
											'There is nothing to show right now. Please look again later.',
											'zinn-seo'
										) }
									</Notice>
								) }
								<TabPanel
									className="zseo-tabs"
									tabs={ panels }
									initialTabName={
										( window.location.hash || '' )
											.replace( '#/', '' )
											.split( '?' )[ 0 ] || 'all'
									}
								>
									{ ( tab ) =>
										tab.name === 'all' ? (
											groups( rest ).map( ( g ) => (
												<Section
													key={ g.context }
													title={
														titles[ g.context ] ||
														titles.more
													}
													products={ g.products }
													owned={ owned }
													canOrder={
														!! state.canOrder
													}
												/>
											) )
										) : (
											<Section
												title={ tabs[ tab.name ].title }
												pitch={ tabs[ tab.name ].pitch }
												products={ inContexts(
													products,
													tabs[ tab.name ].contexts
												) }
												owned={ owned }
												canOrder={ !! state.canOrder }
											/>
										)
									}
								</TabPanel>
								<p>
									<Button
										variant="link"
										onClick={ () =>
											load.reload( '/catalogue?fresh=1' )
										}
									>
										{ __(
											'Check for new prices',
											'zinn-seo'
										) }
									</Button>
								</p>
							</CardBody>
						</Card>
					);
				} }
			</Loaded>
		</div>
	);
}
