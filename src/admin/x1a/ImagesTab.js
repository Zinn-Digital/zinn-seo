/**
 * Image SEO rules (zs-710): templates on upload, every language, and library-wide fixes.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { JobLine, RunButton, Table, useJob, useRoute } from '../p4/shared';
import { SaveBar, Switch } from './shared';
import { confirmBulk } from '../bulk/confirmBulk';

const FIELDS = [ 'alt', 'title', 'caption' ];

/**
 * Field names in words.
 *
 * @return {Object<string, string>} Field => label.
 */
function fieldNames() {
	return {
		alt: __( 'Alt text', 'zinn-seo' ),
		title: __( 'Title', 'zinn-seo' ),
		caption: __( 'Caption', 'zinn-seo' ),
	};
}

/**
 * Library-wide actions: preview, then run in the background.
 *
 * @param {Object}   props
 * @param {Object}   props.state  `GET /images`.
 * @param {Function} props.reload Reload the counts.
 * @return {Element} Panel.
 */
function BulkPanel( { state, reload } ) {
	const names = fieldNames();
	const [ action, setAction ] = useState( {
		kind: 'fill',
		fields: [ 'alt' ],
		find: '',
		replace: '',
		match_case: false,
		case: 'first',
	} );
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/images' ).then( ( r ) => r.job ),
		reload
	);
	useEffect( () => {
		setJob( state.job );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ state.job ] );
	const set = ( part ) => {
		setAction( { ...action, ...part } );
		setPreview( null );
	};
	const running = job && 'pending' === job.status;
	return (
		<PanelBody title={ __( 'Fix the whole media library', 'zinn-seo' ) }>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'What to do', 'zinn-seo' ) }
				value={ action.kind }
				options={ [
					{
						value: 'fill',
						label: __(
							'Fill empty alt text, titles and captions from the templates above',
							'zinn-seo'
						),
					},
					{
						value: 'replace',
						label: __( 'Find and replace', 'zinn-seo' ),
					},
					{
						value: 'case',
						label: __( 'Change capitals', 'zinn-seo' ),
					},
					{
						value: 'punct',
						label: __( 'Remove punctuation', 'zinn-seo' ),
					},
				] }
				onChange={ ( v ) => set( { kind: v } ) }
			/>
			{ 'fill' !== action.kind && (
				<fieldset className="zseo-x1a-fields">
					<legend>{ __( 'In', 'zinn-seo' ) }</legend>
					{ FIELDS.map( ( f ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ f }
							label={ names[ f ] }
							checked={ action.fields.includes( f ) }
							onChange={ ( on ) =>
								set( {
									fields: on
										? [ ...action.fields, f ]
										: action.fields.filter(
												( x ) => x !== f
											),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
			{ 'replace' === action.kind && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Find', 'zinn-seo' ) }
						value={ action.find }
						onChange={ ( v ) => set( { find: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Replace with', 'zinn-seo' ) }
						value={ action.replace }
						onChange={ ( v ) => set( { replace: v } ) }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Match capitals exactly', 'zinn-seo' ) }
						checked={ action.match_case }
						onChange={ ( v ) => set( { match_case: v } ) }
					/>
				</>
			) }
			{ 'case' === action.kind && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Capitals', 'zinn-seo' ) }
					value={ action.case }
					options={ [
						{
							value: 'first',
							label: __( 'First letter only', 'zinn-seo' ),
						},
						{
							value: 'title',
							label: __( 'Title Case', 'zinn-seo' ),
						},
						{
							value: 'lower',
							label: __( 'all lower case', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => set( { case: v } ) }
				/>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<div className="zseo-actions zseo-actions--start">
				<RunButton
					onError={ setError }
					disabled={ running }
					run={ () =>
						seoFetch( '/images/preview', {
							method: 'POST',
							data: { action },
						} ).then( setPreview )
					}
				>
					{ __( 'See what would change', 'zinn-seo' ) }
				</RunButton>
				<RunButton
					variant="primary"
					onError={ setError }
					disabled={ running || ! preview || ! preview.changes }
					run={ () =>
						confirmBulk( preview.changes )
							? seoFetch( '/images/run', {
									method: 'POST',
									data: { action },
								} ).then( ( r ) => setJob( r.job ) )
							: undefined
					}
				>
					{ __( 'Change them', 'zinn-seo' ) }
				</RunButton>
			</div>
			<JobLine job={ job } />
			{ preview && (
				<div className="zseo-stack">
					<p>
						{ preview.complete
							? sprintf(
									/* translators: 1: images that change, 2: images checked. */
									_n(
										'%1$d image of %2$d would change.',
										'%1$d images of %2$d would change.',
										preview.changes,
										'zinn-seo'
									),
									preview.changes,
									preview.checked
								)
							: sprintf(
									/* translators: 1: images that change, 2: images checked so far. */
									__(
										'%1$d of the first %2$d images would change (a large library: the run goes through every image).',
										'zinn-seo'
									),
									preview.changes,
									preview.checked
								) }
					</p>
					<Table
						label={ __( 'Examples', 'zinn-seo' ) }
						empty={ __( 'Nothing would change.', 'zinn-seo' ) }
						columns={ [
							{
								id: 'file',
								label: __( 'Image', 'zinn-seo' ),
								render: ( r ) => (
									<span dir="ltr">{ r.file }</span>
								),
							},
							{
								id: 'change',
								label: __( 'Change', 'zinn-seo' ),
								render: ( r ) => (
									<ul className="zseo-x1a-list">
										{ Object.keys( r.changes ).map(
											( f ) => (
												<li key={ f }>
													<strong>
														{ names[ f ] }:
													</strong>{ ' ' }
													<del>
														{ r.changes[ f ][ 0 ] ||
															'—' }
													</del>{ ' ' }
													<span aria-hidden="true">
														→
													</span>{ ' ' }
													<ins>
														{ r.changes[ f ][ 1 ] }
													</ins>
												</li>
											)
										) }
									</ul>
								),
							},
						] }
						rows={ preview.examples.map( ( e ) => ( {
							...e,
							id: e.id,
						} ) ) }
					/>
				</div>
			) }
		</PanelBody>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.form The settings draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function ImagesTab( { form, data } ) {
	const state = useRoute( '/images' );
	const names = fieldNames();
	if ( ! state.data ) {
		return state.error ? (
			<Notice status="error" isDismissible={ false }>
				{ errorText( { message: state.error } ) }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const langs = state.data.languages || [];
	const per = form.value.img_lang || {};
	const setLang = ( code, field, v ) => {
		const next = {
			...per,
			[ code ]: { ...( per[ code ] || {} ), [ field ]: v },
		};
		form.change( { img_lang: next } );
	};
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ sprintf(
					/* translators: 1: images in the library, 2: images without alt text. */
					__(
						'Alt text tells search engines and screen readers what an image shows, and brings visitors from image search. Your library has %1$d images; %2$d have no alt text.',
						'zinn-seo'
					),
					state.data.images,
					state.data.no_alt
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-images" />
			</p>
			<PanelBody title={ __( 'When an image is uploaded', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="img_clean_names"
					label={ __( 'Give it a clean file name', 'zinn-seo' ) }
					help={ __(
						'"My Photo_FINAL (2).JPG" becomes "my-photo-final-2.jpg": readable, and the same in every browser.',
						'zinn-seo'
					) }
				/>
				<p className="zseo-muted">
					{ __(
						'Fill these when they are empty, never over what you wrote: the file name in words, the title of the post it was uploaded to, its category and your site name.',
						'zinn-seo'
					) }{ ' ' }
					{ __( 'You can use:', 'zinn-seo' ) }{ ' ' }
					<code dir="ltr">
						%filename% %post_title% %category% %site_name% %count%
					</code>
				</p>
				{ FIELDS.map( ( f ) => (
					<TextControl
						__nextHasNoMarginBottom
						key={ f }
						label={ names[ f ] }
						placeholder={ 'alt' === f ? '%filename%' : '' }
						value={ form.value[ 'img_' + f ] || '' }
						onChange={ ( v ) =>
							form.change( { [ 'img_' + f ]: v } )
						}
					/>
				) ) }
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Capitals', 'zinn-seo' ) }
					value={ form.value.img_case || '' }
					options={ [
						{ value: '', label: __( 'As made', 'zinn-seo' ) },
						{
							value: 'first',
							label: __( 'First letter only', 'zinn-seo' ),
						},
						{
							value: 'title',
							label: __( 'Title Case', 'zinn-seo' ),
						},
						{
							value: 'lower',
							label: __( 'all lower case', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => form.change( { img_case: v } ) }
				/>
				<Switch
					form={ form }
					name="img_strip_punct"
					label={ __( 'Remove punctuation', 'zinn-seo' ) }
					help=""
				/>
				<Switch
					form={ form }
					name="img_alt_output"
					label={ __(
						'Fill missing alt text on your pages too',
						'zinn-seo'
					) }
					help={ __(
						'An image shown with an empty alt gets its alt text from the media library, or from the alt text rule above, as the page is shown.',
						'zinn-seo'
					) }
				/>
			</PanelBody>
			{ langs.length > 1 && (
				<PanelBody title={ __( 'In each language', 'zinn-seo' ) }>
					<p className="zseo-muted">
						{ __(
							"With Tranzly, each language gets its own alt text, title and caption, written from the translated post. Leave a field empty to keep the main language's.",
							'zinn-seo'
						) }
					</p>
					{ langs.map( ( l ) => (
						<fieldset key={ l.code } className="zseo-x1a-fields">
							<legend>{ l.name || l.code }</legend>
							{ FIELDS.map( ( f ) => (
								<TextControl
									__nextHasNoMarginBottom
									key={ f }
									label={ names[ f ] }
									value={ ( per[ l.code ] || {} )[ f ] || '' }
									onChange={ ( v ) =>
										setLang( l.code, f, v )
									}
								/>
							) ) }
						</fieldset>
					) ) }
				</PanelBody>
			) }
			<SaveBar form={ form } />
			<BulkPanel state={ state.data } reload={ state.reload } />
			<Button variant="link" onClick={ state.reload }>
				{ __( 'Count again', 'zinn-seo' ) }
			</Button>
		</div>
	);
}
