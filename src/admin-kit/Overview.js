/* Generated from wp/packages/zinn-admin-kit/src/js/Overview.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
} from '@wordpress/components';

import { badgeText, planSites, visibleCards } from './plans';
import { PromoCard, CrossSellCard } from './PromoCard';

/**
 * The first screen: what this site runs, where everything is, and — on our own screen only — the
 * hosting promotion and the cross-sell (features adm-4, adm-5). A host screen may add its own
 * card here by giving its route an `overview( navigate )` function (Zinn® SEO's credit card).
 *
 * @param {Object}        props
 * @param {Object}        props.kit       Boot data.
 * @param {Array<Object>} props.routes    Every route.
 * @param {Function}      props.navigate  Go to a route.
 * @param {Object}        props.prefs     The user's preferences.
 * @param {Function}      props.savePrefs Save a preference change.
 * @return {Element} The screen.
 */
export default function Overview( {
	kit,
	routes,
	navigate,
	prefs,
	savePrefs,
} ) {
	const licence = kit.licence || {};
	const dismissed = prefs.dismissed || [];
	const cross = visibleCards( kit.promotions, dismissed, 'cross-sell' );
	const plan = ( kit.plans || [] ).find( ( p ) => p.id === licence.plan );
	const links = routes.filter(
		( r ) => ! [ 'overview', 'setup' ].includes( r.id )
	);

	return (
		<div className="zak-grid">
			{ 'new' === ( kit.wizard && kit.wizard.state ) && (
				<Card className="zak-card zak-card--wide">
					<CardBody>
						<Flex justify="space-between" wrap>
							<FlexItem>
								<strong>
									{ sprintf(
										/* translators: %s: plugin name. */
										__(
											'Set up %s in a few minutes',
											'zinn-seo'
										),
										kit.name
									) }
								</strong>
							</FlexItem>
							<FlexItem>
								<Button
									variant="primary"
									onClick={ () => navigate( 'setup' ) }
								>
									{ __( 'Start setup', 'zinn-seo' ) }
								</Button>
							</FlexItem>
						</Flex>
					</CardBody>
				</Card>
			) }
			{ routes
				.filter( ( r ) => typeof r.overview === 'function' )
				.map( ( r ) => (
					<Fragment key={ `overview-${ r.id }` }>
						{ r.overview( navigate ) }
					</Fragment>
				) ) }
			<Card className="zak-card" data-zak-tour="status">
				<CardHeader>
					<h2 className="zak-card__title">
						{ __( 'This site', 'zinn-seo' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<dl className="zak-facts">
						<dt>{ __( 'Edition', 'zinn-seo' ) }</dt>
						<dd>{ badgeText( licence ) }</dd>
						{ plan && licence.premium && (
							<>
								<dt>
									{ __(
										'Sites on this licence',
										'zinn-seo'
									) }
								</dt>
								<dd>{ planSites( plan ) }</dd>
							</>
						) }
						<dt>{ __( 'Version', 'zinn-seo' ) }</dt>
						<dd>{ kit.version }</dd>
					</dl>
					{ licence.localhost && (
						<p className="zak-muted">
							{ __(
								'This looks like a staging or local copy, so it does not use up a site on your licence.',
								'zinn-seo'
							) }
						</p>
					) }
					{ ! licence.premium && (
						<Button
							variant="secondary"
							onClick={ () => navigate( 'plans' ) }
						>
							{ __( 'See what Pro adds', 'zinn-seo' ) }
						</Button>
					) }
				</CardBody>
			</Card>
			<Card className="zak-card">
				<CardHeader>
					<h2 className="zak-card__title">
						{ __( 'Go to', 'zinn-seo' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<ul className="zak-links">
						{ links.map( ( r ) => (
							<li key={ r.id }>
								<Button
									variant="link"
									onClick={ () => navigate( r.id ) }
								>
									{ r.label }
								</Button>
							</li>
						) ) }
					</ul>
				</CardBody>
			</Card>
			<PromoCard
				promotions={ kit.promotions }
				dismissed={ dismissed }
				onDismiss={ ( id ) => savePrefs( { dismiss: id } ) }
			/>
			{ cross.map( ( card ) => (
				<CrossSellCard
					key={ card.id }
					card={ card }
					onDismiss={ ( id ) => savePrefs( { dismiss: id } ) }
				/>
			) ) }
		</div>
	);
}
