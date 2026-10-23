/**
 * The AI copywriter in the block editor (zs-782): writes for THIS page (its focus phrase, research
 * and language), inserts a version as blocks after the selected block, puts an SEO title or
 * description into the page's SEO fields (saved with the post), and works on the selected
 * paragraph (improve, summarise, fix grammar, an analogy).
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import CopywriterTool from './CopywriterTool';
import { toBlocks } from './shared';
import './copywriter.scss';

/**
 * The text of a block's HTML.
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
function plain( html ) {
	// DOMParser builds an inert document: nothing in it runs.
	const doc = new window.DOMParser().parseFromString(
		String( html || '' ),
		'text/html'
	);
	return ( doc.body.textContent || '' ).trim();
}

/**
 * The panel.
 *
 * @param {Object}   props
 * @param {number}   props.postId Post.
 * @param {Function} props.setSeo Put a value into the page's SEO field (field, text), or null.
 * @return {Element} Panel.
 */
export default function EditorPanel( { postId, setSeo = null } ) {
	const [ data, setData ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ start, setStart ] = useState( { tool: '', text: '' } );
	const [ phrase, setPhrase ] = useState( '' );
	const block = useSelect(
		( s ) => s( 'core/block-editor' ).getSelectedBlock(),
		[]
	);
	const title = useSelect(
		( s ) => s( 'core/editor' ).getEditedPostAttribute( 'title' ),
		[]
	);
	const { insertBlocks } = useDispatch( 'core/block-editor' );
	useEffect( () => {
		if ( open && ! data ) {
			apiFetch( { path: '/zinn-seo/v1/copywriter' } ).then( setData, () =>
				setData( { tools: [] } )
			);
			apiFetch( { path: `/zinn-seo/v1/fields/post/${ postId }` } ).then(
				( f ) => setPhrase( ( f && f.focus ) || '' ),
				() => {}
			);
		}
	}, [ open, data, postId ] );
	const seo =
		setSeo ||
		( ( field, text ) =>
			apiFetch( {
				path: `/zinn-seo/v1/fields/post/${ postId }`,
				method: 'PUT',
				data: { fields: { [ field ]: text } },
			} ) );
	const selected =
		block && block.attributes ? plain( block.attributes.content ) : '';
	const insert = ( text ) => {
		const create =
			window.wp && window.wp.blocks && window.wp.blocks.createBlock;
		if ( ! create ) {
			return;
		}
		insertBlocks(
			toBlocks( text ).map( ( b ) =>
				create(
					b.name,
					b.attributes,
					( b.inner || [] ).map( ( i ) =>
						create( i.name, i.attributes )
					)
				)
			)
		);
	};
	const quick = ( tool ) => {
		setStart( { tool, text: selected } );
		setOpen( true );
	};
	if ( ! open ) {
		return (
			<div className="zseo-ed-x1c">
				<p className="zseo-ed-label">
					{ __( 'AI copywriter', 'zinn-seo' ) }
				</p>
				<Button variant="secondary" onClick={ () => setOpen( true ) }>
					{ __( 'Write with the AI copywriter', 'zinn-seo' ) }
				</Button>
				{ selected && (
					<p className="zseo-ed-hint">
						{ __( 'On the selected paragraph:', 'zinn-seo' ) }{ ' ' }
						<Button
							variant="link"
							onClick={ () => quick( 'rewrite' ) }
						>
							{ __( 'Improve', 'zinn-seo' ) }
						</Button>
						{ ' · ' }
						<Button
							variant="link"
							onClick={ () => quick( 'summarize' ) }
						>
							{ __( 'Summarise', 'zinn-seo' ) }
						</Button>
						{ ' · ' }
						<Button
							variant="link"
							onClick={ () => quick( 'fix-grammar' ) }
						>
							{ __( 'Fix grammar', 'zinn-seo' ) }
						</Button>
						{ ' · ' }
						<Button
							variant="link"
							onClick={ () => quick( 'expand' ) }
						>
							{ __( 'Write more', 'zinn-seo' ) }
						</Button>
					</p>
				) }
			</div>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	return (
		<div className="zseo-ed-x1c">
			<p className="zseo-ed-label">
				{ __( 'AI copywriter', 'zinn-seo' ) }
			</p>
			<CopywriterTool
				data={ data }
				postId={ postId }
				page={ { title, phrase } }
				initialTool={ start.tool }
				initialText={ start.text }
				onInsert={ insert }
				onSeo={ seo }
			/>
		</div>
	);
}
