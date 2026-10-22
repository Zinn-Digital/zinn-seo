/**
 * The live content optimiser (zs-766): in the block editor's sidebar, the classic editor's box and
 * Elementor's SEO panel, a score for how well the post covers what the top 10 results for its
 * focus phrase cover — the words, names, questions and headings — with what to add, what is used
 * too often and the competing pages' lengths and headings.
 *
 * The top 10 are read ONCE per phrase, country and language, on a click that shows the price
 * first, paid from Zinn® credits, and kept on the site for every post and every writer. The score
 * is worked out here, in the browser, a moment after typing stops: nothing is sent per keystroke.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
} from '@wordpress/components';

import { draftFromHtml, lists, score } from './pure';
import { money } from '../../tools/pure';
import { marketOptions } from '../../tools/markets';
import './optimiser.scss';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';
const conf = boot.optimiser || {};

/** Languages a search can be read in: the 58 the plugin is translated into. */
const LANGUAGES = [
	'am',
	'ar',
	'az',
	'bg',
	'bn',
	'cs',
	'de',
	'el',
	'en',
	'es',
	'fa',
	'fr',
	'gu',
	'ha',
	'he',
	'hi',
	'hr',
	'hu',
	'hy',
	'id',
	'it',
	'ja',
	'ka',
	'kk',
	'km',
	'kn',
	'ko',
	'lo',
	'ml',
	'mn',
	'mr',
	'ms',
	'my',
	'ne',
	'nl',
	'pa',
	'pl',
	'ps',
	'pt',
	'ro',
	'ru',
	'si',
	'so',
	'sq',
	'sr',
	'sw',
	'ta',
	'te',
	'tg',
	'th',
	'tl',
	'tr',
	'uk',
	'ur',
	'uz',
	'vi',
	'yo',
	'zh',
];

/**
 * A language's name in the reader's language.
 *
 * @param {string} code Code.
 * @return {string} Name.
 */
function languageName( code ) {
	try {
		const names = new Intl.DisplayNames(
			[ ( document.documentElement.lang || 'en' ).replace( '_', '-' ) ],
			{ type: 'language' }
		);
		return names.of( code ) || code;
	} catch {
		return code;
	}
}

/**
 * A read's price: whole cents as cents ($0.05), anything finer to the hundredth of a cent.
 *
 * @param {number} micros Millionths of a US dollar.
 * @return {string} Money.
 */
export function priceText( micros ) {
	if ( micros % 10000 !== 0 ) {
		return money( micros );
	}
	try {
		return new Intl.NumberFormat(
			( document.documentElement.lang || 'en' ).replace( '_', '-' ),
			{ style: 'currency', currency: 'USD' }
		).format( micros / 1000000 );
	} catch {
		return `$${ ( micros / 1000000 ).toFixed( 2 ) }`;
	}
}

/**
 * The score as a ring.
 *
 * @param {Object}      props
 * @param {number|null} props.value Score.
 * @return {Element} Ring.
 */
function Ring( { value } ) {
	let level = 'none';
	if ( value !== null && value !== undefined ) {
		level = 'bad';
		if ( value >= 70 ) {
			level = 'good';
		} else if ( value >= 45 ) {
			level = 'ok';
		}
	}
	return (
		<div
			className={ `zseo-opt-ring is-${ level }` }
			style={ { '--zseo-opt': `${ value || 0 }` } }
			role="img"
			aria-label={
				value === null || value === undefined
					? __( 'Content score: not measured yet', 'zinn-seo' )
					: sprintf(
							/* translators: %d: a score out of 100. */
							__( 'Content score: %d out of 100', 'zinn-seo' ),
							value
						)
			}
		>
			<span aria-hidden="true">
				{ value === null || value === undefined ? '–' : value }
			</span>
		</div>
	);
}

/**
 * One term row.
 *
 * @param {Object} props
 * @param {Object} props.t Term result.
 * @return {Element} Row.
 */
function TermRow( { t } ) {
	return (
		<li className={ `zseo-opt-term is-${ t.status }` }>
			<span className="zseo-opt-term__text">{ t.term }</span>
			<span className="zseo-opt-term__count">
				{ sprintf(
					/* translators: 1: times used, 2: low end of the range, 3: high end. */
					__( '%1$d / %2$d–%3$d', 'zinn-seo' ),
					t.count,
					t.low,
					t.high
				) }
			</span>
		</li>
	);
}

/**
 * The section's lists, one tab at a time.
 *
 * @param {Object} props
 * @param {Object} props.result A score().
 * @param {Object} props.model  The model.
 * @return {Element} Lists.
 */
function Details( { result, model } ) {
	const [ tab, setTab ] = useState( 'add' );
	const [ all, setAll ] = useState( false );
	const l = lists( result );
	const add = [ ...l.add, ...l.under ];
	const SHOW = 25;
	const tabs = [
		[
			'add',
			sprintf(
				/* translators: %d: number of terms. */
				__( 'To add (%d)', 'zinn-seo' ),
				add.length
			),
		],
		[
			'over',
			sprintf(
				/* translators: %d: number of terms. */
				__( 'Overused (%d)', 'zinn-seo' ),
				l.over.length
			),
		],
		[ 'names', __( 'Names', 'zinn-seo' ) ],
		[ 'questions', __( 'Questions', 'zinn-seo' ) ],
		[ 'headings', __( 'Headings', 'zinn-seo' ) ],
		[ 'pages', __( 'Top pages', 'zinn-seo' ) ],
	];
	let body;
	if ( tab === 'add' || tab === 'over' ) {
		const rows = tab === 'add' ? add : l.over;
		body = rows.length ? (
			<>
				<p className="zseo-opt-note">
					{ tab === 'add'
						? __(
								'Words and phrases the top pages use that your text lacks or uses too little, most shared first. The numbers: times you use it / the range the top pages use, scaled to your length.',
								'zinn-seo'
							)
						: __(
								'You use these more than the top pages do. Repeating a word does not help; vary it or cut it.',
								'zinn-seo'
							) }
				</p>
				<ul className="zseo-opt-terms">
					{ ( all ? rows : rows.slice( 0, SHOW ) ).map( ( t ) => (
						<TermRow key={ t.term } t={ t } />
					) ) }
				</ul>
				{ rows.length > SHOW && (
					<Button variant="link" onClick={ () => setAll( ! all ) }>
						{ all
							? __( 'Show fewer', 'zinn-seo' )
							: sprintf(
									/* translators: %d: number of rows. */
									__( 'Show all %d', 'zinn-seo' ),
									rows.length
								) }
					</Button>
				) }
			</>
		) : (
			<p className="zseo-opt-note">
				{ tab === 'add'
					? __(
							'You cover every term the top pages share.',
							'zinn-seo'
						)
					: __( 'Nothing is overused.', 'zinn-seo' ) }
			</p>
		);
	} else if ( tab === 'names' ) {
		body = result.entities.length ? (
			<ul className="zseo-opt-checks">
				{ result.entities.map( ( e ) => (
					<li
						key={ e.name }
						className={ e.present ? 'is-good' : 'is-missing' }
					>
						{ e.name }{ ' ' }
						<span className="zseo-opt-muted">
							{ sprintf(
								/* translators: %d: number of top pages. */
								_n(
									'%d top page',
									'%d top pages',
									e.pages,
									'zinn-seo'
								),
								e.pages
							) }
						</span>
					</li>
				) ) }
			</ul>
		) : (
			<p className="zseo-opt-note">
				{ __(
					'The top pages share no names (people, places, products or brands).',
					'zinn-seo'
				) }
			</p>
		);
	} else if ( tab === 'questions' ) {
		const word = {
			answered: __( 'answered in a heading', 'zinn-seo' ),
			mentioned: __( 'mentioned, not answered', 'zinn-seo' ),
			missing: __( 'not covered', 'zinn-seo' ),
		};
		body = result.questions.length ? (
			<ul className="zseo-opt-checks">
				{ result.questions.map( ( q ) => (
					<li
						key={ q.question }
						className={
							q.state === 'answered'
								? 'is-good'
								: `is-${ q.state }`
						}
					>
						{ q.question }{ ' ' }
						<span className="zseo-opt-muted">
							{ word[ q.state ] }
						</span>
					</li>
				) ) }
			</ul>
		) : (
			<p className="zseo-opt-note">
				{ __( 'No questions were found for this search.', 'zinn-seo' ) }
			</p>
		);
	} else if ( tab === 'headings' ) {
		body = result.headings.length ? (
			<>
				<p className="zseo-opt-note">
					{ __(
						'Subjects several top pages give a heading of their own. A heading of yours on the same subject counts.',
						'zinn-seo'
					) }
				</p>
				<ul className="zseo-opt-checks">
					{ result.headings.map( ( h ) => (
						<li
							key={ h.text }
							className={ h.present ? 'is-good' : 'is-missing' }
						>
							{ h.text }{ ' ' }
							<span className="zseo-opt-muted">
								{ sprintf(
									/* translators: %d: number of top pages. */
									_n(
										'%d top page',
										'%d top pages',
										h.pages,
										'zinn-seo'
									),
									h.pages
								) }
							</span>
						</li>
					) ) }
				</ul>
			</>
		) : (
			<p className="zseo-opt-note">
				{ __( 'The top pages share no heading subjects.', 'zinn-seo' ) }
			</p>
		);
	} else {
		body = (
			<ol className="zseo-opt-pages">
				{ ( model.pages || [] ).map( ( p ) => (
					<li key={ `${ p.position }-${ p.url }` }>
						<details>
							<summary>
								<span className="zseo-opt-pages__title">
									{ p.title || p.domain }
								</span>{ ' ' }
								<span className="zseo-opt-muted">
									{ p.domain }
									{ ' · ' }
									{ p.read && p.words
										? sprintf(
												/* translators: %d: number of words. */
												__( '%d words', 'zinn-seo' ),
												p.words
											)
										: __(
												'could not be read',
												'zinn-seo'
											) }
								</span>
							</summary>
							<ExternalLink href={ p.url }>
								{ __( 'Open the page', 'zinn-seo' ) }
							</ExternalLink>
							{ ( p.outline || [] ).length > 0 && (
								<ul className="zseo-opt-outline">
									{ p.outline.map( ( h, i ) => (
										<li
											key={ i }
											className={ `is-h${ h.level }` }
										>
											<span className="zseo-opt-muted">{ `H${ h.level }` }</span>{ ' ' }
											{ h.text }
										</li>
									) ) }
								</ul>
							) }
						</details>
					</li>
				) ) }
			</ol>
		);
	}
	return (
		<>
			<div
				className="zseo-opt-tabs"
				role="tablist"
				aria-label={ __( 'Optimiser details', 'zinn-seo' ) }
			>
				{ tabs.map( ( [ id, label ] ) => (
					<button
						key={ id }
						type="button"
						role="tab"
						aria-selected={ tab === id }
						className={ tab === id ? 'is-active' : '' }
						onClick={ () => {
							setTab( id );
							setAll( false );
						} }
					>
						{ label }
					</button>
				) ) }
			</div>
			<div role="tabpanel" className="zseo-opt-panel">
				{ body }
			</div>
		</>
	);
}

/**
 * The optimiser section.
 *
 * @param {Object}   props
 * @param {number}   props.postId   Post.
 * @param {string}   props.phrase   The first focus phrase.
 * @param {Function} props.getDraft The editor's current values (`content`).
 * @param {number}   props.version  Bumped on every edit.
 * @return {Element} Section.
 */
export default function OptimiserPanel( {
	postId,
	phrase,
	getDraft,
	version,
} ) {
	const def = conf.default || {};
	const [ location, setLocation ] = useState( def.location || '' );
	const [ language, setLanguage ] = useState( def.language || '' );
	const [ entry, setEntry ] = useState( null );
	const [ moneyNow, setMoney ] = useState( conf.money || {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ draftVersion, setDraftVersion ] = useState( 0 );
	const seq = useRef( 0 );
	const markets = conf.markets || {};
	const p = String( phrase || '' ).trim();

	const country =
		Object.keys( markets ).find(
			( c ) => markets[ c ].location === location
		) || '';

	const take = ( r ) => {
		setEntry( r );
		if ( r && r.money ) {
			setMoney( r.money );
		}
	};

	// The kept model for this search: read a moment after the phrase stops changing (free).
	useEffect( () => {
		if ( ! p ) {
			setEntry( null );
			return;
		}
		const n = ++seq.current;
		const t = setTimeout( () => {
			apiFetch( {
				path: `${ base }/optimiser/model?${ new URLSearchParams( {
					phrase: p,
					location,
					language,
				} ) }`,
			} )
				.then( ( r ) => n === seq.current && take( r ) )
				.catch( () => {} );
		}, 800 );
		return () => clearTimeout( t );
	}, [ p, location, language ] );

	// A read in progress: one check every few seconds until Zinn® answers (it takes under a minute).
	useEffect( () => {
		if ( ! entry || entry.state !== 'pending' ) {
			return;
		}
		const n = seq.current;
		const t = setTimeout( () => {
			apiFetch( {
				path: `${ base }/optimiser/model?${ new URLSearchParams( {
					phrase: entry.phrase,
					location: entry.location,
					language: entry.language,
				} ) }`,
			} )
				.then( ( r ) => n === seq.current && take( r ) )
				.catch( () => {} );
		}, 5000 );
		return () => clearTimeout( t );
	}, [ entry ] );

	// Re-score a moment after typing stops — in the browser, nothing sent.
	useEffect( () => {
		const t = setTimeout( () => setDraftVersion( ( v ) => v + 1 ), 400 );
		return () => clearTimeout( t );
	}, [ version ] );

	const model = entry && entry.model;
	// A page builder's editor (Elementor) gives no text to read here: its saved page is scored by
	// the site after each save instead — one request per save, never per keystroke.
	const live = ( ( getDraft && getDraft() ) || {} ).content !== undefined;
	const [ saved, setSaved ] = useState( null );
	useEffect( () => {
		if ( live || ! model || ! postId ) {
			return;
		}
		apiFetch( {
			path: `${ base }/optimiser/post/${ postId }?${ new URLSearchParams(
				{
					phrase: p,
					location,
					language,
				}
			) }`,
		} )
			.then( ( r ) => setSaved( r && r.terms ? r : null ) )
			.catch( () => setSaved( null ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ live, model, draftVersion ] );
	const local = useMemo( () => {
		if ( ! model || ! live ) {
			return null;
		}
		const d = ( getDraft && getDraft() ) || {};
		return score(
			model,
			draftFromHtml( d.content || '' ),
			model.stop || conf.stop || []
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ model, draftVersion, live ] );
	const result = live ? local : saved;

	const read = ( again ) => {
		setBusy( true );
		setError( null );
		apiFetch( {
			path: `${ base }/optimiser/read`,
			method: 'POST',
			data: { phrase: p, location, language, again, post: postId },
		} )
			.then( take )
			.catch( ( e ) => setError( e || {} ) )
			.finally( () => setBusy( false ) );
	};

	const price =
		moneyNow.price_micros === null || moneyNow.price_micros === undefined
			? ''
			: priceText( moneyNow.price_micros );
	const low = [ 'held', 'empty', 'low', 'short' ].includes( moneyNow.state );
	const buy =
		moneyNow.buy_url || ( error && error.data && error.data.buy_url );

	let readButton = null;
	if ( p && moneyNow.can_spend && ( ! entry || entry.state !== 'pending' ) ) {
		const again = !! model;
		let label;
		if ( again ) {
			label = price
				? sprintf(
						/* translators: %s: a price such as $0.05. */
						__( 'Read the top 10 again (%s)', 'zinn-seo' ),
						price
					)
				: __( 'Read the top 10 again', 'zinn-seo' );
		} else {
			label = price
				? sprintf(
						/* translators: %s: a price such as $0.05. */
						__(
							'Read the top 10 for this phrase (%s)',
							'zinn-seo'
						),
						price
					)
				: __( 'Read the top 10 for this phrase', 'zinn-seo' );
		}
		readButton = (
			<Button
				variant={ again ? 'secondary' : 'primary' }
				onClick={ () => read( again ) }
				disabled={ busy || ( ! again && moneyNow.state === 'held' ) }
				isBusy={ busy }
				__next40pxDefaultSize
			>
				{ label }
			</Button>
		);
	}

	return (
		<PanelBody
			title={ __( 'Content optimiser', 'zinn-seo' ) }
			initialOpen
			className="zseo-an-group zseo-opt"
		>
			{ ! p && (
				<p className="zseo-opt-note">
					{ __(
						'Add a focus phrase above. The optimiser then compares your text with the 10 pages that rank for it: the words, names, questions and headings they cover.',
						'zinn-seo'
					) }
				</p>
			) }
			{ p && (
				<div className="zseo-opt-market">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Country', 'zinn-seo' ) }
						value={ country }
						options={ marketOptions( markets ) }
						onChange={ ( c ) =>
							setLocation( ( markets[ c ] || {} ).location || '' )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Language', 'zinn-seo' ) }
						value={ language.split( '-' )[ 0 ] }
						options={ LANGUAGES.map( ( c ) => ( {
							value: c,
							label: languageName( c ),
						} ) ).sort( ( a, b ) =>
							a.label.localeCompare( b.label )
						) }
						onChange={ setLanguage }
					/>
				</div>
			) }
			{ result && (
				<div className="zseo-opt-head" aria-live="polite">
					<Ring value={ result.score } />
					<ul className="zseo-opt-parts">
						{ [
							[ 'terms', __( 'Topic terms', 'zinn-seo' ) ],
							[ 'entities', __( 'Names', 'zinn-seo' ) ],
							[ 'questions', __( 'Questions', 'zinn-seo' ) ],
							[ 'headings', __( 'Headings', 'zinn-seo' ) ],
							[ 'length', __( 'Length', 'zinn-seo' ) ],
						]
							.filter(
								( [ k ] ) => result.parts[ k ] !== undefined
							)
							.map( ( [ k, label ] ) => (
								<li key={ k }>
									<span>{ label }</span>
									<span
										className="zseo-opt-bar"
										style={ {
											'--zseo-opt': `${ result.parts[ k ] }`,
										} }
										aria-hidden="true"
									/>
									<span>{ `${ result.parts[ k ] }%` }</span>
								</li>
							) ) }
					</ul>
				</div>
			) }
			{ result && result.target.median && (
				<p className="zseo-opt-note">
					{ sprintf(
						/* translators: 1: words in the post, 2: low end, 3: high end of the top pages' length. */
						__(
							'Length: %1$d words. Most top pages run %2$d–%3$d words.',
							'zinn-seo'
						),
						result.words,
						result.target.low || result.target.median,
						result.target.high || result.target.median
					) }
				</p>
			) }
			{ entry && entry.state === 'pending' && (
				<p className="zseo-opt-note">
					<Spinner />{ ' ' }
					{ __(
						'Reading the top 10 results… this takes under a minute.',
						'zinn-seo'
					) }
				</p>
			) }
			{ model && entry.old && (
				<p className="zseo-opt-note">
					{ sprintf(
						/* translators: %d: days. */
						_n(
							'These results were read %d day ago. Search results change: read them again when you are ready.',
							'These results were read %d days ago. Search results change: read them again when you are ready.',
							entry.age_days,
							'zinn-seo'
						),
						entry.age_days
					) }
				</p>
			) }
			{ p && ! model && ( ! entry || entry.state !== 'pending' ) && (
				<p className="zseo-opt-note">
					{ moneyNow.connected
						? __(
								'Zinn® reads the 10 pages that rank for this phrase once, from your credits, and keeps the result for every post that targets it. The score is worked out in your editor as you type, free.',
								'zinn-seo'
							)
						: __(
								'Connect this site to a Zinn® account to read the pages that rank for your phrase. Reading them is paid from Zinn® credits; the score as you type is free.',
								'zinn-seo'
							) }
				</p>
			) }
			{ p && moneyNow.connected && ! moneyNow.can_spend && ! model && (
				<p className="zseo-opt-note">
					{ __(
						'Ask an administrator to read the top 10 for this phrase. Once read, everyone writing for it sees the score.',
						'zinn-seo'
					) }
				</p>
			) }
			{ entry && entry.state === 'failed' && entry.error && (
				<Notice status="warning" isDismissible={ false }>
					{ entry.error }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message ||
						__( 'The results could not be read.', 'zinn-seo' ) }
				</Notice>
			) }
			<div className="zseo-opt-actions">
				{ readButton }
				{ ! moneyNow.connected && conf.connect && (
					<Button variant="primary" href={ conf.connect }>
						{ __( 'Connect to Zinn®', 'zinn-seo' ) }
					</Button>
				) }
				{ ( low ||
					( error && error.code === 'zinn_seo_opt_credit' ) ) &&
					buy && (
						<Button
							variant="secondary"
							href={ buy }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Buy credits', 'zinn-seo' ) }
						</Button>
					) }
			</div>
			{ result && model && <Details result={ result } model={ model } /> }
			{ conf.help && (
				<p className="zseo-opt-help">
					<ExternalLink href={ conf.help }>
						{ __( 'Step-by-step guide', 'zinn-seo' ) }
					</ExternalLink>
				</p>
			) }
		</PanelBody>
	);
}
