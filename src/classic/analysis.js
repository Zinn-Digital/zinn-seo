/**
 * The classic editor's "SEO analysis" box: the same panel as the block editor's sidebar, reading
 * the classic editor's title, address, text (TinyMCE or the text tab) and the "Search and social"
 * box, and keeping its focus phrases in hidden fields saved with the post.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';

import AnalysisPanel from '../editor/analysis/AnalysisPanel';

/**
 * The editor's current content.
 *
 * @return {string} Content.
 */
function content() {
	const mce =
		window.tinymce && window.tinymce.get && window.tinymce.get( 'content' );
	if ( mce && ! mce.isHidden() ) {
		return mce.getContent();
	}
	const area = document.getElementById( 'content' );
	return area ? area.value : '';
}

/**
 * A field's value by id.
 *
 * @param {string} id Element id.
 * @return {string|undefined} Value.
 */
function val( id ) {
	const el = document.getElementById( id );
	return el ? el.value : undefined;
}

/**
 * The box.
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element} Panel.
 */
function ClassicAnalysis( { postId } ) {
	const [ version, setVersion ] = useState( 0 );
	const [ fields, setFields ] = useState( {
		focus: val( 'zseo-analysis-focus' ) || '',
		related: val( 'zseo-analysis-related' ) || '',
		cornerstone: val( 'zseo-analysis-cornerstone' ) === '1',
	} );
	useEffect( () => {
		const bump = () => setVersion( ( v ) => v + 1 );
		const ids = [
			'title',
			'content',
			'post_name',
			'zseo-field-title',
			'zseo-field-desc',
			'excerpt',
		];
		const els = ids
			.map( ( id ) => document.getElementById( id ) )
			.filter( Boolean );
		els.forEach( ( el ) => el.addEventListener( 'input', bump ) );
		let mce = null;
		const hook = () => {
			mce =
				window.tinymce &&
				window.tinymce.get &&
				window.tinymce.get( 'content' );
			if ( mce ) {
				mce.on( 'keyup change SetContent', bump );
			}
		};
		if (
			window.tinymce &&
			window.tinymce.get &&
			window.tinymce.get( 'content' )
		) {
			hook();
		} else if ( window.jQuery ) {
			window.jQuery( document ).on( 'tinymce-editor-init', hook );
		}
		return () => {
			els.forEach( ( el ) => el.removeEventListener( 'input', bump ) );
			if ( mce ) {
				mce.off( 'keyup change SetContent', bump );
			}
		};
	}, [] );
	const onFields = ( part ) => {
		const next = { ...fields, ...part };
		setFields( next );
		const set = ( id, v ) => {
			const el = document.getElementById( id );
			if ( el ) {
				el.value = v;
			}
		};
		set( 'zseo-analysis-focus', next.focus );
		set( 'zseo-analysis-related', next.related );
		set( 'zseo-analysis-cornerstone', next.cornerstone ? '1' : '' );
	};
	return (
		<AnalysisPanel
			postId={ postId }
			version={ version }
			saved={ 0 }
			fields={ fields }
			onFields={ onFields }
			getDraft={ () => ( {
				content: content(),
				title: val( 'title' ),
				slug: val( 'post_name' ),
				excerpt: val( 'excerpt' ),
				seo_title: val( 'zseo-field-title' ),
				seo_desc: val( 'zseo-field-desc' ),
				link:
					( document.getElementById( 'sample-permalink' ) || {} )
						.textContent || '',
			} ) }
		/>
	);
}

/**
 * Mount the box when its root is on the screen.
 *
 * @return {void}
 */
export default function mountClassicAnalysis() {
	const root = document.getElementById( 'zinn-seo-analysis-root' );
	if ( root ) {
		createRoot( root ).render(
			<ClassicAnalysis postId={ Number( root.dataset.post ) } />
		);
	}
}
