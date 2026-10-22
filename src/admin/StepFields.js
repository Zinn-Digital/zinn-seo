import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';

import { seoFetch } from './api';
import { SnippetPreview } from './TemplateInput';
import ImagePicker from './ImagePicker';
import { xHandle } from './SocialScreen';

/**
 * Setup: the separator, with the home page's search result as it will look.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The fields.
 */
export function AppearanceFields( { value, onChange } ) {
	const [ meta, setMeta ] = useState( null );
	const [ preview, setPreview ] = useState( null );

	useEffect( () => {
		seoFetch( '/templates' )
			.then( setMeta )
			.catch( () => setMeta( null ) );
	}, [] );
	useEffect( () => {
		seoFetch( '/templates/preview', {
			method: 'POST',
			data: { key: 'home', sep: value.sep || '–' },
		} )
			.then( setPreview )
			.catch( () => setPreview( null ) );
	}, [ value.sep ] );

	return (
		<div className="zseo-stack">
			<p>
				{ __(
					'Every page gets a title and a description in search results, from templates you can change later under Search appearance. Choose the character between the parts of a title:',
					'zinn-seo'
				) }
			</p>
			{ meta && (
				<div
					className="zseo-sep__choices"
					role="radiogroup"
					aria-label={ __( 'Separator', 'zinn-seo' ) }
				>
					{ meta.separators.map( ( s ) => (
						<Button
							key={ s }
							variant={
								( value.sep || '–' ) === s
									? 'primary'
									: 'secondary'
							}
							role="radio"
							aria-checked={ ( value.sep || '–' ) === s }
							onClick={ () => onChange( { sep: s } ) }
						>
							{ s }
						</Button>
					) ) }
				</div>
			) }
			{ preview && (
				<SnippetPreview
					title={ preview.title }
					url={ preview.url }
					desc={ preview.desc }
				/>
			) }
		</div>
	);
}

/**
 * Setup: the default share image and the site's X account.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The fields.
 */
export function SocialFields( { value, onChange } ) {
	return (
		<div className="zseo-stack">
			<p>
				{ __(
					'When someone shares a link to your site, networks show a title, some text and an image. Pages use their own featured image; this one is for pages without any.',
					'zinn-seo'
				) }
			</p>
			<ImagePicker
				id="zseo-setup-og-image"
				label={ __( 'Default share image', 'zinn-seo' ) }
				help={ __( 'Best at 1200 × 630 pixels.', 'zinn-seo' ) }
				value={ value.og_default_image || 0 }
				onChange={ ( v ) => onChange( { og_default_image: v } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __(
					"The site's X (Twitter) username (optional)",
					'zinn-seo'
				) }
				value={ value.x_site || '' }
				onChange={ ( v ) => onChange( { x_site: xHandle( v ) } ) }
			/>
		</div>
	);
}
