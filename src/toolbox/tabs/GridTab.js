/**
 * The local rank grid (zs-520): where the business shows on Google Maps from every point of a
 * grid around it — the street-by-street view a single "local rank" hides.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, SelectControl, TextControl } from '@wordpress/components';

import { seoFetch } from '../../admin/api';
import { Refusal, useAction } from '../../tools/parts';
import { count } from '../../tools/pure';
import { GRID_SIZES, band, gridRows, unitsOf } from '../pure';
import {
	History,
	RunMoney,
	RunNow,
	RunState,
	Schedule,
	useRuns,
} from '../shared';

/**
 * Pick the business on the map (free): search by name, choose the right one.
 *
 * @param {Object}                       props
 * @param {Object}                       props.ctx      Shared state.
 * @param {Object}                       props.business The chosen place.
 * @param {(place: Object|null) => void} props.onPick   Choose a place.
 * @param {string}                       props.label    What the search box asks.
 * @return {Element} Finder.
 */
export function PlaceFinder( { ctx, business, onPick, label } ) {
	const action = useAction();
	const [ query, setQuery ] = useState( '' );
	const [ places, setPlaces ] = useState( [] );
	const find = () =>
		action
			.run( 'find', () =>
				seoFetch( '/toolbox/places', {
					method: 'POST',
					data: { query, location: ctx.market.location || '' },
				} )
			)
			.then( ( found ) => found && setPlaces( found.places || [] ) );
	return (
		<div className="zseo-stack">
			<Refusal
				error={ action.error }
				buyUrl={ ctx.buyUrl }
				onRemove={ action.clear }
			/>
			{ business && business.name ? (
				<p>
					{ __( 'Your business:', 'zinn-seo' ) }{ ' ' }
					<strong>{ business.name }</strong>
					{ business.address
						? ` — ${ business.address }`
						: '' }{ ' ' }
					<Button variant="link" onClick={ () => onPick( null ) }>
						{ __( 'Change', 'zinn-seo' ) }
					</Button>
				</p>
			) : (
				<>
					<div className="zseo-actions zseo-actions--start">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ label }
							value={ query }
							onChange={ setQuery }
						/>
						<Button
							variant="secondary"
							disabled={ ! query.trim() || ! ctx.canSpend }
							isBusy={ 'find' === action.busy }
							onClick={ find }
						>
							{ __( 'Find it on Google Maps', 'zinn-seo' ) }
						</Button>
					</div>
					{ places.length > 0 && (
						<ul className="zseo-places">
							{ places.map( ( p ) => (
								<li key={ p.cid || p.place_id || p.name }>
									<Button
										variant="secondary"
										onClick={ () => onPick( p ) }
									>
										{ __(
											'This is my business',
											'zinn-seo'
										) }
									</Button>{ ' ' }
									<strong>{ p.name }</strong>
									<span className="zseo-muted">
										{ ' ' }
										{ p.address }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
		</div>
	);
}

/**
 * The grid itself: one square per map point, coloured by position, with the number on it.
 *
 * @param {Object} props
 * @param {Object} props.result The run's result.
 * @return {Element} Grid.
 */
export function Grid( { result } ) {
	const rows = gridRows( result.points );
	const words = {
		top: __( 'top 3', 'zinn-seo' ),
		good: __( '4 to 10', 'zinn-seo' ),
		low: __( 'below 10', 'zinn-seo' ),
		none: __( 'not in the top 100', 'zinn-seo' ),
		unknown: __( 'not measured (refunded)', 'zinn-seo' ),
	};
	return (
		<div className="zseo-stack">
			<p className="zseo-lead">
				{ sprintf(
					/* translators: 1: points where the business is in the top 3, 2: all points, 3: average position. */
					__(
						'In the top 3 at %1$s of %2$s points · average position %3$s',
						'zinn-seo'
					),
					count( result.top3_at ),
					count( ( result.points || [] ).length ),
					null === result.average_position
						? '—'
						: count( result.average_position )
				) }
			</p>
			<div
				className="zseo-grid"
				role="table"
				aria-label={ __(
					'Map position from each point, north at the top',
					'zinn-seo'
				) }
				style={ { '--zseo-grid-size': rows.length } }
			>
				{ rows.map( ( row, r ) => (
					<div role="row" className="zseo-grid__row" key={ r }>
						{ row.map( ( p, c ) => {
							const b = band( p.position, p.answered );
							return (
								<span
									role="cell"
									key={ c }
									className={ `zseo-grid__cell is-${ b }` }
									title={ ( p.top || [] ).join( ', ' ) }
									aria-label={ `${ p.position ?? '—' }: ${ words[ b ] }` }
								>
									{ p.answered ? ( p.position ?? '–' ) : '?' }
								</span>
							);
						} ) }
					</div>
				) ) }
			</div>
			<ul className="zseo-legend">
				{ Object.keys( words ).map( ( b ) => (
					<li key={ b }>
						<span
							className={ `zseo-grid__cell is-${ b }` }
							aria-hidden="true"
						/>{ ' ' }
						{ words[ b ] }
					</li>
				) ) }
			</ul>
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
export default function GridTab( { ctx } ) {
	const runs = useRuns( 'geo_grid' );
	const [ business, setBusiness ] = useState( null );
	const [ phrase, setPhrase ] = useState( '' );
	const [ size, setSize ] = useState( 5 );
	const [ spacing, setSpacing ] = useState( '1' );
	const params = business
		? {
				phrase: phrase.trim(),
				lat: business.lat,
				lng: business.lng,
				size,
				spacing_km: Number( spacing ),
				business: business.cid || business.name,
				domain: business.domain || '',
				language: ctx.market.language || 'en',
			}
		: {};
	let why = '';
	if ( ! business ) {
		why = __( 'Find your business on the map first.', 'zinn-seo' );
	} else if ( ! phrase.trim() ) {
		why = __( 'Type the search to check, such as “dentist”.', 'zinn-seo' );
	}
	const units = business ? unitsOf( 'geo_grid', params ) : 0;
	const run = runs.run;
	return (
		<div className="zseo-stack">
			<PlaceFinder
				ctx={ ctx }
				business={ business }
				onPick={ setBusiness }
				label={ __( 'Your business name', 'zinn-seo' ) }
			/>
			<div className="zseo-formrow">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'What customers search for', 'zinn-seo' ) }
					help={ __(
						'One search, such as “emergency plumber”.',
						'zinn-seo'
					) }
					value={ phrase }
					onChange={ setPhrase }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Grid', 'zinn-seo' ) }
					value={ String( size ) }
					options={ GRID_SIZES.map( ( n ) => ( {
						value: String( n ),
						label: sprintf(
							/* translators: 1: points across, 2: points in total. */
							__( '%1$s × %1$s (%2$s points)', 'zinn-seo' ),
							n,
							n * n
						),
					} ) ) }
					onChange={ ( v ) => setSize( Number( v ) ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Distance between points', 'zinn-seo' ) }
					value={ spacing }
					options={ [ '0.5', '1', '2', '5' ].map( ( km ) => ( {
						value: km,
						label: sprintf(
							/* translators: %s: a distance in kilometres. */
							__( '%s km', 'zinn-seo' ),
							km
						),
					} ) ) }
					onChange={ setSpacing }
				/>
			</div>
			<RunNow
				product="geo_grid"
				params={ params }
				units={ units }
				ctx={ ctx }
				label={ __( 'Check my map position', 'zinn-seo' ) }
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product="geo_grid"
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Check it automatically', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ run && run.result && run.result.points && (
				<>
					<h3 className="zseo-card-title">{ run.params.phrase }</h3>
					<Grid result={ run.result } />
					<RunMoney run={ run } />
				</>
			) }
		</div>
	);
}
