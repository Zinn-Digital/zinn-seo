/**
 * The content analysis panel — the same in the block editor's sidebar, the classic editor's box
 * and Elementor's Page settings → SEO (zs-001 … zs-012, zs-073, zs-100 … zs-104).
 *
 * Everything is measured on the SERVER on the page as visitors get it (zs-002): the panel sends
 * the editor's current, unsaved values and draws what comes back. It re-checks a moment after
 * typing stops, and stores the scores after each save.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import SerpPreview from './SerpPreview';
import OptimiserPanel from '../optimiser/OptimiserPanel';
import KeywordFinder from '../../tools/KeywordFinder';
import { parseFocus, rating, stringifyFocus } from './pure';
import './analysis.scss';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';
const conf = boot.analysis || {};

/**
 * Words for a rating.
 *
 * @param {string} r good|ok|bad|none.
 * @return {string} Words.
 */
export function ratingText( r ) {
	switch ( r ) {
		case 'good':
			return __( 'Good', 'zinn-seo' );
		case 'ok':
			return __( 'Could be better', 'zinn-seo' );
		case 'bad':
			return __( 'Needs work', 'zinn-seo' );
	}
	return __( 'Not measured', 'zinn-seo' );
}

/**
 * A score badge.
 *
 * @param {Object}      props
 * @param {string}      props.label Label.
 * @param {number|null} props.score Score.
 * @return {Element} Badge.
 */
function Score( { label, score } ) {
	const r = rating( score );
	return (
		<div className={ `zseo-an-score is-${ r }` }>
			<span className="zseo-an-score__num" aria-hidden="true">
				{ score === null || score === undefined ? '–' : score }
			</span>
			<span className="zseo-an-score__label">
				{ label }
				<span className="screen-reader-text">
					{ score === null || score === undefined
						? ''
						: `: ${ score }/100, ${ ratingText( r ) }` }
				</span>
			</span>
		</div>
	);
}

/**
 * One check.
 *
 * @param {Object} props
 * @param {Object} props.check The check.
 * @return {Element} Row.
 */
function Check( { check } ) {
	const [ open, setOpen ] = useState( false );
	const statusText = {
		good: __( 'Good:', 'zinn-seo' ),
		ok: __( 'To improve:', 'zinn-seo' ),
		bad: __( 'Problem:', 'zinn-seo' ),
		na: __( 'Not measured:', 'zinn-seo' ),
	}[ check.status ];
	return (
		<li className={ `zseo-an-check is-${ check.status }` }>
			<span className="zseo-an-check__dot" aria-hidden="true" />
			<span className="zseo-an-check__text">
				<span className="screen-reader-text">{ statusText } </span>
				{ check.text }
				{ check.marks && check.marks.length > 0 && (
					<>
						{ ' ' }
						<Button
							variant="link"
							className="zseo-an-check__more"
							aria-expanded={ open }
							onClick={ () => setOpen( ! open ) }
						>
							{ open
								? __( 'Hide', 'zinn-seo' )
								: __( 'Show where', 'zinn-seo' ) }
						</Button>
						{ open && (
							<ul className="zseo-an-marks">
								{ check.marks.map( ( m, i ) => (
									<li key={ i }>{ m }</li>
								) ) }
							</ul>
						) }
					</>
				) }
			</span>
		</li>
	);
}

/**
 * A group of checks, problems first.
 *
 * @param {Object}  props
 * @param {string}  props.title  Title.
 * @param {Object}  props.group  `{score, checks}`.
 * @param {Array}   props.extra  More checks (inclusive language).
 * @param {boolean} props.opened Open at first.
 * @param {Element} props.after  Shown under the checks.
 * @return {Element} Panel.
 */
function Group( { title, group, extra = [], opened = false, after = null } ) {
	if ( ! group ) {
		return null;
	}
	const order = { bad: 0, ok: 1, good: 2, na: 3 };
	const checks = [ ...( group.checks || [] ), ...extra ].sort(
		( a, b ) => order[ a.status ] - order[ b.status ]
	);
	const problems = checks.filter( ( c ) => c.status === 'bad' ).length;
	return (
		<PanelBody
			title={
				problems
					? sprintf(
							/* translators: 1: group name, 2: number of problems. */
							_n(
								'%1$s (%2$d problem)',
								'%1$s (%2$d problems)',
								problems,
								'zinn-seo'
							),
							title,
							problems
						)
					: title
			}
			initialOpen={ opened }
			className="zseo-an-group"
		>
			<ul className="zseo-an-checks">
				{ checks.map( ( c, i ) => (
					<Check key={ `${ c.id }-${ i }` } check={ c } />
				) ) }
			</ul>
			{ after }
		</PanelBody>
	);
}

/**
 * The focus phrases editor.
 *
 * @param {Object}   props
 * @param {string}   props.value    Stored text.
 * @param {number}   props.max      Phrases allowed (0 = no limit).
 * @param {Function} props.onChange Called with the new text.
 * @return {Element} Editor.
 */
export function FocusEditor( { value, max, onChange } ) {
	const list = parseFocus( value );
	const rows = list.length ? list : [ { phrase: '', synonyms: '' } ];
	const [ draft, setDraft ] = useState( rows );
	const last = useRef( value );
	useEffect( () => {
		if ( value !== last.current ) {
			last.current = value;
			setDraft( parseFocus( value ).length ? parseFocus( value ) : rows );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );
	const commit = ( next ) => {
		setDraft( next );
		const text = stringifyFocus( next );
		last.current = text;
		onChange( text );
	};
	const full = max > 0 && draft.length >= max;
	return (
		<div className="zseo-an-focus">
			{ draft.map( ( f, i ) => (
				<div className="zseo-an-focus__row" key={ i }>
					<TextControl
						__nextHasNoMarginBottom
						label={
							i === 0
								? __( 'Focus phrase', 'zinn-seo' )
								: sprintf(
										/* translators: %d: the phrase's number. */
										__( 'Extra phrase %d', 'zinn-seo' ),
										i + 1
									)
						}
						help={
							i === 0
								? __(
										'What people would search to find this page.',
										'zinn-seo'
									)
								: undefined
						}
						value={ f.phrase }
						onChange={ ( v ) =>
							commit(
								draft.map( ( x, j ) =>
									j === i ? { ...x, phrase: v } : x
								)
							)
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Synonyms (separate with commas)',
							'zinn-seo'
						) }
						value={ f.synonyms }
						onChange={ ( v ) =>
							commit(
								draft.map( ( x, j ) =>
									j === i ? { ...x, synonyms: v } : x
								)
							)
						}
					/>
					{ draft.length > 1 && (
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								commit( draft.filter( ( x, j ) => j !== i ) )
							}
						>
							{ __( 'Remove this phrase', 'zinn-seo' ) }
						</Button>
					) }
				</div>
			) ) }
			{ ! full && (
				<Button
					variant="secondary"
					onClick={ () =>
						setDraft( [ ...draft, { phrase: '', synonyms: '' } ] )
					}
				>
					{ __( 'Add another phrase', 'zinn-seo' ) }
				</Button>
			) }
			{ full && ! boot.isPro && (
				<p className="zseo-an-note">
					{ sprintf(
						/* translators: %d: phrases allowed. */
						__(
							'%d phrases per page. Pro analyses as many as you add.',
							'zinn-seo'
						),
						max
					) }{ ' ' }
					<a href={ boot.plansUrl }>
						{ __( 'See the Pro plans', 'zinn-seo' ) }
					</a>
				</p>
			) }
		</div>
	);
}

/**
 * Internal link suggestions.
 *
 * @param {Object}   props
 * @param {number}   props.postId     Post.
 * @param {Function} props.getContent Current content.
 * @param {number}   props.version    Changes when the content changes.
 * @param {Function} props.insertLink (anchor, url) => bool, when the editor can insert.
 * @return {Element} Panel.
 */
function Suggestions( { postId, getContent, version, insertLink } ) {
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( {} );
	const load = () => {
		setBusy( true );
		setError( '' );
		const content = getContent();
		apiFetch( {
			path: `${ base }/links/suggest/${ postId }`,
			method: 'POST',
			data: typeof content === 'string' ? { content } : {},
		} )
			.then( setData )
			.catch( ( e ) =>
				setError(
					( e && e.message ) ||
						__( 'Could not load suggestions.', 'zinn-seo' )
				)
			)
			.finally( () => setBusy( false ) );
	};
	useEffect( () => {
		const t = setTimeout( load, data ? 3000 : 0 );
		return () => clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ version, postId ] );
	const copy = ( url ) => {
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( url ).catch( () => {} );
		}
	};
	const addIncoming = ( s ) => {
		apiFetch( {
			path: `${ base }/links/add`,
			method: 'POST',
			data: { source: s.id, target: postId, anchor: s.anchor },
		} )
			.then( () => setDone( { ...done, [ `in-${ s.id }` ]: true } ) )
			.catch( ( e ) => setError( e && e.message ) );
	};
	return (
		<PanelBody
			title={ __( 'Internal links', 'zinn-seo' ) }
			initialOpen={ false }
			className="zseo-an-group"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ busy && ! data && <Spinner /> }
			{ data && (
				<>
					<h3 className="zseo-an-sub">
						{ __( 'Link from this page to', 'zinn-seo' ) }
					</h3>
					{ data.outgoing.length === 0 && (
						<p className="zseo-an-note">
							{ __(
								"No suggestion yet: other pages' phrases do not appear in this text. Write more, or add focus phrases to your other pages.",
								'zinn-seo'
							) }
						</p>
					) }
					<ul className="zseo-an-links">
						{ data.outgoing.map( ( s ) => (
							<li key={ s.id }>
								<a
									href={ s.url }
									target="_blank"
									rel="noreferrer"
								>
									{ s.title }
								</a>
								{ s.cornerstone && (
									<span className="zseo-an-tag">
										{ __( 'Cornerstone', 'zinn-seo' ) }
									</span>
								) }
								<span className="zseo-an-links__anchor">
									{ sprintf(
										/* translators: %s: words to link. */
										__( 'Link the words "%s"', 'zinn-seo' ),
										s.anchor
									) }
								</span>
								<span className="zseo-an-links__sentence">
									{ s.sentence }
								</span>
								<span className="zseo-an-links__do">
									{ insertLink && (
										<Button
											variant="secondary"
											size="compact"
											disabled={ !! done[ s.id ] }
											onClick={ () => {
												if (
													insertLink(
														s.anchor,
														s.url
													)
												) {
													setDone( {
														...done,
														[ s.id ]: true,
													} );
												} else {
													setError(
														__(
															'Those words are not in a paragraph the editor can change; copy the address and link them by hand.',
															'zinn-seo'
														)
													);
												}
											} }
										>
											{ done[ s.id ]
												? __( 'Linked', 'zinn-seo' )
												: __(
														'Insert link',
														'zinn-seo'
													) }
										</Button>
									) }
									<Button
										variant="tertiary"
										size="compact"
										onClick={ () => copy( s.url ) }
									>
										{ __( 'Copy address', 'zinn-seo' ) }
									</Button>
								</span>
							</li>
						) ) }
					</ul>
					{ data.more > 0 && (
						<p className="zseo-an-note">
							{ sprintf(
								/* translators: %d: number of suggestions. */
								_n(
									'%d more suggestion with Pro.',
									'%d more suggestions with Pro.',
									data.more,
									'zinn-seo'
								),
								data.more
							) }{ ' ' }
							<a href={ boot.plansUrl }>
								{ __( 'See the Pro plans', 'zinn-seo' ) }
							</a>
						</p>
					) }
					{ ! boot.isPro && (
						<p className="zseo-an-note">
							{ __(
								'Do it for the whole site with Pro: the AI link plan links every page like an SEO expert, with natural words and one-click undo.',
								'zinn-seo'
							) }{ ' ' }
							<a href={ boot.plansUrl }>
								{ __( 'See the Pro plans', 'zinn-seo' ) }
							</a>
						</p>
					) }
					{ Array.isArray( data.incoming ) && (
						<>
							<h3 className="zseo-an-sub">
								{ __(
									'Pages that could link here',
									'zinn-seo'
								) }
							</h3>
							{ data.incoming.length === 0 && (
								<p className="zseo-an-note">
									{ __(
										"None found: no other page mentions this page's focus phrase without linking here.",
										'zinn-seo'
									) }
								</p>
							) }
							<ul className="zseo-an-links">
								{ data.incoming.map( ( s ) => (
									<li key={ s.id }>
										<a href={ s.edit }>{ s.title }</a>
										<span className="zseo-an-links__sentence">
											{ s.sentence }
										</span>
										<span className="zseo-an-links__do">
											<Button
												variant="secondary"
												size="compact"
												disabled={
													!! done[ `in-${ s.id }` ]
												}
												onClick={ () =>
													addIncoming( s )
												}
											>
												{ done[ `in-${ s.id }` ]
													? __( 'Linked', 'zinn-seo' )
													: sprintf(
															/* translators: %s: words to link. */
															__(
																'Link "%s" here',
																'zinn-seo'
															),
															s.anchor
														) }
											</Button>
										</span>
									</li>
								) ) }
							</ul>
						</>
					) }
					{ ! data.indexed && (
						<p className="zseo-an-note">
							{ __(
								'Build the link index on the Links screen to see which pages already link where.',
								'zinn-seo'
							) }
						</p>
					) }
					{ conf.semantic && (
						<Meaning
							postId={ postId }
							getContent={ getContent }
							insertLink={ insertLink }
						/>
					) }
					<Button variant="link" onClick={ load } disabled={ busy }>
						{ __( 'Look again', 'zinn-seo' ) }
					</Button>
				</>
			) }
		</PanelBody>
	);
}

/**
 * Pro: pages related by meaning (embeddings with the owner's AI key, zs-101).
 *
 * @param {Object}   props
 * @param {number}   props.postId     Post.
 * @param {Function} props.getContent Current content.
 * @param {Function} props.insertLink Insert a link (block editor only).
 * @return {Element} Section.
 */
function Meaning( { postId, getContent, insertLink } ) {
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const run = () => {
		setBusy( true );
		setError( '' );
		const content = getContent();
		apiFetch( {
			path: `${ base }/links/semantic/${ postId }`,
			method: 'POST',
			data: typeof content === 'string' ? { content } : {},
		} )
			.then( setData )
			.catch( ( e ) => setError( ( e && e.message ) || '' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="zseo-an-meaning">
			<h3 className="zseo-an-sub">
				{ __( 'Related by meaning', 'zinn-seo' ) }
			</h3>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ run }
			>
				{ __( 'Find related pages with AI', 'zinn-seo' ) }
			</Button>
			{ error && (
				<Notice status="warning" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ data && (
				<>
					<p className="zseo-an-note">
						{ sprintf(
							/* translators: 1: pages compared, 2: pages in the meaning index. */
							__(
								'Compared with %1$d of %2$d indexed pages.',
								'zinn-seo'
							),
							data.compared,
							data.stored
						) }
					</p>
					<ul className="zseo-an-links">
						{ data.items.map( ( s ) => (
							<li key={ s.id }>
								<a
									href={ s.url }
									target="_blank"
									rel="noreferrer"
								>
									{ s.title }
								</a>
								<span className="zseo-an-tag">
									{ sprintf(
										/* translators: %d: similarity percentage. */
										__( '%d%% related', 'zinn-seo' ),
										s.similarity
									) }
								</span>
								{ s.anchor ? (
									<span className="zseo-an-links__sentence">
										{ s.sentence }
									</span>
								) : (
									<span className="zseo-an-links__sentence">
										{ __(
											'Its words are not in your text yet: write a sentence that leads to it.',
											'zinn-seo'
										) }
									</span>
								) }
								{ s.anchor && insertLink && (
									<span className="zseo-an-links__do">
										<Button
											variant="secondary"
											size="compact"
											onClick={ () =>
												insertLink( s.anchor, s.url )
											}
										>
											{ sprintf(
												/* translators: %s: words to link. */
												__( 'Link "%s"', 'zinn-seo' ),
												s.anchor
											) }
										</Button>
									</span>
								) }
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}

/**
 * Pro: topic coverage against ranking pages.
 *
 * @param {Object}   props
 * @param {number}   props.postId   Post.
 * @param {Object}   props.group    The `entities` group.
 * @param {Function} props.onLoaded Re-run the analysis.
 * @return {Element} Panel.
 */
function Coverage( { postId, group, onLoaded } ) {
	const [ urls, setUrls ] = useState( ( group.urls || [] ).join( '\n' ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const run = () => {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `${ base }/analysis/entities/${ postId }`,
			method: 'POST',
			data: {
				urls: urls
					.split( /\s+/ )
					.map( ( u ) => u.trim() )
					.filter( Boolean )
					.slice( 0, 5 ),
			},
		} )
			.then( onLoaded )
			.catch( ( e ) => setError( e && e.message ) )
			.finally( () => setBusy( false ) );
	};
	const intents = {
		informational: __( 'learning', 'zinn-seo' ),
		commercial: __( 'comparing', 'zinn-seo' ),
		transactional: __( 'buying', 'zinn-seo' ),
		navigational: __( 'finding a site', 'zinn-seo' ),
	};
	return (
		<div className="zseo-an-coverage">
			{ group.intent && (
				<p>
					<strong>{ __( 'Search intent:', 'zinn-seo' ) }</strong>{ ' ' }
					{ intents[ group.intent ] || group.intent }
				</p>
			) }
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'Pages that rank for your phrase (up to 5 addresses, one per line)',
					'zinn-seo'
				) }
				value={ urls }
				rows={ 3 }
				onChange={ setUrls }
			/>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy || ! urls.trim() }
				onClick={ run }
			>
				{ __( 'Compare', 'zinn-seo' ) }
			</Button>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ( group.terms || [] ).length > 0 && (
				<ul className="zseo-an-terms">
					{ group.terms.map( ( t ) => (
						<li
							key={ t.term }
							className={
								t.present ? 'is-present' : 'is-missing'
							}
						>
							<span className="screen-reader-text">
								{ t.present
									? __( 'Covered:', 'zinn-seo' )
									: __( 'Missing:', 'zinn-seo' ) }
							</span>
							{ t.term }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Pro: the page's SEO history.
 *
 * @param {Object}   props
 * @param {number}   props.postId    Post.
 * @param {number}   props.saved     Changes after each save.
 * @param {Function} props.onRestore Called after a restore.
 * @return {Element} Panel.
 */
function History( { postId, saved, onRestore } ) {
	const [ items, setItems ] = useState( null );
	const [ error, setError ] = useState( '' );
	const load = () =>
		apiFetch( { path: `${ base }/revisions/post/${ postId }` } )
			.then( ( r ) => setItems( r.items ) )
			.catch( ( e ) => setError( e && e.message ) );
	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postId, saved ] );
	const show = ( v ) => {
		if ( Array.isArray( v ) ) {
			return v.join( ', ' ) || '—';
		}
		if ( typeof v === 'boolean' ) {
			return v ? __( 'yes', 'zinn-seo' ) : __( 'no', 'zinn-seo' );
		}
		return v === '' || v === null || v === undefined ? '—' : String( v );
	};
	return (
		<PanelBody
			title={ __( 'SEO history', 'zinn-seo' ) }
			initialOpen={ false }
			className="zseo-an-group"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! items && ! error && <Spinner /> }
			{ items && items.length === 0 && (
				<p className="zseo-an-note">
					{ __( 'No SEO field has changed yet.', 'zinn-seo' ) }
				</p>
			) }
			<ol className="zseo-an-history">
				{ ( items || [] ).map( ( h ) => (
					<li key={ h.id }>
						<span className="zseo-an-history__what">
							<strong>{ h.field }</strong>: { show( h.old ) } →{ ' ' }
							{ show( h.new ) }
						</span>
						<span className="zseo-an-history__who">
							{ new Date( h.at * 1000 ).toLocaleString() }
							{ h.user ? ` · ${ h.user }` : '' }
							{ h.actor &&
							h.actor !== 'user' &&
							h.actor !== 'rest'
								? ` · ${ h.actor }`
								: '' }
						</span>
						<Button
							variant="link"
							onClick={ () =>
								apiFetch( {
									path: `${ base }/revisions/restore/${ h.id }`,
									method: 'POST',
								} )
									.then( () => {
										load();
										onRestore();
									} )
									.catch( ( e ) =>
										setError( e && e.message )
									)
							}
						>
							{ __( 'Restore the earlier value', 'zinn-seo' ) }
						</Button>
					</li>
				) ) }
			</ol>
		</PanelBody>
	);
}

/**
 * The panel.
 *
 * @param {Object}   props
 * @param {number}   props.postId         Post.
 * @param {Function} props.getDraft       () => unsaved values {content, title, slug, excerpt, seo_title, seo_desc}.
 * @param {number}   props.version        Changes whenever the editor's content or fields change.
 * @param {number}   props.saved          Changes after each save.
 * @param {Object}   props.fields         {focus, related, cornerstone}.
 * @param {Function} props.onFields       Called with changed fields.
 * @param {Function} props.insertLink     Insert a link into the content (block editor only).
 * @param {Function} props.onFieldsReload Called after a restore so the editor reloads fields.
 * @return {Element} Panel.
 */
export default function AnalysisPanel( {
	postId,
	getDraft,
	version,
	saved,
	fields,
	onFields,
	insertLink,
	onFieldsReload = () => {},
} ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ again, setAgain ] = useState( 0 );
	const seq = useRef( 0 );
	const firstSave = useRef( saved );

	const analyse = ( store = false ) => {
		const n = ++seq.current;
		setBusy( true );
		const draft = getDraft() || {};
		const data = {
			...draft,
			focus: fields.focus || '',
			related: fields.related || '',
			cornerstone: !! fields.cornerstone,
			store,
		};
		Object.keys( data ).forEach(
			( k ) =>
				( data[ k ] === undefined || data[ k ] === null ) &&
				delete data[ k ]
		);
		apiFetch( {
			path: `${ base }/analysis/post/${ postId }`,
			method: 'POST',
			data,
		} )
			.then( ( r ) => {
				if ( n === seq.current ) {
					setResult( r );
					setError( '' );
				}
			} )
			.catch( ( e ) => {
				if ( n === seq.current ) {
					setError(
						( e && e.message ) ||
							__( 'The analysis could not run.', 'zinn-seo' )
					);
				}
			} )
			.finally( () => n === seq.current && setBusy( false ) );
	};

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		const t = setTimeout( () => analyse( false ), result ? 1200 : 0 );
		return () => clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		postId,
		version,
		fields.focus,
		fields.related,
		fields.cornerstone,
		again,
	] );

	useEffect( () => {
		if ( saved !== firstSave.current && postId ) {
			analyse( true );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ saved ] );

	const draft = getDraft() || {};
	const r = result || {};
	const lang = r.language;
	let words = '';
	if ( r.stats && r.stats.unit === 'char' ) {
		words = sprintf(
			/* translators: 1: number of characters, 2: minutes to read. */
			__( '%1$d characters · %2$d min read', 'zinn-seo' ),
			r.stats.length,
			r.stats.minutes
		);
	} else if ( r.stats ) {
		words = sprintf(
			/* translators: 1: number of words, 2: minutes to read. */
			__( '%1$d words · %2$d min read', 'zinn-seo' ),
			r.stats.length,
			r.stats.minutes
		);
	}

	return (
		<div className="zseo-an">
			<PanelBody
				title={ __( 'Focus', 'zinn-seo' ) }
				initialOpen
				className="zseo-an-group"
			>
				<FocusEditor
					value={ fields.focus || '' }
					max={ conf.phrasesMax || 0 }
					onChange={ ( v ) => onFields( { focus: v } ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __(
						'Related phrases the text should mention (commas)',
						'zinn-seo'
					) }
					value={ fields.related || '' }
					onChange={ ( v ) => onFields( { related: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Cornerstone content', 'zinn-seo' ) }
					help={ __(
						'Your most important page on this topic: checked more strictly and suggested first as a link.',
						'zinn-seo'
					) }
					checked={ !! fields.cornerstone }
					onChange={ ( v ) => onFields( { cornerstone: v } ) }
				/>
			</PanelBody>
			{ boot.toolsPro && (
				<KeywordFinder
					conf={ boot.toolsPro }
					postId={ postId }
					phrase={
						( parseFocus( fields.focus )[ 0 ] || {} ).phrase || ''
					}
					onUse={ ( phrase ) => {
						// The first focus phrase is replaced; extra phrases and synonyms stay.
						const list = parseFocus( fields.focus );
						list[ 0 ] = { synonyms: '', ...list[ 0 ], phrase };
						onFields( { focus: stringifyFocus( list ) } );
					} }
				/>
			) }
			<div className="zseo-an-summary" aria-live="polite">
				{ r.seo && (
					<div className="zseo-an-scores">
						<Score
							label={ __( 'SEO', 'zinn-seo' ) }
							score={ r.seo.score }
						/>
						{ r.readability && (
							<Score
								label={ __( 'Readability', 'zinn-seo' ) }
								score={ r.readability.score }
							/>
						) }
						<Score
							label={ __( 'Accessibility', 'zinn-seo' ) }
							score={ r.a11y && r.a11y.score }
						/>
						{ r.answer && (
							<Score
								label={ __( 'Answer-ready', 'zinn-seo' ) }
								score={ r.answer.score }
							/>
						) }
					</div>
				) }
				<p className="zseo-an-status">
					{ busy && <Spinner /> }
					{ busy
						? __(
								'Checking the page as visitors see it…',
								'zinn-seo'
							)
						: words }
				</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }{ ' ' }
						<Button
							variant="link"
							onClick={ () => setAgain( again + 1 ) }
						>
							{ __( 'Try again', 'zinn-seo' ) }
						</Button>
					</Notice>
				) }
			</div>
			{ boot.optimiser && (
				<OptimiserPanel
					postId={ postId }
					phrase={
						( parseFocus( fields.focus )[ 0 ] || {} ).phrase || ''
					}
					getDraft={ getDraft }
					version={ version }
				/>
			) }
			<PanelBody
				title={ __( 'Search result preview', 'zinn-seo' ) }
				initialOpen={ false }
				className="zseo-an-group"
			>
				<SerpPreview
					title={ r.title || draft.title || '' }
					desc={ r.desc || '' }
					url={ draft.link || conf.home || '' }
					siteName={ conf.siteName }
					icon={ conf.siteIcon }
				/>
			</PanelBody>
			{ result && (
				<>
					<Group
						title={ __( 'SEO', 'zinn-seo' ) }
						group={ r.seo }
						opened
					/>
					<Group
						title={ __( 'Readability', 'zinn-seo' ) }
						group={ r.readability }
						extra={
							( r.readability && r.readability.inclusive ) || []
						}
						after={
							lang && (
								<p className="zseo-an-note">
									{ sprintf(
										/* translators: %s: a language code. */
										__( 'Measured as %s.', 'zinn-seo' ),
										lang.locale
									) }{ ' ' }
									{ ! lang.passive &&
										__(
											'Passive voice is not measured in this language.',
											'zinn-seo'
										) }
								</p>
							)
						}
					/>
					<Group
						title={ __( 'Accessibility', 'zinn-seo' ) }
						group={ r.a11y }
					/>
					{ r.answer ? (
						<Group
							title={ __( 'Answer-readiness', 'zinn-seo' ) }
							group={ r.answer }
						/>
					) : (
						<PanelBody
							title={ __( 'Answer-readiness (Pro)', 'zinn-seo' ) }
							initialOpen={ false }
							className="zseo-an-group"
						>
							<p className="zseo-an-note">
								{ __(
									'Pro scores how quotable the page is for AI answers and featured snippets: a direct answer up front, lists and tables, questions as headings, facts, sources, freshness and author.',
									'zinn-seo'
								) }{ ' ' }
								<a href={ boot.plansUrl }>
									{ __( 'See the Pro plans', 'zinn-seo' ) }
								</a>
							</p>
						</PanelBody>
					) }
					{ r.entities ? (
						<Group
							title={ __( 'Topic coverage', 'zinn-seo' ) }
							group={ r.entities }
							after={
								<Coverage
									postId={ postId }
									group={ r.entities }
									onLoaded={ () => setAgain( again + 1 ) }
								/>
							}
						/>
					) : null }
					<Suggestions
						postId={ postId }
						version={ version }
						insertLink={ insertLink }
						getContent={ () => ( getDraft() || {} ).content }
					/>
					{ conf.revisions && (
						<History
							postId={ postId }
							saved={ saved }
							onRestore={ () => {
								onFieldsReload();
								setAgain( again + 1 );
							} }
						/>
					) }
					{ ( r.outline || [] ).length > 0 && (
						<PanelBody
							title={ __( 'Headings', 'zinn-seo' ) }
							initialOpen={ false }
							className="zseo-an-group"
						>
							<ol className="zseo-an-outline">
								{ r.outline.map( ( h, i ) => (
									<li
										key={ i }
										className={ `is-h${ h.level }` }
									>
										<span className="zseo-an-outline__level">
											{ `H${ h.level }` }
										</span>{ ' ' }
										{ h.text }
									</li>
								) ) }
							</ol>
						</PanelBody>
					) }
				</>
			) }
			<p className="zseo-an-foot">
				<ExternalLink href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">
					{ __( 'Google: creating helpful content', 'zinn-seo' ) }
				</ExternalLink>
			</p>
		</div>
	);
}
