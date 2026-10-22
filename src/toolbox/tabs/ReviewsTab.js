/**
 * Reviews (zs-521): the rating and the latest reviews from Google, Trustpilot and Tripadvisor in
 * one place, an alert when new ones arrive or the rating drops, and (Pro) AI drafts of replies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import { Refusal, useAction } from '../../tools/parts';
import { count, day } from '../../tools/pure';
import { domainOf, tripadvisorPage, unitsOf } from '../pure';
import {
	History,
	RunMoney,
	RunNow,
	RunState,
	Schedule,
	useRuns,
} from '../shared';
import { PlaceFinder } from './GridTab';

const NAMES = {
	google: 'Google',
	trustpilot: 'Trustpilot',
	tripadvisor: 'Tripadvisor',
};

/**
 * AI reply drafts for one platform's reviews (Pro): suggestions to copy, edit and post.
 *
 * @param {Object} props
 * @param {Object} props.ctx      Shared state.
 * @param {Array}  props.reviews  Reviews.
 * @param {string} props.business Business name.
 * @return {Element} Drafts.
 */
function Replies( { ctx, reviews, business } ) {
	const action = useAction();
	const [ drafts, setDrafts ] = useState( {} );
	const ask = () =>
		action
			.run( 'ai', () =>
				seoFetch( '/toolbox/ai/review-replies', {
					method: 'POST',
					data: { reviews, business },
				} )
			)
			.then( ( out ) => {
				if ( out ) {
					const map = {};
					( out.replies || [] ).forEach(
						( r ) => ( map[ r.n ] = r.reply )
					);
					setDrafts( map );
				}
			} );
	if ( ! ctx.pro ) {
		return null;
	}
	return (
		<div className="zseo-stack">
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			<div>
				<Button
					variant="secondary"
					isBusy={ 'ai' === action.busy }
					onClick={ ask }
				>
					{ __( 'Draft replies with AI', 'zinn-seo' ) }
				</Button>
			</div>
			{ Object.keys( drafts ).length > 0 && (
				<p className="zseo-muted">
					{ __(
						'Edit each reply, copy it, and post it on the review site. Nothing is posted for you.',
						'zinn-seo'
					) }
				</p>
			) }
			{ reviews.map( ( r, n ) =>
				drafts[ n ] !== undefined ? (
					<TextareaControl
						key={ n }
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: a reviewer's name. */
							__( 'Reply to %s', 'zinn-seo' ),
							r.author || __( 'this review', 'zinn-seo' )
						) }
						value={ drafts[ n ] }
						onChange={ ( v ) =>
							setDrafts( { ...drafts, [ n ]: v } )
						}
					/>
				) : null
			) }
		</div>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.ctx Shared state.
 * @return {Element} Tab.
 */
export default function ReviewsTab( { ctx } ) {
	const runs = useRuns( 'reviews' );
	const [ place, setPlace ] = useState( null );
	const [ trustpilot, setTrustpilot ] = useState( '' );
	const [ tripadvisor, setTripadvisor ] = useState( '' );
	const profiles = [];
	if ( place && place.place_id ) {
		profiles.push( {
			platform: 'google',
			profile_id: place.place_id,
			name: place.name,
		} );
	}
	if ( domainOf( trustpilot ) ) {
		profiles.push( {
			platform: 'trustpilot',
			profile_id: domainOf( trustpilot ),
		} );
	}
	if ( tripadvisorPage( tripadvisor ) ) {
		profiles.push( {
			platform: 'tripadvisor',
			profile_id: tripadvisorPage( tripadvisor ),
		} );
	}
	const params = { profiles, location: ctx.market.location || '' };
	const units = unitsOf( 'reviews', params );
	let why = '';
	if ( ! units ) {
		why = __( 'Add at least one review site.', 'zinn-seo' );
	} else if ( tripadvisor && ! tripadvisorPage( tripadvisor ) ) {
		why = __(
			'Paste the address of your Tripadvisor page, as it shows in the browser.',
			'zinn-seo'
		);
	}
	const run = runs.run;
	const result = ( run && run.result ) || {};
	return (
		<div className="zseo-stack">
			<PlaceFinder
				ctx={ ctx }
				business={ place }
				onPick={ setPlace }
				label={ __( 'Your business on Google', 'zinn-seo' ) }
			/>
			<div className="zseo-formrow">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Your website on Trustpilot', 'zinn-seo' ) }
					help={ __(
						'The domain, such as example.com.',
						'zinn-seo'
					) }
					value={ trustpilot }
					onChange={ setTrustpilot }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Your Tripadvisor page', 'zinn-seo' ) }
					help={ __( 'Paste its address.', 'zinn-seo' ) }
					value={ tripadvisor }
					onChange={ setTripadvisor }
				/>
			</div>
			<RunNow
				product="reviews"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Read my reviews', 'zinn-seo' ) }
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product="reviews"
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Alert me to new reviews', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ ( run && run.alerts ? run.alerts : [] ).map( ( a, i ) => (
				<Notice
					key={ i }
					status={ 'rating_drop' === a.kind ? 'warning' : 'info' }
					isDismissible={ false }
				>
					{ 'rating_drop' === a.kind
						? sprintf(
								/* translators: 1: review site, 2: old rating, 3: new rating. */
								__(
									'%1$s rating fell from %2$s to %3$s.',
									'zinn-seo'
								),
								NAMES[ a.platform ] || a.platform,
								a.from,
								a.to
							)
						: sprintf(
								/* translators: 1: how many, 2: review site, 3: how many are poor. */
								__(
									'%1$s new reviews on %2$s (%3$s poor).',
									'zinn-seo'
								),
								count( a.count ),
								NAMES[ a.platform ] || a.platform,
								count( a.poor )
							) }
				</Notice>
			) ) }
			{ ( result.platforms || [] ).map( ( p ) => (
				<section key={ p.platform } className="zseo-reviews">
					<h3 className="zseo-card-title">
						{ NAMES[ p.platform ] || p.platform }
						{ p.answered && p.found
							? ` · ${ p.rating ?? '—' } ★ · ${ sprintf(
									/* translators: %s: a number of reviews. */
									__( '%s reviews', 'zinn-seo' ),
									count( p.review_count )
								) }`
							: '' }
					</h3>
					{ ! p.answered && (
						<p className="zseo-muted">
							{ __(
								'Not read this time (refunded).',
								'zinn-seo'
							) }
						</p>
					) }
					{ p.answered && ! p.found && (
						<p className="zseo-muted">
							{ __( 'No profile was found there.', 'zinn-seo' ) }
						</p>
					) }
					{ p.unanswered_negative > 0 && (
						<p>
							<strong>
								{ sprintf(
									/* translators: %s: a number of reviews. */
									__(
										'%s poor reviews have no reply yet.',
										'zinn-seo'
									),
									count( p.unanswered_negative )
								) }
							</strong>
						</p>
					) }
					<ul className="zseo-reviewlist">
						{ ( p.reviews || [] ).map( ( r, i ) => (
							<li
								key={ i }
								className={
									r.rating !== null && r.rating <= 3
										? 'is-poor'
										: ''
								}
							>
								<strong>{ r.rating ?? '—' } ★</strong>{ ' ' }
								<span className="zseo-muted">
									{ r.author } · { day( r.published_at ) }
									{ r.replied
										? ` · ${ __( 'replied', 'zinn-seo' ) }`
										: '' }
								</span>
								<p dir="auto">
									{ r.title ? `${ r.title } — ` : '' }
									{ r.text }
								</p>
							</li>
						) ) }
					</ul>
					{ ( p.reviews || [] ).length > 0 && (
						<Replies
							ctx={ ctx }
							reviews={ p.reviews }
							business={ p.name }
						/>
					) }
				</section>
			) ) }
			<RunMoney run={ run } />
		</div>
	);
}
