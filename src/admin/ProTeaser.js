import { __ } from '@wordpress/i18n';
import { Button, Card, CardBody } from '@wordpress/components';

import { viewHref } from '../admin-kit/router';

/**
 * The Pro trial (or licence) that ended unpaid on this site, when there is one and nobody chose
 * the free version yet (§2.62; the premium package's Pro\Lapse sends it as `lapsed`).
 *
 * @return {Object|null} `{ upgrade, freeUrl }`, or null.
 */
export function lapsed() {
	const data = ( typeof window !== 'undefined' && window.zinnSeoAdmin ) || {};
	const l = data.lapsed;
	return l && ! l.free ? l : null;
}

/**
 * A Pro feature on a free site: what it does and where to get it. Shown only on this plugin's own
 * screens (WordPress.org guideline 11: no upsell anywhere else in wp-admin).
 *
 * On a site whose Pro trial ended unpaid the same card says the feature STOPPED, that what was
 * set up is kept, and offers the owner's two ways on: "Upgrade to keep it, or switch to the free
 * version" (owner ruling 2026-10-18).
 *
 * @param {Object}        props
 * @param {string}        props.title  Feature name.
 * @param {Array<string>} props.points What it does, one sentence each.
 * @return {Element} The card.
 */
export default function ProTeaser( { title, points } ) {
	const ended = lapsed();
	if ( ended ) {
		return (
			<Card className="zseo-pro zseo-pro--lapsed">
				<CardBody>
					<p className="zseo-pro__tag">
						{ __( 'Pro — stopped', 'zinn-seo' ) }
					</p>
					<h3 className="zseo-pro__title">{ title }</h3>
					<p>
						{ __(
							'This stopped when your Pro trial ended. Everything you set up here is kept, and it all comes back the moment you upgrade.',
							'zinn-seo'
						) }
					</p>
					<p>
						<Button
							variant="primary"
							href={ ended.upgrade || viewHref( 'plans' ) }
						>
							{ __( 'Upgrade to keep it', 'zinn-seo' ) }
						</Button>{ ' ' }
						{ ended.freeUrl ? (
							<Button variant="secondary" href={ ended.freeUrl }>
								{ __(
									'Switch to the free version',
									'zinn-seo'
								) }
							</Button>
						) : null }
					</p>
				</CardBody>
			</Card>
		);
	}
	return (
		<Card className="zseo-pro">
			<CardBody>
				<p className="zseo-pro__tag">{ __( 'Pro', 'zinn-seo' ) }</p>
				<h3 className="zseo-pro__title">{ title }</h3>
				<ul className="zseo-pro__points">
					{ points.map( ( p ) => (
						<li key={ p }>{ p }</li>
					) ) }
				</ul>
				<Button variant="primary" href={ viewHref( 'plans' ) }>
					{ __( 'See the Pro plans', 'zinn-seo' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
