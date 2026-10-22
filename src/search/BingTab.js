/**
 * Bing Webmaster Tools: clicks and impressions by day, search and page, and the sitemap sent to
 * Bing (zs-090, zs-092) — with the owner's own free Bing key, straight from the site.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, TextControl } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import {
	Loaded,
	NewTab,
	Refusal,
	Table,
	useAction,
	useLoad,
} from '../tools/parts';
import { count, day } from '../tools/pure';
import BingMore from './BingMore';
import Chart from './Chart';
import { position, shortUrl } from './pure';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function BingTab( { data } ) {
	const load = useLoad( '/search/bing' );
	const act = useAction();
	const [ key, setKey ] = useState( '' );
	return (
		<Loaded load={ load }>
			{ ( b ) =>
				! b.connected ? (
					<Card>
						<CardBody className="zseo-stack">
							<p className="zseo-lead">
								{ __(
									'Bing powers Bing, Yahoo, DuckDuckGo and ChatGPT search. Paste your free Bing Webmaster Tools API key to see your Bing searches and clicks here, and your sitemap is sent to Bing for you.',
									'zinn-seo'
								) }
							</p>
							<p className="zseo-small">
								{ __(
									'In Bing Webmaster Tools: Settings (the gear) → API access → API key → Generate. Your site must be added there first — you can import it from Google Search Console in one click.',
									'zinn-seo'
								) }
							</p>
							<NewTab href="https://www.bing.com/webmasters/">
								{ __(
									'Open Bing Webmaster Tools',
									'zinn-seo'
								) }
							</NewTab>
							{ data.canManage && (
								<form
									className="zseo-row zseo-row--top"
									onSubmit={ ( e ) => {
										e.preventDefault();
										act.run( 'connect', () =>
											seoFetch( '/search/bing', {
												method: 'POST',
												data: { key },
											} ).then( ( r ) => {
												setKey( '' );
												load.setData( r );
											} )
										);
									} }
								>
									<TextControl
										label={ __(
											'Bing API key',
											'zinn-seo'
										) }
										type="password"
										autoComplete="off"
										value={ key }
										onChange={ setKey }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
									/>
									<Button
										variant="primary"
										type="submit"
										disabled={ ! key || !! act.busy }
										isBusy={ act.busy === 'connect' }
									>
										{ __( 'Connect Bing', 'zinn-seo' ) }
									</Button>
								</form>
							) }
							<Refusal
								error={ act.error }
								onRemove={ act.clear }
							/>
						</CardBody>
					</Card>
				) : (
					<div className="zseo-stack">
						<p>
							<span className="zseo-ok">
								{ __( 'Bing connected', 'zinn-seo' ) }
							</span>{ ' ' }
							<span className="zseo-address">{ b.site }</span>
						</p>
						{ b.error ? (
							<Refusal error={ { message: b.error } } />
						) : (
							<>
								<dl className="zseo-stats">
									<div className="zseo-stats__item">
										<dt>
											{ __(
												'Clicks from Bing',
												'zinn-seo'
											) }
										</dt>
										<dd>
											{ count(
												( b.totals || {} ).clicks || 0
											) }
										</dd>
									</div>
									<div className="zseo-stats__item">
										<dt>
											{ __( 'Impressions', 'zinn-seo' ) }
										</dt>
										<dd>
											{ count(
												( b.totals || {} )
													.impressions || 0
											) }
										</dd>
									</div>
								</dl>
								<Chart
									days={ b.daily.map( ( d ) => ( {
										date: d.key,
										clicks: d.clicks,
										position: d.position,
									} ) ) }
									label={ __(
										'Clicks from Bing, day by day',
										'zinn-seo'
									) }
								/>
								<div className="zseo-two">
									{ [
										[
											'queries',
											__(
												'Top Bing searches',
												'zinn-seo'
											),
										],
										[
											'pages',
											__(
												'Top pages on Bing',
												'zinn-seo'
											),
										],
									].map( ( [ k, title ] ) => (
										<Card key={ k }>
											<CardBody className="zseo-stack">
												<h3 className="zseo-card-title">
													{ title }
												</h3>
												{ b[ k ].length === 0 ? (
													<p className="zseo-muted">
														{ __(
															'Nothing in this period yet.',
															'zinn-seo'
														) }
													</p>
												) : (
													<Table
														label={ title }
														rowKey={ ( r ) =>
															r.key
														}
														rows={ b[ k ].slice(
															0,
															20
														) }
														columns={ [
															{
																id: 'k',
																label:
																	k ===
																	'pages'
																		? __(
																				'Page',
																				'zinn-seo'
																			)
																		: __(
																				'Search',
																				'zinn-seo'
																			),
																render: (
																	r
																) =>
																	k ===
																	'pages' ? (
																		<span className="zseo-address">
																			{ shortUrl(
																				r.key,
																				data.homeUrl
																			) }
																		</span>
																	) : (
																		r.key
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
																	count(
																		r.clicks
																	),
															},
															{
																id: 'i',
																numeric: true,
																label: __(
																	'Impressions',
																	'zinn-seo'
																),
																render: ( r ) =>
																	count(
																		r.impressions
																	),
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
											</CardBody>
										</Card>
									) ) }
								</div>
								{ ( b.feeds || [] ).length > 0 && (
									<ul className="zseo-log">
										{ b.feeds.map( ( f ) => (
											<li key={ f.url }>
												<span className="zseo-address">
													{ f.url }
												</span>
												<span>{ f.status }</span>
												<span>
													{ sprintf(
														/* translators: %s: number of addresses. */ __(
															'%s addresses',
															'zinn-seo'
														),
														count( f.urls )
													) }
												</span>
												<span className="zseo-muted">
													{ day( f.crawled ) }
												</span>
											</li>
										) ) }
									</ul>
								) }
							</>
						) }
						<BingMore data={ data } />
						{ data.canManage && (
							<div className="zseo-row">
								<Button
									variant="secondary"
									disabled={ !! act.busy }
									onClick={ () =>
										act.run(
											'send',
											() =>
												seoFetch(
													'/search/bing/submit',
													{ method: 'POST' }
												).then( () =>
													load.reload(
														'/search/bing?fresh=1'
													)
												),
											__(
												'Sitemap sent to Bing.',
												'zinn-seo'
											)
										)
									}
								>
									{ __(
										'Send the sitemap to Bing',
										'zinn-seo'
									) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									disabled={ !! act.busy }
									onClick={ () =>
										act.run( 'off', () =>
											seoFetch( '/search/bing', {
												method: 'DELETE',
											} ).then( load.setData )
										)
									}
								>
									{ __( 'Disconnect Bing', 'zinn-seo' ) }
								</Button>
							</div>
						) }
						{ act.done && <p className="zseo-ok">{ act.done }</p> }
						<Refusal error={ act.error } onRemove={ act.clear } />
					</div>
				)
			}
		</Loaded>
	);
}
