/**
 * The AI copywriter (zs-782): pick a tool (or a ready-made prompt), say what to write, choose the
 * language and how many versions, run it, compare the versions with their checks, then use one:
 * insert it, copy it, keep it in the library, or (an SEO title or description) test two in Google.
 *
 * Used by the admin screen (Writing → Copywriter), the block editor's panel and Elementor.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import {
	checkLines,
	fillPrompt,
	filterTools,
	groups,
	inputFields,
	languageName,
	openSlots,
} from './shared';

const base = '/zinn-seo/v1';

/**
 * Words of an error answer.
 *
 * @param {*} e Error.
 * @return {string} Words.
 */
function errorWords( e ) {
	return (
		( e && e.message ) ||
		__( 'Something went wrong. Try again.', 'zinn-seo' )
	);
}

/**
 * The tool.
 *
 * @param {Object}   props
 * @param {Object}   props.data        GET /copywriter.
 * @param {number}   props.postId      The page it writes for (0 none).
 * @param {Object}   props.page        {title, phrase} of that page, for the prompt library.
 * @param {Function} props.onInsert    Insert a variant into the page (null: no such button).
 * @param {Function} props.onSeo       Use a variant as the page's SEO title or description (null: none).
 * @param {string}   props.initialTool Tool to start with.
 * @param {string}   props.initialText Text to start with (the selected block).
 * @param {Function} props.onSaved     Called after something was kept in the library.
 * @return {Element} Tool.
 */
export default function CopywriterTool( {
	data,
	postId = 0,
	page = {},
	onInsert = null,
	onSeo = null,
	initialTool = '',
	initialText = '',
	onSaved = null,
} ) {
	const tools = useMemo( () => data.tools || [], [ data.tools ] );
	const [ group, setGroup ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ toolId, setToolId ] = useState( initialTool || 'blog-ideas' );
	const [ inputs, setInputs ] = useState(
		initialText ? { text: initialText } : {}
	);
	const [ language, setLanguage ] = useState( '' );
	const [ variants, setVariants ] = useState( 2 );
	const [ tone, setTone ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ note, setNote ] = useState( '' );
	const [ promptGroup, setPromptGroup ] = useState( '' );
	useEffect( () => {
		if ( initialText ) {
			setInputs( ( i ) => ( { ...i, text: initialText } ) );
		}
	}, [ initialText ] );
	const tool = tools.find( ( t ) => t.id === toolId ) || tools[ 0 ] || {};
	const shown = useMemo(
		() => filterTools( tools, group, search ),
		[ tools, group, search ]
	);
	const fields = inputFields();
	const screen = data.language || 'en_US';
	const run = () => {
		setBusy( true );
		setError( '' );
		setNote( '' );
		apiFetch( {
			path: `${ base }/copywriter/run`,
			method: 'POST',
			data: {
				tool: tool.id,
				inputs,
				post: postId,
				language,
				variants,
				tone,
			},
		} )
			.then( setResult )
			.catch( ( e ) => setError( errorWords( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const keep = ( index ) =>
		apiFetch( {
			path: `${ base }/copywriter/library`,
			method: 'POST',
			data: { entry: result.entry, variant: index },
		} )
			.then( () => {
				setNote( __( 'Kept in your library.', 'zinn-seo' ) );
				if ( onSaved ) {
					onSaved();
				}
			} )
			.catch( ( e ) => setError( errorWords( e ) ) );
	const copy = ( text ) => {
		const done = () => setNote( __( 'Copied.', 'zinn-seo' ) );
		if ( window.navigator && window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text ).then( done, () => {} );
		}
	};
	const abTest = ( text ) => {
		const field = tool.ab === 'desc' ? 'desc' : 'title';
		apiFetch( {
			path: `${ base }/search/tests`,
			method: 'POST',
			data: {
				name: sprintf(
					/* translators: %s: a page title. */
					__( 'AI copywriter: %s', 'zinn-seo' ),
					page.title || String( postId )
				),
				pages: [ { post_id: postId, [ field ]: text } ],
			},
		} )
			.then( () =>
				setNote(
					__(
						'The A/B test has started: Google is shown your current one and this version in turns. See Search → Tests for which gets more clicks.',
						'zinn-seo'
					)
				)
			)
			.catch( ( e ) => setError( errorWords( e ) ) );
	};
	const prompts = data.prompts || {};
	const promptNames = {
		blog: __( 'Blog', 'zinn-seo' ),
		seo: __( 'SEO', 'zinn-seo' ),
		shop: __( 'Shop', 'zinn-seo' ),
		social: __( 'Social media', 'zinn-seo' ),
		email: __( 'E-mail', 'zinn-seo' ),
		business: __( 'Business pages', 'zinn-seo' ),
		local: __( 'Local business', 'zinn-seo' ),
		strategy: __( 'Ideas and strategy', 'zinn-seo' ),
		editing: __( 'Editing', 'zinn-seo' ),
	};
	const applyPrompt = ( p ) => {
		setToolId( 'freeform' );
		setInputs( {
			topic: fillPrompt( p, {
				phrase: page.phrase,
				title: page.title,
				site: ( data.brand || {} ).name,
			} ),
		} );
	};
	const slots = openSlots( inputs.topic || '' );
	return (
		<div className="zseo-cw">
			<div className="zseo-cw__pick">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Kind of writing', 'zinn-seo' ) }
					value={ group }
					options={ [
						{ value: '', label: __( 'Everything', 'zinn-seo' ) },
						...groups()
							.filter( ( g ) =>
								tools.some( ( t ) => t.group === g.id )
							)
							.map( ( g ) => ( {
								value: g.id,
								label: g.label,
							} ) ),
					] }
					onChange={ setGroup }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Find a tool', 'zinn-seo' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Tool', 'zinn-seo' ) }
					value={ tool.id }
					options={ shown.map( ( t ) => ( {
						value: t.id,
						label: t.label,
					} ) ) }
					onChange={ ( id ) => {
						setToolId( id );
						setResult( null );
					} }
				/>
			</div>
			{ tool.id === 'freeform' && Object.keys( prompts ).length > 0 && (
				<details className="zseo-cw__prompts">
					<summary>
						{ __( 'Start from a ready-made prompt', 'zinn-seo' ) }
					</summary>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Prompt library', 'zinn-seo' ) }
						value={ promptGroup }
						options={ [
							{
								value: '',
								label: __( 'Choose a kind', 'zinn-seo' ),
							},
							...Object.keys( prompts ).map( ( k ) => ( {
								value: k,
								label: promptNames[ k ] || k,
							} ) ),
						] }
						onChange={ setPromptGroup }
					/>
					<ul className="zseo-cw__prompt-list">
						{ ( prompts[ promptGroup ] || [] ).map( ( p, i ) => (
							<li key={ i }>
								<Button
									variant="link"
									onClick={ () => applyPrompt( p ) }
								>
									{ fillPrompt( p, {
										phrase: page.phrase,
										title: page.title,
										site: ( data.brand || {} ).name,
									} ) }
								</Button>
							</li>
						) ) }
					</ul>
				</details>
			) }
			{ ( tool.inputs || [] ).map( ( k ) => {
				const f = fields[ k ] || { label: k, help: '', long: false };
				const Control = f.long ? TextareaControl : TextControl;
				return (
					<Control
						key={ k }
						__nextHasNoMarginBottom
						label={ f.label }
						help={ f.help }
						value={ inputs[ k ] || '' }
						onChange={ ( v ) =>
							setInputs( { ...inputs, [ k ]: v } )
						}
					/>
				);
			} ) }
			{ slots.length > 0 && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %s: words in braces still to replace. */
						__(
							'Replace the words in braces first: %s',
							'zinn-seo'
						),
						slots.map( ( s ) => `{${ s }}` ).join( ', ' )
					) }
				</p>
			) }
			<div className="zseo-cw__opts">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Write it in', 'zinn-seo' ) }
					value={ language }
					options={ [
						{
							value: '',
							label: postId
								? __( 'The language of this page', 'zinn-seo' )
								: __( 'The language of your site', 'zinn-seo' ),
						},
						...( data.languages || [] )
							.map( ( c ) => ( {
								value: c,
								label: languageName( c, screen ),
							} ) )
							.sort( ( a, b ) =>
								a.label.localeCompare( b.label )
							),
					] }
					onChange={ setLanguage }
				/>
				<RangeControl
					__nextHasNoMarginBottom
					label={ __( 'Versions to compare', 'zinn-seo' ) }
					value={ variants }
					min={ 1 }
					max={ 5 }
					onChange={ ( n ) => setVariants( n || 1 ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Tone of voice', 'zinn-seo' ) }
					value={ tone }
					options={ [
						{
							value: '',
							label: __( "Your site's own tone", 'zinn-seo' ),
						},
						{
							value: 'friendly',
							label: __( 'Friendly', 'zinn-seo' ),
						},
						{
							value: 'professional',
							label: __( 'Professional', 'zinn-seo' ),
						},
						{ value: 'expert', label: __( 'Expert', 'zinn-seo' ) },
						{ value: 'casual', label: __( 'Casual', 'zinn-seo' ) },
						{
							value: 'persuasive',
							label: __( 'Persuasive', 'zinn-seo' ),
						},
						{
							value: 'playful',
							label: __( 'Playful', 'zinn-seo' ),
						},
						{ value: 'formal', label: __( 'Formal', 'zinn-seo' ) },
					] }
					onChange={ setTone }
				/>
			</div>
			<Button
				variant="primary"
				isBusy={ busy }
				disabled={ busy || slots.length > 0 }
				onClick={ run }
			>
				{ __( 'Write', 'zinn-seo' ) }
			</Button>
			{ postId > 0 && (
				<p className="zseo-muted">
					{ __(
						'It writes for this page: its focus phrase, its research and your brand.',
						'zinn-seo'
					) }
				</p>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ note && (
				<Notice status="success" onRemove={ () => setNote( '' ) }>
					{ note }
				</Notice>
			) }
			{ result && (
				<div className="zseo-cw__results">
					<p className="zseo-muted">
						{ result.credits
							? sprintf(
									/* translators: %s: an amount in US dollars. */
									__(
										'Paid with your Zinn® credit: $%s.',
										'zinn-seo'
									),
									(
										( result.cost_micros || 0 ) / 1e6
									).toFixed( 4 )
								)
							: __( 'Paid on your own AI key.', 'zinn-seo' ) }
					</p>
					{ ( result.variants || [] ).map( ( v, i ) => (
						<div className="zseo-cw__variant" key={ i }>
							<p className="zseo-cw__variant-label">
								{ sprintf(
									/* translators: %d: a version number. */
									__( 'Version %d', 'zinn-seo' ),
									i + 1
								) }
							</p>
							<div className="zseo-cw__text">{ v.text }</div>
							<ul className="zseo-cw__check">
								{ checkLines( v.check ).map( ( c, n ) => (
									<li
										key={ n }
										className={ `is-${ c.level }` }
									>
										{ c.text }
									</li>
								) ) }
							</ul>
							<div className="zseo-cw__actions">
								{ onInsert && (
									<Button
										variant="primary"
										onClick={ () =>
											onInsert( v.text, tool )
										}
									>
										{ __( 'Insert', 'zinn-seo' ) }
									</Button>
								) }
								{ onSeo && tool.ab && (
									<Button
										variant="secondary"
										onClick={ () =>
											onSeo( tool.ab, v.text )
										}
									>
										{ tool.ab === 'desc'
											? __(
													'Use as SEO description',
													'zinn-seo'
												)
											: __(
													'Use as SEO title',
													'zinn-seo'
												) }
									</Button>
								) }
								<Button
									variant="secondary"
									onClick={ () => copy( v.text ) }
								>
									{ __( 'Copy', 'zinn-seo' ) }
								</Button>
								{ tool.ab && postId > 0 && data.abtest && (
									<Button
										variant="secondary"
										onClick={ () => abTest( v.text ) }
									>
										{ __(
											'A/B test it against the current one in Google',
											'zinn-seo'
										) }
									</Button>
								) }
								<Button
									variant="tertiary"
									onClick={ () => keep( i ) }
								>
									{ __( 'Keep in library', 'zinn-seo' ) }
								</Button>
							</div>
						</div>
					) ) }
					<p className="zseo-muted">
						{ sprintf(
							/* translators: %d: versions. */
							_n(
								'%d version, kept in your history.',
								'%d versions, kept in your history.',
								( result.variants || [] ).length,
								'zinn-seo'
							),
							( result.variants || [] ).length
						) }
					</p>
				</div>
			) }
		</div>
	);
}
