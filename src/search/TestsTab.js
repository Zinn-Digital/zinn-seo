/**
 * Pro: title and description tests on real Google clicks, the winner kept for you (zs-410).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SearchControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { Loaded, Refusal, Table, useAction, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';
import { NeedsGoogle } from './OverviewTab';
import { confidence, pct, testProgress, verdict } from './pure';

/**
 * A phase length in words.
 *
 * @param {number} hours Hours.
 * @return {string} Text.
 */
function lengthText( hours ) {
	if ( hours === 1 ) {
		return __(
			'1 hour (to see a test run; too short to judge)',
			'zinn-seo'
		);
	}
	if ( hours < 24 ) {
		/* translators: %d: number of hours. */
		return sprintf( _n( '%d hour', '%d hours', hours, 'zinn-seo' ), hours );
	}
	const d = hours / 24;
	/* translators: %d: number of days. */
	return sprintf( _n( '%d day', '%d days', d, 'zinn-seo' ), d );
}

/**
 * The form that starts a test.
 *
 * @param {Object}   props
 * @param {Array}    props.lengths Phase lengths offered (hours).
 * @param {Function} props.onDone  Called with the new listing.
 * @return {Element} Card.
 */
function NewTest( { lengths, onDone } ) {
	const act = useAction();
	const [ search, setSearch ] = useState( '' );
	const found = useLoad(
		`/search/tests/pages?search=${ encodeURIComponent( search ) }`
	);
	const [ pages, setPages ] = useState( [] );
	const [ hours, setHours ] = useState( 168 );
	const [ phases, setPhases ] = useState( 4 );
	const [ keep, setKeep ] = useState( true );
	const [ name, setName ] = useState( '' );
	const add = ( p ) => {
		if ( ! pages.some( ( x ) => x.post_id === p.post_id ) ) {
			setPages( [
				...pages,
				{ ...p, name: p.title, title: p.seo, desc: '' },
			] );
		}
	};
	const set = ( id, field, value ) =>
		setPages(
			pages.map( ( p ) =>
				p.post_id === id ? { ...p, [ field ]: value } : p
			)
		);
	const start = () =>
		act.run( 'start', () =>
			seoFetch( '/search/tests', {
				method: 'POST',
				data: {
					name,
					hours,
					phases,
					keep,
					pages: pages.map( ( p ) => ( {
						post_id: p.post_id,
						title: p.title !== p.seo ? p.title : '',
						desc: p.desc,
					} ) ),
				},
			} ).then( ( r ) => {
				setPages( [] );
				onDone( r );
			} )
		);
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h3 className="zseo-card-title">
					{ __( 'Start a test', 'zinn-seo' ) }
				</h3>
				<p className="zseo-small zseo-muted">
					{ __(
						'The page switches between its current wording (A) and your new one (B) in equal turns, so a busy week counts for both. Google shows a new title after it next reads the page — usually within a day for busy pages — so a week per turn gives the clearest answer.',
						'zinn-seo'
					) }
				</p>
				<TextControl
					label={ __( 'Name (for you)', 'zinn-seo' ) }
					value={ name }
					onChange={ setName }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SearchControl
					label={ __( 'Find a page', 'zinn-seo' ) }
					value={ search }
					onChange={ setSearch }
					__nextHasNoMarginBottom
				/>
				<Loaded load={ found }>
					{ ( list ) => (
						<ul className="zseo-log">
							{ list.slice( 0, 8 ).map( ( p ) => (
								<li key={ p.post_id }>
									<span>{ p.title }</span>
									<Button
										variant="link"
										onClick={ () => add( p ) }
										disabled={ pages.some(
											( x ) => x.post_id === p.post_id
										) }
									>
										{ __( 'Add to the test', 'zinn-seo' ) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</Loaded>
				{ pages.map( ( p ) => (
					<fieldset key={ p.post_id } className="zseo-variant">
						<legend>{ p.name }</legend>
						<p className="zseo-small zseo-muted">
							{ __( 'A (now):', 'zinn-seo' ) } { p.seo }
						</p>
						<TextControl
							label={ __( 'B: the new SEO title', 'zinn-seo' ) }
							value={ p.title }
							onChange={ ( v ) => set( p.post_id, 'title', v ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __(
								'B: a new meta description (optional)',
								'zinn-seo'
							) }
							value={ p.desc }
							onChange={ ( v ) => set( p.post_id, 'desc', v ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								setPages(
									pages.filter(
										( x ) => x.post_id !== p.post_id
									)
								)
							}
						>
							{ __( 'Remove', 'zinn-seo' ) }
						</Button>
					</fieldset>
				) ) }
				<div className="zseo-row zseo-row--top">
					<SelectControl
						label={ __( 'Each turn lasts', 'zinn-seo' ) }
						value={ String( hours ) }
						options={ lengths.map( ( h ) => ( {
							value: String( h ),
							label: lengthText( h ),
						} ) ) }
						onChange={ ( v ) => setHours( Number( v ) ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Turns', 'zinn-seo' ) }
						value={ String( phases ) }
						options={ [ 2, 4, 6, 8, 12 ].map( ( n ) => ( {
							value: String( n ),
							label: count( n ),
						} ) ) }
						onChange={ ( v ) => setPhases( Number( v ) ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<ToggleControl
					label={ __( 'Keep the winner automatically', 'zinn-seo' ) }
					help={ __(
						'When B wins clearly it stays; otherwise the current wording comes back.',
						'zinn-seo'
					) }
					checked={ keep }
					onChange={ setKeep }
					__nextHasNoMarginBottom
				/>
				<div>
					<Button
						variant="primary"
						disabled={ pages.length === 0 || !! act.busy }
						isBusy={ act.busy === 'start' }
						onClick={ start }
					>
						{ __( 'Start the test', 'zinn-seo' ) }
					</Button>
				</div>
				<Refusal error={ act.error } onRemove={ act.clear } />
			</CardBody>
		</Card>
	);
}

/**
 * One test.
 *
 * @param {Object}   props
 * @param {Object}   props.test   Test.
 * @param {number}   props.now    Seconds since 1970.
 * @param {number}   props.min    Impressions each side needs.
 * @param {Function} props.onDone Called with the new listing.
 * @return {Element} Card.
 */
function TestCard( { test, now, min, onDone } ) {
	const act = useAction();
	const prog = testProgress( test, now );
	const res = test.result;
	const v = res ? verdict( res.stat ) : '';
	const stop = ( keep ) =>
		act.run( 'stop', () =>
			seoFetch( `/search/tests/${ test.id }/stop`, {
				method: 'POST',
				data: { keep },
			} ).then( onDone )
		);
	return (
		<Card>
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h3 className="zseo-card-title">
						{ test.name ||
							test.pages.map( ( p ) => p.title ).join( ', ' ) }
					</h3>
					<span className={ `zseo-badge is-${ test.status }` }>
						{ {
							running: __( 'Running', 'zinn-seo' ),
							measuring: __(
								'Waiting for Google’s figures',
								'zinn-seo'
							),
							done: __( 'Finished', 'zinn-seo' ),
							stopped: __( 'Stopped', 'zinn-seo' ),
						}[ test.status ] || test.status }
					</span>
				</div>
				{ test.status === 'running' && (
					<p>
						{ sprintf(
							/* translators: 1: round number, 2: rounds, 3: A or B, 4: date and time of the next switch. */
							__(
								'Round %1$s of %2$s: wording %3$s is showing now. It switches to the other wording on %4$s.',
								'zinn-seo'
							),
							count( prog.index ),
							count( prog.total ),
							prog.variant,
							day( now + prog.left, true )
						) }
					</p>
				) }
				<Table
					label={ __( 'Pages in the test', 'zinn-seo' ) }
					rowKey={ ( p ) => p.post_id }
					rows={ test.pages }
					columns={ [
						{
							id: 'p',
							label: __( 'Page', 'zinn-seo' ),
							render: ( p ) => p.title,
						},
						{
							id: 'a',
							label: __( 'A', 'zinn-seo' ),
							render: ( p ) =>
								p.A.title ||
								p.A.desc ||
								__( '(set by the template)', 'zinn-seo' ),
						},
						{
							id: 'b',
							label: __( 'B', 'zinn-seo' ),
							render: ( p ) => p.B.title || p.B.desc,
						},
					] }
				/>
				{ res && (
					<>
						<dl className="zseo-stats">
							{ [ 'A', 'B' ].map( ( k ) => (
								<div key={ k } className="zseo-stats__item">
									<dt>
										{ sprintf(
											/* translators: %s: A or B. */ __(
												'Wording %s',
												'zinn-seo'
											),
											k
										) }
									</dt>
									<dd>
										{ pct(
											k === 'A'
												? res.stat.ctr_a
												: res.stat.ctr_b
										) }
										<span className="zseo-small zseo-muted">
											{ sprintf(
												/* translators: 1: clicks, 2: impressions. */
												__(
													'%1$s clicks of %2$s impressions',
													'zinn-seo'
												),
												count( res.tally[ k ].clicks ),
												count(
													res.tally[ k ].impressions
												)
											) }
										</span>
									</dd>
								</div>
							) ) }
						</dl>
						<Notice
							status={ v === 'winner-b' ? 'success' : 'info' }
							isDismissible={ false }
						>
							{ v === 'winner-b' &&
								sprintf(
									/* translators: 1: percentage, 2: confidence. */
									__(
										'B won: %1$s more clicks per impression, %2$s%% sure. It is now the page’s wording.',
										'zinn-seo'
									),
									pct( res.stat.lift ),
									confidence( res.stat.p )
								) }
							{ v === 'winner-a' &&
								__(
									'The current wording did better. It stays.',
									'zinn-seo'
								) }
							{ v === 'no-difference' &&
								sprintf(
									/* translators: %s: confidence. */
									__(
										'No clear difference (only %s%% sure). The current wording stays — a test never leaves a page worse off by chance.',
										'zinn-seo'
									),
									confidence( res.stat.p )
								) }
							{ v === 'not-enough' &&
								sprintf(
									/* translators: %s: impressions needed. */
									__(
										'Not enough searches to tell (each side needs %s impressions). The current wording stays. Try a longer test or a busier page.',
										'zinn-seo'
									),
									count( min )
								) }
						</Notice>
						<p className="zseo-small zseo-muted">
							{ sprintf(
								/* translators: 1: date, 2: A or B. */
								__( 'Judged %1$s. Kept: %2$s.', 'zinn-seo' ),
								day( res.judged, true ),
								test.kept
							) }
						</p>
					</>
				) }
				{ [ 'running', 'measuring' ].includes( test.status ) && (
					<div className="zseo-row">
						<Button
							variant="secondary"
							disabled={ !! act.busy }
							onClick={ () => stop( 'A' ) }
						>
							{ __( 'Stop and keep A', 'zinn-seo' ) }
						</Button>
						<Button
							variant="tertiary"
							disabled={ !! act.busy }
							onClick={ () => stop( 'B' ) }
						>
							{ __( 'Stop and keep B', 'zinn-seo' ) }
						</Button>
					</div>
				) }
				<Refusal error={ act.error } onRemove={ act.clear } />
			</CardBody>
		</Card>
	);
}

/**
 * The tab.
 *
 * @param {Object}  props
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export default function TestsTab( { google, ready } ) {
	const load = useLoad( ready ? '/search/tests' : null );
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	return (
		<Loaded load={ load }>
			{ ( l ) => (
				<div className="zseo-stack">
					<NewTest
						lengths={ l.lengths }
						onDone={ ( r ) => load.setData( r ) }
					/>
					{ l.tests.length === 0 ? (
						<p className="zseo-muted">
							{ __( 'No test yet.', 'zinn-seo' ) }
						</p>
					) : (
						l.tests.map( ( t ) => (
							<TestCard
								key={ t.id }
								test={ t }
								now={ l.now }
								min={ l.min }
								onDone={ ( r ) => load.setData( r ) }
							/>
						) )
					) }
				</div>
			) }
		</Loaded>
	);
}
