/**
 * The classic editor's "Structured data" box (zs-032, zs-033, zs-039, zs-301) and "SEO analysis"
 * box (zs-001): the same panels as the block editor's, saved with the post. Loaded only on classic
 * post screens.
 */
import { createRoot } from '@wordpress/element';

import { SchemaPanelBody } from '../editor/SchemaPanel';
import mountClassicAnalysis from './analysis';
import '../editor/editor.scss';

// The block editor's media button is a placeholder until a screen provides the real one
// (WordPress's own packages, from `window.wp`).
window.wp.hooks.addFilter(
	'editor.MediaUpload',
	'zinn-seo/classic-media-upload',
	() => window.wp.mediaUtils.MediaUpload
);

const root = document.getElementById( 'zinn-seo-schema-root' );
if ( root ) {
	createRoot( root ).render(
		<SchemaPanelBody postId={ Number( root.dataset.post ) } classic />
	);
}

mountClassicAnalysis();
