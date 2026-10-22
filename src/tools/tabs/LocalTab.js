/**
 * Pro: the local rank grid — where the business stands on Google Maps and in the local results,
 * keyword by keyword, town by town.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import GuideLink from '../../admin/p7/GuideLink';
import { Loaded, Refusal, Table, useAction, useLoad } from '../parts';
import { count, day, lines } from '../pure';
import { Empty, NeedsPlan, RunBar } from '../tool';
import { marketOptions } from '../markets';

/**
 * A position, or "not found" (never zero).
 *
 * @param {number|null} position Position.
 * @return {string} Words.
 */
function place( position ) {
	return null === position || undefined === position
		? __( 'not found', 'zinn-seo' )
		: count( position );
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.ctx  The checks' shared state.
 * @return {Element} Tab.
 */
export default function LocalTab( { data, ctx } ) {
	const load = useLoad( '/tools/local' );
	const action = useAction();
	const [ country, setCountry ] = useState( ctx.market.country );
	const [ town, setTown ] = useState( '' );
	const [ towns, setTowns ] = useState( [] );
	const [ name, setName ] = useState( data.siteName || '' );
	const [ found, setFound ] = useState( null );
	const [ setup, setSetup ] = useState( null );
	const [ words, setWords ] = useState( '' );
	const grid = load.data && load.data.grid;

	useEffect( () => {
		if ( grid && null === setup ) {
			setSetup( {
				business: grid.business || null,
				places: grid.places || [],
			} );
			setWords(
				( grid.keywords || [] ).map( ( k ) => k.phrase ).join( '\n' )
			);
		}
	}, [ grid, setup ] );

	const current = setup || { business: null, places: [] };
	const search = () =>
		action
			.run( 'towns', () =>
				seoFetch(
					`/tools/local/places?country=${ country }&q=${ encodeURIComponent(
						town
					) }`
				)
			)
			.then( ( got ) => got && setTowns( got.places || [] ) );
	const find = () =>
		action
			.run( 'find', () =>
				seoFetch( '/tools/local/find', {
					method: 'POST',
					data: { query: name, geo_id: current.places[ 0 ].geo_id },
				} )
			)
			.then( ( got ) => got && setFound( got.results || [] ) );
	const save = () =>
		action
			.run(
				'save',
				() =>
					seoFetch( '/tools/local', {
						method: 'PUT',
						data: {
							business: current.business,
							places: current.places,
							keywords: lines( words ).map( ( phrase ) => ( {
								phrase,
							} ) ),
						},
					} ),
				__(
					'Saved. The first positions arrive with the next check.',
					'zinn-seo'
				)
			)
			.then( ( saved ) => {
				if ( saved ) {
					load.reload();
					ctx.refresh();
				}
			} );

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __( 'Be found by customers nearby', 'zinn-seo' ) }
						</h2>
						<GuideLink data={ data } id="checks-local" />
					</div>
					<p className="zseo-lead">
						{ __(
							'“Plumber near me” shows different businesses in every town. See where yours appears on Google Maps and in the local results — for each service, in each town you serve — and who is above you.',
							'zinn-seo'
						) }
					</p>
					<Loaded load={ load } buyUrl={ ctx.buyUrl }>
						{ ( local ) =>
							! local.entitled ? (
								<NeedsPlan
									kind="local_rank"
									buyUrl={ ctx.buyUrl }
									text={ __(
										'Track your map and local positions town by town.',
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
									<h3 className="zseo-group__title">
										{ __(
											'1. The towns you serve',
											'zinn-seo'
										) }
									</h3>
									<div className="zseo-row">
										<SelectControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'Country',
												'zinn-seo'
											) }
											value={ country }
											options={ marketOptions(
												ctx.markets
											) }
											onChange={ setCountry }
										/>
										<TextControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'Town or area',
												'zinn-seo'
											) }
											value={ town }
											onChange={ setTown }
										/>
										<div>
											<Button
												variant="secondary"
												disabled={
													town.trim().length < 2 ||
													!! action.busy
												}
												isBusy={
													'towns' === action.busy
												}
												onClick={ search }
											>
												{ __(
													'Find the town',
													'zinn-seo'
												) }
											</Button>
										</div>
									</div>
									{ towns.length > 0 && (
										<ul className="zseo-choices">
											{ towns.map( ( t ) => (
												<li key={ t.geo_id }>
													<Button
														variant="link"
														onClick={ () => {
															setSetup( {
																...current,
																places: [
																	...current.places.filter(
																		( p ) =>
																			p.geo_id !==
																			t.geo_id
																	),
																	{
																		geo_id: t.geo_id,
																		name: t.name,
																		country:
																			t.country,
																		device: 'mobile',
																	},
																],
															} );
															setTowns( [] );
															setTown( '' );
														} }
													>
														{ t.name }
													</Button>{ ' ' }
													<span className="zseo-muted">
														{ t.kind }
													</span>
												</li>
											) ) }
										</ul>
									) }
									{ current.places.length > 0 && (
										<ul className="zseo-chips">
											{ current.places.map( ( p ) => (
												<li key={ p.geo_id }>
													{ p.name }{ ' ' }
													<Button
														variant="link"
														isDestructive
														onClick={ () =>
															setSetup( {
																...current,
																places: current.places.filter(
																	( x ) =>
																		x.geo_id !==
																		p.geo_id
																),
															} )
														}
													>
														{ __(
															'Remove',
															'zinn-seo'
														) }
													</Button>
												</li>
											) ) }
										</ul>
									) }
									<h3 className="zseo-group__title">
										{ __(
											'2. Your business on Google Maps',
											'zinn-seo'
										) }
									</h3>
									{ current.business ? (
										<p>
											<strong>
												{ current.business.name }
											</strong>{ ' ' }
											<span className="zseo-muted">
												{ current.business.address }
											</span>{ ' ' }
											<Button
												variant="link"
												onClick={ () =>
													setSetup( {
														...current,
														business: null,
													} )
												}
											>
												{ __( 'Change', 'zinn-seo' ) }
											</Button>
										</p>
									) : (
										<>
											<div className="zseo-row">
												<TextControl
													__nextHasNoMarginBottom
													__next40pxDefaultSize
													label={ __(
														'Business name',
														'zinn-seo'
													) }
													value={ name }
													onChange={ setName }
												/>
												<div>
													<Button
														variant="secondary"
														disabled={
															! current.places
																.length ||
															! name.trim() ||
															!! action.busy
														}
														isBusy={
															'find' ===
															action.busy
														}
														onClick={ find }
													>
														{ __(
															'Find it on the map',
															'zinn-seo'
														) }
													</Button>
												</div>
											</div>
											<p className="zseo-muted zseo-small">
												{ __(
													'One search on the map; it uses one of this month’s checks. Add a town first.',
													'zinn-seo'
												) }
											</p>
											{ found && ! found.length && (
												<Empty>
													{ __(
														'The map showed no business by that name there. Check the spelling, or search from the town it is in.',
														'zinn-seo'
													) }
												</Empty>
											) }
											{ found && found.length > 0 && (
												<ul className="zseo-choices">
													{ found.map( ( b ) => (
														<li key={ b.cid }>
															<Button
																variant="link"
																onClick={ () => {
																	setSetup( {
																		...current,
																		business:
																			{
																				cid: b.cid,
																				name: b.name,
																				domain: b.domain,
																				address:
																					b.address,
																				category:
																					b.category,
																			},
																	} );
																	setFound(
																		null
																	);
																} }
															>
																{ b.name }
															</Button>{ ' ' }
															<span className="zseo-muted">
																{ b.address }
															</span>
														</li>
													) ) }
												</ul>
											) }
										</>
									) }
									<h3 className="zseo-group__title">
										{ __(
											'3. What customers search for',
											'zinn-seo'
										) }
									</h3>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ __(
											'Keywords (one per line)',
											'zinn-seo'
										) }
										help={ __(
											'Every keyword is checked from every town.',
											'zinn-seo'
										) }
										rows={ 3 }
										value={ words }
										onChange={ setWords }
									/>
									{ ctx.canSpend && (
										<div className="zseo-actions zseo-actions--start">
											<Button
												variant="primary"
												disabled={
													! current.business ||
													! current.places.length ||
													! lines( words ).length ||
													!! action.busy
												}
												isBusy={
													'save' === action.busy
												}
												onClick={ save }
											>
												{ __( 'Save', 'zinn-seo' ) }
											</Button>
											<span className="zseo-muted">
												{ sprintf(
													/* translators: %s: a number of checks. */
													_n(
														'%s check each round',
														'%s checks each round',
														current.places.length *
															lines( words )
																.length,
														'zinn-seo'
													),
													count(
														current.places.length *
															lines( words )
																.length
													)
												) }
											</span>
										</div>
									) }
								</>
							)
						}
					</Loaded>
				</CardBody>
			</Card>
			{ grid && ( grid.rows || [] ).length > 0 && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-card-title">
							{ __(
								'Where you stand, town by town',
								'zinn-seo'
							) }
						</h2>
						{ grid.last_checked_at && (
							<p className="zseo-muted">
								{ sprintf(
									/* translators: %s: a date and time. */
									__( 'Last checked %s.', 'zinn-seo' ),
									day( grid.last_checked_at, true )
								) }
							</p>
						) }
						<Table
							label={ __( 'Local rankings', 'zinn-seo' ) }
							rowKey={ ( r ) => r.key }
							rows={ grid.rows }
							columns={ [
								{
									id: 'phrase',
									label: __( 'Keyword', 'zinn-seo' ),
									render: ( r ) => (
										<strong>{ r.phrase }</strong>
									),
								},
								{
									id: 'town',
									label: __( 'Town', 'zinn-seo' ),
									render: ( r ) =>
										( r.place && r.place.name ) || '',
								},
								{
									id: 'map',
									label: __( 'On the map', 'zinn-seo' ),
									numeric: true,
									render: ( r ) => {
										if ( r.pending ) {
											return __(
												'answer on its way',
												'zinn-seo'
											);
										}
										return r.latest
											? place( r.latest.map_position )
											: __(
													'not checked yet',
													'zinn-seo'
												);
									},
								},
								{
									id: 'pack',
									label: __( 'In the top 3 box', 'zinn-seo' ),
									numeric: true,
									render: ( r ) =>
										r.latest
											? place( r.latest.pack_position )
											: '—',
								},
								{
									id: 'site',
									label: __( 'Your website', 'zinn-seo' ),
									numeric: true,
									render: ( r ) =>
										r.latest
											? place( r.latest.organic_position )
											: '—',
								},
								{
									id: 'above',
									label: __( 'Above you', 'zinn-seo' ),
									render: ( r ) =>
										( r.rivals || [] )
											.slice( 0, 3 )
											.map( ( x ) => x.name )
											.join( ', ' ) || '—',
								},
							] }
						/>
						<RunBar
							tool="local"
							state={ ctx.tools.local }
							units={ 1 }
							ctx={ ctx }
							label={ __( 'Check now', 'zinn-seo' ) }
							onStart={ () =>
								window.setTimeout( load.reload, 20000 )
							}
						/>
					</CardBody>
				</Card>
			) }
		</div>
	);
}
