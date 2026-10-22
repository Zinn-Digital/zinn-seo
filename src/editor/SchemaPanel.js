/**
 * The block editor's "Structured data" panel: what the page is, the article index's takeaways and
 * review date, (Pro) the schema types this page adds and what it is about — and the offline check
 * of everything the page prints (zs-032, zs-033, zs-035, zs-039, zs-301).
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { Field } from '../blocks/Field';
import Results from '../admin/schema/Results';
import { typeOptions } from '../admin/schema/TypesTab';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';

/** Fields this panel edits. */
const OWN = [
	'schema_page',
	'schema_article',
	'takeaways',
	'reviewed',
	'about',
	'mentions',
	'speakable',
	'significant',
];

/**
 * Values that differ from what was loaded.
 *
 * @param {Object} loaded Loaded.
 * @param {Object} draft  Edited.
 * @return {Object} Changes.
 */
export function changed( loaded, draft ) {
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
 * The schema types this page adds (Pro): a form per type.
 *
 * @param {Object}   props
 * @param {Array}    props.list      Added types.
 * @param {Array}    props.catalog   Types available.
 * @param {Array}    props.templates Custom types.
 * @param {Function} props.onChange  Change.
 * @return {Element} The list.
 */
function AddedTypes( { list, catalog, templates, onChange } ) {
	const byKey = {};
	catalog.forEach( ( t ) => ( byKey[ t.key ] = t ) );
	const set = ( i, part ) =>
		onChange( list.map( ( x, j ) => ( j === i ? { ...x, ...part } : x ) ) );
	return (
		<div className="zseo-ed-types">
			{ list.map( ( item, i ) => {
				const isTemplate = 0 === item.type.indexOf( 'template:' );
				const t = isTemplate
					? templates.find(
							( x ) => `template:${ x.id }` === item.type
						)
					: byKey[ item.type ];
				return (
					<Card key={ i } size="small">
						<CardHeader>
							<strong>
								{ isTemplate
									? ( t && t.name ) || item.type
									: ( t && t.label ) || item.type }
							</strong>
							<Button
								size="small"
								icon="trash"
								isDestructive
								label={ __( 'Remove', 'zinn-seo' ) }
								onClick={ () =>
									onChange(
										list.filter( ( x, j ) => j !== i )
									)
								}
							/>
						</CardHeader>
						<CardBody className="zseo-ed">
							{ isTemplate && (
								<p className="zseo-ed-muted">
									{ __(
										'Filled from this post automatically (Structured data → Custom types).',
										'zinn-seo'
									) }
								</p>
							) }
							{ ! isTemplate &&
								t &&
								t.fields.map( ( f ) => (
									<Field
										key={ f.key }
										field={ f }
										value={ ( item.values || {} )[ f.key ] }
										onChange={ ( v ) =>
											set( i, {
												values: {
													...( item.values || {} ),
													[ f.key ]: v,
												},
											} )
										}
									/>
								) ) }
						</CardBody>
					</Card>
				);
			} ) }
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Add structured data', 'zinn-seo' ) }
				value=""
				options={ [
					{ value: '', label: __( 'Choose a type…', 'zinn-seo' ) },
					...catalog.map( ( t ) => ( {
						value: t.key,
						label: t.label,
					} ) ),
					...templates.map( ( t ) => ( {
						value: `template:${ t.id }`,
						label: sprintf(
							/* translators: %s: a custom type's name. */
							__( 'Custom: %s', 'zinn-seo' ),
							t.name
						),
					} ) ),
				] }
				onChange={ ( key ) =>
					key && onChange( [ ...list, { type: key, values: {} } ] )
				}
			/>
		</div>
	);
}

/**
 * The panel in the block editor.
 *
 * @return {Element} Panel.
 */
export default function SchemaPanel() {
	const state = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			saving: editor.isSavingPost(),
			autosaving: editor.isAutosavingPost(),
			meta: editor.getEditedPostAttribute( 'meta' ),
		};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );
	return <SchemaPanelBody { ...state } editPost={ editPost } />;
}

/**
 * The panel's body: the block editor's panel, and the classic editor's box (`classic`: no editor
 * store; what changed travels with the post form in a hidden field and is saved with the post).
 *
 * @param {Object}   props
 * @param {number}   props.postId     Post.
 * @param {boolean}  props.saving     The editor is saving.
 * @param {boolean}  props.autosaving The editor is autosaving.
 * @param {Object}   props.meta       The post's edited meta (block editor).
 * @param {Function} props.editPost   The editor's editPost (block editor).
 * @param {boolean}  props.classic    In the classic editor.
 * @return {Element} Body.
 */
export function SchemaPanelBody( {
	postId,
	saving = false,
	autosaving = false,
	meta = null,
	editPost = null,
	classic = false,
} ) {
	const [ loaded, setLoaded ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ added, setAdded ] = useState( null );
	const [ addedLoaded, setAddedLoaded ] = useState( null );
	const [ catalog, setCatalog ] = useState( [] );
	const [ templates, setTemplates ] = useState( [] );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const wasSaving = useRef( false );
	const metaMode =
		! classic &&
		!! meta &&
		Object.prototype.hasOwnProperty.call( meta, '_zseo_schema_page' );
	const pro = !! boot.isPro;
	const types = boot.schema || { pageTypes: [], articleTypes: [] };

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		apiFetch( { path: `${ base }/fields/post/${ postId }` } )
			.then( setLoaded )
			.catch( ( e ) => setError( ( e && e.message ) || '' ) );
		if ( pro ) {
			Promise.all( [
				apiFetch( { path: `${ base }/schema/post/${ postId }` } ),
				apiFetch( { path: `${ base }/schema/types` } ),
				apiFetch( { path: `${ base }/schema/templates` } ),
			] )
				.then( ( [ list, cat, tpl ] ) => {
					setAdded( list );
					setAddedLoaded( list );
					setCatalog( cat );
					setTemplates( tpl.templates || [] );
				} )
				.catch( () => {} );
		}
	}, [ postId, pro ] );

	const storeAdded = () =>
		apiFetch( {
			path: `${ base }/schema/post/${ postId }`,
			method: 'PUT',
			data: { types: added },
		} ).then( ( list ) => {
			setAdded( list );
			setAddedLoaded( list );
		} );

	// Saved with the post: the fields (in meta mode WordPress does it) and the added types.
	useEffect( () => {
		if ( saving && ! autosaving ) {
			wasSaving.current = true;
			if (
				pro &&
				added &&
				JSON.stringify( added ) !== JSON.stringify( addedLoaded )
			) {
				storeAdded().catch( () => {} );
			}
			return;
		}
		if ( ! saving && wasSaving.current ) {
			wasSaving.current = false;
			setResult( null );
		}
	}, [ saving, autosaving ] ); // eslint-disable-line react-hooks/exhaustive-deps -- runs on the save transition only.

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! loaded ) {
		return <Spinner />;
	}
	const value = { ...loaded, ...draft };
	if ( metaMode ) {
		OWN.forEach( ( k ) => {
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
			Object.keys( part ).forEach(
				( k ) => ( next[ `_zseo_${ k }` ] = part[ k ] )
			);
			editPost( { meta: next } );
			return;
		}
		setDraft( { ...draft, ...part } );
	};
	const dirtyFields =
		! metaMode && Object.keys( changed( loaded, draft ) ).length > 0;
	const dirtyAdded =
		pro &&
		added &&
		JSON.stringify( added ) !== JSON.stringify( addedLoaded );
	const save = () => {
		setBusy( true );
		const jobs = [];
		if ( dirtyFields ) {
			jobs.push(
				apiFetch( {
					path: `${ base }/fields/post/${ postId }`,
					method: 'PUT',
					data: { fields: changed( loaded, draft ) },
				} ).then( ( f ) => {
					setLoaded( f );
					setDraft( {} );
				} )
			);
		}
		if ( dirtyAdded ) {
			jobs.push( storeAdded() );
		}
		Promise.all( jobs )
			.catch( ( e ) => setError( ( e && e.message ) || '' ) )
			.finally( () => setBusy( false ) );
	};
	const check = () => {
		setBusy( true );
		apiFetch( {
			path: `${ base }/schema/validate`,
			method: 'POST',
			data: { post: postId },
		} )
			.then( setResult )
			.catch( ( e ) => setError( ( e && e.message ) || '' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="zseo-ed">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'This page is', 'zinn-seo' ) }
				value={ value.schema_page || '' }
				options={ [
					{
						value: '',
						label: __( 'Default for its type', 'zinn-seo' ),
					},
					...typeOptions( types.pageTypes ),
				] }
				onChange={ ( v ) => set( { schema_page: v } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Article type', 'zinn-seo' ) }
				value={ value.schema_article || '' }
				options={ [
					{
						value: '',
						label: __( 'Default for its type', 'zinn-seo' ),
					},
					{ value: 'none', label: __( 'No article', 'zinn-seo' ) },
					...typeOptions( types.articleTypes ),
				] }
				onChange={ ( v ) => set( { schema_article: v } ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Key takeaways (one per line)', 'zinn-seo' ) }
				help={ __(
					'Shown in the article index at the top of the post.',
					'zinn-seo'
				) }
				rows={ 3 }
				value={ value.takeaways || '' }
				onChange={ ( v ) => set( { takeaways: v } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="date"
				label={ __( 'Last reviewed by an expert', 'zinn-seo' ) }
				value={ value.reviewed || '' }
				onChange={ ( v ) => set( { reviewed: v } ) }
			/>
			{ pro && (
				<>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'What the page is about', 'zinn-seo' ) }
						help={ __(
							'One per line: Name | Wikipedia or Wikidata address.',
							'zinn-seo'
						) }
						rows={ 2 }
						value={ value.about || '' }
						onChange={ ( v ) => set( { about: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'What it mentions', 'zinn-seo' ) }
						rows={ 2 }
						value={ value.mentions || '' }
						onChange={ ( v ) => set( { mentions: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Read aloud by voice assistants (CSS selectors)',
							'zinn-seo'
						) }
						rows={ 2 }
						dir="ltr"
						value={ value.speakable || '' }
						onChange={ ( v ) => set( { speakable: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Most important links (one address per line)',
							'zinn-seo'
						) }
						rows={ 2 }
						dir="ltr"
						value={ value.significant || '' }
						onChange={ ( v ) => set( { significant: v } ) }
					/>
					{ added && (
						<AddedTypes
							list={ added }
							catalog={ catalog }
							templates={ templates }
							onChange={ setAdded }
						/>
					) }
				</>
			) }
			{ ! pro && (
				<p className="zseo-ed-muted">
					{ __(
						'Zinn® SEO Pro adds recipes, events, jobs, courses, software, books, films, services, videos and Q&A to a page from a form, and custom types of your own.',
						'zinn-seo'
					) }{ ' ' }
					<a href={ boot.plansUrl }>
						{ __( 'See Pro', 'zinn-seo' ) }
					</a>
				</p>
			) }
			{ classic && (
				<input
					type="hidden"
					name="zseo_schema_state"
					value={ JSON.stringify( {
						fields: changed( loaded, draft ),
						types: dirtyAdded ? added : null,
					} ) }
				/>
			) }
			{ ! classic && ( dirtyFields || dirtyAdded ) && (
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ save }
				>
					{ __( 'Save structured data', 'zinn-seo' ) }
				</Button>
			) }
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ check }
			>
				{ __( 'Check this page’s structured data', 'zinn-seo' ) }
			</Button>
			<Results result={ result } />
		</div>
	);
}
