/**
 * The overview: clicks, impressions, click rate and position against the period before, day by
 * day, and the pages and searches that bring people in (zs-090). Pro: every page and search.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';

import { Loaded, NewTab, Table, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';
import Chart from './Chart';
import { change, filterRows, pct, position, shortUrl } from './pure';

/**
 * Not ready: what this tab needs.
 *
 * @param {Object} props
 * @param {Object} props.google The link.
 * @return {Element} Card.
 */
export function NeedsGoogle( { google } ) {
	return (
		<Card>
			<CardBody>
				<p className="zseo-lead">
					{ ! google.connected
						? __(
								'Connect Google above to see this. It takes a minute, and it is free.',
								'zinn-seo'
							)
						: __(
								'Choose this site’s Search Console property above to see this.',
								'zinn-seo'
							) }
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * The period picker.
 *
 * @param {Object}   props
 * @param {number}   props.days     Days.
 * @param {Function} props.onChange Called with the days.
 * @param {Array}    props.choices  Days offered.
 * @return {Element} Select.
 */
export function Period( {
	days,
	onChange,
	choices = [ 7, 28, 90, 180, 365 ],
} ) {
	return (
		<SelectControl
			className="zseo-period"
			label={ __( 'Period', 'zinn-seo' ) }
			value={ String( days ) }
			options={ choices.map( ( d ) => ( {
				value: String( d ),
				label: sprintf(
					/* translators: %d: number of days. */
					__( 'Last %d days', 'zinn-seo' ),
					d
				),
			} ) ) }
			onChange={ ( v ) => onChange( Number( v ) ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * The class of a change: flat, up (good) or down (bad).
 *
 * @param {Object} delta `change()`.
 * @return {string} Class.
 */
function deltaClass( delta ) {
	if ( delta.flat ) {
		return 'is-flat';
	}
	return delta.good ? 'is-up' : 'is-down';
}

/**
 * A figure with how it moved.
 *
 * @param {Object} props
 * @param {string} props.label Name.
 * @param {string} props.value The figure, formatted.
 * @param {Object} props.delta `change()`.
 * @return {Element} Item.
 */
function Stat( { label, value, delta } ) {
	return (
		<div className="zseo-stats__item">
			<dt>{ label }</dt>
			<dd>
				{ value }
				{ delta && (
					<span className={ `zseo-delta ${ deltaClass( delta ) }` }>
						{ delta.flat
							? __( 'no change', 'zinn-seo' )
							: sprintf(
									/* translators: %s: a percentage change, e.g. "+12.0%". */
									__( '%s vs before', 'zinn-seo' ),
									( delta.share > 0 ? '+' : '' ) +
										pct( delta.share )
								) }
					</span>
				) }
			</dd>
		</div>
	);
}

/**
 * Pro: every page or search, with the period before.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {number} props.days Days.
 * @return {Element} Card.
 */
function Everything( { data, days } ) {
	const [ dim, setDim ] = useState( 'page' );
	const [ search, setSearch ] = useState( '' );
	const [ shown, setShown ] = useState( 50 );
	const load = useLoad( `/search/report?dim=${ dim }&days=${ days }` );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h3 className="zseo-card-title">
						{ __( 'Every page and search', 'zinn-seo' ) }
					</h3>
					<SelectControl
						label={ __( 'Show', 'zinn-seo' ) }
						hideLabelFromVision
						value={ dim }
						options={ [
							{ value: 'page', label: __( 'Pages', 'zinn-seo' ) },
							{
								value: 'query',
								label: __( 'Searches', 'zinn-seo' ),
							},
							{
								value: 'country',
								label: __( 'Countries', 'zinn-seo' ),
							},
							{
								value: 'device',
								label: __( 'Devices', 'zinn-seo' ),
							},
						] }
						onChange={ setDim }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<SearchControl
					label={ __( 'Filter', 'zinn-seo' ) }
					value={ search }
					onChange={ setSearch }
					__nextHasNoMarginBottom
				/>
				<Loaded load={ load }>
					{ ( r ) => {
						const rows = filterRows( r.rows, search, [ 'key' ] );
						return (
							<>
								{ ! r.complete && (
									<p className="zseo-small zseo-muted">
										{ __(
											'Still reading every row from Google — open this again in a minute for the rest.',
											'zinn-seo'
										) }
									</p>
								) }
								<Table
									label={ __(
										'Every page and search',
										'zinn-seo'
									) }
									rowKey={ ( row ) => row.key }
									rows={ rows.slice( 0, shown ) }
									columns={ [
										{
											id: 'key',
											label:
												dim === 'page'
													? __( 'Page', 'zinn-seo' )
													: __(
															'Search',
															'zinn-seo'
														),
											render: ( row ) =>
												dim === 'page' ? (
													<span className="zseo-address">
														{ shortUrl(
															row.key,
															data.homeUrl
														) }
													</span>
												) : (
													row.key
												),
										},
										{
											id: 'clicks',
											numeric: true,
											label: __( 'Clicks', 'zinn-seo' ),
											render: ( row ) =>
												count( row.clicks ),
										},
										{
											id: 'was',
											numeric: true,
											label: __( 'Before', 'zinn-seo' ),
											render: ( row ) =>
												row.was
													? count( row.was.clicks )
													: '—',
										},
										{
											id: 'impressions',
											numeric: true,
											label: __(
												'Impressions',
												'zinn-seo'
											),
											render: ( row ) =>
												count( row.impressions ),
										},
										{
											id: 'ctr',
											numeric: true,
											label: __(
												'Click rate',
												'zinn-seo'
											),
											render: ( row ) => pct( row.ctr ),
										},
										{
											id: 'position',
											numeric: true,
											label: __( 'Position', 'zinn-seo' ),
											render: ( row ) =>
												position( row.position ),
										},
									] }
								/>
								<p className="zseo-small zseo-muted">
									{ sprintf(
										/* translators: 1: rows shown, 2: rows in all. */
										__(
											'Showing %1$s of %2$s',
											'zinn-seo'
										),
										count( Math.min( shown, rows.length ) ),
										count( rows.length )
									) }
								</p>
								{ rows.length > shown && (
									<div>
										<Button
											variant="secondary"
											onClick={ () =>
												setShown( shown + 200 )
											}
										>
											{ __( 'Show more', 'zinn-seo' ) }
										</Button>
									</div>
								) }
							</>
						);
					} }
				</Loaded>
			</CardBody>
		</Card>
	);
}

/**
 * The tab.
 *
 * @param {Object}  props
 * @param {Object}  props.data   Boot data.
 * @param {Object}  props.google The link.
 * @param {boolean} props.ready  Google connected and a property chosen.
 * @return {Element} Tab.
 */
export default function OverviewTab( { data, google, ready } ) {
	const [ days, setDays ] = useState( 28 );
	const load = useLoad( ready ? `/search/overview?days=${ days }` : null );
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	const pro = 'free' !== data.plan;
	return (
		<div className="zseo-stack">
			<div className="zseo-row">
				<Period days={ days } onChange={ setDays } />
				<Button
					variant="tertiary"
					onClick={ () =>
						load.reload( `/search/overview?days=${ days }&fresh=1` )
					}
				>
					{ __( 'Read again from Google', 'zinn-seo' ) }
				</Button>
			</div>
			<Loaded load={ load }>
				{ ( o ) => (
					<>
						<p className="zseo-small zseo-muted">
							{ sprintf(
								/* translators: 1: first day, 2: last day. */
								__(
									'%1$s to %2$s. Google’s figures settle after about two days, so the last days are not shown yet.',
									'zinn-seo'
								),
								day( o.from ),
								day( o.to )
							) }
						</p>
						<dl className="zseo-stats">
							<Stat
								label={ __( 'Clicks', 'zinn-seo' ) }
								value={ count( o.totals.clicks ) }
								delta={ change(
									o.totals.clicks,
									o.previous.clicks
								) }
							/>
							<Stat
								label={ __( 'Impressions', 'zinn-seo' ) }
								value={ count( o.totals.impressions ) }
								delta={ change(
									o.totals.impressions,
									o.previous.impressions
								) }
							/>
							<Stat
								label={ __( 'Click rate', 'zinn-seo' ) }
								value={ pct( o.totals.ctr ) }
								delta={ change( o.totals.ctr, o.previous.ctr ) }
							/>
							<Stat
								label={ __( 'Average position', 'zinn-seo' ) }
								value={ position( o.totals.position ) }
								delta={ change(
									o.totals.position,
									o.previous.position,
									true
								) }
							/>
						</dl>
						<Chart
							days={ o.daily }
							position
							label={ __(
								'Clicks from Google, day by day',
								'zinn-seo'
							) }
						/>
						<div className="zseo-two">
							{ [
								[ 'pages', __( 'Top pages', 'zinn-seo' ) ],
								[ 'queries', __( 'Top searches', 'zinn-seo' ) ],
							].map( ( [ key, title ] ) => (
								<Card key={ key }>
									<CardBody className="zseo-stack">
										<h3 className="zseo-card-title">
											{ title }
										</h3>
										{ o[ key ].rows.length === 0 ? (
											<p className="zseo-muted">
												{ __(
													'Google has not shown this site in search in this period yet.',
													'zinn-seo'
												) }
											</p>
										) : (
											<Table
												label={ title }
												rowKey={ ( r ) =>
													r.page || r.query
												}
												rows={ o[ key ].rows }
												columns={ [
													{
														id: 'k',
														label:
															key === 'pages'
																? __(
																		'Page',
																		'zinn-seo'
																	)
																: __(
																		'Search',
																		'zinn-seo'
																	),
														render: ( r ) =>
															key === 'pages' ? (
																<NewTab
																	href={
																		r.page
																	}
																>
																	<span className="zseo-address">
																		{ shortUrl(
																			r.page,
																			data.homeUrl
																		) }
																	</span>
																</NewTab>
															) : (
																r.query
															),
													},
													{
														id: 'c',
														numeric: true,
														label: __(
															'Clicks',
															'zinn-seo'
														),
														render: ( r ) =>
															count( r.clicks ),
													},
													{
														id: 'p',
														numeric: true,
														label: __(
															'Position',
															'zinn-seo'
														),
														render: ( r ) =>
															position(
																r.position
															),
													},
												] }
											/>
										) }
										{ ! pro &&
											o[ key ].total >
												o[ key ].rows.length && (
												<p className="zseo-small zseo-muted">
													{ sprintf(
														/* translators: %s: a number. */
														__(
															'%s more — every one, with the change against the period before, is in Pro.',
															'zinn-seo'
														),
														count(
															o[ key ].total -
																o[ key ].rows
																	.length
														)
													) }
												</p>
											) }
									</CardBody>
								</Card>
							) ) }
						</div>
						{ ! o.complete && <Spinner /> }
						{ pro && <Everything data={ data } days={ days } /> }
					</>
				) }
			</Loaded>
		</div>
	);
}
