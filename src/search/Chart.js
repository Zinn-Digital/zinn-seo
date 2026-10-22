/**
 * A small line chart: clicks (and optionally position) day by day, with the days an SEO change
 * was made marked. Drawn as plain SVG — no chart library — and only on this plugin's screen.
 */
import { __, sprintf } from '@wordpress/i18n';

import { count, day } from '../tools/pure';
import { points } from './pure';

const W = 600;
const H = 140;

/**
 * @param {Object}        props
 * @param {Array<Object>} props.days     `{ date, clicks, position, changes? }`.
 * @param {boolean}       props.position Draw the position line too.
 * @param {string}        props.label    What the chart shows (its accessible name).
 * @return {Element|null} Chart.
 */
export default function Chart( { days, position = false, label } ) {
	if ( ! days || days.length < 2 ) {
		return null;
	}
	const clicks = days.map( ( d ) => d.clicks || 0 );
	const pos = days.map( ( d ) => d.position || 0 );
	const total = clicks.reduce( ( a, b ) => a + b, 0 );
	const marks = days
		.map( ( d, i ) => ( { d, i } ) )
		.filter( ( { d } ) => ( d.changes || [] ).length > 0 );
	const summary = sprintf(
		/* translators: 1: number of clicks, 2: first day, 3: last day. */
		__( '%1$s clicks from %2$s to %3$s', 'zinn-seo' ),
		count( total ),
		day( days[ 0 ].date ),
		day( days[ days.length - 1 ].date )
	);
	return (
		<figure className="zseo-chart">
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				preserveAspectRatio="none"
				role="img"
				aria-label={ `${ label }: ${ summary }` }
				className="zseo-chart__svg"
			>
				{ marks.map( ( { d, i } ) => {
					const x = ( i / ( days.length - 1 ) ) * W;
					return (
						<line
							key={ d.date }
							x1={ x }
							x2={ x }
							y1="0"
							y2={ H }
							className="zseo-chart__mark"
						>
							<title>
								{ `${ day( d.date ) }: ${ d.changes
									.map(
										( c ) => `${ c.label } — ${ c.title }`
									)
									.join( '; ' ) }` }
							</title>
						</line>
					);
				} ) }
				<polyline
					points={ points( clicks, W, H - 4 ) }
					className="zseo-chart__clicks"
					vectorEffect="non-scaling-stroke"
				/>
				{ position && (
					<polyline
						points={ points( pos, W, H - 4, true ) }
						className="zseo-chart__position"
						vectorEffect="non-scaling-stroke"
					/>
				) }
			</svg>
			<figcaption className="zseo-chart__legend">
				<span className="zseo-chart__key is-clicks">
					{ __( 'Clicks', 'zinn-seo' ) }
				</span>
				{ position && (
					<span className="zseo-chart__key is-position">
						{ __( 'Position (higher is better)', 'zinn-seo' ) }
					</span>
				) }
				{ marks.length > 0 && (
					<span className="zseo-chart__key is-mark">
						{ __( 'An SEO change was made', 'zinn-seo' ) }
					</span>
				) }
				<span className="zseo-muted">{ summary }</span>
			</figcaption>
		</figure>
	);
}
