/**
 * Writing tool guidelines (zs-732, Pro): the owner's own Surfer and Frase keys, and how to bring in
 * the guidelines of the tools that sell no API (paste their export in the editor).
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, TextControl } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { ErrorLine } from './shared';

/**
 * One tool's key row.
 *
 * @param {Object}   props
 * @param {Object}   props.tool    `{id, name, api, hasKey}`.
 * @param {Function} props.onSaved New state.
 * @return {Element} Row.
 */
function KeyRow( { tool, onSaved } ) {
	const [ key, setKey ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const save = () => {
		setBusy( true );
		setError( '' );
		seoFetch( `/content-tools/keys/${ tool.id }`, {
			method: 'PUT',
			data: { key },
		} )
			.then( ( s ) => {
				setKey( '' );
				onSaved( s );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="zseo-x1c-box">
			<h4>{ tool.name }</h4>
			<ErrorLine text={ error } />
			<TextControl
				__nextHasNoMarginBottom
				type="password"
				autoComplete="off"
				label={
					tool.hasKey
						? __( 'API key (saved — type to replace)', 'zinn-seo' )
						: __( 'API key', 'zinn-seo' )
				}
				value={ key }
				onChange={ setKey }
			/>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy || ( ! key && ! tool.hasKey ) }
				onClick={ save }
			>
				{ ! key && tool.hasKey
					? __( 'Forget the key', 'zinn-seo' )
					: __( 'Save the key', 'zinn-seo' ) }
			</Button>
		</div>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function ContentToolsTab( { data } ) {
	const [ state, setState ] = useState( data.contentTools || null );
	if ( ! state ) {
		return (
			<ProTeaser
				title={ __( 'Guidelines from your writing tools', 'zinn-seo' ) }
				points={ [
					__(
						'Bring the terms, length and headings your Surfer, Clearscope, MarketMuse, Frase or Semrush report asks for into the editor.',
						'zinn-seo'
					),
					__( 'See each term filled in as you write.', 'zinn-seo' ),
					__(
						'Check a draft against the web for copied passages before you publish.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	const api = state.tools.filter( ( t ) => t.api );
	const paste = state.tools.filter( ( t ) => ! t.api );
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Bring the guidelines of the writing tool you already pay for into the editor: the terms to use and how often, the length and the headings. Open a post, then Search and social, Writing guidelines.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="content-tools" />
			</p>
			<p>
				{ __(
					'Connect with your own key (the guidelines come straight from your account):',
					'zinn-seo'
				) }
			</p>
			{ api.map( ( t ) => (
				<KeyRow key={ t.id } tool={ t } onSaved={ setState } />
			) ) }
			<Notice status="info" isDismissible={ false }>
				{ paste.map( ( t ) => t.name ).join( ', ' ) }:{ ' ' }
				{ __(
					'these tools have no API for your reports, so copy the term list (or download the CSV) from the report and paste it into the editor. Every format they export is read.',
					'zinn-seo'
				) }
			</Notice>
		</div>
	);
}
