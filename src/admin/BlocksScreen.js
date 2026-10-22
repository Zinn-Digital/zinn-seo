import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, TabPanel } from '@wordpress/components';

import { errorText, useSettings } from './api';
import { ModuleGate } from './discovery/shared';
import ProTeaser from './ProTeaser';
import LibraryTab from './blocks/LibraryTab';
import IndexTab from './blocks/IndexTab';
import GlossaryTab from './blocks/GlossaryTab';
import CustomBlocksTab from './blocks/CustomBlocksTab';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function blocksTabs() {
	return [
		{ name: 'library', title: __( 'Blocks', 'zinn-seo' ) },
		{ name: 'index', title: __( 'Article index', 'zinn-seo' ) },
		{ name: 'glossary', title: __( 'Glossary', 'zinn-seo' ) },
		{ name: 'custom', title: __( 'Custom blocks', 'zinn-seo' ) },
	];
}

/**
 * The SEO blocks: the library, the article index everywhere, (Pro) the glossary and blocks you
 * design.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function BlocksScreen( { data } ) {
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
					text: __( 'Saved.', 'zinn-seo' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
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
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	const teaser = (
		<ProTeaser
			title={ __( 'More blocks, and blocks you design', 'zinn-seo' ) }
			points={ [
				__(
					'Comparison tables that turn into cards on phones — never a sideways scroll.',
					'zinn-seo'
				),
				__(
					'Fact checks with ClaimReview structured data.',
					'zinn-seo'
				),
				__(
					'A glossary with its own A-to-Z page that links the first mention of each term in your posts.',
					'zinn-seo'
				),
				__(
					'Video that loads nothing from YouTube until it is played, with key moments and transcript.',
					'zinn-seo'
				),
				__(
					'Design your own block — fields, look and structured data — and use it in every page builder.',
					'zinn-seo'
				),
			] }
		/>
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
				tabs={ blocksTabs() }
				initialTabName={
					blocksTabs().some( ( t ) => t.name === initial )
						? initial
						: 'library'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					if ( 'glossary' === tab.name || 'custom' === tab.name ) {
						if ( ! isPro ) {
							return teaser;
						}
						const on = !! ( data.modules || {} )[ 'blocks-pro' ];
						return (
							<>
								<ModuleGate
									id="blocks-pro"
									label={ __( 'Pro SEO blocks', 'zinn-seo' ) }
									{ ...props }
								>
									{ 'glossary' === tab.name ? (
										<GlossaryTab { ...props } />
									) : (
										<CustomBlocksTab { ...props } />
									) }
								</ModuleGate>
								{ ( 'glossary' === tab.name || ! on ) &&
									saveBar }
							</>
						);
					}
					return (
						<>
							<ModuleGate
								id="blocks"
								label={ __( 'SEO blocks', 'zinn-seo' ) }
								{ ...props }
							>
								{ 'library' === tab.name ? (
									<>
										<LibraryTab { ...props } />
										{ ! isPro && teaser }
									</>
								) : (
									<IndexTab { ...props } />
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
