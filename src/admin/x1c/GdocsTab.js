/**
 * The Google Docs add-on (zs-704, Pro): the writer's personal key and the address to paste.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, TextControl } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { useRoute } from '../p4/shared';
import { ErrorLine } from './shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function GdocsTab( { data } ) {
	const on = data.modules && data.modules.gdocs;
	const state = useRoute( on ? '/gdocs/key' : '/settings' );
	const [ made, setMade ] = useState( null );
	const [ error, setError ] = useState( '' );
	if ( ! on ) {
		return (
			<ProTeaser
				title={ __( 'Google Docs™ add-on', 'zinn-seo' ) }
				points={ [
					__(
						"Check a Google Doc's SEO and readability while you write it.",
						'zinn-seo'
					),
					__(
						'Send it to WordPress as a draft with its SEO title, description and focus phrase.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	const make = () =>
		seoFetch( '/gdocs/key', { method: 'POST' } )
			.then( setMade )
			.catch( ( e ) => setError( errorText( e ) ) );
	const forget = () =>
		seoFetch( '/gdocs/key', { method: 'DELETE' } )
			.then( () => {
				setMade( null );
				state.reload();
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	const has = made || ( state.data && state.data.hasKey );
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Write in Google Docs™ and check SEO and readability as you go; send the document to WordPress as a draft when it is ready. In Google Docs, open Extensions, Zinn® SEO, and paste the address and key below.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="gdocs" />
			</p>
			<ErrorLine text={ error } />
			{ made && (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'Copy the key now: it is shown only once.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ made && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						readOnly
						label={ __( 'Address', 'zinn-seo' ) }
						value={ made.endpoint }
						onChange={ () => {} }
					/>
					<TextControl
						__nextHasNoMarginBottom
						readOnly
						label={ __( 'Key', 'zinn-seo' ) }
						value={ made.key }
						onChange={ () => {} }
					/>
				</>
			) }
			<div className="zseo-x1c-row">
				<Button variant="primary" onClick={ make }>
					{ has
						? __(
								'Make a new key (the old one stops working)',
								'zinn-seo'
							)
						: __( 'Make my key', 'zinn-seo' ) }
				</Button>
				{ has && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ forget }
					>
						{ __( 'Stop the add-on using my account', 'zinn-seo' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
