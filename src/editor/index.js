/**
 * The block editor's "Search and social" panel (document sidebar).
 *
 * ⛔ zs-155: loaded only when a post of a public type is edited by someone who may edit its SEO
 * (Editor::enqueue_block_editor). The editor's own packages come from WordPress (`window.wp`), so
 * this bundle carries only the panel itself.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Notice,
	Spinner,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import SchemaPanel from './SchemaPanel';
import registerAnalysisSidebar from './AnalysisSidebar';
import { LockConfirm, LockPanel, lockMiddleware } from './LockPanel';
import registerAiPanel from './AiPanel';
import { PrimaryTermField, SnippetFields, snippetPreview } from './X1aFields';
import registerNosnippet from './Nosnippet';
import SchedulePanel from './SchedulePanel';
import registerX1cPanels from './X1cPanels';
import { fillOnFocus, revertOnBlur } from '../fields/fill';
import './editor.scss';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';

/**
 * The robots flags a person can set here.
 *
 * @return {Array<{value: string, label: string}>} Flags.
 */
function robotsChoices() {
	return [
		{
			value: 'noindex',
			label: __( 'Hide from search results (noindex)', 'zinn-seo' ),
		},
		{
			value: 'nofollow',
			label: __( 'Do not follow its links (nofollow)', 'zinn-seo' ),
		},
		{
			value: 'noarchive',
			label: __( 'No cached copy (noarchive)', 'zinn-seo' ),
		},
		{
			value: 'nosnippet',
			label: __( 'No text snippet (nosnippet)', 'zinn-seo' ),
		},
		{
			value: 'noimageindex',
			label: __( 'Do not index its images (noimageindex)', 'zinn-seo' ),
		},
		{
			value: 'nocache',
			label: __(
				'Bing Copilot uses only the title, link and snippet (nocache)',
				'zinn-seo'
			),
		},
	];
}

/**
 * The fields that differ from what was loaded. Exported for the tests.
 *
 * @param {Object} loaded Loaded.
 * @param {Object} draft  Edited.
 * @return {Object} Changes.
 */
export function changes( loaded, draft ) {
	const out = {};
	Object.keys( draft || {} ).forEach( ( k ) => {
		if (
			JSON.stringify( draft[ k ] ) !==
			JSON.stringify( ( loaded || {} )[ k ] )
		) {
			out[ k ] = draft[ k ];
		}
	} );
	return out;
}

/**
 * Pick an image with the editor's media library.
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {number}   props.value    Attachment ID.
 * @param {string}   props.hint     What is used when empty.
 * @param {Function} props.onChange Called with an ID (0 to remove).
 * @return {Element} The control.
 */
function ImageField( { label, value, hint, onChange } ) {
	const media = useSelect(
		( select ) => ( value ? select( 'core' ).getMedia( value ) : null ),
		[ value ]
	);
	const MediaUpload =
		window.wp.blockEditor && window.wp.blockEditor.MediaUpload;
	return (
		<div className="zseo-ed-image">
			<p className="zseo-ed-label">{ label }</p>
			{ media && media.source_url && (
				<img
					src={ media.source_url }
					alt=""
					className="zseo-ed-image__img"
				/>
			) }
			{ MediaUpload && (
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ value || undefined }
					onSelect={ ( m ) => onChange( m.id ) }
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ value
								? __( 'Change image', 'zinn-seo' )
								: __( 'Choose image', 'zinn-seo' ) }
						</Button>
					) }
				/>
			) }
			{ !! value && (
				<Button
					variant="link"
					isDestructive
					onClick={ () => onChange( 0 ) }
				>
					{ __( 'Remove', 'zinn-seo' ) }
				</Button>
			) }
			{ hint && <p className="zseo-ed-hint">{ hint }</p> }
		</div>
	);
}

/**
 * A counted text field.
 *
 * @param {Object}   props
 * @param {string}   props.label       Label.
 * @param {string}   props.value       Value.
 * @param {string}   props.placeholder The template's result.
 * @param {number}   props.limit       Characters search engines usually show.
 * @param {boolean}  props.multiline   A textarea.
 * @param {Function} props.onChange    Change.
 * @return {Element} The field.
 */
function Counted( { label, value, placeholder, limit, multiline, onChange } ) {
	const Control = multiline ? TextareaControl : TextControl;
	const length = ( value || placeholder || '' ).length;
	const filled = useRef( null );
	// Click a suggested title or description to edit it (zs-757).
	const on = !! ( window.zinnSeoEditor || {} ).placeholderEdit;
	return (
		<div className="zseo-ed-field">
			<Control
				__nextHasNoMarginBottom
				label={ label }
				value={ value || '' }
				placeholder={ placeholder }
				rows={ multiline ? 3 : undefined }
				onChange={ onChange }
				onFocus={ () => {
					const text = fillOnFocus( value, placeholder, on );
					if ( null !== text ) {
						filled.current = text;
						onChange( text );
					}
				} }
				onBlur={ ( e ) => {
					if (
						'' === revertOnBlur( e.target.value, filled.current )
					) {
						onChange( '' );
					}
					filled.current = null;
				} }
			/>
			{ limit > 0 && (
				<p
					className={
						length > limit
							? 'zseo-ed-count is-over'
							: 'zseo-ed-count'
					}
				>
					{ sprintf(
						/* translators: 1: characters used, 2: characters search engines usually show. */
						__( '%1$d of about %2$d characters', 'zinn-seo' ),
						length,
						limit
					) }
				</p>
			) }
		</div>
	);
}

/**
 * The panel body.
 *
 * @return {Element} The panel.
 */
function SeoPanel() {
	const { postId, saving, autosaving, meta } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			saving: editor.isSavingPost(),
			autosaving: editor.isAutosavingPost(),
			meta: editor.getEditedPostAttribute( 'meta' ),
		};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );
	const [ loaded, setLoaded ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ resolved, setResolved ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ more, setMore ] = useState( false );
	const [ storing, setStoring ] = useState( false );
	const wasSaving = useRef( false );
	// Post types with custom-field support carry our fields in the post itself: an edit here makes
	// the post "changed" and WordPress saves the fields with it. Other types save through our route.
	const metaMode =
		!! meta && Object.prototype.hasOwnProperty.call( meta, '_zseo_title' );

	const refresh = () =>
		apiFetch( { path: `${ base }/resolved/post/${ postId }` } )
			.then( setResolved )
			.catch( () => {} );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		Promise.all( [
			apiFetch( { path: `${ base }/fields/post/${ postId }` } ),
			apiFetch( { path: `${ base }/resolved/post/${ postId }` } ),
		] )
			.then( ( [ fields, res ] ) => {
				setLoaded( fields );
				setResolved( res );
			} )
			.catch( ( e ) =>
				setError(
					( e && e.message ) ||
						__( 'Could not load the SEO fields.', 'zinn-seo' )
				)
			);
	}, [ postId ] );

	// After a real (not auto) save, show what the page now says.
	useEffect( () => {
		if ( saving && ! autosaving ) {
			wasSaving.current = true;
			return;
		}
		if ( ! saving && wasSaving.current ) {
			wasSaving.current = false;
			apiFetch( { path: `${ base }/resolved/post/${ postId }` } )
				.then( setResolved )
				.catch( () => {} );
		}
	}, [ saving, autosaving, postId ] );

	const store = () => {
		setStoring( true );
		apiFetch( {
			path: `${ base }/fields/post/${ postId }`,
			method: 'PUT',
			data: { fields: changes( loaded, draft ) },
		} )
			.then( ( fields ) => {
				setLoaded( fields );
				setDraft( {} );
				return refresh();
			} )
			.catch( ( e ) =>
				setError(
					( e && e.message ) ||
						__( 'The SEO fields were not saved.', 'zinn-seo' )
				)
			)
			.finally( () => setStoring( false ) );
	};

	if ( error ) {
		return (
			<Notice status="error" onRemove={ () => setError( '' ) }>
				{ error }
			</Notice>
		);
	}
	if ( ! loaded || ! resolved ) {
		return <Spinner />;
	}
	const value = { ...loaded, ...draft };
	if ( metaMode ) {
		Object.keys( loaded ).forEach( ( k ) => {
			if (
				Object.prototype.hasOwnProperty.call( meta, `_zseo_${ k }` )
			) {
				value[ k ] = meta[ `_zseo_${ k }` ];
			}
		} );
	}
	const set = ( part ) => {
		if ( metaMode ) {
			const next = {};
			Object.keys( part ).forEach( ( k ) => {
				next[ `_zseo_${ k }` ] = part[ k ];
			} );
			editPost( { meta: next } );
			return;
		}
		setDraft( { ...draft, ...part } );
	};
	const robots = value.robots || [];
	const auto = resolved.auto || {};
	const social = resolved.social || {};
	const discover = resolved.discover;
	const dirty =
		! metaMode && Object.keys( changes( loaded, draft ) ).length > 0;

	return (
		<div className="zseo-ed">
			<div
				className="zseo-ed-snippet"
				aria-label={ __( 'Search result preview', 'zinn-seo' ) }
			>
				<div className="zseo-ed-snippet__title">
					{ value.title || auto.title }
				</div>
				<div className="zseo-ed-snippet__url" dir="ltr">
					{ resolved.address }
				</div>
				{ ( () => {
					// What the result will really show: nothing with nosnippet, cut at the
					// page's snippet limit (zs-741).
					const shown = snippetPreview(
						value.desc || auto.desc || '',
						robots,
						value.max_snippet
					);
					return (
						<>
							<div className="zseo-ed-snippet__desc">
								{ shown.text ||
									( shown.note
										? ''
										: __(
												'No description: search engines pick text from the page.',
												'zinn-seo'
											) ) }
							</div>
							{ shown.note && (
								<p className="zseo-ed-hint">{ shown.note }</p>
							) }
						</>
					);
				} )() }
			</div>
			<Counted
				label={ __( 'SEO title', 'zinn-seo' ) }
				value={ value.title }
				placeholder={ auto.title }
				limit={ 60 }
				onChange={ ( v ) => set( { title: v } ) }
			/>
			<Counted
				label={ __( 'Meta description', 'zinn-seo' ) }
				value={ value.desc }
				placeholder={ auto.desc }
				limit={ 160 }
				multiline
				onChange={ ( v ) => set( { desc: v } ) }
			/>
			{ Object.prototype.hasOwnProperty.call(
				loaded,
				'primary_term'
			) && (
				<PrimaryTermField
					value={ value.primary_term }
					onChange={ ( v ) => set( { primary_term: v } ) }
				/>
			) }
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Hide from search results (noindex)', 'zinn-seo' ) }
				checked={ robots.includes( 'noindex' ) }
				onChange={ ( on ) =>
					set( {
						robots: on
							? [
									...robots.filter( ( r ) => 'index' !== r ),
									'noindex',
								]
							: robots.filter( ( r ) => 'noindex' !== r ),
					} )
				}
			/>
			<Button
				variant="link"
				aria-expanded={ more }
				onClick={ () => setMore( ! more ) }
			>
				{ more
					? __( 'Fewer options', 'zinn-seo' )
					: __( 'More options', 'zinn-seo' ) }
			</Button>
			{ more && (
				<div className="zseo-ed-more">
					<TextControl
						__nextHasNoMarginBottom
						type="url"
						label={ __( 'Canonical address', 'zinn-seo' ) }
						value={ value.canonical || '' }
						placeholder={ auto.canonical }
						onChange={ ( v ) => set( { canonical: v } ) }
					/>
					{ Object.prototype.hasOwnProperty.call(
						loaded,
						'crumb'
					) && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Name in breadcrumbs', 'zinn-seo' ) }
							help={ __( 'Empty: the title.', 'zinn-seo' ) }
							value={ value.crumb || '' }
							onChange={ ( v ) => set( { crumb: v } ) }
						/>
					) }
					<fieldset>
						<legend className="zseo-ed-label">
							{ __( 'Search engines', 'zinn-seo' ) }
						</legend>
						{ robotsChoices()
							.filter( ( c ) => 'noindex' !== c.value )
							.map( ( c ) => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ c.value }
									label={ c.label }
									checked={ robots.includes( c.value ) }
									onChange={ ( on ) =>
										set( {
											robots: on
												? [ ...robots, c.value ]
												: robots.filter(
														( r ) => r !== c.value
													),
										} )
									}
								/>
							) ) }
					</fieldset>
					{ Object.prototype.hasOwnProperty.call(
						loaded,
						'max_snippet'
					) && <SnippetFields value={ value } set={ set } /> }
					<h3 className="zseo-ed-heading">
						{ __( 'When shared', 'zinn-seo' ) }
					</h3>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title when shared', 'zinn-seo' ) }
						value={ value.og_title || '' }
						placeholder={ social.title }
						onChange={ ( v ) => set( { og_title: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Text when shared', 'zinn-seo' ) }
						value={ value.og_desc || '' }
						placeholder={ social.desc }
						rows={ 3 }
						onChange={ ( v ) => set( { og_desc: v } ) }
					/>
					<ImageField
						label={ __( 'Image when shared', 'zinn-seo' ) }
						value={ value.og_image || 0 }
						hint={ value.og_image ? '' : social.image_source }
						onChange={ ( v ) => set( { og_image: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title on X (optional)', 'zinn-seo' ) }
						value={ value.x_title || '' }
						onChange={ ( v ) => set( { x_title: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Card size on X', 'zinn-seo' ) }
						value={ value.x_card || '' }
						options={ [
							{
								value: '',
								label: __( 'The site’s card size', 'zinn-seo' ),
							},
							{
								value: 'summary_large_image',
								label: __( 'Large picture', 'zinn-seo' ),
							},
							{
								value: 'summary',
								label: __( 'Small square picture', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) => set( { x_card: v } ) }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Ask Pinterest not to allow pins of this page',
							'zinn-seo'
						) }
						checked={ !! value.nopin }
						onChange={ ( v ) => set( { nopin: v } ) }
					/>
				</div>
			) }
			{ discover && (
				<div className="zseo-ed-discover">
					<h3 className="zseo-ed-heading">
						{ discover.ready
							? __( 'Ready for Google Discover', 'zinn-seo' )
							: __(
									'Google Discover: what is missing',
									'zinn-seo'
								) }
					</h3>
					<ul>
						{ discover.checks.map( ( c ) => (
							<li
								key={ c.id }
								className={ c.ok ? 'is-ok' : 'is-missing' }
							>
								<span className="screen-reader-text">
									{ c.ok
										? __( 'Done:', 'zinn-seo' )
										: __( 'To do:', 'zinn-seo' ) }
								</span>{ ' ' }
								{ c.label }
							</li>
						) ) }
					</ul>
					<ExternalLink href="https://developers.google.com/search/docs/appearance/google-discover">
						{ __( "Google's Discover guidance", 'zinn-seo' ) }
					</ExternalLink>
				</div>
			) }
			{ dirty && (
				<Button
					variant="primary"
					isBusy={ storing }
					disabled={ storing }
					onClick={ store }
				>
					{ __( 'Save the SEO fields', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Register the panel (the document settings panel moved from edit-post to editor in WordPress 6.6).
 */
function register() {
	const wp = window.wp || {};
	const Panel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel || ! wp.plugins ) {
		return;
	}
	wp.plugins.registerPlugin( 'zinn-seo', {
		render: () => (
			<Panel
				name="zinn-seo"
				title={ __( 'Search and social', 'zinn-seo' ) }
				className="zseo-ed-panel"
			>
				<SeoPanel />
			</Panel>
		),
		icon: null,
	} );
	registerAnalysisSidebar();
	registerAiPanel( Panel );
	registerX1cPanels( Panel );
	if ( boot.nosnippet ) {
		registerNosnippet();
	}
	if ( boot.schedule ) {
		wp.plugins.registerPlugin( 'zinn-seo-schedule', {
			render: () => (
				<Panel
					name="zinn-seo-schedule"
					title={ __( 'Schedule an SEO change', 'zinn-seo' ) }
					className="zseo-ed-panel"
				>
					<SchedulePanel />
				</Panel>
			),
			icon: null,
		} );
	}
	if ( boot.lock ) {
		// Pro SEO lock: the panel, and the confirmation a protected change needs.
		apiFetch.use( lockMiddleware( boot.lock.header ) );
		wp.plugins.registerPlugin( 'zinn-seo-lock', {
			render: () => (
				<>
					<Panel
						name="zinn-seo-lock"
						title={ __( 'SEO lock', 'zinn-seo' ) }
						className="zseo-ed-panel"
					>
						<LockPanel
							boot={ boot }
							canLock={ !! boot.lock.canLock }
						/>
					</Panel>
					<LockConfirm />
				</>
			),
			icon: null,
		} );
	}
	if ( boot.schema ) {
		wp.plugins.registerPlugin( 'zinn-seo-schema', {
			render: () => (
				<Panel
					name="zinn-seo-schema"
					title={ __( 'Structured data', 'zinn-seo' ) }
					className="zseo-ed-panel"
				>
					<SchemaPanel />
				</Panel>
			),
			icon: null,
		} );
	}
}

register();
