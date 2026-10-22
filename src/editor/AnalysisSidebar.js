/**
 * The block editor's "Zinn® SEO" sidebar: the content analysis of the page being written
 * (zs-001), with the editor's unsaved content, title and slug. Page Builder Sandwich builds inside
 * the block editor, so its pages get the same sidebar.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

import AnalysisPanel from './analysis/AnalysisPanel';
import { linkFirst } from './analysis/pure';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';

/**
 * The HTML of a rich-text value (a string before WordPress 6.5, a RichTextData object after).
 *
 * @param {*} value Value.
 * @return {string} HTML.
 */
function html( value ) {
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( value && typeof value.toHTMLString === 'function' ) {
		return value.toHTMLString();
	}
	return value ? String( value ) : '';
}

/**
 * Every block, inner blocks included.
 *
 * @param {Array} blocks Blocks.
 * @return {Array} Flat list.
 */
function flatten( blocks ) {
	const out = [];
	( blocks || [] ).forEach( ( b ) => {
		out.push( b );
		out.push( ...flatten( b.innerBlocks ) );
	} );
	return out;
}

/**
 * The sidebar's body.
 *
 * @return {Element} Body.
 */
export function AnalysisBody() {
	const {
		postId,
		content,
		title,
		slug,
		excerpt,
		link,
		meta,
		saving,
		autosaving,
	} = useSelect( ( select ) => {
		const e = select( 'core/editor' );
		return {
			postId: e.getCurrentPostId(),
			content: e.getEditedPostContent(),
			title: e.getEditedPostAttribute( 'title' ),
			slug: e.getEditedPostAttribute( 'slug' ),
			excerpt: e.getEditedPostAttribute( 'excerpt' ),
			link: e.getPermalink ? e.getPermalink() : '',
			meta: e.getEditedPostAttribute( 'meta' ),
			saving: e.isSavingPost(),
			autosaving: e.isAutosavingPost(),
		};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );
	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );
	const blocks = useSelect(
		( select ) => select( 'core/block-editor' ).getBlocks(),
		[]
	);
	const metaMode =
		!! meta && Object.prototype.hasOwnProperty.call( meta, '_zseo_focus' );
	const [ own, setOwn ] = useState( null );
	const [ saved, setSaved ] = useState( 0 );
	const wasSaving = useRef( false );
	const [ version, setVersion ] = useState( 0 );

	// Fields of a type without custom-field support live behind the plugin's own route.
	useEffect( () => {
		if ( ! metaMode && postId ) {
			apiFetch( { path: `${ base }/fields/post/${ postId }` } )
				.then( ( f ) =>
					setOwn( {
						focus: f.focus || '',
						related: f.related || '',
						cornerstone: !! f.cornerstone,
					} )
				)
				.catch( () =>
					setOwn( { focus: '', related: '', cornerstone: false } )
				);
		}
	}, [ metaMode, postId ] );

	useEffect( () => {
		setVersion( ( v ) => v + 1 );
	}, [
		content,
		title,
		slug,
		excerpt,
		meta && meta._zseo_title,
		meta && meta._zseo_desc,
	] );

	useEffect( () => {
		if ( saving && ! autosaving ) {
			wasSaving.current = true;
			return;
		}
		if ( ! saving && wasSaving.current ) {
			wasSaving.current = false;
			setSaved( ( s ) => s + 1 );
		}
	}, [ saving, autosaving ] );

	const fields = metaMode
		? {
				focus: meta._zseo_focus || '',
				related: meta._zseo_related || '',
				cornerstone: !! meta._zseo_cornerstone,
			}
		: own || { focus: '', related: '', cornerstone: false };

	const onFields = ( part ) => {
		if ( metaMode ) {
			const next = {};
			Object.keys( part ).forEach( ( k ) => {
				next[ `_zseo_${ k }` ] = part[ k ];
			} );
			editPost( { meta: next } );
			return;
		}
		const merged = { ...fields, ...part };
		setOwn( merged );
		apiFetch( {
			path: `${ base }/fields/post/${ postId }`,
			method: 'PUT',
			data: { fields: part },
		} ).catch( () => {} );
	};

	const insertLink = ( anchor, url ) => {
		for ( const b of flatten( blocks ) ) {
			if (
				! [
					'core/paragraph',
					'core/list-item',
					'core/quote',
					'core/verse',
				].includes( b.name )
			) {
				continue;
			}
			const before = html( b.attributes.content );
			const after = linkFirst( before, anchor, url );
			if ( after ) {
				updateBlockAttributes( b.clientId, { content: after } );
				return true;
			}
		}
		return false;
	};

	if ( ! postId || ( ! metaMode && ! own ) ) {
		return null;
	}
	return (
		<AnalysisPanel
			postId={ postId }
			version={ version }
			saved={ saved }
			fields={ fields }
			onFields={ onFields }
			insertLink={ insertLink }
			getDraft={ () => ( {
				content,
				title,
				slug,
				excerpt: typeof excerpt === 'string' ? excerpt : '',
				link,
				seo_title:
					meta && typeof meta._zseo_title === 'string'
						? meta._zseo_title
						: undefined,
				seo_desc:
					meta && typeof meta._zseo_desc === 'string'
						? meta._zseo_desc
						: undefined,
			} ) }
		/>
	);
}

/**
 * The toolbar icon (a magnifier).
 *
 * @return {Element} Icon.
 */
function Icon() {
	return (
		<svg
			width="24"
			height="24"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false"
			className="zseo-an-icon"
		>
			<circle
				cx="11"
				cy="11"
				r="6.5"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
			/>
			<path
				d="M16 16l5 5"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
			/>
			<circle className="zseo-an-icon__dot" cx="11" cy="11" r="3" />
		</svg>
	);
}

/**
 * Register the sidebar (WordPress 6.6 moved it from edit-post to editor).
 *
 * @return {void}
 */
export default function registerAnalysisSidebar() {
	const wp = window.wp || {};
	const Sidebar =
		( wp.editor && wp.editor.PluginSidebar ) ||
		( wp.editPost && wp.editPost.PluginSidebar );
	const MoreItem =
		( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) ||
		( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );
	if ( ! Sidebar || ! wp.plugins || ! boot.analysis ) {
		return;
	}
	wp.plugins.registerPlugin( 'zinn-seo-analysis', {
		icon: <Icon />,
		render: () => (
			<>
				{ MoreItem && (
					<MoreItem target="zinn-seo-analysis" icon={ <Icon /> }>
						{ __( 'Zinn® SEO analysis', 'zinn-seo' ) }
					</MoreItem>
				) }
				<Sidebar
					name="zinn-seo-analysis"
					title={ __( 'Zinn® SEO', 'zinn-seo' ) }
					className="zseo-an-sidebar"
				>
					<AnalysisBody />
				</Sidebar>
			</>
		),
	} );
}
