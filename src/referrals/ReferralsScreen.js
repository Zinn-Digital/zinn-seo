/**
 * AI visitors (zs-708) and ad earnings (zs-711): which pages ChatGPT, Perplexity, Gemini, Copilot
 * and Claude send people to, and what each page earns from ads beside its Google clicks. Read
 * from Google Analytics and this site's own server — no tracking script is added.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Notice, SelectControl, TabPanel } from '@wordpress/components';

import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { Loaded, Table, useLoad } from '../tools/parts';
import { count, price } from '../tools/pure';
import { assistantName, merge } from './pure';

/**
 * A table with its name shown above it as a heading (the name is also the table's label).
 *
 * @param {Object} props Table props (`label`, `columns`, `rows`, `rowKey`).
 * @return {Element} Table.
 */
function TitledTable( props ) {
	return (
		<>
			<h3 className="zseo-card-title">{ props.label }</h3>
			<Table { ...props } />
		</>
	);
}

/**
 * The period picker.
 *
 * @param {Object}   props
 * @param {number}   props.days     Days.
 * @param {Function} props.onChange Change.
 * @return {Element} Picker.
 */
function Period( { days, onChange } ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ __( 'Period', 'zinn-seo' ) }
			value={ String( days ) }
			onChange={ ( v ) => onChange( Number( v ) ) }
			options={ [
				{ value: '7', label: __( 'Last 7 days', 'zinn-seo' ) },
				{ value: '28', label: __( 'Last 28 days', 'zinn-seo' ) },
				{ value: '90', label: __( 'Last 90 days', 'zinn-seo' ) },
			] }
		/>
	);
}

/**
 * AI visitors.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function AiTab( { data } ) {
	const [ days, setDays ] = useState( 28 );
	const load = useLoad( `/referrals/ai?days=${ days }` );
	return (
		<div className="zseo-stack">
			<Period days={ days } onChange={ setDays } />
			<Loaded load={ load }>
				{ ( r ) => {
					const source = r.ga4 || r.server;
					const totals = merge( r.ga4, r.server );
					return (
						<>
							<p className="zseo-muted">
								{ r.ga4
									? __(
											'From Google Analytics: visits whose source was an AI assistant.',
											'zinn-seo'
										)
									: __(
											'From this site’s own server: visits that arrived from an AI assistant. Pages served from a full-page cache are not counted, so the real number is at least this.',
											'zinn-seo'
										) }{ ' ' }
								{ ! r.ga4_on && (
									<a href={ viewHref( 'search' ) }>
										{ __(
											'Connect Google Analytics for the full count',
											'zinn-seo'
										) }
									</a>
								) }
							</p>
							{ r.ga4_error && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ r.ga4_error }
								</Notice>
							) }
							<TitledTable
								label={ __(
									'Visits by assistant',
									'zinn-seo'
								) }
								rowKey={ ( row ) => row.assistant }
								rows={ totals }
								columns={ [
									{
										id: 'a',
										label: __( 'Assistant', 'zinn-seo' ),
										render: ( row ) =>
											assistantName( row.assistant ),
									},
									{
										id: 'v',
										label: __( 'Visits', 'zinn-seo' ),
										numeric: true,
										render: ( row ) => count( row.visits ),
									},
								] }
							/>
							{ ! totals.length && (
								<p>
									{ __(
										'No visits from AI assistants in this period yet.',
										'zinn-seo'
									) }
								</p>
							) }
							{ ( source.pages || [] ).length > 0 && (
								<TitledTable
									label={ __(
										'Pages AI assistants send people to',
										'zinn-seo'
									) }
									rowKey={ ( row ) => row.page }
									rows={ source.pages.slice( 0, 100 ) }
									columns={ [
										{
											id: 'p',
											label: __( 'Page', 'zinn-seo' ),
											render: ( row ) => (
												<bdi className="zseo-break">
													{ row.page }
												</bdi>
											),
										},
										{
											id: 'v',
											label: __( 'Visits', 'zinn-seo' ),
											numeric: true,
											render: ( row ) =>
												count( row.visits ),
										},
										{
											id: 'b',
											label: __( 'From', 'zinn-seo' ),
											render: ( row ) =>
												Object.keys( row.by )
													.map(
														( a ) =>
															`${ assistantName( a ) } ${ count(
																row.by[ a ]
															) }`
													)
													.join( ' · ' ),
										},
									] }
								/>
							) }
							<GuideLink data={ data } id="ai-referrals" />
						</>
					);
				} }
			</Loaded>
		</div>
	);
}

/**
 * Ad earnings per page.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function EarningsTab( { data } ) {
	const [ days, setDays ] = useState( 28 );
	const load = useLoad( `/referrals/earnings?days=${ days }` );
	return (
		<div className="zseo-stack">
			<p>
				{ __(
					'What each page earns from ads, beside its clicks and ranking on Google — so you see which SEO work pays. Your AdSense account must be linked to your Google Analytics property.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="ad-earnings" />
			</p>
			<Period days={ days } onChange={ setDays } />
			<Loaded load={ load }>
				{ ( r ) => {
					if ( false === r.ga4_on ) {
						return (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Connect Google Analytics on the Search performance screen first, and link your AdSense account to the same Analytics property.',
									'zinn-seo'
								) }{ ' ' }
								<a href={ viewHref( 'search' ) }>
									{ __(
										'Go to Search performance',
										'zinn-seo'
									) }
								</a>
							</Notice>
						);
					}
					const rows = r.rows || [];
					const currency = r.currency || 'USD';
					const earned = rows.some(
						( row ) => row.earnings_minor > 0
					);
					return (
						<>
							{ ! earned && (
								<Notice status="info" isDismissible={ false }>
									{ __(
										'Google Analytics shows no ad earnings for this period. If you run AdSense, link it to this Analytics property (AdSense → Account → Access and authorisation → Google Analytics integration).',
										'zinn-seo'
									) }
								</Notice>
							) }
							<TitledTable
								label={ __( 'Earnings by page', 'zinn-seo' ) }
								rowKey={ ( row ) => row.page }
								rows={ rows.slice( 0, 200 ) }
								columns={ [
									{
										id: 'p',
										label: __( 'Page', 'zinn-seo' ),
										render: ( row ) => (
											<bdi className="zseo-break">
												{ row.page }
											</bdi>
										),
									},
									{
										id: 'e',
										label: __( 'Ad earnings', 'zinn-seo' ),
										numeric: true,
										render: ( row ) =>
											price(
												row.earnings_minor,
												currency
											),
									},
									{
										id: 'c',
										label: __(
											'Google clicks',
											'zinn-seo'
										),
										numeric: true,
										render: ( row ) => count( row.clicks ),
									},
									{
										id: 'k',
										label: __(
											'Earned per click',
											'zinn-seo'
										),
										numeric: true,
										render: ( row ) =>
											null === row.per_click_minor
												? '—'
												: price(
														row.per_click_minor,
														currency
													),
									},
									{
										id: 'r',
										label: __( 'Position', 'zinn-seo' ),
										numeric: true,
										render: ( row ) =>
											null === row.position
												? '—'
												: sprintf( '%s', row.position ),
									},
								] }
							/>
						</>
					);
				} }
			</Loaded>
		</div>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function ReferralsScreen( { data } ) {
	return (
		<div className="zseo-admin zseo-referrals">
			<TabPanel
				className="zseo-tabs"
				tabs={ [
					{ name: 'ai', title: __( 'AI visitors', 'zinn-seo' ) },
					{
						name: 'earnings',
						title: __( 'Ad earnings', 'zinn-seo' ),
					},
				] }
				initialTabName={
					'#/earnings' === window.location.hash ? 'earnings' : 'ai'
				}
			>
				{ ( tab ) =>
					'earnings' === tab.name ? (
						<EarningsTab data={ data } />
					) : (
						<AiTab data={ data } />
					)
				}
			</TabPanel>
		</div>
	);
}
