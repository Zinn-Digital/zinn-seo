import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Dashicon,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * The shortcode for a block (simple fields as attributes; lists and rows go between the tags).
 *
 * @param {Object} block Block description.
 * @return {string} Shortcode.
 */
export function shortcodeOf( block ) {
	const simple = ( block.fields || [] ).filter(
		( f ) => [ 'text', 'select' ].includes( f.type ) && f.content
	);
	const attrs = simple
		.slice( 0, 2 )
		.map( ( f ) => ` ${ f.key }="…"` )
		.join( '' );
	return `[zinn_seo block="${ block.short }"${ attrs }]`;
}

/**
 * Every SEO block: what it does, where it appears, on or off.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function LibraryTab( { value, onChange, data } ) {
	const [ blocks, setBlocks ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( '' );
	useEffect( () => {
		seoFetch( '/blocks' )
			.then( setBlocks )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! blocks ) {
		return <Spinner />;
	}
	const off = value.blocks_off || [];
	const builders = [
		__( 'Block editor', 'zinn-seo' ),
		...( data.builders && data.builders.pbs
			? [ __( 'Page Builder Sandwich', 'zinn-seo' ) ]
			: [] ),
		...( data.builders && data.builders.elementor
			? [ __( 'Elementor', 'zinn-seo' ) ]
			: [] ),
		__( 'Shortcode', 'zinn-seo' ),
	];
	const copy = ( text ) => {
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text ).then( () => {
				setCopied( text );
				setTimeout( () => setCopied( '' ), 2000 );
			} );
		}
	};

	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ sprintf(
					/* translators: %s: the places blocks appear, comma-separated. */
					__(
						'Each block is written once and appears in: %s. A page loads a block’s few styles only when it uses that block, and its structured data joins the page’s one graph.',
						'zinn-seo'
					),
					builders.join( ', ' )
				) }
			</p>
			<ul className="zseo-blocks">
				{ blocks.map( ( b ) => (
					<li key={ b.name }>
						<Card size="small" className="zseo-blocks__card">
							<CardBody>
								<div className="zseo-blocks__head">
									<Dashicon icon={ b.icon } />
									<strong>{ b.title }</strong>
									{ 'pro' === b.tier && (
										<span className="zseo-badge is-pro">
											{ __( 'Pro', 'zinn-seo' ) }
										</span>
									) }
								</div>
								<p className="zseo-muted">{ b.description }</p>
								{ ! b.offered && (
									<p className="zseo-muted">
										{ __(
											'Page Builder Sandwich offers its own version, so this one is not in the inserter; copies already on pages keep working.',
											'zinn-seo'
										) }
									</p>
								) }
								<div className="zseo-blocks__foot">
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __( 'On', 'zinn-seo' ) }
										checked={ ! off.includes( b.short ) }
										onChange={ ( on ) =>
											onChange( {
												blocks_off: on
													? off.filter(
															( n ) =>
																n !== b.short
														)
													: [ ...off, b.short ],
											} )
										}
									/>
									<Button
										variant="link"
										onClick={ () =>
											copy( shortcodeOf( b ) )
										}
									>
										{ copied === shortcodeOf( b )
											? __( 'Copied', 'zinn-seo' )
											: __(
													'Copy shortcode',
													'zinn-seo'
												) }
									</Button>
								</div>
							</CardBody>
						</Card>
					</li>
				) ) }
			</ul>
		</div>
	);
}
