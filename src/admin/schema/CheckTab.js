import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	PanelBody,
	SearchControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import Results from './Results';

/**
 * Check any page's structured data, or pasted JSON-LD, offline (zs-035).
 *
 * @return {Element} The tab.
 */
export default function CheckTab() {
	const [ query, setQuery ] = useState( '' );
	const [ found, setFound ] = useState( [] );
	const [ picked, setPicked ] = useState( null );
	const [ paste, setPaste ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const q = query.trim();
		const timer = setTimeout( () => {
			apiFetch( {
				path: `/wp/v2/search?type=post&subtype=any&per_page=8&search=${ encodeURIComponent(
					q
				) }`,
			} )
				.then( ( rows ) => setFound( rows || [] ) )
				.catch( () => setFound( [] ) );
		}, 250 );
		return () => clearTimeout( timer );
	}, [ query ] );

	const run = ( data ) => {
		setBusy( true );
		setError( '' );
		setResult( null );
		seoFetch( '/schema/validate', { method: 'POST', data } )
			.then( setResult )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="zseo-stack">
			<PanelBody title={ __( 'Check a page', 'zinn-seo' ) }>
				<p className="zseo-muted">
					{ __(
						'Checks the structured data a page prints against schema.org and Google’s current rich-result rules, here on your site — nothing is sent anywhere.',
						'zinn-seo'
					) }
				</p>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Find a page', 'zinn-seo' ) }
					value={ query }
					onChange={ setQuery }
				/>
				<ul className="zseo-picks">
					{ found.map( ( row ) => (
						<li key={ row.id }>
							<Button
								variant={
									picked && picked.id === row.id
										? 'primary'
										: 'tertiary'
								}
								onClick={ () => {
									setPicked( row );
									run( { post: row.id } );
								} }
							>
								{ row.title || __( '(no title)', 'zinn-seo' ) }
							</Button>
							<span className="zseo-muted">{ row.subtype }</span>
						</li>
					) ) }
				</ul>
			</PanelBody>
			<PanelBody
				title={ __( 'Check pasted structured data', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'JSON-LD, script tags, or a whole page’s HTML',
						'zinn-seo'
					) }
					rows={ 8 }
					dir="ltr"
					value={ paste }
					onChange={ setPaste }
				/>
				<Button
					variant="secondary"
					disabled={ ! paste.trim() || busy }
					onClick={ () => run( { json: paste } ) }
				>
					{ __( 'Check', 'zinn-seo' ) }
				</Button>
			</PanelBody>
			{ busy && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<Results result={ result } />
		</div>
	);
}
