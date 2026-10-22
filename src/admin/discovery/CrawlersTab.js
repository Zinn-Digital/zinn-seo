import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import ProTeaser from '../ProTeaser';
import { num, when } from './shared';

/**
 * What each kind of crawler is for.
 *
 * @return {Object<string, {title: string, text: string}>} Kinds.
 */
export function kinds() {
	return {
		answer: {
			title: __( 'AI search and answers', 'zinn-seo' ),
			text: __(
				"Read your pages to answer people's questions, and link to you as the source. Blocking them removes you from those answers.",
				'zinn-seo'
			),
		},
		user: {
			title: __( 'Fetching for a person', 'zinn-seo' ),
			text: __(
				'Read one page because someone asked an assistant to look at it.',
				'zinn-seo'
			),
		},
		train: {
			title: __( 'Training AI models', 'zinn-seo' ),
			text: __(
				'Collect pages to train future models. Blocking them keeps your text out of training and costs you nothing in search or AI answers.',
				'zinn-seo'
			),
		},
	};
}

/**
 * Which preset a set of choices is, or `custom`.
 *
 * @param {Object<string, Array<string>>} presets Preset => blocked ids.
 * @param {Array<Object>}                 rows    Crawlers with `allowed`.
 * @return {string} Preset id.
 */
export function presetOf( presets, rows ) {
	const blocked = rows
		.filter( ( r ) => ! r.allowed )
		.map( ( r ) => r.id )
		.sort()
		.join( ',' );
	const found = Object.keys( presets ).find(
		( p ) => [ ...presets[ p ] ].sort().join( ',' ) === blocked
	);
	return found || 'custom';
}

/**
 * Thirty days of visits as small bars (an image for sighted people, the total for everyone).
 *
 * @param {Object} props
 * @param {Object} props.days Day => visits.
 * @return {Element} Bars.
 */
function Bars( { days } ) {
	const list = [];
	for ( let i = 29; i >= 0; i-- ) {
		const d = new Date( Date.now() - i * 86400000 )
			.toISOString()
			.slice( 0, 10 );
		list.push( days[ d ] || 0 );
	}
	const max = Math.max( 1, ...list );
	return (
		<span className="zseo-bars" aria-hidden="true">
			{ list.map( ( v, i ) => (
				<span
					key={ i }
					style={ {
						blockSize: `${ Math.max( 2, ( v / max ) * 24 ) }px`,
					} }
				/>
			) ) }
		</span>
	);
}

/**
 * AI crawlers: who may read the site, content signals, and (Pro) who visited.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {boolean}  props.isPro    A paid plan is active.
 * @return {Element} The tab.
 */
export default function CrawlersTab( { value, onChange, isPro } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		seoFetch( '/crawlers' )
			.then( setData )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );

	if ( ! data ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const choices = value.ai_crawlers || {};
	const rows = data.crawlers.map( ( c ) => ( {
		...c,
		allowed: c.id in choices ? !! choices[ c.id ] : true,
	} ) );
	const preset = presetOf( data.presets, rows );
	const setPreset = ( p ) => {
		if ( 'custom' === p ) {
			return;
		}
		const map = {};
		data.crawlers.forEach( ( c ) => {
			if ( data.presets[ p ].includes( c.id ) ) {
				map[ c.id ] = false;
			}
		} );
		onChange( { ai_crawlers: map } );
	};
	const setOne = ( id, allowed ) => {
		const map = { ...choices };
		if ( allowed ) {
			delete map[ id ];
		} else {
			map[ id ] = false;
		}
		onChange( { ai_crawlers: map } );
	};
	const k = kinds();

	return (
		<div className="zseo-stack">
			<PanelBody title={ __( 'Who may read your site', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Choice', 'zinn-seo' ) }
						value={ preset }
						options={ [
							{
								value: 'allow_all',
								label: __(
									'Allow every AI crawler (best for AI visibility)',
									'zinn-seo'
								),
							},
							{
								value: 'block_train',
								label: __(
									'Allow AI answers, block training',
									'zinn-seo'
								),
							},
							{
								value: 'block_all',
								label: __(
									'Block every AI crawler',
									'zinn-seo'
								),
							},
							{
								value: 'custom',
								label: __( 'My own choice, below', 'zinn-seo' ),
							},
						] }
						onChange={ setPreset }
					/>
					<p className="zseo-muted">
						{ __(
							'Your choice is written to robots.txt. The companies listed say their crawlers obey it; search engines such as Google and Bing are not affected.',
							'zinn-seo'
						) }
					</p>
					{ [ 'answer', 'user', 'train' ].map( ( kind ) => (
						<section key={ kind } className="zseo-crawlers">
							<h3 className="zseo-group__title">
								{ k[ kind ].title }
							</h3>
							<p className="zseo-muted">{ k[ kind ].text }</p>
							<ul className="zseo-crawlers__list">
								{ rows
									.filter( ( r ) => r.kind === kind )
									.map( ( r ) => (
										<li key={ r.id }>
											<ToggleControl
												__nextHasNoMarginBottom
												label={ `${ r.token } · ${ r.company }` }
												checked={ r.allowed }
												onChange={ ( on ) =>
													setOne( r.id, on )
												}
												help={
													r.allowed
														? __(
																'Allowed',
																'zinn-seo'
															)
														: __(
																'Blocked',
																'zinn-seo'
															)
												}
											/>
											{ r.docs && (
												<ExternalLink href={ r.docs }>
													{ __(
														'About this crawler',
														'zinn-seo'
													) }
												</ExternalLink>
											) }
										</li>
									) ) }
							</ul>
						</section>
					) ) }
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'How your content may be used', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<div className="zseo-stack">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'State it in robots.txt (content signals)',
							'zinn-seo'
						) }
						help={ __(
							'A line every crawler can read, saying whether your pages may be used for search, in AI answers, and to train AI. A young standard (contentsignals.org); not every company reads it yet.',
							'zinn-seo'
						) }
						checked={ !! value.ai_signals }
						onChange={ ( v ) => onChange( { ai_signals: v } ) }
					/>
					{ value.ai_signals && (
						<div className="zseo-row">
							{ [
								[ 'ai_sig_search', __( 'Search', 'zinn-seo' ) ],
								[
									'ai_sig_input',
									__( 'AI answers', 'zinn-seo' ),
								],
								[
									'ai_sig_train',
									__( 'AI training', 'zinn-seo' ),
								],
							].map( ( [ key, label ] ) => (
								<SelectControl
									key={ key }
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ label }
									value={ value[ key ] }
									options={ [
										{
											value: 'yes',
											label: __( 'Yes', 'zinn-seo' ),
										},
										{
											value: 'no',
											label: __( 'No', 'zinn-seo' ),
										},
									] }
									onChange={ ( v ) =>
										onChange( { [ key ]: v } )
									}
								/>
							) ) }
						</div>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Reserve text and data mining rights (EU)',
							'zinn-seo'
						) }
						help={ __(
							'Publishes the W3C TDM reservation file, the machine-readable opt-out EU copyright law recognises.',
							'zinn-seo'
						) }
						checked={ !! value.tdm_reservation }
						onChange={ ( v ) => onChange( { tdm_reservation: v } ) }
					/>
					{ value.tdm_reservation && (
						<>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								type="url"
								dir="ltr"
								label={ __(
									'Your licensing terms (optional)',
									'zinn-seo'
								) }
								help={ __(
									'A page explaining how to license your content.',
									'zinn-seo'
								) }
								value={ value.tdm_policy || '' }
								onChange={ ( v ) =>
									onChange( { tdm_policy: v.trim() } )
								}
							/>
							<ExternalLink href={ data.tdm_url }>
								{ data.tdm_url }
							</ExternalLink>
						</>
					) }
				</div>
			</PanelBody>
			{ isPro && data.log ? (
				<PanelBody
					title={ __(
						'AI crawler visits, last 30 days',
						'zinn-seo'
					) }
					initialOpen={ false }
				>
					<p className="zseo-muted">
						{ __(
							'Counted when WordPress answers a crawler. Pages a full-page cache serves are not counted; robots.txt, the sitemaps and llms.txt always are.',
							'zinn-seo'
						) }
					</p>
					{ Object.keys( data.hits || {} ).length === 0 ? (
						<p>
							{ __(
								'No AI crawler has visited yet.',
								'zinn-seo'
							) }
						</p>
					) : (
						<ul className="zseo-visits">
							{ rows
								.filter( ( r ) => data.hits[ r.id ] )
								.sort(
									( a, b ) =>
										data.hits[ b.id ].total -
										data.hits[ a.id ].total
								)
								.map( ( r ) => {
									const h = data.hits[ r.id ];
									return (
										<li key={ r.id }>
											<strong>{ r.token }</strong>
											<Bars days={ h.days } />
											<span>
												{ sprintf(
													/* translators: %s: a number of visits. */
													__(
														'%s visits',
														'zinn-seo'
													),
													num( h.total )
												) }
											</span>
											<span className="zseo-muted">
												{ sprintf(
													/* translators: 1: date and time, 2: a path. */
													__(
														'Last: %1$s, %2$s',
														'zinn-seo'
													),
													when(
														Date.parse(
															h.last_seen.replace(
																' ',
																'T'
															) + 'Z'
														) / 1000
													),
													h.last_path
												) }
											</span>
										</li>
									);
								} ) }
						</ul>
					) }
					<Button
						variant="tertiary"
						onClick={ () =>
							seoFetch( '/crawlers' ).then( setData )
						}
					>
						{ __( 'Refresh', 'zinn-seo' ) }
					</Button>
				</PanelBody>
			) : (
				<ProTeaser
					title={ __( 'AI crawler visits', 'zinn-seo' ) }
					points={ [
						__(
							'See which AI crawlers visit, how often each day, and what they read last.',
							'zinn-seo'
						),
						__(
							'Kept as one small counter per crawler per day: no growing log, no slowdown.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}
