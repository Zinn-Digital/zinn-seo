import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import ProTeaser from '../ProTeaser';
import { Address } from './shared';

/**
 * llms.txt for AI assistants, per language, and (Pro) Markdown for agents.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {boolean}  props.isPro    A paid plan is active.
 * @return {Element} The tab.
 */
export default function LlmsTab( { value, onChange, isPro } ) {
	const [ info, setInfo ] = useState( null );
	const [ lang, setLang ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const load = ( l ) =>
		seoFetch( `/llms?lang=${ encodeURIComponent( l ) }` )
			.then( setInfo )
			.catch( ( e ) => setError( errorText( e ) ) );

	useEffect( () => {
		load( lang );
	}, [ lang ] );

	const mods = value.modules || {};
	const on = ( id, fallback ) => ( id in mods ? !! mods[ id ] : fallback );
	const setModule = ( id, v ) =>
		onChange( { modules: { ...mods, [ id ]: v } } );

	if ( ! info ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const chosen =
		value.llms_types && value.llms_types.length > 0
			? value.llms_types
			: info.chosen;

	return (
		<div className="zseo-stack">
			<PanelBody title={ __( 'llms.txt', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<p>
						{ __(
							"A short guide for AI assistants: what the site is and which pages matter, each with its description. The full version carries those pages' text.",
							'zinn-seo'
						) }
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Publish llms.txt', 'zinn-seo' ) }
						checked={ on( 'llms', true ) }
						onChange={ ( v ) => setModule( 'llms', v ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Publish llms-full.txt too', 'zinn-seo' ) }
						checked={ !! value.llms_full }
						onChange={ ( v ) => onChange( { llms_full: v } ) }
					/>
					<ul className="zseo-files">
						{ info.files.map( ( f ) => (
							<li key={ f.lang || 'default' }>
								{ f.name && <strong>{ f.name }</strong> }
								<Address href={ f.url } />
								{ value.llms_full && (
									<Address href={ f.full } />
								) }
							</li>
						) ) }
					</ul>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Summary', 'zinn-seo' ) }
						help={ __(
							"One or two sentences. Empty: the site's tagline.",
							'zinn-seo'
						) }
						rows={ 2 }
						value={ value.llms_summary || '' }
						onChange={ ( v ) => onChange( { llms_summary: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'More about the site (optional)',
							'zinn-seo'
						) }
						help={ __(
							'What an assistant should know: who you serve, where, what you are known for.',
							'zinn-seo'
						) }
						rows={ 3 }
						value={ value.llms_details || '' }
						onChange={ ( v ) => onChange( { llms_details: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Key pages (IDs, in order)', 'zinn-seo' ) }
						help={ __(
							'Empty: the front page and the top-level pages.',
							'zinn-seo'
						) }
						value={ value.llms_pages || '' }
						onChange={ ( v ) =>
							onChange( {
								llms_pages: v.replace( /[^\d,\s]/g, '' ),
							} )
						}
					/>
					<fieldset>
						<legend className="zseo-legend">
							{ __( 'Also list the newest of', 'zinn-seo' ) }
						</legend>
						{ info.types.map( ( t ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ t.name }
								label={ t.label }
								checked={ chosen.includes( t.name ) }
								onChange={ ( c ) =>
									onChange( {
										llms_types: c
											? [ ...chosen, t.name ]
											: chosen.filter(
													( n ) => n !== t.name
												),
									} )
								}
							/>
						) ) }
					</fieldset>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 5000 }
						label={ __( 'Pages of each type', 'zinn-seo' ) }
						value={ String( value.llms_per_type ) }
						onChange={ ( v ) =>
							onChange( {
								llms_per_type: Math.max(
									1,
									Math.min( 5000, parseInt( v, 10 ) || 1 )
								),
							} )
						}
					/>
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'Preview', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<div className="zseo-stack">
					{ info.files.length > 1 && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Language', 'zinn-seo' ) }
							value={ lang }
							options={ info.files.map( ( f ) => ( {
								value: f.lang,
								label:
									f.name || __( 'Main language', 'zinn-seo' ),
							} ) ) }
							onChange={ setLang }
						/>
					) }
					<pre className="zseo-pre">{ info.preview }</pre>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="secondary"
							isBusy={ busy }
							onClick={ () => {
								setBusy( true );
								seoFetch( '/llms/refresh', { method: 'POST' } )
									.then( () => load( lang ) )
									.finally( () => setBusy( false ) );
							} }
						>
							{ __( 'Refresh the published files', 'zinn-seo' ) }
						</Button>
					</div>
					<p className="zseo-muted">
						{ __(
							'The preview shows saved settings. The files refresh by themselves when a listed page changes.',
							'zinn-seo'
						) }
					</p>
				</div>
			</PanelBody>
			{ isPro ? (
				<PanelBody
					title={ __( 'Markdown for AI agents', 'zinn-seo' ) }
					initialOpen={ false }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Answer every page as Markdown too',
							'zinn-seo'
						) }
						help={ __(
							'Each page also answers at its own address with .md added, as clean text: no menus, no scripts. Never indexed as a duplicate.',
							'zinn-seo'
						) }
						checked={ on( 'markdown', true ) }
						onChange={ ( v ) => setModule( 'markdown', v ) }
					/>
				</PanelBody>
			) : (
				<ProTeaser
					title={ __( 'Markdown for AI agents', 'zinn-seo' ) }
					points={ [
						__(
							'Every page also answers at its own address with .md added, as clean text for AI agents.',
							'zinn-seo'
						),
						__(
							"Cached, announced in each page's head, and never indexed as a duplicate.",
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}
