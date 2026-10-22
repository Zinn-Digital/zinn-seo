/**
 * Pro: revenue from search — GA4 organic sessions, conversions and revenue per landing page next
 * to its Search Console clicks; reads only, no tracking script (zs-412).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Card, CardBody, Notice } from '@wordpress/components';

import { Loaded, Table, useLoad } from '../tools/parts';
import { count, day, price } from '../tools/pure';
import { NeedsGoogle, Period } from './OverviewTab';
import { position } from './pure';

/**
 * An amount of revenue in its currency (GA4 reports units, not cents).
 *
 * @param {number} amount   Amount.
 * @param {string} currency ISO code.
 * @return {string} Text.
 */
function revenue( amount, currency ) {
	return currency
		? price( Math.round( ( amount || 0 ) * 100 ), currency )
		: count( Math.round( amount || 0 ) );
}

/**
 * The tab.
 *
 * @param {Object}  props
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export default function AnalyticsTab( { google, ready } ) {
	const [ days, setDays ] = useState( 28 );
	const has = ready && google.analytics_property;
	const load = useLoad( has ? `/search/analytics?days=${ days }` : null );
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	if ( ! has ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ ( google.features || [] ).includes( 'analytics' )
					? __(
							'Choose this site’s Google Analytics property on the Google card above.',
							'zinn-seo'
						)
					: __(
							'Tick “Google Analytics” and press Add these on the Google card above. Nothing is added to your pages: Zinn® SEO only reads the figures your existing Analytics already has.',
							'zinn-seo'
						) }
			</Notice>
		);
	}
	return (
		<div className="zseo-stack">
			<Period
				days={ days }
				onChange={ setDays }
				choices={ [ 7, 28, 90 ] }
			/>
			<Loaded load={ load }>
				{ ( r ) => (
					<>
						<dl className="zseo-stats">
							<div className="zseo-stats__item">
								<dt>
									{ __(
										'Visits from Google search',
										'zinn-seo'
									) }
								</dt>
								<dd>{ count( r.totals.sessions ) }</dd>
							</div>
							<div className="zseo-stats__item">
								<dt>
									{ __(
										'Conversions from search',
										'zinn-seo'
									) }
								</dt>
								<dd>
									{ count(
										Math.round( r.totals.key_events )
									) }
								</dd>
							</div>
							<div className="zseo-stats__item">
								<dt>
									{ __( 'Revenue from search', 'zinn-seo' ) }
								</dt>
								<dd>
									{ revenue( r.totals.revenue, r.currency ) }
								</dd>
							</div>
							<div className="zseo-stats__item">
								<dt>
									{ __( 'Revenue per click', 'zinn-seo' ) }
								</dt>
								<dd>
									{ r.totals.clicks > 0
										? revenue(
												r.totals.revenue /
													r.totals.clicks,
												r.currency
											)
										: '—' }
								</dd>
							</div>
						</dl>
						<p className="zseo-small zseo-muted">
							{ sprintf(
								/* translators: 1: first day, 2: last day. */
								__(
									'%1$s to %2$s. Visits, conversions and revenue are Google Analytics’ “Organic Search” channel; clicks and position are Search Console’s.',
									'zinn-seo'
								),
								day( r.from ),
								day( r.to )
							) }
						</p>
						<Card>
							<CardBody>
								<Table
									label={ __(
										'What each page earns from search',
										'zinn-seo'
									) }
									rowKey={ ( p ) => p.path }
									rows={ r.rows.slice( 0, 200 ) }
									columns={ [
										{
											id: 'p',
											label: __( 'Page', 'zinn-seo' ),
											render: ( p ) => (
												<span className="zseo-address">
													{ p.path }
												</span>
											),
										},
										{
											id: 's',
											numeric: true,
											label: __( 'Visits', 'zinn-seo' ),
											render: ( p ) =>
												count( p.sessions ),
										},
										{
											id: 'k',
											numeric: true,
											label: __(
												'Conversions',
												'zinn-seo'
											),
											render: ( p ) =>
												count(
													Math.round( p.key_events )
												),
										},
										{
											id: 'r',
											numeric: true,
											label: __( 'Revenue', 'zinn-seo' ),
											render: ( p ) =>
												revenue(
													p.revenue,
													r.currency
												),
										},
										{
											id: 'c',
											numeric: true,
											label: __( 'Clicks', 'zinn-seo' ),
											render: ( p ) => count( p.clicks ),
										},
										{
											id: 'pos',
											numeric: true,
											label: __( 'Position', 'zinn-seo' ),
											render: ( p ) =>
												position( p.position ),
										},
									] }
								/>
							</CardBody>
						</Card>
					</>
				) }
			</Loaded>
		</div>
	);
}
