/**
 * Site audit: the whole site read page by page, each problem with the pages it is on and a
 * link straight to the page here that needs fixing.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, Notice, Spinner } from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Refusal } from '../parts';
import { count } from '../pure';
import { Empty, Held, RunBar, RunLine, Schedule, useResults } from '../tool';
import { issueWords } from '../issues';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function AuditTab( { data, ctx } ) {
	const state = ctx.tools.audit;
	const results = useResults( 'audit' );
	const [ run, setRun ] = useState( null );
	const [ error, setError ] = useState( null );
	const latestId = results.latest ? results.latest.id : '';

	// The list carries no findings: the newest finished run is read with them, and with the
	// page on this site for each address it names.
	useEffect( () => {
		if ( ! latestId ) {
			setRun( null );
			return;
		}
		let off = false;
		seoFetch( `/tools/run/${ latestId }` )
			.then( ( full ) => ! off && setRun( full ) )
			.catch( ( e ) => ! off && setError( e ) );
		return () => {
			off = true;
		};
	}, [ latestId ] );

	const summary = ( run && run.summary ) || {};
	const findings = ( ( run && run.findings ) || [] )
		.slice()
		.sort( ( a, b ) => ( b.weight || 0 ) - ( a.weight || 0 ) );
	const order = { critical: 0, warn: 1, info: 2, pass: 3 };
	findings.sort(
		( a, b ) => ( order[ a.severity ] ?? 9 ) - ( order[ b.severity ] ?? 9 )
	);
	const tags = {
		critical: __( 'Fix first', 'zinn-seo' ),
		warn: __( 'Worth fixing', 'zinn-seo' ),
		info: __( 'Good to know', 'zinn-seo' ),
		pass: __( 'All good', 'zinn-seo' ),
	};

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Find what is holding your site back',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-audit" />
					</div>
					<p className="zseo-lead">
						{ __(
							'We read your site the way Google does — broken links, missing titles, pages nothing links to, redirects that go in circles — and take you straight to the page that needs fixing.',
							'zinn-seo'
						) }
					</p>
					<Held state={ state } buyUrl={ ctx.buyUrl } />
					<Schedule
						tool="audit"
						state={ state }
						units={ state.units }
						ctx={ ctx }
						label={ __(
							'Audit the site automatically',
							'zinn-seo'
						) }
					/>
					<RunBar
						tool="audit"
						state={ state }
						units={ state.units }
						ctx={ ctx }
						label={ __( 'Audit my site now', 'zinn-seo' ) }
						running={ results.running }
						onStart={ results.watch }
					/>
					{ ! state.in_plan && (
						<p className="zseo-muted zseo-small">
							{ sprintf(
								/* translators: %s: a number of pages. */
								__(
									'One audit reads up to %s pages. A bigger site? The Site Audit plan in your Zinn® account reads up to 500.',
									'zinn-seo'
								),
								count( state.units )
							) }
						</p>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'What the audit found', 'zinn-seo' ) }
					</h2>
					<Refusal
						error={ results.error || error }
						buyUrl={ ctx.buyUrl }
					/>
					<RunLine run={ results.latest } />
					{ latestId && ! run && ! error && <Spinner /> }
					{ run && (
						<>
							{ summary.crawl_truncated && (
								<Notice status="info" isDismissible={ false }>
									{ sprintf(
										/* translators: %s: a number of pages. */
										__(
											'The audit stopped at its limit of %s pages, so pages beyond that were not read. What is below is about the pages that were.',
											'zinn-seo'
										),
										count( summary.max_pages )
									) }
								</Notice>
							) }
							<dl className="zseo-stats">
								<div className="zseo-stats__item">
									<dt>{ __( 'Score', 'zinn-seo' ) }</dt>
									<dd>
										{ null === run.score ||
										undefined === run.score
											? '—'
											: sprintf(
													/* translators: %s: a score out of 100. */
													__(
														'%s / 100',
														'zinn-seo'
													),
													count( run.score )
												) }
									</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ __( 'Pages read', 'zinn-seo' ) }</dt>
									<dd>{ count( summary.pages_crawled ) }</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>
										{ __( 'Broken pages', 'zinn-seo' ) }
									</dt>
									<dd>{ count( summary.broken_pages ) }</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>
										{ __( 'Broken links', 'zinn-seo' ) }
									</dt>
									<dd>{ count( summary.broken_links ) }</dd>
								</div>
							</dl>
							<ul className="zseo-findings">
								{ findings.map( ( f ) => {
									const words = issueWords( f.code );
									const detail = f.detail || {};
									const examples = detail.examples || [];
									return (
										<li
											key={ f.code }
											className={ `is-${ f.severity }` }
										>
											<div className="zseo-findings__top">
												<span
													className={ `zseo-tag is-${ f.severity }` }
												>
													{ tags[ f.severity ] ||
														f.severity }
												</span>
												<strong>{ words.title }</strong>
												{ detail.pages > 0 && (
													<span className="zseo-muted">
														{ sprintf(
															/* translators: %s: a number of pages. */
															_n(
																'%s page',
																'%s pages',
																detail.pages,
																'zinn-seo'
															),
															count(
																detail.pages
															)
														) }
													</span>
												) }
											</div>
											{ words.fix && (
												<p>{ words.fix }</p>
											) }
											{ examples.length > 0 && (
												<ul className="zseo-examples">
													{ examples
														.slice( 0, 10 )
														.map( ( url ) => {
															const page =
																( f.pages ||
																	{} )[ url ];
															return (
																<li key={ url }>
																	<a
																		href={
																			url
																		}
																		className="zseo-url"
																		dir="ltr"
																	>
																		{ url }
																	</a>
																	{ page &&
																		page.edit && (
																			<a
																				className="zseo-fixlink"
																				href={
																					page.edit
																				}
																			>
																				{ __(
																					'Fix this page',
																					'zinn-seo'
																				) }
																			</a>
																		) }
																</li>
															);
														} ) }
												</ul>
											) }
										</li>
									);
								} ) }
							</ul>
						</>
					) }
					{ ! latestId && ! results.loading && (
						<Empty>
							{ __(
								'No audit yet. Run the first one and every problem is listed here with the pages it is on — most important first.',
								'zinn-seo'
							) }
						</Empty>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
