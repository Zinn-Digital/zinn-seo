/**
 * Stage X1C's editor panels: the headline score, keyword lists, the AI writer on a passage,
 * spelling and tone, writing tool guidelines and originality (Pro), the image generator (Pro),
 * links to your other sites (Agency), affiliate links in the post (Pro), news (Pro), datasets and
 * math solvers (Pro), and the "Show as FAQ in search results" switch on accordion blocks (Pro).
 *
 * ⛔ zs-200: nothing here changes the post by itself. A rewrite, a fix or a picture goes into the
 * UNSAVED post when the person presses the button, and WordPress keeps a revision when they save.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import GuideAnchor, { editorGuide } from '../admin/x1a/GuideAnchor';
import ModelPicker from '../admin/x1c/ModelPicker';
// zs-782 (statically: a lazy chunk's strings would not be translated, see WritingScreen.js).
import CopywriterPanel from '../copywriter/EditorPanel';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';
const call = ( path, options = {} ) =>
	apiFetch( { path: `${ base }${ path }`, ...options } );
const errText = ( e ) =>
	( e && e.message ) ||
	__( 'Something went wrong. Please try again.', 'zinn-seo' );

/**
 * HTML as plain text, parsed inertly (DOMParser runs no script and loads no image).
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function plainOf( html ) {
	const doc = new window.DOMParser().parseFromString(
		String( html || '' ),
		'text/html'
	);
	return ( doc.body && doc.body.textContent ) || '';
}

/**
 * The post's current text, from its blocks, as plain text (paragraphs on their own lines).
 *
 * @param {Array<Object>} blocks Blocks.
 * @return {string} Text.
 */
export function blocksText( blocks ) {
	const out = [];
	const walk = ( list ) =>
		( list || [] ).forEach( ( b ) => {
			const a = b.attributes || {};
			[ 'content', 'value', 'citation', 'caption' ].forEach( ( k ) => {
				if ( 'string' === typeof a[ k ] && a[ k ] ) {
					out.push( a[ k ] );
				} else if (
					a[ k ] &&
					'function' === typeof a[ k ].toString &&
					'object' === typeof a[ k ]
				) {
					const s = String( a[ k ] );
					if ( s && '[object Object]' !== s ) {
						out.push( s );
					}
				}
			} );
			walk( b.innerBlocks );
		} );
	walk( blocks );
	return out
		.map( ( html ) => plainOf( html ) )
		.filter( Boolean )
		.join( '\n\n' );
}

/**
 * Replace the first occurrence of some words in the post's blocks (the unsaved post).
 *
 * @param {Array<Object>} blocks Blocks.
 * @param {string}        found  Words to replace.
 * @param {string}        fix    New words.
 * @param {Function}      update updateBlockAttributes.
 * @return {boolean} Whether a block held them.
 */
export function replaceInBlocks( blocks, found, fix, update ) {
	for ( const b of blocks || [] ) {
		const a = b.attributes || {};
		for ( const k of [ 'content', 'value' ] ) {
			const raw = a[ k ];
			let html = '';
			if ( 'string' === typeof raw ) {
				html = raw;
			} else if ( raw ) {
				html = String( raw );
			}
			if ( html && html.includes( found ) ) {
				update( b.clientId, { [ k ]: html.replace( found, fix ) } );
				return true;
			}
		}
		if ( replaceInBlocks( b.innerBlocks, found, fix, update ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The post's own SEO fields (the plugin's fields route), loaded and saved together.
 *
 * @param {number} postId Post.
 * @return {Object} `{value, set, save, dirty, ready, error, busy}`.
 */
function useFields( postId ) {
	const [ loaded, setLoaded ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		if ( postId ) {
			call( `/fields/post/${ postId }` )
				.then( setLoaded )
				.catch( ( e ) => setError( errText( e ) ) );
		}
	}, [ postId ] );
	return {
		value: { ...( loaded || {} ), ...draft },
		ready: !! loaded,
		error,
		busy,
		dirty: Object.keys( draft ).length > 0,
		set: ( part ) => setDraft( { ...draft, ...part } ),
		save: () => {
			setBusy( true );
			return call( `/fields/post/${ postId }`, {
				method: 'PUT',
				data: { fields: draft },
			} )
				.then( ( f ) => {
					setLoaded( f );
					setDraft( {} );
				} )
				.catch( ( e ) => setError( errText( e ) ) )
				.finally( () => setBusy( false ) );
		},
	};
}

/**
 * The headline score of the post title, as it is typed.
 *
 * @return {Element|null} Section.
 */
function HeadlineScore() {
	const title = useSelect(
		( s ) => s( 'core/editor' ).getEditedPostAttribute( 'title' ) || '',
		[]
	);
	const [ result, setResult ] = useState( null );
	const timer = useRef( 0 );
	useEffect( () => {
		window.clearTimeout( timer.current );
		if ( ! title.trim() ) {
			setResult( null );
			return;
		}
		timer.current = window.setTimeout( () => {
			call( '/headline', {
				method: 'POST',
				data: {
					titles: [ title ],
					language: ( boot.headline || {} ).language || '',
				},
			} )
				.then( ( r ) => setResult( r.items[ 0 ] ) )
				.catch( () => {} );
		}, 800 );
		return () => window.clearTimeout( timer.current );
	}, [ title ] );
	if ( ! result ) {
		return null;
	}
	const todo = ( result.checks || [] ).filter( ( c ) => ! c.ok );
	return (
		<div className={ `zseo-ed-x1c zseo-ed-score is-${ result.grade }` }>
			<p className="zseo-ed-label">
				{ sprintf(
					/* translators: %d: a score out of 100. */
					__( 'Headline score: %d/100', 'zinn-seo' ),
					result.score
				) }
			</p>
			{ todo.length > 0 && (
				<ul className="zseo-ed-list">
					{ todo.slice( 0, 3 ).map( ( c ) => (
						<li key={ c.id }>{ c.tip }</li>
					) ) }
				</ul>
			) }
			<p className="zseo-ed-hint">
				<GuideAnchor href={ editorGuide( 'headline' ) } />
			</p>
		</div>
	);
}

/**
 * The post's keyword lists.
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Section.
 */
function KeywordLists( { postId } ) {
	const [ lists, setLists ] = useState( null );
	const [ mine, setMine ] = useState( [] );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		Promise.all( [
			call( '/keyword-lists?limit=500' ),
			call( `/keyword-lists/post/${ postId }` ),
		] )
			.then( ( [ all, own ] ) => {
				setLists( all.items || [] );
				setMine( own.lists || [] );
			} )
			.catch( ( e ) => setError( errText( e ) ) );
	}, [ postId ] );
	const toggle = ( id, on ) => {
		const next = on ? [ ...mine, id ] : mine.filter( ( x ) => x !== id );
		setMine( next );
		call( `/keyword-lists/post/${ postId }`, {
			method: 'PUT',
			data: { lists: next },
		} ).catch( ( e ) => setError( errText( e ) ) );
	};
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! lists ) {
		return <Spinner />;
	}
	return (
		<fieldset className="zseo-ed-x1c">
			<legend className="zseo-ed-label">
				{ __( 'Keyword lists', 'zinn-seo' ) }
			</legend>
			{ lists.length === 0 && (
				<p className="zseo-ed-hint">
					{ __(
						'No lists yet: make them under SEO, Writing, Keyword lists.',
						'zinn-seo'
					) }
				</p>
			) }
			{ lists.map( ( l ) => (
				<CheckboxControl
					key={ l.id }
					__nextHasNoMarginBottom
					label={ l.label }
					checked={ mine.includes( l.id ) }
					onChange={ ( on ) => toggle( l.id, on ) }
				/>
			) ) }
		</fieldset>
	);
}

/**
 * The AI writer on the selected block: rewrite, expand, shorten, simplify or continue.
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Section.
 */
function Passage( { postId } ) {
	const block = useSelect(
		( s ) => s( 'core/block-editor' ).getSelectedBlock(),
		[]
	);
	const { updateBlockAttributes, insertBlocks } =
		useDispatch( 'core/block-editor' );
	const [ mode, setMode ] = useState( 'rewrite' );
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const html =
		block && block.attributes
			? String( block.attributes.content || '' )
			: '';
	const plain = plainOf( html );
	const ask = () => {
		setBusy( true );
		setError( '' );
		call( '/ai/run', {
			method: 'POST',
			data: {
				task: 'passage',
				ids: [ postId ],
				options: { text: plain, mode },
			},
		} )
			.then( ( run ) => {
				const item = ( run.items || [] )[ 0 ] || {};
				if ( ! item.ok ) {
					throw new Error( ( item.failure || {} ).message );
				}
				setText( item.fields.text || '' );
			} )
			.catch( ( e ) => setError( errText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const use = () => {
		const paras = text
			.split( /\n\s*\n/ )
			.map( ( p ) => p.trim() )
			.filter( Boolean );
		if ( 'continue' === mode ) {
			const create =
				window.wp && window.wp.blocks && window.wp.blocks.createBlock;
			if ( create ) {
				insertBlocks(
					paras.map( ( p ) =>
						create( 'core/paragraph', { content: p } )
					)
				);
			}
		} else {
			updateBlockAttributes( block.clientId, {
				content: paras.join( '<br><br>' ),
			} );
		}
		setText( '' );
	};
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">{ __( 'AI writer', 'zinn-seo' ) }</p>
			{ ! plain ? (
				<p className="zseo-ed-hint">
					{ __(
						'Select a paragraph to rewrite, expand, shorten, simplify or continue it.',
						'zinn-seo'
					) }
				</p>
			) : (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __(
							'What to do with the selected paragraph',
							'zinn-seo'
						) }
						value={ mode }
						options={ [
							{
								value: 'rewrite',
								label: __( 'Rewrite it', 'zinn-seo' ),
							},
							{
								value: 'expand',
								label: __( 'Expand it', 'zinn-seo' ),
							},
							{
								value: 'shorten',
								label: __( 'Shorten it', 'zinn-seo' ),
							},
							{
								value: 'simplify',
								label: __( 'Simplify it', 'zinn-seo' ),
							},
							{
								value: 'continue',
								label: __(
									'Write the next paragraph',
									'zinn-seo'
								),
							},
						] }
						onChange={ setMode }
					/>
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ ask }
					>
						{ __( 'Write', 'zinn-seo' ) }
					</Button>
				</>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ text && (
				<div className="zseo-ed-box">
					<p>{ text }</p>
					<Button variant="primary" onClick={ use }>
						{ 'continue' === mode
							? __( 'Add it after', 'zinn-seo' )
							: __( 'Use it', 'zinn-seo' ) }
					</Button>{ ' ' }
					<Button variant="tertiary" onClick={ () => setText( '' ) }>
						{ __( 'Discard', 'zinn-seo' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

/**
 * Spelling, grammar and tone of the post.
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Section.
 */
function Proofread( { postId } ) {
	const blocks = useSelect(
		( s ) => s( 'core/block-editor' ).getBlocks(),
		[]
	);
	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const check = () => {
		setBusy( true );
		setError( '' );
		call( '/proofread', {
			method: 'POST',
			data: { post: postId, text: blocksText( blocks ) },
		} )
			.then( setResult )
			.catch( ( e ) => setError( errText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const fix = ( issue, to ) => {
		if (
			replaceInBlocks( blocks, issue.found, to, updateBlockAttributes )
		) {
			setResult( {
				...result,
				issues: result.issues.filter( ( i ) => i !== issue ),
			} );
		}
	};
	const n = result ? result.issues.length : 0;
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">
				{ __( 'Spelling, grammar and tone', 'zinn-seo' ) }
			</p>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ check }
			>
				{ __( 'Check the text', 'zinn-seo' ) }
			</Button>{ ' ' }
			<GuideAnchor href={ editorGuide( 'proofread' ) } />
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ result && (
				<>
					<p className="zseo-ed-hint">
						{ n
							? sprintf(
									/* translators: %d: a number of mistakes. */
									_n(
										'%d thing to look at',
										'%d things to look at',
										n,
										'zinn-seo'
									),
									n
								)
							: __( 'No mistakes found.', 'zinn-seo' ) }
					</p>
					<ul className="zseo-ed-list">
						{ result.issues.slice( 0, 50 ).map( ( i ) => (
							<li key={ `${ i.offset }-${ i.length }` }>
								<mark>{ i.found }</mark>
								{ i.message && (
									<span className="zseo-ed-hint">
										{ ' ' }
										{ i.message }
									</span>
								) }
								{ ( i.suggestions || [] )
									.slice( 0, 2 )
									.map( ( s ) => (
										<Button
											key={ s }
											variant="link"
											onClick={ () => fix( i, s ) }
										>
											{ sprintf(
												/* translators: %s: the corrected words. */
												__(
													'Change to "%s"',
													'zinn-seo'
												),
												s
											) }
										</Button>
									) ) }
							</li>
						) ) }
					</ul>
					{ result.tone && ! result.tone.error && (
						<p className="zseo-ed-hint">
							{ sprintf(
								/* translators: 1: how it sounds, 2: a score out of 100. */
								__(
									'Tone: sounds %1$s (%2$d/100 for your voice).',
									'zinn-seo'
								),
								result.tone.heard,
								result.tone.match
							) }{ ' ' }
							{ result.tone.summary }
						</p>
					) }
				</>
			) }
		</div>
	);
}

/**
 * Writing tool guidelines and the originality check (Pro).
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Section.
 */
function Guidelines( { postId } ) {
	const blocks = useSelect(
		( s ) => s( 'core/block-editor' ).getBlocks(),
		[]
	);
	const tools = ( boot.contentTools || {} ).tools || [];
	const [ g, setG ] = useState( undefined );
	const [ cover, setCover ] = useState( null );
	const [ source, setSource ] = useState( 'surfer' );
	const [ paste, setPaste ] = useState( '' );
	const [ ref, setRef ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ check, setCheck ] = useState( null );
	const [ quote, setQuote ] = useState( null );
	useEffect( () => {
		call( `/content-tools/post/${ postId }` )
			.then( ( r ) => setG( r.guidelines ) )
			.catch( () => setG( null ) );
	}, [ postId ] );
	const headings = () => {
		let h = 0;
		const walk = ( list ) =>
			( list || [] ).forEach( ( b ) => {
				if ( 'core/heading' === b.name ) {
					h++;
				}
				walk( b.innerBlocks );
			} );
		walk( blocks );
		return h;
	};
	const measure = () =>
		call( '/content-tools/coverage', {
			method: 'POST',
			data: {
				post: postId,
				text: blocksText( blocks ),
				headings: headings(),
			},
		} )
			.then( setCover )
			.catch( ( e ) => setError( errText( e ) ) );
	const tool = tools.find( ( t ) => t.id === source ) || {};
	const bring = () => {
		setBusy( 'import' );
		setError( '' );
		const data =
			tool.api && tool.hasKey && ref
				? { tool: source, ref }
				: { source, text: paste };
		call( `/content-tools/post/${ postId }`, { method: 'PUT', data } )
			.then( ( r ) => {
				setG( r.guidelines );
				setPaste( '' );
				return measure();
			} )
			.catch( ( e ) => setError( errText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const startCheck = () => {
		setBusy( 'orig' );
		setError( '' );
		call( '/content-tools/originality', {
			method: 'POST',
			data: { post: postId, text: blocksText( blocks ), count: 10 },
		} )
			.then( setCheck )
			.catch( ( e ) => setError( errText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	useEffect( () => {
		if ( ! check || 'queued' !== check.status ) {
			return;
		}
		const t = window.setTimeout(
			() =>
				call( `/content-tools/originality/${ check.id }` )
					.then( setCheck )
					.catch( () => {} ),
			5000
		);
		return () => window.clearTimeout( t );
	}, [ check ] );
	const askQuote = () =>
		call( '/content-tools/originality/quote?count=10' )
			.then( setQuote )
			.catch( ( e ) => setError( errText( e ) ) );
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">
				{ __( 'Writing guidelines', 'zinn-seo' ) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ g === undefined && <Spinner /> }
			{ g && (
				<>
					<p className="zseo-ed-hint">
						{ sprintf(
							/* translators: 1: a number of terms, 2: a tool. */
							__( '%1$d terms from %2$s.', 'zinn-seo' ),
							( g.terms || [] ).length,
							( tools.find( ( t ) => t.id === g.source ) || {} )
								.name || g.source
						) }{ ' ' }
						<Button variant="link" onClick={ measure }>
							{ __( 'Measure the text', 'zinn-seo' ) }
						</Button>
					</p>
					{ cover && (
						<>
							<p>
								<strong>
									{ sprintf(
										/* translators: 1: a score, 2: words. */
										__(
											'Score %1$d/100 · %2$d words',
											'zinn-seo'
										),
										cover.score,
										cover.words
									) }
								</strong>
							</p>
							<ul className="zseo-ed-list zseo-ed-terms">
								{ cover.terms.map( ( t ) => (
									<li
										key={ t.term }
										className={ `is-${ t.status }` }
									>
										{ t.term } { t.uses }/{ t.min }
										{ t.max > t.min ? `–${ t.max }` : '' }
									</li>
								) ) }
							</ul>
						</>
					) }
				</>
			) }
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Bring in guidelines from', 'zinn-seo' ) }
				value={ source }
				options={ [
					...tools.map( ( t ) => ( { value: t.id, label: t.name } ) ),
					{ value: 'other', label: __( 'Another tool', 'zinn-seo' ) },
				] }
				onChange={ setSource }
			/>
			{ tool.api && tool.hasKey ? (
				<TextControl
					__nextHasNoMarginBottom
					label={
						'frase' === source
							? __(
									'The search to build guidelines for',
									'zinn-seo'
								)
							: __( "The report's address or id", 'zinn-seo' )
					}
					value={ ref }
					onChange={ setRef }
				/>
			) : (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'Paste the term list or CSV the tool exported',
						'zinn-seo'
					) }
					value={ paste }
					onChange={ setPaste }
				/>
			) }
			<Button
				variant="secondary"
				isBusy={ 'import' === busy }
				disabled={ !! busy || ( ! paste && ! ref ) }
				onClick={ bring }
			>
				{ __( 'Bring them in', 'zinn-seo' ) }
			</Button>{ ' ' }
			<GuideAnchor href={ editorGuide( 'content-tools' ) } />
			<p className="zseo-ed-label">{ __( 'Originality', 'zinn-seo' ) }</p>
			<p className="zseo-ed-hint">
				{ __(
					"Searches the web for your draft's most distinctive sentences, word for word. Only those sentences are sent.",
					'zinn-seo'
				) }
			</p>
			{ ! quote && (
				<Button variant="link" onClick={ askQuote }>
					{ __( 'What does it cost?', 'zinn-seo' ) }
				</Button>
			) }
			{ quote && (
				<p className="zseo-ed-hint">
					{ sprintf(
						/* translators: %s: an amount of money. */
						__(
							'Up to %s in Zinn® credits; sentences the search cannot answer are refunded.',
							'zinn-seo'
						),
						'$' + ( quote.cost_micros / 1000000 ).toFixed( 2 )
					) }
				</p>
			) }
			<Button
				variant="secondary"
				isBusy={
					'orig' === busy || ( check && 'queued' === check.status )
				}
				disabled={ !! busy || ( check && 'queued' === check.status ) }
				onClick={ startCheck }
			>
				{ __( 'Check originality', 'zinn-seo' ) }
			</Button>{ ' ' }
			<GuideAnchor href={ editorGuide( 'originality' ) } />
			{ check && 'queued' === check.status && (
				<p className="zseo-ed-hint">
					{ __(
						'Searching… this takes a minute or two.',
						'zinn-seo'
					) }
				</p>
			) }
			{ check && 'queued' !== check.status && check.result && (
				<>
					<p>
						<strong>
							{ null === check.result.original_percent
								? __(
										'The search could not be completed; you were refunded.',
										'zinn-seo'
									)
								: sprintf(
										/* translators: %d: a percentage. */
										__( '%d%% original', 'zinn-seo' ),
										check.result.original_percent
									) }
						</strong>
					</p>
					<ul className="zseo-ed-list">
						{ ( check.result.phrases || [] )
							.filter( ( p ) => p.copies && p.copies.length )
							.map( ( p ) => (
								<li key={ p.phrase }>
									<mark>{ p.phrase }</mark>
									<br />
									{ p.copies.slice( 0, 3 ).map( ( c ) => (
										<a
											key={ c.url }
											href={ c.url }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ c.domain }{ ' ' }
										</a>
									) ) }
								</li>
							) ) }
					</ul>
				</>
			) }
		</div>
	);
}

/**
 * The AI image generator (Pro).
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Section.
 */
function Images( { postId } ) {
	const state = boot.imageGen || {};
	const { editPost } = useDispatch( 'core/editor' );
	const { insertBlocks } = useDispatch( 'core/block-editor' );
	const [ prompt, setPrompt ] = useState( '' );
	const [ size, setSize ] = useState( 'landscape' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ image, setImage ] = useState( null );
	const [ run, setRun ] = useState( '' );
	const [ model, setModel ] = useState( '' );
	const draw = ( again = '' ) => {
		setBusy( true );
		setError( '' );
		call( '/image-generator', {
			method: 'POST',
			data: { post: postId, prompt, size, run: again, model },
		} )
			.then( ( r ) => {
				if ( r.pending ) {
					setRun( r.pending );
					window.setTimeout( () => draw( r.pending ), 6000 );
					return;
				}
				setRun( '' );
				setImage( r );
				setBusy( false );
			} )
			.catch( ( e ) => {
				setRun( '' );
				setError( errText( e ) );
				setBusy( false );
			} );
	};
	if ( ! state.ownKey && ! state.credits ) {
		return (
			<div className="zseo-ed-x1c">
				<p className="zseo-ed-label">
					{ __( 'AI images', 'zinn-seo' ) }
				</p>
				<p className="zseo-ed-hint">
					{ __(
						'Add an OpenAI or Google Gemini key that can make images in the AI settings, or connect Zinn® credits.',
						'zinn-seo'
					) }
				</p>
			</div>
		);
	}
	const insert = () => {
		const create =
			window.wp && window.wp.blocks && window.wp.blocks.createBlock;
		if ( create ) {
			insertBlocks(
				create( 'core/image', {
					id: image.id,
					url: image.large || image.url,
					alt: image.alt,
					caption: image.caption,
				} )
			);
		}
	};
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">{ __( 'AI images', 'zinn-seo' ) }</p>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Describe the picture', 'zinn-seo' ) }
				value={ prompt }
				onChange={ setPrompt }
			/>
			<ModelPicker
				feature="image"
				value={ model }
				onChange={ setModel }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Shape', 'zinn-seo' ) }
				value={ size }
				options={ [
					{
						value: 'landscape',
						label: __( 'Wide (featured image)', 'zinn-seo' ),
					},
					{ value: 'square', label: __( 'Square', 'zinn-seo' ) },
					{ value: 'portrait', label: __( 'Tall', 'zinn-seo' ) },
				] }
				onChange={ setSize }
			/>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy || prompt.trim().length < 3 }
				onClick={ () => draw() }
			>
				{ __( 'Draw it', 'zinn-seo' ) }
			</Button>{ ' ' }
			<GuideAnchor href={ editorGuide( 'image-generator' ) } />
			{ run && (
				<p className="zseo-ed-hint">
					{ __(
						'Drawing… a picture can take a minute or two.',
						'zinn-seo'
					) }
				</p>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ image && (
				<div className="zseo-ed-box">
					<img
						src={ image.large || image.url }
						alt={ image.alt }
						className="zseo-ed-img"
					/>
					<p className="zseo-ed-hint">
						{ image.filename } · { image.alt }
					</p>
					<Button
						variant="primary"
						onClick={ () =>
							editPost( { featured_media: image.id } )
						}
					>
						{ __( 'Set as featured image', 'zinn-seo' ) }
					</Button>{ ' ' }
					<Button variant="secondary" onClick={ insert }>
						{ __( 'Put it in the text', 'zinn-seo' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

/**
 * Links to your other sites (Agency).
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Section.
 */
function Crosslinks( { postId } ) {
	const [ items, setItems ] = useState( null );
	const [ error, setError ] = useState( '' );
	const look = () =>
		call( `/crosslinks/post/${ postId }` )
			.then( ( r ) => setItems( r.items || [] ) )
			.catch( ( e ) => setError( errText( e ) ) );
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">
				{ __( 'Links to your other sites', 'zinn-seo' ) }
			</p>
			{ ( boot.crosslinks || {} ).footprint && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No-trace mode is on: links between sites you own are a pattern search engines look for.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<Button variant="secondary" onClick={ look }>
				{ __( 'Suggest links', 'zinn-seo' ) }
			</Button>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ items && ! items.length && (
				<p className="zseo-ed-hint">
					{ __(
						'No page on your other sites fits this post yet.',
						'zinn-seo'
					) }
				</p>
			) }
			{ items && items.length > 0 && (
				<ul className="zseo-ed-list">
					{ items.map( ( i ) => (
						<li key={ i.url }>
							<a
								href={ i.url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ i.title }
							</a>{ ' ' }
							<span className="zseo-ed-hint">
								{ i.site }
								{ i.anchor
									? ' · ' +
										sprintf(
											/* translators: %s: words in the post. */
											__( 'link from "%s"', 'zinn-seo' ),
											i.anchor
										)
									: '' }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * The affiliate links this post uses (Pro).
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element|null} Section.
 */
function Affiliates( { postId } ) {
	const [ links, setLinks ] = useState( null );
	useEffect( () => {
		call( `/affiliates/post/${ postId }` )
			.then( ( r ) => setLinks( r.links || [] ) )
			.catch( () => setLinks( [] ) );
	}, [ postId ] );
	if ( ! links || ! links.length ) {
		return null;
	}
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">
				{ __( 'Affiliate links in this post', 'zinn-seo' ) }
			</p>
			<ul className="zseo-ed-list">
				{ links.map( ( l ) => (
					<li key={ l.id }>
						{ l.label } <code dir="ltr">{ l.short }</code>{ ' ' }
						{ 'broken' === l.problem && (
							<strong>{ __( 'broken', 'zinn-seo' ) }</strong>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The writing panel.
 *
 * @return {Element} Panel body.
 */
export function WritingPanel() {
	const postId = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostId(),
		[]
	);
	if ( ! postId ) {
		return <Spinner />;
	}
	return (
		<div className="zseo-ed-x1c-panel">
			{ boot.headline && <HeadlineScore /> }
			{ boot.kwlists && <KeywordLists postId={ postId } /> }
			{ boot.writer && boot.aiState && boot.aiState.ready && (
				<Passage postId={ postId } />
			) }
			{ boot.copywriter && <CopywriterPanel postId={ postId } /> }
			{ boot.proofread && <Proofread postId={ postId } /> }
			{ boot.contentTools && <Guidelines postId={ postId } /> }
			{ boot.imageGen && <Images postId={ postId } /> }
			{ boot.crosslinks && <Crosslinks postId={ postId } /> }
			{ boot.affiliates && <Affiliates postId={ postId } /> }
		</div>
	);
}

/**
 * A story's news fields (Pro).
 *
 * @return {Element|null} Panel body.
 */
export function NewsPanel() {
	const postId = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostId(),
		[]
	);
	const f = useFields( postId );
	if ( f.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ f.error }
			</Notice>
		);
	}
	if ( ! f.ready ) {
		return <Spinner />;
	}
	const kinds = ( boot.news || {} ).kinds || [];
	const label = {
		NewsArticle: __( 'News', 'zinn-seo' ),
		ReportageNewsArticle: __( 'Reportage', 'zinn-seo' ),
		AnalysisNewsArticle: __( 'Analysis', 'zinn-seo' ),
		OpinionNewsArticle: __( 'Opinion', 'zinn-seo' ),
		ReviewNewsArticle: __( 'Review', 'zinn-seo' ),
		BackgroundNewsArticle: __( 'Background', 'zinn-seo' ),
		LiveBlogPosting: __( 'Live blog', 'zinn-seo' ),
	};
	return (
		<div className="zseo-ed-x1c-panel">
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Kind of story', 'zinn-seo' ) }
				value={ f.value.news_type || '' }
				options={ [
					{ value: '', label: __( 'News (default)', 'zinn-seo' ) },
					...kinds
						.filter( ( k ) => 'NewsArticle' !== k )
						.map( ( k ) => ( {
							value: k,
							label: label[ k ] || k,
						} ) ),
				] }
				onChange={ ( v ) => f.set( { news_type: v } ) }
			/>
			{ 'LiveBlogPosting' === f.value.news_type && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						type="datetime-local"
						label={ __( 'Live since', 'zinn-seo' ) }
						value={ f.value.live_start || '' }
						onChange={ ( v ) => f.set( { live_start: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="datetime-local"
						label={ __(
							'Live until (empty while live)',
							'zinn-seo'
						) }
						value={ f.value.live_end || '' }
						onChange={ ( v ) => f.set( { live_end: v } ) }
					/>
					<p className="zseo-ed-hint">
						{ __(
							'Each heading that starts with a time ("10:45 …") is one live update.',
							'zinn-seo'
						) }
					</p>
				</>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Keep out of Google News', 'zinn-seo' ) }
				help={ __( 'It stays in normal search.', 'zinn-seo' ) }
				checked={ !! f.value.news_exclude }
				onChange={ ( v ) => f.set( { news_exclude: v } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Stock tickers', 'zinn-seo' ) }
				help={ __(
					'As EXCHANGE:SYMBOL, separated by commas (NASDAQ:AAPL).',
					'zinn-seo'
				) }
				value={ f.value.news_tickers || '' }
				onChange={ ( v ) => f.set( { news_tickers: v } ) }
			/>
			<Button
				variant="primary"
				isBusy={ f.busy }
				disabled={ f.busy || ! f.dirty }
				onClick={ f.save }
			>
				{ __( 'Save', 'zinn-seo' ) }
			</Button>{ ' ' }
			<GuideAnchor href={ editorGuide( 'news' ) } />
		</div>
	);
}

/**
 * A page's dataset and math solver (Pro).
 *
 * @return {Element} Panel body.
 */
export function DatasetPanel() {
	const postId = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostId(),
		[]
	);
	const f = useFields( postId );
	if ( f.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ f.error }
			</Notice>
		);
	}
	if ( ! f.ready ) {
		return <Spinner />;
	}
	const parse = ( s ) => {
		try {
			const v = JSON.parse( s || '{}' );
			return v && 'object' === typeof v ? v : {};
		} catch {
			return {};
		}
	};
	const d = parse( f.value.dataset );
	const m = parse( f.value.math_solver );
	const setD = ( part ) =>
		f.set( { dataset: JSON.stringify( { ...d, ...part } ) } );
	const setM = ( part ) =>
		f.set( { math_solver: JSON.stringify( { ...m, ...part } ) } );
	const text = ( name, lbl, help ) => (
		<TextControl
			__nextHasNoMarginBottom
			label={ lbl }
			help={ help }
			value={ d[ name ] || '' }
			onChange={ ( v ) => setD( { [ name ]: v } ) }
		/>
	);
	const downloads = d.downloads || [];
	return (
		<div className="zseo-ed-x1c-panel">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'This page describes a dataset', 'zinn-seo' ) }
				checked={ !! d.on }
				onChange={ ( v ) => setD( { on: v } ) }
			/>
			{ d.on && (
				<>
					{ text(
						'name',
						__( 'Name (empty: the title)', 'zinn-seo' )
					) }
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Description (50 to 5,000 characters; empty: the excerpt)',
							'zinn-seo'
						) }
						value={ d.description || '' }
						onChange={ ( v ) => setD( { description: v } ) }
					/>
					{ text( 'license', __( 'Licence address', 'zinn-seo' ) ) }
					{ text( 'creator', __( 'Creator', 'zinn-seo' ) ) }
					{ text(
						'identifier',
						__( 'Identifier (e.g. a DOI)', 'zinn-seo' )
					) }
					{ text(
						'keywords',
						__( 'Keywords, separated by commas', 'zinn-seo' )
					) }
					{ text(
						'temporal',
						__( 'Period covered (e.g. 2020/2025)', 'zinn-seo' )
					) }
					{ text( 'spatial', __( 'Place covered', 'zinn-seo' ) ) }
					{ text(
						'variables',
						__(
							'What it measures, separated by commas',
							'zinn-seo'
						)
					) }
					{ downloads.map( ( dl, i ) => (
						<TextControl
							key={ i }
							__nextHasNoMarginBottom
							type="url"
							label={ sprintf(
								/* translators: %d: a number. */
								__( 'Download %d', 'zinn-seo' ),
								i + 1
							) }
							value={ dl.url || '' }
							onChange={ ( v ) =>
								setD( {
									downloads: downloads
										.map( ( x, j ) =>
											j === i ? { ...x, url: v } : x
										)
										.filter( ( x ) => x.url ),
								} )
							}
						/>
					) ) }
					<Button
						variant="link"
						onClick={ () =>
							setD( {
								downloads: [
									...downloads,
									{ url: 'https://' },
								],
							} )
						}
					>
						{ __( 'Add a download', 'zinn-seo' ) }
					</Button>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Also offer the data files attached to this page',
							'zinn-seo'
						) }
						checked={ false !== d.files }
						onChange={ ( v ) => setD( { files: v } ) }
					/>
				</>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'This page solves maths problems', 'zinn-seo' ) }
				checked={ !! m.on }
				onChange={ ( v ) => setM( { on: v } ) }
			/>
			{ m.on && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						type="url"
						label={ __( 'Solver address', 'zinn-seo' ) }
						help={ __(
							'Where the problem goes, as {math_expression_string}: https://example.com/solve?q={math_expression_string}',
							'zinn-seo'
						) }
						value={ m.target || '' }
						onChange={ ( v ) => setM( { target: v } ) }
					/>
					{ ( ( boot.dataset || {} ).mathTypes || [] ).map( ( t ) => (
						<CheckboxControl
							key={ t }
							__nextHasNoMarginBottom
							label={ t }
							checked={ ( m.types || [] ).includes( t ) }
							onChange={ ( v ) =>
								setM( {
									types: v
										? [ ...( m.types || [] ), t ]
										: ( m.types || [] ).filter(
												( x ) => x !== t
											),
								} )
							}
						/>
					) ) }
				</>
			) }
			<Button
				variant="primary"
				isBusy={ f.busy }
				disabled={ f.busy || ! f.dirty }
				onClick={ f.save }
			>
				{ __( 'Save', 'zinn-seo' ) }
			</Button>{ ' ' }
			<GuideAnchor href={ editorGuide( 'dataset' ) } />
		</div>
	);
}

/**
 * "Show as FAQ in search results" on accordion blocks (Pro, zs-712).
 *
 * @return {void}
 */
export function registerAccordionFaq() {
	const names = boot.accordionFaq || [];
	const wp = window.wp || {};
	if ( ! names.length || ! wp.hooks || ! wp.compose || ! wp.blockEditor ) {
		return;
	}
	const { InspectorControls } = wp.blockEditor;
	wp.hooks.addFilter(
		'blocks.registerBlockType',
		'zinn-seo/accordion-faq-attribute',
		( settings, name ) =>
			names.includes( name )
				? {
						...settings,
						attributes: {
							...settings.attributes,
							zinnSeoFaq: { type: 'boolean', default: false },
						},
					}
				: settings
	);
	wp.hooks.addFilter(
		'editor.BlockEdit',
		'zinn-seo/accordion-faq-control',
		wp.compose.createHigherOrderComponent(
			( BlockEdit ) => ( props ) => {
				if ( ! names.includes( props.name ) ) {
					return <BlockEdit { ...props } />;
				}
				return (
					<>
						<BlockEdit { ...props } />
						<InspectorControls>
							<div className="zseo-ed-x1c-faq">
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Show as FAQ in search results',
										'zinn-seo'
									) }
									help={ __(
										"Each item's title is a question and its text the answer, in the page's structured data.",
										'zinn-seo'
									) }
									checked={ !! props.attributes.zinnSeoFaq }
									onChange={ ( v ) =>
										props.setAttributes( { zinnSeoFaq: v } )
									}
								/>
							</div>
						</InspectorControls>
					</>
				);
			},
			'withZinnSeoFaq'
		)
	);
}

/**
 * Register the panels this site's features call for.
 *
 * @param {Function} Panel PluginDocumentSettingPanel.
 * @return {void}
 */
export default function registerX1cPanels( Panel ) {
	const wp = window.wp || {};
	if (
		boot.headline ||
		boot.kwlists ||
		boot.writer ||
		boot.copywriter ||
		boot.proofread ||
		boot.contentTools ||
		boot.imageGen ||
		boot.crosslinks ||
		boot.affiliates
	) {
		wp.plugins.registerPlugin( 'zinn-seo-writing', {
			render: () => (
				<Panel
					name="zinn-seo-writing"
					title={ __( 'Writing help', 'zinn-seo' ) }
					className="zseo-ed-panel"
				>
					<WritingPanel />
				</Panel>
			),
			icon: null,
		} );
	}
	if ( boot.news ) {
		wp.plugins.registerPlugin( 'zinn-seo-news', {
			render: () => {
				const NewsGate = () => {
					const type = useSelect(
						( s ) => s( 'core/editor' ).getCurrentPostType(),
						[]
					);
					return ( boot.news.types || [] ).includes( type ) ? (
						<Panel
							name="zinn-seo-news"
							title={ __( 'News', 'zinn-seo' ) }
							className="zseo-ed-panel"
						>
							<NewsPanel />
						</Panel>
					) : null;
				};
				return <NewsGate />;
			},
			icon: null,
		} );
	}
	if ( boot.dataset ) {
		wp.plugins.registerPlugin( 'zinn-seo-dataset', {
			render: () => (
				<Panel
					name="zinn-seo-dataset"
					title={ __( 'Dataset and math solver', 'zinn-seo' ) }
					className="zseo-ed-panel"
				>
					<DatasetPanel />
				</Panel>
			),
			icon: null,
		} );
	}
	registerAccordionFaq();
}
