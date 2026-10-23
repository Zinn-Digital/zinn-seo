/**
 * The AI copywriter inside Elementor (zs-782): Page settings → SEO. "Insert" adds the chosen
 * version as a Text Editor widget at the end of the page (in a new container, or a section and
 * column on a site without containers); an SEO title or description goes into the SEO fields on
 * the same panel (saved with the page).
 */
import { createRoot } from '@wordpress/element';

import { toHtml } from '../copywriter/shared';
import EditorTool from './CopywriterBox';

/**
 * Add a Text Editor widget with this HTML at the end of the page.
 *
 * @param {string} html HTML.
 * @return {boolean} Added.
 */
export function insertWidget( html ) {
	const run = window.$e && window.$e.run;
	const preview =
		window.elementor &&
		window.elementor.getPreviewContainer &&
		window.elementor.getPreviewContainer();
	if ( ! run || ! preview ) {
		return false;
	}
	const widget = {
		elType: 'widget',
		widgetType: 'text-editor',
		settings: { editor: html },
	};
	const containers = !! (
		window.elementorCommon?.config?.experimentalFeatures?.container ||
		window.elementor?.config?.experimentalFeatures?.container
	);
	if ( containers ) {
		const box = run( 'document/elements/create', {
			container: preview,
			model: { elType: 'container' },
		} );
		run( 'document/elements/create', { container: box, model: widget } );
		return true;
	}
	const section = run( 'document/elements/create', {
		container: preview,
		model: { elType: 'section' },
		columns: 1,
	} );
	const column = section && section.children && section.children[ 0 ];
	if ( ! column ) {
		return false;
	}
	run( 'document/elements/create', { container: column, model: widget } );
	return true;
}

/**
 * Put a version into the page's SEO title or description control.
 *
 * @param {string} field title | desc.
 * @param {string} text  Text.
 * @return {void}
 */
export function setSeoField( field, text ) {
	const model = window.elementor?.settings?.page?.model;
	if ( model ) {
		model.set( 'desc' === field ? 'zseo_desc' : 'zseo_title', text );
	}
}

/**
 * Mount into every box not yet mounted.
 *
 * @return {void}
 */
export function mountCopywriter() {
	document
		.querySelectorAll( '.zseo-el-copywriter:not([data-mounted])' )
		.forEach( ( node ) => {
			node.setAttribute( 'data-mounted', '1' );
			createRoot( node ).render(
				<EditorTool
					postId={ Number( node.getAttribute( 'data-post' ) ) || 0 }
					onInsert={ ( text ) => insertWidget( toHtml( text ) ) }
					onSeo={ setSeoField }
				/>
			);
		} );
}
