/**
 * Page speed: Core Web Vitals from real visitors for the site and each template, with the cause
 * named (zs-322), and a lab test of any page (Pro, zs-411).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import ProTeaser from '../admin/ProTeaser';
import { viewHref } from '../admin-kit/router';
import { Loaded, Refusal, useAction, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';
import { pct } from './pure';

const METRICS = () => [
	[ 'lcp', __( 'Largest paint', 'zinn-seo' ), 'ms' ],
	[ 'inp', __( 'Response to taps', 'zinn-seo' ), 'ms' ],
	[ 'cls', __( 'Layout shift', 'zinn-seo' ), 'score' ],
	[ 'fcp', __( 'First paint', 'zinn-seo' ), 'ms' ],
	[ 'ttfb', __( 'Server response', 'zinn-seo' ), 'ms' ],
];

/**
 * A rating in words.
 *
 * @param {string|null} r good|needs_improvement|poor.
 * @return {string} Text.
 */
function rating( r ) {
	if ( r === 'good' ) {
		return __( 'Good', 'zinn-seo' );
	}
	if ( r === 'needs_improvement' ) {
		return __( 'Needs work', 'zinn-seo' );
	}
	if ( r === 'poor' ) {
		return __( 'Poor', 'zinn-seo' );
	}
	return '—';
}

/**
 * A metric's figure: seconds for times, the score for layout shift.
 *
 * @param {Object|undefined} m    Metric.
 * @param {string}           unit ms|score.
 * @return {string} Text.
 */
function figure( m, unit ) {
	if ( ! m || m.p75 === null ) {
		return '—';
	}
	if ( unit !== 'ms' ) {
		return m.p75.toFixed( 2 );
	}
	return sprintf(
		/* translators: %s: seconds, e.g. "2.4". */
		__( '%s s', 'zinn-seo' ),
		( m.p75 / 1000 ).toFixed( 1 )
	);
}

/**
 * One record's metrics.
 *
 * @param {Object} props
 * @param {Object} props.record Record.
 * @return {Element} List.
 */
function Metrics( { record } ) {
	return (
		<dl className="zseo-stats">
			{ METRICS().map( ( [ k, label, unit ] ) => {
				const m = ( record.metrics || {} )[ k ];
				return (
					<div
						key={ k }
						className={ `zseo-stats__item is-${ m ? m.rating : 'none' }` }
					>
						<dt>{ label }</dt>
						<dd>
							{ figure( m, unit ) }
							<span className="zseo-rating">
								{ rating( m ? m.rating : null ) }
							</span>
						</dd>
					</div>
				);
			} ) }
		</dl>
	);
}

/**
 * Causes and fixes.
 *
 * @param {Object} props
 * @param {Array}  props.causes Causes.
 * @return {Element|null} List.
 */
function Causes( { causes } ) {
	if ( ! causes || causes.length === 0 ) {
		return null;
	}
	return (
		<ul className="zseo-causes">
			{ causes.map( ( c, i ) => (
				<li key={ i }>
					<strong>{ c.cause }</strong> { c.fix }
				</li>
			) ) }
		</ul>
	);
}

/**
 * A lab test (Pro): start, then read the result as it lands.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function Lab( { data } ) {
	const act = useAction();
	const [ url, setUrl ] = useState( data.homeUrl );
	const [ check, setCheck ] = useState( null );
	useEffect( () => {
		if ( ! check || check.status !== 'queued' ) {
			return undefined;
		}
		const t = setTimeout(
			() =>
				seoFetch( `/search/speed/lab/${ check.id }` )
					.then( setCheck )
					.catch( () => setCheck( { ...check, status: 'failed' } ) ),
			8000
		);
		return () => clearTimeout( t );
	}, [ check ] );
	const r = ( check && check.result ) || {};
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h3 className="zseo-card-title">
					{ __( 'Lab test of one page', 'zinn-seo' ) }
				</h3>
				<p className="zseo-small zseo-muted">
					{ __(
						'A full Lighthouse test on our own servers, with the biggest savings named. Free when the site is hosted by Zinn Digital®; otherwise it uses a little Zinn® credit, refunded if no result comes back.',
						'zinn-seo'
					) }
				</p>
				<form
					className="zseo-row zseo-row--top"
					onSubmit={ ( e ) => {
						e.preventDefault();
						act.run( 'lab', () =>
							seoFetch( '/search/speed/lab', {
								method: 'POST',
								data: { url },
							} ).then( setCheck )
						);
					} }
				>
					<TextControl
						label={ __( 'Page', 'zinn-seo' ) }
						type="url"
						value={ url }
						onChange={ setUrl }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						type="submit"
						isBusy={
							act.busy === 'lab' ||
							( check && check.status === 'queued' )
						}
						disabled={
							!! act.busy ||
							( check && check.status === 'queued' )
						}
					>
						{ __( 'Test it', 'zinn-seo' ) }
					</Button>
				</form>
				<Refusal error={ act.error } buyUrl="" onRemove={ act.clear } />
				{ check && check.status === 'queued' && (
					<p>
						{ __(
							'Testing… this takes about a minute.',
							'zinn-seo'
						) }
					</p>
				) }
				{ check && check.status === 'failed' && (
					<p>
						{ check.error ||
							__(
								'The test could not finish. Nothing was charged.',
								'zinn-seo'
							) }
					</p>
				) }
				{ check && check.status === 'complete' && (
					<>
						<dl className="zseo-stats">
							{ [
								[
									'performance',
									__( 'Performance', 'zinn-seo' ),
								],
								[ 'seo', __( 'SEO', 'zinn-seo' ) ],
								[
									'accessibility',
									__( 'Accessibility', 'zinn-seo' ),
								],
								[
									'best_practices',
									__( 'Best practices', 'zinn-seo' ),
								],
							].map( ( [ k, label ] ) => (
								<div key={ k } className="zseo-stats__item">
									<dt>{ label }</dt>
									<dd>
										{ r[ k ] === null ||
										r[ k ] === undefined
											? '—'
											: count( r[ k ] ) }
									</dd>
								</div>
							) ) }
						</dl>
						<Causes causes={ check.causes } />
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
 * @param {Object} props.data   Boot data.
 * @param {Object} props.google Link (unused: field data needs only the Zinn® connection).
 * @return {Element} Tab.
 */
export default function SpeedTab( { data, google } ) {
	const [ form, setForm ] = useState( 'phone' );
	const load = useLoad(
		google.zinn_connected ? `/search/speed?form=${ form }` : null
	);
	if ( ! google.zinn_connected ) {
		return (
			<Card>
				<CardBody className="zseo-stack">
					<p className="zseo-lead">
						{ __(
							'Connect this site to your free Zinn® account to see how fast it really is for visitors — the speed Google ranks on.',
							'zinn-seo'
						) }
					</p>
					<div>
						<Button
							variant="primary"
							href={ viewHref( 'credits' ) }
						>
							{ __( 'Connect this site', 'zinn-seo' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		);
	}
	return (
		<div className="zseo-stack">
			<SelectControl
				label={ __( 'Visitors on', 'zinn-seo' ) }
				value={ form }
				options={ [
					{ value: 'phone', label: __( 'Phones', 'zinn-seo' ) },
					{ value: 'desktop', label: __( 'Computers', 'zinn-seo' ) },
				] }
				onChange={ setForm }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<Loaded load={ load }>
				{ ( s ) => (
					<>
						<Card>
							<CardBody className="zseo-stack">
								<div className="zseo-cardhead">
									<h3 className="zseo-card-title">
										{ __(
											'The whole site, for real visitors',
											'zinn-seo'
										) }
									</h3>
									{ s.site.overall && (
										<span
											className={ `zseo-badge is-${ s.site.overall }` }
										>
											{ s.site.overall === 'good'
												? __(
														'Passes Google’s Core Web Vitals',
														'zinn-seo'
													)
												: __(
														'Does not pass Google’s Core Web Vitals yet',
														'zinn-seo'
													) }
										</span>
									) }
								</div>
								{ s.site.has_data ? (
									<>
										<Metrics record={ s.site } />
										{ s.site.period && (
											<p className="zseo-small zseo-muted">
												{ sprintf(
													/* translators: 1: first day, 2: last day. */
													__(
														'Chrome users from %1$s to %2$s (the 75th percentile, as Google measures).',
														'zinn-seo'
													),
													day( s.site.period.from ),
													day( s.site.period.to )
												) }
											</p>
										) }
										<Causes causes={ s.causes } />
									</>
								) : (
									<p className="zseo-muted">
										{ __(
											'Google does not have enough Chrome visits to this site yet to report its real speed. A lab test (below) measures it now.',
											'zinn-seo'
										) }
									</p>
								) }
							</CardBody>
						</Card>
						<Card>
							<CardBody className="zseo-stack">
								<h3 className="zseo-card-title">
									{ __( 'By template', 'zinn-seo' ) }
								</h3>
								<p className="zseo-small zseo-muted">
									{ __(
										'Pages built on the same template share their speed: fix the template once and every page on it gets faster.',
										'zinn-seo'
									) }
								</p>
								<ul className="zseo-templates">
									{ s.templates.map( ( t ) => (
										<li
											key={ t.id }
											className="zseo-templates__item"
										>
											<div className="zseo-cardhead">
												<strong>{ t.label }</strong>
												{ t.record && (
													<span
														className={ `zseo-badge is-${ t.record.overall || 'none' }` }
													>
														{ t.from === 'page'
															? rating(
																	t.record
																		.overall
																)
															: __(
																	'Too few visits for its own figures: the site’s are shown',
																	'zinn-seo'
																) }
													</span>
												) }
											</div>
											{ t.error && <p>{ t.error }</p> }
											{ t.record && t.from === 'page' && (
												<Metrics record={ t.record } />
											) }
											{ t.from === 'page' && (
												<Causes causes={ t.causes } />
											) }
											{ t.record && t.from === 'page' && (
												<p className="zseo-small zseo-muted">
													{ sprintf(
														/* translators: %s: a share, e.g. "82%". */
														__(
															'%s of visits had a good largest paint.',
															'zinn-seo'
														),
														pct(
															(
																(
																	t.record
																		.metrics ||
																	{}
																).lcp || {}
															).good || 0,
															0
														)
													) }
												</p>
											) }
										</li>
									) ) }
								</ul>
							</CardBody>
						</Card>
						{ s.pro ? (
							<Lab data={ data } />
						) : (
							<ProTeaser
								title={ __(
									'Test any page in the lab',
									'zinn-seo'
								) }
								points={ [
									__(
										'A full Lighthouse test of any page, with the biggest savings named.',
										'zinn-seo'
									),
									__( 'Free on Zinn® hosting.', 'zinn-seo' ),
								] }
							/>
						) }
					</>
				) }
			</Loaded>
		</div>
	);
}
