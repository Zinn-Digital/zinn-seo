/**
 * Pro: who is talking about you — pages across the web that mention your brand — and sites that
 * copied a page of yours.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Loaded, Refusal, Table, useAction, useLoad } from '../parts';
import { count, day } from '../pure';
import { Empty, NeedsPlan } from '../tool';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function MentionsTab( { data, ctx } ) {
	const held = ( ctx.plan.products || [] ).includes( 'brand_mentions' );
	const [ tone, setTone ] = useState( '' );
	const load = useLoad( held ? `/tools/mentions?tone=${ tone }` : null );
	const pages = useLoad( held ? '/tools/pages' : null );
	const action = useAction();
	const [ phrase, setPhrase ] = useState( '' );
	const [ frequency, setFrequency ] = useState( 'weekly' );
	const [ page, setPage ] = useState( '' );
	const phrases = ctx.target.mentions || [];
	const save = ( list, done ) =>
		action
			.run(
				'save',
				() =>
					seoFetch( '/tools/mentions/phrases', {
						method: 'PUT',
						data: { phrases: list },
					} ),
				done
			)
			.then( ( saved ) => {
				if ( saved ) {
					setPhrase( '' );
					ctx.refresh();
				}
			} );
	const tones = {
		positive: { words: __( 'Positive', 'zinn-seo' ), cls: 'is-success' },
		neutral: { words: __( 'Neutral', 'zinn-seo' ), cls: '' },
		negative: { words: __( 'Negative', 'zinn-seo' ), cls: 'is-critical' },
	};

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Know when people talk about you',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="checks-mentions" />
					</div>
					<p className="zseo-lead">
						{ __(
							'A mention without a link is a link you can ask for. A bad review you never saw is a customer you lose. We listen across the web for your name — and for copies of your pages.',
							'zinn-seo'
						) }
					</p>
					{ ! held ? (
						<NeedsPlan
							kind="brand_mentions"
							buyUrl={ ctx.buyUrl }
							text={ __(
								'Hear about new mentions of your brand, good and bad, and about sites that copy your content.',
								'zinn-seo'
							) }
						/>
					) : (
						<>
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
							{ ctx.canSpend && (
								<>
									<div className="zseo-row">
										<TextControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'A name or phrase to listen for',
												'zinn-seo'
											) }
											value={ phrase }
											onChange={ setPhrase }
										/>
										<SelectControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'How often',
												'zinn-seo'
											) }
											value={ frequency }
											options={ [
												{
													value: 'daily',
													label: __(
														'Every day',
														'zinn-seo'
													),
												},
												{
													value: 'weekly',
													label: __(
														'Every week',
														'zinn-seo'
													),
												},
												{
													value: 'monthly',
													label: __(
														'Every month',
														'zinn-seo'
													),
												},
											] }
											onChange={ setFrequency }
										/>
									</div>
									<div>
										<Button
											variant="secondary"
											disabled={
												phrase.trim().length < 3 ||
												!! action.busy
											}
											isBusy={ 'save' === action.busy }
											onClick={ () =>
												save(
													[
														...phrases,
														{ phrase, frequency },
													],
													__(
														'Saved. It is checked on the next round.',
														'zinn-seo'
													)
												)
											}
										>
											{ __(
												'Listen for it',
												'zinn-seo'
											) }
										</Button>
									</div>
									<div className="zseo-row">
										<SelectControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'Watch a page for copies',
												'zinn-seo'
											) }
											help={ __(
												'We listen for one sentence of the page. Any other site showing it has copied the page — you are told once per site.',
												'zinn-seo'
											) }
											value={ page }
											options={ [
												{
													value: '',
													label: __(
														'Choose a page…',
														'zinn-seo'
													),
												},
												...(
													( pages.data &&
														pages.data.items ) ||
													[]
												).map( ( p ) => ( {
													value: String( p.id ),
													label: p.title || p.url,
												} ) ),
											] }
											onChange={ setPage }
										/>
									</div>
									<div>
										<Button
											variant="secondary"
											disabled={
												! page || !! action.busy
											}
											isBusy={ 'watch' === action.busy }
											onClick={ () =>
												action
													.run(
														'watch',
														() =>
															seoFetch(
																'/tools/mentions/watch',
																{
																	method: 'POST',
																	data: {
																		post: Number(
																			page
																		),
																	},
																}
															),
														__(
															'Watching that page for copies.',
															'zinn-seo'
														)
													)
													.then( ( done ) => {
														if ( done ) {
															setPage( '' );
															ctx.refresh();
														}
													} )
											}
										>
											{ __(
												'Watch this page',
												'zinn-seo'
											) }
										</Button>
									</div>
								</>
							) }
							{ phrases.length > 0 && (
								<Table
									label={ __(
										'Phrases listened for',
										'zinn-seo'
									) }
									rowKey={ ( r ) => r.phrase }
									rows={ phrases }
									columns={ [
										{
											id: 'phrase',
											label: __( 'Phrase', 'zinn-seo' ),
											render: ( r ) => r.phrase,
										},
										{
											id: 'last',
											label: __(
												'Last checked',
												'zinn-seo'
											),
											render: ( r ) =>
												r.last_checked_at
													? day(
															r.last_checked_at,
															true
														)
													: __(
															'Not yet',
															'zinn-seo'
														),
										},
										{
											id: 'do',
											label: __( 'Action', 'zinn-seo' ),
											render: ( r ) =>
												ctx.canSpend ? (
													<Button
														variant="link"
														isDestructive
														disabled={
															!! action.busy
														}
														onClick={ () =>
															save(
																phrases.filter(
																	( p ) =>
																		p.phrase !==
																		r.phrase
																),
																__(
																	'Removed.',
																	'zinn-seo'
																)
															)
														}
													>
														{ __(
															'Stop listening',
															'zinn-seo'
														) }
													</Button>
												) : null,
										},
									] }
								/>
							) }
						</>
					) }
				</CardBody>
			</Card>
			{ held && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-card-title">
							{ __( 'Mentions found', 'zinn-seo' ) }
						</h2>
						<div className="zseo-row">
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Tone', 'zinn-seo' ) }
								value={ tone }
								options={ [
									{
										value: '',
										label: __( 'Any', 'zinn-seo' ),
									},
									...Object.keys( tones ).map( ( t ) => ( {
										value: t,
										label: tones[ t ].words,
									} ) ),
								] }
								onChange={ setTone }
							/>
						</div>
						<Loaded load={ load } buyUrl={ ctx.buyUrl }>
							{ ( found ) =>
								found.mentions.length ? (
									<>
										<p className="zseo-muted">
											{ sprintf(
												/* translators: 1: mentions shown, 2: mentions in all. */
												_n(
													'Showing %1$s of %2$s mention.',
													'Showing %1$s of %2$s mentions.',
													found.total,
													'zinn-seo'
												),
												count( found.mentions.length ),
												count( found.total )
											) }
										</p>
										<ul className="zseo-mentions">
											{ found.mentions.map( ( m ) => (
												<li key={ m.id || m.url }>
													<div className="zseo-noticelist__top">
														<span
															className={ `zseo-tag ${
																m.copy_of
																	? 'is-critical'
																	: tones[
																			m
																				.tone
																		].cls
															}` }
														>
															{ m.copy_of
																? __(
																		'Copy of your page',
																		'zinn-seo'
																	)
																: tones[
																		m.tone
																	].words }
														</span>
														<span className="zseo-muted zseo-small">
															{ day(
																m.first_seen
															) }
														</span>
													</div>
													<a
														href={ m.url }
														target="_blank"
														rel="noopener noreferrer nofollow"
													>
														{ m.title || m.url }
													</a>{ ' ' }
													<span
														className="zseo-muted"
														dir="ltr"
													>
														{ m.domain }
													</span>
													{ m.snippet && (
														<p>{ m.snippet }</p>
													) }
												</li>
											) ) }
										</ul>
									</>
								) : (
									<Empty>
										{ __(
											'No mentions found yet. New ones appear here after each check.',
											'zinn-seo'
										) }
									</Empty>
								)
							}
						</Loaded>
					</CardBody>
				</Card>
			) }
		</div>
	);
}
