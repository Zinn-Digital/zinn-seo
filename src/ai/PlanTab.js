/**
 * Plan and compete: a content brief for a new page (zs-194), why a competitor's page outranks
 * yours (zs-544), and better titles for pages people see but do not click (zs-542).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	ComboboxControl,
	Notice,
	TextControl,
} from '@wordpress/components';

import { seoFetch, errorText } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { NewTab } from '../tools/parts';
import { AiFailure, PaidBy, firstFields, useRun } from './parts';

/**
 * Pick one of the site's published pages by typing its title.
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {number}   props.value    Post id.
 * @param {Function} props.onChange Called with the id.
 * @return {Element} Picker.
 */
function PagePicker( { label, value, onChange } ) {
	const [ options, setOptions ] = useState( [] );
	const search = ( text ) => {
		if ( ! text || text.length < 2 ) {
			return;
		}
		apiFetch( {
			path: `/wp/v2/search?type=post&subtype=any&per_page=10&search=${ encodeURIComponent( text ) }`,
		} )
			.then( ( rows ) =>
				setOptions(
					( rows || [] ).map( ( r ) => ( {
						value: String( r.id ),
						label: r.title || String( r.id ),
					} ) )
				)
			)
			.catch( () => {} );
	};
	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			value={ value ? String( value ) : null }
			options={ options }
			onFilterValueChange={ search }
			onChange={ ( v ) => onChange( v ? Number( v ) : 0 ) }
		/>
	);
}

/**
 * The content brief.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function BriefCard( { data } ) {
	const ai = data.ai;
	const [ topic, setTopic ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ error, setError ] = useState( '' );
	const run = useRun();
	const brief = firstFields( run.result );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h2 className="zseo-card-title">
						{ __( 'Plan a page that can rank', 'zinn-seo' ) }
					</h2>
					<GuideLink data={ data } id="ai-brief" />
				</div>
				<p className="zseo-lead">
					{ __(
						'Tell it the topic. You get the outline, the questions searchers ask and the things a complete page mentions — built on your latest keyword research when you have run one. Then start the draft in one click.',
						'zinn-seo'
					) }
				</p>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Topic of the new page', 'zinn-seo' ) }
					value={ topic }
					onChange={ setTopic }
				/>
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="primary"
						isBusy={ !! run.busy }
						disabled={ ! topic.trim() || !! run.busy }
						onClick={ () => {
							setDraft( null );
							run.run( 'brief', '/ai/brief', { topic } );
						} }
					>
						{ __( 'Write the brief', 'zinn-seo' ) }
					</Button>
					<PaidBy ai={ ai } />
				</div>
				<AiFailure
					failure={ run.failure }
					buyUrl={ ai.credits.buy_url }
					onRemove={ run.clear }
				/>
				{ brief && (
					<div className="zseo-brief">
						<h3>{ brief.title }</h3>
						<p>{ brief.intent }</p>
						{ brief.words > 0 && (
							<p className="zseo-muted">
								{ sprintf(
									/* translators: %d: a number of words. */
									__( 'About %d words', 'zinn-seo' ),
									brief.words
								) }
							</p>
						) }
						<ol>
							{ brief.outline.map( ( s ) => (
								<li key={ s.heading }>
									<strong>{ s.heading }</strong>
									<ul>
										{ s.points.map( ( p ) => (
											<li key={ p }>{ p }</li>
										) ) }
									</ul>
								</li>
							) ) }
						</ol>
						{ brief.questions.length > 0 && (
							<>
								<h4>
									{ __( 'Questions to answer', 'zinn-seo' ) }
								</h4>
								<ul>
									{ brief.questions.map( ( q ) => (
										<li key={ q }>{ q }</li>
									) ) }
								</ul>
							</>
						) }
						{ brief.mention.length > 0 && (
							<p>
								<strong>
									{ __( 'Mention:', 'zinn-seo' ) }
								</strong>{ ' ' }
								{ brief.mention.join( ', ' ) }
							</p>
						) }
						<div className="zseo-actions zseo-actions--start">
							{ draft ? (
								<Button variant="primary" href={ draft.edit }>
									{ __( 'Open the draft', 'zinn-seo' ) }
								</Button>
							) : (
								<Button
									variant="secondary"
									onClick={ () =>
										seoFetch( '/ai/brief', {
											method: 'POST',
											data: {
												topic,
												step: 'draft',
												brief,
											},
										} )
											.then( setDraft )
											.catch( ( e ) =>
												setError( errorText( e ) )
											)
									}
								>
									{ __(
										'Start a draft from this brief',
										'zinn-seo'
									) }
								</Button>
							) }
						</div>
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }
					</div>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Why a competitor outranks you.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function CompetitorCard( { data } ) {
	const ai = data.ai;
	const [ page, setPage ] = useState( 0 );
	const [ url, setUrl ] = useState( '' );
	const [ phrase, setPhrase ] = useState( '' );
	const run = useRun();
	const out = firstFields( run.result );
	const impact = {
		high: __( 'Big difference', 'zinn-seo' ),
		medium: __( 'Some difference', 'zinn-seo' ),
		low: __( 'Small difference', 'zinn-seo' ),
	};
	return (
		<Card>
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h2 className="zseo-card-title">
						{ __( 'Why does their page beat mine?', 'zinn-seo' ) }
					</h2>
					<GuideLink data={ data } id="ai-competitor" />
				</div>
				<p className="zseo-lead">
					{ __(
						"Put your page next to a competitor's that ranks above it. AI compares the two side by side — headings, depth, structured data, links — and tells you exactly what to change.",
						'zinn-seo'
					) }
				</p>
				<PagePicker
					label={ __( 'Your page', 'zinn-seo' ) }
					value={ page }
					onChange={ setPage }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="url"
					label={ __( "Their page's address", 'zinn-seo' ) }
					value={ url }
					onChange={ setUrl }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'The search you both want (optional)',
						'zinn-seo'
					) }
					value={ phrase }
					onChange={ setPhrase }
				/>
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="primary"
						isBusy={ !! run.busy }
						disabled={ ! page || ! url || !! run.busy }
						onClick={ () =>
							run.run( 'competitor', '/ai/run', {
								task: 'competitor',
								ids: [ page ],
								options: { url, phrase },
							} )
						}
					>
						{ __( 'Compare', 'zinn-seo' ) }
					</Button>
					<PaidBy ai={ ai } />
				</div>
				<AiFailure
					failure={ run.failure }
					buyUrl={ ai.credits.buy_url }
					onRemove={ run.clear }
				/>
				{ out && (
					<div className="zseo-stack">
						<p>{ out.summary }</p>
						<h3 className="zseo-card-title">
							{ __( 'Why they rank higher', 'zinn-seo' ) }
						</h3>
						<ul>
							{ out.reasons.map( ( r ) => (
								<li key={ r.point }>
									<strong>{ r.point }</strong> —{ ' ' }
									{ r.evidence }
								</li>
							) ) }
						</ul>
						<h3 className="zseo-card-title">
							{ __( 'What to change', 'zinn-seo' ) }
						</h3>
						<ul>
							{ out.fixes.map( ( f ) => (
								<li key={ f.fix }>
									{ f.fix }{ ' ' }
									<span
										className={ `zseo-impact is-${ f.impact }` }
									>
										{ impact[ f.impact ] }
									</span>
								</li>
							) ) }
						</ul>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Pages people see but do not click.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function CtrCard( { data } ) {
	const ai = data.ai;
	const [ list, setList ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ open, setOpen ] = useState( 0 );
	const [ note, setNote ] = useState( '' );
	const run = useRun();
	useEffect( () => {
		seoFetch( '/ai/ctr' )
			.then( setList )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	const options = firstFields( run.result );
	const act = ( row, option, step ) =>
		( step === 'use'
			? seoFetch( `/ai/suggestions/${ row.post_id }`, {
					method: 'POST',
					data: {
						task: 'meta',
						action: 'accept',
						fields: { title: option.title, desc: option.desc },
					},
				} )
			: seoFetch( '/ai/ctr', {
					method: 'POST',
					data: {
						post_id: row.post_id,
						step: 'test',
						title: option.title,
						desc: option.desc,
					},
				} )
		)
			.then( () =>
				setNote(
					step === 'use'
						? __(
								'The new title and description are live on the page.',
								'zinn-seo'
							)
						: __(
								'The A/B test has started. Results appear on the Search screen.',
								'zinn-seo'
							)
				)
			)
			.catch( ( e ) => setNote( errorText( e ) ) );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h2 className="zseo-card-title">
						{ __(
							'Get more clicks from the searches you already appear in',
							'zinn-seo'
						) }
					</h2>
					<GuideLink data={ data } id="ai-ctr" />
				</div>
				<p className="zseo-lead">
					{ __(
						'These pages are shown in Google often but clicked less than their position should earn. AI writes three better titles for each — use one now, or test it against the current one.',
						'zinn-seo'
					) }
				</p>
				{ error && (
					<Notice status="info" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ note && (
					<Notice status="success" onRemove={ () => setNote( '' ) }>
						{ note }
					</Notice>
				) }
				{ list && list.unavailable && (
					<Notice status="info" isDismissible={ false }>
						{ list.unavailable }
					</Notice>
				) }
				{ list && ! list.unavailable && list.pages.length === 0 && (
					<p className="zseo-muted">
						{ __(
							'No page is falling short right now. Well done.',
							'zinn-seo'
						) }
					</p>
				) }
				{ list && list.pages.length > 0 && (
					<ul className="zseo-suggs">
						{ list.pages.slice( 0, 20 ).map( ( row ) => (
							<li key={ row.post_id } className="zseo-sugg">
								<div className="zseo-sugg__head">
									<NewTab href={ row.page }>
										{ row.title }
									</NewTab>
								</div>
								<p className="zseo-muted">
									{ sprintf(
										/* translators: 1: impressions, 2: click rate, 3: expected click rate, 4: position. */
										__(
											'%1$d views in search · %2$s clicked · %3$s expected at position %4$s',
											'zinn-seo'
										),
										row.impressions,
										`${ ( row.ctr * 100 ).toFixed( 1 ) }%`,
										`${ ( row.expected * 100 ).toFixed( 1 ) }%`,
										row.position
									) }
								</p>
								<div className="zseo-actions zseo-actions--start">
									<Button
										variant="secondary"
										isBusy={
											run.busy && open === row.post_id
										}
										disabled={ !! run.busy }
										onClick={ () => {
											setOpen( row.post_id );
											run.run( 'ctr', '/ai/ctr', {
												post_id: row.post_id,
												queries: row.queries,
												impressions: row.impressions,
												ctr: row.ctr,
												position: row.position,
											} );
										} }
									>
										{ __(
											'Write better titles',
											'zinn-seo'
										) }
									</Button>
									<PaidBy ai={ ai } />
								</div>
								{ open === row.post_id && (
									<AiFailure
										failure={ run.failure }
										buyUrl={ ai.credits.buy_url }
										onRemove={ run.clear }
									/>
								) }
								{ open === row.post_id && options && (
									<ol className="zseo-options">
										{ options.options.map( ( o ) => (
											<li key={ o.title }>
												<strong>{ o.title }</strong>
												<span>{ o.desc }</span>
												<em>{ o.angle }</em>
												<span className="zseo-actions zseo-actions--start">
													<Button
														variant="primary"
														size="compact"
														onClick={ () =>
															act( row, o, 'use' )
														}
													>
														{ __(
															'Use now',
															'zinn-seo'
														) }
													</Button>
													<Button
														variant="secondary"
														size="compact"
														onClick={ () =>
															act(
																row,
																o,
																'test'
															)
														}
													>
														{ __(
															'A/B test it',
															'zinn-seo'
														) }
													</Button>
												</span>
											</li>
										) ) }
									</ol>
								) }
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function PlanTab( { data } ) {
	return (
		<div className="zseo-stack">
			<BriefCard data={ data } />
			<CompetitorCard data={ data } />
			<CtrCard data={ data } />
		</div>
	);
}
