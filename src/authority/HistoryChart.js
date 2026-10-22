/**
 * A score's history for the site and its competitors: plain SVG (no chart library), drawn only on
 * this plugin's screen. The site itself is the solid line; each competitor a dashed one.
 */
import { __, sprintf } from '@wordpress/i18n';

import { markXs } from '../metrics/markers';
import { day } from '../tools/pure';
import { frameOf, runs, score } from './pure';

const W = 600;
const H = 160;

/**
 * @param {Object} props
 * @param {Object} props.series  `{ domain: [ { date, value } ] }`.
 * @param {string} props.own     The site's own domain.
 * @param {string} props.label   What the chart shows (its accessible name).
 * @param {Object} props.markers Optional `{changes, alerts}`: days of SEO changes and alerts (zs-651).
 * @return {Element|null} Chart.
 */
export default function HistoryChart( { series, own, label, markers } ) {
	const frame = frameOf( series );
	if ( ! frame ) {
		return null;
	}
	// The site itself first, then its competitors in name order.
	const domains = [
		...Object.keys( series ).filter( ( d ) => d === own ),
		...Object.keys( series )
			.filter( ( d ) => d !== own )
			.sort( ( a, b ) => a.localeCompare( b ) ),
	];
	const mine = ( series[ own ] || [] ).filter( ( p ) => p.value !== null );
	const last = mine.length ? mine[ mine.length - 1 ] : null;
	const summary = last
		? sprintf(
				/* translators: 1: a score, 2: a date. */
				__( 'Now %1$s (checked %2$s)', 'zinn-seo' ),
				score( last.value ),
				day( last.date )
			)
		: __( 'No reading for this site yet', 'zinn-seo' );
	return (
		<figure className="zseo-chart zseo-authority-chart">
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				preserveAspectRatio="none"
				role="img"
				aria-label={ `${ label }: ${ summary }` }
				className="zseo-chart__svg"
			>
				{ markXs( markers, frame, W ).map( ( m ) => (
					<line
						key={ `${ m.kind }-${ m.date }-${ m.x }` }
						x1={ m.x }
						x2={ m.x }
						y1={ 0 }
						y2={ H }
						className={ `zseo-chart__mark is-${ m.kind }` }
						vectorEffect="non-scaling-stroke"
					>
						<title>{ `${ day( m.date ) }: ${
							m.kind === 'alert'
								? m.label
								: sprintf(
										/* translators: %s: a number of changes. */
										__( '%s SEO changes', 'zinn-seo' ),
										m.label
									)
						}` }</title>
					</line>
				) ) }
				{ domains.map( ( domain, i ) =>
					runs( series[ domain ], { ...frame, w: W, h: H - 4 } ).map(
						( pts, j ) => (
							<polyline
								key={ `${ domain }-${ j }` }
								points={ pts }
								className={
									domain === own
										? 'zseo-chart__clicks'
										: `zseo-authority-chart__rival is-${
												i % 4
											}`
								}
								vectorEffect="non-scaling-stroke"
							/>
						)
					)
				) }
			</svg>
			<figcaption className="zseo-chart__legend">
				{ domains.map( ( domain, i ) => (
					<span
						key={ domain }
						className={
							domain === own
								? 'zseo-chart__key is-clicks'
								: `zseo-chart__key zseo-authority-chart__key is-${
										i % 4
									}`
						}
					>
						<bdi>{ domain }</bdi>
					</span>
				) ) }
				{ markXs( markers, frame, W ).length > 0 && (
					<>
						<span className="zseo-chart__key is-change">
							{ __( 'SEO changes', 'zinn-seo' ) }
						</span>
						<span className="zseo-chart__key is-alert">
							{ __( 'Alerts', 'zinn-seo' ) }
						</span>
					</>
				) }
				<span className="zseo-muted">{ summary }</span>
			</figcaption>
		</figure>
	);
}
