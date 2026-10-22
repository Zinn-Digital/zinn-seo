import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';

/**
 * The kinds of site, in the order the setup offers them.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
export function siteTypes() {
	return [
		{ value: 'blog', label: __( 'Blog or magazine', 'zinn-seo' ) },
		{
			value: 'business',
			label: __( 'Business or organisation', 'zinn-seo' ),
		},
		{ value: 'shop', label: __( 'Online shop', 'zinn-seo' ) },
		{ value: 'news', label: __( 'News publisher', 'zinn-seo' ) },
		{ value: 'portfolio', label: __( 'Portfolio', 'zinn-seo' ) },
		{ value: 'community', label: __( 'Community or forum', 'zinn-seo' ) },
		{ value: 'other', label: __( 'Something else', 'zinn-seo' ) },
	];
}

/**
 * Pick an image from the media library (the logo).
 *
 * @param {Object}   props
 * @param {number}   props.value    Attachment ID (0 for none).
 * @param {Function} props.onChange Called with the new ID.
 * @return {Element} The control.
 */
export function LogoPicker( { value, onChange } ) {
	const [ url, setUrl ] = useState( '' );

	useEffect( () => {
		if ( ! value ) {
			setUrl( '' );
			return;
		}
		apiFetch( { path: `/wp/v2/media/${ value }?_fields=source_url` } )
			.then( ( media ) => setUrl( media.source_url || '' ) )
			.catch( () => setUrl( '' ) );
	}, [ value ] );

	const open = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Choose a logo', 'zinn-seo' ),
			library: { type: 'image' },
			button: { text: __( 'Use this image', 'zinn-seo' ) },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const picked = frame.state().get( 'selection' ).first().toJSON();
			onChange( picked.id );
			setUrl( picked.url );
		} );
		frame.open();
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			id="zseo-logo"
			label={ __( 'Logo', 'zinn-seo' ) }
			help={ __(
				'Search engines can show it beside your results. A square image of at least 112 by 112 pixels works best.',
				'zinn-seo'
			) }
		>
			<div className="zseo-logo">
				{ url && (
					<img
						src={ url }
						alt={ __( 'The chosen logo', 'zinn-seo' ) }
						className="zseo-logo__img"
					/>
				) }
				<Button variant="secondary" onClick={ open }>
					{ url
						? __( 'Change the logo', 'zinn-seo' )
						: __( 'Choose a logo', 'zinn-seo' ) }
				</Button>
				{ url && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => onChange( 0 ) }
					>
						{ __( 'Remove', 'zinn-seo' ) }
					</Button>
				) }
			</div>
		</BaseControl>
	);
}

/**
 * The people who could be the person behind a personal site.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
function usePeople() {
	const [ people, setPeople ] = useState( [] );
	useEffect( () => {
		apiFetch( {
			path: '/wp/v2/users?per_page=100&context=edit&_fields=id,name',
		} )
			.then( ( users ) =>
				setPeople(
					users.map( ( u ) => ( {
						value: String( u.id ),
						label: u.name,
					} ) )
				)
			)
			.catch( () => setPeople( [] ) );
	}, [] );
	return people;
}

/**
 * The kind of site.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Called with a partial change.
 * @param {Object}   props.found    What the setup detected.
 * @return {Element} The fields.
 */
export function SiteTypeFields( { value, onChange, found } ) {
	return (
		<div className="zseo-stack">
			<RadioControl
				label={ __( 'What kind of site is this?', 'zinn-seo' ) }
				help={ __(
					'This decides which kind of structured data search engines are given and which templates are suggested. You can change it at any time.',
					'zinn-seo'
				) }
				selected={
					value.site_type || ( found && found.site_type ) || ''
				}
				options={ siteTypes() }
				onChange={ ( siteType ) => onChange( { site_type: siteType } ) }
			/>
			{ found && found.woocommerce && (
				<p className="zseo-found">
					{ __( 'WooCommerce is active on this site.', 'zinn-seo' ) }
				</p>
			) }
			{ found &&
				found.language_plugins &&
				found.language_plugins.length > 0 && (
					<p className="zseo-found">
						{ __( 'Multilingual plugin found:', 'zinn-seo' ) }{ ' ' }
						{ found.language_plugins
							.map( ( p ) => p.name )
							.join( ', ' ) }
					</p>
				) }
		</div>
	);
}

/**
 * Who is behind the site: an organisation or a person, its name and logo.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Called with a partial change.
 * @param {Object}   props.found    What the setup detected.
 * @return {Element} The fields.
 */
export function IdentityFields( { value, onChange, found } ) {
	const people = usePeople();
	const type = value.identity_type || 'organization';

	return (
		<div className="zseo-stack">
			<RadioControl
				label={ __( 'Who is behind this site?', 'zinn-seo' ) }
				selected={ type }
				options={ [
					{
						value: 'organization',
						label: __( 'A company or organisation', 'zinn-seo' ),
					},
					{ value: 'person', label: __( 'A person', 'zinn-seo' ) },
				] }
				onChange={ ( identityType ) =>
					onChange( { identity_type: identityType } )
				}
			/>
			{ 'organization' === type && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Organisation name', 'zinn-seo' ) }
						value={ value.identity_name || '' }
						placeholder={ ( found && found.site_name ) || '' }
						onChange={ ( name ) =>
							onChange( { identity_name: name } )
						}
					/>
					<LogoPicker
						value={
							value.identity_logo || ( found && found.logo ) || 0
						}
						onChange={ ( logo ) =>
							onChange( { identity_logo: logo } )
						}
					/>
				</>
			) }
			{ 'person' === type && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'The person', 'zinn-seo' ) }
					value={ String( value.identity_person || '' ) }
					options={ [
						{ value: '', label: __( 'Choose a user', 'zinn-seo' ) },
						...people,
					] }
					onChange={ ( id ) =>
						onChange( { identity_person: Number( id ) || 0 } )
					}
				/>
			) }
		</div>
	);
}
