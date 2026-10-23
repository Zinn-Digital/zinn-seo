/**
 * Writing → Copywriter (zs-782, Pro): write, the library, the history, the templates (an SEO
 * manager edits a tool's instructions or adds tools of their own) and runs over many pages.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	RangeControl,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import CopywriterTool from './CopywriterTool';
import { checkLines, groups, languageName } from './shared';
import './copywriter.scss';

const base = '/zinn-seo/v1';

/**
 * Saved pieces or the history, newest first, with search and "more".
 *
 * @param {Object} props
 * @param {string} props.kind  saved | history.
 * @param {number} props.stamp Changes when something was added.
 * @return {Element} List.
 */
function Pieces( { kind, stamp } ) {
	const [ rows, setRows ] = useState( null );
	const [ next, setNext ] = useState( 0 );
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const load = useCallback(
		( before = 0 ) =>
			apiFetch( {
				path: `${ base }/copywriter/library?kind=${ kind }&before=${ before }&search=${ encodeURIComponent( search ) }`,
			} )
				.then( ( r ) => {
					setRows( ( old ) =>
						before ? [ ...( old || [] ), ...r.items ] : r.items
					);
					setNext( r.next );
				} )
				.catch( ( e ) => setError( e.message || '' ) ),
		[ kind, search ]
	);
	useEffect( () => {
		load( 0 );
	}, [ load, stamp ] );
	const remove = ( id ) =>
		apiFetch( {
			path: `${ base }/copywriter/library/${ id }`,
			method: 'DELETE',
		} )
			.then( () => setRows( ( r ) => r.filter( ( x ) => x.id !== id ) ) )
			.catch( ( e ) => setError( e.message || '' ) );
	const keep = ( id ) =>
		apiFetch( {
			path: `${ base }/copywriter/library`,
			method: 'POST',
			data: { entry: id },
		} ).catch( ( e ) => setError( e.message || '' ) );
	if ( null === rows ) {
		return <Spinner />;
	}
	return (
		<div className="zseo-cw-pieces">
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Search', 'zinn-seo' ) }
				value={ search }
				onChange={ setSearch }
			/>
			{ error && <Notice status="error">{ error }</Notice> }
			{ rows.length === 0 && (
				<p className="zseo-muted">
					{ 'saved' === kind
						? __(
								'Nothing kept yet. Press "Keep in library" on a version you like.',
								'zinn-seo'
							)
						: __( 'Nothing written yet.', 'zinn-seo' ) }
				</p>
			) }
			{ rows.map( ( r ) => (
				<div className="zseo-cw__variant" key={ r.id }>
					<p className="zseo-cw__variant-label">
						{ r.title }
						{ r.postTitle ? ` — ${ r.postTitle }` : '' }
						{ ' · ' }
						{ r.at }
					</p>
					{ ( r.variants || [] ).map( ( v, i ) => (
						<div key={ i }>
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
						</div>
					) ) }
					<div className="zseo-cw__actions">
						<Button
							variant="secondary"
							onClick={ () =>
								window.navigator.clipboard &&
								window.navigator.clipboard.writeText(
									( r.variants || [] )
										.map( ( v ) => v.text )
										.join( '\n\n' )
								)
							}
						>
							{ __( 'Copy', 'zinn-seo' ) }
						</Button>
						{ 'history' === kind && (
							<Button
								variant="tertiary"
								onClick={ () => keep( r.id ) }
							>
								{ __( 'Keep in library', 'zinn-seo' ) }
							</Button>
						) }
						<Button
							variant="link"
							isDestructive
							onClick={ () => remove( r.id ) }
						>
							{ __( 'Delete', 'zinn-seo' ) }
						</Button>
					</div>
				</div>
			) ) }
			{ next > 0 && (
				<Button variant="secondary" onClick={ () => load( next ) }>
					{ __( 'Show more', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * The templates: a tool's instructions, edited (or put back), and tools of your own.
 *
 * @param {Object}   props
 * @param {Object}   props.data   GET /copywriter.
 * @param {Function} props.reload Reload the data.
 * @return {Element} Editor.
 */
function Templates( { data, reload } ) {
	const [ id, setId ] = useState( '' );
	const [ brief, setBrief ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ fresh, setFresh ] = useState( '' );
	const [ msg, setMsg ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const tool = ( data.tools || [] ).find( ( t ) => t.id === id );
	useEffect( () => {
		setBrief( tool ? tool.brief : '' );
	}, [ tool ] );
	const save = ( target, body ) =>
		apiFetch( {
			path: `${ base }/copywriter/templates/${ target }`,
			method: 'PUT',
			data: body,
		} )
			.then( () => {
				setMsg( __( 'Saved.', 'zinn-seo' ) );
				reload();
			} )
			.catch( ( e ) => setError( e.message || '' ) );
	if ( ! data.canEdit ) {
		return (
			<p className="zseo-muted">
				{ __(
					'An SEO manager can change how the tools write.',
					'zinn-seo'
				) }
			</p>
		);
	}
	return (
		<div className="zseo-cw-templates">
			{ msg && (
				<Notice status="success" onRemove={ () => setMsg( '' ) }>
					{ msg }
				</Notice>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Tool', 'zinn-seo' ) }
				value={ id }
				options={ [
					{ value: '', label: __( 'Choose a tool', 'zinn-seo' ) },
					...( data.tools || [] ).map( ( t ) => ( {
						value: t.id,
						label: t.edited
							? sprintf(
									/* translators: %s: a tool's name. */ __(
										'%s (changed)',
										'zinn-seo'
									),
									t.label
								)
							: t.label,
					} ) ),
				] }
				onChange={ setId }
			/>
			{ tool && (
				<>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'How it writes (the instructions the AI gets)',
							'zinn-seo'
						) }
						value={ brief }
						rows={ 6 }
						onChange={ setBrief }
					/>
					<Button
						variant="primary"
						onClick={ () => save( tool.id, { brief } ) }
					>
						{ __( 'Save', 'zinn-seo' ) }
					</Button>{ ' ' }
					{ ( tool.edited || tool.own ) && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => save( tool.id, { brief: '' } ) }
						>
							{ tool.own
								? __( 'Delete this tool', 'zinn-seo' )
								: __( 'Put the original back', 'zinn-seo' ) }
						</Button>
					) }
				</>
			) }
			<h3>{ __( 'A tool of your own', 'zinn-seo' ) }</h3>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Name', 'zinn-seo' ) }
				value={ label }
				onChange={ setLabel }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'What it writes, and how', 'zinn-seo' ) }
				value={ fresh }
				rows={ 5 }
				onChange={ setFresh }
			/>
			<Button
				variant="secondary"
				disabled={ ! label || ! fresh }
				onClick={ () =>
					save(
						'own-' +
							label
								.toLowerCase()
								.replace( /[^a-z0-9]+/g, '-' )
								.slice( 0, 30 ),
						{
							label,
							brief: fresh,
						}
					).then( () => {
						setLabel( '' );
						setFresh( '' );
					} )
				}
			>
				{ __( 'Add the tool', 'zinn-seo' ) }
			</Button>
		</div>
	);
}

/**
 * One tool over many pages, in the background; the versions go to the library.
 *
 * @param {Object}   props
 * @param {Object}   props.data   GET /copywriter.
 * @param {Function} props.reload Reload the data.
 * @return {Element} Form.
 */
function Bulk( { data, reload } ) {
	const [ tool, setTool ] = useState( 'seo-description' );
	const [ type, setType ] = useState( 'post' );
	const [ language, setLanguage ] = useState( '' );
	const [ variants, setVariants ] = useState( 1 );
	const [ dry, setDry ] = useState( null );
	const [ error, setError ] = useState( '' );
	const job = data.bulk || {};
	const spec = { tool, type, language, variants };
	const ask = ( isDry ) =>
		apiFetch( {
			path: `${ base }/copywriter/bulk`,
			method: 'POST',
			data: { ...spec, dry: isDry },
		} )
			.then( ( r ) => {
				if ( isDry ) {
					setDry( r );
				} else {
					setDry( null );
					reload();
				}
			} )
			.catch( ( e ) => setError( e.message || '' ) );
	return (
		<div className="zseo-cw-bulk">
			<p className="zseo-muted">
				{ __(
					'Writes with one tool for every published page of a kind, in the background. Nothing is changed on the pages: every version waits in your library, written for its page.',
					'zinn-seo'
				) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Tool', 'zinn-seo' ) }
				value={ tool }
				options={ ( data.tools || [] ).map( ( t ) => ( {
					value: t.id,
					label: t.label,
				} ) ) }
				onChange={ setTool }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Pages', 'zinn-seo' ) }
				value={ type }
				options={ ( data.types || [] ).map( ( t ) => ( {
					value: t.name,
					label: t.label,
				} ) ) }
				onChange={ setType }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Write it in', 'zinn-seo' ) }
				value={ language }
				options={ [
					{
						value: '',
						label: __( "Each page's own language", 'zinn-seo' ),
					},
					...( data.languages || [] ).map( ( c ) => ( {
						value: c,
						label: languageName( c, data.language ),
					} ) ),
				] }
				onChange={ setLanguage }
			/>
			<RangeControl
				__nextHasNoMarginBottom
				label={ __( 'Versions per page', 'zinn-seo' ) }
				value={ variants }
				min={ 1 }
				max={ 5 }
				onChange={ ( n ) => setVariants( n || 1 ) }
			/>
			<Button variant="secondary" onClick={ () => ask( true ) }>
				{ __( 'How many pages?', 'zinn-seo' ) }
			</Button>
			{ dry && (
				<p>
					{ sprintf(
						/* translators: %d: pages. */
						__(
							'%d pages. Each is one AI request, paid from your credit or your own key; the price is shown in your credit history.',
							'zinn-seo'
						),
						dry.pages
					) }{ ' ' }
					<Button variant="primary" onClick={ () => ask( false ) }>
						{ __( 'Start', 'zinn-seo' ) }
					</Button>
				</p>
			) }
			{ job.status && job.status !== 'idle' && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: 1: job status, 2: pages done, 3: pages in all. */
						__( 'Last run: %1$s, %2$d of %3$d pages.', 'zinn-seo' ),
						job.status,
						( job.progress || {} ).done || 0,
						( job.progress || {} ).total || 0
					) }
					{ job.paused_note ? ` ${ job.paused_note }` : '' }
				</p>
			) }
		</div>
	);
}

/**
 * The tab.
 *
 * @return {Element} Tab.
 */
export default function CopywriterTab() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ stamp, setStamp ] = useState( 0 );
	const reload = useCallback(
		() =>
			apiFetch( { path: `${ base }/copywriter` } )
				.then( setData )
				.catch( ( e ) => setError( e.message || '' ) ),
		[]
	);
	useEffect( () => {
		reload();
	}, [ reload ] );
	if ( error ) {
		return <Notice status="error">{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	const c = data.count || {};
	return (
		<div className="zseo-cw-screen">
			<p className="zseo-muted">
				{ sprintf(
					/* translators: 1: writing tools, 2: ready-made prompts. */
					__(
						"%1$d writing tools and %2$d ready-made prompts, in every language of your site. Each run uses your page's own SEO research when you open it from a page.",
						'zinn-seo'
					),
					c.tools +
						( data.tools || [] ).filter( ( t ) => t.own ).length,
					c.prompts
				) }
			</p>
			<TabPanel
				className="zseo-tabs"
				tabs={ [
					{ name: 'write', title: __( 'Write', 'zinn-seo' ) },
					{ name: 'saved', title: __( 'Library', 'zinn-seo' ) },
					{ name: 'history', title: __( 'History', 'zinn-seo' ) },
					{ name: 'templates', title: __( 'Templates', 'zinn-seo' ) },
					{ name: 'bulk', title: __( 'Many pages', 'zinn-seo' ) },
				] }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'write':
							return (
								<CopywriterTool
									data={ data }
									onSaved={ () => setStamp( ( s ) => s + 1 ) }
								/>
							);
						case 'saved':
						case 'history':
							return <Pieces kind={ tab.name } stamp={ stamp } />;
						case 'templates':
							return (
								<Templates data={ data } reload={ reload } />
							);
						case 'bulk':
							return <Bulk data={ data } reload={ reload } />;
						default:
							return null;
					}
				} }
			</TabPanel>
		</div>
	);
}

// The groups, for the guide's tests.
export { groups };
