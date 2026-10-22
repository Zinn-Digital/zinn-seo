/**
 * Pro: which crawlers read which pages, how often and with what answer (zs-340), and the crawl
 * budget they waste, with one-click fixes (zs-341).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SearchControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { Loaded, Refusal, Table, useAction, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';
import { pct } from './pure';

/**
 * A waste kind in words.
 *
 * @param {string} k Kind.
 * @return {string} Text.
 */
function wasteText( k ) {
	return (
		{
			params: __( 'Addresses with filters or parameters', 'zinn-seo' ),
			missing: __( 'Pages that no longer exist (404/410)', 'zinn-seo' ),
			redirect: __( 'Redirects', 'zinn-seo' ),
			error: __( 'Server errors', 'zinn-seo' ),
			search: __( 'Internal search results', 'zinn-seo' ),
			feed: __( 'Feeds', 'zinn-seo' ),
		}[ k ] || k
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function CrawlTab( { data } ) {
	const [ bot, setBot ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const load = useLoad(
		`/search/crawl?bot=${ encodeURIComponent( bot ) }&search=${ encodeURIComponent( search ) }`
	);
	const act = useAction();
	const set = ( on, sample ) =>
		act.run( 'on', () =>
			seoFetch( '/search/crawl', {
				method: 'PUT',
				data: { on, sample },
			} ).then( load.setData )
		);
	const fix = ( what, on ) =>
		act.run( `fix-${ what }`, () =>
			seoFetch( '/search/crawl/fix', {
				method: 'POST',
				data: { what, on },
			} ).then( load.setData )
		);
	return (
		<Loaded load={ load }>
			{ ( c ) => (
				<div className="zseo-stack">
					<Card>
						<CardBody className="zseo-stack">
							<ToggleControl
								label={ __( 'Log crawler visits', 'zinn-seo' ) }
								help={ __(
									'Counts each visit by Google, Bing and the AI crawlers — one counter per page and answer, never a row per visit, at most 10,000 rows. Visitors’ pages are not slowed.',
									'zinn-seo'
								) }
								checked={ c.on }
								disabled={ ! data.canManage || !! act.busy }
								onChange={ ( on ) => set( on, c.sample ) }
								__nextHasNoMarginBottom
							/>
							{ c.on && (
								<SelectControl
									label={ __(
										'On a very busy site, count',
										'zinn-seo'
									) }
									value={ String( c.sample ) }
									options={ [ 1, 5, 10, 50 ].map( ( n ) => ( {
										value: String( n ),
										label:
											1 === n
												? __(
														'every visit',
														'zinn-seo'
													)
												: sprintf(
														/* translators: %d: a number. */ __(
															'1 visit in %d',
															'zinn-seo'
														),
														n
													),
									} ) ) }
									onChange={ ( v ) =>
										set( true, Number( v ) )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							) }
							<p className="zseo-small zseo-muted">
								{ __(
									'Pages a full-page cache answers never reach WordPress and are not counted. Crawlers are recognised by name, so a scraper pretending to be Googlebot is counted as Googlebot.',
									'zinn-seo'
								) }
							</p>
						</CardBody>
					</Card>
					{ c.bots.length === 0 ? (
						<p className="zseo-muted">
							{ c.on
								? __(
										'No crawler has visited since logging started.',
										'zinn-seo'
									)
								: __(
										'Switch on logging to see crawler visits here.',
										'zinn-seo'
									) }
						</p>
					) : (
						<>
							<Card>
								<CardBody className="zseo-stack">
									<h3 className="zseo-card-title">
										{ __( 'Crawl budget', 'zinn-seo' ) }
									</h3>
									<p>
										{ sprintf(
											/* translators: 1: share, 2: visits. */
											__(
												'%1$s of search engines’ %2$s visits went to pages that cannot rank.',
												'zinn-seo'
											),
											pct( c.budget.share, 0 ),
											count( c.budget.total )
										) }
									</p>
									<ul className="zseo-log">
										{ Object.entries( c.budget.kinds )
											.filter( ( [ , n ] ) => n > 0 )
											.map( ( [ k, n ] ) => (
												<li key={ k }>
													<strong>
														{ wasteText( k ) }
													</strong>
													<span>{ count( n ) }</span>
													{ k === 'search' && (
														<Button
															variant="link"
															disabled={
																! data.canManage ||
																!! act.busy
															}
															onClick={ () =>
																fix(
																	'search',
																	! c.rules.includes(
																		'Disallow: /*?s='
																	)
																)
															}
														>
															{ c.rules.includes(
																'Disallow: /*?s='
															)
																? __(
																		'Undo the robots rule',
																		'zinn-seo'
																	)
																: __(
																		'Keep crawlers out of search results',
																		'zinn-seo'
																	) }
														</Button>
													) }
													{ k === 'missing' && (
														<a
															href={ `${ data.adminUrl }admin.php?page=zinn-seo&view=redirects` }
														>
															{ __(
																'Redirect them',
																'zinn-seo'
															) }
														</a>
													) }
													{ k === 'redirect' && (
														<span className="zseo-muted">
															{ __(
																'Update the links that still point at old addresses.',
																'zinn-seo'
															) }
														</span>
													) }
												</li>
											) ) }
									</ul>
									{ Object.keys( c.budget.params ).length >
										0 && (
										<Table
											label={ __(
												'Parameters crawled',
												'zinn-seo'
											) }
											rowKey={ ( [ name ] ) => name }
											rows={ Object.entries(
												c.budget.params
											) }
											columns={ [
												{
													id: 'n',
													label: __(
														'Parameter',
														'zinn-seo'
													),
													render: ( [ name ] ) => (
														<code>{ name }</code>
													),
												},
												{
													id: 'h',
													numeric: true,
													label: __(
														'Visits',
														'zinn-seo'
													),
													render: ( [ , h ] ) =>
														count( h ),
												},
												{
													id: 'f',
													label: __(
														'Fix',
														'zinn-seo'
													),
													render: ( [ name ] ) => {
														const on =
															c.rules.includes(
																`Disallow: /*?*${ name }=`
															);
														return (
															<Button
																variant="link"
																disabled={
																	! data.canManage ||
																	!! act.busy
																}
																onClick={ () =>
																	fix(
																		name,
																		! on
																	)
																}
															>
																{ on
																	? __(
																			'Undo the robots rule',
																			'zinn-seo'
																		)
																	: __(
																			'Keep crawlers out',
																			'zinn-seo'
																		) }
															</Button>
														);
													},
												},
											] }
										/>
									) }
									{ c.rules.length > 0 && (
										<p className="zseo-small">
											{ __(
												'robots.txt rules added here:',
												'zinn-seo'
											) }{ ' ' }
											<code>
												{ c.rules.join( ' · ' ) }
											</code>
										</p>
									) }
								</CardBody>
							</Card>
							<Card>
								<CardBody className="zseo-stack">
									<h3 className="zseo-card-title">
										{ __( 'Who crawls', 'zinn-seo' ) }
									</h3>
									<Table
										label={ __( 'Crawlers', 'zinn-seo' ) }
										rowKey={ ( b ) => b.bot }
										rows={ c.bots }
										columns={ [
											{
												id: 'n',
												label: __(
													'Crawler',
													'zinn-seo'
												),
												render: ( b ) =>
													`${ b.name }${ b.ai ? ` (${ __( 'AI', 'zinn-seo' ) })` : '' }`,
											},
											{
												id: 'h',
												numeric: true,
												label: __(
													'Visits',
													'zinn-seo'
												),
												render: ( b ) =>
													count( b.hits ),
											},
											{
												id: 'o',
												numeric: true,
												label: __(
													'Answered OK',
													'zinn-seo'
												),
												render: ( b ) =>
													pct(
														b.hits
															? b.ok / b.hits
															: 0,
														0
													),
											},
											{
												id: 'l',
												label: __(
													'Last visit',
													'zinn-seo'
												),
												render: ( b ) =>
													day( b.last, true ),
											},
										] }
									/>
								</CardBody>
							</Card>
							<Card>
								<CardBody className="zseo-stack">
									<h3 className="zseo-card-title">
										{ __( 'Pages crawled', 'zinn-seo' ) }
									</h3>
									<div className="zseo-row zseo-row--top">
										<SelectControl
											label={ __(
												'Crawler',
												'zinn-seo'
											) }
											value={ bot }
											options={ [
												{
													value: '',
													label: __(
														'All',
														'zinn-seo'
													),
												},
												...c.bots.map( ( b ) => ( {
													value: b.bot,
													label: b.name,
												} ) ),
											] }
											onChange={ setBot }
											__nextHasNoMarginBottom
											__next40pxDefaultSize
										/>
										<SearchControl
											label={ __(
												'Address contains',
												'zinn-seo'
											) }
											value={ search }
											onChange={ setSearch }
											__nextHasNoMarginBottom
										/>
									</div>
									<Table
										label={ __(
											'Pages crawled',
											'zinn-seo'
										) }
										rowKey={ ( p ) =>
											`${ p.bot }|${ p.status }|${ p.address }`
										}
										rows={ c.pages }
										columns={ [
											{
												id: 'a',
												label: __(
													'Address',
													'zinn-seo'
												),
												render: ( p ) => (
													<span className="zseo-address">
														{ p.address }
													</span>
												),
											},
											{
												id: 'b',
												label: __(
													'Crawler',
													'zinn-seo'
												),
												render: ( p ) => p.name,
											},
											{
												id: 's',
												numeric: true,
												label: __(
													'Answer',
													'zinn-seo'
												),
												render: ( p ) => p.status,
											},
											{
												id: 'h',
												numeric: true,
												label: __(
													'Visits',
													'zinn-seo'
												),
												render: ( p ) =>
													count( p.hits ),
											},
											{
												id: 'l',
												label: __( 'Last', 'zinn-seo' ),
												render: ( p ) =>
													day( p.last, true ),
											},
										] }
									/>
								</CardBody>
							</Card>
						</>
					) }
					{ c.rows >= c.ceiling && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'The log is full (10,000 rows): the least recently crawled addresses make way for new ones.',
								'zinn-seo'
							) }
						</Notice>
					) }
					<Refusal error={ act.error } onRemove={ act.clear } />
				</div>
			) }
		</Loaded>
	);
}
