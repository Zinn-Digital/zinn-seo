import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, TabPanel } from '@wordpress/components';

import { errorText, useSettings } from './api';
import { ModuleGate } from './discovery/shared';
import ProTeaser from './ProTeaser';
import GraphTab from './schema/GraphTab';
import TypesTab from './schema/TypesTab';
import CheckTab from './schema/CheckTab';
import CustomTab from './schema/CustomTab';
import RulesTab from './schema/RulesTab';
import FillTab from './schema/FillTab';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function schemaTabs() {
	return [
		{ name: 'graph', title: __( 'Knowledge graph', 'zinn-seo' ) },
		{ name: 'types', title: __( 'Content types', 'zinn-seo' ) },
		{ name: 'check', title: __( 'Check', 'zinn-seo' ) },
		{ name: 'fill', title: __( 'Fill with AI', 'zinn-seo' ) },
		{ name: 'custom', title: __( 'Custom types', 'zinn-seo' ) },
		{ name: 'rules', title: __( 'Rules', 'zinn-seo' ) },
	];
}

/**
 * Structured data: one graph per page, the site's knowledge graph, what each content type is, the
 * offline check, and (Pro) custom types and rules.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function SchemaScreen( { data } ) {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const isPro = 'free' !== data.plan;

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! settings ) {
		return <Spinner />;
	}
	const value = { ...settings, ...draft };
	const change = ( part ) => {
		setDraft( { ...draft, ...part } );
		setNotice( null );
	};
	const dirty = Object.keys( draft ).length > 0;
	const store = () => {
		setBusy( true );
		save( draft )
			.then( () => {
				setDraft( {} );
				setNotice( {
					status: 'success',
					text: __(
						'Saved. Pages show it from their next visit.',
						'zinn-seo'
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	const props = { value, onChange: change, data };
	const saveBar = (
		<p className="zak-actions">
			<Button
				variant="primary"
				isBusy={ busy }
				disabled={ busy || ! dirty }
				onClick={ store }
			>
				{ __( 'Save changes', 'zinn-seo' ) }
			</Button>
		</p>
	);

	return (
		<div className="zseo-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<TabPanel
				className="zseo-tabs"
				tabs={ schemaTabs() }
				initialTabName={
					schemaTabs().some( ( t ) => t.name === initial )
						? initial
						: 'graph'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					if ( 'check' === tab.name ) {
						return <CheckTab />;
					}
					if ( 'fill' === tab.name ) {
						return <FillTab data={ data } />;
					}
					if ( 'custom' === tab.name || 'rules' === tab.name ) {
						if ( ! isPro ) {
							return (
								<ProTeaser
									title={ __(
										'Any schema type, built visually or copied from any page',
										'zinn-seo'
									) }
									points={ [
										__(
											'Add a recipe, event, job, course, software, book, film, service, video or Q&A to a page from a simple form.',
											'zinn-seo'
										),
										__(
											'Design your own type from the whole schema.org vocabulary, or copy one from any page’s structured data.',
											'zinn-seo'
										),
										__(
											'Give a type to every post in a category, of a content type or with a custom field — filled from each post automatically.',
											'zinn-seo'
										),
										__(
											'Say what a page is about and what it mentions, which parts a voice assistant reads, and its most important links.',
											'zinn-seo'
										),
									] }
								/>
							);
						}
						const proOn = !! ( data.modules || {} )[ 'schema-pro' ];
						return (
							<>
								<ModuleGate
									id="schema-pro"
									label={ __(
										'Pro structured data',
										'zinn-seo'
									) }
									{ ...props }
								>
									{ 'custom' === tab.name ? (
										<CustomTab />
									) : (
										<RulesTab { ...props } />
									) }
								</ModuleGate>
								{ ( 'rules' === tab.name || ! proOn ) &&
									saveBar }
							</>
						);
					}
					return (
						<>
							<ModuleGate
								id="schema"
								label={ __( 'Structured data', 'zinn-seo' ) }
								{ ...props }
							>
								{ 'graph' === tab.name ? (
									<GraphTab { ...props } />
								) : (
									<TypesTab { ...props } />
								) }
							</ModuleGate>
							{ saveBar }
						</>
					);
				} }
			</TabPanel>
		</div>
	);
}
