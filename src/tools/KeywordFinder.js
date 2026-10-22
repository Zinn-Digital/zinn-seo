/**
 * Pro, in the editor: find the phrase worth writing this page for — in the country and language
 * it is read in — and keep one focus phrase per market.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../admin/api';
import { count, money, refusal } from './pure';
import { marketOptions, regionName } from './markets';

/**
 * The panel.
 *
 * @param {Object}   props
 * @param {Object}   props.conf   `window.zinnSeoEditor.toolsPro`.
 * @param {number}   props.postId The post.
 * @param {string}   props.phrase The page's first focus phrase (the default topic).
 * @param {Function} props.onUse  Called with a phrase to make it the focus phrase.
 * @return {Element|null} Panel.
 */
export default function KeywordFinder( { conf, postId, phrase, onUse } ) {
	const [ seed, setSeed ] = useState( '' );
	const [ country, setCountry ] = useState(
		( conf.market && conf.market.country ) || 'US'
	);
	const [ language, setLanguage ] = useState(
		( conf.market && conf.market.language ) || 'en'
	);
	const [ focus, setFocus ] = useState( { rows: [], brief: null } );
	const [ busy, setBusy ] = useState( '' );
	const [ fail, setFail ] = useState( null );
	const [ said, setSaid ] = useState( '' );
	const [ list, setList ] = useState( null );
	const [ asking, setAsking ] = useState( false );
	const timer = useRef( null );
	const before = useRef( '' );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		seoFetch( `/tools/focus/${ postId }` )
			.then( ( got ) => {
				setFocus( got );
				if ( got.market ) {
					setCountry( got.market.country );
					setLanguage( got.market.language );
				}
			} )
			.catch( () => {} );
		return () => window.clearTimeout( timer.current );
	}, [ postId ] );

	if ( ! conf ) {
		return null;
	}
	const markets = conf.markets || {};
	const topic = seed.trim() || String( phrase || '' ).trim();

	// The list is at Zinn®: read the newest finished one until it is the one just asked for.
	const wait = ( tries = 0 ) => {
		seoFetch( '/tools/keywords/results' )
			.then( ( got ) => {
				const audits = got.audits || [];
				const newest = audits[ 0 ];
				if (
					newest &&
					newest.id !== before.current &&
					'running' !== newest.status
				) {
					setList( newest );
					setBusy( '' );
					return;
				}
				if ( tries < 40 ) {
					timer.current = window.setTimeout(
						() => wait( tries + 1 ),
						5000
					);
				} else {
					setBusy( '' );
					setSaid(
						__(
							'Still working. The list will be under SEO → Rankings and checks → Keywords.',
							'zinn-seo'
						)
					);
				}
			} )
			.catch( () => setBusy( '' ) );
	};
	const find = () => {
		setAsking( false );
		setBusy( 'find' );
		setFail( null );
		setSaid( '' );
		seoFetch( '/tools/keywords/results' )
			.then( ( got ) => {
				before.current =
					got.audits && got.audits[ 0 ] ? got.audits[ 0 ].id : '';
			} )
			.catch( () => {} )
			.then( () =>
				seoFetch( '/tools/research', {
					method: 'POST',
					data: { tool: 'keywords', seed: topic, country, language },
				} )
			)
			.then( () => {
				timer.current = window.setTimeout( () => wait( 0 ), 5000 );
			} )
			.catch( ( e ) => {
				setBusy( '' );
				setFail( e );
			} );
	};
	const keep = ( text ) => {
		setBusy( 'keep' );
		seoFetch( `/tools/focus/${ postId }`, {
			method: 'PUT',
			data: { country, language, phrase: text },
		} )
			.then( ( got ) => {
				setFocus( { ...focus, rows: got.rows } );
				setBusy( '' );
			} )
			.catch( ( e ) => {
				setBusy( '' );
				setFail( e );
			} );
	};
	const track = ( text ) => {
		setBusy( `track:${ text }` );
		seoFetch( '/tools/research/track', {
			method: 'POST',
			data: { rows: [ { phrase: text, country, language } ] },
		} )
			.then( () => {
				setBusy( '' );
				setSaid(
					sprintf(
						/* translators: %s: a search phrase. */
						__( '“%s” is now tracked.', 'zinn-seo' ),
						text
					)
				);
			} )
			.catch( ( e ) => {
				setBusy( '' );
				setFail( e );
			} );
	};
	const ideas = ( list && list.summary && list.summary.ideas ) || [];
	const r = fail ? refusal( fail ) : null;
	const brief = focus.brief;

	return (
		<PanelBody
			title={ __( 'Find a phrase', 'zinn-seo' ) }
			initialOpen={ !! brief }
			className="zseo-an-group zseo-finder"
		>
			{ brief && (
				<Notice status="info" isDismissible={ false }>
					<strong>{ __( 'Your brief', 'zinn-seo' ) }</strong>{ ' ' }
					{ sprintf(
						/* translators: 1: a search phrase, 2: searches a month. */
						__(
							'Write this page for “%1$s” (%2$s searches a month).',
							'zinn-seo'
						),
						brief.keyword,
						count( brief.searches )
					) }{ ' ' }
					{ brief.their && brief.their.url && (
						<>
							{ sprintf(
								/* translators: 1: a competitor's site, 2: their position. */
								__(
									'%1$s ranks at %2$s with this page — read it, then write something more useful:',
									'zinn-seo'
								),
								brief.rival,
								count( brief.their.position )
							) }{ ' ' }
							<a
								href={ brief.their.url }
								target="_blank"
								rel="noopener noreferrer nofollow"
							>
								{ brief.their.url }
							</a>
						</>
					) }
				</Notice>
			) }
			{ ! conf.connected ? (
				<p>
					{ __(
						'See how often a phrase is searched and how hard page one is, without leaving the editor.',
						'zinn-seo'
					) }{ ' ' }
					<a href={ conf.creditsUrl }>
						{ __( 'Connect this site first', 'zinn-seo' ) }
					</a>
				</p>
			) : (
				<>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Topic', 'zinn-seo' ) }
						placeholder={ phrase || '' }
						value={ seed }
						onChange={ setSeed }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Searches in', 'zinn-seo' ) }
						value={ country }
						options={ marketOptions( markets ) }
						onChange={ ( c ) => {
							setCountry( c );
							setLanguage(
								( markets[ c ] || {} ).language || 'en'
							);
						} }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Language code', 'zinn-seo' ) }
						value={ language }
						onChange={ ( v ) =>
							setLanguage(
								v
									.toLowerCase()
									.replace( /[^a-z]/g, '' )
									.slice( 0, 3 )
							)
						}
					/>
					{ fail && (
						<Notice
							status="warning"
							onRemove={ () => setFail( null ) }
						>
							{ r.message || errorText( fail ) }{ ' ' }
							{ 'refused' === r.class && (
								<a href={ conf.creditsUrl }>
									{ __( 'See your credit', 'zinn-seo' ) }
								</a>
							) }
						</Notice>
					) }
					{ said && (
						<Notice
							status="success"
							onRemove={ () => setSaid( '' ) }
						>
							{ said }
						</Notice>
					) }
					{ conf.canSpend ? (
						<p className="zseo-finder__go">
							{ ! asking ? (
								<Button
									variant="secondary"
									disabled={ ! topic || !! busy }
									isBusy={ 'find' === busy }
									onClick={ () =>
										conf.price ? setAsking( true ) : find()
									}
								>
									{ 'find' === busy
										? __( 'Looking…', 'zinn-seo' )
										: __( 'Find phrases', 'zinn-seo' ) }
								</Button>
							) : (
								<>
									<span>
										{ sprintf(
											/* translators: %s: an amount of money. */
											__(
												'This uses %s of your credit. Run it?',
												'zinn-seo'
											),
											money( conf.price )
										) }
									</span>{ ' ' }
									<Button variant="primary" onClick={ find }>
										{ __( 'Yes, run it', 'zinn-seo' ) }
									</Button>{ ' ' }
									<Button
										variant="tertiary"
										onClick={ () => setAsking( false ) }
									>
										{ __( 'Cancel', 'zinn-seo' ) }
									</Button>
								</>
							) }
						</p>
					) : (
						<p>
							{ __(
								'Only someone allowed to spend the site’s credit can run research.',
								'zinn-seo'
							) }
						</p>
					) }
					{ 'find' === busy && <Spinner /> }
					{ list && ! ideas.length && (
						<p>
							{ __(
								'Nothing came back for that. Try a broader topic.',
								'zinn-seo'
							) }
						</p>
					) }
					{ ideas.length > 0 && (
						<ul className="zseo-finder__list">
							{ ideas.slice( 0, 25 ).map( ( i ) => (
								<li key={ i.keyword }>
									<strong>{ i.keyword }</strong>
									<span className="zseo-finder__num">
										{ sprintf(
											/* translators: 1: searches a month, 2: difficulty out of 100. */
											__(
												'%1$s searches a month · difficulty %2$s',
												'zinn-seo'
											),
											count( i.search_volume ),
											count( i.difficulty )
										) }
									</span>
									<span className="zseo-finder__do">
										{ conf.trendsUrl && (
											<a
												href={ `${ conf.trendsUrl }?phrase=${ encodeURIComponent(
													i.keyword
												) }` }
												target="_blank"
												rel="noopener noreferrer"
											>
												{ __(
													'See the trend',
													'zinn-seo'
												) }
												<span className="screen-reader-text">
													{ ' ' }
													{ __(
														'(opens in a new tab)',
														'zinn-seo'
													) }
												</span>
											</a>
										) }
										<Button
											variant="link"
											onClick={ () => onUse( i.keyword ) }
										>
											{ __(
												'Use as focus phrase',
												'zinn-seo'
											) }
										</Button>
										<Button
											variant="link"
											disabled={ !! busy }
											onClick={ () => keep( i.keyword ) }
										>
											{ sprintf(
												/* translators: %s: a country. */
												__( 'Keep for %s', 'zinn-seo' ),
												regionName( country )
											) }
										</Button>
										{ conf.canSpend && (
											<Button
												variant="link"
												disabled={ !! busy }
												isBusy={
													busy ===
													`track:${ i.keyword }`
												}
												onClick={ () =>
													track( i.keyword )
												}
											>
												{ __(
													'Track its ranking',
													'zinn-seo'
												) }
											</Button>
										) }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
			{ focus.rows.length > 0 && (
				<div className="zseo-finder__markets">
					<strong>
						{ __( 'Focus phrase by market', 'zinn-seo' ) }
					</strong>
					<ul>
						{ focus.rows.map( ( row ) => (
							<li key={ `${ row.country }|${ row.language }` }>
								<span>
									{ regionName( row.country ) } (
									{ row.language.toUpperCase() })
								</span>
								{ ': ' }
								<strong>{ row.phrase }</strong>{ ' ' }
								<Button
									variant="link"
									isDestructive
									disabled={ !! busy }
									onClick={ () => {
										setBusy( 'keep' );
										seoFetch( `/tools/focus/${ postId }`, {
											method: 'PUT',
											data: {
												country: row.country,
												language: row.language,
												phrase: '',
											},
										} )
											.then( ( got ) => {
												setFocus( {
													...focus,
													rows: got.rows,
												} );
												setBusy( '' );
											} )
											.catch( () => setBusy( '' ) );
									} }
								>
									{ __( 'Remove', 'zinn-seo' ) }
								</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }
		</PanelBody>
	);
}
