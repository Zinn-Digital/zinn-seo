/**
 * Fill with AI (zs-765): FAQ, how-to steps, product details and article details written by AI
 * from each page across the whole site, checked against schema.org and Google's rules, reviewed,
 * then used as one batch that undoes in one click.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { money } from '../../tools/pure';
import { AiFailure, failureOf, PaidBy, SetUp } from '../../ai/parts';
import { JobPanel } from '../../ai/WriteTab';
import { costLine, googleNote, KINDS, recordLines } from './fill-pure';
import { confirmBulk } from '../bulk/confirmBulk';

/**
 * One suggestion in the review.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Review row.
 * @param {string}   props.kind     Kind.
 * @param {boolean}  props.selected Selected.
 * @param {Function} props.onSelect Select or not.
 * @param {Function} props.onUse    Use it.
 * @param {Function} props.onSkip   Throw it away.
 * @param {boolean}  props.busy     A request is running.
 * @return {Element} The row.
 */
function Suggestion( { item, kind, selected, onSelect, onUse, onSkip, busy } ) {
	const check = item.check || {};
	const lines = recordLines( kind, item.record || {} );
	const empty = check.empty || 0 === lines.length;
	return (
		<li className="zseo-fill__item">
			<div className="zseo-fill__head">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ item.title || __( '(no title)', 'zinn-seo' ) }
					checked={ selected }
					disabled={ empty || ! check.ok }
					onChange={ onSelect }
				/>
				<a href={ item.url } target="_blank" rel="noreferrer">
					{ __( 'View page', 'zinn-seo' ) }
					<span className="screen-reader-text">
						{ ' ' }
						{ __( '(opens in a new tab)', 'zinn-seo' ) }
					</span>
				</a>
			</div>
			{ empty ? (
				<p className="zseo-muted">
					{ __(
						'The AI found nothing to add for this page. Skip it.',
						'zinn-seo'
					) }
				</p>
			) : (
				<dl className="zseo-fill__record">
					{ lines.map( ( [ term, value ], i ) => (
						<div key={ i } className="zseo-fill__line">
							<dt>{ term }</dt>
							<dd>{ value }</dd>
						</div>
					) ) }
				</dl>
			) }
			{ ( check.errors || [] ).length > 0 && (
				<Notice status="error" isDismissible={ false }>
					{ check.errors.join( ' ' ) }
				</Notice>
			) }
			{ ( check.warnings || [] ).length > 0 && (
				<ul className="zseo-fill__warnings">
					{ check.warnings.map( ( w, i ) => (
						<li key={ i }>{ w }</li>
					) ) }
				</ul>
			) }
			{ check.ok && ! empty && (
				<p className="zseo-fill__ok">
					{ __(
						'Checked: valid schema.org, nothing Google would reject.',
						'zinn-seo'
					) }
				</p>
			) }
			<div className="zseo-actions zseo-actions--start">
				<Button
					variant="secondary"
					disabled={ busy || empty || ! check.ok }
					onClick={ onUse }
				>
					{ __( 'Use', 'zinn-seo' ) }
				</Button>
				<Button variant="tertiary" disabled={ busy } onClick={ onSkip }>
					{ __( 'Skip', 'zinn-seo' ) }
				</Button>
			</div>
		</li>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The tab.
 */
export default function FillTab( { data } ) {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ kind, setKind ] = useState( 'faq' );
	const [ picked, setPicked ] = useState( null );
	const [ missing, setMissing ] = useState( true );
	const [ price, setPrice ] = useState( null );
	const [ failure, setFailure ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ review, setReview ] = useState( { items: [], next: 0, total: 0 } );
	const [ selected, setSelected ] = useState( [] );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback(
		() =>
			seoFetch( '/schema/fill' )
				.then( setState )
				.catch( ( e ) => setError( errorText( e ) ) ),
		[]
	);
	const loadReview = useCallback(
		( after = 0 ) =>
			seoFetch(
				`/schema/fill/review?kind=${ kind }&after=${ after }&per_page=20`
			)
				.then( ( r ) =>
					setReview( ( old ) =>
						after
							? { ...r, items: [ ...old.items, ...r.items ] }
							: r
					)
				)
				.catch( ( e ) => setError( errorText( e ) ) ),
		[ kind ]
	);
	useEffect( () => {
		load();
	}, [ load ] );
	useEffect( () => {
		setSelected( [] );
		setPrice( null );
		setPicked( null );
		loadReview( 0 );
	}, [ kind, loadReview ] );

	const jobs = ( state && state.jobs ) || {};
	const running = Object.values( jobs ).some(
		( j ) => j && j.status === 'pending'
	);
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		const timer = window.setInterval( () => {
			load();
			loadReview( 0 );
		}, 3000 );
		return () => window.clearInterval( timer );
	}, [ running, load, loadReview ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! state ) {
		return <Spinner />;
	}
	const ai = state.ai;
	if ( ! state.available || ! ai ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Switch on AI writing help under Settings to fill structured data with AI.',
					'zinn-seo'
				) }
			</Notice>
		);
	}
	const info = state.kinds[ kind ] || { types: [], waiting: 0 };
	const types = picked || info.types.map( ( t ) => t.name );
	const scope = { kind, types, missing };
	const generation = jobs[ 'schema-fill' ];
	const applying = jobs[ 'schema-fill-apply' ];

	const call = ( name, path, body ) => {
		setBusy( name );
		setFailure( null );
		setNotice( null );
		return seoFetch( path, { method: 'POST', data: body } )
			.then( ( r ) => {
				setBusy( '' );
				return r;
			} )
			.catch( ( e ) => {
				setBusy( '' );
				setFailure( failureOf( e ) );
				return null;
			} );
	};
	const undo = ( batch ) =>
		seoFetch( '/bulk/undo', { method: 'POST', data: { batch } } )
			.then( ( r ) => {
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: 1: fields restored, 2: fields changed since and left alone. */
						__(
							'Undone: %1$d pages put back, %2$d changed since and left as they are.',
							'zinn-seo'
						),
						r.restored || 0,
						r.kept || 0
					),
				} );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	const used = ( r ) => {
		if ( ! r ) {
			return;
		}
		setSelected( [] );
		if ( r.job ) {
			setNotice( {
				status: 'info',
				text: __(
					'Using them in the background. You can undo the whole batch when it finishes.',
					'zinn-seo'
				),
			} );
		} else {
			setNotice( {
				status: r.failed && r.failed.length ? 'warning' : 'success',
				text:
					sprintf(
						/* translators: %d: number of pages. */
						_n(
							'Added to %d page.',
							'Added to %d pages.',
							r.used,
							'zinn-seo'
						),
						r.used
					) +
					( r.failed && r.failed.length
						? ' ' +
							r.failed
								.map( ( f ) => `#${ f.id }: ${ f.message }` )
								.join( ' ' )
						: '' ),
				batch: r.batch,
			} );
		}
		load();
		loadReview( 0 );
	};
	const ids = ( list ) => ( { kind, ids: list } );
	const control = ( id, action ) =>
		seoFetch( '/schema/fill/control', {
			method: 'POST',
			data: { job: id, action },
		} )
			.then( () => load() )
			.catch( ( e ) => setFailure( failureOf( e ) ) );

	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __(
								'Fill structured data across your site with AI',
								'zinn-seo'
							) }
						</h2>
						<GuideLink data={ data } id="schema-fill" />
					</div>
					<p className="zseo-lead">
						{ __(
							'FAQs, how-to steps, product details and article summaries written from each page’s own text, for thousands of pages at once. Every one is checked against schema.org and Google’s rules and waits for you to review it — nothing changes on your site until you say so, and one click undoes it.',
							'zinn-seo'
						) }
					</p>
					{ ! ai.ready ? (
						<SetUp ai={ ai } />
					) : (
						<div className="zseo-stack">
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'What to fill', 'zinn-seo' ) }
								value={ kind }
								options={ KINDS.filter(
									( k ) =>
										'product' !== k ||
										( state.kinds.product.types || [] )
											.length > 0
								).map( ( k ) => ( {
									value: k,
									label: state.kinds[ k ].label,
								} ) ) }
								onChange={ setKind }
							/>
							<p className="zseo-muted">{ googleNote( kind ) }</p>
							{ info.types.length > 1 && (
								<fieldset className="zseo-types">
									<legend>
										{ __(
											'For these kinds of content',
											'zinn-seo'
										) }
									</legend>
									{ info.types.map( ( t ) => (
										<CheckboxControl
											__nextHasNoMarginBottom
											key={ t.name }
											label={ t.label }
											checked={ types.includes( t.name ) }
											onChange={ ( on ) => {
												setPicked(
													on
														? [ ...types, t.name ]
														: types.filter(
																( n ) =>
																	n !== t.name
															)
												);
												setPrice( null );
											} }
										/>
									) ) }
								</fieldset>
							) }
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Only pages that do not have it yet',
									'zinn-seo'
								) }
								checked={ missing }
								onChange={ ( v ) => {
									setMissing( v );
									setPrice( null );
								} }
							/>
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant="secondary"
									isBusy={ busy === 'count' }
									disabled={ !! busy }
									onClick={ () =>
										call( 'count', '/schema/fill', {
											...scope,
											mode: 'count',
										} ).then( ( r ) => r && setPrice( r ) )
									}
								>
									{ __(
										'Count and show the cost',
										'zinn-seo'
									) }
								</Button>
								<Button
									variant="secondary"
									isBusy={ busy === 'sample' }
									disabled={ !! busy }
									onClick={ () =>
										call( 'sample', '/schema/fill', {
											...scope,
											mode: 'sample',
											sample: 5,
										} ).then( ( r ) => {
											if ( r ) {
												loadReview( 0 );
												load();
												// The sample measured this site's own price
												// (ZH6-2): a shown cost is counted again.
												if ( price ) {
													call(
														'count',
														'/schema/fill',
														{
															...scope,
															mode: 'count',
														}
													).then(
														( c ) =>
															c && setPrice( c )
													);
												}
											}
										} )
									}
								>
									{ __( 'Try it on 5 pages', 'zinn-seo' ) }
								</Button>
								<Button
									variant="primary"
									isBusy={ busy === 'start' }
									disabled={
										!! busy ||
										( generation &&
											[ 'pending', 'paused' ].includes(
												generation.status
											) )
									}
									onClick={ () =>
										call( 'start', '/schema/fill', {
											...scope,
											mode: 'start',
										} ).then( ( r ) => r && load() )
									}
								>
									{ __( 'Fill every page', 'zinn-seo' ) }
								</Button>
								<PaidBy ai={ ai } />
							</div>
							{ price && (
								<div className="zseo-fill__price">
									<p className="zseo-count">
										{ costLine( price, money ) }
									</p>
									{ price.credits &&
										false === price.enough &&
										( price.buy_url ||
											ai.credits.buy_url ) && (
											<Notice
												status="warning"
												isDismissible={ false }
											>
												{ __(
													'Your Zinn® credit will not cover all of it. The run stops safely when it runs out and carries on by itself after a top-up.',
													'zinn-seo'
												) }{ ' ' }
												<a
													href={
														price.buy_url ||
														ai.credits.buy_url
													}
													target="_blank"
													rel="noopener noreferrer"
												>
													{ __(
														'Buy credits',
														'zinn-seo'
													) }
												</a>
											</Notice>
										) }
								</div>
							) }
							<AiFailure
								failure={ failure }
								buyUrl={ ai.credits.buy_url }
								onRemove={ () => setFailure( null ) }
							/>
							<JobPanel
								job={ generation }
								id="schema-fill"
								ai={ ai }
								onCtl={ control }
								label={
									generation && state.kinds[ generation.kind ]
										? state.kinds[ generation.kind ].label
										: ''
								}
							/>
						</div>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ sprintf(
							/* translators: %d: number of suggestions waiting. */
							_n(
								'Review: %d suggestion waiting',
								'Review: %d suggestions waiting',
								review.total || 0,
								'zinn-seo'
							),
							review.total || 0
						) }
					</h2>
					{ notice && (
						<Notice
							status={ notice.status }
							spokenMessage={ notice.text }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.text }{ ' ' }
							{ notice.batch && (
								<Button
									variant="link"
									onClick={ () => undo( notice.batch ) }
								>
									{ __( 'Undo', 'zinn-seo' ) }
								</Button>
							) }
						</Notice>
					) }
					<JobPanel
						job={ applying }
						id="schema-fill-apply"
						ai={ ai }
						onCtl={ control }
						label={ __( 'Using suggestions', 'zinn-seo' ) }
					/>
					{ applying &&
						'done' === applying.status &&
						applying.batch && (
							<p>
								<Button
									variant="secondary"
									onClick={ () => undo( applying.batch ) }
								>
									{ __( 'Undo the whole batch', 'zinn-seo' ) }
								</Button>
							</p>
						) }
					{ review.total > 0 && (
						<div className="zseo-actions zseo-actions--start">
							<Button
								variant="primary"
								disabled={ !! busy || ! selected.length }
								isBusy={ busy === 'use' }
								onClick={ () =>
									confirmBulk( selected.length ) &&
									call(
										'use',
										'/schema/fill/apply',
										ids( selected )
									).then( used )
								}
							>
								{ selected.length
									? sprintf(
											/* translators: %d: number of selected pages. */
											__(
												'Use the %d selected',
												'zinn-seo'
											),
											selected.length
										)
									: __( 'Use the selected', 'zinn-seo' ) }
							</Button>
							<Button
								variant="secondary"
								disabled={ !! busy }
								isBusy={ busy === 'all' }
								onClick={ () =>
									confirmBulk( review.total || 0 ) &&
									call( 'all', '/schema/fill/apply', {
										kind,
										all: true,
									} ).then( used )
								}
							>
								{ __(
									'Use every one that passes the check',
									'zinn-seo'
								) }
							</Button>
							<Button
								variant="tertiary"
								disabled={ !! busy }
								onClick={ () =>
									call( 'skipall', '/schema/fill/skip', {
										kind,
										all: true,
									} ).then( ( r ) => r && loadReview( 0 ) )
								}
							>
								{ __( 'Skip them all', 'zinn-seo' ) }
							</Button>
						</div>
					) }
					{ 0 === review.items.length ? (
						<p className="zseo-muted">
							{ __(
								'Nothing is waiting. Try it on a few pages, or fill every page, and the suggestions appear here.',
								'zinn-seo'
							) }
						</p>
					) : (
						<ul className="zseo-fill__list">
							{ review.items.map( ( item ) => (
								<Suggestion
									key={ item.id }
									item={ item }
									kind={ kind }
									busy={ !! busy }
									selected={ selected.includes( item.id ) }
									onSelect={ ( on ) =>
										setSelected(
											on
												? [ ...selected, item.id ]
												: selected.filter(
														( x ) => x !== item.id
													)
										)
									}
									onUse={ () =>
										call(
											'use',
											'/schema/fill/apply',
											ids( [ item.id ] )
										).then( used )
									}
									onSkip={ () =>
										call(
											'skip',
											'/schema/fill/skip',
											ids( [ item.id ] )
										).then( ( r ) => r && loadReview( 0 ) )
									}
								/>
							) ) }
						</ul>
					) }
					{ review.next > 0 && review.items.length < review.total && (
						<Button
							variant="secondary"
							onClick={ () => loadReview( review.next ) }
						>
							{ __( 'Load more', 'zinn-seo' ) }
						</Button>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
