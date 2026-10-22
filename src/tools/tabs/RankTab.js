/**
 * Rank tracker: the phrases to follow, in which country and on which device, and where the site
 * stands for each.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Refusal, Table, useAction } from '../parts';
import { count, lines, money, movement } from '../pure';
import { Empty, Held, RunBar, RunLine, Schedule, useResults } from '../tool';
import { unitEach } from '../labels';
import { countryName, marketOptions } from '../markets';

/**
 * How a phrase moved, in words and an arrow.
 *
 * @param {Object} props
 * @param {Object} props.row A rank row.
 * @return {Element} Cell.
 */
function Move( { row } ) {
	const kind = movement( row );
	const words = {
		up: sprintf(
			/* translators: %s: a number of places. */
			_n( 'up %s place', 'up %s places', row.delta, 'zinn-seo' ),
			count( row.delta )
		),
		down: sprintf(
			/* translators: %s: a number of places. */
			_n(
				'down %s place',
				'down %s places',
				Math.abs( row.delta ),
				'zinn-seo'
			),
			count( Math.abs( row.delta ) )
		),
		same: __( 'no change', 'zinn-seo' ),
		new: __( 'first reading', 'zinn-seo' ),
		out: __( 'not in the top 100', 'zinn-seo' ),
		wait: __( 'answer on its way', 'zinn-seo' ),
	};
	const arrows = {
		up: '▲',
		down: '▼',
		same: '–',
		new: '•',
		out: '',
		wait: '…',
	};
	return (
		<span className={ `zseo-rankmove is-${ kind }` }>
			<span aria-hidden="true">{ arrows[ kind ] } </span>
			{ words[ kind ] }
		</span>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  `{ target, tools, account, canSpend, buyUrl, refresh, markets, market }`.
 * @return {Element} Tab.
 */
export default function RankTab( { data, ctx } ) {
	const state = ctx.tools.rank;
	const results = useResults( 'rank' );
	const action = useAction();
	const [ text, setText ] = useState( '' );
	const [ country, setCountry ] = useState( ctx.market.country );
	const [ device, setDevice ] = useState( '' );
	const tracked = ctx.target.keywords || [];
	const adding = lines( text );

	const save = ( list, done ) =>
		action
			.run(
				'save',
				() =>
					seoFetch( '/tools/rank/list', {
						method: 'PUT',
						data: { items: list },
					} ),
				done
			)
			.then( ( saved ) => {
				if ( saved ) {
					setText( '' );
					ctx.refresh();
				}
			} );
	const add = () => {
		const market = ctx.markets[ country ] || {};
		const devices =
			'both' === device ? [ 'desktop', 'mobile' ] : [ device ];
		const more = [];
		adding.forEach( ( phrase ) =>
			devices.forEach( ( d ) =>
				more.push( {
					phrase,
					location: market.location || '',
					language: market.language || '',
					device: d,
				} )
			)
		);
		save(
			[ ...tracked, ...more ],
			__(
				'Saved. These phrases are checked on your schedule.',
				'zinn-seo'
			)
		);
	};
	const remove = ( i ) =>
		save(
			tracked.filter( ( _, j ) => j !== i ),
			__( 'Removed.', 'zinn-seo' )
		);

	const summary = ( results.latest && results.latest.summary ) || {};
	const rows = summary.keywords || [];

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __( 'See where you rank on Google', 'zinn-seo' ) }
						</h2>
						<GuideLink data={ data } id="checks-rank" />
					</div>
					<p className="zseo-lead">
						{ __(
							'Add the phrases your customers search for. We check your position for each, tell you when one moves, and show who ranks above you.',
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
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __(
									'Phrases to track (one per line)',
									'zinn-seo'
								) }
								rows={ 4 }
								value={ text }
								onChange={ setText }
							/>
							<div className="zseo-row">
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Searched from', 'zinn-seo' ) }
									value={ country }
									options={ marketOptions( ctx.markets ) }
									onChange={ setCountry }
								/>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'On', 'zinn-seo' ) }
									value={ device }
									options={ [
										{
											value: '',
											label: __(
												'A computer',
												'zinn-seo'
											),
										},
										{
											value: 'mobile',
											label: __( 'A phone', 'zinn-seo' ),
										},
										{
											value: 'both',
											label: __(
												'Both (counts as two checks)',
												'zinn-seo'
											),
										},
									] }
									onChange={ setDevice }
								/>
							</div>
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant="secondary"
									disabled={
										! adding.length || !! action.busy
									}
									isBusy={ 'save' === action.busy }
									onClick={ add }
								>
									{ sprintf(
										/* translators: %s: a number of phrases. */
										_n(
											'Track %s phrase',
											'Track %s phrases',
											adding.length || 1,
											'zinn-seo'
										),
										count( adding.length || 1 )
									) }
								</Button>
								{ ! state.in_plan &&
									null !== state.unit_price && (
										<span className="zseo-cost">
											{ sprintf(
												/* translators: 1: an amount of money, 2: how it is counted. */
												__( '%1$s %2$s', 'zinn-seo' ),
												money( state.unit_price ),
												unitEach( state.unit )
											) }
										</span>
									) }
							</div>
						</>
					) }
					{ tracked.length > 0 && (
						<Schedule
							tool="rank"
							state={ state }
							units={ tracked.length }
							ctx={ ctx }
							label={ __(
								'Check these phrases automatically',
								'zinn-seo'
							) }
						/>
					) }
					<RunBar
						tool="rank"
						state={ state }
						units={ tracked.length }
						ctx={ ctx }
						label={ __( 'Check my rankings now', 'zinn-seo' ) }
						disabled={ ! tracked.length }
						why={ __(
							'Add a phrase first: only you know which searches you want to win.',
							'zinn-seo'
						) }
						running={ results.running }
						onStart={ results.watch }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'Your rankings', 'zinn-seo' ) }
					</h2>
					<Refusal error={ results.error } buyUrl={ ctx.buyUrl } />
					<RunLine run={ results.latest } />
					{ rows.length > 0 && (
						<dl className="zseo-stats">
							<div className="zseo-stats__item">
								<dt>{ __( 'Ranking', 'zinn-seo' ) }</dt>
								<dd>
									{ sprintf(
										/* translators: 1: how many phrases rank, 2: how many were checked. */
										__( '%1$s of %2$s', 'zinn-seo' ),
										count( summary.ranking ),
										count( summary.keywords_measured )
									) }
								</dd>
							</div>
							<div className="zseo-stats__item">
								<dt>{ __( 'On page one', 'zinn-seo' ) }</dt>
								<dd>{ count( summary.page_one ) }</dd>
							</div>
							<div className="zseo-stats__item">
								<dt>
									{ __( 'In the top three', 'zinn-seo' ) }
								</dt>
								<dd>{ count( summary.top_three ) }</dd>
							</div>
							<div className="zseo-stats__item">
								<dt>
									{ __( 'Average position', 'zinn-seo' ) }
								</dt>
								<dd>{ count( summary.average_position ) }</dd>
							</div>
						</dl>
					) }
					{ rows.length > 0 ? (
						<Table
							label={ __( 'Your rankings', 'zinn-seo' ) }
							rowKey={ ( r ) => r.key }
							rows={ rows }
							columns={ [
								{
									id: 'phrase',
									label: __( 'Phrase', 'zinn-seo' ),
									render: ( r ) => (
										<>
											<strong>{ r.phrase }</strong>
											{ 'mobile' === r.device && (
												<span className="zseo-tag">
													{ __(
														'phone',
														'zinn-seo'
													) }
												</span>
											) }
										</>
									),
								},
								{
									id: 'position',
									label: __( 'Position', 'zinn-seo' ),
									numeric: true,
									render: ( r ) =>
										r.position ? count( r.position ) : '—',
								},
								{
									id: 'move',
									label: __( 'Change', 'zinn-seo' ),
									render: ( r ) => <Move row={ r } />,
								},
								{
									id: 'page',
									label: __( 'Your page', 'zinn-seo' ),
									render: ( r ) =>
										r.url ? (
											<a
												href={ r.url }
												className="zseo-url"
												dir="ltr"
											>
												{ r.url.replace(
													/^https?:\/\/[^/]+/,
													''
												) || '/' }
											</a>
										) : (
											'—'
										),
								},
								{
									id: 'ai',
									label: __(
										'Google’s AI answer',
										'zinn-seo'
									),
									render: ( r ) => {
										if ( ! r.ai_overview ) {
											return (
												<span className="zseo-muted">
													{ __(
														'none shown',
														'zinn-seo'
													) }
												</span>
											);
										}
										return r.ai_overview_cites_us ? (
											<span className="zseo-ok">
												{ __(
													'quotes you',
													'zinn-seo'
												) }
											</span>
										) : (
											__(
												'does not quote you',
												'zinn-seo'
											)
										);
									},
								},
								{
									id: 'rivals',
									label: __( 'Above you', 'zinn-seo' ),
									render: ( r ) =>
										( r.rivals || [] ).length ? (
											<span dir="ltr">
												{ r.rivals
													.slice( 0, 3 )
													.map( ( x ) => x.domain )
													.join( ', ' ) }
											</span>
										) : (
											'—'
										),
								},
							] }
						/>
					) : (
						! results.loading && (
							<Empty>
								{ __(
									'No reading yet. Add your phrases and run the first check — positions, changes and the sites above you appear here.',
									'zinn-seo'
								) }
							</Empty>
						)
					) }
				</CardBody>
			</Card>
			{ tracked.length > 0 && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-card-title">
							{ sprintf(
								/* translators: %s: a number of phrases. */
								_n(
									'%s phrase tracked',
									'%s phrases tracked',
									tracked.length,
									'zinn-seo'
								),
								count( tracked.length )
							) }
						</h2>
						<Table
							label={ __( 'Phrases tracked', 'zinn-seo' ) }
							rowKey={ ( r, i ) => i }
							rows={ tracked }
							columns={ [
								{
									id: 'phrase',
									label: __( 'Phrase', 'zinn-seo' ),
									render: ( r ) => r.phrase,
								},
								{
									id: 'where',
									label: __( 'Searched from', 'zinn-seo' ),
									render: ( r ) =>
										countryName(
											ctx.markets,
											r.location
										) || __( 'Default', 'zinn-seo' ),
								},
								{
									id: 'device',
									label: __( 'On', 'zinn-seo' ),
									render: ( r ) =>
										'mobile' === r.device
											? __( 'A phone', 'zinn-seo' )
											: __( 'A computer', 'zinn-seo' ),
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
												onClick={ () => remove( i ) }
											>
												{ __(
													'Stop tracking',
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
		</div>
	);
}
