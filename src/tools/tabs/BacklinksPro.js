/**
 * Pro: every backlink with a verdict, the disavow file, and the alerts (a wave of spammy links,
 * a copy of your content).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Loaded, Refusal, Table, useAction, useLoad } from '../parts';
import { count, day, disavowLines } from '../pure';
import { Empty, NeedsPlan } from '../tool';
import { verdictReason } from '../issues';

/**
 * Alerts raised on this site.
 *
 * @return {Element|null} Card.
 */
export function Alerts() {
	const load = useLoad( '/tools/alerts' );
	const items = ( load.data && load.data.items ) || [];
	if ( ! items.length ) {
		return null;
	}
	return (
		<Card className="zseo-alerts">
			<CardBody className="zseo-stack">
				<h2 className="zseo-card-title">
					{ __( 'Alerts', 'zinn-seo' ) }
				</h2>
				<ul className="zseo-noticelist">
					{ items.map( ( a ) => (
						<li
							key={ a.id }
							className={ `is-warning${ a.read ? ' is-read' : '' }` }
						>
							<div className="zseo-noticelist__top">
								<span className="zseo-tag is-warning">
									{ 'content_copy' === a.kind
										? __( 'Copied content', 'zinn-seo' )
										: __( 'Backlinks', 'zinn-seo' ) }
								</span>
								<span className="zseo-muted zseo-small">
									{ day( a.at, true ) }
								</span>
							</div>
							<strong>{ a.title }</strong>
							<p>{ a.message }</p>
							{ a.data && a.data.url && (
								<p>
									<a href={ a.data.url } dir="ltr">
										{ a.data.url }
									</a>
								</p>
							) }
						</li>
					) ) }
				</ul>
				{ items.some( ( a ) => ! a.read ) && (
					<div>
						<Button
							variant="secondary"
							onClick={ () =>
								seoFetch( '/tools/alerts/read', {
									method: 'POST',
									data: {},
								} ).then( load.setData )
							}
						>
							{ __( 'Mark all as read', 'zinn-seo' ) }
						</Button>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The review and the disavow file.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Cards.
 */
export default function BacklinksPro( { data, ctx } ) {
	const held = ( ctx.plan.products || [] ).includes( 'backlinks' );
	const load = useLoad( held ? '/tools/backlinks' : null );
	const saved = useLoad( '/tools/disavow' );
	const action = useAction();
	const [ show, setShow ] = useState( 'risky' );
	const [ domains, setDomains ] = useState( new Set() );
	const [ urls, setUrls ] = useState( new Set() );
	useEffect( () => {
		if ( saved.data ) {
			setDomains( new Set( saved.data.domains || [] ) );
			setUrls( new Set( saved.data.urls || [] ) );
		}
	}, [ saved.data ] );

	const flip = ( set, setter, key, on ) => {
		const next = new Set( set );
		if ( on ) {
			next.add( key );
		} else {
			next.delete( key );
		}
		setter( next );
	};
	const levels = {
		toxic: { words: __( 'Harmful', 'zinn-seo' ), cls: 'is-critical' },
		suspect: { words: __( 'Worth a look', 'zinn-seo' ), cls: 'is-warning' },
		ok: { words: __( 'Fine', 'zinn-seo' ), cls: 'is-success' },
		gone: { words: __( 'Gone', 'zinn-seo' ), cls: '' },
	};
	const download = () =>
		action
			.run(
				'file',
				() =>
					seoFetch( '/tools/disavow', {
						method: 'PUT',
						data: { domains: [ ...domains ], urls: [ ...urls ] },
					} ).then( () => seoFetch( '/tools/disavow/file' ) ),
				__(
					'Saved. Your disavow file was downloaded — upload it in Google Search Console when you are sure.',
					'zinn-seo'
				)
			)
			.then( ( file ) => {
				if ( ! file ) {
					return;
				}
				const blob = new window.Blob( [ file.text ], {
					type: 'text/plain;charset=utf-8',
				} );
				const a = document.createElement( 'a' );
				a.href = window.URL.createObjectURL( blob );
				a.download = 'disavow.txt';
				document.body.appendChild( a );
				a.click();
				a.remove();
				window.URL.revokeObjectURL( a.href );
			} );

	return (
		<>
			<Alerts />
			<Card className="zseo-backlinks">
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Review your backlinks and drop the harmful ones',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-disavow" />
					</div>
					{ ! held ? (
						<NeedsPlan
							kind="backlinks"
							buyUrl={ ctx.buyUrl }
							text={ __(
								'Rate every link pointing at your site and build a disavow file from the ones you choose.',
								'zinn-seo'
							) }
						/>
					) : (
						<>
							<Notice status="warning" isDismissible={ false }>
								<strong>
									{ __(
										'Most sites should never disavow anything.',
										'zinn-seo'
									) }
								</strong>{ ' ' }
								{ __(
									'Google ignores ordinary junk links by itself, and disavowing a good link by mistake can cost you rankings. Use this only when Google has sent you a manual action for unnatural links, or when you know a batch of paid or spam links was built to your site. When in doubt, leave it.',
									'zinn-seo'
								) }
							</Notice>
							<Refusal
								error={ action.error }
								buyUrl={ ctx.buyUrl }
								onRemove={ action.clear }
							/>
							{ action.done && (
								<Notice
									status="success"
									onRemove={ action.clear }
								>
									{ action.done }
								</Notice>
							) }
							<Loaded load={ load } buyUrl={ ctx.buyUrl }>
								{ ( list ) => {
									const rows = list.links.filter( ( l ) =>
										'risky' === show
											? [ 'toxic', 'suspect' ].includes(
													l.verdict.level
												)
											: 'all' === show ||
												l.verdict.level === show
									);
									const file = disavowLines(
										list.links,
										domains,
										urls
									);
									return (
										<>
											<p className="zseo-muted">
												{ sprintf(
													/* translators: 1: links shown, 2: links in all, 3: a date. */
													__(
														'Showing the %1$s strongest of %2$s links, read %3$s.',
														'zinn-seo'
													),
													count( list.showing ),
													count( list.total ),
													day( list.fetched_at, true )
												) }{ ' ' }
												<Button
													variant="link"
													isBusy={ load.loading }
													onClick={ () =>
														load.reload(
															'/tools/backlinks?fresh=1'
														)
													}
												>
													{ __(
														'Read them again',
														'zinn-seo'
													) }
												</Button>
											</p>
											<SelectControl
												__nextHasNoMarginBottom
												__next40pxDefaultSize
												label={ __(
													'Show',
													'zinn-seo'
												) }
												value={ show }
												options={ [
													{
														value: 'risky',
														label: sprintf(
															/* translators: %s: a number of links. */
															__(
																'Harmful and worth a look (%s)',
																'zinn-seo'
															),
															count(
																list.counts
																	.toxic +
																	list.counts
																		.suspect
															)
														),
													},
													{
														value: 'toxic',
														label: sprintf(
															/* translators: %s: a number of links. */
															__(
																'Harmful (%s)',
																'zinn-seo'
															),
															count(
																list.counts
																	.toxic
															)
														),
													},
													{
														value: 'all',
														label: sprintf(
															/* translators: %s: a number of links. */
															__(
																'Every link (%s)',
																'zinn-seo'
															),
															count(
																list.showing
															)
														),
													},
												] }
												onChange={ setShow }
											/>
											{ rows.length > 0 ? (
												<Table
													label={ __(
														'Backlinks',
														'zinn-seo'
													) }
													rowKey={ ( l ) =>
														l.url_from + l.url_to
													}
													rows={ rows }
													columns={ [
														{
															id: 'from',
															label: __(
																'Linking page',
																'zinn-seo'
															),
															render: ( l ) => (
																<>
																	<strong dir="ltr">
																		{
																			l.domain_from
																		}
																	</strong>
																	<br />
																	<a
																		href={
																			l.url_from
																		}
																		className="zseo-url"
																		dir="ltr"
																		rel="noopener noreferrer nofollow"
																		target="_blank"
																	>
																		{
																			l.url_from
																		}
																	</a>
																</>
															),
														},
														{
															id: 'anchor',
															label: __(
																'Link text',
																'zinn-seo'
															),
															render: ( l ) =>
																l.anchor ||
																( l.is_image
																	? __(
																			'(an image)',
																			'zinn-seo'
																		)
																	: '—' ),
														},
														{
															id: 'spam',
															label: __(
																'Spam score',
																'zinn-seo'
															),
															numeric: true,
															render: ( l ) =>
																count(
																	l.spam_score
																),
														},
														{
															id: 'verdict',
															label: __(
																'Verdict',
																'zinn-seo'
															),
															render: ( l ) => (
																<>
																	<span
																		className={ `zseo-tag ${
																			levels[
																				l
																					.verdict
																					.level
																			]
																				.cls
																		}` }
																	>
																		{
																			levels[
																				l
																					.verdict
																					.level
																			]
																				.words
																		}
																	</span>
																	{ l.verdict
																		.reasons
																		.length >
																		0 && (
																		<span className="zseo-small">
																			{ ' ' }
																			{ l.verdict.reasons
																				.map(
																					verdictReason
																				)
																				.join(
																					', '
																				) }
																		</span>
																	) }
																</>
															),
														},
														{
															id: 'drop',
															label: __(
																'Disavow',
																'zinn-seo'
															),
															render: ( l ) =>
																'gone' ===
																l.verdict
																	.level ? null : (
																	<span className="zseo-inline">
																		<CheckboxControl
																			__nextHasNoMarginBottom
																			label={ __(
																				'Whole site',
																				'zinn-seo'
																			) }
																			checked={ domains.has(
																				l.domain_from
																			) }
																			onChange={ (
																				on
																			) =>
																				flip(
																					domains,
																					setDomains,
																					l.domain_from,
																					on
																				)
																			}
																		/>
																		<CheckboxControl
																			__nextHasNoMarginBottom
																			label={ __(
																				'This page',
																				'zinn-seo'
																			) }
																			disabled={ domains.has(
																				l.domain_from
																			) }
																			checked={
																				urls.has(
																					l.url_from
																				) ||
																				domains.has(
																					l.domain_from
																				)
																			}
																			onChange={ (
																				on
																			) =>
																				flip(
																					urls,
																					setUrls,
																					l.url_from,
																					on
																				)
																			}
																		/>
																	</span>
																),
														},
													] }
												/>
											) : (
												<Empty>
													{ __(
														'Nothing in this group — good news.',
														'zinn-seo'
													) }
												</Empty>
											) }
											<div className="zseo-actions zseo-actions--start">
												<Button
													variant="primary"
													disabled={
														! data.canManage ||
														!! action.busy ||
														( ! domains.size &&
															! urls.size )
													}
													isBusy={
														'file' === action.busy
													}
													onClick={ download }
												>
													{ __(
														'Save and download the disavow file',
														'zinn-seo'
													) }
												</Button>
												<span className="zseo-muted">
													{ sprintf(
														/* translators: 1: number of websites, 2: number of pages. */
														__(
															'%1$s and %2$s',
															'zinn-seo'
														),
														sprintf(
															/* translators: %s: a number of websites. */
															_n(
																'%s whole site',
																'%s whole sites',
																domains.size,
																'zinn-seo'
															),
															count(
																domains.size
															)
														),
														sprintf(
															/* translators: %s: a number of pages. */
															_n(
																'%s single page',
																'%s single pages',
																file.urls
																	.length,
																'zinn-seo'
															),
															count(
																file.urls.length
															)
														)
													) }
												</span>
											</div>
											<p className="zseo-muted zseo-small">
												{ __(
													'Nothing is sent to Google from here. You upload the file yourself in Search Console, and you can remove a line and upload again at any time.',
													'zinn-seo'
												) }
											</p>
										</>
									);
								} }
							</Loaded>
						</>
					) }
				</CardBody>
			</Card>
		</>
	);
}
