/**
 * The copywriter box inside Elementor (zs-782): the same tool as the block editor's, for the page
 * being edited.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import CopywriterTool from '../copywriter/CopywriterTool';
import '../copywriter/copywriter.scss';

/**
 * The box.
 *
 * @param {Object}   props
 * @param {number}   props.postId   Page.
 * @param {Function} props.onInsert Insert a version.
 * @param {Function} props.onSeo    Use a version as the SEO title or description.
 * @return {Element} Box.
 */
export default function CopywriterBox( { postId, onInsert, onSeo } ) {
	const [ open, setOpen ] = useState( false );
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( {} );
	useEffect( () => {
		if ( open && ! data ) {
			apiFetch( { path: '/zinn-seo/v1/copywriter' } ).then( setData, () =>
				setData( { tools: [] } )
			);
			apiFetch( { path: `/zinn-seo/v1/fields/post/${ postId }` } ).then(
				( f ) =>
					setPage( {
						phrase: ( f && f.focus ) || '',
						title:
							window.elementor?.config?.document?.settings
								?.settings?.post_title || '',
					} ),
				() => {}
			);
		}
	}, [ open, data, postId ] );
	if ( ! open ) {
		return (
			<Button variant="secondary" onClick={ () => setOpen( true ) }>
				{ __( 'Write with the AI copywriter', 'zinn-seo' ) }
			</Button>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	return (
		<CopywriterTool
			data={ data }
			postId={ postId }
			page={ page }
			onInsert={ onInsert }
			onSeo={ onSeo }
		/>
	);
}
