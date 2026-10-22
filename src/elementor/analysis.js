/**
 * Elementor's Page settings → SEO: the content analysis of the page as Elementor renders it,
 * re-checked after each save (zs-001, zs-002).
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState } from '@wordpress/element';

import AnalysisPanel from '../editor/analysis/AnalysisPanel';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';

/**
 * The panel inside Elementor.
 *
 * @param {Object} props
 * @param {number} props.postId Post.
 * @return {Element|null} Panel.
 */
function ElementorAnalysis( { postId } ) {
	const [ fields, setFields ] = useState( null );
	const [ saved, setSaved ] = useState( 0 );
	const [ version, setVersion ] = useState( 0 );
	useEffect( () => {
		apiFetch( { path: `${ base }/fields/post/${ postId }` } )
			.then( ( f ) =>
				setFields( {
					focus: f.focus || '',
					related: f.related || '',
					cornerstone: !! f.cornerstone,
				} )
			)
			.catch( () =>
				setFields( { focus: '', related: '', cornerstone: false } )
			);
		const onSaved = () => setSaved( ( s ) => s + 1 );
		const model = window.elementor?.settings?.page?.model;
		const onChange = () => setVersion( ( v ) => v + 1 );
		if ( window.elementor?.channels?.editor ) {
			window.elementor.channels.editor.on( 'saved', onSaved );
		}
		if ( model ) {
			model.on( 'change', onChange );
		}
		return () => {
			if ( window.elementor?.channels?.editor ) {
				window.elementor.channels.editor.off( 'saved', onSaved );
			}
			if ( model ) {
				model.off( 'change', onChange );
			}
		};
	}, [ postId ] );
	if ( ! fields ) {
		return null;
	}
	const model = window.elementor?.settings?.page?.model;
	const get = ( k ) => ( model ? model.get( k ) || '' : '' );
	return (
		<AnalysisPanel
			postId={ postId }
			version={ version + saved }
			saved={ saved }
			fields={ fields }
			onFields={ ( part ) => {
				setFields( { ...fields, ...part } );
				apiFetch( {
					path: `${ base }/fields/post/${ postId }`,
					method: 'PUT',
					data: { fields: part },
				} ).catch( () => {} );
			} }
			getDraft={ () => ( {
				// No `content`: the server renders Elementor's saved layout.
				seo_title: get( 'zseo_title' ),
				seo_desc: get( 'zseo_desc' ),
				noindex: get( 'zseo_noindex' ) === 'yes',
				title:
					window.elementor?.settings?.page?.model?.get(
						'post_title'
					) || undefined,
			} ) }
		/>
	);
}

/**
 * Mount into every empty analysis box (the panel re-renders its controls when it opens).
 *
 * @return {void}
 */
export function mountElementorAnalysis() {
	document
		.querySelectorAll( '.zseo-el-analysis:not([data-mounted])' )
		.forEach( ( el ) => {
			el.setAttribute( 'data-mounted', '1' );
			createRoot( el ).render(
				<ElementorAnalysis postId={ Number( el.dataset.post ) } />
			);
		} );
}
