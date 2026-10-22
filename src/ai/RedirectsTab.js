/**
 * Redirects by meaning: the right page for every address in the 404 list, and the map from an old
 * site's addresses to the new ones (zs-097, zs-196, zs-inn-09). Every suggestion is a switched-off
 * redirect until someone uses it.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
} from '@wordpress/components';

import { seoFetch, errorText } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { Table } from '../tools/parts';
import { AiFailure, failureOf, PaidBy } from './parts';
import { JobPanel, useJobs } from './WriteTab';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function RedirectsTab( { data } ) {
	const ai = data.ai;
	const [ jobs, setJobs, refresh ] = useJobs( ai.jobs );
	const [ page, setPage ] = useState( null );
	const [ sitemap, setSitemap ] = useState( '' );
	const [ failure, setFailure ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ count, setCount ] = useState( null );
	const [ error, setError ] = useState( '' );
	const job = jobs[ 'ai-map' ];

	const load = useCallback(
		() =>
			seoFetch( '/ai/redirects' )
				.then( setPage )
				.catch( ( e ) => setError( errorText( e ) ) ),
		[]
	);
	useEffect( () => {
		load();
	}, [ load, job && job.status, job && job.progress && job.progress.done ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const start = ( name, body ) => {
		setBusy( name );
		setFailure( null );
		setCount( null );
		return seoFetch( '/ai/redirects', { method: 'POST', data: body } )
			.then( ( r ) => {
				setBusy( '' );
				if ( body.dry_run ) {
					setCount( r.count );
				} else {
					refresh();
				}
			} )
			.catch( ( e ) => {
				setBusy( '' );
				setFailure( failureOf( e ) );
			} );
	};
	const decide = ( ids, action ) =>
		seoFetch( '/ai/redirects/decide', {
			method: 'POST',
			data: { ids, action },
		} )
			.then( load )
			.catch( ( e ) => setError( errorText( e ) ) );
	const control = ( id, action ) =>
		seoFetch( '/ai/bulk/control', {
			method: 'POST',
			data: { job: id, action },
		} ).then( setJobs );

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Send lost visitors to the right page',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="ai-redirects" />
					</div>
					<p className="zseo-lead">
						{ __(
							'For every missing address people and search engines still visit, AI picks the page on your site that matches it best — by meaning, not just by matching words. Moving from another site? Give it the old sitemap and get the whole redirect map in minutes.',
							'zinn-seo'
						) }
					</p>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="primary"
							isBusy={ busy === 'notfound' }
							disabled={
								!! busy ||
								( job &&
									[ 'pending', 'paused' ].includes(
										job.status
									) )
							}
							onClick={ () =>
								start( 'notfound', { kind: 'notfound' } )
							}
						>
							{ __(
								'Suggest pages for my 404 list',
								'zinn-seo'
							) }
						</Button>
						<PaidBy ai={ ai } />
					</div>
					<div className="zseo-row zseo-row--top">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="url"
							label={ __(
								"Old site's sitemap address",
								'zinn-seo'
							) }
							placeholder="https://old.example.com/sitemap.xml"
							value={ sitemap }
							onChange={ setSitemap }
						/>
						<div className="zseo-actions zseo-actions--start">
							<Button
								variant="secondary"
								isBusy={ busy === 'count' }
								disabled={ ! sitemap || !! busy }
								onClick={ () =>
									start( 'count', {
										kind: 'move',
										sitemap,
										dry_run: true,
									} )
								}
							>
								{ __( 'Count addresses', 'zinn-seo' ) }
							</Button>
							<Button
								variant="secondary"
								isBusy={ busy === 'move' }
								disabled={ ! sitemap || !! busy }
								onClick={ () =>
									start( 'move', { kind: 'move', sitemap } )
								}
							>
								{ __( 'Map the old site', 'zinn-seo' ) }
							</Button>
						</div>
					</div>
					{ count !== null && (
						<p className="zseo-count">
							{ sprintf(
								/* translators: %d: how many old addresses. */
								_n(
									'The sitemap lists %d address.',
									'The sitemap lists %d addresses.',
									count,
									'zinn-seo'
								),
								count
							) }
						</p>
					) }
					<AiFailure
						failure={ failure }
						buyUrl={ ai.credits.buy_url }
						onRemove={ () => setFailure( null ) }
					/>
					<JobPanel
						job={ job }
						id="ai-map"
						ai={ ai }
						onCtl={ control }
						label={ __( 'Redirect suggestions', 'zinn-seo' ) }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ page && (
						<>
							<div className="zseo-cardhead">
								<h3 className="zseo-card-title">
									{ page.total > 0
										? sprintf(
												/* translators: %d: how many redirect suggestions. */
												_n(
													'%d redirect to review',
													'%d redirects to review',
													page.total,
													'zinn-seo'
												),
												page.total
											)
										: __(
												'No redirect suggestions waiting',
												'zinn-seo'
											) }
								</h3>
								{ page.items.length > 0 && (
									<Button
										variant="secondary"
										onClick={ () =>
											decide(
												page.items.map( ( r ) => r.id ),
												'accept'
											)
										}
									>
										{ __( 'Use all shown', 'zinn-seo' ) }
									</Button>
								) }
							</div>
							{ page.items.length > 0 && (
								<p className="zseo-muted">
									{ __(
										'These redirects are switched off until you use them. They also appear on the Redirects screen.',
										'zinn-seo'
									) }
								</p>
							) }
							{ page.items.length > 0 && (
								<Table
									label={ __(
										'Redirect suggestions',
										'zinn-seo'
									) }
									rowKey={ ( r ) => r.id }
									rows={ page.items }
									columns={ [
										{
											id: 'from',
											label: __(
												'Old address',
												'zinn-seo'
											),
											render: ( r ) => (
												<code>{ r.source }</code>
											),
										},
										{
											id: 'to',
											label: __( 'Goes to', 'zinn-seo' ),
											render: ( r ) => (
												<code>{ r.target }</code>
											),
										},
										{
											id: 'why',
											label: __( 'Why', 'zinn-seo' ),
											render: ( r ) => r.note,
										},
										{
											id: 'do',
											label: __( 'Action', 'zinn-seo' ),
											render: ( r ) => (
												<span className="zseo-actions zseo-actions--start">
													<Button
														variant="primary"
														size="compact"
														onClick={ () =>
															decide(
																[ r.id ],
																'accept'
															)
														}
													>
														{ __(
															'Use',
															'zinn-seo'
														) }
													</Button>
													<Button
														variant="tertiary"
														size="compact"
														onClick={ () =>
															decide(
																[ r.id ],
																'reject'
															)
														}
													>
														{ __(
															'Discard',
															'zinn-seo'
														) }
													</Button>
												</span>
											),
										},
									] }
								/>
							) }
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
