/**
 * Backlinks: the links you earned, watched one by one — still there, still pointing at the right
 * page, still passing authority — and, with backlink monitoring, the whole profile.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import ProTeaser from '../../admin/ProTeaser';
import GuideLink from '../../admin/p7/GuideLink';
import { Refusal, Table, useAction } from '../parts';
import { count } from '../pure';
import { Empty, Held, NeedsPlan, RunBar, RunLine, useResults } from '../tool';
import { linkProblem } from '../issues';
import BacklinksPro from './BacklinksPro';

/**
 * The whole link profile (the account's backlink monitoring).
 *
 * @param {Object} props
 * @param {Object} props.ctx The checks' shared state.
 * @return {Element} Card.
 */
function Profile( { ctx } ) {
	const held = ( ctx.plan.products || [] ).includes( 'backlinks' );
	const results = useResults( 'profile', held );
	const summary = ( results.latest && results.latest.summary ) || {};
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2 className="zseo-card-title">
					{ __( 'Every site that links to you', 'zinn-seo' ) }
				</h2>
				{ ! held ? (
					<NeedsPlan
						kind="backlinks"
						buyUrl={ ctx.buyUrl }
						text={ __(
							'See every site linking to you, the links you gained and lost this week, and how trustworthy they are.',
							'zinn-seo'
						) }
					/>
				) : (
					<>
						<Refusal
							error={ results.error }
							buyUrl={ ctx.buyUrl }
						/>
						<RunLine run={ results.latest } />
						{ summary.measured ? (
							<dl className="zseo-stats">
								<div className="zseo-stats__item">
									<dt>
										{ __(
											'Sites linking to you',
											'zinn-seo'
										) }
									</dt>
									<dd>
										{ count( summary.referring_domains ) }
									</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ __( 'Links', 'zinn-seo' ) }</dt>
									<dd>{ count( summary.backlinks ) }</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ __( 'New sites', 'zinn-seo' ) }</dt>
									<dd className="zseo-ok">
										{ count(
											summary.new_referring_domains
										) }
									</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ __( 'Lost sites', 'zinn-seo' ) }</dt>
									<dd>
										{ count(
											summary.lost_referring_domains
										) }
									</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>{ __( 'Spam score', 'zinn-seo' ) }</dt>
									<dd>{ count( summary.spam_score ) }</dd>
								</div>
							</dl>
						) : (
							! results.loading && (
								<Empty>
									{ __(
										'No reading yet. The first one arrives with your next scheduled check.',
										'zinn-seo'
									) }
								</Empty>
							)
						) }
						<RunBar
							tool="profile"
							state={ ctx.tools.profile }
							units={ 1 }
							ctx={ ctx }
							label={ __( 'Read my backlinks now', 'zinn-seo' ) }
							running={ results.running }
							onStart={ results.watch }
						/>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function LinksTab( { data, ctx } ) {
	const state = ctx.tools.links;
	const results = useResults( 'links' );
	const action = useAction();
	const [ draft, setDraft ] = useState( {
		source_url: '',
		target_url: '',
		anchor: '',
	} );
	const watched = ctx.target.links || [];
	const save = ( list, done ) =>
		action
			.run(
				'save',
				() =>
					seoFetch( '/tools/links/list', {
						method: 'PUT',
						data: { items: list },
					} ),
				done
			)
			.then( ( saved ) => {
				if ( saved ) {
					setDraft( { source_url: '', target_url: '', anchor: '' } );
					ctx.refresh();
				}
			} );
	const summary = ( results.latest && results.latest.summary ) || {};
	const rows = summary.links || [];
	const words = {
		live: __( 'Still there', 'zinn-seo' ),
		lost: __( 'Lost', 'zinn-seo' ),
		changed: __( 'Changed', 'zinn-seo' ),
		unreadable: __( 'Could not be read', 'zinn-seo' ),
	};
	const tone = {
		live: 'is-success',
		lost: 'is-critical',
		changed: 'is-warning',
		unreadable: '',
	};

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Keep the links you worked for',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-links" />
					</div>
					<p className="zseo-lead">
						{ __(
							'A link from a good site is hard to earn and easy to lose. Give us the pages that link to you and we check each one: still there, still pointing at the right page, still passing authority. You hear the day one disappears.',
							'zinn-seo'
						) }
					</p>
					<Held state={ state } buyUrl={ ctx.buyUrl } />
					<Refusal
						error={ action.error }
						buyUrl={ ctx.buyUrl }
						onRemove={ action.clear }
					/>
					{ action.done && (
						<Notice status="success" onRemove={ action.clear }>
							{ action.done }
						</Notice>
					) }
					{ ctx.canSpend && (
						<>
							<div className="zseo-row">
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									type="url"
									className="zseo-address"
									label={ __(
										'The page that links to you',
										'zinn-seo'
									) }
									placeholder="https://"
									value={ draft.source_url }
									onChange={ ( v ) =>
										setDraft( { ...draft, source_url: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									type="url"
									className="zseo-address"
									label={ __(
										'Your page it should link to (optional)',
										'zinn-seo'
									) }
									placeholder={ data.homeUrl }
									value={ draft.target_url }
									onChange={ ( v ) =>
										setDraft( { ...draft, target_url: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'The link’s words (optional)',
										'zinn-seo'
									) }
									value={ draft.anchor }
									onChange={ ( anchor ) =>
										setDraft( { ...draft, anchor } )
									}
								/>
							</div>
							<div>
								<Button
									variant="secondary"
									disabled={
										! /^https?:\/\/.+\..+/.test(
											draft.source_url
										) || !! action.busy
									}
									isBusy={ 'save' === action.busy }
									onClick={ () =>
										save(
											[ ...watched, draft ],
											__(
												'Saved. The first check runs within the hour.',
												'zinn-seo'
											)
										)
									}
								>
									{ __( 'Watch this link', 'zinn-seo' ) }
								</Button>
							</div>
						</>
					) }
					<RunBar
						tool="links"
						state={ state }
						units={ watched.length }
						ctx={ ctx }
						label={ __( 'Check my links now', 'zinn-seo' ) }
						disabled={ ! watched.length }
						why={ __( 'Add a link to watch first.', 'zinn-seo' ) }
						running={ results.running }
						onStart={ results.watch }
					/>
				</CardBody>
			</Card>
			{ watched.length > 0 && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-card-title">
							{ sprintf(
								/* translators: %s: a number of links. */
								_n(
									'%s link watched',
									'%s links watched',
									watched.length,
									'zinn-seo'
								),
								count( watched.length )
							) }
						</h2>
						<Refusal
							error={ results.error }
							buyUrl={ ctx.buyUrl }
						/>
						<RunLine run={ results.latest } />
						<Table
							label={ __( 'Links watched', 'zinn-seo' ) }
							rowKey={ ( r, i ) => i }
							rows={ watched }
							columns={ [
								{
									id: 'from',
									label: __( 'Linking page', 'zinn-seo' ),
									render: ( r ) => (
										<a
											href={ r.source_url }
											className="zseo-url"
											dir="ltr"
										>
											{ r.source_url }
										</a>
									),
								},
								{
									id: 'state',
									label: __( 'Last check', 'zinn-seo' ),
									render: ( r ) => {
										const found = rows.find(
											( x ) =>
												x.source_url === r.source_url &&
												( x.target_url || '' ) ===
													( r.target_url || '' )
										);
										if ( ! found ) {
											return (
												<span className="zseo-muted">
													{ __(
														'Not checked yet',
														'zinn-seo'
													) }
												</span>
											);
										}
										return (
											<>
												<span
													className={ `zseo-tag ${
														tone[ found.status ] ||
														''
													}` }
												>
													{ words[ found.status ] ||
														found.status }
												</span>
												{ found.problem && (
													<span className="zseo-small">
														{ ' ' }
														{ linkProblem(
															found.problem
														) }
													</span>
												) }
												{ 'live' === found.status &&
													false ===
														found.dofollow && (
														<span className="zseo-small">
															{ ' ' }
															{ linkProblem(
																'now_nofollow'
															) }
														</span>
													) }
											</>
										);
									},
								},
								{
									id: 'do',
									label: __( 'Action', 'zinn-seo' ),
									render: ( r, i ) =>
										ctx.canSpend ? (
											<Button
												variant="link"
												isDestructive
												disabled={ !! action.busy }
												onClick={ () =>
													save(
														watched.filter(
															( _, j ) => j !== i
														),
														__(
															'Removed.',
															'zinn-seo'
														)
													)
												}
											>
												{ __(
													'Stop watching',
													'zinn-seo'
												) }
											</Button>
										) : null,
								},
							] }
						/>
					</CardBody>
				</Card>
			) }
			<Profile ctx={ ctx } />
			{ data.toolsPro ? (
				<BacklinksPro data={ data } ctx={ ctx } />
			) : (
				<ProTeaser
					title={ __( 'Clean up harmful backlinks', 'zinn-seo' ) }
					points={ [
						__(
							'Every backlink rated: harmful, worth a look, or fine — with the reason.',
							'zinn-seo'
						),
						__(
							'Tick the ones to drop and download a disavow file ready for Google.',
							'zinn-seo'
						),
						__(
							'An alert the day a wave of spammy links points at your site, or another site copies your content.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}
