/**
 * Pro: Google Business Profile reviews, replies and posts (zs-361); the business on Apple
 * Business Connect and Bing Places kept in step (zs-442).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { Loaded, NewTab, Refusal, useAction, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';

const STARS = { ONE: 1, TWO: 2, THREE: 3, FOUR: 4, FIVE: 5 };

/**
 * Save text as a file (the browser's own download; nothing leaves the site).
 *
 * @param {string} name File name.
 * @param {string} text Contents.
 * @return {void}
 */
function save( name, text ) {
	const url = URL.createObjectURL(
		new Blob( [ '﻿' + text ], { type: 'text/csv;charset=utf-8' } )
	);
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = name;
	document.body.appendChild( a );
	a.click();
	a.remove();
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

/**
 * Where a listing stands, in words.
 *
 * @param {Object} st `{ downloaded, changed }`.
 * @return {string} Text.
 */
function listingState( st ) {
	if ( ! st.downloaded ) {
		return __( 'Not uploaded from here yet.', 'zinn-seo' );
	}
	if ( st.changed ) {
		return __(
			'Your details changed since the last upload — upload the new file.',
			'zinn-seo'
		);
	}
	return sprintf(
		/* translators: %s: date. */
		__( 'Up to date (downloaded %s).', 'zinn-seo' ),
		day( st.downloaded, true )
	);
}

/**
 * One review with its reply box.
 *
 * @param {Object}  props
 * @param {Object}  props.review    Review.
 * @param {boolean} props.canManage May reply.
 * @return {Element} Item.
 */
function Review( { review, canManage } ) {
	const act = useAction();
	const [ text, setText ] = useState( review.reply || '' );
	const [ reply, setReply ] = useState( review.reply || '' );
	const stars = STARS[ review.rating ] || 0;
	return (
		<li className="zseo-review">
			<div className="zseo-cardhead">
				<strong>
					{ review.author || __( 'A Google user', 'zinn-seo' ) }
				</strong>
				<span
					aria-label={ sprintf(
						/* translators: %d: stars out of 5. */ __(
							'%d out of 5 stars',
							'zinn-seo'
						),
						stars
					) }
				>
					{ '★'.repeat( stars ) }
					{ '☆'.repeat( 5 - stars ) }
				</span>
				<span className="zseo-muted zseo-small">
					{ day( review.created ) }
				</span>
			</div>
			{ review.comment && <p>{ review.comment }</p> }
			{ reply && (
				<p className="zseo-found">
					<strong>{ __( 'Your reply:', 'zinn-seo' ) }</strong>{ ' ' }
					{ reply }
				</p>
			) }
			{ canManage && (
				<div className="zseo-stack">
					<TextareaControl
						label={
							reply
								? __( 'Change your reply', 'zinn-seo' )
								: __( 'Reply', 'zinn-seo' )
						}
						value={ text }
						onChange={ setText }
						rows={ 2 }
						__nextHasNoMarginBottom
					/>
					<div>
						<Button
							variant="secondary"
							disabled={ ! text.trim() || !! act.busy }
							isBusy={ !! act.busy }
							onClick={ () =>
								act.run( 'reply', () =>
									seoFetch( '/search/business/reply', {
										method: 'POST',
										data: { id: review.id, text },
									} ).then( ( r ) => setReply( r.reply ) )
								)
							}
						>
							{ __( 'Publish the reply on Google', 'zinn-seo' ) }
						</Button>
					</div>
					<Refusal error={ act.error } onRemove={ act.clear } />
				</div>
			) }
		</li>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data   Boot data.
 * @param {Object} props.google Link.
 * @return {Element} Tab.
 */
export default function BusinessTab( { data, google } ) {
	const has = !! google.connected && !! google.business_location;
	const reviews = useLoad( has ? '/search/business/reviews' : null );
	const listings = useLoad( '/search/listings' );
	const act = useAction();
	const [ post, setPost ] = useState( '' );
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<h3 className="zseo-card-title">
						{ __( 'Google reviews and posts', 'zinn-seo' ) }
					</h3>
					{ ! has ? (
						<Notice status="info" isDismissible={ false }>
							{ ( google.features || [] ).includes( 'business' )
								? __(
										'Choose your Business Profile location on the Google card above.',
										'zinn-seo'
									)
								: __(
										'Tick “Google Business Profile” and press Add these on the Google card above.',
										'zinn-seo'
									) }
						</Notice>
					) : (
						<Loaded load={ reviews }>
							{ ( r ) => (
								<>
									<p>
										{ sprintf(
											/* translators: 1: average stars, 2: number of reviews. */
											__(
												'%1$s stars from %2$s reviews',
												'zinn-seo'
											),
											r.average
												? r.average.toFixed( 1 )
												: '—',
											count( r.total )
										) }
									</p>
									<ul className="zseo-reviews">
										{ r.reviews.map( ( rv ) => (
											<Review
												key={ rv.id }
												review={ rv }
												canManage={ data.canManage }
											/>
										) ) }
									</ul>
									{ data.canManage && (
										<div className="zseo-stack">
											<TextareaControl
												label={ __(
													'Publish a post on your Business Profile',
													'zinn-seo'
												) }
												help={ __(
													'Up to 1,500 characters. It shows on Google Search and Maps.',
													'zinn-seo'
												) }
												value={ post }
												onChange={ setPost }
												__nextHasNoMarginBottom
											/>
											<div>
												<Button
													variant="secondary"
													disabled={
														! post.trim() ||
														!! act.busy
													}
													isBusy={
														act.busy === 'post'
													}
													onClick={ () =>
														act.run(
															'post',
															() =>
																seoFetch(
																	'/search/business/post',
																	{
																		method: 'POST',
																		data: {
																			summary:
																				post,
																		},
																	}
																).then( () =>
																	setPost(
																		''
																	)
																),
															__(
																'Published on Google.',
																'zinn-seo'
															)
														)
													}
												>
													{ __(
														'Publish',
														'zinn-seo'
													) }
												</Button>
											</div>
										</div>
									) }
								</>
							) }
						</Loaded>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h3 className="zseo-card-title">
						{ __( 'Apple Maps and Bing listings', 'zinn-seo' ) }
					</h3>
					<p className="zseo-small zseo-muted">
						{ __(
							'Local rankings reward the same name, address, phone and hours everywhere. Download your business details in each service’s own import format and upload them there; this screen tells you when they change so the listings never drift.',
							'zinn-seo'
						) }
					</p>
					<Loaded load={ listings }>
						{ ( l ) =>
							l.places === 0 ? (
								<p>
									{ __(
										'Add your business details under Works with → Local business first.',
										'zinn-seo'
									) }
								</p>
							) : (
								<ul className="zseo-log">
									{ [
										[
											'apple',
											// Service names are brands, so they are not translated.
											'Apple Business Connect',
											'https://businessconnect.apple.com/',
										],
										[
											'bing',
											'Bing Places',
											'https://www.bingplaces.com/',
										],
									].map( ( [ s, name, href ] ) => (
										<li key={ s }>
											<strong>{ name }</strong>
											<span>
												{ listingState( l[ s ] ) }
											</span>
											<Button
												variant={
													l[ s ].changed
														? 'primary'
														: 'secondary'
												}
												disabled={
													! data.canManage ||
													!! act.busy
												}
												onClick={ () =>
													act.run( s, () =>
														seoFetch(
															`/search/listings/${ s }`
														).then( ( f ) => {
															save(
																f.name,
																f.csv
															);
															listings.reload();
														} )
													)
												}
											>
												{ sprintf(
													/* translators: %d: number of places. */ __(
														'Download (%d places)',
														'zinn-seo'
													),
													l.places
												) }
											</Button>
											<NewTab href={ href }>
												{ __( 'Open', 'zinn-seo' ) }
											</NewTab>
										</li>
									) ) }
								</ul>
							)
						}
					</Loaded>
				</CardBody>
			</Card>
			{ act.done && (
				<Notice status="success" isDismissible={ false }>
					{ act.done }
				</Notice>
			) }
			<Refusal error={ act.error } onRemove={ act.clear } />
		</div>
	);
}
